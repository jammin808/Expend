<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Matching\TransferPairer;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way allocations are written. Enforces the ledger's invariants:
 *  - lines sum exactly to the source amount;
 *  - business lines have a category of the right kind;
 *  - automation never overwrites a decision you made or confirmed;
 *  - locked tax years can't change;
 *  - every change is audited, with who really made it: a decision names its maker, and confirming or clearing
 *    takes the actor of the surrounding AuditContext ('you' outside one);
 *  - two writers never decide the same line at once: the line and its allocations are locked while they are
 *    checked and replaced, so the guard above always sees the latest decision.
 *
 * Deciding what a line already says changes nothing and records nothing (a nightly pass that comes to the same
 * suggestion again leaves no trace).
 */
final class AllocationWriter
{
    /**
     * @param  list<array{kind:string, category_id?:?int, work_job_id?:?int, amount_pence:int, business_use_pct?:?int, note?:?string}>  $lines
     * @return bool true when the line now says this (written now, or it already did); false when skipped because
     *              automation tried to touch a protected decision
     */
    public function decide(
        BankTransaction|ManualExpense $source,
        array $lines,
        string $decidedBy = 'you',
        bool $confirm = true,
        ?string $reason = null,
        ?int $ruleId = null,
    ): bool {
        $amount = $source->amount_pence;
        $taxYear = $this->taxYearOf($source);
        $this->assertUnlocked($taxYear);
        $this->assertLines($lines, $amount);

        $done = DB::transaction(function () use ($source, $lines, $decidedBy, $confirm, $reason, $ruleId, $taxYear) {
            $existing = $this->lockedAllocations($source, $status);

            if ($decidedBy !== 'you' && $existing->contains(fn (Allocation $a) => $a->is_manual || $a->confirmed_at !== null)) {
                return false;
            }
            if ($this->unchanged($status, $existing, $lines, $decidedBy, $confirm, $reason, $ruleId)) {
                return true; // already says exactly this: nothing to write, nothing to record
            }

            $before = $existing->map(fn (Allocation $a) => $this->snapshot($a))->all();
            $source->allocations()->delete();

            $now = now();
            $created = [];
            foreach ($lines as $line) {
                $created[] = $source->allocations()->create([
                    'tax_year' => $taxYear,
                    'kind' => $line['kind'],
                    'category_id' => in_array($line['kind'], Allocation::NEEDS_CATEGORY, true) ? ($line['category_id'] ?? null) : null,
                    'work_job_id' => $line['work_job_id'] ?? null,
                    'invoice_id' => $line['invoice_id'] ?? null,
                    'amount_pence' => $line['amount_pence'],
                    'business_use_pct' => $line['business_use_pct'] ?? null,
                    'decided_by' => $decidedBy,
                    'is_manual' => $decidedBy === 'you',
                    'confirmed_at' => $confirm ? $now : null,
                    'merchant_rule_id' => $ruleId,
                    'reason' => self::fit($reason),
                    'note' => self::fit($line['note'] ?? null),
                ]);
            }

            $source->forceFill(['review_status' => $confirm ? 'confirmed' : 'suggested'])->saveQuietly();

            AuditLog::record(
                $decidedBy === 'you' ? 'allocation.decided' : 'allocation.suggested',
                $source,
                $before ?: null,
                array_map(fn (Allocation $a) => $this->snapshot($a), $created),
                $reason,
                $decidedBy === 'you' ? 'you' : $decidedBy,
            );

            return true;
        });
        if ($done && $decidedBy === 'you') {
            $this->afterYou($source);
        }

        return $done;
    }

    /** One side of a transfer pair decided by you settles the other side (see TransferPairer::afterYourDecision). */
    private function afterYou(BankTransaction|ManualExpense $source): void
    {
        if ($source instanceof BankTransaction && $source->transfer_pair_id) {
            app(TransferPairer::class)->afterYourDecision($source);
        }
    }

    /** Reasons and notes are shown in full in the history; the stored copy fits its column. */
    private static function fit(?string $text, int $max = 255): ?string
    {
        return $text !== null && mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * Accept the current suggestion as it stands. Recorded as the surrounding AuditContext's actor: 'you' for your
     * tap, 'matcher' when a matcher settles a line for you (the other side of a transfer you decided).
     */
    public function confirm(BankTransaction|ManualExpense $source, ?string $reason = null): void
    {
        $this->assertUnlocked($this->taxYearOf($source));
        $actor = AuditContext::actor();

        DB::transaction(function () use ($source, $reason, $actor) {
            $allocations = $this->lockedAllocations($source);
            if ($allocations->isEmpty()) {
                throw new InvalidArgumentException('There is nothing to confirm yet. Choose what this is first.');
            }
            $source->allocations()->whereNull('confirmed_at')->update(['confirmed_at' => now()]);
            $source->forceFill(['review_status' => 'confirmed'])->saveQuietly();
            AuditLog::record('allocation.confirmed', $source, null, $allocations->map(fn ($a) => $this->snapshot($a))->all(), $reason, $actor);
        });
        // Only your own confirmation settles the other side of a pair; a matcher's never sets off another.
        if ($actor === 'you') {
            $this->afterYou($source);
        }
    }

    /**
     * Remove the decision so the item goes back to the Inbox.
     *
     * @param  string|null  $actor  who cleared it, for the history: 'matcher' when an outdated suggestion is taken back;
     *                              when not given, the surrounding AuditContext's actor ('you' outside one)
     */
    public function clear(BankTransaction|ManualExpense $source, ?string $reason = null, ?string $actor = null): void
    {
        $this->assertUnlocked($this->taxYearOf($source));
        $actor ??= AuditContext::actor();

        DB::transaction(function () use ($source, $reason, $actor) {
            $before = $this->lockedAllocations($source)->map(fn ($a) => $this->snapshot($a))->all();
            $source->allocations()->delete();
            $source->forceFill(['review_status' => 'unreviewed'])->saveQuietly();
            AuditLog::record('allocation.cleared', $source, $before ?: null, null, $reason, $actor);
        });
    }

    /**
     * The line's allocations, read afresh with the line and its allocations locked until the surrounding transaction
     * ends, so another writer waits rather than deciding from what it read before.
     *
     * @param  string|null  $status  set to the line's review status as stored
     * @return EloquentCollection<int, Allocation>
     */
    private function lockedAllocations(BankTransaction|ManualExpense $source, ?string &$status = null): EloquentCollection
    {
        $status = $source->newQuery()->whereKey($source->getKey())->lockForUpdate()->value('review_status');

        return $source->allocations()->lockForUpdate()->get();
    }

    /**
     * Whether the line already says exactly this: the same lines, by the same maker, with the same reason and rule,
     * confirmed or not alike, and the line's status to match. The Categoriser's marker isn't compared: it's set after
     * the write, from the reason.
     *
     * @param  EloquentCollection<int, Allocation>  $existing
     */
    private function unchanged(?string $status, EloquentCollection $existing, array $lines, string $decidedBy, bool $confirm, ?string $reason, ?int $ruleId): bool
    {
        if ($existing->isEmpty() || $existing->count() !== count($lines) || $status !== ($confirm ? 'confirmed' : 'suggested')) {
            return false;
        }
        $int = fn ($v) => $v === null ? null : (int) $v;
        $key = fn (array $l) => json_encode([
            $l['kind'], $int($l['category_id']), $int($l['work_job_id']), $int($l['invoice_id']), (int) $l['amount_pence'],
            $int($l['business_use_pct']), $l['note'], $l['decided_by'], (bool) $l['is_manual'], (bool) $l['confirmed'],
            $int($l['merchant_rule_id']), $l['reason'],
        ]);
        $have = $existing->map(fn (Allocation $a) => $key([
            'kind' => $a->kind, 'category_id' => $a->category_id, 'work_job_id' => $a->work_job_id, 'invoice_id' => $a->invoice_id,
            'amount_pence' => $a->amount_pence, 'business_use_pct' => $a->business_use_pct, 'note' => $a->note, 'decided_by' => $a->decided_by,
            'is_manual' => $a->is_manual, 'confirmed' => $a->confirmed_at !== null, 'merchant_rule_id' => $a->merchant_rule_id, 'reason' => $a->reason,
        ]))->sort()->values()->all();
        $want = collect($lines)->map(fn (array $l) => $key([
            'kind' => $l['kind'],
            'category_id' => in_array($l['kind'], Allocation::NEEDS_CATEGORY, true) ? ($l['category_id'] ?? null) : null,
            'work_job_id' => $l['work_job_id'] ?? null, 'invoice_id' => $l['invoice_id'] ?? null, 'amount_pence' => $l['amount_pence'],
            'business_use_pct' => $l['business_use_pct'] ?? null, 'note' => self::fit($l['note'] ?? null), 'decided_by' => $decidedBy,
            'is_manual' => $decidedBy === 'you', 'confirmed' => $confirm, 'merchant_rule_id' => $ruleId, 'reason' => self::fit($reason),
        ]))->sort()->values()->all();

        return $have === $want;
    }

    /**
     * Build lines for a single decision, optionally split into business and personal parts.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(int $amount, string $kind, ?int $categoryId = null, ?int $jobId = null, int $businessPct = 100, ?string $note = null): array
    {
        if (! in_array($kind, Allocation::NEEDS_CATEGORY, true) || $businessPct >= 100) {
            return [[
                'kind' => $kind, 'category_id' => $categoryId, 'work_job_id' => $jobId, 'amount_pence' => $amount,
                'business_use_pct' => in_array($kind, Allocation::NEEDS_CATEGORY, true) ? 100 : null, 'note' => $note,
            ]];
        }

        [$business, $personal] = Money::splitByPercent($amount, max(0, $businessPct));
        $lines = [];
        if ($business !== 0) {
            $lines[] = ['kind' => $kind, 'category_id' => $categoryId, 'work_job_id' => $jobId, 'amount_pence' => $business, 'business_use_pct' => $businessPct, 'note' => $note];
        }
        if ($personal !== 0) {
            $lines[] = ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => $personal, 'business_use_pct' => null, 'note' => $note];
        }

        return $lines;
    }

    public function taxYearOf(BankTransaction|ManualExpense $source): string
    {
        return $source instanceof BankTransaction
            ? UkTaxYear::fromDate($source->effectiveDate())->label()
            : UkTaxYear::fromDate($source->spent_on)->label();
    }

    private function assertUnlocked(string $label): void
    {
        $year = TaxYear::query()->where('label', $label)->first();
        if ($year?->isLocked()) {
            throw new InvalidArgumentException("Tax year {$label} is locked. Unlock it (with a reason) before changing anything in it.");
        }
    }

    private function assertLines(array $lines, int $amount): void
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A decision needs at least one line.');
        }

        $sum = 0;
        foreach ($lines as $i => $line) {
            if (! isset(Allocation::KINDS[$line['kind'] ?? ''])) {
                throw new InvalidArgumentException('Line '.($i + 1).' has an unknown type.');
            }
            if (! is_int($line['amount_pence'] ?? null) || $line['amount_pence'] === 0) {
                throw new InvalidArgumentException('Line '.($i + 1).' needs a non-zero amount.');
            }
            if (($line['amount_pence'] < 0) !== ($amount < 0)) {
                throw new InvalidArgumentException('Line '.($i + 1).' must be '.($amount < 0 ? 'money out' : 'money in').', like the transaction itself.');
            }
            if (in_array($line['kind'], Allocation::NEEDS_CATEGORY, true)) {
                $category = isset($line['category_id']) ? Category::query()->find($line['category_id']) : null;
                if (! $category) {
                    throw new InvalidArgumentException('Line '.($i + 1).' needs an HMRC category.');
                }
                $expected = $line['kind'] === 'business_income' ? 'income' : 'expense';
                if ($category->kind !== $expected) {
                    throw new InvalidArgumentException('Line '.($i + 1).": \"{$category->name}\" isn't an {$expected} category.");
                }
            }
            $sum += $line['amount_pence'];
        }

        if ($sum !== $amount) {
            throw new InvalidArgumentException(sprintf(
                'The lines add up to %s but the amount is %s. They must match exactly.',
                Money::format($sum), Money::format($amount),
            ));
        }
    }

    private function snapshot(Allocation $a): array
    {
        return $a->only(['kind', 'category_id', 'work_job_id', 'amount_pence', 'business_use_pct', 'decided_by', 'is_manual', 'note'])
            + ['confirmed' => $a->confirmed_at !== null];
    }
}
