<?php

namespace App\Http\Presenters;

use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\WorkJob;
use App\Support\Money;

/** Shapes models into the arrays the React pages use. */
final class Present
{
    public static function tx(BankTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'source' => 'bank',
            'date' => $tx->effectiveDate()->format('Y-m-d'),
            'posted_on' => $tx->posted_on->format('Y-m-d'),
            'tax_year' => $tx->tax_year,
            'account' => $tx->relationLoaded('account') ? ['id' => $tx->account->id, 'name' => $tx->account->name, 'kind' => $tx->account->kind, 'last4' => $tx->account->last4] : null,
            'time' => $tx->transacted_time,
            'place' => $tx->place,
            'card_last4' => $tx->card_last4,
            'payment_type' => $tx->payment_type,
            'description' => $tx->description,
            'counterparty' => $tx->counterparty,
            'merchant_key' => $tx->merchant_key,
            'amount_pence' => $tx->amount_pence,
            'original_amount_minor' => $tx->original_amount_minor,
            'original_currency' => $tx->original_currency,
            // The currency the shop charged, where it isn't the account's own ($90.00 paid from the euro account), in
            // that currency's own units.
            'merchant_amount_minor' => $tx->merchant_amount_minor,
            'merchant_currency' => $tx->merchant_currency,
            // The rate the bank used: how many of the foreign currency per £1.
            'fx' => $tx->fx_source
                ? ['units_per_gbp' => Money::rate($tx->fx_units_per_gbp), 'rate_date' => $tx->fx_rate_date?->format('Y-m-d'), 'source' => $tx->fx_source]
                : ($tx->original_currency && $tx->original_amount_minor && $tx->amount_pence
                    // Worked out from the line itself: its other currency is kept in that currency's own units (¥1,500 is 1500).
                    ? ['units_per_gbp' => number_format(Money::major(abs($tx->original_amount_minor), $tx->original_currency) / (abs($tx->amount_pence) / 100), 4, '.', ''),
                        'rate_date' => $tx->effectiveDate()->format('Y-m-d'), 'source' => 'bank']
                    : null),
            'fee_minor' => $tx->fee_minor,
            'bank_category' => $tx->bank_category,
            'review_status' => $tx->review_status,
            'transfer_pair_id' => $tx->transfer_pair_id,
            'allocations' => $tx->relationLoaded('allocations') ? $tx->allocations->map(fn (Allocation $a) => self::allocation($a))->values()->all() : [],
        ] + ($tx->relationLoaded('quickReceipt') ? ['receipt' => self::receipt($tx->quickReceipt)] : []);
    }

    public static function manual(ManualExpense $e): array
    {
        return [
            'id' => $e->id,
            'source' => 'manual',
            'date' => $e->spent_on->format('Y-m-d'),
            'tax_year' => $e->tax_year,
            'account' => ['id' => 0, 'name' => 'Manual ('.str_replace('_', ' ', $e->paid_with).')', 'kind' => 'cash'],
            'description' => $e->description,
            'merchant_key' => $e->merchant_key,
            'amount_pence' => $e->amount_pence,
            'original_amount_minor' => $e->original_amount_minor,
            'original_currency' => $e->original_currency,
            'fx' => $e->original_currency ? ['units_per_gbp' => Money::rate($e->fx_units_per_gbp), 'rate_date' => $e->fx_rate_date?->format('Y-m-d'), 'source' => $e->fx_source] : null,
            'review_status' => $e->review_status,
            'allocations' => $e->relationLoaded('allocations') ? $e->allocations->map(fn (Allocation $a) => self::allocation($a))->values()->all() : [],
        ] + ($e->relationLoaded('quickEntry') ? ['receipt' => self::receipt($e->quickEntry?->status === 'added' ? $e->quickEntry : null)] : []);
    }

    /**
     * Your receipt photo from the phone on a line or an expense (load quickReceipt, or quickEntry, with the page's
     * items to have it): its entry page and its file. Null for none.
     *
     * @return array{quick_id:int, document_id:int, href:string, file:string, what:?string}|null
     */
    public static function receipt(?QuickEntry $entry): ?array
    {
        return $entry && $entry->document_id ? ['quick_id' => $entry->id, 'document_id' => (int) $entry->document_id, 'href' => '/quick/entries/'.$entry->id,
            'file' => '/documents/'.$entry->document_id.'/file', 'what' => $entry->what] : null;
    }

    public static function allocation(Allocation $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'category_id' => $a->category_id,
            'category' => $a->relationLoaded('category') ? $a->category?->name : null,
            'work_job_id' => $a->work_job_id,
            'job' => $a->relationLoaded('job') ? $a->job?->title : null,
            'amount_pence' => $a->amount_pence,
            'business_use_pct' => $a->business_use_pct,
            'decided_by' => $a->decided_by,
            'is_manual' => $a->is_manual,
            'confirmed' => $a->confirmed_at !== null,
            'reason' => $a->reason,
            'note' => $a->note,
            // Income design v3: the invoice a line pays (and its number, with the line's invoice loaded), the client
            // stored on the line itself (only where its invoice and job name none), and the client it counts for, worked
            // out as IncomeClient does (with the line's invoice, job and client loaded; null otherwise). A form sends
            // back what it loaded, so editing a split never drops a link.
            'invoice_id' => $a->invoice_id,
            'invoice_number' => $a->relationLoaded('invoice') ? $a->invoice?->number : null,
            'client_id' => $a->client_id,
            'client' => self::clientOf($a),
        ];
    }

    /**
     * The name of the client a line counts for (its invoice's, else its own job's, else its own; a merged client as
     * the one it went into), from what's loaded on the line: with('allocations.invoice.client.mergedInto',
     * 'allocations.job.client.mergedInto', 'allocations.client.mergedInto'). Null when they aren't loaded or none is.
     */
    private static function clientOf(Allocation $a): ?string
    {
        foreach (['invoice', 'job'] as $via) {
            $id = $via === 'invoice' ? $a->invoice_id : $a->work_job_id;
            if ($id === null) {
                continue;
            }
            if (! $a->relationLoaded($via)) {
                return null;
            }
            $owner = $a->{$via};
            if ($owner?->client_id !== null) {
                return $owner->relationLoaded('client') ? self::named($owner->client) : null;
            }
        }
        if ($a->client_id === null) {
            return null;
        }

        return $a->relationLoaded('client') ? self::named($a->client) : null;
    }

    /** A client's name, or the name of the client it was merged into (when that's loaded). */
    private static function named(?Client $client): ?string
    {
        if ($client === null) {
            return null;
        }
        if ($client->merged_into_id !== null && $client->relationLoaded('mergedInto') && $client->mergedInto !== null) {
            return $client->mergedInto->name;
        }

        return $client->name;
    }

    /** What a page loads with a payment's lines for allocation() to give their invoice and client. */
    public const ALLOCATION_LINKS = ['allocations.invoice.client.mergedInto', 'allocations.job.client.mergedInto', 'allocations.client.mergedInto'];

    public static function job(WorkJob $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'client' => $job->relationLoaded('client') ? $job->client?->name : null,
            'client_id' => $job->client_id,
            'starts_on' => $job->starts_on->format('Y-m-d'),
            'ends_on' => $job->ends_on->format('Y-m-d'),
            'start_time' => $job->start_time ? substr($job->start_time, 0, 5) : null,
            'end_time' => $job->end_time ? substr($job->end_time, 0, 5) : null,
            'location' => $job->location,
            'postcode' => $job->postcode,
            'fee_pence' => $job->fee_pence,
            'po_number' => $job->po_number,
            'po_not_required' => (bool) $job->po_not_required,
            'status' => $job->status,
            'notes' => $job->notes,
            // Who the work was for when another client pays (an agency's job for a production company), the rate and
            // how it's counted, the days agreed, when it was marked done, and what a cancelled job still owes.
            'end_client_id' => $job->end_client_id,
            'end_client' => $job->relationLoaded('endClient') ? $job->endClient?->name : null,
            'rate_pence' => $job->rate_pence,
            'rate_unit' => $job->rate_unit,
            'days_agreed' => $job->days_agreed,
            'done_at' => $job->done_at?->format('Y-m-d H:i'),
            'cancel_charge' => $job->cancel_charge,
            'cancel_fee_pence' => $job->cancel_fee_pence,
        ];
    }

    /** Lists the decision pickers need on every page that decides things. */
    public static function lookups(): array
    {
        $categories = Category::query()->with('parent')->orderBy('sort')->get();

        return [
            'kinds' => collect(Allocation::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'box' => $c->hmrc_box,
                'group' => $c->parent?->name ?? $c->name, 'is_top' => $c->parent_id === null,
                'allowable' => $c->is_allowable, 'capital' => $c->is_capital,
            ])->values()->all(),
            'jobs' => WorkJob::query()->with('client')->orderByDesc('starts_on')->limit(500)->get()
                ->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d')])->all(),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name', 'kind', 'provider'])->toArray(),
            // For the client picker on money in (merged duplicates left out: a name you merged finds the one it went into).
            'clients' => Client::query()->unmerged()->orderBy('name')->get(['id', 'name'])->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ];
    }

    /**
     * Jobs working on (or a day either side of) a date: likely homes for a transaction. By the job's own days, so the
     * days between a rig and a de-rig don't offer it.
     */
    public static function jobsNear(string $date): array
    {
        return WorkJob::query()->with(['client', 'days'])
            ->whereDate('starts_on', '<=', date('Y-m-d', strtotime($date.' +1 day')))
            ->whereDate('ends_on', '>=', date('Y-m-d', strtotime($date.' -1 day')))
            ->orderBy('starts_on')->limit(20)->get()
            ->filter(fn (WorkJob $j) => $j->isNear($date))->take(5)
            ->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name])->values()->all();
    }
}
