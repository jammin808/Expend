<?php

namespace App\Domain\Tax;

use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The tax pot, phase 1 (docs/mail-and-calendar.md section 3 idea 2): how much to put aside from each business payment
 * you receive, and how much is needed by the next payment to HMRC. Always an estimate, from your records and this
 * year's rules (TaxEstimator, BillPreview). It only reads: Expend never moves money.
 *
 * A year's figures are your confirmed records plus what's suggested but not yet confirmed (money in matched to an
 * invoice or by a rule, costs suggested the same way): you're often behind on confirming, and leaving suggestions out
 * would make a year look far less profitable than it is. Money in that's still to decide isn't counted, but said.
 *
 * Put aside: the share of the expected profit that goes in income tax and National Insurance (TaxEstimator's total over
 * the profit, the average rate), times the share of your income that's profit, so a steady year's payments put aside
 * about that year's tax. The expected profit is this year's so far scaled to a full year (once there are three months
 * of it), or last year's if that's more, so a year whose records are behind isn't under-saved; the profit share comes
 * from the same year. With no profit recorded in either year, the basic rate plus Class 4's main rate (26% in 2026-27)
 * on all of it, and it says so.
 *
 * Needed: by the next 31 January, that year's balancing payment plus the first payment on account for the next year,
 * as BillPreview works them out (less payments on account you've recorded as paid), from your filed profit when there
 * is one; while that year is still running (February to 5 April), on its expected profit, as above. From 1 February
 * to 31 July, the second payment on account due on 31 July comes first.
 *
 * Settings (no migration, defaults here): tax_pot_space, the Starling Space chosen in Settings as the pot
 * ({account_uid, kind, uid, name}, default null); tax_pot_balance, its balance as last read by the Starling import
 * ({ok, pence, currency, name, at} or {ok: false, error: scope|missing|failed, at}, default null).
 */
final class TaxPot
{
    public const SPACE = 'tax_pot_space';

    public const BALANCE = 'tax_pot_balance';

    public const TZ = 'Europe/London';

    /** A full-year projection from this year's records needs this many months of them. */
    public const PROJECT_AFTER_MONTHS = 3;

    /** @var array<string, array> ReturnSummary by year, for one call */
    private array $summaries = [];

    /** @var array<string, array{income_pence: int, costs_pence: int, undecided_in_pence: int}> by year */
    private array $unconfirmed = [];

    /**
     * @param  (callable(string): array)|null  $summaryFor  ReturnSummary::for, or a page's own memo of it
     * @param  (callable(string): array)|null  $unconfirmedFor  a year's suggested money in and costs and undecided money
     *                                                          in ({income_pence, costs_pence, undecided_in_pence}); read
     *                                                          from the records when not given
     */
    public function __construct(private $summaryFor = null, private $unconfirmedFor = null) {}

    /**
     * The share of business money received to put aside, and what it rests on. Null where Expend can't estimate the
     * tax (Scottish rates, or a year with no rates).
     *
     * @return array{rate: float, year: string, profit_pence: ?int, tax_pence: ?int, margin: float, basis: string, fallback: bool}|null
     */
    public function rate(?CarbonInterface $on = null): ?array
    {
        $on = CarbonImmutable::parse(($on ?? CarbonImmutable::now(self::TZ))->format('Y-m-d'));
        $year = UkTaxYear::fromDate($on);
        $label = $year->label();
        // This year's rates, or last year's while this year's aren't known yet.
        $params = TaxParameters::for($label) ? $label : (TaxParameters::for($year->previous()->label()) ? $year->previous()->label() : null);
        if ($params === null || Setting::get('scottish_taxpayer')) {
            return null;
        }
        $expected = $this->expected($year, $on);
        $profit = $expected['profit'];
        $tax = null;
        if ($profit === null) {
            // Nothing to go on: the basic rate plus Class 4's main rate on all of it.
            $p = TaxParameters::for($params);
            $rate = ((float) $p['rates']['basic'] + (float) $p['class4']['main_rate']) / 100;
        } else {
            $estimate = TaxEstimator::estimate($params, $profit);
            $tax = $estimate ? (int) $estimate['total_pence'] : null;
            if ($tax === null) {
                return null;
            }
            $rate = (float) ($tax / $profit);
        }

        return ['rate' => $rate, 'year' => $label, 'profit_pence' => $profit, 'tax_pence' => $tax, 'margin' => round($expected['margin'], 4),
            'basis' => $expected['basis'], 'fallback' => $profit === null];
    }

    /**
     * About how much to put aside from one business payment: rounded to £10 (to £1 under £100).
     *
     * @return array{pence: int, rate: float, margin: float}|null
     */
    public function forPayment(int $pence, ?CarbonInterface $on = null): ?array
    {
        $rate = $this->rate($on);
        if ($rate === null || $pence <= 0) {
            return null;
        }
        $aside = $pence * $rate['margin'] * $rate['rate'];
        $round = $aside >= 10_000 ? 1_000 : 100;

        return ['pence' => (int) (round($aside / $round) * $round), 'rate' => $rate['rate'], 'margin' => $rate['margin']];
    }

    /**
     * What HMRC is likely to want next. The top-level due_on, pence and label are the next payment: from 1 February to
     * 31 July the second payment on account due on 31 July (when there is one), otherwise the next 31 January's. The
     * 31 January payment is always in 'january': the balancing payment for the year that ended the April before (or is
     * still running, until 5 April), plus the first payment on account for the year after it. Null where it can't be
     * estimated.
     *
     * at_least: that year's figures leave out money in that's still to decide, so it may be more.
     *
     * @return array{due_on: string, pence: int, label: string, year: string, next_year: string, balancing_pence: int,
     *     payment_on_account_pence: int, january: array{due_on: string, pence: int, label: string},
     *     july: ?array{due_on: string, pence: int, label: string}, basis: string, profit_pence: int,
     *     suggested_pence: int, undecided_pence: int, at_least: bool}|null
     */
    public function need(?CarbonInterface $today = null): ?array
    {
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::now(self::TZ))->format('Y-m-d'));
        if (Setting::get('scottish_taxpayer')) {
            return null;
        }
        $due = CarbonImmutable::create($today->year, 1, 31);
        if ($today->gt($due)) {
            $due = $due->addYear();
        }
        $year = UkTaxYear::fromDate(sprintf('%04d-04-05', $due->year - 1)); // the year whose balance is due then
        $label = $year->label();
        $profit = $this->yearProfit($year, $today);
        $bill = BillPreview::for($label, TaxEstimator::estimate($label, $profit['pence']));
        if (! $bill) {
            return null;
        }

        // From 1 February to 31 July: the second payment on account for the year now running is due on 31 July. It's
        // half of the year before's bill (BillPreview of that year), less what's recorded as paid on account beyond the
        // first; what it pays comes off the 31 January balance.
        $july = null;
        $julyOn = CarbonImmutable::create($today->year, 7, 31);
        if ($due->year === $today->year + 1 && $today->lte($julyOn)) {
            $before = $year->previous();
            $prior = BillPreview::for($before->label(), TaxEstimator::estimate($before->label(), $this->yearProfit($before, $today)['pence']));
            $poa = (int) ($prior['payment_on_account_pence'] ?? 0);
            $paid = (int) (TaxYear::query()->where('label', $label)->value('payments_on_account_paid_pence') ?? 0);
            $second = max(0, min($poa, 2 * $poa - $paid));
            if ($second > 0) {
                $july = ['due_on' => $julyOn->format('Y-m-d'), 'pence' => $second, 'label' => '2nd payment on account for '.$label];
            }
        }
        $balancing = (int) $bill['balancing_pence'] - ($july['pence'] ?? 0);
        $january = ['due_on' => $due->format('Y-m-d'), 'pence' => max(0, $balancing + (int) $bill['payment_on_account_pence']), 'label' => (string) $bill['due'][0]['label']];
        $next = $july ?? $january;

        return [
            'due_on' => $next['due_on'],
            'pence' => $next['pence'],
            'label' => $next['label'],
            'year' => $label,
            'next_year' => $year->next()->label(),
            'balancing_pence' => max(0, $balancing),
            'payment_on_account_pence' => (int) $bill['payment_on_account_pence'],
            'january' => $january,
            'july' => $july,
            'basis' => $profit['basis'],
            'profit_pence' => $profit['pence'],
            'suggested_pence' => $profit['suggested_pence'],
            'undecided_pence' => $profit['undecided_pence'],
            'at_least' => $profit['basis'] !== 'filed' && $profit['undecided_pence'] > 0,
        ];
    }

    /**
     * Everything the dashboard card shows: the rate, the need and the pot as last read (or why it can't be shown).
     *
     * @return array{rate_pct: ?int, per_thousand_pence: ?int, basis: ?string, basis_profit_pence: ?int, fallback: bool, need: ?array, pot: ?array, pot_status: string}
     */
    public function card(): array
    {
        $rate = $this->rate();
        $pot = self::pot();

        return [
            'rate_pct' => $rate ? (int) round($rate['rate'] * 100) : null,
            'per_thousand_pence' => $rate ? $this->forPayment(100_000)['pence'] : null,
            'basis' => $rate['basis'] ?? null,
            'basis_profit_pence' => $rate['profit_pence'] ?? null,
            'fallback' => (bool) ($rate['fallback'] ?? false),
            'need' => $this->need(),
            'pot' => $pot['ok'] ? $pot['balance'] : null,
            'pot_status' => $pot['status'],
        ];
    }

    /**
     * The pot as last read from Starling: ok (with its balance), none (no Space chosen), scope (the token can't read
     * Spaces), missing (the Space has gone), failed (Starling couldn't be read), or not_connected.
     *
     * @return array{ok: bool, status: string, balance: ?array{pence: int, currency: string, name: string, at: ?string}}
     */
    public static function pot(): array
    {
        $space = Setting::get(self::SPACE, null);
        if (! is_array($space) || empty($space['uid'])) {
            return ['ok' => false, 'status' => 'none', 'balance' => null];
        }
        if (! Setting::get('starling_token')) {
            return ['ok' => false, 'status' => 'not_connected', 'balance' => null];
        }
        $last = Setting::get(self::BALANCE, null);
        if (! is_array($last)) {
            return ['ok' => false, 'status' => 'failed', 'balance' => null];
        }
        if (! ($last['ok'] ?? false)) {
            return ['ok' => false, 'status' => in_array($last['error'] ?? null, ['scope', 'missing'], true) ? $last['error'] : 'failed', 'balance' => null];
        }

        return ['ok' => true, 'status' => 'ok', 'balance' => [
            'pence' => (int) $last['pence'], 'currency' => (string) ($last['currency'] ?? 'GBP'), 'name' => (string) ($last['name'] ?? $space['name'] ?? 'Tax pot'), 'at' => $last['at'] ?? null,
        ]];
    }

    /**
     * The Telegram message after an import that brought business money in: one line per payment ("Acme Events paid
     * £1,380: put about £210 aside (estimate)."), then the pot against what's needed by the next payment to HMRC (and
     * the 31 January one after it, from February to July). Only names and amounts: no account numbers or references.
     * Null when nothing new was business income, or no estimate can be made.
     *
     * Counted as business income: money in that's decided, or suggested (matched to an invoice, or by a rule), as
     * business income. Money in that's still to be decided isn't counted yet.
     *
     * @param  list<int>  $batchIds  the imports just made
     */
    public function importMessage(array $batchIds): ?string
    {
        if ($batchIds === []) {
            return null;
        }
        $lines = BankTransaction::query()->whereIn('import_batch_id', $batchIds)->where('amount_pence', '>', 0)
            ->whereHas('allocations', fn ($q) => $q->where('kind', 'business_income'))
            ->with(['allocations' => fn ($q) => $q->where('kind', 'business_income')->with('invoice.client')])
            ->orderBy('posted_on')->orderBy('id')->limit(50)->get();
        $out = [];
        $total = 0;
        foreach ($lines as $tx) {
            $amount = (int) $tx->allocations->sum('amount_pence');
            $aside = $this->forPayment($amount, $tx->posted_on);
            if ($aside === null) {
                return null; // no estimate (Scottish rates): nothing to say
            }
            $invoice = $tx->allocations->first(fn ($a) => $a->invoice !== null)?->invoice;
            $payer = self::payer($invoice?->client?->name ?? $invoice?->bill_to_name ?? $tx->counterparty);
            $total += $aside['pence'];
            if (count($out) < 10) {
                $out[] = "{$payer} paid ".self::pounds($amount).': '.($aside['pence'] > 0 ? 'put about '.self::pounds($aside['pence']).' aside' : 'nothing to put aside at this year’s expected profit').' (estimate).';
            }
        }
        if ($out === []) {
            return null;
        }
        if (count($lines) > 1) {
            $out[] = 'In all, about '.self::pounds($total).' to put aside.';
        }
        $need = $this->need();
        $pot = self::pot();
        $by = $need ? CarbonImmutable::parse($need['due_on'])->format('j M Y') : null;
        $about = $need && $need['at_least'] ? 'at least ' : 'about ';
        // From February to July the 31 July payment comes first; the 31 January one follows.
        $then = $need && $need['july'] && $need['january']['pence'] > 0
            ? ', then '.$about.self::pounds($need['january']['pence']).' by '.CarbonImmutable::parse($need['january']['due_on'])->format('j M Y') : '';
        if ($pot['ok']) {
            $out[] = 'Tax pot '.self::pounds($pot['balance']['pence']).($need && $need['pence'] > 0 ? ' of '.$about.self::pounds($need['pence']).' needed by '.$by.$then : '').'.';
        } elseif ($need && $need['pence'] > 0) {
            $out[] = ucfirst($about).self::pounds($need['pence']).' is needed by '.$by.$then.' (estimate).';
        }

        return implode("\n", $out);
    }

    /** "£1,380": whole pounds, as the pot's figures are only ever about. */
    public static function pounds(int $pence): string
    {
        return '£'.number_format((int) round($pence / 100));
    }

    /** A payer's name for a message: no long numbers (an account or reference in the name), at most 40 characters. */
    private static function payer(?string $name): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\d{4,}/', '', (string) $name)));

        return $name === '' ? 'A client' : mb_substr($name, 0, 40);
    }

    private function summary(string $label): array
    {
        return $this->summaries[$label] ??= $this->summaryFor !== null ? ($this->summaryFor)($label) : ReturnSummary::for($label);
    }

    /**
     * A year's suggested but unconfirmed business money in and allowable costs, and its money in that's still to decide.
     *
     * @return array{income_pence: int, costs_pence: int, undecided_in_pence: int}
     */
    private function unconfirmed(string $label): array
    {
        if (isset($this->unconfirmed[$label])) {
            return $this->unconfirmed[$label];
        }
        if ($this->unconfirmedFor !== null) {
            $u = ($this->unconfirmedFor)($label);
        } else {
            $suggested = Allocation::query()->where('tax_year', $label)->whereNull('confirmed_at');
            $allowable = Category::query()->where('is_allowable', true)->pluck('id');
            $u = [
                'income_pence' => (int) (clone $suggested)->where('kind', 'business_income')->sum('amount_pence'),
                'costs_pence' => -(int) (clone $suggested)->where('kind', 'business_expense')->whereIn('category_id', $allowable)->sum('amount_pence'),
                'undecided_in_pence' => (int) BankTransaction::query()->where('tax_year', $label)->where('review_status', 'unreviewed')->where('amount_pence', '>', 0)->sum('amount_pence'),
            ];
        }

        return $this->unconfirmed[$label] = ['income_pence' => (int) ($u['income_pence'] ?? 0), 'costs_pence' => (int) ($u['costs_pence'] ?? 0),
            'undecided_in_pence' => (int) ($u['undecided_in_pence'] ?? 0)];
    }

    /**
     * A year's figures as the pot reads them: confirmed, plus what's suggested but not confirmed yet.
     *
     * @return array{turnover: int, profit: int, suggested: int, undecided: int}
     */
    private function figures(string $label): array
    {
        $s = $this->summary($label);
        $u = $this->unconfirmed($label);

        return [
            'turnover' => (int) ($s['turnover_pence'] ?? 0) + $u['income_pence'],
            'profit' => (int) ($s['net_profit_pence'] ?? 0) + $u['income_pence'] - $u['costs_pence'],
            'suggested' => $u['income_pence'],
            'undecided' => $u['undecided_in_pence'],
        ];
    }

    /**
     * The profit expected for a year on a day: its figures so far scaled to a full year (once there are three months of
     * them), or the year before's if that's more; with the share of income that's profit from the same year.
     *
     * @return array{profit: ?int, margin: float, basis: string}
     */
    private function expected(UkTaxYear $year, CarbonImmutable $on): array
    {
        $now = $this->figures($year->label());
        $last = $this->figures($year->previous()->label());
        $months = (int) floor($year->startsOn()->diffInMonths($on));
        $projected = $months >= self::PROJECT_AFTER_MONTHS && $now['turnover'] > 0 ? self::projection($year, $on, $now['profit']) : null;
        $share = fn (array $f) => $f['turnover'] > 0 ? max(0.0, min(1.0, $f['profit'] / $f['turnover'])) : 1.0;

        return match (true) {
            $projected !== null && $projected > 0 && $projected >= $last['profit'] => ['profit' => $projected, 'margin' => $share($now), 'basis' => 'this year so far, scaled to a full year'],
            $last['profit'] > 0 => ['profit' => $last['profit'], 'margin' => $share($last), 'basis' => 'last year’s profit'],
            default => ['profit' => null, 'margin' => 1.0, 'basis' => 'no profit recorded yet'],
        };
    }

    /**
     * The profit a year's bill is worked out on: your filed profit; while the year is still running, its expected
     * profit (expected()); otherwise its figures (confirmed plus suggested).
     *
     * @return array{pence: int, basis: string, suggested_pence: int, undecided_pence: int}
     */
    private function yearProfit(UkTaxYear $year, CarbonImmutable $today): array
    {
        $label = $year->label();
        $figures = $this->figures($label);
        $filed = TaxYear::query()->where('label', $label)->value('filed_profit_pence');
        if ($filed !== null) {
            return ['pence' => (int) $filed, 'basis' => 'filed', 'suggested_pence' => 0, 'undecided_pence' => 0];
        }
        if ($today->lte($year->endsOn())) {
            return ['pence' => (int) ($this->expected($year, $today)['profit'] ?? 0), 'basis' => 'expected',
                'suggested_pence' => $figures['suggested'], 'undecided_pence' => $figures['undecided']];
        }

        return ['pence' => $figures['profit'], 'basis' => 'records', 'suggested_pence' => $figures['suggested'], 'undecided_pence' => $figures['undecided']];
    }

    /** This year's profit so far, scaled to the whole year (as TaxSavings projects it). */
    private static function projection(UkTaxYear $year, CarbonImmutable $on, int $profit): int
    {
        $days = (int) round($year->startsOn()->diffInDays($year->endsOn())) + 1;
        $elapsed = max(1, (int) round($year->startsOn()->diffInDays($on)) + 1);

        return (int) round($profit * $days / $elapsed);
    }
}
