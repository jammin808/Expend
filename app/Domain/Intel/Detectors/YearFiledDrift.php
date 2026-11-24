<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\ReturnSummary;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;

/**
 * Filed returns your records no longer match (year.filed_drift, M1). For each year filed (or being amended) with its
 * filed figures saved, the return's figures now (ReturnSummary) are compared with them: turnover, allowable expenses
 * and profit. A figure is listed when it's DRIFT_PENCE or more away from anything rounding your records to whole
 * pounds could give (a return takes whole pounds, so each box may be rounded either way), with the date to amend by
 * (12 months after the filing date: FilingDates::amendBy), first on the list within 60 days of it. A return that took
 * the trading allowance instead of expenses is compared the same way.
 *
 * Only a year Expend holds from start to end is compared: one with records in its first month and in its last.
 * The year before you started, whose figures you typed in at setup, holds a few days of statements at most, and a
 * few days set against a whole year's return would be no comparison at all. While payments in the year are still to
 * decide, it says to decide them first rather than to amend.
 *
 * The key carries the difference, so "Not relevant" sets aside the difference you've seen, and a new one comes back.
 * Only reads.
 */
final class YearFiledDrift implements Detector
{
    public const CODE = 'year.filed_drift';

    public const VERSION = 1;

    /**
     * A filed return that differs from your records by this much or more (in pence), beyond what rounding to whole
     * pounds could explain, is listed.
     */
    public const DRIFT_PENCE = 100;

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    /** The filed years the last detect() compared (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $years = TaxYear::query()->whereIn('status', ['filed', 'amending'])
            ->where(fn ($q) => $q->whereNotNull('filed_turnover_pence')->orWhereNotNull('filed_expenses_pence')->orWhereNotNull('filed_profit_pence'))->orderBy('label')->get();
        $pounds = fn (int $pence) => Money::format($pence, 'GBP', false);
        $compared = 0;
        $drafts = [];
        foreach ($years as $year) {
            $label = $year->label;
            if (! self::holdsWholeYear($label)) {
                continue;
            }
            $compared++;
            $s = ReturnSummary::for($label);
            $filed = array_filter(['turnover' => $year->filed_turnover_pence, 'expenses' => $year->filed_expenses_pence, 'profit' => $year->filed_profit_pence], fn ($v) => $v !== null);
            $filed = array_map('intval', $filed);
            // How far each filed figure is from what the records could give, rounded to whole pounds either way.
            $off = fn (array $basis) => array_map(fn ($k) => $filed[$k] < $basis[$k]['low'] ? $basis[$k]['low'] - $filed[$k] : ($filed[$k] > $basis[$k]['high'] ? $filed[$k] - $basis[$k]['high'] : 0),
                array_combine(array_keys($filed), array_keys($filed)));
            // The trading allowance instead of expenses: no expenses, and profit is turnover less the allowance (all
            // of it, or as much as there was turnover). With expenses left blank it could be either, so whichever of
            // the two the return is nearer to is used.
            $allowance = $s['trading_allowance']['allowance_pence'];
            $tookAllowance = isset($filed['turnover'], $filed['profit']) && $filed['turnover'] > $filed['profit'] && min($filed['turnover'], $allowance) === $filed['turnover'] - $filed['profit']
                && ($filed['expenses'] ?? 0) === 0;
            $usual = self::returnFigures($s, false);
            $basis = $usual;
            $onAllowance = false;
            if ($tookAllowance) {
                $withAllowance = self::returnFigures($s, true);
                if (isset($filed['expenses']) || array_sum($off($withAllowance)) < array_sum($off($usual))) {
                    [$basis, $onAllowance] = [$withAllowance, true];
                }
            }
            $now = array_map(fn ($b) => $b['exact'], $basis);
            $diff = [];
            foreach ($off($basis) as $k => $away) {
                if ($away >= self::DRIFT_PENCE) {
                    $diff[$k] = $now[$k] - $filed[$k];
                }
            }
            if ($diff === []) {
                continue;
            }
            $by = FilingDates::amendBy($label, $year);
            $canAmend = today()->lte($by);
            $pending = $s['pending']['unreviewed_count'] + $s['pending']['suggested_count'] + $s['pending']['manual_unreviewed_count'];
            $names = ['turnover' => 'turnover', 'expenses' => 'allowable expenses', 'profit' => 'profit'];
            $figures = fn (array $values) => implode(', ', array_map(fn ($k) => $names[$k].' '.$pounds($values[$k]), array_keys($filed)));
            $profit = $diff['profit'] ?? null;
            $amendText = $canAmend ? 'amend the return by '.$by->format('j F Y').'.' : null;
            $money = $pending === 0 && $profit !== null;
            $drafts[] = new FindingDraft(
                key: 'drift:'.$label.':'.substr(md5(json_encode($diff)), 0, 10), code: self::CODE, group: 'tax',
                priority: $pending === 0 && $canAmend && $by->lte(today()->addDays(60)) ? 1 : 2,
                title: ($pending > 0
                    ? "{$label} figures differ from the return you filed, with {$pending} ".($pending === 1 ? 'payment' : 'payments').' still to decide'
                    : ($profit !== null ? "{$label} profit is ".$pounds(abs($profit)).' '.($profit > 0 ? 'higher' : 'lower').' than the return you filed' : "{$label} figures differ from the return you filed")
                        .($canAmend ? ': amend by '.$by->format('j F Y') : '')),
                detail: implode(' ', array_filter([
                    'Filed: '.$figures($filed).'. Your records now: '.$figures($now).'.',
                    $onAllowance ? 'Worked out with the trading allowance, as your return was.' : null,
                    $pending > 0 ? 'Decide '.($pending === 1 ? 'it' : 'them').' first: the difference may go once '.($pending === 1 ? 'it’s' : 'they’re').' done.'
                        .($amendText ? ' If it’s still there, '.$amendText : '') : null,
                    $pending > 0 || $profit === null ? null : ($profit > 0 ? 'You may owe more tax.' : 'You may have paid too much tax.'),
                    $pending === 0 && $amendText ? 'If your records are right, '.$amendText : null,
                    $canAmend ? null : 'The 12 months to amend the return ended on '.$by->format('j F Y').': ask an accountant how to put it right.',
                    'The year page shows what’s in it now.',
                ])),
                href: '/years/'.$label, subjectRef: 'year:'.$label, scopeRef: 'year:'.$label,
                // With payments still to decide there's no money to it yet, either way: 'none', not unknown.
                amountPence: $money ? -$profit : null, direction: $money ? ($profit > 0 ? 'owed_by_you' : 'claimable') : 'none',
                grade: Grades::forCode(self::CODE)[0], dueOn: $canAmend ? $by : null, detectorVersion: self::VERSION,
            );
        }
        $this->checked = $compared;
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * Whether Expend holds the whole tax year: something confirmed in it (a decision or a trip), and records (statement
     * lines, cash expenses or trips) in its first month and in its last.
     */
    private static function holdsWholeYear(string $label): bool
    {
        if (! Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->exists() && ! MileageTrip::query()->where('tax_year', $label)->exists()) {
            return false;
        }
        $year = UkTaxYear::fromLabel($label);
        $within = fn (CarbonImmutable $from, CarbonImmutable $to) => BankTransaction::query()->where('tax_year', $label)->whereDate('posted_on', '>=', $from->format('Y-m-d'))->whereDate('posted_on', '<=', $to->format('Y-m-d'))->exists()
            || ManualExpense::query()->where('tax_year', $label)->whereDate('spent_on', '>=', $from->format('Y-m-d'))->whereDate('spent_on', '<=', $to->format('Y-m-d'))->exists()
            || MileageTrip::query()->where('tax_year', $label)->whereDate('trip_on', '>=', $from->format('Y-m-d'))->whereDate('trip_on', '<=', $to->format('Y-m-d'))->exists();

        return $within($year->startsOn(), $year->startsOn()->addMonth()->subDay()) && $within($year->endsOn()->subMonth()->addDay(), $year->endsOn());
    }

    /**
     * A return's figures from your records (ReturnSummary::for()): each exactly, and the lowest and highest it could
     * be once each box is rounded to whole pounds (turnover and other income in their own boxes, expenses in one box for
     * each kind, profit from those).
     *
     * @param  bool  $allowance  with the trading allowance instead of expenses
     * @return array{turnover:array{exact:int, low:int, high:int}, expenses:array{exact:int, low:int, high:int}, profit:array{exact:int, low:int, high:int}}
     */
    private static function returnFigures(array $s, bool $allowance): array
    {
        $down = fn (int $p) => (int) (floor($p / 100) * 100);
        $up = fn (int $p) => (int) (ceil($p / 100) * 100);
        $turnover = (int) $s['turnover_pence'];
        $other = (int) $s['other_income_pence'];
        if ($allowance) {
            $less = (int) $s['trading_allowance']['allowance_pence'];
            $expenses = ['exact' => 0, 'low' => 0, 'high' => 0];
            $profit = ['exact' => max(0, $turnover + $other - $less), 'low' => max(0, $down($turnover) + $down($other) - $less), 'high' => max(0, $up($turnover) + $up($other) - $less)];
        } else {
            $boxes = collect($s['expense_lines'])->groupBy(fn ($l) => $l['box'] ?? $l['code'])->map(fn ($ls) => (int) $ls->sum('allowable_pence'));
            $expenses = ['exact' => (int) $s['allowable_expenses_pence'], 'low' => (int) $boxes->sum(fn ($p) => $down($p)), 'high' => (int) $boxes->sum(fn ($p) => $up($p))];
            $profit = ['exact' => (int) $s['net_profit_pence'], 'low' => $down($turnover) + $down($other) - $expenses['high'], 'high' => $up($turnover) + $up($other) - $expenses['low']];
        }

        return ['turnover' => ['exact' => $turnover, 'low' => $down($turnover), 'high' => $up($turnover)], 'expenses' => $expenses, 'profit' => $profit];
    }
}
