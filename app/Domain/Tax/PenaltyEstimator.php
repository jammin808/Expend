<?php

namespace App\Domain\Tax;

use App\Models\Allocation;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\Months;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Works out the fines and interest HMRC charges (or will charge) for each tax year, from what Expend knows: whether
 * HMRC sent a notice to file and when, when each return was (or wasn't) filed and how, the tax each year comes to,
 * and the payments you've made to HMRC.
 *
 *  - Notice to file: only a notice creates a filing date (TMA 1970 s8). With none, there are no late filing
 *    penalties; not telling HMRC by 5 October costs a failure-to-notify penalty (FA 2008 Sch 41) instead, on the tax
 *    still unpaid on 31 January. Dates come from FilingDates.
 *  - Late filing: Schedule 55 (£100, then £10 a day from three months for up to 90 days, then 5% or £300 at six
 *    and twelve months), or, for years under the new rules (PenaltyRegime), late submission points counted across
 *    all your returns and Making Tax Digital quarterly updates, with £200 at the threshold (LateSubmissionPoints;
 *    the submissions and the days you sent them are in Submissions).
 *  - Late payment: Schedule 56 (5% of what's unpaid 30 days, 6 months and 12 months after the due date), or the new
 *    penalties (Schedule 26) for years under the new rules. What's penalised is the year's tax less what's been paid
 *    towards it, so unpaid payments on account are inside it, though they're never penalised on their own. Extra tax
 *    from an amended return is penalised from its own, later date. A payment plan holds penalties off from the day
 *    you asked to the end of the plan, if HMRC agree it; broken, they're at risk until HMRC charge them by notice.
 *  - Payments on account: only once there's a return (or HMRC determination) for the year before; interest on them
 *    as charged until this year's return is in, then on half this year's tax if that's lower.
 *  - Interest: simple, at HMRC's rate in force each day, on payments on account, balancing payments and penalties
 *    alike (on a penalty from 30 days after HMRC charge it: a late payment penalty no earlier than the return, or
 *    HMRC's determination, gives a figure for the tax), in runs rounded down to the penny the way HMRC's published
 *    example is (LatePaymentInterest).
 *
 * Figures the law fixes are worked out exactly. Anything that rests on a fact Expend can't know (a notice HMRC sent,
 * the date on it, whether HMRC charged a penalty in time) uses what you've told it, or a stated assumption, and is
 * marked as an estimate; so are the new rules, and new rates, for years where they're announced but not law (the law's
 * figures are in the totals, the announced ones beside them). Your payments are
 * matched to the oldest amount due first, as HMRC usually does. HMRC's own statement is the final word. The rules and
 * rates are in resources/tax/penalties.json.
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
     * @param  array{filed_on?:array<string,string>, pay_all_on?:string, plan_from?:string}  $whatIf  plan_from: a payment plan asked for on that date, agreed and kept
     * @return array{years:list<array>, totals:array, as_of:string, payments:list<array>}
     */
    public static function all(?CarbonImmutable $asOf = null, array $whatIf = []): array
    {
        // Each year asks about your Making Tax Digital status and other settings: read them once.
        return Setting::remembering(fn () => self::compute($asOf ?? CarbonImmutable::today(), $whatIf));
    }

    private static function compute(CarbonImmutable $asOf, array $whatIf): array
    {
        self::$summaries = [];
        $labels = self::labels($asOf);
        $years = [];
        foreach ($labels as $label) {
            $years[$label] = self::yearFacts($label, $whatIf);
        }
        foreach ($labels as $label) {
            $years[$label]['poa'] = self::poaFacts($label, $years);
        }
        // Late submission points are counted across years: one count for the rules that are law, and one with the
        // announced rules too, for the years where they're only announced.
        $stored = Submissions::stored();
        foreach ($labels as $label) {
            $years[$label]['submissions'] = Submissions::forYear($label, $years[$label], $stored);
        }
        $ledgers = self::pointsLedgers($years, $asOf);
        foreach ($labels as $label) {
            $years[$label]['points_ledger'] = match ($years[$label]['regime']) {
                PenaltyRegime::LAW => $ledgers['law'],
                PenaltyRegime::ANNOUNCED => $ledgers['announced'],
                default => null,
            };
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
        $payBy = (int) (self::rules()['interest']['penalty_interest_from_days_after_notice'] ?? 30);
        foreach ($years as $label => $y) {
            // Where the new rules are announced but not law, the law (Schedules 55 and 56) gives the figures, and the
            // new rules are worked out alongside as an estimate.
            $newRules = $y['regime'] === PenaltyRegime::LAW;
            $filing = self::filingPenalties($label, $y, $asOf, $newRules);
            $plan = [];
            $paying = self::paymentPenalties($label, $y, $obligations, $payments, $asOf, $whatIf, $newRules, $plan);
            $notify = self::failureToNotify($label, $y, $obligations, $payments, $asOf);
            $announced = null;
            $ignored = [];
            if ($y['regime'] === PenaltyRegime::ANNOUNCED) {
                $announced = ['text' => 'Under the announced new rules (not yet law)', 'items' => array_map(fn ($p) => array_replace($p, ['estimate' => true, 'assumption' => trim('Announced, not yet law: worked out as if the new rules apply to '.$label.'. '.($p['assumption'] ?? ''))]),
                    array_merge(self::filingPenalties($label, $y, $asOf, true), self::paymentPenalties($label, $y, $obligations, $payments, $asOf, $whatIf, true, $ignored, true)))];
            } elseif ($newRules && ($rates = self::sch26Rates($label))['announced'] !== null) {
                // The new rules are law for you, but higher rates for this year are only announced: the figures above
                // use the rates in the law, and these show the announced ones.
                $a = $rates['announced'];
                $announced = ['text' => 'With the '.$a['first_pct'].'% late payment rates announced for '.$label.' (not yet law)',
                    'items' => array_map(fn ($p) => array_replace($p, ['estimate' => true]), self::paymentPenalties($label, $y, $obligations, $payments, $asOf, $whatIf, true, $ignored, true))];
            }
            $penalties = array_merge($filing, $notify, $paying);
            $out[$label] = ['facts' => $y, 'penalties' => $penalties, 'announced' => $announced, 'plan' => $plan];
            foreach ($penalties as $p) {
                // A failure-to-notify penalty is only charged once HMRC know, on a percentage they decide; one your
                // payment plan held off is only charged if HMRC send a notice; and a late payment penalty on a return
                // not yet filed (with no determination) can't be charged until there's a figure for the tax: no
                // interest on any of them yet. Interest on a penalty runs from 30 days after HMRC charge it (the daily
                // ones at day 90 or on filing; late payment ones once the return is in).
                if (! in_array($p['status'], ['upcoming', 'at_risk'], true) && $p['amount_pence'] > 0 && $p['code'] !== 'sch41' && ! $p['awaits_return']) {
                    $obligations[] = ['year' => $label, 'kind' => 'penalty', 'label' => $p['label'], 'due' => CarbonImmutable::parse($p['charged_on'] ?? $p['date'])->addDays($payBy),
                        'amount' => $p['amount_pence'], 'interest_cap' => $p['amount_pence']];
                }
            }
        }
        $ledger = self::allocate($obligations, $payments);

        $result = [];
        $totals = ['penalties_pence' => 0, 'interest_pence' => 0, 'upcoming_pence' => 0, 'per_day_pence' => 0, 'unpaid_pence' => 0, 'on_filing_pence' => 0, 'at_risk_pence' => 0];
        foreach ($out as $label => $o) {
            $yearOblig = array_values(array_filter($ledger, fn ($l) => $l['year'] === $label));
            $interest = 0;
            $perDay = 0;
            foreach ($yearOblig as &$l) {
                $calc = self::interest($l, $asOf);
                $l['interest_pence'] = $calc['pence'];
                $l['interest_segments'] = $calc['segments'];
                $l['outstanding_pence'] = self::outstandingOn($l, $asOf);
                $interest += $l['interest_pence'];
                $perDay += self::dailyRate($l, $asOf);
            }
            unset($l);
            $year = self::present($label, $o['facts'], $o['penalties'], $o['announced'], $o['plan'], $yearOblig, $interest, $perDay, $asOf, $years);
            // A year too old to file your own return for is only shown while something is still owed for it.
            $owed = $year['penalties_pence'] + $year['interest_pence'] + $year['unpaid_pence'] + $year['upcoming_pence'] + $year['on_filing_pence'] + $year['at_risk_pence'];
            if ($asOf->gt($o['facts']['dates']['own_return_last']) && $owed === 0 && $year['penalties'] === []) {
                continue;
            }
            $result[] = $year;
            $totals['penalties_pence'] += $year['penalties_pence'];
            $totals['interest_pence'] += $interest;
            $totals['upcoming_pence'] += $year['upcoming_pence'];
            $totals['per_day_pence'] += $perDay;
            $totals['unpaid_pence'] += $year['unpaid_pence'];
            $totals['on_filing_pence'] += $year['on_filing_pence'];
            $totals['at_risk_pence'] += $year['at_risk_pence'];
        }

        return ['years' => $result, 'totals' => $totals, 'as_of' => $asOf->format('Y-m-d'),
            'payments' => array_map(fn ($p) => ['date' => $p['date']->format('Y-m-d'), 'amount_pence' => $p['amount'], 'source' => $p['source']], $payments),
            // Your late submission points, as the law stands and with the announced rules (null where none apply).
            'points' => ['law' => self::pointsSummary($ledgers['law'], $years, $asOf, false), 'announced' => self::pointsSummary($ledgers['announced'], $years, $asOf, true)]];
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
        // You can file your own return until 5 April four years after the year ends (TMA 1970 s34A). One year more is
        // worked out, so tax and penalties still owed for it show; all() leaves it out once nothing is owed.
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

    private static function yearFacts(string $label, array $whatIf): array
    {
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
        $summary = self::summary($label);
        // Self-employment makes you chargeable, and so obliged to tell HMRC, once gross trading and other business
        // income is over the £1,000 trading allowance (ITTOIA 2005 s783AD).
        $chargeable = ($summary['turnover_pence'] ?? 0) + ($summary['other_income_pence'] ?? 0) > (TaxParameters::for($label)['trading_allowance'] ?? 100_000);
        $dates = FilingDates::for($label, $record, $filed);
        $cached = fn (string $l) => self::summary($l);
        $day = fn ($d) => $d ? CarbonImmutable::parse($d->format('Y-m-d')) : null;
        // A payment plan: what you've told Expend, or a "what if" plan asked for on a date, agreed and kept.
        $plan = ! empty($whatIf['plan_from'])
            ? ['requested_on' => CarbonImmutable::parse($whatIf['plan_from']), 'agreed' => true, 'ends_on' => null, 'broken_on' => null, 'notice_on' => null, 'amount' => null, 'what_if' => true]
            : ['requested_on' => $day($record?->plan_requested_on), 'agreed' => $record?->plan_agreed, 'ends_on' => $day($record?->plan_ends_on),
                'broken_on' => $day($record?->plan_broken_on), 'notice_on' => $day($record?->plan_notice_on), 'amount' => $record?->plan_amount_pence, 'what_if' => false];

        return [
            'liability' => $relevant + $class2, 'relevant' => $relevant, 'class2' => $class2, 'basis' => $basis, 'estimable' => $estimate !== null,
            // Why the tax can't be worked out (it's unknown, not nil): Scottish income tax, or no rates loaded for the year.
            'unestimable' => $estimate !== null ? null : (Setting::get('scottish_taxpayer') ? 'scottish' : 'no_rates'),
            'class2_weeks' => ['weeks' => $estimate['class2_weeks'] ?? null, 'set' => $estimate['class2_weeks_set'] ?? false],
            'filed_on' => $filedOn, 'filed' => $filed, 'status' => $record?->status ?? 'outstanding',
            'deadline' => $dates['filing_date'], 'balancing_due' => $dates['balancing_due'], 'dates' => $dates,
            'notice' => $dates['notice'], 'chargeable' => $chargeable,
            // A return is needed where HMRC sent a notice to file. Not knowing, Expend assumes they did for any year
            // you track here or that you were chargeable for.
            'return_needed' => $dates['notice'] === 'given' || ($dates['notice'] === 'assumed' && ($record !== null || $chargeable)),
            'regime' => PenaltyRegime::for($label, $cached), 'mtd' => PenaltyRegime::mandated($label, $cached),
            'first_new_rules_year' => PenaltyRegime::firstNewRulesYear($cached),
            // Making Tax Digital quarterly updates this year (mandated, or as an accepted volunteer), and your first
            // year in it (no points for late quarterly updates then: HMRC's easement).
            'quarterly' => Submissions::quarterlyBasis($label, $cached),
            'first_mandated_year' => PenaltyRegime::firstMandatedYear($cached),
            'plan' => $plan,
            // Status "Amending": extra tax from the amendment is penalised from its own date (Sch 3ZA; Sch 56 item 19).
            'amending' => $record?->status === 'amending',
            'amended_on' => $day($record?->amended_on),
            'original_liability' => $record?->original_liability_pence,
            'determination_on' => $dates['determination_on'],
            'record' => [
                'notice_to_file' => $record?->notice_to_file, 'notice_date' => $record?->notice_date?->format('Y-m-d'),
                'filed_method' => $record?->filed_method, 'filing_date_override' => $record?->filing_date_override?->format('Y-m-d'),
                'deferred_to' => $record?->deferred_to?->format('Y-m-d'), 'told_hmrc_by_5_october' => $record?->told_hmrc_by_5_october,
            ],
        ];
    }

    /**
     * Payments on account for the year. They're only due once there's a return (or HMRC's determination) for the year
     * before (TMA 1970 s59A(1)); one filed late makes them due from the original dates all the same (s59A(4A)). Each is
     * half of that year's income tax and Class 4, none if that was under £1,000. Until this year's return is filed,
     * interest runs on them as charged. Once it's filed, HMRC cut each to half this year's figure if that's lower (Sch
     * 53 paras 1-2; SAM1020), with interest still from the original dates, so anything paid on the first above that
     * counts towards the second from 31 July (SALF305).
     *
     * @return array{charged:int, amount:int, notes:list<string>}
     */
    private static function poaFacts(string $label, array $years): array
    {
        $y = $years[$label];
        $period = UkTaxYear::fromLabel($label);
        $prevLabel = $period->previous()->label();
        $prev = $years[$prevLabel] ?? null;
        $threshold = TaxParameters::for($label)['payments_on_account_threshold'] ?? 100_000;
        $would = $prev !== null && $prev['relevant'] >= $threshold ? intdiv($prev['relevant'], 2) : 0;
        $assessed = $prev !== null && ($prev['filed'] || $prev['determination_on'] !== null);
        $charged = $assessed ? $would : 0;
        $amount = $y['filed'] ? min($charged, max(0, intdiv($y['relevant'], 2))) : $charged;
        $notes = [];
        if ($would > 0 && ! $assessed) {
            $notes[] = 'No payments on account for '.$label.': HMRC only ask for them once the return for '.$prevLabel.' (or their own determination of it) is in. '
                .'If it’s filed late they’re due from the original dates (31 January and 31 July '.($period->startYear + 1).') all the same, with interest.';
        } elseif ($charged > 0 && ! $y['filed']) {
            $notes[] = 'Interest on the payments on account for '.$label.' runs on the amounts charged ('.Money::format($charged).' each, half of '.$prevLabel.'’s income tax and Class 4) '
                .'until this year’s return is filed; then HMRC cut them to half this year’s figure if that’s lower.';
        } elseif ($amount < $charged) {
            $notes[] = 'This year’s tax is less than the payments on account, so HMRC cut each to '.Money::format($amount).' (half this year’s income tax and Class 4), with interest from the original dates on that. '
                .'Anything paid towards the first above that counts towards the second from 31 July.';
        }

        return ['charged' => $charged, 'amount' => $amount, 'notes' => $notes];
    }

    /** Payments on account for the year (see poaFacts) and the balancing payment. */
    private static function obligationsFor(string $label, array $years): array
    {
        $y = $years[$label];
        $period = UkTaxYear::fromLabel($label);
        $poa = $y['poa']['amount'];
        $out = [];
        if ($poa > 0) {
            $cut = $poa < $y['poa']['charged'] ? ' (cut to half this year’s tax)' : '';
            $poa1 = CarbonImmutable::create($period->startYear + 1, 1, 31);
            $poa2 = CarbonImmutable::create($period->startYear + 1, 7, 31);
            // Where last year's tax was due late under s59B(3), HMRC's system starts interest on these from the same
            // later date as on that tax (SAM60050, SAM60052); the dates in the law don't move.
            $later = $years[$period->previous()->label()]['dates']['interest_from'] ?? $poa1;
            $out[] = ['year' => $label, 'kind' => 'poa1', 'label' => '1st payment on account'.$cut, 'due' => $poa1, 'interest_from' => $later->max($poa1), 'amount' => $poa, 'interest_cap' => $poa];
            $out[] = ['year' => $label, 'kind' => 'poa2', 'label' => '2nd payment on account'.$cut, 'due' => $poa2, 'interest_from' => $later->max($poa2), 'amount' => $poa, 'interest_cap' => $poa];
        }
        $balancing = max(0, $y['liability'] - 2 * $poa);
        // The tax is owed whether or not HMRC asked for a return: due on 31 January, or later under s59B(3).
        if ($balancing > 0 && ($y['return_needed'] || $y['chargeable'])) {
            $out[] = ['year' => $label, 'kind' => 'balancing', 'label' => 'Balancing payment', 'due' => $y['balancing_due'], 'interest_from' => $y['dates']['interest_from']->max($y['balancing_due']),
                'amount' => $balancing, 'interest_cap' => $balancing];
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
     * Match payments to amounts due, oldest due first (payments on account you entered go to their own year: its
     * payments on account, then its balancing payment).
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
            $targets = isset($p['year']) ? array_keys(array_filter($obligations, fn ($o) => $o['year'] === $p['year'] && in_array($o['kind'], ['poa1', 'poa2', 'balancing'], true))) : array_keys($obligations);
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

    /** What a late filing figure rests on, when it isn't something you've told Expend: the notice, or its date. */
    private static function noticeAssumption(string $label, array $y): array
    {
        $assumed = array_values(array_filter([
            $y['notice'] === 'assumed' ? 'Assumes HMRC sent you a notice to file by 31 July '.(UkTaxYear::fromLabel($label)->startYear + 1).', as they do for anyone registered for Self Assessment.' : null,
            $y['deadline'] && $y['dates']['filing_basis'] === 'estimate' ? 'The deadline ('.$y['deadline']->format('j F Y').') is estimated from the date of HMRC’s notice; the date printed on the notice is the one that counts.' : null,
        ]));

        return $assumed ? ['estimate' => true, 'assumption' => implode(' ', $assumed)] : [];
    }

    private static function filingPenalties(string $label, array $y, CarbonImmutable $asOf, bool $newRules): array
    {
        // The new rules: late submission points (and £200 at the threshold) on the return and quarterly updates.
        if ($newRules) {
            return self::pointsPenalties($label, $y, $asOf);
        }
        // No notice to file, no filing date, so no late filing penalty (failure to notify applies instead).
        if (! $y['return_needed'] || $y['deadline'] === null) {
            return [];
        }
        $deadline = $y['deadline'];
        $filedOn = $y['filed_on'];
        $unfiledOn = fn (CarbonImmutable $d) => ! $y['filed'] || ($filedOn && $filedOn->gt($d));
        $state = fn (CarbonImmutable $d) => $d->lte($asOf) ? 'due' : 'upcoming';
        $out = [];
        $r = self::rules();
        $fmt = fn (CarbonImmutable $d) => $d->format('j F Y');
        $extra = self::noticeAssumption($label, $y);

        $s = $r['sch55'];
        $penaltyDate = $deadline->addDay();
        $liability = $y['liability'];
        $src = $s['sources'][0];
        $tooLate = ! $y['filed'] && $asOf->gt($y['dates']['own_return_last']);
        if ($unfiledOn($deadline)) {
            $out[] = self::penalty('sch55_initial', 'Late filing: £100', $penaltyDate, $s['initial_pence'], $state($penaltyDate), 'The return wasn’t filed by '.$fmt($deadline).'. The £100 is due even if no tax is owed.', $src, $extra);
        }
        // Daily: once the return is still unfiled at the end of the 3 months beginning with the penalty date, £10 a day
        // for up to 90 days, counted up to and including the day HMRC get the return (SAM61230).
        $dailyFrom = Months::after($penaltyDate, $s['daily_from_months']);
        $dailyTo = $dailyFrom->addDays($s['daily_max_days'] - 1);
        if ($unfiledOn($dailyFrom->subDay())) {
            $end = $filedOn && $filedOn->lt($dailyTo) ? $filedOn : $dailyTo;
            $soFar = $asOf->lt($dailyFrom) ? 0 : (int) $dailyFrom->diffInDays($asOf->lt($end) ? $asOf : $end) + 1;
            $all = (int) $dailyFrom->diffInDays($end) + 1;
            $daily = ['estimate' => true, 'assumption' => trim(($extra['assumption'] ?? '').' Assumes HMRC gave notice of daily penalties, as they normally do.')];
            if ($soFar > 0) {
                // HMRC charge them all at once, at day 90 or when the return arrives (SA370, SAM61230), so interest on
                // them runs from 30 days after that, not from the start date.
                $out[] = self::penalty('sch55_daily', "Daily penalties: £10 × {$soFar} days", $dailyFrom, $soFar * $s['daily_pence'], $soFar < $all && $asOf->lt($end) ? 'accruing' : 'due',
                    '£10 a day from '.$fmt($dailyFrom).' for up to 90 days (£900), counted up to and including the day HMRC get your return. HMRC must give notice of the start date, usually in the £100 penalty notice. '
                    .'They charge them at day 90 or when the return arrives, whichever is first ('.$fmt($end).'), and interest runs from 30 days after that.', $src, $daily + ['charged_on' => $end->format('Y-m-d')]);
            }
            if ($all > $soFar && ! $filedOn) {
                $out[] = self::penalty('sch55_daily_more', 'More daily penalties if still not filed', $asOf->lt($dailyFrom) ? $dailyFrom : $asOf->addDay(), ($all - $soFar) * $s['daily_pence'], 'upcoming',
                    'Up to '.Money::format(($all - $soFar) * $s['daily_pence']).' more, £10 a day until '.$fmt($dailyTo).' or until HMRC get your return.', $src, $daily);
            }
        }
        foreach ([['sch55_6m', 'Six months late', 6, $s['six_month_pct'], $s['six_month_min_pence']], ['sch55_12m', 'Twelve months late', 12, $s['twelve_month_pct'], $s['twelve_month_min_pence']]] as [$code, $title, $months, $pct, $min]) {
            // Charged if still unfiled at the end of the 6 (or 12) months beginning with the penalty date.
            $trigger = Months::after($penaltyDate, $months);
            if (! $unfiledOn($trigger->subDay())) {
                continue;
            }
            $full = max($min, self::pct($liability, $pct));
            // While the return isn't in, HMRC charge £300 and add the rest when it's filed (SAM61240).
            [$now, $later] = $y['filed'] ? [$full, 0] : [$min, $full - $min];
            $when = $tooLate ? 'once HMRC assess the tax' : 'when you file';
            // The tax isn't worked out (Scottish, or no rates): £300 is the least it can be, not the figure.
            $unknown = ! $y['estimable'];
            $known = $unknown ? ['estimate' => true, 'assumption' => trim(($extra['assumption'] ?? '').' '.self::unestimableText($label, $y).', so 5% of it may be more than '.Money::format($min).'.')] : [];
            $out[] = self::penalty($code, "{$title}: ".Money::format($now).($unknown ? ($y['filed'] ? ' at least' : ' now, maybe more '.$when) : ($later > 0 ? ' now, '.Money::format($later).' more '.$when : '')), $trigger, $now, $state($trigger),
                "The greater of {$pct}% of the tax for the year (".($unknown ? 'not worked out here' : Money::format($liability).', from '.$y['basis']).') and '.Money::format($min).'.'
                .(! $y['filed'] ? ' While the return isn’t filed HMRC charge '.Money::format($min).' and add the rest '.$when.'.' : '')
                .($code === 'sch55_12m' ? ' It can be 70% or 100% if information was deliberately withheld.' : ''), $src, $known + $extra + ['on_filing_pence' => $later]);
        }

        // HMRC must charge these by the last day of 2 years beginning with the filing date (Sch 55 para 19).
        return self::timeLimited($out, Months::periodEnd($deadline, 24), $y, $asOf);
    }

    // ---------------------------------------------------------------- late submission points

    /**
     * The points count (LateSubmissionPoints) over the years under the new rules: 'law' over the years they're law
     * for you, 'announced' over those and the years they're only announced for. The return for a year under the old
     * rules only matters for whether all points can go (para 8 condition B), which HMRC apply to returns from before
     * the new rules for VAT; Expend does the same, as an estimate. An accepted volunteer's quarterly updates aren't
     * counted (HMRC's guidance), and late quarterly updates in your first year in Making Tax Digital earn no point.
     *
     * @return array{law:?array, announced:?array}
     */
    private static function pointsLedgers(array $years, CarbonImmutable $asOf): array
    {
        $rules = self::rules()['points'];
        $day = fn (?string $d) => $d ? CarbonImmutable::parse($d) : null;
        $build = function (array $regimes) use ($years, $asOf, $rules, $day): ?array {
            $obligations = [];
            $groups = [];
            foreach ($years as $label => $y) {
                if (! in_array($y['regime'], $regimes, true)) {
                    if ($y['return_needed'] && $y['deadline'] !== null) {
                        $obligations[] = ['key' => $label.':annual', 'kind' => 'annual', 'due' => $y['deadline'], 'submitted' => $y['filed_on'], 'made' => $y['filed'],
                            'excused' => false, 'eligible' => false, 'counts' => false, 'reason' => null];
                    }

                    continue;
                }
                $groups[$label] = $y['mtd'] ? '1B' : '1A';
                foreach ($y['submissions'] as $s) {
                    if ($s['kind'] === 'quarterly' && $s['basis'] !== 'mandated') {
                        continue;
                    }
                    $firstYear = $s['kind'] === 'quarterly' && $label === $y['first_mandated_year'];
                    $obligations[] = ['key' => $s['key'], 'kind' => $s['kind'], 'due' => $day($s['due_on']), 'submitted' => $day($s['submitted_on']), 'made' => $s['made'],
                        'excused' => $s['excused'], 'eligible' => ! $firstYear, 'counts' => true,
                        'reason' => $firstYear ? 'No point, because late quarterly updates in your first year in Making Tax Digital ('.$label.') don’t earn points. That’s HMRC’s practice, not law.' : null];
                }
            }

            return $groups === [] ? null : LateSubmissionPoints::run($obligations, $groups, $asOf, $rules);
        };
        $regimes = array_column($years, 'regime');

        return [
            'law' => in_array(PenaltyRegime::LAW, $regimes, true) ? $build([PenaltyRegime::LAW]) : null,
            'announced' => in_array(PenaltyRegime::ANNOUNCED, $regimes, true) ? $build([PenaltyRegime::LAW, PenaltyRegime::ANNOUNCED]) : null,
        ];
    }

    /**
     * The year's points and £200 penalties, from the count. A point is £0: it's what you're liable to, and HMRC give
     * it by notice. £200 needs the points to have been given, so it's marked as an estimate resting on that.
     */
    private static function pointsPenalties(string $label, array $y, CarbonImmutable $asOf): array
    {
        $ledger = $y['points_ledger'] ?? null;
        if ($ledger === null) {
            return [];
        }
        $rules = self::rules()['points'];
        $fmt = fn (string $d) => CarbonImmutable::parse($d)->format('j F Y');
        $out = [];
        foreach ($y['submissions'] as $s) {
            $e = $ledger['events'][$s['key']] ?? null;
            if ($e === null || ! $e['counts'] || $e['failure_on'] === null || (! $e['point'] && $e['penalty_pence'] === 0)) {
                continue;
            }
            $on = CarbonImmutable::parse($e['failure_on']);
            $status = $on->lte($asOf) ? 'due' : 'upcoming';
            $annual = $s['kind'] === 'annual';
            $suffix = $annual ? '' : '_q'.$s['quarter'];
            $what = $annual ? 'Late return' : 'Late quarterly update ('.Submissions::periodText($s).')';
            $threshold = $e['threshold'];
            $extra = $annual ? self::noticeAssumption($label, $y) : [];
            if (! $annual && $e['state'] === 'not_sent') {
                $extra = ['estimate' => true, 'assumption' => 'No “sent on” date is recorded for this update, so it’s taken as not sent. If you sent it, record the date on the year page.'];
            }
            if ($e['point']) {
                $out[] = self::penalty('points'.$suffix, $what.': a late submission point ('.$e['held'].' of '.$threshold.')', $on, 0, $status,
                    'A late '.($annual ? 'return' : 'quarterly update').' makes you liable to a late submission point, not a fine. At '.$threshold.' points ('
                    .($threshold === (int) $rules['threshold_quarterly'] ? 'the threshold for people in Making Tax Digital' : 'the threshold for annual returns only').') there’s a £200 penalty, and another £200 for each later late submission while you’re at '.$threshold.'. '
                    .'This point expires at the end of '.$fmt($e['expires_on']).', unless you’re at '.$threshold.' points then; if you are, it stays until all your points go.',
                    $rules['sources'][0], $extra + ['expires_on' => $e['expires_on']]);
            }
            if ($e['penalty_pence'] > 0) {
                $item = self::penalty('sch24_200'.$suffix, $what.': £200 at '.$threshold.' points', $on, $e['penalty_pence'], $status,
                    ($e['point'] ? 'This point took you to '.$threshold.' points, the threshold' : 'You were already at '.$threshold.' points, the threshold').', so there’s a £200 penalty. '
                    .'Each later late submission costs another £200 (at most one a month for each kind of submission) until all your points go. HMRC send a notice, and it’s due within 30 days of it.',
                    $rules['sources'][1] ?? $rules['sources'][0],
                    ['estimate' => true, 'assumption' => trim(($extra['assumption'] ?? '').' Assumes HMRC give each point you’re liable to, as they normally do: giving them is up to HMRC, and the £200 needs them.')]);
                // HMRC must charge it by the later of 2 years from the failure and, for a return, 12 months after the
                // tax is known (para 17).
                $dateA = Months::periodEnd($on, 24);
                if ($annual) {
                    [$item] = self::timeLimited([$item], $dateA, $y, $asOf);
                } else {
                    $item['date_a'] = $dateA->format('Y-m-d');
                    if ($status === 'due' && $asOf->gt($dateA)) {
                        $item['time_limit'] = 'Only if HMRC charged it by '.$dateA->format('j F Y').'; after that it can no longer be charged.';
                    }
                }
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Each submission of a year as the year page and the to-do list show it: due, sent, and what being late did (or,
     * not yet due, would do if nothing changes).
     */
    private static function submissionsView(array $y, CarbonImmutable $asOf): array
    {
        $ledger = $y['points_ledger'] ?? null;
        $announced = $y['regime'] === PenaltyRegime::ANNOUNCED;
        $fmt = fn (string $d) => CarbonImmutable::parse($d)->format('j F Y');

        return array_map(function (array $s) use ($ledger, $announced, $asOf, $fmt) {
            $e = $ledger['events'][$s['key']] ?? null;
            $state = $e['state'] ?? LateSubmissionPoints::state(['made' => $s['made'], 'submitted' => $s['submitted_on'] ? CarbonImmutable::parse($s['submitted_on']) : null,
                'due' => CarbonImmutable::parse($s['due_on'])], $asOf);
            if ($s['kind'] === 'quarterly' && $s['basis'] === 'volunteer') {
                $effect = 'Not counted for points: HMRC give accepted volunteers no points for quarterly updates.';
            } elseif ($e === null || $state === 'on_time') {
                $effect = $state === 'on_time' ? ($s['made'] && $s['submitted_on'] === null ? 'Filed (date not recorded): taken as on time.' : 'Sent on time.') : null;
            } elseif ($state === 'excused') {
                $effect = 'Late, but HMRC accepted your excuse: no point, and it counts as on time.';
            } else {
                // Not due yet: what being late would do. No "sent on" date: Expend can't tell it was late, only that it
                // has no date for it, so it says what follows if it wasn't sent in time.
                $late = match ($state) {
                    'not_due' => 'If it’s late: ',
                    'not_sent' => 'If it wasn’t sent on time: ',
                    default => 'Late: ',
                };
                $effect = $late.($e['point'] ? 'a point ('.$e['held'].' of '.$e['threshold'].'), expiring at the end of '.$fmt($e['expires_on']).($e['penalty_pence'] > 0 ? ', and £200 for reaching '.$e['threshold'] : '').'.'
                    : ($e['penalty_pence'] > 0 ? '£200, because '.($state === 'not_due' ? 'you’d be' : 'you were').' at '.$e['threshold'].' points.' : lcfirst((string) $e['note'])));
                if ($announced) {
                    $effect .= ' (Under the announced rules, which aren’t law yet.)';
                }
            }

            return ['id' => $s['id'], 'kind' => $s['kind'], 'quarter' => $s['quarter'], 'period_start' => $s['period_start'], 'period_end' => $s['period_end'],
                'due_on' => $s['due_on'], 'submitted_on' => $s['submitted_on'], 'made' => $s['made'], 'excused' => $s['excused'], 'note' => $s['note'],
                'derived' => $s['derived'], 'volunteer' => $s['basis'] === 'volunteer', 'status' => $state, 'effect' => $effect,
                'point' => (bool) ($e['point'] ?? false), 'penalty_pence' => (int) ($e['penalty_pence'] ?? 0), 'announced' => $announced,
                'what' => Submissions::describe($s)];
        }, $y['submissions']);
    }

    /**
     * Your points as they stand today, for the year page, the to-do list and the assistant: how many and the
     * threshold, when each goes, when they'd all go if everything is sent on time from now, the £200s, the next
     * submission and what being late would cost, and what all that rests on.
     */
    private static function pointsSummary(?array $ledger, array $years, CarbonImmutable $asOf, bool $announced): ?array
    {
        if ($ledger === null) {
            return null;
        }
        $subs = [];
        foreach ($years as $y) {
            foreach ($y['submissions'] as $s) {
                $subs[$s['key']] = $s;
            }
        }
        $what = fn (string $key) => isset($subs[$key]) ? Submissions::describe($subs[$key]) : 'the '.str_replace(':annual', '', $key).' return';
        $fmt = fn (?string $d) => $d ? CarbonImmutable::parse($d)->format('j F Y') : null;
        $threshold = $ledger['threshold'];
        $held = $ledger['held'];
        $points = array_map(fn ($p) => ['what' => $what($p['key']), 'failure_on' => $p['failure_on'], 'expires_on' => $p['expires_on'], 'stays' => $p['stays'],
            'goes_on' => $p['goes_on'], 'added' => $p['added']], $ledger['points']);
        $fines = [];
        $next = null;
        foreach ($ledger['events'] as $key => $e) {
            if ($e['counts'] && $e['penalty_pence'] > 0) {
                $fines[] = ['on' => $e['failure_on'], 'what' => $what($key), 'pence' => $e['penalty_pence'], 'projected' => $e['projected']];
            }
            if ($e['counts'] && $e['state'] === 'not_due' && isset($subs[$key]) && ($next === null || $subs[$key]['due_on'] < $next['due_on'])) {
                $next = ['what' => $what($key), 'due_on' => $subs[$key]['due_on'],
                    'if_late' => $e['penalty_pence'] > 0 ? '£200'.($e['point'] ? ' and a point ('.$e['held'].' of '.$threshold.')' : '') : ($e['point'] ? 'a point ('.$e['held'].' of '.$threshold.')' : 'no point ('.lcfirst((string) $e['note']).')')];
            }
        }
        usort($fines, fn ($a, $b) => $a['on'] <=> $b['on']);
        $needs = array_map($what, $ledger['reset_needs']);
        $atMax = $held > 0 && $held >= $threshold;
        $text = match (true) {
            $held === 0 => 'You have no late submission points. At '.$threshold.' points each late submission costs £200.',
            $atMax => 'You have '.$held.' late submission points, the threshold: each late submission costs £200, and your points don’t expire on their own.',
            default => 'You have '.$held.' late submission point'.($held === 1 ? '' : 's').'. At '.$threshold.', each late submission costs £200.',
        };
        if ($held > 0) {
            $text .= ' If '.($needs ? 'you send '.implode(' and ', $needs).' now and everything else on time' : 'everything is sent on time from now').', they all go on '.$fmt($ledger['reset_on']).'.';
        }

        $mandated = $ledger['group'] === '1B';
        $assumptions = ['Points are what you’re liable to: HMRC give each one by notice, as they normally do, within '.($mandated ? '11' : '48').' weeks of the failure (or 12 months after the tax is known). A £200 needs the points to have been given.'];
        $unsent = false;
        $firstYear = null;
        foreach ($years as $label => $y) {
            foreach ($y['submissions'] as $s) {
                $e = $ledger['events'][$s['key']] ?? null;
                if ($e && $e['counts'] && $s['kind'] === 'quarterly' && $e['state'] === 'not_sent') {
                    $unsent = true;
                }
                if ($e && $s['kind'] === 'quarterly' && $label === $y['first_mandated_year']) {
                    $firstYear = $label;
                }
            }
        }
        if ($unsent) {
            $assumptions[] = 'Quarterly updates with no “sent on” date are taken as not sent. Record the dates on each year’s page.';
        }
        if ($firstYear) {
            $assumptions[] = 'No points for late quarterly updates in your first year in Making Tax Digital ('.$firstYear.'): that’s HMRC’s practice, not law. A late update that year still restarts the 12 months on time you need for your points to go, as the law reads, so that part is an estimate.';
        }
        if (! $mandated && PenaltyRegime::volunteerFrom() !== null) {
            $assumptions[] = 'As an accepted volunteer your threshold is 2 and your quarterly updates earn no points (HMRC’s guidance).';
        }
        // Moving between a yearly return only and quarterly updates (Sch 24 paras 9-13).
        $inLedger = array_filter($years, fn ($y) => $y['regime'] === PenaltyRegime::LAW || ($announced && $y['regime'] === PenaltyRegime::ANNOUNCED));
        if (count(array_unique(array_map(fn ($y) => (bool) $y['mtd'], $inLedger))) > 1) {
            $assumptions[] = 'Moving into quarterly updates adds 2 points if you have any, and moving out takes 2 off (Sch 24 para 10). A return for an earlier year that falls due after the move is counted with your points from then on: the law doesn’t say, so that part is an estimate.';
        }
        if ($held > 0) {
            $assumptions[] = 'All your points go only once everything due in the 24 months before has been sent, returns under the old rules included: HMRC say so for VAT and haven’t said for income tax, so that part is an estimate.';
        }
        if ($announced) {
            $assumptions[] = 'Announced, not yet law: points for years where the new rules are only announced are worked out as if they apply.';
        }

        return ['announced' => $announced, 'group' => $ledger['group'], 'threshold' => $threshold, 'held' => $held, 'at_threshold' => $atMax,
            'points' => $points, 'reset_on' => $ledger['reset_on'], 'reset_needs' => $needs,
            'penalties' => $fines, 'penalties_pence' => array_sum(array_map(fn ($f) => $f['projected'] ? 0 : $f['pence'], $fines)),
            'next' => $next, 'history' => $ledger['history'], 'text' => $text, 'estimate' => true, 'assumptions' => $assumptions];
    }

    /**
     * Not telling HMRC by 5 October that you need to file (TMA 1970 s7; FA 2008 Sch 41): a percentage of the tax still
     * unpaid on 31 January after the year, up to 30% for a failure that isn't deliberate. How low it goes depends on
     * whether you tell HMRC before they find out (unprompted) and when HMRC became aware: less than 12 months after the
     * tax first went unpaid (1 February), so by 31 January a year later (case A), as low as 0%; otherwise 10% (20% and
     * 10% if prompted; para 13(3)). Where HMRC sent a notice to file after 5 October they knew by then; with no notice
     * they're taken to find out when you tell them, now at the earliest. Expend can't know how you told them or how
     * fully you help, so it shows the range and assumes you told (or tell) HMRC yourself and help fully.
     */
    private static function failureToNotify(string $label, array $y, array $obligations, array $payments, CarbonImmutable $asOf): array
    {
        $d = $y['dates'];
        $unknown = ! $y['estimable'];
        if (! $y['chargeable'] || ! $d['missed_notify'] || (! $unknown && $y['liability'] <= 0)) {
            return [];
        }
        $failure = $d['notify_by']->addDay();
        if ($asOf->lt($failure)) {
            return [];
        }
        $r = self::rules()['failure_to_notify'];
        $year = UkTaxYear::fromLabel($label)->startYear;
        $measuredOn = CarbonImmutable::create($year + 2, 1, 31);
        $caseAUntil = CarbonImmutable::create($year + 3, 1, 31);
        $fmt = fn (CarbonImmutable $x) => $x->format('j F Y');
        $measured = $asOf->gt($measuredOn);
        $lateNotice = $d['notice_date'] !== null;
        // HMRC knew by the date of their notice to file at the latest; with none, when you tell them (now, at the earliest).
        $aware = $lateNotice ? $d['notice_date'] : $asOf;
        $caseA = $aware->lte($caseAUntil);
        $lowPct = $caseA ? $r['min_unprompted_within_12_months_pct'] : $r['min_unprompted_after_12_months_pct'];
        $promptedPct = $caseA ? $r['min_prompted_within_12_months_pct'] : $r['min_prompted_after_12_months_pct'];
        $told = $lateNotice
            ? 'HMRC’s notice to file came on '.$fmt($d['notice_date']).', after '.$fmt($d['notify_by']).', and you haven’t said you told them by then that you needed to file. '
            : 'You hadn’t told HMRC by '.$fmt($d['notify_by']).' that you needed to file. ';
        $floor = $lateNotice
            ? 'Taken as if you registered yourself, before HMRC contacted you, and help them fully: HMRC knew by '.$fmt($d['notice_date']).($caseA ? ', within 12 months,' : '')
                .' so it can be as low as '.$lowPct.'% ('.$promptedPct.'% at least if they contacted you first). HMRC decide the percentage. If you had told them by '.$fmt($d['notify_by']).', say so on this page: then there’s no penalty.'
            : 'Tell HMRC yourself, before they contact you, and help them work it out'.($caseA ? ' by '.$fmt($caseAUntil) : '').', and it can be as low as '.$lowPct.'% ('
                .$promptedPct.'% at least once they’ve contacted you). HMRC decide the percentage.';
        $source = $r['sources'][0];

        if ($unknown) {
            // The tax is unknown, not nil: the penalty is still a percentage of it.
            return [self::penalty('sch41', 'Telling HMRC late: up to '.$r['max_pct'].'% of the tax unpaid on '.$fmt($measuredOn).' (not worked out)', $measuredOn->addDay(), 0,
                $measured ? 'due' : 'upcoming', $told.'The penalty is a percentage of the tax for '.$label.' still unpaid on '.$fmt($measuredOn).', up to '.$r['max_pct'].'%. '.$floor, $source, [
                    'estimate' => true, 'amount_unknown' => true,
                    'assumption' => self::unestimableText($label, $y).', so this penalty isn’t worked out: it’s between '.$lowPct.'% and '.$r['max_pct'].'% of the tax unpaid on '.$fmt($measuredOn).'.',
                ])];
        }

        // The year's tax less what was paid towards it (payments on account and the balancing payment) by then.
        $plr = max(0, $y['liability'] - self::paidTowards($label, self::allocate($obligations, $payments))($measured ? $measuredOn : $asOf));
        if ($plr <= 0) {
            return [];
        }
        $low = self::pct($plr, $lowPct);
        $high = self::pct($plr, $r['max_pct']);
        $why = $measured
            ? $told.Money::format($plr).' of the tax for '.$label.' was unpaid on '.$fmt($measuredOn).'. The penalty is a percentage of that, up to '.$r['max_pct'].'%. '.$floor
            : $told.'If any of the tax for '.$label.' is still unpaid on '.$fmt($measuredOn).' ('.Money::format($plr).' now), a penalty of up to '.$r['max_pct'].'% of what’s unpaid then can be charged, '
                .'as low as '.$lowPct.'% if '.($lateNotice ? 'you registered yourself' : 'you tell HMRC yourself').' before they contacted you. Paying it all by '.$fmt($measuredOn).' means no penalty.';

        return [self::penalty('sch41', 'Telling HMRC late: '.Money::format($low).' to '.Money::format($high).' (estimate)', $measuredOn->addDay(), $low, $measured ? 'due' : 'upcoming', $why, $source, [
            'estimate' => true,
            'assumption' => ($lateNotice
                ? 'Assumes you registered yourself, before HMRC contacted you, and help them fully: the lowest the penalty can be. '
                : 'Assumes you tell HMRC yourself, before they contact you, and help them fully: the lowest the penalty can be. ')
                .'It can be up to '.Money::format($high).'.'
                .($lateNotice ? ' HMRC’s notice to file came after 5 October, so this applies unless you’d told them by then.' : ''),
            'range' => ['min_pence' => $low, 'max_pence' => $high],
        ])];
    }

    /** Why the year's tax isn't worked out (it's unknown, not nil). */
    private static function unestimableText(string $label, array $y): string
    {
        return ($y['unestimable'] ?? null) === 'scottish'
            ? 'Expend doesn’t work out Scottish income tax, so the tax for '.$label.' isn’t known here'
            : 'The tax rates for '.$label.' aren’t loaded in Expend yet, so the tax for the year isn’t known here';
    }

    /**
     * Late payment penalties (Sch 56, or Sch 26 under the new rules) on the year's tax and, for an amended return, on
     * the extra tax from the amendment. $tally collects what a payment plan does to them. $announcedRates: use Sch 26
     * rates that are announced for the year but not law (for the figures shown beside the law's).
     *
     * The amount penalised at each date is the year's tax less everything paid towards it by then (its payments on
     * account and balancing payment), so payments on account still unpaid are inside it (TMA 1970 s59B(1); SAM61390);
     * next year's first payment on account, due the same day, isn't. Payments on account are never penalised on their own.
     *
     * HMRC can only charge one once there's a figure for the tax: your return, or their determination, which counts as
     * one (TMA 1970 s28C(3)). So each is taken as charged on the later of its date and the day the return was filed (or
     * the determination made), and, with neither yet, it waits: no interest on it, and it's marked as an estimate.
     */
    private static function paymentPenalties(string $label, array $y, array $obligations, array $payments, CarbonImmutable $asOf, array $whatIf, bool $newRules, array &$tally, bool $announcedRates = false): array
    {
        $tally = ['protected' => 0, 'pending' => 0, 'at_risk' => 0, 'at_risk_later' => 0, 'charged' => 0, 'plan' => null];
        $liability = $y['liability'];
        if ($liability <= 0 || ! ($y['return_needed'] || $y['chargeable'])) {
            return [];
        }
        // What's unpaid on each date depends on all the payments, matched oldest first. A date still to come is judged
        // on what's been paid so far (or, for "what if I pay on…", on the payments then).
        $paidBy = self::paidTowards($label, self::allocate($obligations, $payments));
        $unpaid = fn (CarbonImmutable $d) => max(0, $liability - $paidBy($d));
        $at = fn (CarbonImmutable $t) => $t->lte($asOf) || ! empty($whatIf['pay_all_on']) ? $t : $asOf;
        $plan = self::planFacts($label, $y, $asOf, $unpaid);
        $tally['plan'] = $plan;
        $state = fn (CarbonImmutable $d) => $d->lte($asOf) ? 'due' : 'upcoming';
        $fmt = fn (CarbonImmutable $d) => $d->format('j F Y');
        $r = self::rules();
        $out = [];

        // What a plan does to one penalty: none of it is charged while it's held off; it's at risk once the plan is
        // broken (until HMRC charge it by notice); it's charged in full while HMRC haven't agreed the plan, and once
        // they've charged it after a breach. Tallies what's held off (by the plan's state) and returns what's charged.
        $settle = function (int $full, int $withPlan, CarbonImmutable $on) use (&$tally, $plan, $asOf): int {
            $held = $full - $withPlan;
            if ($held <= 0 || $plan === null || $plan['state'] === null) {
                return $full;
            }
            if ($on->lte($asOf)) {
                $tally[$plan['state']] += $held;
            } elseif ($plan['state'] === 'at_risk') {
                $tally['at_risk_later'] += $held;
            }

            return in_array($plan['state'], ['pending', 'charged'], true) ? $full : $withPlan;
        };
        // What a broken plan held off: at risk until HMRC charge it by notice (Sch 56 para 10(3); Sch 26 paras 7 and 9).
        $atRisk = function (string $code, string $title, CarbonImmutable $on, int $amount, string $why, string $source) use (&$out, $plan, $fmt, $asOf) {
            if ($amount > 0 && $plan !== null && $plan['state'] === 'at_risk') {
                $out[] = self::penalty($code.'_at_risk', 'At risk: '.$title.' '.Money::format($amount), $on, $amount, 'at_risk',
                    $why.($on->gt($asOf)
                        ? ' If it arises, your payment plan holds it off, but the plan was broken on '.$fmt($plan['broken_on']).', so HMRC could charge it by sending you a notice.'
                        : ' Your payment plan held this off, but it was broken on '.$fmt($plan['broken_on']).', so HMRC can now charge it by sending you a notice.'),
                    $source, ['estimate' => true, 'assumption' => 'Assumes HMRC haven’t sent a notice charging it (say so on this page if they have). Until they do, the plan still holds it off, and if they agree to change the plan, it stays held off.']);
            }
        };
        // Charged in full after HMRC's notice following a breach: liable from the date of the notice.
        $noticed = function (array $item, int $full, int $charged, int $withPlan) use ($plan, $fmt): array {
            if ($plan === null || $plan['state'] !== 'charged' || $full - $withPlan <= 0) {
                return $item;
            }
            $on = $plan['notice_on']->max(CarbonImmutable::parse($item['date']));
            $item['charged_on'] = $on->max(CarbonImmutable::parse($item['charged_on'] ?? $item['date']))->format('Y-m-d');
            $item['why'] .= ' Your payment plan held this off, but it was broken and HMRC charged it by a notice on '.$fmt($plan['notice_on']).'.';

            return $item;
        };
        // When HMRC can charge it: once the return is in, or their determination is made (see above).
        $notFiled = ! $y['filed'];
        $chargeFrom = $y['filed_on'] ?? ($notFiled ? $y['determination_on'] : null);
        $waits = $notFiled && $y['determination_on'] === null;
        $noNotice = $y['notice'] === 'not_given';
        $basis = $notFiled ? ($y['determination_on'] !== null
            ? 'HMRC’s determination of '.$fmt($y['determination_on']).' counts as your return until you file, so they can charge it on the tax in it; it’s worked out here on the tax from '.$y['basis'].', which may differ.'
            : ($noNotice
                ? 'Assumes you register and file, so the tax counts as due from '.$fmt($y['balancing_due']).', and HMRC charge it once you file, back to this date. If HMRC assess the tax themselves instead, late payment penalties run from 30 days after their assessment.'
                : 'HMRC charge it once you file (or they make a determination), back to this date, on the tax on your return; it’s worked out here from '.$y['basis'].'.'))
            : null;
        $whenCharged = function (array $item) use ($chargeFrom, $waits, $basis): array {
            if ($basis !== null) {
                $item['estimate'] = true;
                $item['assumption'] = trim(($item['assumption'] ?? '').' '.$basis);
            }
            if ($waits) {
                // No figure for the tax yet, so it can't be charged, and no interest runs on it.
                $item['awaits_return'] = ! in_array($item['status'], ['upcoming', 'at_risk'], true);
                $item['charged_on'] = null;
            } elseif ($chargeFrom !== null) {
                $item['charged_on'] = $chargeFrom->max(CarbonImmutable::parse($item['charged_on'] ?? $item['date']))->format('Y-m-d');
            }

            return $item;
        };

        $rateRows = self::sch26Rates($label);
        foreach (self::amountsDue($y, $asOf, $unpaid) as $a) {
            $due = $a['due'];
            $u = $a['unpaid'];
            $extra = $a['extra'];
            if ($newRules) {
                $s = $r['sch26'];
                $rates = $announcedRates && $rateRows['announced'] !== null ? $rateRows['announced'] : $rateRows['law'];
                if (! empty($rates['announced'])) {
                    // Rates announced for this year but not yet made law (the law still says 3%).
                    $extra = ['estimate' => true, 'assumption' => trim('Uses the '.$rates['first_pct'].'% rates announced for '.$label.', which aren’t law yet (the law says '
                        .$rateRows['law']['first_pct'].'%). '.($extra['assumption'] ?? ''))];
                }
                // The 30-day window is HMRC's easement for your first year under these rules, given once (practice, not law).
                $first = $label === $y['first_new_rules_year'];
                $grace = $first ? $s['first_year_window_days'] : $s['first_days'];
                $day15 = $due->addDays($s['first_days']);
                $day30 = $due->addDays($s['second_days']);
                $graceEnd = $due->addDays($grace);
                // Paid in full within 15 days (30 in your first year): no penalty at all.
                if ($u($at($graceEnd)) <= 0) {
                    continue;
                }
                $from = $plan['from'] ?? null;
                $unpaid15 = $u($at($day15));
                $aOn = $day15->addDay()->max($graceEnd->addDay());
                $bOn = $day30->addDay();
                // HMRC charge the first penalty after day 30, once they know whether B is due (CH193140).
                $firstCharged = ['charged_on' => $bOn->format('Y-m-d')];
                // Amount A: proposals by day 15 (30 in your first year) that lead to an agreed plan avoid it.
                $fullA = self::pct($unpaid15, $rates['first_pct']);
                $withA = self::pct($unpaid15 - self::covered($plan, $from !== null && $from->lte($graceEnd), $aOn, $unpaid15), $rates['first_pct']);
                $chargedA = $settle($fullA, $withA, $aOn);
                if ($chargedA > 0) {
                    $out[] = $noticed(self::penalty('sch26_'.$a['code'].'a', 'Late payment'.$a['title'].': '.$rates['first_pct'].'% at day 15', $aOn, $chargedA, $state($aOn),
                        Money::format($unpaid15).' '.$a['what'].' still unpaid 15 days after it was due'.($first ? ' (and not paid, or a payment plan agreed, within the 30 days HMRC allow in your first year under these rules)' : '').'.',
                        $s['sources'][0], $extra + $firstCharged), $fullA, $chargedA, $withA);
                }
                $atRisk('sch26_'.$a['code'].'a', 'late payment'.$a['title'].', '.$rates['first_pct'].'% at day 15:', $aOn, $fullA - $chargedA, Money::format($unpaid15).' '.$a['what'].' was unpaid at day 15.', $s['sources'][0]);
                $unpaid30 = $u($at($day30));
                if ($unpaid30 <= 0) {
                    continue;
                }
                // Amount B: proposals by day 30 that lead to an agreed plan avoid it.
                $fullB = self::pct($unpaid30, $rates['second_pct']);
                $withB = self::pct($unpaid30 - self::covered($plan, $from !== null && $from->lte($day30), $bOn, $unpaid30), $rates['second_pct']);
                $chargedB = $settle($fullB, $withB, $bOn);
                if ($chargedB > 0) {
                    $out[] = $noticed(self::penalty('sch26_'.$a['code'].'b', 'Late payment'.$a['title'].': another '.$rates['second_pct'].'% at day 30', $bOn, $chargedB, $state($bOn),
                        Money::format($unpaid30).' '.$a['what'].' still unpaid 30 days after it was due.', $s['sources'][0], $extra + $firstCharged), $fullB, $chargedB, $withB);
                }
                $atRisk('sch26_'.$a['code'].'b', 'late payment'.$a['title'].', '.$rates['second_pct'].'% at day 30:', $bOn, $fullB - $chargedB, Money::format($unpaid30).' '.$a['what'].' was unpaid at day 30.', $s['sources'][0]);
                // The yearly rate, charged daily from day 31 on what's unpaid at the start of each day, until it's paid in
                // full; not from the day you made proposals that led to an agreed plan (para 8(5)-(6)); and for no more
                // than 2 years: HMRC must charge it by Date A, the last day of 2 years beginning with the due date (para
                // 18; they can from 7 days before, SI 2024/1153).
                $dateA = Months::periodEnd($due, 24);
                $end = $asOf->lt($dateA) ? $asOf : $dateA;
                if ($bOn->gt($end)) {
                    continue;
                }
                $divisor = (int) ($s['second_penalty_divisor'] ?? 365);
                $covers = fn (CarbonImmutable $d, int $balance) => self::covered($plan, $from !== null && $d->gte($from), $d, $balance);
                $full = self::yearlyRatePenalty($u, $bOn, $end, (string) $rates['annual_pct'], $divisor);
                $with = self::yearlyRatePenalty($u, $bOn, $end, (string) $rates['annual_pct'], $divisor, $covers);
                $charged = $settle($full['pence'], $with['pence'], $bOn);
                if ($charged > 0) {
                    $last = $plan !== null && in_array($plan['state'], ['protected', 'at_risk'], true) ? $with['last_day'] : $full['last_day'];
                    $accruing = $last !== null && $last->eq($asOf) && $u($asOf) > 0 && $asOf->lt($dateA);
                    $out[] = $noticed(self::penalty('sch26_'.$a['code'].'daily', 'Late payment'.$a['title'].': '.$rates['annual_pct'].'% a year from day 31', $bOn, $charged, $accruing ? 'accruing' : 'due',
                        $rates['annual_pct'].'% a year on what’s unpaid, charged daily from '.$fmt($bOn).' until it’s paid in full (or until the day before you asked for a payment plan HMRC then agreed), for up to 2 years: '
                        .'HMRC must charge it by '.$fmt($dateA).'.', $s['sources'][0], array_replace($extra, [
                            'estimate' => true,
                            'assumption' => trim(($extra['assumption'] ?? '').' Each day is counted at '.$rates['annual_pct'].'% ÷ '.$divisor.', as in HMRC’s worked example (the law sets no day count). HMRC charge it once the tax is paid, or from '.$fmt($dateA->subDays(7)).' if it isn’t.'),
                            // Charged once the tax is paid; while it's still growing, from 7 days before Date A at the earliest.
                            'charged_on' => ($accruing || $last === null ? $dateA->subDays(7) : $last)->format('Y-m-d'),
                        ])), $full['pence'], $charged, $with['pence']);
                }
                $atRisk('sch26_'.$a['code'].'daily', 'late payment'.$a['title'].', '.$rates['annual_pct'].'% a year from day 31:', $bOn, $full['pence'] - $charged, 'Charged daily on what was unpaid from day 31.', $s['sources'][0]);

                continue;
            }

            $s = $r['sch56'];
            // T1 is 30 days after the due date; the penalty date is the day after. T2 and T3 end the 5 and 11 months
            // beginning with the penalty date (2 August and 2 February for a 31 January due date).
            $t1 = $due->addDays(30);
            $penaltyDate = $t1->addDay();
            $items = [];
            foreach ([['30d', '30 days late', $t1], ['6m', '6 months late', Months::periodEnd($penaltyDate, 5)], ['12m', '12 months late', Months::periodEnd($penaltyDate, 11)]] as [$suffix, $title, $endOf]) {
                $unpaidThen = $u($at($endOf));
                if ($unpaidThen <= 0) {
                    continue;
                }
                $on = $endOf->addDay();
                // A plan asked for by the end of the trigger date, agreed and kept, holds off the penalty on the amount
                // in the plan, if it arises before the plan ends (Sch 56 para 10; SAM61380).
                $full = self::pct($unpaidThen, 5);
                $with = self::pct($unpaidThen - self::covered($plan, ($plan['from'] ?? null) !== null && $plan['from']->lte($endOf), $on, $unpaidThen), 5);
                $charged = $settle($full, $with, $on);
                $why = '5% of the '.Money::format($unpaidThen).' '.$a['what'].' '.($endOf->lte($asOf) ? 'unpaid on '.$fmt($endOf) : 'still unpaid, if it isn’t paid by '.$fmt($endOf)).'.';
                if ($charged > 0) {
                    $items[] = $noticed(self::penalty('sch56_'.$a['code'].$suffix, 'Late payment'.$a['title'].", {$title}: ".Money::format($charged), $on, $charged, $state($on),
                        $why.($charged < $full ? ' The rest is held off by your payment plan.' : ''), $s['sources'][0], $extra), $full, $charged, $with);
                }
                $atRisk('sch56_'.$a['code'].$suffix, 'late payment'.$a['title'].', '.$title.':', $on, $full - $charged, $why, $s['sources'][1]);
            }
            // HMRC must charge these by the last day of 2 years beginning with T1 (Sch 56 para 12).
            array_push($out, ...self::timeLimited($items, Months::periodEnd($t1, 24), $y, $asOf));
        }

        return array_map($whenCharged, $out);
    }

    /**
     * The Sch 26 rates for a tax year: 'law', the ones in force (SI 2025/589: 2% for 2024-25, 3% from 2025-26), and
     * 'announced', higher ones announced for the year but not made law (4% for 2027-28 on, Budget 2025), or null.
     *
     * @return array{law:array, announced:?array}
     */
    private static function sch26Rates(string $label): array
    {
        $rows = self::rules()['sch26']['rates_by_year'];
        ksort($rows);
        $law = null;
        $latest = null;
        foreach ($rows as $year => $row) {
            if ($year > $label && $law !== null) {
                break;
            }
            $latest = $row;
            if (empty($row['announced'])) {
                $law = $row;
            }
        }
        $law ??= reset($rows);

        return ['law' => $law, 'announced' => ! empty($latest['announced']) ? $latest : null];
    }

    /** What's been paid towards a year's tax (its payments on account and balancing payment) by the end of a day. */
    private static function paidTowards(string $label, array $ledger): \Closure
    {
        $paid = [];
        foreach ($ledger as $o) {
            if ($o['year'] === $label && in_array($o['kind'], ['poa1', 'poa2', 'balancing'], true)) {
                array_push($paid, ...$o['paid']);
            }
        }

        return fn (CarbonImmutable $d) => array_sum(array_map(fn ($p) => $p['date']->lte($d) ? $p['amount'] : 0, $paid));
    }

    /**
     * The amounts late payment penalties run on, each from its own due date: the year's tax and, for a return you've
     * amended, the extra tax from the amendment. That's due on the later of the normal date and 30 days after HMRC get
     * the amendment (TMA 1970 s59B(5), Sch 3ZA), so it's penalised from then (Sch 56 item 19; Sch 26 item 3), while
     * interest on it still runs from 31 January (Sch 53 para 3). A lower figure just lowers the penalties.
     *
     * @return list<array{code:string, title:string, what:string, due:CarbonImmutable, unpaid:\Closure, extra:array}>
     */
    private static function amountsDue(array $y, CarbonImmutable $asOf, \Closure $unpaid): array
    {
        $due = $y['balancing_due'];
        $extra = match ($y['dates']['balancing_basis']) {
            'assumed' => ['estimate' => true, 'assumption' => 'Assumes the tax was due on '.$due->format('j F Y').', because you hadn’t told HMRC by 5 October that you needed to file.'],
            'estimate' => ['estimate' => true, 'assumption' => 'The tax is taken as due on '.$due->format('j F Y').': you told HMRC in time but their notice to file hasn’t come, and the tax is due on 31 January if it comes by 31 October, otherwise 3 months after it.'],
            default => [],
        };
        $increase = $y['amending'] && $y['original_liability'] !== null ? max(0, $y['liability'] - $y['original_liability']) : 0;
        $out = [['code' => '', 'title' => '', 'what' => 'of the tax for the year', 'due' => $due, 'unpaid' => fn (CarbonImmutable $d) => max(0, $unpaid($d) - $increase), 'extra' => $extra]];
        if ($increase > 0) {
            // Payments go to the original tax first, then to the extra tax.
            $sent = $y['amended_on'] ?? $asOf;
            $incDue = $sent->addDays(30)->max($due);
            $assumed = $y['amended_on'] === null ? 'Assumes you send the amended return today ('.$asOf->format('j F Y').'): the extra tax is due 30 days after HMRC get it. ' : '';
            $out[] = ['code' => 'inc_', 'title' => ' on the extra tax from your amendment', 'what' => 'of the extra tax from your amendment', 'due' => $incDue,
                'unpaid' => fn (CarbonImmutable $d) => min($increase, $unpaid($d)),
                'extra' => $assumed || $extra ? ['estimate' => true, 'assumption' => trim($assumed.($extra['assumption'] ?? ''))] : []];
        }

        return $out;
    }

    /**
     * Your payment plan as the late payment rules see it. What counts is the day you ASKED HMRC (FA 2009 Sch 56 para
     * 10; FA 2021 Sch 26 paras 6 and 8), if HMRC then agreed it. It holds off the penalties arising from then to the end
     * of the deferral period (the plan's last payment; taken as until the tax is paid if you haven't said). Broken (a
     * missed payment, or the plan ending with tax unpaid), it still holds them off until HMRC send a notice charging
     * them (Sch 56 para 10(3); Sch 26 paras 7 and 9): till then they're at risk. HMRC won't normally agree a Self
     * Assessment plan while the return is overdue (DMBM803040), so a request made then that you haven't said they
     * agreed counts from the day the return was filed, or not at all while it isn't. State: protected (agreed and
     * kept), pending (not agreed yet), at_risk (broken: HMRC can charge what it held off by notice), charged (HMRC did),
     * or null (it holds nothing off).
     */
    private static function planFacts(string $label, array $y, CarbonImmutable $asOf, \Closure $unpaid): ?array
    {
        $p = $y['plan'];
        if ($p['requested_on'] === null) {
            return null;
        }
        $fmt = fn (CarbonImmutable $d) => $d->format('j F Y');
        $notes = [];
        $warnings = [];
        $from = $p['requested_on'];
        $overdue = $y['return_needed'] && $y['deadline'] !== null && $from->gt($y['deadline'])
            && (! $y['filed'] || ($y['filed_on'] !== null && $y['filed_on']->gt($from)));
        if ($overdue && $p['agreed'] === true) {
            // Your own record beats the default: HMRC agreed it, so it counts from the day you asked.
            $notes[] = 'HMRC don’t normally agree a payment plan while a return is overdue, and the return for '.$label.' was when you asked ('.$fmt($p['requested_on'])
                .'). You’ve said they agreed this one, so it’s taken as holding penalties off from then: check the date on HMRC’s letter.';
        } elseif ($overdue) {
            if ($y['filed'] && $y['filed_on'] !== null) {
                $from = $y['filed_on'];
                $notes[] = 'HMRC don’t agree a payment plan while a return is overdue, and the return for '.$label.' was when you asked ('.$fmt($p['requested_on']).'), so the plan is taken as asked for on the day it was filed ('.$fmt($from).').';
            } else {
                $from = null;
                $warnings[] = 'HMRC won’t normally agree a payment plan while the return for '.$label.' is overdue, so the plan holds off no late payment penalties. File the return, then ask HMRC again.';
            }
        }
        $noticeOn = $p['notice_on'] !== null && $p['notice_on']->lte($asOf) ? $p['notice_on'] : null;
        $broken = $p['broken_on'] !== null && $p['broken_on']->lte($asOf) ? $p['broken_on'] : null;
        if ($broken === null && $p['agreed'] === true && $p['ends_on'] !== null && $p['ends_on']->lt($asOf) && $unpaid($p['ends_on']) > 0) {
            $broken = $p['ends_on']->addDay();
            $notes[] = 'The plan ended on '.$fmt($p['ends_on']).' with '.Money::format($unpaid($p['ends_on'])).' still unpaid, which breaks it.';
        }
        $broken ??= $noticeOn;
        $state = $from === null || $p['agreed'] === false ? null
            : ($p['agreed'] !== true ? 'pending' : ($broken === null ? 'protected' : ($noticeOn !== null ? 'charged' : 'at_risk')));
        // Penalties arising up to the end of the deferral period are held off: the plan's last payment, or, once HMRC
        // have charged what it held off, the day before their notice.
        $deferralEnd = $p['ends_on'];
        if ($state === 'charged') {
            $deferralEnd = $deferralEnd === null ? $noticeOn->subDay() : $deferralEnd->min($noticeOn->subDay());
        }
        if ($p['agreed'] === false) {
            $notes[] = 'You’ve said HMRC didn’t agree the plan, so it holds off no penalties.';
        } elseif ($state === 'pending') {
            $notes[] = 'Not agreed yet (or you haven’t said): penalties are shown in full. If HMRC agree the plan and you keep to it, the ones arising from '.$fmt($from).' on are cancelled.';
        } elseif ($state === 'at_risk') {
            $notes[] = 'The plan was broken on '.$fmt($broken).'. It still holds off the penalties arising '.($p['ends_on'] ? 'up to '.$fmt($p['ends_on']) : 'until the tax is paid')
                .' unless HMRC send a notice charging them, so they’re shown as at risk, not due. If HMRC send one, record its date on this page.';
        } elseif ($state === 'charged') {
            $notes[] = 'HMRC sent a notice on '.$fmt($noticeOn).' charging the late payment penalties the plan held off, so they’re charged in full, with interest from 30 days after the notice. Penalties arising after that aren’t held off.';
        }
        if ($state === 'protected' && $p['ends_on'] === null && ! $p['what_if']) {
            $notes[] = 'Assumes the plan runs until the tax is paid off. Add the date of the last payment if you know it.';
        }
        if ($state !== null && $p['amount'] !== null) {
            $notes[] = 'The plan covers '.Money::format($p['amount']).'; late payment penalties on anything else unpaid are charged as normal.';
        }

        return ['requested_on' => $p['requested_on'], 'from' => $from, 'agreed' => $p['agreed'], 'ends_on' => $p['ends_on'], 'broken_on' => $broken,
            'notice_on' => $noticeOn, 'deferral_end' => $deferralEnd,
            'amount' => $p['amount'], 'state' => $state, 'what_if' => $p['what_if'], 'notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * How much of $unpaid a plan covers for a penalty arising on $on, if the request was in time ($inTime): penalties
     * arising up to the end of the deferral period, broken or not (a breach only lets HMRC charge them by notice).
     */
    private static function covered(?array $plan, bool $inTime, CarbonImmutable $on, int $unpaid): int
    {
        if ($plan === null || $plan['state'] === null || ! $inTime) {
            return 0;
        }
        if ($plan['deferral_end'] !== null && $on->gt($plan['deferral_end'])) {
            return 0;
        }

        return $plan['amount'] === null ? $unpaid : min($unpaid, max(0, $plan['amount']));
    }

    /**
     * A yearly-rate penalty charged daily (FA 2021 Sch 26 para 8): each day from $from to $to (inclusive) at the
     * balance unpaid at the start of that day, less anything $covers says a payment plan covers that day. Days with
     * the same balance form a segment of balance × rate × days ÷ $divisor, rounded to the penny.
     *
     * @param  \Closure(CarbonImmutable):int  $unpaid  unpaid at the end of a day
     * @param  (\Closure(CarbonImmutable, int):int)|null  $covers
     * @return array{pence:int, last_day:?CarbonImmutable} last_day: the last day anything was charged
     */
    public static function yearlyRatePenalty(\Closure $unpaid, CarbonImmutable $from, CarbonImmutable $to, string $pct, int $divisor = 365, ?\Closure $covers = null): array
    {
        $total = 0;
        $run = [0, 0]; // [balance, days]
        $last = null;
        $flush = function () use (&$run, &$total, $pct, $divisor) {
            if ($run[1] > 0 && $run[0] > 0) {
                $total += BigDecimal::of($run[0])->multipliedBy($pct)->multipliedBy($run[1])->dividedBy(100 * $divisor, 0, RoundingMode::HALF_UP)->toInt();
            }
            $run = [0, 0];
        };
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $balance = $unpaid($d->subDay());
            if ($balance <= 0) {
                break;
            }
            $charged = $balance - ($covers ? min($balance, $covers($d, $balance)) : 0);
            if ($charged !== $run[0]) {
                $flush();
                $run[0] = $charged;
            }
            $run[1]++;
            if ($charged > 0) {
                $last = $d;
            }
        }
        $flush();

        return ['pence' => $total, 'last_day' => $last];
    }

    /**
     * Assessment time limits (Sch 55 para 19, Sch 56 para 12): HMRC must charge a penalty by the later of Date A and
     * Date B, 12 months from when the tax is known. A penalty HMRC charged in time stays payable; one they didn't
     * waits, for an unfiled return, until the tax is known (normally when you file).
     */
    private static function timeLimited(array $items, CarbonImmutable $dateA, array $y, CarbonImmutable $asOf): array
    {
        $fmt = fn (CarbonImmutable $d) => $d->format('j F Y');
        foreach ($items as &$p) {
            $p['date_a'] = $dateA->format('Y-m-d');
            if (in_array($p['status'], ['upcoming', 'at_risk'], true)) {
                continue;
            }
            if (! $y['filed']) {
                if ($asOf->gt($dateA)) {
                    $p['time_limit'] = 'Only if HMRC charged it by '.$fmt($dateA).'. If they didn’t, '.($asOf->gt($y['dates']['own_return_last'])
                        ? 'they can only charge it once they assess the tax for the year, within 12 months of that.'
                        : 'it can be charged for 12 months after you file.');
                }
            } elseif ($y['filed_on']) {
                // Date B: 12 months from when the tax was known, taken as the filing date plus HMRC's 30 days (CH64200).
                $dateB = Months::periodEnd($y['filed_on']->addDays(30), 12);
                $limit = $dateB->gt($dateA) ? $dateB : $dateA;
                if ($asOf->gt($limit)) {
                    $p['time_limit'] = 'Only if HMRC charged it by about '.$fmt($limit).'; after that it can no longer be charged.';
                }
            }
        }
        unset($p);

        return $items;
    }

    /**
     * One penalty. status: due, accruing (still growing), upcoming (if nothing changes), or at_risk (held off by a
     * payment plan that was broken: HMRC can charge it by notice). charged_on: when HMRC are taken to charge it, if
     * not on its date; interest runs from 30 days after. awaits_return: a late payment penalty that can't be charged
     * until the return is in (or HMRC assess the tax), so no interest on it yet. amount_unknown: the tax it's a
     * percentage of isn't worked out (Scottish income tax, or no rates loaded), so amount_pence isn't the figure.
     */
    private static function penalty(string $code, string $label, CarbonImmutable $date, int $amount, string $status, string $why, string $source, array $extra = []): array
    {
        return array_replace(['code' => $code, 'label' => $label, 'date' => $date->format('Y-m-d'), 'amount_pence' => $amount, 'status' => $status, 'why' => $why, 'source' => $source,
            'estimate' => false, 'assumption' => null, 'on_filing_pence' => 0, 'range' => null, 'time_limit' => null, 'date_a' => null, 'charged_on' => null,
            'awaits_return' => false, 'amount_unknown' => false], $extra);
    }

    // ---------------------------------------------------------------- interest

    /**
     * Interest on an amount due, from its due date to the end of $asOf (or when paid), in segments rounded down to the
     * penny as HMRC's published example does (LatePaymentInterest).
     *
     * @return array{pence:int, days:int, segments:list<array>}
     */
    private static function interest(array $o, CarbonImmutable $asOf): array
    {
        $principal = min($o['amount'], $o['interest_cap'] ?? $o['amount']);

        return LatePaymentInterest::calculate($principal, $o['interest_from'] ?? $o['due'], $asOf, $o['paid'], self::rules()['interest']['late_payment_rates']);
    }

    /** About how much interest the next day adds (for "interest is adding about £X a day"). */
    private static function dailyRate(array $o, CarbonImmutable $asOf): int
    {
        if (($o['interest_from'] ?? $o['due'])->gte($asOf)) {
            return 0;
        }
        $balance = min($o['interest_cap'] ?? $o['amount'], self::outstandingOn($o, $asOf));
        $tomorrow = $asOf->addDay();

        return (int) BigDecimal::of($balance)->multipliedBy(self::rateOn($tomorrow))->dividedBy(100 * LatePaymentInterest::divisor($tomorrow), 0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * How the interest is worked out, and the convention it rests on. No law fixes the year length or the rounding;
     * this follows HMRC's published example (penalties.json interest.method), so the figure is an estimate.
     */
    public static function interestNote(?CarbonImmutable $asOf = null): string
    {
        $asOf ??= CarbonImmutable::today();
        $known = self::ratesKnownTo();

        return 'Interest is simple interest at HMRC’s rate for each day, from the day after each amount was due up to and including the day it’s paid (or today). '
            .'It’s worked out the way HMRC’s published example is: the interest for each run of days at one balance and rate is worked out on a 365-day year (366 in a leap year) and rounded down to the penny. '
            .'No law fixes that method and HMRC’s own guidance isn’t consistent, so it’s an estimate that can differ from HMRC’s statement by a penny or so for each run.'
            .($known && $asOf->gt($known) ? ' Rates after '.$known->format('j F Y').' aren’t published yet, so the last known rate ('.self::rateOn($known).'%) is used.' : '');
    }

    /** The last day the published rate table is known to cover (penalties.json interest.rates_known_to). */
    private static function ratesKnownTo(): ?CarbonImmutable
    {
        $raw = (string) (self::rules()['interest']['rates_known_to'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) ? CarbonImmutable::parse($m[0]) : null;
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

    /**
     * The next late payment penalties coming if the tax isn't paid: every one arising on the first date (so the figure
     * is what paying before then avoids), or null.
     *
     * @return array{date:string, pence:int, count:int, labels:list<string>}|null
     */
    public static function nextLatePayment(array $upcoming): ?array
    {
        $late = array_values(array_filter($upcoming, fn ($p) => str_starts_with($p['code'], 'sch56') || str_starts_with($p['code'], 'sch26')));
        if ($late === []) {
            return null;
        }
        usort($late, fn ($a, $b) => $a['date'] <=> $b['date']);
        $same = array_values(array_filter($late, fn ($p) => $p['date'] === $late[0]['date']));

        return ['date' => $late[0]['date'], 'pence' => array_sum(array_column($same, 'amount_pence')), 'count' => count($same), 'labels' => array_column($same, 'label')];
    }

    /** @param  array{protected:int, pending:int, at_risk:int, plan:?array}  $tally  what the payment plan does (paymentPenalties) */
    private static function present(string $label, array $y, array $penalties, ?array $announced, array $tally, array $obligations, int $interest, int $perDay, CarbonImmutable $asOf, array $years = []): array
    {
        $split = function (array $items): array {
            $due = array_values(array_filter($items, fn ($p) => in_array($p['status'], ['due', 'accruing'], true)));
            $upcoming = array_values(array_filter($items, fn ($p) => $p['status'] === 'upcoming'));
            $atRisk = array_values(array_filter($items, fn ($p) => $p['status'] === 'at_risk'));
            usort($upcoming, fn ($a, $b) => $a['date'] <=> $b['date']);

            return [$due, $upcoming, $atRisk];
        };
        [$due, $upcoming, $atRisk] = $split($penalties);
        $plan = $tally['plan'] ?? null;
        $unpaid = array_sum(array_map(fn ($o) => $o['outstanding_pence'], $obligations));
        $d = $y['dates'];
        $fmt = fn (?CarbonImmutable $x) => $x?->format('j F Y');
        $start = UkTaxYear::fromLabel($label)->startYear;
        $newRules = $y['regime'] === PenaltyRegime::LAW;
        $submissions = self::submissionsView($y, $asOf);
        $points = self::pointsSummary($y['points_ledger'] ?? null, $years, $asOf, $y['regime'] === PenaltyRegime::ANNOUNCED);
        $annual = collect($submissions)->firstWhere('kind', 'annual');
        $tooLate = $y['return_needed'] && ! $y['filed'] && $asOf->gt($d['own_return_last']);
        // No notice to file and you needed one: tell HMRC (unless you've said you did, by 5 October).
        $notifyHmrc = $y['notice'] === 'not_given' && $y['chargeable'] && $d['missed_notify'];
        $toldNoNotice = $y['notice'] === 'not_given' && $y['chargeable'] && ! $d['missed_notify'];
        $determined = $d['own_return_basis'] === 'determination';

        $actions = [];
        if ($toldNoNotice) {
            $actions[] = 'You told HMRC by '.$fmt($d['notify_by']).' that you need to file for '.$label.', so there’s no penalty for telling them late. HMRC haven’t sent a notice to file yet: '
                .'when it comes, the return is due 3 months after it (or by 31 January '.($start + 2).' online, if that’s later), and the tax by '.$fmt($y['balancing_due'])
                .($d['balancing_basis'] === 'estimate' && $asOf->gt(CarbonImmutable::create($start + 1, 10, 31)) ? ' at the earliest (3 months after the notice)' : '').'. If it hasn’t come, ask HMRC about it.';
        } elseif ($notifyHmrc) {
            $measuredOn = CarbonImmutable::create($start + 2, 1, 31);
            $caseAUntil = CarbonImmutable::create($start + 3, 1, 31);
            $actions[] = $asOf->lte($d['notify_by'])
                ? 'Tell HMRC by '.$fmt($d['notify_by']).' that you need to file a return for '.$label.' (register for Self Assessment): your self-employed income for the year is over £1,000.'
                : 'Tell HMRC now that you need to file a return for '.$label.': register for Self Assessment. '.($asOf->lte($measuredOn)
                    ? 'Pay the year’s tax by '.$fmt($measuredOn).' and there’s no penalty for telling them late.'
                    : ($asOf->lte($caseAUntil)
                        ? 'Telling them yourself, before they contact you, by '.$fmt($caseAUntil).' can bring the penalty down to nothing.'
                        : 'Telling them yourself, before they contact you, can bring the penalty down to as little as 10% of the tax that was unpaid on '.$fmt($measuredOn).' (it can be up to 30%; HMRC decide).'));
        } elseif ($tooLate && $determined) {
            // s28C(5): the window to replace HMRC's determination has closed, so it stands as the tax for the year.
            $actions[] = 'HMRC made a determination of the tax for '.$label.' on '.$fmt($d['determination_on']).'. The time to replace it with your own return ended on '.$fmt($d['own_return_last'])
                .', so the determination stands as the tax for the year. The only way to change it now is a claim for special relief (TMA 1970 Schedule 1AB paragraph 3A), which has strict conditions: get advice from an accountant.';
        } elseif ($tooLate) {
            $actions[] = 'The time limit for filing your own return for '.$label.' ended on '.$fmt($d['own_return_last']).', so it can no longer be filed in the usual way. '
                .'HMRC can still assess the tax (for up to 6 years after the year if the tax was lost through carelessness, 20 if deliberately). Get advice from an accountant before you contact HMRC.';
        } elseif ($y['return_needed'] && ! $y['filed'] && $y['deadline']) {
            if ($asOf->lte($y['deadline'])) {
                $actions[] = 'File the return by '.$fmt($y['deadline']).($newRules
                    ? ' to avoid '.(($annual['penalty_pence'] ?? 0) > 0 ? ($annual['point'] ? 'a late submission point and a £200 penalty (it would take you to '.$points['threshold'].' points).' : 'a £200 penalty (you’d be at '.$points['threshold'].' points).') : 'a late submission point.')
                    : ' to avoid the £100 late filing penalty.');
            } elseif ($newRules) {
                $held = $points['held'] ?? 0;
                $threshold = $points['threshold'] ?? 0;
                $actions[] = 'File the return as soon as you can. '.(($annual['point'] ?? false) || ($annual['penalty_pence'] ?? 0) === 0
                    ? 'Being late has earned a late submission point (you have '.$held.' of '.$threshold.' now); at '.$threshold.' points each late submission costs £200.'
                    : 'You were at '.$threshold.' points, so being late has cost £200, and each later late submission costs another £200.')
                    .($held > 0 ? ' Until it’s filed your points can’t all go: everything due in the last 24 months has to be sent.' : '');
            } else {
                $next = array_values(array_filter($upcoming, fn ($p) => str_starts_with($p['code'], 'sch55')))[0] ?? null;
                $on = $next ? CarbonImmutable::parse($next['date']) : null;
                $actions[] = 'File the return as soon as you can'.match ($next['code'] ?? null) {
                    'sch55_daily_more' => $asOf->lt($on)
                        ? ': from '.$fmt($on).' HMRC can add £10 a day, up to £900.'
                        : ': £10 is being added every day until HMRC get it (up to '.Money::format($next['amount_pence']).' more).',
                    'sch55_6m', 'sch55_12m' => ': if it isn’t in by the end of '.$fmt($on->subDay()).', a '.($next['code'] === 'sch55_6m' ? 'six' : 'twelve').'-month penalty of '
                        .Money::format($next['amount_pence'] + $next['on_filing_pence']).' follows (5% of the tax, at least £300).',
                    default => '. You can only file it yourself until '.$fmt($d['own_return_last']).($determined ? ', the last day it can replace HMRC’s determination' : '').'.',
                };
            }
            // A determination (HMRC's own figure for a missing return) can only be replaced within its time limit, which
            // is then the last day for your own return (s28C(5); s34A(2)(b)).
            if ($determined) {
                $actions[] = 'HMRC made a determination of the tax on '.$fmt($d['determination_on']).'. Your return replaces it only if it reaches them by '.$fmt($d['determination_last'])
                    .'; after that the determination stands as the tax for the year.';
            } elseif ($d['determination_last'] && $asOf->gt($y['deadline'])) {
                $actions[] = $asOf->lte($d['determination_last'])
                    ? 'If HMRC sent you a determination (their own estimate of the tax, made because no return arrived), your return must reach them by '.$fmt($d['determination_last'])
                        .' to replace it, or within 12 months of the determination if that’s later.'
                    : 'If HMRC sent you a determination (their own estimate of the tax), your return replaces it only if it reaches them within 12 months of the date on the determination; '
                        .'otherwise the determination stands. Get advice if you have one.';
            }
        }
        // Quarterly updates past their due date with no "sent on" date.
        $unsent = array_values(array_filter($submissions, fn ($x) => $x['kind'] === 'quarterly' && $x['status'] === 'not_sent'));
        if ($unsent) {
            $actions[] = 'Send '.implode(' and ', array_map(fn ($x) => $x['what'].' (due '.$fmt(CarbonImmutable::parse($x['due_on'])).')', $unsent))
                .' as soon as you can, then record the date'.(count($unsent) > 1 ? 's' : '').' you sent '.(count($unsent) > 1 ? 'them' : 'it').' on this page. Already sent? Just record the date.';
        }
        // A broken plan first: what it held off can now be charged.
        if ($plan && $plan['state'] === 'at_risk' && $tally['at_risk'] + $tally['at_risk_later'] > 0) {
            array_unshift($actions, 'Your payment plan for '.$label.' was broken on '.$fmt($plan['broken_on']).', so HMRC can '
                .($tally['at_risk'] > 0 ? 'now charge the '.Money::format($tally['at_risk']).' of late payment penalties it held off' : 'charge the late payment penalties it holds off')
                .($tally['at_risk_later'] > 0 ? ($tally['at_risk'] > 0 ? ', and ' : ' (').Money::format($tally['at_risk_later']).' more that would arise later while it’s in place'.($tally['at_risk'] > 0 ? '' : ')') : '')
                .', by sending you a notice. Call HMRC (0300 123 1813) straight away: if they agree to change the plan, it keeps holding them off.');
        }
        foreach ($plan['warnings'] ?? [] as $w) {
            $actions[] = $w;
        }
        if ($unpaid > 0) {
            // Every late payment penalty arising on the next date (A and B fall together in a first year under the new
            // rules; extra tax from an amendment can fall due with the rest).
            $next = self::nextLatePayment($upcoming);
            $actions[] = 'Pay the '.Money::format($unpaid).' owed'.($next ? ' before '.CarbonImmutable::parse($next['date'])->format('j F Y').' to avoid '
                .($next['count'] > 1 ? Money::format($next['pence']).' of late payment penalties' : 'a '.Money::format($next['pence']).' late payment penalty') : '')
                .($perDay > 0 ? '; interest is adding about '.Money::format($perDay).' a day' : '').'.';
            if ($plan && $plan['state'] === 'charged' && $tally['charged'] > 0) {
                $actions[] = 'HMRC charged the '.Money::format($tally['charged']).' of late payment penalties your broken payment plan held off: pay them within 30 days of their notice, or interest is added.';
            }
            if ($plan && $plan['state'] === 'protected') {
                $actions[] = 'Keep to your payment plan: while you do, late payment penalties on what it covers aren’t charged'.($tally['protected'] > 0 ? ' ('.Money::format($tally['protected']).' held off so far)' : '').'. Interest still runs until it’s paid.';
            } elseif ($plan && $plan['state'] === 'pending') {
                $actions[] = 'You asked HMRC for a payment plan on '.$fmt($plan['requested_on']).'. Once they agree it, say so here'
                    .($tally['pending'] > 0 ? ': if you keep to it, '.Money::format($tally['pending']).' of the late payment penalties above are cancelled.' : '.');
            } elseif (! $plan || $plan['state'] === null) {
                $actions[] = 'Can’t pay it all? You can set up a payment plan online if you owe £30,000 or less, your returns are filed (including this one), you have no other tax debts or HMRC payment plans, '
                    .'you can set up a Direct Debit from a UK bank account in your name, and you can pay within 12 months; otherwise call HMRC. '
                    .'What counts is the day you ask: asking before a late payment penalty date, then keeping to the plan HMRC agree, stops the penalties that would follow. Interest still runs.';
            }
        }
        if ($due) {
            $actions[] = 'If something outside your control stopped you (serious illness, a bereavement, an HMRC system problem), you can appeal within 30 days of the penalty notice: that’s a “reasonable excuse”.';
        }

        $assumptions = ['An estimate from your records: HMRC’s statement is the final word.',
            'Payments to HMRC in your statements are matched to the oldest amount due first, as HMRC usually does.'];
        $assumptions[] = match ($y['notice']) {
            'assumed' => 'Assumes HMRC sent you a notice to file by 31 July '.($start + 1).', as they do for anyone registered for Self Assessment. If they didn’t, or sent it later, say so on this page.',
            'not_given' => 'You’ve said HMRC haven’t sent a notice to file for '.$label.', so there’s no late filing penalty. If you needed to tell HMRC you had to file and didn’t by '.$fmt($d['notify_by']).', a penalty for telling them late applies instead.',
            default => 'HMRC sent you a notice to file'.($d['notice_date'] ? ' on '.$fmt($d['notice_date']) : '').'.',
        };
        array_push($assumptions, ...$d['notes'], ...$y['poa']['notes'], ...($plan['notes'] ?? []));
        $amendment = null;
        if ($y['amending']) {
            $increase = $y['original_liability'] !== null ? max(0, $y['liability'] - $y['original_liability']) : null;
            $incDue = $increase ? ($y['amended_on'] ?? $asOf)->addDays(30)->max($y['balancing_due']) : null;
            $assumptions[] = $y['original_liability'] === null
                ? 'You’re amending this return. Late payment penalties are worked out as if all the tax was on the original return; add the tax on the original return to have any extra tax penalised from its own, later date.'
                : ($increase > 0
                    ? 'Your amendment adds '.Money::format($increase).' of tax. That’s due on '.$fmt($incDue).(($y['amended_on'] ?? $asOf)->addDays(30)->gt($y['balancing_due'])
                        ? ($y['amended_on'] ? ' (30 days after HMRC got the amendment, '.$fmt($y['amended_on']).')' : ' if you send it today (30 days after HMRC get it)')
                        : ', the normal date, because that’s later than 30 days after HMRC '.($y['amended_on'] ? 'got the amendment ('.$fmt($y['amended_on']).')' : 'get the amendment, if you send it today'))
                        .', and late payment penalties on it run from then; interest on it runs from '.$fmt($y['balancing_due']).', as if it had been on the original return.'
                    : 'Your amendment doesn’t add tax, so late payment penalties are on the lower figure.');
            $amendment = ['amended_on' => $y['amended_on']?->format('Y-m-d'), 'original_liability_pence' => $y['original_liability'],
                'increase_pence' => $increase, 'increase_due' => $incDue?->format('Y-m-d'), 'assumed_sent_today' => $increase > 0 && $y['amended_on'] === null];
        }
        if ($y['deadline'] && $d['filing_basis'] === 'notice') {
            $assumptions[] = 'The deadline ('.$fmt($y['deadline']).') is the one printed on HMRC’s notice.';
        } elseif ($y['deadline'] && $d['filing_basis'] === 'deferred') {
            $assumptions[] = 'HMRC deferred the deadline to '.$fmt($y['deadline']).'.';
        }
        if ($y['return_needed']) {
            $assumptions[] = $y['record']['filed_method'] === 'paper' && $y['filed']
                ? 'Filed on paper, so the paper deadline ('.$fmt($d['paper']).') applies.'
                : 'Assumes the return is (or will be) filed online. A paper return has an earlier deadline'.($d['paper'] ? ' ('.$fmt($d['paper']).')' : '').'.';
        }
        if ($obligations !== [] && $interest > 0) {
            $assumptions[] = self::interestNote($asOf);
        }
        $assumptions[] = 'Assumes each penalty is charged on the day it arises, as HMRC normally do, except: daily late filing penalties at day 90 or when the return arrives; '
            .'a late payment penalty only once there’s a figure for the tax (your return, or HMRC’s determination), so not before the return is filed; and the new rules’ first late payment penalty after day 30. '
            .'Interest on a penalty runs from 30 days after it’s charged, as HMRC work it out (read literally, the law would start it a day earlier).';
        if (! $y['estimable']) {
            $assumptions[] = self::unestimableText($label, $y).'. That doesn’t mean it’s nil: penalties that are a percentage of it, late payment penalties and interest aren’t worked out for this year.';
        }
        $assumptions[] = 'The tax is worked out from '.$y['basis'].'; other income (a job, savings, dividends) isn’t included.';
        if ($y['class2'] > 0) {
            // Compulsory Class 2 (to 2023-24) is in the balancing payment, so in the penalties and interest on it.
            $assumptions[] = 'The tax includes '.Money::format($y['class2']).' of Class 2 National Insurance for '.$y['class2_weeks']['weeks'].' weeks'
                .($y['class2_weeks']['set'] ? ', as set in Settings.' : ', a full year. If you weren’t self-employed all year, or reached State Pension age, set the weeks in Settings.')
                // The rule applying interest and penalties to Class 2 (SSCBA 1992 s11A) was repealed from 6 April 2024.
                .($label === '2023-24' ? ' Interest and penalties on 2023-24 Class 2 are worked out as for earlier years: the rule applying them was repealed from 6 April 2024, so this rests on the general saving for repealed law and on HMRC practice.' : '');
        }
        if ($newRules && $label === $y['first_new_rules_year']) {
            $assumptions[] = 'Your first year under the new penalty rules: HMRC don’t charge a late payment penalty if, within 30 days of the due date, you pay in full or contact them and agree a payment plan you keep to. That’s HMRC practice, given once, not law (the law allows 15 days).';
        }
        if ($points && ! $points['announced']) {
            array_push($assumptions, ...$points['assumptions']);
        }
        if ($y['regime'] === PenaltyRegime::ANNOUNCED) {
            $assumptions[] = 'New penalty rules (points for late returns and new late payment penalties) are announced for everyone from 2027-28, but they aren’t law yet: the figures above use the rules that are law. What the new rules would cost is shown separately, as an estimate.';
        }
        if ($label >= self::rules()['regimes']['new_regime_from']['mtd'] && PenaltyRegime::volunteerFrom() === null) {
            $assumptions[] = PenaltyRegime::exemptionText()
                ?? 'Worked out from your income in Expend (property income isn’t counted) that you’re '.($y['mtd'] ? '' : 'not ').'in Making Tax Digital for '.$label
                    .'. If HMRC told you otherwise, or you’re exempt or an accepted volunteer, say so in Settings.';
        }

        $alt = null;
        if ($announced !== null) {
            [$altDue, $altUpcoming, $altRisk] = $split($announced['items']);
            $alt = ['regime_text' => $announced['text'], 'penalties' => $altDue, 'upcoming' => $altUpcoming, 'at_risk' => $altRisk,
                'penalties_pence' => array_sum(array_column($altDue, 'amount_pence')), 'upcoming_pence' => array_sum(array_column($altUpcoming, 'amount_pence')),
                // Your points if the announced rules apply (an estimate); under the law's rules they're shown above.
                'points' => $points && $points['announced'] ? $points : null];
        }
        $fmtDay = fn (?CarbonImmutable $x) => $x?->format('Y-m-d');

        return [
            'tax_year' => $label,
            'regime' => $y['regime'],
            'regime_text' => PenaltyRegime::text($y['regime'], $label),
            'new_rules' => $newRules,
            'deadline' => $y['deadline']?->format('Y-m-d'),
            'deadline_basis' => $d['filing_basis'],
            'paper_deadline' => $d['paper']?->format('Y-m-d'),
            'balancing_due' => $y['balancing_due']->format('Y-m-d'),
            // law; assumed (late notice, not said whether you told HMRC by 5 October); estimate (told HMRC, no notice yet).
            'balancing_basis' => $d['balancing_basis'],
            'notify_by' => $d['notify_by']->format('Y-m-d'),
            'own_return_last_date' => $d['own_return_last']->format('Y-m-d'),
            'determination_last_date' => $d['determination_last']?->format('Y-m-d'),
            'filed' => $y['filed'], 'filed_on' => $y['filed_on']?->format('Y-m-d'), 'return_needed' => $y['return_needed'], 'mtd' => $y['mtd'],
            'chargeable' => $y['chargeable'], 'notify_hmrc' => $notifyHmrc, 'too_late_to_file' => $tooLate,
            'notice' => $y['record'] + ['assumed' => $y['notice'] === 'assumed'],
            'status' => $y['status'],
            'liability_pence' => $y['liability'], 'basis' => $y['basis'], 'estimable' => $y['estimable'],
            // Why the tax isn't worked out (scottish, no_rates), so it's unknown rather than nil.
            'unestimable' => $y['unestimable'],
            'told_no_notice' => $toldNoNotice,
            // What you've told Expend about a payment plan (for the form), and what it does to the penalties.
            'plan_facts' => ['plan_requested_on' => $fmtDay($y['plan']['what_if'] ? null : $y['plan']['requested_on']), 'plan_agreed' => $y['plan']['what_if'] ? null : $y['plan']['agreed'],
                'plan_ends_on' => $fmtDay($y['plan']['ends_on']), 'plan_broken_on' => $fmtDay($y['plan']['broken_on']), 'plan_notice_on' => $fmtDay($y['plan']['notice_on']),
                'plan_amount_pence' => $y['plan']['amount']],
            'plan' => $plan ? [
                'requested_on' => $fmtDay($plan['requested_on']), 'counts_from' => $fmtDay($plan['from']), 'agreed' => $plan['agreed'], 'ends_on' => $fmtDay($plan['ends_on']),
                'broken_on' => $fmtDay($plan['broken_on']), 'notice_on' => $fmtDay($plan['notice_on']), 'amount_pence' => $plan['amount'], 'state' => $plan['state'], 'what_if' => $plan['what_if'],
                'protected_pence' => $tally['protected'], 'pending_pence' => $tally['pending'], 'at_risk_pence' => $tally['at_risk'], 'at_risk_later_pence' => $tally['at_risk_later'],
                'charged_pence' => $tally['charged'],
                'notes' => array_merge($plan['warnings'], $plan['notes']),
            ] : null,
            'amendment' => $amendment,
            'determination_on' => $fmtDay($d['determination_on']),
            'obligations' => array_map(fn ($o) => ['kind' => $o['kind'], 'label' => $o['label'], 'due' => $o['due']->format('Y-m-d'),
                // Interest starts here: the due date, or HMRC's later date for tax due under s59B(3) (an estimate).
                'interest_from' => ($o['interest_from'] ?? $o['due'])->format('Y-m-d'), 'amount_pence' => $o['amount'],
                'paid_pence' => $o['amount'] - $o['outstanding_pence'], 'outstanding_pence' => $o['outstanding_pence'], 'interest_pence' => $o['interest_pence'],
                // How the interest is made up: each run of days at one balance, rate and year length, rounded down.
                'interest_segments' => $o['interest_segments'] ?? []], $obligations),
            'penalties' => $due,
            'upcoming' => $upcoming,
            'interest_pence' => $interest,
            'per_day_pence' => $perDay,
            'penalties_pence' => array_sum(array_column($due, 'amount_pence')),
            'upcoming_pence' => array_sum(array_column($upcoming, 'amount_pence')),
            'on_filing_pence' => array_sum(array_column($due, 'on_filing_pence')),
            // Held off by a payment plan that was broken: HMRC can charge these by notice. Not in the totals.
            'at_risk' => $atRisk,
            'at_risk_pence' => array_sum(array_column($atRisk, 'amount_pence')),
            'unpaid_pence' => $unpaid,
            'announced' => $alt,
            // Submissions points are counted on (the return; quarterly updates), and your points under the rules that
            // are law for this year (for a year where they're only announced, they're under 'announced').
            'submissions' => $submissions,
            'points' => $points && ! $points['announced'] ? $points : null,
            'actions' => $actions,
            'rate_pct' => self::rateOn($asOf),
            'assumptions' => $assumptions,
        ];
    }
}
