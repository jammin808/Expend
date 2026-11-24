<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Tax\TaxSavings;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Throwable;

/**
 * Tax you could save (tax.saving, M4): the tax savings review (TaxSavings::review, the year page's "Ways to pay less
 * tax") for each open year, as findings: its items to act on or to check that come with an estimated saving. The
 * review only reads your records and the law library (resources/tax/law.json): it never calls AI or anything outside
 * Expend, so neither does this. Each is graded as the review reports it: something to act on, found in your records,
 * is likely; something to check first is possible. A year whose review goes wrong is left out this time, and the
 * findings of that year are kept (coverage failed). Only reads.
 */
final class TaxSaving implements Detector
{
    public const CODE = 'tax.saving';

    public const VERSION = 1;

    /** The review's statuses that become findings, with the grade each is reported at. */
    public const STATUSES = ['action' => Grades::LIKELY, 'check' => Grades::POSSIBLE];

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

    /** The years the last detect() reviewed (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** The open years reviewed: not locked or filed, and the current one even if you aren't tracking it yet. @return list<string> */
    public static function years(): array
    {
        $tracked = TaxYear::query()->get(['label', 'status', 'locked_at'])->keyBy('label');
        $years = $tracked->filter(fn (TaxYear $y) => $y->locked_at === null && ! in_array($y->status, ['filed', 'amending'], true))->keys()->all();
        $current = UkTaxYear::current()->label();
        if (! isset($tracked[$current])) {
            $years[] = $current;
        }
        sort($years);

        return array_values(array_unique($years));
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $drafts = [];
        $coverage = Coverage::Complete;
        $reviewed = 0;
        // The review works out every year's penalties and reads the settings many times: read them once.
        Setting::remembering(function () use (&$drafts, &$coverage, &$reviewed) {
            foreach (self::years() as $label) {
                try {
                    $review = TaxSavings::review($label);
                } catch (Throwable $e) {
                    report($e);
                    $coverage = Coverage::Failed;

                    continue;
                }
                $reviewed++;
                foreach ($review['items'] as $i) {
                    $saving = (int) ($i['saving_pence'] ?? 0);
                    if (! isset(self::STATUSES[$i['status']]) || $saving <= 0) {
                        continue;
                    }
                    $drafts[] = new FindingDraft(
                        key: 'saving:'.$label.':'.$i['id'], code: self::CODE, group: 'claims', priority: 2,
                        title: ($i['title'] ?: 'A way to pay less tax').' ('.$label.'): about '.Money::format($saving, 'GBP', false).' less tax',
                        detail: $i['finding'].($review['in_progress'] ? ' The year isn’t over: worked out from your records so far.' : '')
                            .' An estimate at your rate of tax; the year page has the conditions and the law behind it'.(($i['needs_accountant'] ?? false) ? ', and this one needs an accountant' : '').'.',
                        href: '/years/'.$label, subjectRef: Ref::make('year', $label), scopeRef: Ref::make('year', $label),
                        amountPence: $saving, direction: 'claimable', grade: self::STATUSES[$i['status']], detectorVersion: self::VERSION,
                    );
                }
            }
        });
        $this->checked = $reviewed;
        $this->coverage = $coverage;

        return $drafts;
    }
}
