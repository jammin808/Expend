<?php

namespace App\Domain\Income;

use App\Models\Allocation;
use App\Models\Category;

/**
 * Ledger lines as the writer takes them (AllocationWriter::decide), for income: built from what a payment already
 * says, field by field, so re-saving it with one thing changed (a client, a job) never loses the rest (its invoice, its
 * note, its business share, its other lines); and the income and deduction lines the matcher and the chips propose.
 */
final class Lines
{
    /** The fields of a line the writer keeps. */
    public const FIELDS = ['kind', 'category_id', 'work_job_id', 'invoice_id', 'client_id', 'amount_pence', 'business_use_pct', 'note'];

    /**
     * A payment's lines as they stand, field by field, in the order they were written.
     *
     * @param  iterable<Allocation>  $allocations
     * @return list<array{kind:string, category_id:?int, work_job_id:?int, invoice_id:?int, client_id:?int, amount_pence:int, business_use_pct:?int, note:?string}>
     */
    public static function from(iterable $allocations): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;

        return collect($allocations)->sortBy('id')->values()->map(fn (Allocation $a) => [
            'kind' => (string) $a->kind,
            'category_id' => $int($a->category_id),
            'work_job_id' => $int($a->work_job_id),
            'invoice_id' => $int($a->invoice_id),
            'client_id' => $int($a->client_id),
            'amount_pence' => (int) $a->amount_pence,
            'business_use_pct' => $int($a->business_use_pct),
            'note' => $a->note,
        ])->all();
    }

    /**
     * One turnover line (or another income category's), with whatever it's for: an invoice (the writer fills its job),
     * a job, or only a client.
     *
     * @return array{kind:string, category_id:?int, work_job_id:?int, invoice_id:?int, client_id:?int, amount_pence:int, business_use_pct:int, note:?string}
     */
    public static function income(int $pence, ?int $invoiceId = null, ?int $jobId = null, ?int $clientId = null, ?int $categoryId = null): array
    {
        return [
            'kind' => 'business_income', 'category_id' => $categoryId ?? self::category('turnover'), 'work_job_id' => $jobId, 'invoice_id' => $invoiceId,
            'client_id' => $clientId, 'amount_pence' => $pence, 'business_use_pct' => 100, 'note' => null,
        ];
    }

    /**
     * A deduction: the fee or commission the payer took off, as a negative business expense beside the income (never
     * with an invoice). $pence is what was taken off, as a positive amount.
     *
     * @return array{kind:string, category_id:?int, work_job_id:?int, invoice_id:null, client_id:?int, amount_pence:int, business_use_pct:int, note:?string}
     */
    public static function deduction(int $pence, ?int $categoryId = null, ?int $jobId = null, ?int $clientId = null): array
    {
        return [
            'kind' => 'business_expense', 'category_id' => $categoryId ?? self::category('finance'), 'work_job_id' => $jobId, 'invoice_id' => null,
            'client_id' => $jobId === null ? $clientId : null, 'amount_pence' => -abs($pence), 'business_use_pct' => 100, 'note' => null,
        ];
    }

    /** Whether a line is a deduction: a negative business expense (on money in, beside larger income). */
    public static function isDeduction(array|Allocation $line): bool
    {
        $kind = $line instanceof Allocation ? $line->kind : ($line['kind'] ?? null);
        $pence = (int) ($line instanceof Allocation ? $line->amount_pence : ($line['amount_pence'] ?? 0));

        return $kind === 'business_expense' && $pence < 0;
    }

    /** A category's id by its code (turnover, finance, other), or null when it isn't there. */
    public static function category(string $code): ?int
    {
        $id = Category::query()->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }
}
