<?php

namespace App\Domain\Income;

use App\Models\Allocation;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one place a ledger line's client is worked out (income design v3): its invoice's client, else its own job's
 * client, else the client stored on the line (allocations.client_id, kept only where neither names one: client-only
 * income, a refund paid back to a client, a deduction with no job, a cost for a client with no job). Nothing is copied
 * onto a line that has an invoice or a job, so editing a job's client re-credits that job's lines with nothing written.
 *
 * It's always the paying client (who you invoice and who pays, such as a crewing agency), never a job's end client.
 * A client you merged into another counts as the one it was merged into (clients.merged_into_id, always one step), so
 * every reader sees one client for both.
 */
final class IncomeClient
{
    /** The column scope() adds to each row: the worked-out client, merges resolved. */
    public const COLUMN = 'client_id_worked';

    /** The client before merges: the invoice's, else the line's own job's, else the line's own. */
    private const RAW = 'COALESCE(ic_inv.client_id, ic_job.client_id, allocations.client_id)';

    /**
     * The worked-out client as an SQL expression over allocations, for a select, a filter or a GROUP BY. It needs the
     * joins scope() adds. A client that wasn't merged has no merged_into_id, so the COALESCE falls through to it.
     */
    public static function expression(): string
    {
        return 'COALESCE(ic_cm.merged_into_id, ic_inv.client_id, ic_job.client_id, allocations.client_id)';
    }

    /**
     * Adds the worked-out client to a query of allocations as client_id_worked: two left joins (the line's invoice and
     * its own job) and one to the client, for merges. Filter or group with expression(). When nothing is selected yet,
     * every allocation column is kept.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public static function scope(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        $base = $query instanceof Builder ? $query->getQuery() : $query;
        if ($base->columns === null) {
            $query->select('allocations.*');
        }

        return $query
            ->leftJoin('invoices as ic_inv', 'ic_inv.id', '=', 'allocations.invoice_id')
            ->leftJoin('work_jobs as ic_job', 'ic_job.id', '=', 'allocations.work_job_id')
            ->leftJoin('clients as ic_cm', 'ic_cm.id', '=', DB::raw(self::RAW))
            ->addSelect(DB::raw(self::expression().' as '.self::COLUMN));
    }

    /**
     * The worked-out client of each of these lines (merges resolved), by allocation id, with its name: two queries
     * whatever the number of lines (the lines with scope()'s joins, then the clients' names). A line nothing names a
     * client for is left out.
     *
     * @param  iterable<int>  $allocationIds
     * @return array<int, array{id:int, name:string}>
     */
    public static function named(iterable $allocationIds): array
    {
        $ids = collect($allocationIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $worked = collect();
        foreach ($ids->chunk(500) as $chunk) {
            $worked = $worked->union(self::scope(DB::table('allocations')->select('allocations.id'))->whereIn('allocations.id', $chunk->all())
                ->pluck(self::COLUMN, 'id'));
        }
        $worked = $worked->filter(fn ($client) => $client !== null)->map(fn ($client) => (int) $client);
        $names = $worked->isEmpty() ? collect() : Client::query()->whereKey($worked->unique()->values()->all())->pluck('name', 'id');

        return $worked->filter(fn (int $client) => $names->has($client))->map(fn (int $client) => ['id' => $client, 'name' => (string) $names[$client]])->all();
    }

    /** One line's worked-out client (merges resolved), or null when nothing names one. */
    public static function of(Allocation $line): ?int
    {
        $client = null;
        if ($line->invoice_id !== null) {
            $invoice = $line->relationLoaded('invoice') ? $line->invoice : Invoice::query()->whereKey($line->invoice_id)->first(['id', 'client_id']);
            $client = $invoice?->client_id;
        }
        if ($client === null && $line->work_job_id !== null) {
            $job = $line->relationLoaded('job') ? $line->job : WorkJob::query()->whereKey($line->work_job_id)->first(['id', 'client_id']);
            $client = $job?->client_id;
        }

        return self::resolve($client ?? $line->client_id);
    }

    /** An invoice's client (merges resolved): its own client_id, else its job's, else null. */
    public static function ofInvoice(Invoice $invoice): ?int
    {
        $client = $invoice->client_id;
        if ($client === null && $invoice->work_job_id !== null) {
            $client = WorkJob::query()->whereKey($invoice->work_job_id)->value('client_id');
        }

        return self::resolve($client === null ? null : (int) $client);
    }

    /** The client a merged duplicate was merged into, or the client itself. */
    public static function resolve(?int $clientId): ?int
    {
        if ($clientId === null) {
            return null;
        }
        $into = Client::query()->whereKey($clientId)->value('merged_into_id');

        return $into === null ? $clientId : (int) $into;
    }
}
