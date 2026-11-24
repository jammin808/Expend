<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Travel\TravelDays;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TaxYear;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Suggests what each payment is when nothing more specific has (no rule of yours, no invoice, no travel or HMRC
 * pattern). In order:
 *
 *  1. what you chose before for the same merchant;
 *  2. what you chose for similar merchants (sharing a distinctive first word, e.g. every "TESCO ...");
 *  3. how you usually treat the bank's own category for it (Starling's "Eating out", Amex's "Travel-Lodging");
 *  4. a list of common UK merchants and what they usually are, judged against your job days where it matters
 *     (a meal or hotel on a job day away is business; at home it isn't).
 *
 * Everything is a suggestion with its reason, for you to confirm or change. Each choice you make teaches it: the
 * next payment like it is suggested the same way, and after two the app offers to make it automatic.
 */
final class Categoriser
{
    /** Words too common to say two merchants are alike. */
    private const GENERIC = ['LTD', 'LIMITED', 'PLC', 'UK', 'STORE', 'STORES', 'SHOP', 'THE', 'AND', 'SERVICES', 'SERVICE', 'GROUP', 'INTERNATIONAL', 'ONLINE', 'MARKETPLACE',
        'AMAZON', 'AMZN', 'PAYPAL', 'SQ', 'SUMUP', 'ZETTLE', 'IZETTLE', 'STRIPE', 'WORLDPAY', 'CRV', 'BOOKING', 'TRANSFER', 'MONEY', 'BANK', 'CASH'];

    /**
     * Common merchants: pattern => [what it usually is, category code or null, needs a job day?]. "day" entries are
     * business only on a job day (or a night away for it); otherwise personal.
     */
    private const HINTS = [
        ['/\b(SCREWFIX|TOOLSTATION|WICKES|B ?& ?Q|TRAVIS PERKINS|CPC|FARNELL|RS COMPONENTS|STUDIOSPARES|CANFORD|THOMANN|GEAR4MUSIC|STAGE ELECTRICS|LIGHTING SUPERMARKET|PROLIGHT|CABLE ?CHIC|PRO AV|FROM ?THE ?DECK)\b/', 'business_expense', 'materials', false, 'kit, cable and consumables'],
        ['/\b(ADOBE|MICROSOFT|MSFT|DROPBOX|GOOGLE ?(WORKSPACE|GSUITE|STORAGE|ONE)|ZOOM\.?US|SLACK|CANVA|NOTION|XERO|QUICKBOOKS|FREEAGENT|ICLOUD|1PASSWORD|LASTPASS|WETRANSFER|VECTORWORKS|AUTODESK|QLAB|FIGURE ?53|SOUNDCLOUD)\b/', 'business_expense', 'software', false, 'software or a subscription'],
        ['/\b(VODAFONE|EE LIMITED|EE MOBILE|O2|THREE\.CO|GIFFGAFF|BT GROUP|BT PLC|VIRGIN MEDIA|SKY DIGITAL|PLUSNET|TALKTALK|HYPEROPTIC|LEBARA|LYCAMOBILE|ID MOBILE)\b/', 'business_expense', 'phone_internet', false, 'phone or internet: only the business share counts'],
        ['/\b(HISCOX|SIMPLY BUSINESS|MARKEL|ADMIRAL PRO|PHOTOGUARD|DOCTOR INSURE|INSURE ?4 ?MUSIC|ALLIANZ MUSICAL)\b/', 'business_expense', 'insurance', false, 'business insurance'],
        ['/\b(COMPANIES HOUSE|ICO|INFORMATION COMMISSIONER|PLASA|BECTU|EQUITY|MUSICIANS UNION|PRS|PPL|IOSH|CSCS|CITB|IPAF|PASMA)\b/', 'business_expense', 'training', false, 'a trade body, licence or course'],
        ['/\b(ROYAL MAIL|PARCELFORCE|DPD|EVRI|HERMES|UPS|FEDEX|DHL|POST OFFICE)\b/', 'business_expense', 'stationery', false, 'postage'],
        ['/\b(PREMIER INN|TRAVELODGE|HOLIDAY INN|IBIS|HILTON|MARRIOTT|NOVOTEL|MERCURE|DOUBLETREE|HAMPTON BY|RADISSON|JURYS|CROWNE PLAZA|BEST WESTERN|AIRBNB|HOTELS\.COM|EXPEDIA|LASTMINUTE)\b/', 'night', 'travel_accommodation', true, 'a hotel'],
        ['/\b(PRET|GREGGS|COSTA|STARBUCKS|CAFFE NERO|MCDONALDS|MCDONALD S|BURGER KING|KFC|SUBWAY|LEON|ITSU|WASABI|WHSMITH|BOOTS|UPPER CRUST|WETHERSPOON|NANDOS|WAGAMAMA|GAILS|TIM HORTONS|FIVE GUYS|DOMINOS|DELIVEROO|JUST EAT|UBER ?EATS)\b/', 'day', 'travel_subsistence', true, 'food or drink'],
        ['/\b(SHELL|ESSO|TEXACO|GULF|MURCO|JET PETROL|BP|TESCO PFS|SAINSBURYS PETROL|ASDA PETROL|MORRISONS PETROL|MFG|MOTO|WELCOME BREAK|ROADCHEF|EXTRA MSA)\b/', 'fuel', 'travel_fuel', true, 'fuel or a service station'],
        ['/\b(TESCO|SAINSBURY|ASDA|MORRISONS|ALDI|LIDL|WAITROSE|CO-?OP|OCADO|ICELAND|M&S|MARKS ?& ?SPENCER|SPAR|BUDGENS|FARMFOODS)\b/', 'personal', null, false, 'a supermarket: usually personal (a meal while working away can be business)'],
        ['/\b(NETFLIX|SPOTIFY|DISNEY|APPLE ?TV|NOW ?TV|AMAZON PRIME|AUDIBLE|PLAYSTATION|XBOX|NINTENDO|STEAM|TWITCH|DEEZER|TIDAL)\b/', 'personal', null, false, 'streaming or games'],
        ['/\b(GYM|PUREGYM|THE GYM GROUP|DAVID LLOYD|NUFFIELD HEALTH|ANYTIME FITNESS|BANNATYNE|VIRGIN ACTIVE)\b/', 'personal', null, false, 'a gym'],
        ['/\b(MONTHLY FEE|ACCOUNT FEE|OVERDRAFT FEE|BANK CHARGE|CARD FEE|FX FEE|NON-STERLING|FOREIGN TRANSACTION FEE)\b/', 'business_expense', 'finance', false, 'a bank or card charge'],
    ];

    /** The bank's own categories, where they say clearly what a payment is. */
    private const BANK_CATEGORIES = [
        '/^(GROCERIES|GROCERY|SUPERMARKETS?)$/i' => ['personal', null, false, 'groceries'],
        '/^(ENTERTAINMENT|HOBBIES|LIFESTYLE|HOLIDAYS?)$/i' => ['personal', null, false, 'entertainment'],
        '/LODGING|HOTEL|ACCOMMODATION/i' => ['night', 'travel_accommodation', true, 'a hotel'],
        '/EATING[_ ]OUT|RESTAURANT|FAST[_ ]?FOOD|CAFE|BARS?/i' => ['day', 'travel_subsistence', true, 'food or drink'],
        '/TAXI/i' => ['day', 'travel_taxi', true, 'a taxi'],
        '/AIRLINE|FLIGHTS?/i' => ['day', 'travel_flights', true, 'a flight'],
        '/RAIL|TRAIN/i' => ['day', 'travel_rail', true, 'a train'],
        '/FUEL|PETROL|GAS STATION/i' => ['fuel', 'travel_fuel', true, 'fuel'],
    ];

    /** @var array{merchant:array, token:array, bank:array}|null decisions of yours, for learning */
    private ?array $learned = null;

    /** @var array<string, ?int> */
    private array $categories = [];

    public function __construct(private AllocationWriter $writer, private TravelDays $days) {}

    /** How this class's suggestions start, so they can be told apart and updated as you decide more. */
    public const REASONS = ['Like the ', 'Like ', 'You usually decide the bank', 'Looks like ', 'Check: Looks like ', 'The bank calls it', 'Check: The bank calls it'];

    /** Forget what was learned (after a decision of yours), so the next suggestion uses it. */
    public function relearn(): void
    {
        $this->learned = null;
    }

    public static function isOurs(?string $reason): bool
    {
        foreach (self::REASONS as $prefix) {
            if ($reason !== null && str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * After you decide some payments: suggest the same for others waiting from the same merchants (and update
     * earlier guesses of this class that your decision now contradicts).
     *
     * @param  Collection<int, BankTransaction>  $decided
     * @return int payments whose suggestion was added or changed
     */
    public function learnFrom(Collection $decided): int
    {
        $keys = $decided->filter(fn ($t) => $t instanceof BankTransaction)->pluck('merchant_key')->unique()->reject(fn ($k) => $k === 'UNKNOWN')->values();
        if ($keys->isEmpty()) {
            return 0;
        }
        $this->relearn();
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $changed = 0;
        $waiting = BankTransaction::query()->whereIn('merchant_key', $keys)->where('review_status', '!=', 'confirmed')->whereNull('transfer_pair_id')
            ->whereNotIn('tax_year', $locked)->whereNotIn('id', $decided->pluck('id'))->with('allocations')->limit(300)->get();
        foreach ($waiting as $tx) {
            $ours = $tx->allocations->isNotEmpty() && $tx->allocations->every(fn (Allocation $a) => $a->decided_by === 'matcher' && $a->confirmed_at === null && self::isOurs($a->reason));
            if ($tx->allocations->isNotEmpty() && ! $ours) {
                continue; // another matcher's, a rule's, the AI's or yours: left alone
            }
            $p = $this->propose($tx);
            if (! $p || ($ours && $tx->allocations->first()->reason === $p['reason'])) {
                continue;
            }
            try {
                $changed += (int) $this->writer->decide($tx, $p['lines'], 'matcher', false, $p['reason']);
            } catch (InvalidArgumentException) {
                // doesn't fit
            }
        }

        return $changed;
    }

    /**
     * Suggest for lines nobody has decided. Never touches a decision of yours, a rule's, or anything confirmed.
     *
     * @param  Collection<int, BankTransaction>  $txs
     */
    public function suggestFor(Collection $txs): int
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $made = 0;
        foreach ($txs as $tx) {
            if (in_array($tx->tax_year, $locked, true) || $tx->allocations()->exists() || $tx->transfer_pair_id) {
                continue;
            }
            $p = $this->propose($tx);
            if (! $p) {
                continue;
            }
            try {
                $made += (int) $this->writer->decide($tx, $p['lines'], 'matcher', false, $p['reason']);
            } catch (InvalidArgumentException) {
                // lines that don't fit, or a locked year
            }
        }

        return $made;
    }

    /** @return array{lines:list<array>, reason:string, source:string}|null */
    public function propose(BankTransaction $tx): ?array
    {
        if (OwnAccounts::reason($tx)) {
            return null; // your own money moving: the transfer check deals with it
        }
        $this->learned ??= $this->learn();
        $dir = $tx->amount_pence < 0 ? 'out' : 'in';
        $name = $tx->counterparty ?: $tx->merchant_key;

        // 1. Your own decisions for this merchant.
        // Your latest five decisions count, so changing your mind is followed.
        if (($shape = self::majority(array_slice($this->learned['merchant'][$dir][$tx->merchant_key] ?? [], 0, 5), 1, 0.6))) {
            $times = $shape['n'] === $shape['of'] ? "the {$shape['of']} ".($shape['of'] === 1 ? 'time' : 'times') : "{$shape['n']} of the last {$shape['of']} times";

            return $this->build($tx, $shape, "Like {$times} you decided {$name}", 'merchant');
        }
        // 2. Similar merchants you've decided.
        $token = self::token($tx->merchant_key);
        if ($token && ($shape = self::majority($this->learned['token'][$dir][$token] ?? [], 2, 0.75))) {
            return $this->build($tx, $shape, "Like the other {$token} payments you decided ({$shape['n']} of {$shape['of']})", 'similar');
        }
        // 3. How you treat the bank's own category.
        $bank = trim((string) $tx->bank_category);
        if ($bank !== '' && ($shape = self::majority($this->learned['bank'][$dir][mb_strtoupper($bank)] ?? [], 3, 0.75))) {
            return $this->build($tx, $shape, 'You usually decide the bank’s “'.self::pretty($bank)."” payments this way ({$shape['n']} of {$shape['of']})", 'bank_learned');
        }
        if ($dir === 'in') {
            return null; // money in: invoices and your own decisions only
        }
        // 4. Common merchants, then the bank's category.
        $text = mb_strtoupper($tx->description.' '.$tx->counterparty);
        foreach (self::HINTS as [$pattern, $kind, $code, , $what]) {
            if (preg_match($pattern, $text)) {
                return $this->hint($tx, $kind, $code, $what, 'Looks like '.$what);
            }
        }
        foreach (self::BANK_CATEGORIES as $pattern => [$kind, $code, , $what]) {
            if ($bank !== '' && preg_match($pattern, $bank)) {
                return $this->hint($tx, $kind, $code, $what, 'The bank calls it “'.self::pretty($bank).'”');
            }
        }

        return null;
    }

    /** A common-merchant hint, judged against your job days where it depends on them. */
    private function hint(BankTransaction $tx, string $kind, ?string $code, string $what, string $why): ?array
    {
        $date = $tx->effectiveDate()->format('Y-m-d');
        if ($kind === 'personal') {
            return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => $why.': usually personal', 'source' => 'hint'];
        }
        if ($kind === 'fuel' && Vehicle::query()->where('method', 'simplified')->exists() && ! Vehicle::query()->where('method', '!=', 'simplified')->exists()) {
            return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => $why.': you claim simplified mileage, which already covers fuel (fuel for a hire car is claimable: change it if so)', 'source' => 'hint'];
        }
        if (in_array($kind, ['day', 'night', 'fuel'], true)) {
            $v = $kind === 'night' ? $this->days->night($date) : $this->days->day($date);
            if ($v['verdict'] === 'personal') {
                return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => "{$why}, with {$v['reason']}: personal", 'source' => 'hint'];
            }
            if ($kind === 'day' && $code === 'travel_subsistence' && ! $this->awayOvernight($v['job'], $date)) {
                // Meals are only allowable when you're away from your normal pattern, usually overnight.
                return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => "{$why} on a working day of ".($v['job']?->title ?? 'a job').', but not while away overnight, so usually not allowable', 'source' => 'hint'];
            }
            $check = $v['verdict'] === 'review' ? 'Check: ' : '';

            return ['lines' => AllocationWriter::lines($tx->amount_pence, 'business_expense', $this->category($code), $v['job']?->id), 'reason' => "{$check}{$why}, {$v['reason']}", 'source' => 'hint'];
        }

        // A business purchase on one of your job days is suggested as that job's cost.
        $v = $this->days->day($date);
        $job = $v['verdict'] === 'business' ? $v['job'] : null;

        return ['lines' => AllocationWriter::lines($tx->amount_pence, 'business_expense', $this->category($code), $job?->id),
            'reason' => $why.': usually a business cost'.($job ? ', on a day of '.$job->title : ''), 'source' => 'hint'];
    }

    private function awayOvernight($job, string $date): bool
    {
        if (! $job) {
            return false;
        }
        $prev = date('Y-m-d', strtotime($date.' -1 day'));

        return in_array($this->days->night($date)['verdict'], ['business'], true) || in_array($this->days->night($prev)['verdict'], ['business'], true);
    }

    /** @param array{kind:string, category_id:?int, business_pct:int, n:int, of:int} $shape */
    private function build(BankTransaction $tx, array $shape, string $reason, string $source): ?array
    {
        $jobId = null;
        if ($shape['kind'] === 'business_expense') {
            $v = $this->days->day($tx->effectiveDate()->format('Y-m-d'));
            $jobId = $v['verdict'] === 'business' ? $v['job']?->id : null;
            if ($jobId) {
                $reason .= ', on a day of '.$v['job']->title;
            }
        }

        return ['lines' => AllocationWriter::lines($tx->amount_pence, $shape['kind'], $shape['category_id'], $jobId, $shape['business_pct']), 'reason' => $reason, 'source' => $source];
    }

    /** Your decisions on confirmed payments (the last three years), by merchant, by distinctive word and by the bank's category. */
    private function learn(): array
    {
        $out = ['merchant' => [], 'token' => [], 'bank' => []];
        BankTransaction::query()->where('review_status', 'confirmed')->where('posted_on', '>=', now()->subYears(3)->format('Y-m-d'))
            ->with('allocations:id,bank_transaction_id,kind,category_id,business_use_pct,decided_by,is_manual')
            ->select(['id', 'merchant_key', 'bank_category', 'amount_pence'])->orderByDesc('posted_on')->limit(8000)->get()
            ->each(function (BankTransaction $t) use (&$out) {
                if ($t->allocations->isEmpty() || ! $t->allocations->every(fn (Allocation $a) => $a->decided_by === 'you')) {
                    return;
                }
                $shape = self::shape($t);
                if (! $shape) {
                    return;
                }
                $dir = $t->amount_pence < 0 ? 'out' : 'in';
                $key = json_encode($shape);
                $out['merchant'][$dir][$t->merchant_key][] = $key;
                if ($shape['kind'] === 'transfer') {
                    return; // a transfer is about the account, not the kind of merchant
                }
                if ($token = self::token($t->merchant_key)) {
                    $out['token'][$dir][$token][] = $key;
                }
                if ($t->bank_category) {
                    $out['bank'][$dir][mb_strtoupper(trim($t->bank_category))][] = $key;
                }
            });

        return $out;
    }

    /** @return array{kind:string, category_id:?int, business_pct:int}|null */
    private static function shape(BankTransaction $t): ?array
    {
        $main = $t->allocations->firstWhere('kind', '!=', 'personal') ?? $t->allocations->first();
        $kinds = $t->allocations->pluck('kind')->sort()->values()->all();
        if (count($kinds) > 2 || (count($kinds) === 2 && ! in_array('personal', $kinds, true))) {
            return null;
        }

        return ['kind' => $main->kind, 'category_id' => $main->category_id, 'business_pct' => (int) ($main->business_use_pct ?? 100)];
    }

    /** @return array{kind:string, category_id:?int, business_pct:int, n:int, of:int}|null */
    private static function majority(array $keys, int $min, float $share): ?array
    {
        if (count($keys) < $min) {
            return null;
        }
        $counts = array_count_values($keys);
        arsort($counts);
        $top = array_key_first($counts);
        if ($share > $counts[$top] / count($keys)) {
            return null;
        }

        return json_decode($top, true) + ['n' => $counts[$top], 'of' => count($keys)];
    }

    /** The distinctive first word of a merchant, e.g. "TESCO" from "TESCO STORES". */
    public static function token(string $merchantKey): ?string
    {
        foreach (explode(' ', $merchantKey) as $w) {
            if (mb_strlen($w) >= 4 && ! in_array($w, self::GENERIC, true) && $w !== 'UNKNOWN') {
                return $w;
            }
        }

        return null;
    }

    private function category(?string $code): ?int
    {
        if (! $code) {
            return null;
        }

        return $this->categories[$code] ??= Category::query()->where('code', $code)->value('id');
    }

    private static function pretty(string $bank): string
    {
        return ucfirst(mb_strtolower(str_replace('_', ' ', $bank)));
    }
}
