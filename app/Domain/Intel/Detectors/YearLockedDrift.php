<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\YearChecks;
use App\Models\TaxYear;
use App\Support\Money;

/**
 * Locked years whose figures have changed (year.locked_drift, M4): for each locked year, the figures recorded when you
 * locked it (the tax_year.locked event's snapshot: YearChecks::lockSnapshot()) against ReturnSummary::for() now. A
 * locked year's own records can't change, but what its figures are worked out from can: a category made allowable or
 * not, the year's rates or the mileage rules. A year locked before the snapshot existed has nothing to compare with.
 * The key carries the difference, so "Not relevant" sets aside the difference you've seen, and a new one comes back.
 * Only reads.
 */
final class YearLockedDrift implements Detector
{
    public const CODE = 'year.locked_drift';

    public const VERSION = 1;

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

    /** The locked years with a snapshot the last detect() compared (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $drafts = [];
        $compared = 0;
        foreach (TaxYear::query()->whereNotNull('locked_at')->orderBy('label')->get() as $year) {
            $event = YearChecks::lastLock($year);
            $then = $event?->after['snapshot']['figures'] ?? null;
            if (! is_array($then)) {
                continue;
            }
            $compared++;
            $diff = YearChecks::figuresDiff($then, YearChecks::figures(ReturnSummary::for($year->label)));
            if ($diff === []) {
                continue;
            }
            $label = $year->label;
            $profit = $diff['net_profit_pence'] ?? null;
            $drafts[] = new FindingDraft(
                key: 'locked_drift:'.$label.':'.substr(md5(json_encode($diff)), 0, 10), code: self::CODE, group: 'tax', priority: 2,
                title: $label.' has changed since you locked it'.($profit !== null ? ': profit '.Money::format(abs($profit['now'] - $profit['then']), 'GBP', false).' '.($profit['now'] > $profit['then'] ? 'higher' : 'lower') : ''),
                detail: 'When you locked it on '.$event->occurred_at->format('j M Y').': '.implode(', ', array_map(fn ($k, $d) => YearChecks::FIGURE_NAMES[$k].' '.Money::format($d['then'], 'GBP', false), array_keys($diff), $diff))
                    .'. Now: '.implode(', ', array_map(fn ($k, $d) => YearChecks::FIGURE_NAMES[$k].' '.Money::format($d['now'], 'GBP', false), array_keys($diff), $diff))
                    .'. Nothing in the year was changed, so something its figures are worked out from has (a category’s allowable setting, the year’s rates or the mileage rules). '
                    .'Check it on the year page before you rely on the return you filed.',
                href: '/years/'.$label, subjectRef: Ref::make('year', $label), scopeRef: Ref::make('year', $label), evidence: [Ref::make('audit', $event->id, null, $event->hash ?: null)],
                direction: 'none', grade: Grades::forCode(self::CODE)[0], detectorVersion: self::VERSION,
            );
        }
        $this->checked = $compared;
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
