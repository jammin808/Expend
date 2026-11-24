<?php

namespace App\Domain\Tax;

use App\Models\TaxSubmission;
use App\Models\TaxYear;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The submissions late submission points are counted on (FA 2021 Sch 24), for the years under the new rules.
 *
 *  - The return for the year ('annual'): its due date is the filing date from HMRC's notice (FilingDates) and it was
 *    sent on the year's filed_on, so nothing is entered twice. Its row only adds whether HMRC accepted an excuse.
 *  - Quarterly updates ('quarterly'), if you're in Making Tax Digital that year (or an accepted volunteer), from
 *    2026-27: 6 April to 5 July, 6 July to 5 October, 6 October to 5 January and 6 January to 5 April, due 7 August,
 *    7 November, 7 February and 7 May (SI 2026/336 regs 12-13). You record the day each was sent; one with no date
 *    is taken as not sent.
 *
 * The rows are made when the year page is shown (sync()); until then the same submissions are worked out here, so
 * the penalties never depend on a row existing.
 */
final class Submissions
{
    /**
     * The standard quarterly update periods of a tax year and their due dates.
     *
     * @return list<array{quarter:int, period_start:CarbonImmutable, period_end:CarbonImmutable, due_on:CarbonImmutable}>
     */
    public static function quarters(string $label): array
    {
        $s = UkTaxYear::fromLabel($label)->startYear;
        $starts = [CarbonImmutable::create($s, 4, 6), CarbonImmutable::create($s, 7, 6), CarbonImmutable::create($s, 10, 6), CarbonImmutable::create($s + 1, 1, 6), CarbonImmutable::create($s + 1, 4, 6)];
        $dues = self::dueDays();
        $out = [];
        for ($q = 0; $q < 4; $q++) {
            $end = $starts[$q + 1]->subDay();
            [$dayNo, $month] = $dues[$q];
            $due = CarbonImmutable::create((int) $end->format('Y'), $month, $dayNo);
            if ($due->lte($end)) {
                $due = $due->addYear();
            }
            $out[] = ['quarter' => $q + 1, 'period_start' => $starts[$q]->startOfDay(), 'period_end' => $end->startOfDay(), 'due_on' => $due->startOfDay()];
        }

        return $out;
    }

    /** "7 August" etc. from penalties.json points.quarterly_update_due, as [day, month]. */
    private static function dueDays(): array
    {
        $out = [];
        foreach (PenaltyEstimator::rules()['points']['quarterly_update_due'] ?? [] as $text) {
            $d = date_parse_from_format('j F', (string) $text);
            if ($d['error_count'] === 0 && $d['day'] && $d['month']) {
                $out[] = [(int) $d['day'], (int) $d['month']];
            }
        }

        return count($out) === 4 ? $out : [[7, 8], [7, 11], [7, 2], [7, 5]];
    }

    /**
     * Whether the year has quarterly updates: 'mandated' (you're in Making Tax Digital), 'volunteer' (an accepted
     * volunteer sends them; HMRC give no points for them), or null. Only from 2026-27, when these deadlines start.
     */
    public static function quarterlyBasis(string $label, ?Closure $summary = null): ?string
    {
        if ($label < (PenaltyEstimator::rules()['regimes']['new_regime_from']['mtd'] ?? '2026-27')) {
            return null;
        }
        if (PenaltyRegime::mandated($label, $summary)) {
            return 'mandated';
        }
        $volunteer = PenaltyRegime::volunteerFrom();

        return $volunteer !== null && $label >= $volunteer ? 'volunteer' : null;
    }

    /**
     * What's recorded, keyed "tax_year|kind|period_start".
     *
     * @return array<string, TaxSubmission>
     */
    public static function stored(): array
    {
        return TaxSubmission::query()->get()->keyBy(fn (TaxSubmission $s) => $s->tax_year.'|'.$s->kind.'|'.$s->period_start->format('Y-m-d'))->all();
    }

    /**
     * The year's submissions under the new rules, from its facts and what's recorded.
     *
     * @param  array{regime:string, deadline:?CarbonImmutable, filed:bool, filed_on:?CarbonImmutable, return_needed:bool, quarterly:?string}  $y
     * @param  array<string, TaxSubmission>  $stored
     * @return list<array>
     */
    public static function forYear(string $label, array $y, array $stored): array
    {
        $out = [];
        $period = UkTaxYear::fromLabel($label);
        $day = fn (?CarbonImmutable $d) => $d?->format('Y-m-d');
        if ($y['regime'] !== PenaltyRegime::SCH55_56 && $y['return_needed'] && $y['deadline'] !== null) {
            $row = $stored[$label.'|annual|'.$period->startsOn()->format('Y-m-d')] ?? null;
            $out[] = ['id' => $row?->id, 'key' => $label.':annual', 'tax_year' => $label, 'kind' => 'annual', 'quarter' => null,
                'period_start' => $day($period->startsOn()), 'period_end' => $day($period->endsOn()), 'due_on' => $day($y['deadline']),
                // Sent on the day the return was filed; filed with no date recorded is taken as on time.
                'submitted_on' => $day($y['filed_on']), 'made' => $y['filed'], 'excused' => (bool) $row?->excused, 'note' => $row?->note, 'derived' => true, 'basis' => null];
        }
        if ($y['quarterly'] !== null) {
            foreach (self::quarters($label) as $q) {
                $row = $stored[$label.'|quarterly|'.$q['period_start']->format('Y-m-d')] ?? null;
                $sent = $row?->submitted_on ? CarbonImmutable::parse($row->submitted_on->format('Y-m-d')) : null;
                $out[] = ['id' => $row?->id, 'key' => $label.':q'.$q['quarter'], 'tax_year' => $label, 'kind' => 'quarterly', 'quarter' => $q['quarter'],
                    'period_start' => $day($q['period_start']), 'period_end' => $day($q['period_end']), 'due_on' => $day($q['due_on']),
                    'submitted_on' => $day($sent), 'made' => $sent !== null, 'excused' => (bool) $row?->excused, 'note' => $row?->note, 'derived' => false, 'basis' => $y['quarterly']];
            }
        }

        return $out;
    }

    /**
     * Make the year's rows so you can record against them: its quarterly updates, if it has them, and its return's
     * row (under the new rules), kept in step with the year's filing date and filed date. Nothing is deleted: what
     * you recorded stays if a setting changes, and is simply not counted while it doesn't apply.
     */
    public static function sync(string $label): void
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $period = UkTaxYear::fromLabel($label);
        $record = TaxYear::query()->where('label', $label)->first();
        if ($record !== null && PenaltyRegime::for($label) !== PenaltyRegime::SCH55_56) {
            $deadline = FilingDates::deadline($label, $record);
            if ($deadline !== null) {
                $row = self::row($label, 'annual', $period->startsOn()) ?? new TaxSubmission(['tax_year' => $label, 'kind' => 'annual', 'period_start' => $period->startsOn()->format('Y-m-d')]);
                $want = ['period_end' => $period->endsOn()->format('Y-m-d'), 'due_on' => $deadline->format('Y-m-d'), 'submitted_on' => $record->filed_on?->format('Y-m-d')];
                // Saved only when something differs (compared as days), so showing the page writes nothing.
                if (! $row->exists || collect($want)->contains(fn ($v, $k) => $row->{$k}?->format('Y-m-d') !== $v)) {
                    $row->fill($want)->save();
                }
            }
        }
        if (self::quarterlyBasis($label) !== null) {
            foreach (self::quarters($label) as $q) {
                if (self::row($label, 'quarterly', $q['period_start']) === null) {
                    TaxSubmission::query()->create(['tax_year' => $label, 'kind' => 'quarterly', 'period_start' => $q['period_start']->format('Y-m-d'),
                        'period_end' => $q['period_end']->format('Y-m-d'), 'due_on' => $q['due_on']->format('Y-m-d')]);
                }
            }
        }
    }

    /** The stored row for a submission (dates compared as days, whatever time the database keeps with them). */
    private static function row(string $label, string $kind, CarbonImmutable $start): ?TaxSubmission
    {
        return TaxSubmission::query()->where('tax_year', $label)->where('kind', $kind)->whereDate('period_start', $start->format('Y-m-d'))->first();
    }

    /** "6 Jul to 5 Oct 2027", or "6 Oct 2026 to 5 Jan 2027" across a year end. */
    public static function periodText(array $s): string
    {
        $from = CarbonImmutable::parse($s['period_start']);
        $to = CarbonImmutable::parse($s['period_end']);

        return $from->format('j M').($from->format('Y') !== $to->format('Y') ? ' '.$from->format('Y') : '').' to '.$to->format('j M Y');
    }

    /** "the 2026-27 return" or "the quarterly update for 6 July to 5 October 2026". */
    public static function describe(array $s): string
    {
        if ($s['kind'] === 'annual') {
            return 'the '.$s['tax_year'].' return';
        }
        $from = CarbonImmutable::parse($s['period_start']);
        $to = CarbonImmutable::parse($s['period_end']);

        return 'the quarterly update for '.$from->format('j F').($from->format('Y') !== $to->format('Y') ? ' '.$from->format('Y') : '').' to '.$to->format('j F Y');
    }
}
