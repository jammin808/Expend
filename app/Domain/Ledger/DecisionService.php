<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Turns what you chose on screen into allocations for one or many items. */
final class DecisionService
{
    public function __construct(private AllocationWriter $writer) {}

    /**
     * @param  Collection<int, BankTransaction|ManualExpense>  $sources
     * @return array{saved:int, skipped:int, errors:list<string>}
     */
    public function apply(array $decision, Collection $sources, bool $includeProtected = true): array
    {
        $saved = 0;
        $skipped = 0;
        $errors = [];

        foreach ($sources as $source) {
            if (! $includeProtected && $source->allocations()->where(fn ($q) => $q->where('is_manual', true)->orWhereNotNull('confirmed_at'))->exists()) {
                $skipped++;

                continue;
            }

            try {
                $lines = isset($decision['lines'])
                    ? array_map(fn ($l) => [
                        'kind' => $l['kind'],
                        'category_id' => $l['category_id'] ?? null,
                        'work_job_id' => $l['work_job_id'] ?? null,
                        'amount_pence' => (int) $l['amount_pence'],
                        'business_use_pct' => $l['business_use_pct'] ?? null,
                        'note' => $l['note'] ?? null,
                    ], $decision['lines'])
                    : AllocationWriter::lines(
                        $source->amount_pence,
                        $decision['kind'],
                        $decision['category_id'] ?? null,
                        $decision['work_job_id'] ?? null,
                        (int) ($decision['business_pct'] ?? 100),
                        $decision['note'] ?? null,
                    );
                $this->writer->decide($source, $lines, 'you', true, $decision['reason'] ?? null);
                $saved++;
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (($decision['make_rule'] ?? false) && ! isset($decision['lines']) && ($first = $sources->first())) {
            $rule = MerchantRule::query()->updateOrCreate(['merchant_key' => $first->merchant_key], [
                'kind' => $decision['kind'],
                'category_id' => $decision['category_id'] ?? null,
                'work_job_id' => $decision['work_job_id'] ?? null,
                'business_pct' => (int) ($decision['business_pct'] ?? 100),
            ]);
            AuditLog::record('rule.saved', $rule, null, $rule->toArray());
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'errors' => array_values(array_unique($errors))];
    }
}
