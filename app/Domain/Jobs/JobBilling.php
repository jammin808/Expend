<?php

namespace App\Domain\Jobs;

use App\Domain\Income\IncomeClient;
use App\Domain\Invoices\InvoicePayments;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Whether a job has been billed, worked out the same way everywhere (the to-do list's "Invoice <job>", the finished-job
 * nudge, the map, the income chips and "Still owed by"): it has an issued (or legacy paid) invoice, or confirmed
 * business income on it, with or without an invoice of another job ("one invoice for three shows", or paid without an
 * Expend invoice), as JobCloseout counts it. A job paid like that doesn't ask to be invoiced.
 *
 * Always by the paying client, never the end client.
 */
final class JobBilling
{
    /** How long before a job's first day a client's payment with no job may be for it (a deposit). */
    public const BEFORE_DAYS = 30;

    /** How long after a job's last day a client's payment with no job may be for it. */
    public const AFTER_DAYS = 120;

    /**
     * The jobs that have been billed, of these.
     *
     * @param  iterable<int|WorkJob>  $jobs
     * @return array<int, true> by job id
     */
    public static function billed(iterable $jobs): array
    {
        $ids = collect($jobs)->map(fn ($j) => (int) ($j instanceof WorkJob ? $j->id : $j))->filter()->unique()->values();
        $out = [];
        if ($ids->isEmpty()) {
            return $out;
        }
        foreach ($ids->chunk(500) as $chunk) {
            $chunk = $chunk->values()->all();
            foreach (Invoice::query()->whereIn('work_job_id', $chunk)->whereIn('status', InvoicePayments::PAYABLE)->distinct()->pluck('work_job_id') as $id) {
                $out[(int) $id] = true;
            }
            // Confirmed income on the job, net of money paid back, from active statements (a held statement's lines
            // count nowhere).
            $paid = Allocation::query()->where('kind', 'business_income')->whereNotNull('confirmed_at')->whereIn('work_job_id', $chunk)
                ->where(fn ($q) => $q->whereNull('bank_transaction_id')->orWhereHas('transaction'))
                ->groupBy('work_job_id')->havingRaw('SUM(amount_pence) > 0')->pluck('work_job_id');
            foreach ($paid as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /** Whether one job has been billed. */
    public static function isBilled(WorkJob|int $job): bool
    {
        $id = (int) ($job instanceof WorkJob ? $job->id : $job);

        return isset(self::billed([$id])[$id]);
    }

    /**
     * A client's finished jobs with nothing billed (cancelled jobs left out), the latest first: what that client may
     * still owe you for. A client merged into this one counts as this one.
     *
     * @return EloquentCollection<int, WorkJob>
     */
    public static function owed(int $clientId, int $limit = 20): EloquentCollection
    {
        $jobs = WorkJob::query()->whereIn('client_id', self::clientIds($clientId))->where('status', '!=', 'cancelled')
            ->whereDate('ends_on', '<', today()->format('Y-m-d'))->orderByDesc('ends_on')->orderByDesc('id')->limit(max(1, $limit) * 5)->get();
        $billed = self::billed($jobs);

        return $jobs->reject(fn (WorkJob $j) => isset($billed[$j->id]))->take($limit)->values();
    }

    /**
     * Confirmed payments the job's client made with no job and no invoice, posted from 30 days before its first day to
     * 120 days after its last: "Acme Events paid £450 on 3 Mar with no job: was it for this?". Only money in, on
     * active statements, oldest first, each line with its payment.
     *
     * @return EloquentCollection<int, Allocation>
     */
    public static function clientOnlyAfter(WorkJob $job): EloquentCollection
    {
        if ($job->client_id === null || $job->starts_on === null || $job->ends_on === null) {
            return new EloquentCollection;
        }
        $from = $job->starts_on->copy()->subDays(self::BEFORE_DAYS)->format('Y-m-d');
        $to = $job->ends_on->copy()->addDays(self::AFTER_DAYS)->format('Y-m-d');

        return Allocation::query()->select('allocations.*')
            ->join('bank_transactions as jb_tx', 'jb_tx.id', '=', 'allocations.bank_transaction_id')
            ->where('jb_tx.state', 'active')->whereDate('jb_tx.posted_on', '>=', $from)->whereDate('jb_tx.posted_on', '<=', $to)
            ->where('allocations.kind', 'business_income')->whereNotNull('allocations.confirmed_at')->where('allocations.amount_pence', '>', 0)
            ->whereNull('allocations.work_job_id')->whereNull('allocations.invoice_id')
            ->whereIn('allocations.client_id', self::clientIds((int) $job->client_id))
            ->orderBy('jb_tx.posted_on')->orderBy('allocations.id')
            ->with('transaction')->get();
    }

    /**
     * A client and every client merged into it (or, for a merged client, the one it went into and its other
     * duplicates): the rows that count as one client.
     *
     * @return list<int>
     */
    private static function clientIds(int $clientId): array
    {
        $into = IncomeClient::resolve($clientId) ?? $clientId;

        return Client::query()->whereKey($into)->orWhere('merged_into_id', $into)->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
