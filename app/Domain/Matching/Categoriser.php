<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Travel\TravelDays;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Suggests what a payment is when nothing more specific has. It is the last step of the Suggester, after your
 * rules, invoices, and the HMRC, fine, car-hire and travel patterns. In order:
 *
 *  1. what you chose before for the same merchant (for money in, the only thing used besides an invoice). A payee
 *     you've decided as a transfer is left alone: it may be an account of yours, so it's for you and the transfer
 *     check, never guessed as spending or income;
 *  2. what you chose for similar merchants: the same distinctive first word (not a person's first name or a word
 *     like BRITISH), and the same kind of merchant on the list in 4 (VIRGIN MEDIA teaches nothing about VIRGIN ACTIVE);
 *  3. how you usually treat the bank's own category for it (Starling's "Eating out", Amex's "Travel-Lodging"). Not
 *     for payments to people, and not for catch-all categories like Starling's "Payments";
 *  4. a list of common UK merchants and what they usually are;
 *  5. the bank's category.
 *
 * Meals, hotels, fuel and travel are always judged against your job days, nights away and mileage method, also
 * when they are learned from you: a lunch you decided as business while working away doesn't make a lunch at home
 * business. Only evidence makes them business: a hotel stay, the distance, a hotel naming the job's town. Without
 * it they're personal, with a note when a job might make them business. A choice counts when you made it or when
 * you accepted a suggestion (a rule's doesn't count).
 *
 * Everything is a suggestion with its reason, for you to confirm or change. The allocations it makes are marked
 * (matcher "categoriser"), or known by wording only this class uses, so learning from your decisions only ever
 * updates its own suggestions (and a rule's, when you change the rule).
 */
final class Categoriser
{
    /** What this class writes in allocations.matcher. */
    public const MARKER = 'categoriser';

    /** Words too common to say two merchants are alike. */
    private const GENERIC = ['LTD', 'LIMITED', 'PLC', 'LLP', 'UK', 'STORE', 'STORES', 'SHOP', 'THE', 'AND', 'SERVICES', 'SERVICE', 'GROUP', 'INTERNATIONAL', 'ONLINE', 'MARKETPLACE',
        'AMAZON', 'AMZN', 'PAYPAL', 'SQ', 'SUMUP', 'ZETTLE', 'IZETTLE', 'STRIPE', 'WORLDPAY', 'CRV', 'BOOKING', 'TRANSFER', 'MONEY', 'BANK', 'CASH',
        // Lead words many unrelated businesses share (BRITISH AIRWAYS and BRITISH GAS, VIRGIN MEDIA and VIRGIN ACTIVE).
        'BRITISH', 'VIRGIN', 'NATIONAL', 'ROYAL', 'GREAT', 'NORTH', 'NORTHERN', 'SOUTH', 'SOUTHERN', 'EAST', 'EASTERN', 'WEST', 'WESTERN',
        'FIRST', 'CITY', 'PREMIER', 'UNITED', 'GENERAL', 'CENTRAL', 'METRO', 'EURO', 'EUROPE', 'EUROPEAN', 'GLOBAL', 'WORLD', 'HOME', 'STAR',
        'BEST', 'GOLDEN', 'GRAND', 'COUNTY', 'COUNCIL', 'BOROUGH', 'ENGLAND', 'ENGLISH', 'SCOTLAND', 'SCOTTISH', 'WALES', 'WELSH', 'IRISH',
        'HOLDINGS', 'TRADING', 'COMPANY', 'SOLUTIONS', 'SYSTEMS', 'CENTRE', 'CENTER', 'CLUB',
        'LEEDS', 'MANCHESTER', 'BIRMINGHAM', 'GLASGOW', 'EDINBURGH', 'BRISTOL', 'LIVERPOOL', 'SHEFFIELD', 'NEWCASTLE', 'CARDIFF', 'BELFAST',
        'NOTTINGHAM', 'LEICESTER', 'YORK', 'OXFORD', 'CAMBRIDGE', 'BRIGHTON', 'READING', 'COVENTRY', 'ABERDEEN', 'DUNDEE', 'SOUTHAMPTON'];

    /** Common first names: a payee starting with one is usually a person, and people aren't "similar" to each other. */
    private const FIRST_NAMES = ['JAMES', 'JOHN', 'DAVID', 'PAUL', 'MARK', 'MICHAEL', 'ANDREW', 'PETER', 'RICHARD', 'ROBERT', 'STEVEN', 'STEPHEN',
        'THOMAS', 'CHRISTOPHER', 'CHRIS', 'DANIEL', 'MATTHEW', 'ANTHONY', 'TONY', 'SIMON', 'GARY', 'KEVIN', 'IAN', 'ALAN', 'NEIL', 'JASON',
        'LEE', 'CRAIG', 'DEAN', 'SCOTT', 'ADAM', 'BEN', 'BENJAMIN', 'SAM', 'SAMUEL', 'JOE', 'JOSEPH', 'JACK', 'HARRY', 'GEORGE', 'OLIVER',
        'CHARLIE', 'WILLIAM', 'LIAM', 'LUKE', 'RYAN', 'JAMIE', 'TOM', 'NICK', 'NICHOLAS', 'MARTIN', 'PHILIP', 'PHIL', 'KEITH', 'GRAHAM',
        'COLIN', 'BARRY', 'STUART', 'ROSS', 'KARL', 'CARL', 'SEAN', 'SHAUN', 'DARREN', 'WAYNE', 'LEON', 'ALEX', 'ALEXANDER', 'MAX', 'OSCAR',
        'ARCHIE', 'HENRY', 'EDWARD', 'ROB', 'BOB', 'DAN', 'DAVE', 'MIKE', 'MATT', 'PETE', 'STEVE', 'ANDY', 'JON', 'JONATHAN', 'TIM',
        'NATHAN', 'AARON', 'JORDAN', 'CONNOR', 'KIERAN', 'CALLUM', 'LEWIS', 'ELLIOT', 'OWEN', 'RHYS', 'GARETH', 'DYLAN', 'EVAN', 'HUGH',
        'FRASER', 'ANGUS', 'HAMISH', 'EWAN', 'IAIN', 'DOUGLAS', 'KENNETH', 'BRIAN', 'DEREK', 'TREVOR', 'GORDON', 'NIGEL', 'MALCOLM', 'ROGER',
        'TERRY', 'FRANK', 'PATRICK', 'MARY', 'SARAH', 'EMMA', 'LAURA', 'CLAIRE', 'LISA', 'RACHEL', 'HANNAH', 'JESSICA', 'AMY', 'KATE',
        'KATIE', 'SOPHIE', 'CHARLOTTE', 'LUCY', 'EMILY', 'OLIVIA', 'AMELIA', 'ISLA', 'ELLA', 'CHLOE', 'ELLIE', 'ZOE', 'NICOLA', 'JOANNE',
        'HELEN', 'SUSAN', 'JULIE', 'KAREN', 'LINDA', 'ANGELA', 'JANE', 'ANNA', 'ANNE', 'ELIZABETH', 'VICTORIA', 'REBECCA', 'GEMMA', 'NATALIE',
        'SAMANTHA', 'LOUISE', 'KELLY', 'STACEY', 'DONNA', 'TRACEY', 'MICHELLE', 'DEBORAH', 'DEBBIE', 'CAROL', 'CAROLINE', 'CATHERINE',
        'KATHERINE', 'FIONA', 'ALISON', 'ALICE', 'ABIGAIL', 'MEGAN', 'BETHANY', 'FREYA', 'ISABELLA', 'ISABEL', 'PHOEBE', 'GEORGIA',
        'JENNIFER', 'JENNY', 'NAOMI', 'LAUREN', 'ELEANOR', 'HARRIET', 'PENNY', 'MOLLY'];

    /** Words that make a payee a business rather than a person. */
    private const COMPANY_WORDS = ['LTD', 'LIMITED', 'PLC', 'LLP', 'COMPANY', 'GROUP', 'HIRE', 'EVENTS', 'SERVICES', 'PRODUCTIONS', 'AUDIO',
        'LIGHTING', 'SOUND', 'STAGE', 'MEDIA', 'STUDIO', 'STUDIOS', 'HOLDINGS', 'TRADING', 'SUPPLIES', 'HOTEL', 'CAFE', 'RESTAURANT'];

    /** Bank payment types for a payment straight to someone's account (rather than a card payment at a business). */
    private const TO_AN_ACCOUNT = '/FASTER|TRANSFER|STANDING ORDER|BACS|\bFPS\b|\bFPO\b|BANK PAYMENT/i';

    /** The bank's catch-all categories (Starling puts crew, rent, HMRC and card bills all under "Payments"): never learned. */
    private const CATCH_ALL_BANK = '/^(PAYMENTS?|TRANSFERS?|GENERAL|OTHERS?|MISC|MISCELLANEOUS|UNCATEGORI[SZ]ED|NONE|SAVINGS?|INCOME|CASH)$/';

    /** Words in job titles and hotel names that say nothing about a place ("Glasgow arena" names GLASGOW, not ARENA). */
    private const NOT_A_PLACE = ['THE', 'AND', 'SHOW', 'SHOWS', 'GIGS', 'LIVE', 'TOUR', 'EVENT', 'EVENTS', 'CONFERENCE', 'FESTIVAL', 'ARENA',
        'HALL', 'CENTRE', 'CENTER', 'THEATRE', 'THEATER', 'STADIUM', 'CLUB', 'HOTEL', 'HOTELS', 'LODGE', 'PREMIER', 'TRAVELODGE', 'HOLIDAY',
        'EXPRESS', 'AIRPORT', 'PARK', 'HOUSE', 'ROYAL', 'GRAND', 'CITY', 'CENTRAL', 'NORTH', 'SOUTH', 'EAST', 'WEST', 'GREAT', 'BRITISH',
        'NATIONAL', 'INTERNATIONAL', 'GROUP', 'LIMITED', 'DERIG', 'REHEARSAL', 'REHEARSALS', 'PRODUCTION', 'PRODUCTIONS', 'AWARDS', 'DINNER',
        'LAUNCH', 'PARTY', 'WEDDING', 'CORPORATE', 'MEETING', 'SUMMIT', 'EXPO', 'EXHIBITION', 'STAGE', 'SOUND', 'LIGHTING', 'AUDIO', 'VIDEO',
        'CREW', 'WORK', 'NIGHT', 'WEEK', 'WEEKEND', 'SPRING', 'SUMMER', 'AUTUMN', 'WINTER', 'CHRISTMAS', 'BOOKING', 'TRAVEL', 'AIRWAYS',
        'AIRLINES', 'FLIGHT', 'FLIGHTS', 'SUITES', 'RESORT', 'TOWER', 'VENUE', 'ACADEMY', 'COUNTY', 'LOCAL', 'PRIVATE', 'CLIENT'];

    /** Tax refunds and repayments: never turnover. */
    private const TAX_PAYER = '/\bHMRC\b|H\s?M\s?R\s?C|SELF ASSESSMENT|INLAND REVENUE|HM REVENUE|TAX REFUND|TAX REBATE|TAX REPAYMENT/i';

    /**
     * Common merchants: [pattern, what it usually is, category code, what]. "business_expense" is a business cost
     * whatever the day; "phone" only in part; "day", "night", "fuel" and "services" are judged against your job days.
     * The first that matches wins, so the more particular ones come first.
     */
    private const HINTS = [
        ['/\b(SCREWFIX|TOOLSTATION|WICKES|B ?& ?Q|TRAVIS PERKINS|CPC|FARNELL|RS COMPONENTS|STUDIOSPARES|CANFORD|THOMANN|GEAR4MUSIC|STAGE ELECTRICS|LIGHTING SUPERMARKET|PROLIGHT|CABLE ?CHIC|PRO AV|FROM ?THE ?DECK)\b/', 'business_expense', 'materials', 'kit, cable and consumables'],
        // Before software, so MICROSOFT*XBOX is a game, not an Office subscription.
        ['/\b(NETFLIX|SPOTIFY|DISNEY|APPLE ?TV|NOW ?TV|AMAZON PRIME|PRIME VIDEO|AUDIBLE|PLAYSTATION|XBOX|GAME ?PASS|NINTENDO|STEAM|TWITCH|DEEZER|TIDAL)\b/', 'personal', null, 'streaming or games'],
        ['/\b(ADOBE|MICROSOFT|MSFT|DROPBOX|GOOGLE ?(WORKSPACE|GSUITE|STORAGE|ONE)|ZOOM\.?US|SLACK|CANVA|NOTION|XERO|QUICKBOOKS|FREEAGENT|ICLOUD|1PASSWORD|LASTPASS|WETRANSFER|VECTORWORKS|AUTODESK|QLAB|FIGURE ?53|SOUNDCLOUD)\b/', 'business_expense', 'software', 'software or a subscription'],
        // O2 the network, not the O2 venues (THE O2, O2 ACADEMY BRIXTON...); before the supermarkets, for TESCO MOBILE.
        ['/\b(VODAFONE|EE LIMITED|EE MOBILE|(?<!THE )O2(?! ?(ACADEMY|ARENA|APOLLO|FORUM|INSTITUTE|RITZ|CITY HALL|GUILDHALL|SHEPHERD|EMPIRE|VICTORIA))|TELEFONICA|THREE\.CO|GIFFGAFF|BT GROUP|BT PLC|VIRGIN MEDIA|PLUSNET|TALKTALK|HYPEROPTIC|LEBARA|LYCAMOBILE|ID MOBILE|TESCO MOBILE|SKY MOBILE|VOXI|SMARTY)\b/', 'phone', 'phone_internet', 'phone or internet'],
        ['/\b(HISCOX|SIMPLY BUSINESS|MARKEL|ADMIRAL PRO|PHOTOGUARD|DOCTOR INSURE|INSURE ?4 ?MUSIC|ALLIANZ MUSICAL)\b/', 'business_expense', 'insurance', 'business insurance'],
        ['/\b(COMPANIES HOUSE|ICO|INFORMATION COMMISSIONER|PLASA|BECTU|EQUITY|MUSICIANS UNION|PRS|PPL|IOSH|CSCS|CITB|IPAF|PASMA)\b/', 'business_expense', 'training', 'a trade body, licence or course'],
        ['/\b(ROYAL MAIL|PARCELFORCE|DPD|EVRI|HERMES|UPS|FEDEX|DHL|POST OFFICE)\b/', 'business_expense', 'stationery', 'postage'],
        ['/\b(PREMIER INN|TRAVELODGE|HOLIDAY INN|IBIS|HILTON|MARRIOTT|NOVOTEL|MERCURE|DOUBLETREE|HAMPTON BY|RADISSON|JURYS|CROWNE PLAZA|BEST WESTERN|AIRBNB|HOTELS\.COM|EXPEDIA|LASTMINUTE)\b/', 'night', 'travel_accommodation', 'a hotel'],
        ['/\b(PRET|GREGGS|COSTA|STARBUCKS|CAFFE NERO|MCDONALDS|MCDONALD S|BURGER KING|KFC|SUBWAY|LEON|ITSU|WASABI|WHSMITH|BOOTS|UPPER CRUST|WETHERSPOON|NANDOS|WAGAMAMA|GAILS|TIM HORTONS|FIVE GUYS|DOMINOS|DELIVEROO|JUST EAT|UBER ?EATS)\b/', 'day', 'travel_subsistence', 'food or drink'],
        // SHELL the fuel station, not SHELL ENERGY the household supplier.
        ['/\b(SHELL(?! ?ENERGY)|ESSO|TEXACO|GULF|MURCO|JET PETROL|BP|TESCO PFS|SAINSBURYS PETROL|ASDA PETROL|MORRISONS PETROL|MFG)\b/', 'fuel', 'travel_fuel', 'fuel'],
        // Motorway services sell food as well as fuel: always for you to check.
        ['/\b(MOTO|WELCOME BREAK|ROADCHEF|EXTRA MSA)\b/', 'services', 'travel_fuel', 'a motorway service station (fuel or food)'],
        ['/\b(TESCO|SAINSBURY|ASDA|MORRISONS|ALDI|LIDL|WAITROSE|CO-?OP|OCADO|ICELAND|M&S|MARKS ?& ?SPENCER|SPAR|BUDGENS|FARMFOODS)\b/', 'personal', null, 'a supermarket: usually personal (a meal while working away can be business)'],
        ['/\b(GYM|PUREGYM|THE GYM GROUP|DAVID LLOYD|NUFFIELD HEALTH|ANYTIME FITNESS|BANNATYNE|VIRGIN ACTIVE)\b/', 'personal', null, 'a gym'],
        ['/\b(MONTHLY FEE|ACCOUNT FEE|OVERDRAFT FEE|BANK CHARGE|CARD FEE|FX FEE|NON-STERLING|FOREIGN TRANSACTION FEE|BUSINESS FEE|BUSINESS PLAN FEE)\b/', 'business_expense', 'finance', 'a bank or card charge'],
    ];

    /** The bank's own categories, where they say clearly what a payment is. */
    private const BANK_CATEGORIES = [
        '/^(GROCERIES|GROCERY|SUPERMARKETS?)$/i' => ['personal', null, 'groceries'],
        '/^(ENTERTAINMENT|HOBBIES|LIFESTYLE|HOLIDAYS?)$/i' => ['personal', null, 'entertainment'],
        '/LODGING|HOTEL|ACCOMMODATION/i' => ['night', 'travel_accommodation', 'a hotel'],
        '/EATING[_ ]OUT|RESTAURANT|FAST[_ ]?FOOD|CAFE|BARS?/i' => ['day', 'travel_subsistence', 'food or drink'],
        '/TAXI/i' => ['day', 'travel_taxi', 'a taxi'],
        '/AIRLINE|FLIGHTS?/i' => ['day', 'travel_flights', 'a flight'],
        '/RAIL|TRAIN/i' => ['day', 'travel_rail', 'a train'],
        '/FUEL|PETROL|GAS STATION/i' => ['fuel', 'travel_fuel', 'fuel'],
        // Revolut's own "Fee" lines (its monthly plan, a currency exchange's fee): never money moving to a pocket.
        '/^(FEES?|BANK[_ ]CHARGES?|BANK[_ ]FEES?)$/i' => ['business_expense', 'finance', 'a bank charge'],
    ];

    /** Categories that depend on the day (and night): how each is judged. Learned ones are judged the same way. */
    private const BY_THE_DAY = [
        'travel_subsistence' => 'day', 'travel_accommodation' => 'night', 'travel_fuel' => 'fuel', 'travel_flights' => 'day',
        'travel_rail' => 'day', 'travel_taxi' => 'day', 'travel_local' => 'day', 'travel_parking' => 'day', 'travel_car_hire' => 'day',
    ];

    /** Costs that belong to a job when paid on one of its days. Overheads (software, phone, insurance...) never do. */
    public const JOB_COSTS = ['materials', 'cost_of_goods', 'subcontractors', 'freelancers', 'staff', 'equipment',
        'travel_accommodation', 'travel_rail', 'travel_taxi', 'travel_local', 'travel_flights', 'travel_parking', 'travel_car_hire', 'travel_fuel', 'travel_subsistence'];

    /** How far ahead a hotel or flight is looked for a job it might have been booked for (only a note, unless it names the job's town). */
    private const BOOKED_AHEAD_DAYS = 90;

    /** @var array{merchant:array, token:array, bank:array}|null decisions of yours, for learning */
    private ?array $learned = null;

    /** @var array<int, string> the reason of each suggestion proposed since the last marking, by transaction */
    private array $proposed = [];

    /** @var array<string, ?int> */
    private array $categories = [];

    /** @var array<int, ?string> */
    private array $codes = [];

    /** @var array<string, int> */
    private array $jobsOn = [];

    private ?bool $simplifiedOnly = null;

    private ?int $phoneShare = null;

    private bool $phoneShareLoaded = false;

    public function __construct(private AllocationWriter $writer, private TravelDays $days) {}

    /** Forget what was learned (after a decision of yours), so the next suggestion uses it. */
    public function relearn(): void
    {
        $this->learned = null;
    }

    /**
     * After you decide (or accept) some payments: suggest again for the others waiting from the same merchants,
     * through the whole Suggester (your rules, invoices, HMRC and travel patterns first, then this class). Only
     * payments with nothing yet, with this class's own suggestion, or with your rule's suggestion (when you've just
     * changed the rule) are touched; a suggestion that no longer holds goes back to undecided. One that comes out the
     * same isn't written again (its wording is updated).
     *
     * @param  Collection<int, BankTransaction|mixed>  $decided
     * @return int payments whose suggestion was added, changed or taken away
     */
    public function learnFrom(Collection $decided): int
    {
        $decided = $decided->filter(fn ($t) => $t instanceof BankTransaction);
        $keys = $decided->pluck('merchant_key')->unique()->reject(fn ($k) => $k === 'UNKNOWN' || $k === null || $k === '')->values();
        if ($keys->isEmpty()) {
            return 0;
        }
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $rules = MerchantRule::query()->whereIn('merchant_key', $keys)->get()->keyBy('merchant_key');
        $waiting = BankTransaction::query()->whereIn('merchant_key', $keys)->where('review_status', '!=', 'confirmed')->whereNull('transfer_pair_id')
            ->whereNotIn('tax_year', $locked)->whereNotIn('id', $decided->pluck('id'))
            // Nothing yet, or only this class's own (unconfirmed) suggestion, or your rule's: another matcher's, the
            // AI's or yours are left alone, however they're worded.
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($q) => self::notOurs($q, $rules->pluck('id')->all())))
            ->with(['allocations', 'account'])->orderBy('id')->limit(300)->get();
        if ($waiting->isEmpty()) {
            return 0;
        }

        // Only these merchants, words and bank categories matter here: learn just those.
        $this->learned = $this->learn($this->scopeOf($waiting));
        $again = collect();
        $before = [];
        $reworded = [];
        try {
            foreach ($waiting as $tx) {
                if ($tx->allocations->isNotEmpty()) {
                    $rule = $rules->get($tx->merchant_key);
                    $ruleApplies = $rule && RuleLearner::appliesTo($rule, $tx->amount_pence);
                    $name = $tx->counterparty ?: $tx->merchant_key;
                    if ($tx->allocations->every(fn (Allocation $a) => $a->decided_by === 'rule')) {
                        // Your rule's suggestion: left as it is while the rule still says the same.
                        if ($ruleApplies && $tx->allocations->every(fn (Allocation $a) => (int) $a->merchant_rule_id === $rule->id)
                            && self::sameLines($tx->allocations, AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, $rule->work_job_id, (int) ($rule->business_pct ?? 100)))) {
                            continue;
                        }
                        $why = "Your rule for {$tx->merchant_key} changed: suggested again";
                    } else {
                        // Money in may match an invoice and a rule may now apply: those go through the whole chain again.
                        $p = $this->make($tx);
                        if ($p && $tx->amount_pence < 0 && ! $ruleApplies && self::sameLines($tx->allocations, $p['lines'])) {
                            $reason = self::fit($p['reason']);
                            $first = $tx->allocations->first();
                            if ($first->reason !== $reason || $first->matcher !== self::MARKER) {
                                $reworded[$reason][] = $tx->id; // the same suggestion: only how many times you decided it has changed
                            }

                            continue;
                        }
                        $why = 'Suggested again after your decision on another payment from '.$name;
                    }
                    $before[$tx->id] = self::lineSet($tx->allocations);
                    try {
                        // Automation clearing its own suggestion: recorded as the matcher's, not yours.
                        $this->writer->clear($tx, $why, 'matcher');
                    } catch (InvalidArgumentException) {
                        continue; // a locked year: left as it is
                    }
                }
                $again->push($tx);
            }
            foreach ($reworded as $reason => $ids) {
                Allocation::query()->whereIn('bank_transaction_id', $ids)->where('decided_by', 'matcher')->where('is_manual', false)->whereNull('confirmed_at')
                    ->where(fn ($q) => $q->whereNull('matcher')->orWhere('matcher', self::MARKER))
                    ->update(['reason' => $reason, 'matcher' => self::MARKER]);
            }
            $this->proposed = [];
            $this->suggestFor($again);
        } finally {
            $this->learned = null; // learned for these merchants only
            $this->proposed = [];
        }

        $after = Allocation::query()->whereIn('bank_transaction_id', $again->pluck('id'))->get()->groupBy('bank_transaction_id');

        return $again->filter(fn (BankTransaction $tx) => self::lineSet($after->get($tx->id, collect())) !== ($before[$tx->id] ?? []))->count();
    }

    /**
     * Allocations learning must leave alone: yours, anything confirmed, another matcher's or the AI's, and a rule's
     * other than these merchants' own rules. This class's own suggestions are marked, or (when the Suggester wrote
     * them, as an import does) known by wording only this class uses.
     *
     * @param  list<int>  $ruleIds  the rules of the merchants being learned from
     */
    private static function notOurs($q, array $ruleIds): void
    {
        $q->where('is_manual', true)->orWhereNotNull('confirmed_at')
            ->orWhereNotIn('decided_by', ['matcher', 'rule'])
            ->orWhere(fn ($q) => $q->whereNotNull('matcher')->where('matcher', '!=', self::MARKER))
            ->orWhere(fn ($q) => $q->where('decided_by', 'matcher')->whereNull('matcher')
                ->where(fn ($q) => $q->whereNull('reason')->orWhereNot(fn ($q) => self::ownWording($q))))
            ->orWhere(fn ($q) => $q->where('decided_by', 'rule')->where(fn ($q) => $q->whereNull('merchant_rule_id')->orWhereNotIn('merchant_rule_id', $ruleIds ?: [0])));
    }

    /** A reason only this class writes: what it learned from you, the bank's category, or its list of merchants. */
    private static function ownWording($q): void
    {
        $prefixes = ['Like %you decided%', 'Unlike %you decided%', 'You usually decide the bank’s%', 'Not how you usually decide the bank’s%', 'The bank calls it%'];
        foreach (self::HINTS as [, , , $what]) {
            $prefixes[] = 'Looks like '.$what.'%';
        }
        foreach ($prefixes as $p) {
            $q->orWhere('reason', 'like', $p)->orWhere('reason', 'like', 'Check: '.$p);
        }
    }

    /**
     * Suggest for lines nobody has decided, through the whole Suggester chain (never this class alone), and mark
     * this class's own suggestions. Never touches a decision of yours, a rule's, or anything confirmed.
     *
     * @param  Collection<int, BankTransaction>  $txs
     */
    public function suggestFor(Collection $txs): int
    {
        $suggester = app()->make(Suggester::class, ['categoriser' => $this]);
        $txs = $txs->filter(fn (BankTransaction $tx) => ! $tx->transfer_pair_id)->values();
        if ($txs->isEmpty()) {
            return 0;
        }
        $made = 0;
        try {
            $made = $suggester->suggestFor($txs);
        } catch (InvalidArgumentException) {
            // One payment that doesn't fit stops the batch: go one by one (those already done are skipped).
            foreach ($txs as $tx) {
                try {
                    $made += $suggester->suggestFor(collect([$tx]));
                } catch (InvalidArgumentException) {
                    // lines that don't fit, or a locked year
                }
            }
        }
        $this->markOwn();

        return $made;
    }

    /**
     * Write one of this class's proposals as a suggestion, marked as its own. The Suggester uses this for the last
     * step of its chain.
     *
     * @param  array{lines:list<array>, reason:string}  $proposal
     */
    public function suggest(BankTransaction $tx, array $proposal): bool
    {
        return DB::transaction(function () use ($tx, $proposal) {
            $done = $this->writer->decide($tx, $proposal['lines'], 'matcher', false, $proposal['reason']);
            if ($done) {
                $tx->allocations()->update(['matcher' => self::MARKER]);
            }

            return $done;
        });
    }

    /** Mark the suggestions written from this class's proposals (by the Suggester) as its own. */
    public function markOwn(): void
    {
        foreach ($this->proposed as $id => $reason) {
            Allocation::query()->where('bank_transaction_id', $id)->where('decided_by', 'matcher')->where('is_manual', false)->whereNull('confirmed_at')
                ->whereNull('matcher')->where('reason', self::fit($reason))->update(['matcher' => self::MARKER]);
        }
        $this->proposed = [];
    }

    /** @return list<string> the lines as they matter: what, where, how much, by whom */
    private static function lineSet(Collection $allocations): array
    {
        $set = $allocations->map(fn (Allocation $a) => json_encode([
            $a->kind, $a->category_id === null ? null : (int) $a->category_id, $a->work_job_id === null ? null : (int) $a->work_job_id,
            $a->invoice_id === null ? null : (int) $a->invoice_id, (int) $a->amount_pence, $a->business_use_pct === null ? null : (int) $a->business_use_pct,
            $a->decided_by, $a->merchant_rule_id === null ? null : (int) $a->merchant_rule_id, $a->confirmed_at !== null,
        ]))->sort()->values()->all();

        return $set;
    }

    /** Whether a suggestion's lines are the ones proposed (what, where, how much). */
    private static function sameLines(Collection $allocations, array $lines): bool
    {
        $norm = fn (array $l) => json_encode([$l['kind'], isset($l['category_id']) ? (int) $l['category_id'] : null, isset($l['work_job_id']) ? (int) $l['work_job_id'] : null,
            isset($l['invoice_id']) ? (int) $l['invoice_id'] : null, (int) $l['amount_pence'], isset($l['business_use_pct']) ? (int) $l['business_use_pct'] : null]);
        $have = $allocations->map(fn (Allocation $a) => $norm($a->only(['kind', 'category_id', 'work_job_id', 'invoice_id', 'amount_pence', 'business_use_pct'])))->sort()->values()->all();
        $want = collect($lines)->map(fn (array $l) => $norm($l))->sort()->values()->all();

        return $have === $want;
    }

    /** @return array{lines:list<array>, reason:string, source:string}|null */
    public function propose(BankTransaction $tx): ?array
    {
        $p = $this->make($tx);
        if ($p) {
            $this->proposed[$tx->id] = $p['reason'];
        }

        return $p;
    }

    private function make(BankTransaction $tx): ?array
    {
        $this->learned ??= $this->learn();
        $dir = $tx->amount_pence < 0 ? 'out' : 'in';
        $name = $tx->counterparty ?: $tx->merchant_key;
        $hint = $dir === 'out' ? self::hintFor($tx) : null;

        // 1. Your own decisions for this merchant. Your latest five count, so changing your mind is followed.
        $mine = $tx->merchant_key !== 'UNKNOWN' ? array_slice($this->learned['merchant'][$dir][$tx->merchant_key] ?? [], 0, 5) : [];
        if ($tx->merchant_key !== 'UNKNOWN' && $this->decidedAsTransfer($tx->merchant_key)) {
            // A payee you've decided as a transfer may be an account of yours: never guessed as spending or income
            // (through similar merchants or the bank's category either). It's for you and the transfer check.
            return null;
        }
        $shape = self::majority($mine, 1, 0.6);
        if ($shape && ! ($shape['kind'] === 'business_income' && preg_match(self::TAX_PAYER, $tx->description.' '.$tx->counterparty))) {
            $times = $shape['n'] === $shape['of'] ? "the {$shape['of']} ".($shape['of'] === 1 ? 'time' : 'times') : "{$shape['n']} of the last {$shape['of']} times";

            return $this->fromLearned($tx, $shape, ["Like {$times} you decided {$name}", "Unlike {$times} you decided {$name}"], 'merchant', $hint);
        }
        if (OwnAccounts::reason($tx)) {
            // Your own money moving: the transfer check deals with it. Only your own decisions for this merchant
            // (above) come first: a phone top-up you always decide as a phone bill stays one.
            return null;
        }
        if ($dir === 'in') {
            return null; // money in: an invoice (matched before this) or your own decisions for the same payer, nothing looser
        }
        // 2. Similar merchants you've decided.
        if (($bucket = self::bucket($tx)) && ($shape = self::majority($this->learned['token'][$bucket] ?? [], 3, 0.75))) {
            $token = strstr($bucket, '|', true);

            return $this->fromLearned($tx, $shape, ["Like the other {$token} payments you decided ({$shape['n']} of {$shape['of']})", "Unlike the other {$token} payments you decided ({$shape['n']} of {$shape['of']})"], 'similar', $hint);
        }
        // 3. How you treat the bank's own category: not for a payment to a person (crew and your landlord share the
        // bank's "Payments"), nor for a catch-all category.
        $bank = trim((string) $tx->bank_category);
        if (self::learnableBank($bank) && ! self::paysAPerson($tx) && ($shape = self::majority($this->learned['bank'][mb_strtoupper($bank)] ?? [], 3, 0.75))) {
            $pretty = self::pretty($bank);

            return $this->fromLearned($tx, $shape, ["You usually decide the bank’s “{$pretty}” payments this way ({$shape['n']} of {$shape['of']})", "Not how you usually decide the bank’s “{$pretty}” payments ({$shape['n']} of {$shape['of']})"], 'bank_learned', $hint);
        }
        // 4. Common merchants, then the bank's category.
        if ($hint) {
            return $this->fromHint($tx, $hint['kind'], $hint['code'], 'Looks like '.$hint['what']);
        }
        if (($fromBank = self::bankHint($bank)) !== null) {
            return $this->fromHint($tx, $fromBank['kind'], $fromBank['code'], 'The bank calls it “'.self::pretty($bank).'”');
        }

        return null;
    }

    /**
     * A suggestion from what you decided before. Meals, hotels, fuel and travel are judged against this payment's
     * day like any other; your choice wins where you made it on a day like this one.
     *
     * @param  array{kind:string, category_id:?int, business_pct:int, n:int, of:int, dates:list<string>, job:bool}  $shape
     * @param  array{0:string, 1:string}  $subject  how to say it when following your choice, and when not
     */
    private function fromLearned(BankTransaction $tx, array $shape, array $subject, string $source, ?array $hint): ?array
    {
        $date = $tx->effectiveDate()->format('Y-m-d');
        $code = $this->code($shape['category_id']);
        [$kind, $judgeCode] = $this->judgedAs($shape, $code, $hint, $tx);

        if ($kind === null) {
            // Not a matter of the day: as you decided it, with the job only if yours had one.
            $job = null;
            $note = '';
            if ($shape['kind'] === 'business_expense' && $shape['job']) {
                $v = $this->days->day($date);
                [$job, $note] = $v['verdict'] === 'business' ? $this->onlyJob($v['job'], $date) : [null, ''];
            }

            return $this->proposal(AllocationWriter::lines($tx->amount_pence, $shape['kind'], $shape['category_id'], $job?->id, $shape['business_pct']),
                $subject[0].($job ? ', on a day of '.$job->title : '').$note, $source);
        }

        $j = $this->judge($kind, $judgeCode, $date, self::text($tx));
        $judgedBusiness = $j['verdict'] !== 'personal';
        $learnedBusiness = $shape['kind'] === 'business_expense';
        $categoryId = $learnedBusiness ? $shape['category_id'] : $this->category($judgeCode);
        $pct = $learnedBusiness ? $shape['business_pct'] : 100;

        if ($learnedBusiness === $judgedBusiness) {
            return $this->judged($tx, $j, $categoryId, $pct, $subject[0], $source);
        }
        // Simplified mileage already covers fuel in your own vehicle, whatever you decided for a hire car's.
        $simplified = in_array($kind, ['fuel', 'services'], true) && $this->simplifiedOnly();
        if (! $simplified && $this->decidedOnDaysLike($shape['dates'], $kind, $judgeCode, $judgedBusiness)) {
            $lines = $learnedBusiness
                ? AllocationWriter::lines($tx->amount_pence, 'business_expense', $shape['category_id'], null, $shape['business_pct'])
                : AllocationWriter::lines($tx->amount_pence, $shape['kind'], $shape['category_id']);

            return $this->proposal($lines, $subject[0].', on days like this one', $source);
        }

        // Your choices were on other kinds of days: this one is judged on its own.
        return $this->judged($tx, $j, $categoryId, $pct, $subject[1], $source);
    }

    /**
     * How a learned shape is judged against the day: [kind, category code], or [null, null] when the day doesn't
     * matter. A business shape by its category (a meal, a hotel, fuel, travel); a personal one by what the merchant
     * is (a café you always decide as personal is still a café on a day working away).
     *
     * @return array{0:?string, 1:?string}
     */
    private function judgedAs(array $shape, ?string $code, ?array $hint, BankTransaction $tx): array
    {
        if ($shape['kind'] === 'business_expense') {
            return isset(self::BY_THE_DAY[$code]) ? [self::BY_THE_DAY[$code], $code] : [null, null];
        }
        if ($shape['kind'] !== 'personal') {
            return [null, null];
        }
        if ($hint && in_array($hint['kind'], ['day', 'night', 'fuel', 'services'], true)) {
            return [$hint['kind'], $hint['code']];
        }
        $bank = self::bankHint(trim((string) $tx->bank_category));
        if ($bank && in_array($bank['kind'], ['day', 'night', 'fuel'], true)) {
            return [$bank['kind'], $bank['code']];
        }

        return [null, null];
    }

    /** Whether any of your decisions (by date) was on a day judged the same way as this one. */
    private function decidedOnDaysLike(array $dates, string $kind, ?string $code, bool $business): bool
    {
        foreach ($dates as $d) {
            if (($this->judge($kind, $code, $d)['verdict'] !== 'personal') === $business) {
                return true;
            }
        }

        return false;
    }

    /** A common-merchant (or bank-category) hint, judged against your job days where it depends on them. */
    private function fromHint(BankTransaction $tx, string $kind, ?string $code, string $subject): array
    {
        $date = $tx->effectiveDate()->format('Y-m-d');
        if ($kind === 'personal') {
            return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'personal'), $subject.': usually personal', 'hint');
        }
        if ($kind === 'phone') {
            // Only the business share of a phone or broadband bill counts: never the whole bill by default.
            if (($share = $this->phoneShare()) !== null) {
                return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'business_expense', $this->category($code), null, $share),
                    "Check: {$subject}: only the business share counts. {$share}% business, as you decided your last phone or internet bill", 'hint');
            }

            return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'personal'),
                "Check: {$subject}: only the business share counts, so split it into business and personal (left as personal until you do)", 'hint');
        }
        if ($kind === 'business_expense') {
            // A job's own costs (kit, travel) go with the job on its day; overheads never do.
            $job = null;
            $note = '';
            if (in_array($code, self::JOB_COSTS, true)) {
                $v = $this->days->day($date);
                [$job, $note] = $v['verdict'] === 'business' ? $this->onlyJob($v['job'], $date) : [null, ''];
            }

            return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'business_expense', $this->category($code), $job?->id),
                $subject.': usually a business cost'.($job ? ', on a day of '.$job->title : '').$note, 'hint');
        }

        return $this->judged($tx, $this->judge($kind, $code, $date, self::text($tx)), $this->category($code), 100, $subject, 'hint');
    }

    /** Lines and reason from a judgement of the day. */
    private function judged(BankTransaction $tx, array $j, ?int $categoryId, int $pct, string $subject, string $source): array
    {
        $check = $j['check'] ? 'Check: ' : '';
        if ($j['verdict'] === 'personal') {
            return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'personal'), $check.$subject.$j['text'], $source);
        }
        [$job, $note] = $this->onlyJob($j['job'], $tx->effectiveDate()->format('Y-m-d'));

        return $this->proposal(AllocationWriter::lines($tx->amount_pence, 'business_expense', $categoryId, $job?->id, $pct), $check.$subject.$j['text'].$note, $source);
    }

    /**
     * A meal, hotel, fuel or travel payment on its date: business (with its job), personal, or business for you to
     * check. "text" goes straight after what it is ("Looks like a hotel" + text). $text is the payment's own wording
     * (a hotel naming the job's town); without it (your past decisions, by date) only the date counts.
     *
     * @return array{verdict:string, job:?WorkJob, text:string, check:bool}
     */
    private function judge(string $kind, ?string $code, string $date, ?string $text = null): array
    {
        if (in_array($kind, ['fuel', 'services'], true) && $this->simplifiedOnly()) {
            return ['verdict' => 'personal', 'job' => null, 'check' => $kind === 'services',
                'text' => ': you claim simplified mileage, which already covers fuel (fuel for a hire car is claimable: change it if so)'];
        }
        $v = $kind === 'night' ? $this->nightOf($date, $text) : $this->days->day($date);
        if ($v['verdict'] === 'personal' && $code === 'travel_flights') {
            $v = $this->bookedAhead($date, $text) ?? $v; // flights are booked weeks ahead
        }
        if ($v['verdict'] === 'personal') {
            // A job it may have been booked for is only a note: personal until you say it was for the job.
            return isset($v['maybe'])
                ? ['verdict' => 'personal', 'job' => null, 'check' => true, 'text' => ", {$v['maybe']}"]
                : ['verdict' => 'personal', 'job' => null, 'check' => $kind === 'services', 'text' => ", with {$v['reason']}: personal"];
        }
        if ($kind === 'day' && $code === 'travel_subsistence') {
            // Meals are only allowable when you're away from your normal pattern, usually overnight.
            return match ($this->awayOvernight($v['job'], $date)) {
                'no' => ['verdict' => 'personal', 'job' => null, 'check' => false, 'text' => ", {$v['reason']}, but not while away overnight, so usually not allowable"],
                'maybe' => ['verdict' => 'personal', 'job' => null, 'check' => true, 'text' => ", {$v['reason']}: personal unless you stayed away overnight for it"],
                'likely' => ['verdict' => 'review', 'job' => $v['job'], 'check' => true, 'text' => ", {$v['reason']}: allowable only if you were away overnight"],
                default => ['verdict' => $v['verdict'], 'job' => $v['job'], 'check' => $v['verdict'] === 'review', 'text' => ", {$v['reason']}, while away overnight"],
            };
        }
        $extra = $kind === 'services' ? ': fuel is a travel cost, but food there only while away overnight' : '';

        return ['verdict' => $v['verdict'], 'job' => $v['job'], 'check' => $v['verdict'] === 'review' || $kind === 'services', 'text' => ", {$v['reason']}{$extra}"];
    }

    /** A hotel by the night it was paid for, or the night before (paid at checkout), or a job soon after (booked ahead). */
    private function nightOf(string $date, ?string $text = null): array
    {
        $v = $this->days->night($date);
        if ($v['verdict'] !== 'personal') {
            return $v;
        }
        $before = $this->days->night(CarbonImmutable::parse($date)->subDay()->format('Y-m-d'));
        if ($before['verdict'] !== 'personal' && $before['job']) {
            return ['verdict' => 'review', 'job' => $before['job'],
                'reason' => 'paid the morning after a night for '.self::jobName($before['job']).' (hotels often charge at checkout): business if the stay was for it'];
        }

        return $this->bookedAhead($date, $text) ?? $v;
    }

    /**
     * A job starting within a few weeks, away from home, that a hotel or flight paid now may be for. Paying ahead of a
     * job is weak evidence (holidays are booked ahead too): business, for you to check, only when the payment names
     * the job's town (PREMIER INN GLASGOW ahead of "Glasgow arena"). Otherwise it stays personal, with a note naming
     * the job ("maybe"), and no job attached.
     *
     * @return array{verdict:string, job:?WorkJob, reason:string, maybe?:string}|null
     */
    private function bookedAhead(string $date, ?string $text): ?array
    {
        $d = CarbonImmutable::parse($date);
        $near = (float) Setting::get('overnight_near_miles');
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '>', $date)
            ->whereDate('starts_on', '<=', $d->addDays(self::BOOKED_AHEAD_DAYS)->format('Y-m-d'))->orderBy('starts_on')->limit(5)->get()
            ->filter(function (WorkJob $job) use ($near) {
                $miles = $this->days->milesFromHome($job);

                return $miles === null || $miles >= $near;
            });
        if ($jobs->isEmpty()) {
            return null;
        }
        $words = $text !== null ? self::words($text) : [];
        foreach ($jobs as $job) {
            $place = array_values(array_intersect(self::placeWords($job), $words));
            if ($place) {
                return ['verdict' => 'review', 'job' => $job,
                    'reason' => 'paid ahead of '.self::jobName($job).' on '.$job->starts_on->format('j M').' and naming '.ucfirst(mb_strtolower($place[0])).': business if you booked it for that job'];
            }
        }
        $job = $jobs->first();

        return ['verdict' => 'personal', 'job' => null, 'reason' => 'no job that day or the next',
            'maybe' => 'maybe booked ahead for '.self::jobName($job).' on '.$job->starts_on->format('j M').': personal unless it was for that job'];
    }

    /** Words in a job's title and location that may name its town (GLASGOW from "Glasgow arena"). */
    private static function placeWords(WorkJob $job): array
    {
        return array_values(array_filter(self::words($job->title.' '.$job->location), fn (string $w) => mb_strlen($w) >= 4 && ! in_array($w, self::NOT_A_PLACE, true)));
    }

    /** @return list<string> the words of some text, in capitals */
    private static function words(string $text): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^A-Z]+/', mb_strtoupper($text)) ?: [])));
    }

    /**
     * Whether you were away overnight for a job on that date, on evidence: "yes" with a hotel stay for it that night or
     * the night before, or when the job is far from home; "likely" (for you to check) when it's a known middling
     * distance and that night or the one before counts as a business night (between two working days, or an early
     * start or late finish your settings count); "no" when it's near home; "maybe" otherwise: the distance unknown, or
     * only nights that need review. "maybe" stays personal, with a note.
     */
    private function awayOvernight(?WorkJob $job, string $date): string
    {
        if (! $job) {
            return 'no';
        }
        $prev = CarbonImmutable::parse($date)->subDay()->format('Y-m-d');
        $stayed = TravelItem::query()->where('kind', 'hotel')->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('work_job_id', $job->id)->orWhere('decision', 'business'))
            ->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'))
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereDate('ends_on', '>=', $date)->orWhere(fn ($q) => $q->whereNull('ends_on')->whereDate('starts_on', '>=', $prev)))
            ->exists();
        if ($stayed) {
            return 'yes';
        }
        $miles = $this->days->milesFromHome($job);
        if ($miles === null) {
            return 'maybe';
        }
        if ($miles > (float) Setting::get('overnight_far_miles')) {
            return 'yes';
        }
        if ($miles < (float) Setting::get('overnight_near_miles')) {
            return 'no';
        }
        foreach ([$date, $prev] as $night) {
            if ($this->days->night($night)['verdict'] === 'business') {
                return 'likely';
            }
        }

        return 'maybe';
    }

    /**
     * The job to attach, if it's the only one working that day.
     *
     * @return array{0:?WorkJob, 1:string} the job and a note for the reason
     */
    private function onlyJob(?WorkJob $job, string $date): array
    {
        if (! $job) {
            return [null, ''];
        }
        $this->jobsOn[$date] ??= WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->with('days')->get()->filter(fn (WorkJob $j) => $j->worksOn($date))->count();

        return $this->jobsOn[$date] > 1 ? [null, " ({$this->jobsOn[$date]} jobs that day: link the right one)"] : [$job, ''];
    }

    private function proposal(array $lines, string $reason, string $source): array
    {
        return ['lines' => $lines, 'reason' => $reason, 'source' => $source];
    }

    /** Whether you only claim simplified mileage (which covers fuel) for your own vehicles. */
    private function simplifiedOnly(): bool
    {
        return $this->simplifiedOnly ??= Vehicle::query()->where('method', 'simplified')->exists() && ! Vehicle::query()->where('method', '!=', 'simplified')->exists();
    }

    /** The business share of the last phone or internet bill you decided (or accepted), if any. */
    private function phoneShare(): ?int
    {
        if ($this->phoneShareLoaded) {
            return $this->phoneShare;
        }
        $this->phoneShareLoaded = true;
        $phone = $this->category('phone_internet');
        $last = $phone ? Allocation::query()->where('category_id', $phone)->where('kind', 'business_expense')->whereNotNull('bank_transaction_id')
            ->whereNotNull('confirmed_at')->whereIn('decided_by', ['you', 'matcher', 'ai'])->orderByDesc('id')->first() : null;
        if ($last) {
            $tx = BankTransaction::query()->with('allocations')->find($last->bank_transaction_id);
            $shape = $tx ? self::shape($tx) : null;
            $this->phoneShare = $shape && $shape['kind'] === 'business_expense' ? $shape['business_pct'] : null;
        }

        return $this->phoneShare;
    }

    /**
     * Your decisions on confirmed payments (the last three years): by merchant, by similar merchant and by the bank's
     * category. A decision is yours when you made it, or accepted a suggestion; one confirmed by a rule isn't.
     * Transfers are only kept per payee, to leave that payee alone: they're about the accounts, which the transfer
     * check looks at. Payments to people and catch-all bank categories aren't learned by the bank's category.
     *
     * @param  array{keys:list<string>, tokens:list<string>, banks:list<string>}|null  $scope  only what's needed for these
     */
    private function learn(?array $scope = null): array
    {
        $out = ['merchant' => [], 'token' => [], 'bank' => []];
        $query = BankTransaction::query()->where('review_status', 'confirmed')->where('posted_on', '>=', now()->subYears(3)->format('Y-m-d'))
            ->with('allocations:id,bank_transaction_id,kind,category_id,work_job_id,amount_pence,business_use_pct,decided_by,is_manual,confirmed_at')
            ->select(['id', 'merchant_key', 'bank_category', 'amount_pence', 'description', 'counterparty', 'payment_type', 'posted_on', 'transaction_on']);
        if ($scope) {
            $query->where(function ($q) use ($scope) {
                $q->whereIn('merchant_key', $scope['keys'] ?: ['']);
                foreach ($scope['tokens'] as $token) {
                    $q->orWhere('merchant_key', 'like', '%'.$token.'%');
                }
                if ($scope['banks']) {
                    $q->orWhereIn(DB::raw('UPPER(TRIM(bank_category))'), $scope['banks']);
                }
            });
        }
        $query->orderByDesc('posted_on')->orderByDesc('id')->limit(8000)->get()
            ->each(function (BankTransaction $t) use (&$out) {
                if (! self::yours($t->allocations) || ! ($shape = self::shape($t))) {
                    return;
                }
                $dir = $t->amount_pence < 0 ? 'out' : 'in';
                $transfer = $shape['kind'] === 'transfer';
                $entry = ['k' => json_encode($shape), 'd' => $t->effectiveDate()->format('Y-m-d'), 'j' => $t->allocations->contains(fn (Allocation $a) => $a->work_job_id !== null), 't' => $transfer];
                if ($t->merchant_key !== 'UNKNOWN') {
                    $out['merchant'][$dir][$t->merchant_key][] = $entry; // a transfer only to leave that payee alone
                }
                if ($dir === 'in' || $transfer) {
                    return; // money in is only learned per payer; a transfer is about the accounts, not the merchant
                }
                if ($bucket = self::bucket($t)) {
                    $out['token'][$bucket][] = $entry;
                }
                if (self::learnableBank((string) $t->bank_category) && ! self::paysAPerson($t)) {
                    $out['bank'][mb_strtoupper(trim($t->bank_category))][] = $entry;
                }
            });

        return $out;
    }

    /** What learning needs for these payments: their merchants, distinctive words and bank categories. */
    private function scopeOf(Collection $txs): array
    {
        return [
            'keys' => $txs->pluck('merchant_key')->unique()->values()->all(),
            'tokens' => $txs->map(fn (BankTransaction $t) => self::token($t->merchant_key, $t->payment_type))->filter()->unique()->values()->all(),
            'banks' => $txs->map(fn (BankTransaction $t) => mb_strtoupper(trim((string) $t->bank_category)))->filter()->unique()->values()->all(),
        ];
    }

    /** Whether these lines were your choice: made by you, or a matcher's or the AI's suggestion you accepted. */
    private static function yours(Collection $allocations): bool
    {
        return $allocations->isNotEmpty() && $allocations->every(fn (Allocation $a) => $a->decided_by === 'you'
            || ($a->confirmed_at !== null && in_array($a->decided_by, ['matcher', 'ai'], true)));
    }

    /**
     * A decision's shape: what it is, the category, and the business share. A split you typed by amounts (a
     * business part and a personal part) keeps its share, worked out from the amounts.
     *
     * @return array{kind:string, category_id:?int, business_pct:int}|null
     */
    private static function shape(BankTransaction $t): ?array
    {
        $lines = $t->allocations;
        $kinds = $lines->pluck('kind')->sort()->values()->all();
        if ($kinds === [] || count($kinds) > 2 || (count($kinds) === 2 && ! in_array('personal', $kinds, true))) {
            return null;
        }
        $main = $lines->firstWhere('kind', '!=', 'personal') ?? $lines->first();
        $pct = 100;
        if (in_array($main->kind, Allocation::NEEDS_CATEGORY, true)) {
            $total = (int) $lines->sum('amount_pence');
            $pct = $lines->count() === 2 && $total !== 0
                ? max(1, min(100, (int) round(100 * (int) $main->amount_pence / $total)))
                : (int) ($main->business_use_pct ?? 100);
        }

        return ['kind' => $main->kind, 'category_id' => $main->category_id === null ? null : (int) $main->category_id, 'business_pct' => $pct];
    }

    /** @return array{kind:string, category_id:?int, business_pct:int, n:int, of:int, dates:list<string>, job:bool}|null */
    private static function majority(array $entries, int $min, float $share): ?array
    {
        if (count($entries) < $min) {
            return null;
        }
        $counts = array_count_values(array_column($entries, 'k'));
        arsort($counts);
        $top = array_key_first($counts);
        if ($share > $counts[$top] / count($entries)) {
            return null;
        }
        $winning = array_values(array_filter($entries, fn (array $e) => $e['k'] === $top));
        $withJob = count(array_filter($winning, fn (array $e) => $e['j']));

        return json_decode($top, true) + ['n' => $counts[$top], 'of' => count($entries), 'dates' => array_slice(array_column($winning, 'd'), 0, 5), 'job' => $withJob * 2 >= count($winning)];
    }

    /**
     * The distinctive first word of a merchant, e.g. "TESCO" from "TESCO STORES". None for a person (or a business
     * named after one), and none for words many businesses share.
     */
    public static function token(string $merchantKey, ?string $paymentType = null): ?string
    {
        if ($merchantKey === 'UNKNOWN' || self::looksLikePerson($merchantKey, $paymentType)) {
            return null;
        }
        foreach (explode(' ', $merchantKey) as $w) {
            if (in_array($w, self::FIRST_NAMES, true)) {
                return null;
            }
            if (mb_strlen($w) >= 4 && ! in_array($w, self::GENERIC, true)) {
                return $w;
            }
        }

        return null;
    }

    /** "TESCO|11": a distinctive word and which kind of merchant on the list it is, so like is only compared with like. */
    private static function bucket(BankTransaction $tx): ?string
    {
        $token = self::token((string) $tx->merchant_key, $tx->payment_type);

        return $token ? $token.'|'.(self::hintFor($tx)['i'] ?? '-') : null;
    }

    /** Whether the payee looks like a person: a first name and a surname, or a payment to someone's account by name. */
    private static function looksLikePerson(string $merchantKey, ?string $paymentType): bool
    {
        $words = array_values(array_filter(explode(' ', $merchantKey)));
        if (array_intersect($words, self::COMPANY_WORDS)) {
            return false;
        }
        if (count($words) === 2 && in_array($words[0], self::FIRST_NAMES, true)) {
            return true;
        }

        return $paymentType !== null && preg_match(self::TO_AN_ACCOUNT, $paymentType) === 1 && in_array(count($words), [2, 3], true)
            && preg_match('/^[A-Z ]+$/', $merchantKey) === 1;
    }

    /**
     * Whether the payment goes to a person rather than a business: a payee that looks like a person, paid to their
     * account or with no payment type. A card payment or direct debit is at a business, whatever its name.
     */
    private static function paysAPerson(BankTransaction $tx): bool
    {
        $type = trim((string) $tx->payment_type);
        if ($type !== '' && preg_match(self::TO_AN_ACCOUNT, $type) !== 1) {
            return false;
        }

        return self::looksLikePerson((string) $tx->merchant_key, $tx->payment_type);
    }

    /** @return array{i:int, kind:string, code:?string, what:string}|null the merchant on the common list */
    private static function hintFor(BankTransaction $tx): ?array
    {
        $text = mb_strtoupper($tx->description.' '.$tx->counterparty);
        foreach (self::HINTS as $i => [$pattern, $kind, $code, $what]) {
            if (preg_match($pattern, $text, $m)) {
                return self::namesAPerson($tx, $m[0]) ? null : ['i' => $i, 'kind' => $kind, 'code' => $code, 'what' => $what];
            }
        }

        return null;
    }

    /**
     * Whether a match on the list is really a person who shares the name: LEON JONES and SARAH COSTA are crew, not a
     * LEON or COSTA café. A listed name of two words (TIM HORTONS, DAVID LLOYD, COMPANIES HOUSE) is the business,
     * unless it's paid straight to someone's account under a first name (David Lloyd the sound engineer).
     */
    private static function namesAPerson(BankTransaction $tx, string $matched): bool
    {
        if (! self::paysAPerson($tx)) {
            return false;
        }
        $listed = array_values(array_filter(explode(' ', trim(preg_replace('/\s+/', ' ', $matched)))));
        if (count($listed) > 1) {
            $type = trim((string) $tx->payment_type);

            return $type !== '' && in_array($listed[0], self::FIRST_NAMES, true);
        }
        $first = explode(' ', (string) $tx->merchant_key)[0];

        return in_array($listed[0] ?? '', self::FIRST_NAMES, true) || in_array($first, self::FIRST_NAMES, true);
    }

    /** Whether the bank's category says something about the payment (not a catch-all like "Payments"). */
    private static function learnableBank(string $bank): bool
    {
        $bank = mb_strtoupper(trim(str_replace('_', ' ', $bank)));

        return $bank !== '' && preg_match(self::CATCH_ALL_BANK, $bank) !== 1;
    }

    /** Whether any of your latest decisions for this payee, money in or out, was a transfer. */
    private function decidedAsTransfer(string $merchantKey): bool
    {
        foreach (['out', 'in'] as $dir) {
            foreach (array_slice($this->learned['merchant'][$dir][$merchantKey] ?? [], 0, 5) as $e) {
                if ($e['t'] ?? false) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The payment's own wording: its description and the payee. */
    private static function text(BankTransaction $tx): string
    {
        return $tx->description.' '.$tx->counterparty;
    }

    /** @return array{kind:string, code:?string, what:string}|null */
    private static function bankHint(string $bank): ?array
    {
        if ($bank === '') {
            return null;
        }
        foreach (self::BANK_CATEGORIES as $pattern => [$kind, $code, $what]) {
            if (preg_match($pattern, $bank)) {
                return ['kind' => $kind, 'code' => $code, 'what' => $what];
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

    private function code(?int $categoryId): ?string
    {
        if (! $categoryId) {
            return null;
        }

        return $this->codes[$categoryId] ??= Category::query()->whereKey($categoryId)->value('code');
    }

    private static function jobName(WorkJob $job): string
    {
        return $job->title.' (job '.$job->id.')';
    }

    /** As AllocationWriter stores a reason: shortened to fit its column. */
    private static function fit(string $text, int $max = 255): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private static function pretty(string $bank): string
    {
        return ucfirst(mb_strtolower(str_replace('_', ' ', $bank)));
    }
}
