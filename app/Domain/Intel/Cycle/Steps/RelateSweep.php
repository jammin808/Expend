<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Vetoes;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Models\Veto;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * relate.sweep (docs/intelligence-cycle.md 4.2, M7): takes back automatic suggestions whose basis has gone, so the Inbox
 * never offers what can no longer be right. Only suggestions nobody has confirmed (a matcher's, the AI's or a rule's),
 * in open years; your decisions and anything confirmed are never touched. Taken back by matching, with the reason, and
 * back in the Inbox to be suggested afresh:
 *  - matched to an invoice that has been voided, or that other payments you confirmed have paid in full;
 *  - put on a job that has been cancelled;
 *  - made by a rule that has since been deleted;
 *  - what you have since said no to (a veto: its shape, or the invoice it's matched to).
 *
 * Each part goes through the lines in id order, PER_CHUNK at a time, and stops between chunks when its time is up,
 * carrying on from its cursor. Safe to run twice: a line taken back is undecided, and isn't found again.
 */
final class RelateSweep implements CycleStep
{
    public const KEY = 'relate.sweep';

    public const PER_CHUNK = 300;

    /** The parts, in order. */
    public const PARTS = ['void_invoice', 'paid_invoice', 'cancelled_job', 'deleted_rule', 'vetoed'];

    public function __construct(private AllocationWriter $writer, private int $chunk = self::PER_CHUNK) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Relate;
    }

    public function writes(): bool
    {
        return true;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $part = in_array($ctx->cursor['part'] ?? null, self::PARTS, true) ? $ctx->cursor['part'] : self::PARTS[0];
        $after = (int) ($ctx->cursor['last_id'] ?? 0);
        $counts = array_merge(array_fill_keys(self::PARTS, 0), array_intersect_key((array) ($ctx->cursor['counts'] ?? []), array_flip(self::PARTS)));
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();

        for ($i = array_search($part, self::PARTS, true); $i < count(self::PARTS); $i++) {
            $part = self::PARTS[$i];
            while (true) {
                [$swept, $last] = $this->sweep($part, $after, $locked, $ctx);
                $counts[$part] += $swept;
                if ($last === null) {
                    break; // this part is done
                }
                $after = $last;
                if (! $ctx->hasTime()) {
                    return StepOutcome::more(['part' => $part, 'last_id' => $after, 'counts' => $counts], ['swept' => array_sum($counts)] + $counts);
                }
            }
            $after = 0;
        }

        return StepOutcome::ok(['swept' => array_sum($counts)] + $counts);
    }

    /**
     * One chunk of one part: the lines after $after (by id) that it looks at, and those of them it took back.
     *
     * @param  list<string>  $locked
     * @return array{0:int, 1:?int} lines taken back, and the last id looked at (null: nothing left in this part)
     */
    private function sweep(string $part, int $after, array $locked, CycleContext $ctx): array
    {
        if ($part === 'vetoed') {
            return $this->sweepVetoed($after, $locked, $ctx);
        }
        $query = self::open($locked)->where('id', '>', $after)->orderBy('id')->limit($this->chunk);
        $txs = match ($part) {
            'void_invoice' => $query->whereHas('allocations', fn ($a) => $a->whereIn('invoice_id', Invoice::query()->where('status', 'void')->select('id'))),
            'paid_invoice' => $query->whereHas('allocations', fn ($a) => $a->whereIn('invoice_id', Invoice::query()->whereIn('status', ['issued', 'paid'])->select('id'))),
            'cancelled_job' => $query->whereHas('allocations', fn ($a) => $a->whereIn('work_job_id', WorkJob::query()->where('status', 'cancelled')->select('id'))),
            'deleted_rule' => $query->whereHas('allocations', fn ($a) => $a->where('decided_by', 'rule')
                ->where(fn ($a) => $a->whereNull('merchant_rule_id')->orWhereNotIn('merchant_rule_id', MerchantRule::query()->select('id')))),
        };
        $txs = $txs->with('allocations')->get();
        if ($txs->isEmpty()) {
            return [0, null];
        }
        $reasons = match ($part) {
            'void_invoice', 'paid_invoice' => $this->invoiceReasons($txs, $part),
            'cancelled_job' => $this->jobReasons($txs),
            'deleted_rule' => $txs->mapWithKeys(fn (BankTransaction $t) => [$t->id => 'The rule that suggested this has been deleted, so it’s back for you to decide'])->all(),
        };
        $swept = 0;
        foreach ($txs as $tx) {
            if (isset($reasons[$tx->id]) && $this->takeBack($tx, $reasons[$tx->id], $ctx)) {
                $swept++;
            }
        }

        return [$swept, (int) $txs->last()->id];
    }

    /**
     * Suggestions you've since said no to: their shape (a veto on what this line is), or the invoice they're matched to
     * (a veto on that invoice for this payment). Gone through by the vetoes' subjects, in order.
     *
     * @param  list<string>  $locked
     * @return array{0:int, 1:?int}
     */
    private function sweepVetoed(int $after, array $locked, CycleContext $ctx): array
    {
        $ids = Veto::query()->active()->whereIn('relation', ['allocation_shape', 'invoice_payment'])->where('subject_ref', 'like', 'tx:%')->pluck('subject_ref')
            ->map(fn (string $r) => (int) substr($r, 3))->filter(fn (int $id) => $id > $after)->unique()->sort()->values()->take($this->chunk);
        if ($ids->isEmpty()) {
            return [0, null];
        }
        $txs = self::open($locked)->whereIn('id', $ids->all())->with('allocations')->orderBy('id')->get();
        $vetoes = Veto::query()->active()->whereIn('subject_ref', $ids->map(fn ($id) => 'tx:'.$id)->all())->whereIn('relation', ['allocation_shape', 'invoice_payment'])->get()->groupBy('subject_ref');
        $swept = 0;
        foreach ($txs as $tx) {
            $mine = $vetoes->get('tx:'.$tx->id, collect());
            $method = Methods::of($tx->allocations->first())['method'];
            $shape = Vetoes::shape($tx->allocations);
            $blocked = Vetoes::matching($mine->where('relation', 'allocation_shape'), null, $shape, $method)->isNotEmpty()
                || $tx->allocations->pluck('invoice_id')->filter()->contains(fn ($inv) => Vetoes::matching($mine->where('relation', 'invoice_payment'), 'inv:'.$inv, null, $method)->isNotEmpty());
            if ($blocked && $this->takeBack($tx, 'You’ve said no to this suggestion, so it’s back for you to decide', $ctx)) {
                $swept++;
            }
        }

        return [$swept, (int) $ids->last()];
    }

    /**
     * Lines with an automatic suggestion only (a matcher's, the AI's or a rule's, nothing confirmed, nothing of yours), in
     * open years.
     *
     * @param  list<string>  $locked
     */
    private static function open(array $locked): Builder
    {
        return BankTransaction::query()->where('review_status', 'suggested')->whereNotIn('tax_year', $locked ?: [''])
            ->whereHas('allocations')
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)->orWhereNotIn('decided_by', ['matcher', 'ai', 'rule'])));
    }

    /**
     * Why each line's invoice match no longer holds: the invoice was voided, or is paid in full by payments you confirmed
     * (this suggestion isn't confirmed, so it isn't among them).
     *
     * @param  Collection<int, BankTransaction>  $txs
     * @return array<int, string>
     */
    private function invoiceReasons(Collection $txs, string $part): array
    {
        $invoices = Invoice::query()->whereIn('id', $txs->flatMap(fn (BankTransaction $t) => $t->allocations->pluck('invoice_id'))->filter()->unique()->values()->all())->get()->keyBy('id');
        $received = $part === 'paid_invoice' ? InvoicePayments::received($invoices->filter(fn (Invoice $i) => $i->status !== 'void')) : [];
        $out = [];
        foreach ($txs as $tx) {
            foreach ($tx->allocations->pluck('invoice_id')->filter()->unique() as $id) {
                $i = $invoices[$id] ?? null;
                if (! $i) {
                    continue;
                }
                $number = $i->number ?? 'the draft';
                if ($part === 'void_invoice' && $i->status === 'void') {
                    $out[$tx->id] = "Invoice {$number} has been voided, so this payment can’t be for it: it’s back for you to decide";
                } elseif ($part === 'paid_invoice' && $i->total_minor > 0 && ($received[$i->id]['paid'] ?? 0) >= $i->total_minor) {
                    $out[$tx->id] = "Invoice {$number} has been paid by other payments you confirmed, so this one isn’t for it: it’s back for you to decide";
                }
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, BankTransaction>  $txs
     * @return array<int, string>
     */
    private function jobReasons(Collection $txs): array
    {
        $jobs = WorkJob::query()->whereIn('id', $txs->flatMap(fn (BankTransaction $t) => $t->allocations->pluck('work_job_id'))->filter()->unique()->values()->all())
            ->where('status', 'cancelled')->pluck('title', 'id');
        $out = [];
        foreach ($txs as $tx) {
            $job = $tx->allocations->pluck('work_job_id')->filter()->first(fn ($id) => isset($jobs[$id]));
            if ($job !== null) {
                $out[$tx->id] = "{$jobs[$job]} was cancelled, so this suggestion no longer holds: it’s back for you to decide";
            }
        }

        return $out;
    }

    /**
     * Take a suggestion back, as matching's, with why. Checked again with the line locked, so a decision of yours made
     * since it was read is never cleared. False when it changed since (decided, confirmed, or its year locked).
     */
    private function takeBack(BankTransaction $tx, string $reason, CycleContext $ctx): bool
    {
        try {
            $done = DB::transaction(function () use ($tx, $reason) {
                BankTransaction::query()->whereKey($tx->id)->lockForUpdate()->value('id');
                $fresh = Allocation::query()->where('bank_transaction_id', $tx->id)->lockForUpdate()->get();
                if ($fresh->isEmpty() || $fresh->contains(fn (Allocation $a) => $a->confirmed_at !== null || $a->is_manual || ! in_array($a->decided_by, ['matcher', 'ai', 'rule'], true))) {
                    return false;
                }
                AuditContext::with('matcher', fn () => $this->writer->clear($tx, $reason, 'matcher'));

                return true;
            });
        } catch (InvalidArgumentException) {
            return false; // locked since
        }
        if ($done) {
            $ctx->handOff('changed', 'tx:'.$tx->id);
        }

        return $done;
    }
}
