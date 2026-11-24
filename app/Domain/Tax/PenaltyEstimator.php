<?php

namespace App\Domain\Tax;

use App\Models\Allocation;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Works out the fines and interest HMRC charges (or will charge) for each tax year, from what Expend knows: when
 * each return was (or wasn't) filed, the tax each year comes to, and the payments you've made to HMRC.
 *
 *  - Late filing: Schedule 55 (£100, then £10 a day from three months for up to 90 days, then 5% or £300 at six
 *    and twelve months), or the points system for years under the new rules.
 *  - Late payment: Schedule 56 (5% of what's unpaid 30 days, 6 months and 12 months after the due date), or the new
 *    penalties for years under the new rules. Payments on account never get late payment penalties.
 *  - Interest: simple, daily, at HMRC's rate in force each day, on payments on account, balancing payments and
 *    penalties alike.
 *
 * It is an estimate. Your payments to HMRC are matched to the oldest amount due first, as HMRC usually does; a
 * return is assumed to be online with the usual notice to file; penalties are assumed to be notified on the day
 * they arise. HMRC's own statement is the final word. The rules and rates are in resources/tax/penalties.json.
 */
final class PenaltyEstimator
{
    private static ?array $rules = null;

    /** @var array<string, array> */
    private static array $summaries = [];

    private static function summary(string $label): array
    {
        return self::$summaries[$label] ??= ReturnSummary::for($label);
    }

    public static function rules(): array
    {
        if (self::$rules === null) {
            $path = resource_path('tax/penalties.json');
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($json)) {
                throw new RuntimeException('The penalty rules are missing.');
            }
            self::$rules = $json;
        }

        return self::$rules;
    }

    /**
     * Every tax year from the first one Expend knows about to the current one.
     *
     * @param  array{filed_on?:array<string,string>, pay_all_on?:string, plan_from?:string}  $whatIf
     * @return array{years:list<array>, totals:array, as_of:string, payments:list<array>}
     */
    public static function all(?CarbonImmutable $asOf = null, array $whatIf = []): array
    {
        $asOf ??= CarbonImmutable::today();
        self::$summaries = [];
        $labels = self::labels($asOf);
        $years = [];
        foreach ($labels as $label) {
            $years[$label] = self::yearFacts($label, $whatIf);
        }

        // What's due to HMRC, oldest first: payments on account, balancing payments, then the penalties as they arise.
        $obligations = [];
        foreach ($years as $label => $y) {
            foreach (self::obligationsFor($label, $years) as $o) {
                $obligations[] = $o;
            }
        }
        $payments = self::payments($asOf, $whatIf, $obligations);

        // Penalties depend on what's unpaid at each date, and become amounts due themselves (with interest).
        $out = [];
        foreach ($years as $label => $y) {
            $filing = self::filingPenalties($label, $y, $asOf);
            $paying = self::paymentPenalties($label, $y, $obligations, $payments, $asOf, $whatIf);
            $out[$label] = ['facts' => $y, 'penalties' => array_merge($filing, $paying)];
            foreach (array_merge($filing, $paying) as $p) {
                if ($p['status'] !== 'upcoming' && $p['amount_pence'] > 0) {
                    $obligations[] = ['year' => $label, 'kind' => 'penalty', 'label' => $p['label'], 'due' => CarbonImmutable::parse($p['date'])->addDays(30),
                        'amount' => $p['amount_pence'], 'interest_cap' => $p['amount_pence']];
                }
            }
        }
        $ledger = self::allocate($obligations, $payments);

        $result = [];
        $totals = ['penalties_pence' => 0, 'interest_pence' => 0, 'upcoming_pence' => 0, 'per_day_pence' => 0, 'unpaid_pence' => 0];
        foreach ($out as $label => $o) {
            $yearOblig = array_values(array_filter($ledger, fn ($l) => $l['year'] === $label));
            $interest = 0;
            $perDay = 0;
            foreach ($yearOblig as &$l) {
                $l['interest_pence'] = self::interest($l, $asOf);
                $l['outstanding_pence'] = self::outstandingOn($l, $asOf);
                $interest += $l['interest_pence'];
                $perDay += self::dailyRate($l, $asOf);
            }
            unset($l);
            $penalties = array_sum(array_map(fn ($p) => $p['status'] === 'upcoming' ? 0 : $p['amount_pence'], $o['penalties']));
            $upcoming = array_sum(array_map(fn ($p) => $p['status'] === 'upcoming' ? $p['amount_pence'] : 0, $o['penalties']));
            $unpaid = array_sum(array_map(fn ($l) => $l['outstanding_pence'], $yearOblig));
            $result[] = self::present($label, $o['facts'], $o['penalties'], $yearOblig, $interest, $perDay, $asOf);
            $totals['penalties_pence'] += $penalties;
            $totals['interest_pence'] += $interest;
            $totals['upcoming_pence'] += $upcoming;
            $totals['per_day_pence'] += $perDay;
            $totals['unpaid_pence'] += $unpaid;
        }

        return ['years' => $result, 'totals' => $totals, 'as_of' => $asOf->format('Y-m-d'),
            'payments' => array_map(fn ($p) => ['date' => $p['date']->format('Y-m-d'), 'amount_pence' => $p['amount'], 'source' => $p['source']], $payments)];
    }

    /** One tax year (worked out with the others, because payments go to the oldest amounts first). */
    public static function forYear(string $label, ?CarbonImmutable $asOf = null, array $whatIf = []): ?array
    {
        $label = UkTaxYear::fromLabel($label)->label();
        foreach (self::all($asOf, $whatIf)['years'] as $y) {
            if ($y['tax_year'] === $label) {
                return $y;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- facts

    /** @return list<string> */
    private static function labels(CarbonImmutable $asOf): array
    {
        $current = UkTaxYear::fromDate($asOf);
        $first = TaxYear::query()->orderBy('label')->value('label')
            ?? Allocation::query()->orderBy('tax_year')->value('tax_year')
            ?? $current->label();
        // HMRC can only go back so far for a return you file yourself (4 years), so older years aren't estimated.
        $earliest = UkTaxYear::fromDate($asOf->subYears(5));
        $start = UkTaxYear::fromLabel($first);
        if ($start->startYear < $earliest->startYear) {
            $start = $earliest;
        }
        $out = [];
        for ($y = $start; $y->startYear <= $current->startYear; $y = $y->next()) {
            $out[] = $y->label();
        }

        return $out;
    }

    /** @return array{liability:int, relevant:int, class2:int, basis:string, filed_on:?CarbonImmutable, filed:bool, status:string, deadline:CarbonImmutable, new_regime:bool, mtd:bool, plan_from:?CarbonImmutable, poa_paid:int, return_needed:bool} */
    private static function yearFacts(string $label, array $whatIf): array
    {
        $period = UkTaxYear::fromLabel($label);
        $record = TaxYear::query()->where('label', $label)->first();
        $profit = $record?->filed_profit_pence;
        $basis = $profit !== null ? 'the profit on your filed return' : 'your confirmed records so far';
        if ($profit === null) {
            $profit = self::summary($label)['net_profit_pence'];
        }
        $estimate = TaxEstimator::estimate($label, (int) $profit);
        $relevant = $estimate ? $estimate['income_tax']['total_pence'] + $estimate['class4_pence'] : 0;
        $class2 = $estimate['class2_pence'] ?? 0;
        $filedOn = isset($whatIf['filed_on'][$label]) ? CarbonImmutable::parse($whatIf['filed_on'][$label])
            : ($record?->filed_on ? CarbonImmutable::parse($record->filed_on->format('Y-m-d')) : null);
        $filed = $filedOn !== null || in_array($record?->status, ['filed', 'amending'], true);
        $turnover = self::summary($label)['turnover_pence'] ?? 0;
        $mtd = self::inMtd($label);
        $planFrom = $whatIf['plan_from'] ?? ($record?->payment_plan_from ?? null);

        return [
            'liability' => $relevant + $class2, 'relevant' => $relevant, 'class2' => $class2, 'basis' => $basis, 'estimable' => $estimate !== null,
            'filed_on' => $filedOn, 'filed' => $filed, 'status' => $record?->status ?? 'outstanding', 'deadline' => $period->filingDeadline(),
            'new_regime' => self::newRegime($label, $mtd), 'mtd' => $mtd, 'plan_from' => $planFrom ? CarbonImmutable::parse($planFrom) : null,
            'poa_paid' => (int) ($record?->payments_on_account_paid_pence ?? 0),
            // A return is needed for any year you traded above the £1,000 trading allowance, or that you track here.
            'return_needed' => $record !== null || $turnover > 100_000,
        ];
    }

    private static function inMtd(string $label): bool
    {
        foreach (config('tax.mtd_thresholds', []) as $t) {
            if (UkTaxYear::fromDate($t['starts'])->label() <= $label && (self::summary($t['based_on'])['turnover_pence'] ?? 0) > $t['turnover_over']) {
                return true;
            }
        }

        return false;
    }

    private static function newRegime(string $label, bool $mtd): bool
    {
        $r = self::rules()['regimes']['new_regime_from'];

        return $label >= $r['everyone'] || ($mtd && $label >= $r['mtd']);
    }

    /** Payments on account for the year (from the year before) and the balancing payment. */
    private static function obligationsFor(string $label, array $years): array
    {
        $y = $years[$label];
        $period = UkTaxYear::fromLabel($label);
        $prev = $years[$period->previous()->label()] ?? null;
        $threshold = TaxParameters::for($label)['payments_on_account_threshold'] ?? 100_000;
        // Each payment on account is half of last year's income tax and Class 4; none if that was under £1,000.
        $poa = $prev && $prev['relevant'] >= $threshold ? intdiv($prev['relevant'], 2) : 0;
        // Interest is only due on what turns out to be needed: half of this year's figure, if that's lower.
        $poaInterest = min($poa, intdiv($y['relevant'], 2));
        $out = [];
        if ($poa > 0) {
            $out[] = ['year' => $label, 'kind' => 'poa1', 'label' => '1st payment on account', 'due' => CarbonImmutable::create($period->startYear + 1, 1, 31), 'amount' => $poa, 'interest_cap' => $poaInterest];
            $out[] = ['year' => $label, 'kind' => 'poa2', 'label' => '2nd payment on account', 'due' => CarbonImmutable::create($period->startYear + 1, 7, 31), 'amount' => $poa, 'interest_cap' => $poaInterest];
        }
        $balancing = max(0, $y['liability'] - 2 * $poa);
        if ($balancing > 0 && $y['return_needed']) {
            $out[] = ['year' => $label, 'kind' => 'balancing', 'label' => 'Balancing payment', 'due' => $y['deadline'], 'amount' => $balancing, 'interest_cap' => $balancing];
        }

        return $out;
    }

    /** Your payments to HMRC: tax payments in your statements, payments on account you entered, and any "what if". */
    private static function payments(CarbonImmutable $asOf, array $whatIf, array $obligations): array
    {
        $out = [];
        foreach (Allocation::query()->where('kind', 'tax_payment')->with(['transaction:id,posted_on,transaction_on', 'manualExpense:id,spent_on'])->get() as $a) {
            $date = $a->transaction?->effectiveDate() ?? $a->manualExpense?->spent_on;
            if ($date && $a->amount_pence < 0) {
                $out[] = ['date' => CarbonImmutable::parse($date->format('Y-m-d')), 'amount' => -$a->amount_pence, 'source' => 'statement'];
            }
        }
        foreach (TaxYear::query()->where('payments_on_account_paid_pence', '>', 0)->get() as $t) {
            // Payments on account you told Expend about: taken as paid on time.
            $year = UkTaxYear::fromLabel($t->label);
            $out[] = ['date' => CarbonImmutable::create($year->startYear + 1, 1, 31), 'amount' => intdiv($t->payments_on_account_paid_pence, 2), 'source' => 'entered', 'year' => $t->label];
            $out[] = ['date' => CarbonImmutable::create($year->startYear + 1, 7, 31), 'amount' => $t->payments_on_account_paid_pence - intdiv($t->payments_on_account_paid_pence, 2), 'source' => 'entered', 'year' => $t->label];
        }
        if (! empty($whatIf['pay_all_on'])) {
            $on = CarbonImmutable::parse($whatIf['pay_all_on']);
            $due = array_sum(array_map(fn ($o) => $o['amount'], $obligations)) - array_sum(array_map(fn ($p) => $p['amount'], $out));
            if ($due > 0) {
                $out[] = ['date' => $on, 'amount' => $due + 10_000_000, 'source' => 'what_if']; // enough to clear everything, penalties included
            }
        }
        usort($out, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $out;
    }

    /**
     * Match payments to amounts due, oldest due first (payments on account you entered go to their own year).
     *
     * @return list<array> obligations with 'paid' => list of [date, amount]
     */
    private static function allocate(array $obligations, array $payments): array
    {
        usort($obligations, fn ($a, $b) => [$a['due'], $a['kind'] === 'penalty'] <=> [$b['due'], $b['kind'] === 'penalty']);
        foreach ($obligations as &$o) {
            $o['left'] = $o['amount'];
            $o['paid'] = [];
        }
        unset($o);
        foreach ($payments as $p) {
            $left = $p['amount'];
            $targets = isset($p['year']) ? array_keys(array_filter($obligations, fn ($o) => $o['year'] === $p['year'] && str_starts_with($o['kind'], 'poa'))) : array_keys($obligations);
            foreach ($targets as $i) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $obligations[$i]['left']);
                if ($take > 0) {
                    $obligations[$i]['left'] -= $take;
                    $obligations[$i]['paid'][] = ['date' => $p['date'], 'amount' => $take];
                    $left -= $take;
                }
            }
        }

        return $obligations;
    }

    /** What's still unpaid at the end of a day. */
    private static function outstandingOn(array $o, CarbonImmutable $day): int
    {
        $paid = array_sum(array_map(fn ($p) => $p['date']->lte($day) ? $p['amount'] : 0, $o['paid']));

        return max(0, $o['amount'] - $paid);
    }

    // ---------------------------------------------------------------- penalties

    private static function filingPenalties(string $label, array $y, CarbonImmutable $asOf): array
    {
        if (! $y['return_needed']) {
            return [];
        }
        $deadline = $y['deadline'];
        $filedOn = $y['filed_on'];
        $unfiledOn = fn (CarbonImmutable $d) => ! $y['filed'] || ($filedOn && $filedOn->gt($d));
        $state = fn (CarbonImmutable $d) => $d->lte($asOf) ? 'due' : 'upcoming';
        $out = [];
        $r = self::rules();

        if ($y['new_regime']) {
            if ($unfiledOn($deadline)) {
                $out[] = self::penalty('points', 'A late submission point', $deadline->addDay(), 0, $state($deadline->addDay()),
                    'Each late return earns a point. At '.$r['points']['threshold_annual'].' points (4 if you send quarterly updates) there’s a £200 penalty, and £200 more for each later late one.', $r['points']['sources'][0]);
            }

            return $out;
        }

        $s = $r['sch55'];
        $penaltyDate = $deadline->addDay();
        $liability = $y['liability'];
        $src = $s['sources'][0];
        if ($unfiledOn($deadline)) {
            $out[] = self::penalty('sch55_initial', 'Late filing: £100', $penaltyDate, $s['initial_pence'], $state($penaltyDate), 'The return wasn’t filed by '.$deadline->format('j F Y').'. The £100 is due even if no tax is owed.', $src);
        }
        // Daily: from the day after three months beginning with the penalty date, while still not filed, up to 90 days.
        $dailyFrom = $penaltyDate->addMonths($s['daily_from_months']);
        $dailyTo = $dailyFrom->addDays($s['daily_max_days'] - 1);
        if ($unfiledOn($dailyFrom->subDay())) {
            $lastUnfiled = $filedOn ? $filedOn->subDay() : null;
            $end = $lastUnfiled && $lastUnfiled->lt($dailyTo) ? $lastUnfiled : $dailyTo;
            $soFar = $asOf->lt($dailyFrom) ? 0 : (int) $dailyFrom->diffInDays($asOf->lt($end) ? $asOf : $end) + 1;
            $all = (int) $dailyFrom->diffInDays($end) + 1;
            if ($soFar > 0) {
                $out[] = self::penalty('sch55_daily', "Daily penalties: £10 × {$soFar} days", $dailyFrom, $soFar * $s['daily_pence'], $soFar < $all && $asOf->lt($end) ? 'accruing' : 'due',
                    '£10 a day from '.$dailyFrom->format('j F Y').' until filed, for up to 90 days (£900). HMRC sends a notice first, normally around the three-month point.', $src);
            }
            if ($all > $soFar && ! $filedOn) {
                $out[] = self::penalty('sch55_daily_more', 'More daily penalties if still not filed', $asOf->lt($dailyFrom) ? $dailyFrom : $asOf->addDay(), ($all - $soFar) * $s['daily_pence'], 'upcoming',
                    'Up to '.Money::format(($all - $soFar) * $s['daily_pence']).' more, £10 a day until '.$dailyTo->format('j F Y').' or until you file.', $src);
            }
        }
        foreach ([['sch55_6m', 'Six months late', 6, $s['six_month_pct'], $s['six_month_min_pence']], ['sch55_12m', 'Twelve months late', 12, $s['twelve_month_pct'], $s['twelve_month_min_pence']]] as [$code, $title, $months, $pct, $min]) {
            $trigger = $penaltyDate->addMonths($months);
            if ($unfiledOn($trigger->subDay())) {
                $amount = max($min, self::pct($liability, $pct));
                $out[] = self::penalty($code, "{$title}: ".Money::format($amount), $trigger, $amount, $state($trigger),
                    "The greater of {$pct}% of the tax due for the year (".Money::format($liability).', from '.$y['basis'].') and '.Money::format($min).'.'.($code === 'sch55_12m' ? ' It can be 70% or 100% if information was deliberately withheld.' : ''), $src);
            }
        }

        return $out;
    }

    private static function paymentPenalties(string $label, array $y, array $obligations, array $payments, CarbonImmutable $asOf, array $whatIf): array
    {
        $balancing = array_values(array_filter($obligations, fn ($o) => $o['year'] === $label && $o['kind'] === 'balancing'))[0] ?? null;
        if (! $balancing) {
            return [];
        }
        // What's unpaid on each date depends on all the payments, matched oldest first.
        $ledger = self::allocate($obligations, $payments);
        $b = array_values(array_filter($ledger, fn ($o) => $o['year'] === $label && $o['kind'] === 'balancing'))[0];
        $due = $b['due'];
        $plan = $y['plan_from'];
        $state = fn (CarbonImmutable $d) => $d->lte($asOf) ? 'due' : 'upcoming';
        $r = self::rules();
        $out = [];

        if ($y['new_regime']) {
            $s = $r['sch26'];
            $rates = $s['rates_by_year'][$label] ?? end($s['rates_by_year']);
            $first = $label === $r['regimes']['new_regime_from']['everyone'] || ($y['mtd'] && $label === $r['regimes']['new_regime_from']['mtd']);
            // Paid in full (or a payment plan asked for) within 15 days, or 30 in your first year under these rules: no penalty.
            $grace = $first ? $s['first_year_window_days'] : $s['first_days'];
            $day15 = $due->addDays($s['first_days']);
            $day30 = $due->addDays($s['second_days']);
            $inGrace = self::outstandingOn($b, $due->addDays($grace)) <= 0 || ($plan && $plan->lte($due->addDays($grace)));
            $unpaid15 = self::outstandingOn($b, $day15);
            if (! $inGrace && $unpaid15 > 0) {
                $out[] = self::penalty('sch26_a', 'Late payment: '.$rates['first_pct'].'% at day 15', $day15->addDay()->max($due->addDays($grace + 1)), self::pct($unpaid15, $rates['first_pct']),
                    $state($day15->addDay()->max($due->addDays($grace + 1))), Money::format($unpaid15).' still unpaid 15 days after it was due'.($first ? ' (and not paid within the 30 days allowed in your first year)' : '').'.', $s['sources'][0]);
                $unpaid30 = self::outstandingOn($b, $day30);
                if ($unpaid30 > 0 && ! ($plan && $plan->lte($day30))) {
                    $out[] = self::penalty('sch26_b', 'Late payment: another '.$rates['second_pct'].'% at day 30', $day30->addDay(), self::pct($unpaid30, $rates['second_pct']), $state($day30->addDay()), Money::format($unpaid30).' still unpaid 30 days after it was due.', $s['sources'][0]);
                    $yearly = self::dailyPenalty($b, $day30->addDay(), $asOf, $plan, (string) $rates['annual_pct']);
                    if ($yearly > 0) {
                        $out[] = self::penalty('sch26_daily', 'Late payment: '.$rates['annual_pct'].'% a year from day 31', $day30->addDay(), $yearly, self::outstandingOn($b, $asOf) > 0 ? 'accruing' : 'due', 'Charged daily on what’s unpaid until it’s paid in full or a payment plan is agreed.', $s['sources'][0]);
                    }
                }
            }

            return $out;
        }

        $s = $r['sch56'];
        $penaltyDate = $due->addDays(31);
        $tests = [
            ['sch56_30d', '30 days late', $due->addDays(30)],
            ['sch56_6m', '6 months late', $penaltyDate->addMonths(5)->subDay()],
            ['sch56_12m', '12 months late', $penaltyDate->addMonths(11)->subDay()],
        ];
        foreach ($tests as [$code, $title, $endOf]) {
            if ($plan && $plan->lte($endOf)) {
                continue; // a payment plan agreed by then stops this one and later ones while it's kept to
            }
            $paidSoFar = $endOf->lte($asOf) || ! empty($whatIf['pay_all_on']);
            $unpaid = $paidSoFar ? self::outstandingOn($b, $endOf) : self::outstandingOn($b, $asOf);
            if ($unpaid <= 0) {
                continue;
            }
            $amount = self::pct($unpaid, 5);
            $out[] = self::penalty($code, "Late payment, {$title}: ".Money::format($amount), $endOf->addDay(), $amount, $state($endOf->addDay()),
                '5% of the '.Money::format($unpaid).' of the balancing payment '.($endOf->lte($asOf) ? 'unpaid on '.$endOf->format('j F Y') : 'still unpaid, if it isn’t paid by '.$endOf->format('j F Y')).'.', $s['sources'][0]);
        }

        return $out;
    }

    private static function penalty(string $code, string $label, CarbonImmutable $date, int $amount, string $status, string $why, string $source): array
    {
        return ['code' => $code, 'label' => $label, 'date' => $date->format('Y-m-d'), 'amount_pence' => $amount, 'status' => $status, 'why' => $why, 'source' => $source];
    }

    // ---------------------------------------------------------------- interest

    /** Interest on an amount due, from its due date to the end of $asOf (or when paid). */
    private static function interest(array $o, CarbonImmutable $asOf): int
    {
        $cap = min($o['amount'], $o['interest_cap'] ?? $o['amount']);
        if ($cap <= 0 || $o['due']->gte($asOf)) {
            return 0;
        }
        // Events where the balance or the rate changes.
        $points = [$o['due']];
        foreach ($o['paid'] as $p) {
            if ($p['date']->gt($o['due']) && $p['date']->lt($asOf)) {
                $points[] = $p['date'];
            }
        }
        // Each segment counts the days after its start up to and including its end, so a new rate (or a new
        // calendar year, for leap years) starts a segment on the day before it applies.
        foreach (self::rates() as [$from]) {
            $before = $from->subDay();
            if ($before->gt($o['due']) && $before->lt($asOf)) {
                $points[] = $before;
            }
        }
        for ($y = (int) $o['due']->format('Y') + 1; $y <= (int) $asOf->format('Y'); $y++) {
            $newYearsEve = CarbonImmutable::create($y - 1, 12, 31);
            if ($newYearsEve->gt($o['due']) && $newYearsEve->lt($asOf)) {
                $points[] = $newYearsEve;
            }
        }
        $points[] = $asOf;
        usort($points, fn ($a, $b) => $a <=> $b);
        $total = BigDecimal::zero();
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$a, $b] = [$points[$i], $points[$i + 1]];
            $days = (int) $a->diffInDays($b);
            if ($days <= 0) {
                continue;
            }
            // Interest runs on the balance at the start of each day (the due day itself isn't counted).
            $balance = min($cap, max(0, $cap - ($o['amount'] - self::outstandingOn($o, $a))));
            if ($balance <= 0) {
                continue;
            }
            $rate = self::rateOn($a->addDay());
            $yearDays = $a->addDay()->isLeapYear() ? 366 : 365;
            $total = $total->plus(BigDecimal::of($balance)->multipliedBy($rate)->multipliedBy($days)->dividedBy(100 * $yearDays, 6, RoundingMode::HALF_UP));
        }

        return $total->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    private static function dailyRate(array $o, CarbonImmutable $asOf): int
    {
        if ($o['due']->gte($asOf)) {
            return 0;
        }
        $balance = min($o['interest_cap'] ?? $o['amount'], self::outstandingOn($o, $asOf));

        return (int) BigDecimal::of($balance)->multipliedBy(self::rateOn($asOf->addDay()))->dividedBy(100 * ($asOf->isLeapYear() ? 366 : 365), 0, RoundingMode::HALF_UP)->toInt();
    }

    /** The new rules' yearly-rate penalty, charged daily on what's unpaid from day 31 until paid (or a plan). */
    private static function dailyPenalty(array $o, CarbonImmutable $from, CarbonImmutable $asOf, ?CarbonImmutable $plan, string $pct): int
    {
        $end = $plan && $plan->lt($asOf) ? $plan : $asOf;
        $total = BigDecimal::zero();
        for ($d = $from; $d->lte($end); $d = $d->addDay()) {
            $bal = self::outstandingOn($o, $d->subDay());
            if ($bal <= 0) {
                break;
            }
            $total = $total->plus(BigDecimal::of($bal)->multipliedBy($pct)->dividedBy(100 * ($d->isLeapYear() ? 366 : 365), 6, RoundingMode::HALF_UP));
        }

        return $total->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /** @return list<array{0:CarbonImmutable, 1:string}> */
    private static function rates(): array
    {
        return array_map(fn ($r) => [CarbonImmutable::parse($r['from']), $r['pct']], self::rules()['interest']['late_payment_rates']);
    }

    private static function rateOn(CarbonImmutable $day): string
    {
        $rate = '0';
        foreach (self::rates() as [$from, $pct]) {
            if ($from->lte($day)) {
                $rate = $pct;
            }
        }

        return $rate;
    }

    public static function currentRate(?CarbonImmutable $day = null): string
    {
        return self::rateOn($day ?? CarbonImmutable::today());
    }

    private static function pct(int $pence, int|string $pct): int
    {
        return (int) BigDecimal::of($pence)->multipliedBy($pct)->dividedBy(100, 0, RoundingMode::DOWN)->toInt();
    }

    // ---------------------------------------------------------------- output

    private static function present(string $label, array $y, array $penalties, array $obligations, int $interest, int $perDay, CarbonImmutable $asOf): array
    {
        $due = array_values(array_filter($penalties, fn ($p) => $p['status'] !== 'upcoming'));
        $upcoming = array_values(array_filter($penalties, fn ($p) => $p['status'] === 'upcoming'));
        usort($upcoming, fn ($a, $b) => $a['date'] <=> $b['date']);
        $unpaid = array_sum(array_map(fn ($o) => $o['outstanding_pence'], $obligations));
        $actions = [];
        if ($y['return_needed'] && ! $y['filed']) {
            $actions[] = $asOf->lte($y['deadline'])
                ? 'File the return by '.$y['deadline']->format('j F Y').' to avoid the £100 late filing penalty.'
                : 'File the return as soon as you can: every day from '.$y['deadline']->addDay()->addMonths(3)->format('j F Y').' adds £10 (up to £900), and more is charged at 6 and 12 months.';
        }
        if ($unpaid > 0) {
            $next = $upcoming ? array_values(array_filter($upcoming, fn ($p) => str_starts_with($p['code'], 'sch56') || str_starts_with($p['code'], 'sch26')))[0] ?? null : null;
            $actions[] = 'Pay the '.Money::format($unpaid).' owed'.($next ? ' before '.CarbonImmutable::parse($next['date'])->format('j F Y').' to avoid a '.Money::format($next['amount_pence']).' late payment penalty' : '')
                .($perDay > 0 ? '; interest is adding about '.Money::format($perDay).' a day' : '').'.';
            $actions[] = 'Can’t pay it all? If you owe £30,000 or less and your returns are up to date, a payment plan set up online stops further late payment penalties (interest still runs).';
        }
        if ($due) {
            $actions[] = 'If something outside your control stopped you (serious illness, a bereavement, an HMRC system problem), you can appeal within 30 days of the penalty notice: that’s a “reasonable excuse”.';
        }

        return [
            'tax_year' => $label,
            'regime' => $y['new_regime'] ? 'new' : 'sch55_56',
            'regime_text' => $y['new_regime'] ? 'New rules (points for late filing, new late payment penalties)' : 'Current rules (Schedules 55 and 56)',
            'deadline' => $y['deadline']->format('Y-m-d'),
            'filed' => $y['filed'], 'filed_on' => $y['filed_on']?->format('Y-m-d'), 'return_needed' => $y['return_needed'], 'mtd' => $y['mtd'],
            'liability_pence' => $y['liability'], 'basis' => $y['basis'], 'estimable' => $y['estimable'],
            'payment_plan_from' => $y['plan_from']?->format('Y-m-d'),
            'obligations' => array_map(fn ($o) => ['kind' => $o['kind'], 'label' => $o['label'], 'due' => $o['due']->format('Y-m-d'), 'amount_pence' => $o['amount'],
                'paid_pence' => $o['amount'] - $o['outstanding_pence'], 'outstanding_pence' => $o['outstanding_pence'], 'interest_pence' => $o['interest_pence']], $obligations),
            'penalties' => $due,
            'upcoming' => $upcoming,
            'interest_pence' => $interest,
            'per_day_pence' => $perDay,
            'penalties_pence' => array_sum(array_column($due, 'amount_pence')),
            'upcoming_pence' => array_sum(array_column($upcoming, 'amount_pence')),
            'unpaid_pence' => $unpaid,
            'actions' => $actions,
            'rate_pct' => self::rateOn($asOf),
            'assumptions' => [
                'An estimate from your records: HMRC’s statement is the final word.',
                'Payments to HMRC in your statements are matched to the oldest amount due first, as HMRC usually does.',
                'Assumes an online return with the usual notice to file, and penalties notified on the day they arise.',
                'The tax is worked out from '.$y['basis'].'; other income (a job, savings, dividends) isn’t included.',
            ],
        ];
    }
}
