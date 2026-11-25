<?php

namespace App\Domain\Quick;

use App\Domain\Jobs\DateWords;
use App\Domain\Jobs\JobDays;
use App\Models\Account;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A cost in a few words, as you'd text it ("£12.50 parking Leeds", "12.50 parking", "€15 taxi Berlin", "parking £5",
 * "£5 cash parking yesterday"), read by fixed rules, never by AI. The phone reads shared text by the same rules
 * (public/quick-store.js parseText), and both are checked against tests/fixtures/quick-text.json.
 *
 *  - The amount comes first (optionally after "spent" or "paid"), or last in a short message (six words or fewer) when
 *    it says its currency or has pence. A decimal comma ("12,50") is refused, as Money::parse refuses it, and a
 *    question ("How much did I spend on parking?") or a /command is never a cost: those go to the assistant.
 *  - "cash" and "personal" say how you paid; "card", "business" or an account's name say the business card.
 *  - "today", "yesterday", a weekday or a date ("23 Nov") say the day; otherwise it's today. A day without a year is
 *    the last one on or before today.
 *  - The rest is what it was.
 */
final class QuickText
{
    /** Currencies taken by their code in any case; any other ISO code only in capitals ("15 CHF"). */
    private const CODES = ['gbp', 'eur', 'usd', 'chf', 'cad', 'aud', 'nzd', 'jpy', 'sek', 'nok', 'dkk', 'pln', 'czk', 'huf', 'aed', 'isk'];

    private const SYMBOLS = ['£' => 'GBP', '€' => 'EUR', '$' => 'USD'];

    /** The most words in a message whose amount comes last. */
    public const SHORT_WORDS = 6;

    /** A message starting with one of these is a question or a request: it goes to the assistant, "?" or not. */
    public const ASKING = ['how', 'what', 'whats', 'what’s', 'what\'s', 'why', 'when', 'where', 'which', 'who', 'whose', 'can', 'could', 'should', 'would', 'shall',
        'do', 'does', 'did', 'is', 'are', 'was', 'were', 'will', 'has', 'have', 'am', 'tell', 'show', 'explain', 'please'];

    /** A currency written as a word after the amount ("15 euros taxi"). */
    public const CURRENCY_WORDS = ['euro' => 'EUR', 'euros' => 'EUR', 'dollar' => 'USD', 'dollars' => 'USD', 'bucks' => 'USD', 'pound' => 'GBP', 'pounds' => 'GBP',
        'quid' => 'GBP', 'yen' => 'JPY'];

    /** Other currencies' names: never booked in pounds, so the message goes to the assistant. */
    private const OTHER_MONEY_WORDS = ['franc', 'francs', 'krona', 'kronor', 'krone', 'kroner', 'zloty', 'zlotys', 'forint', 'forints', 'dirham', 'dirhams', 'rupee', 'rupees',
        'lira', 'rand', 'peso', 'pesos', 'yuan', 'renminbi', 'ringgit', 'baht', 'shekel', 'shekels', 'koruna', 'cents', 'pence'];

    /** Before an amount that comes last, these make it a sum being asked about ("tax on £1,200", "receipts under £10"). */
    private const NOT_BEFORE_LAST = ['on', 'of', 'over', 'under', 'than', 'about', 'per', 'is', 'was'];

    /** Before a plain whole number that comes last, these make it a time ("bus at 8", "taxi for 6"). */
    private const NOT_BEFORE_NUMBER = ['at', 'by', 'for', 'from', 'to', 'until', 'till', 'before', 'after', 'around'];

    /** Words that say what a cost was: a plain whole number after one of them is an amount in pounds ("parking 8"). */
    public const COST_WORDS = ['parking', 'park', 'toll', 'tolls', 'fuel', 'petrol', 'diesel', 'taxi', 'cab', 'uber', 'train', 'tube', 'bus', 'tram', 'ferry', 'hotel', 'food',
        'lunch', 'dinner', 'breakfast', 'coffee', 'snack', 'sandwich', 'meal', 'kit', 'cable', 'cables', 'tape', 'batteries', 'stamps', 'postage'];

    /** The most an amount may be from the phone or a text (the keypad's limit, £9,999,999): hundredths of its currency. */
    public const MAX_HUNDREDTHS = 999_999_999;

    private const WEEKDAYS = ['monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'wednesday' => 3, 'wed' => 3, 'weds' => 3, 'thursday' => 4, 'thu' => 4,
        'thur' => 4, 'thurs' => 4, 'friday' => 5, 'fri' => 5, 'saturday' => 6, 'sat' => 6, 'sunday' => 7, 'sun' => 7];

    /**
     * @param  array<string, int>  $accounts  account names (lower case) => id, for "Starling" or "Amex" in the text
     * @return array{minor:int, currency:string, amount:string, what:string, paid_with:?string, account_id:?int, date:string, date_word:?string}|null
     */
    public static function parse(string $text, CarbonImmutable $today, array $accounts = []): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || str_starts_with($text, '/') || str_contains($text, '?') || mb_strlen($text) > 300) {
            return null;
        }
        // A bank's notification pasted in ("You spent £12.50 at NCP"): the amount comes after "spent".
        $text = preg_replace('/^(?:you|i)\s+(?=(?:spent|paid)\b)/iu', '', $text) ?? $text;
        if (in_array(mb_strtolower(trim((string) strtok($text, ' '), '.,;:!')), self::ASKING, true)) {
            return null; // "can I claim parking £12.50": a question, even without its "?"
        }
        $amount = '(?:([£€$])\s?)?(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,3}))?(?:\s?([A-Za-z]{3}))?';
        $first = null;
        if (preg_match('/^(?:(?:spent|paid)\s+)?'.$amount.'(?=\s|$)(.*)$/iu', $text, $m)) {
            $first = self::amountOf($m[1], $m[2], $m[3] ?? '', $m[4] ?? '');
            $rest = trim($m[5] ?? '');
            if ($first === null && ($m[4] ?? '') !== '') {
                // "12.50 car park": a word, not a currency code.
                $first = self::amountOf($m[1], $m[2], $m[3] ?? '', '');
                $rest = trim($m[4].($m[5] ?? ''));
            }
            // A currency written as a word ("15 euros taxi"): that currency, never pounds by mistake.
            $word = mb_strtolower(trim((string) strtok($rest, ' '), '.,;:!'));
            if ($first !== null && $word !== '' && ($m[4] ?? '') === '' && (isset(self::CURRENCY_WORDS[$word]) || in_array($word, self::OTHER_MONEY_WORDS, true))) {
                $named = self::CURRENCY_WORDS[$word] ?? null;
                if ($named === null || ($m[1] !== '' && self::SYMBOLS[$m[1]] !== $named)) {
                    return null;
                }
                $first = self::amountOf($m[1], $m[2], $m[3] ?? '', $named);
                $rest = trim(mb_substr($rest, mb_strlen((string) strtok($rest, ' '))));
            }
        }
        if ($first === null) {
            if (! preg_match('/^(.+?)\s'.$amount.'$/iu', $text, $m) || count(preg_split('/\s+/', trim($m[1]))) + 1 > self::SHORT_WORDS) {
                return null;
            }
            $before = array_map(fn ($w) => trim($w, '.,;:!'), preg_split('/\s+/', mb_strtolower(trim($m[1]))) ?: []);
            $last = (string) end($before);
            if (in_array($last, self::NOT_BEFORE_LAST, true)) {
                return null;
            }
            // Last in a short message only when it's plainly money: a currency, pence ("parking 5.00"), or a whole
            // number after a word that says what a cost was ("parking 8"; "meet at 8" and "bus at 8" are times).
            if (($m[2] ?? '') === '' && ($m[5] ?? '') === '' && ($m[4] ?? '') === ''
                && (array_intersect($before, self::COST_WORDS) === [] || str_contains($m[3], ',') || in_array($last, self::NOT_BEFORE_NUMBER, true))) {
                return null;
            }
            $first = self::amountOf($m[2], $m[3], $m[4] ?? '', $m[5] ?? '');
            $rest = trim($m[1]);
        }
        if ($first === null || $first['minor'] <= 0) {
            return null;
        }

        $paid = null;
        $account = null;
        $date = $today;
        $dateWord = null;
        // A date written out ("23 Nov", "23rd November 2026") is taken out first.
        foreach (DateWords::spans($rest) as $span) {
            [$d, $mo, $y] = $span['parts'][0][0];
            $on = self::dayOf((int) $d, (int) $mo, $y === null ? null : (int) $y, $today);
            if ($on === null) {
                continue; // a day that doesn't exist (29 Feb 2027): not a date
            }
            $date = $on;
            $dateWord = substr($rest, $span['at'], $span['len']);
            $rest = trim(substr($rest, 0, $span['at']).' '.substr($rest, $span['at'] + $span['len']));
            break;
        }
        $words = $rest === '' ? [] : preg_split('/\s+/u', $rest);
        $keep = [];
        $lowerAccounts = [];
        foreach ($accounts as $name => $id) {
            $lowerAccounts[mb_strtolower(trim((string) $name))] = (int) $id;
        }
        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $l = mb_strtolower(trim($w, '.,;:!'));
            $next = mb_strtolower(trim($words[$i + 1] ?? '', '.,;:!'));
            if ($l === 'cash') {
                $paid = 'cash';

                continue;
            }
            if ($l === 'personal') {
                $paid = 'personal_card';
                if ($next === 'card') {
                    $i++;
                }

                continue;
            }
            if (in_array($l, ['card', 'business'], true)) {
                $paid ??= 'business';
                if ($l === 'business' && in_array($next, ['card', 'account'], true)) {
                    $i++;
                }

                continue;
            }
            if (isset($lowerAccounts[$l])) {
                $paid = 'business';
                $account = $lowerAccounts[$l];

                continue;
            }
            if ($dateWord === null && in_array($l, ['today', 'yesterday'], true)) {
                $date = $l === 'today' ? $today : $today->subDay();
                $dateWord = $l;
                self::dropOn($keep);

                continue;
            }
            if ($dateWord === null && isset(self::WEEKDAYS[$l])) {
                $back = ($today->dayOfWeekIso - self::WEEKDAYS[$l] + 7) % 7;
                $date = $today->subDays($back);
                $dateWord = $l;
                self::dropOn($keep);

                continue;
            }
            $keep[] = $w;
        }
        // "spent £12 on parking", "£5 at NCP", "paid £5 to NCP": the joining word goes.
        while ($keep !== [] && in_array(mb_strtolower($keep[0]), ['on', 'for', 'at', 'to'], true)) {
            array_shift($keep);
        }
        self::dropOn($keep);

        return ['minor' => $first['minor'], 'currency' => $first['currency'], 'amount' => $first['amount'], 'what' => trim(implode(' ', $keep)), 'paid_with' => $paid,
            'account_id' => $account, 'date' => $date->format('Y-m-d'), 'date_word' => $dateWord];
    }

    /**
     * The job a few words name, near the day ("parking Leeds" on a Leeds Arena day): a word of three letters or more
     * found in exactly one job's title, venue or client, among jobs working within two days of it. Otherwise the one
     * job working on that day (or, before JobDays::OVERNIGHT_UNTIL today, last night), if there's exactly one. Null
     * when neither is clear.
     *
     * @return array{job:WorkJob, source:string, on_day:bool}|null
     */
    public static function jobFor(string $what, CarbonImmutable $date): ?array
    {
        $near = WorkJob::query()->with(['days', 'client'])->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $date->addDays(2)->format('Y-m-d'))->whereDate('ends_on', '>=', $date->subDays(2)->format('Y-m-d'))->get();
        if ($near->isEmpty()) {
            return null;
        }
        // Before OVERNIGHT_UNTIL today, last night's job still counts (a load-out at 00:30), as on the phone.
        $days = [$date->format('Y-m-d')];
        $now = CarbonImmutable::now('Europe/London');
        if ($date->format('Y-m-d') === $now->format('Y-m-d') && $now->format('H:i') < JobDays::OVERNIGHT_UNTIL) {
            $days[] = $date->subDay()->format('Y-m-d');
        }
        $onDay = $near->filter(fn (WorkJob $j) => collect($days)->contains(fn (string $d) => $j->worksOn($d)))->values();
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($what)) ?: [], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::NOT_NAMES, true));
        if ($words !== []) {
            $named = $near->filter(function (WorkJob $j) use ($words) {
                $hay = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $j->title.' '.$j->location.' '.($j->client?->name ?? '')) ?? '').' ';
                foreach ($words as $w) {
                    if (str_contains($hay, ' '.$w.' ')) {
                        return true;
                    }
                }

                return false;
            })->values();
            if ($named->count() === 1) {
                $job = $named->first();

                return ['job' => $job, 'source' => 'words', 'on_day' => $onDay->contains('id', $job->id)];
            }
        }

        return $onDay->count() === 1 ? ['job' => $onDay->first(), 'source' => 'today', 'on_day' => true] : null;
    }

    /** Words that say what a cost was, never which job: never matched against a job's name. */
    private const NOT_NAMES = ['the', 'and', 'for', 'parking', 'park', 'car', 'fuel', 'petrol', 'diesel', 'food', 'lunch', 'dinner', 'breakfast', 'coffee', 'taxi', 'train',
        'tube', 'bus', 'hotel', 'kit', 'cable', 'tape', 'cash', 'card', 'personal', 'business', 'today', 'yesterday', 'arena', 'hall', 'centre', 'center', 'theatre', 'events',
        'event', 'show', 'live', 'awards', 'conference', 'festival', 'stadium', 'club', 'venue', 'station', 'services', 'airport'];

    /** The names of your accounts, lower case, for "Starling" or "Amex" in a message. @return array<string, int> */
    public static function accountNames(): array
    {
        $out = [];
        foreach (Account::query()->where('kind', '!=', 'cash')->where('is_active', true)->get(['id', 'name']) as $a) {
            $name = mb_strtolower(trim((string) $a->name));
            $out[$name] = $a->id;
            $first = strtok($name, ' ');
            if ($first !== false && mb_strlen($first) >= 3 && ! isset($out[$first])) {
                $out[$first] = $a->id;
            }
        }

        return $out;
    }

    /**
     * A day written without its year is the last one on or before today (29 February: the last leap year's); with
     * its year, that day if it exists. Null when it doesn't.
     */
    public static function dayOf(int $d, int $m, ?int $y, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($y !== null) {
            return checkdate($m, $d, $y) ? CarbonImmutable::create($y, $m, $d)->startOfDay() : null;
        }
        for ($year = $today->year; $year >= $today->year - 8; $year--) {
            if (checkdate($m, $d, $year) && ($on = CarbonImmutable::create($year, $m, $d)->startOfDay())->lte($today)) {
                return $on;
            }
        }

        return null;
    }

    /** @param  list<string>  $keep */
    private static function dropOn(array &$keep): void
    {
        if ($keep !== [] && in_array(mb_strtolower(end($keep)), ['on', 'from', 'for', 'at'], true)) {
            array_pop($keep);
        }
    }

    /** @return array{minor:int, currency:string, amount:string}|null */
    private static function amountOf(string $symbol, string $whole, string $decimals, string $code): ?array
    {
        $currency = self::SYMBOLS[$symbol] ?? 'GBP';
        if ($code !== '') {
            $known = in_array(mb_strtolower($code), self::CODES, true) || ($code === strtoupper($code) && self::isoCode($code));
            if (! $known || ($symbol !== '' && strtoupper($code) !== $currency)) {
                return null;
            }
            $currency = strtoupper($code);
        }
        $text = $whole.($decimals !== '' ? '.'.$decimals : '');
        try {
            $minor = Money::parseIn($text, $currency);
        } catch (Throwable) {
            return null; // more decimals than the currency has
        }
        if ($minor === null || Money::toHundredths($minor, $currency) > self::MAX_HUNDREDTHS) {
            return null; // too big to be a cost added from the phone
        }
        $digits = Money::digits($currency);

        $unit = 10 ** $digits;

        return ['minor' => $minor, 'currency' => $currency, 'amount' => $digits > 0 ? intdiv($minor, $unit).'.'.str_pad((string) ($minor % $unit), $digits, '0', STR_PAD_LEFT) : (string) $minor];
    }

    private static function isoCode(string $code): bool
    {
        try {
            Currency::of(strtoupper($code));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A draft's words as the bot reads them back: "£12.50 · parking Leeds · today, 25 Nov".
     *
     * @param  array{minor:int, currency:string, what:string, date:string, date_word:?string}  $p
     */
    public static function summary(array $p, CarbonImmutable $today): string
    {
        $on = CarbonImmutable::parse($p['date']);
        // A day long ago, or in another tax year, says its year ("Sun 30 Nov 2025" is not this coming Sunday).
        $far = $on->lt($today->subMonths(6)) || UkTaxYear::fromDate($on)->label() !== UkTaxYear::fromDate($today)->label();
        $day = $on->isSameDay($today) ? 'today, '.$on->format('j M') : ($on->isSameDay($today->subDay()) ? 'yesterday, '.$on->format('j M') : $on->format($far ? 'D j M Y' : 'D j M'));

        return implode(' · ', array_filter([Money::formatMinor($p['minor'], $p['currency'], false), $p['what'] !== '' ? $p['what'] : null, $day]));
    }
}
