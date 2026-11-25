<?php

namespace App\Http\Controllers;

use App\Domain\Clients\ClientIds;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobBilling;
use App\Domain\Jobs\JobCreator;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What the pickers for money in search, read only (income design v3): jobs by title, venue, PO number or client
 * (JobCombobox), what a client still owes you ("Still owed by Acme Events" in the drawer) and open invoices by number
 * or amount ("+ Another invoice"). Each answers JSON with a fixed number of queries, however many rows there are.
 */
class LookupController extends Controller
{
    /** How many jobs the job search gives. */
    private const JOBS = 30;

    /** How many invoices the invoice search gives. */
    private const INVOICES = 20;

    /**
     * Jobs matching what you typed (title, venue, PO number, or the paying or end client's name), not limited by date.
     * For income (for=income) with a client, that client's finished jobs with nothing billed come first ("Still owed
     * by Acme Events"); the rest are nearest the payment's date (on) first, cancelled jobs last and marked.
     */
    public function jobs(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => 'nullable|string|max:100', 'client' => 'nullable|integer', 'for' => 'nullable|in:income,cost', 'on' => 'nullable|date']);
        $q = trim((string) ($f['q'] ?? ''));
        $on = isset($f['on']) ? CarbonImmutable::parse($f['on'])->format('Y-m-d') : null;
        $client = isset($f['client']) ? ClientIds::resolve((int) $f['client']) : null;

        $base = WorkJob::query()->with(['client:id,name,merged_into_id', 'endClient:id,name'])
            ->when($q !== '', fn (Builder $query) => $query->where(function (Builder $w) use ($q) {
                $like = '%'.$q.'%';
                $w->where('title', 'like', $like)->orWhere('location', 'like', $like)->orWhere('po_number', 'like', $like)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', $like))
                    ->orWhereHas('endClient', fn (Builder $c) => $c->where('name', 'like', $like));
            }));
        if ($on !== null) {
            // The nearest each side of the day, then nearest first: two queries, whatever the database.
            $before = (clone $base)->whereDate('starts_on', '<=', $on)->orderByDesc('starts_on')->orderByDesc('id')->limit(self::JOBS)->get();
            $after = (clone $base)->whereDate('starts_on', '>', $on)->orderBy('starts_on')->orderBy('id')->limit(self::JOBS)->get();
            $day = CarbonImmutable::parse($on);
            $jobs = $before->concat($after)->sortBy(fn (WorkJob $j) => [$j->status === 'cancelled' ? 1 : 0,
                min(abs($day->diffInDays($j->starts_on, false)), abs($day->diffInDays($j->ends_on, false)))])->values();
        } else {
            $jobs = $base->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")->orderByDesc('starts_on')->orderByDesc('id')->limit(self::JOBS)->get();
        }

        $owed = collect();
        if (($f['for'] ?? null) === 'income' && $client !== null) {
            $owed = JobBilling::owed($client, 10)->load(['client:id,name,merged_into_id', 'endClient:id,name']);
            if ($q !== '') {
                $needle = mb_strtolower($q);
                $owed = $owed->filter(fn (WorkJob $j) => str_contains(mb_strtolower($j->title.' '.$j->location.' '.$j->po_number), $needle))->values();
            }
        }
        $ownedIds = $owed->pluck('id')->flip();

        return response()->json([
            'owed' => $owed->map(fn (WorkJob $j) => self::job($j) + ['owed' => true])->values(),
            'jobs' => $jobs->reject(fn (WorkJob $j) => $ownedIds->has($j->id))->take(self::JOBS)->map(fn (WorkJob $j) => self::job($j))->values(),
            'owed_label' => $owed->isNotEmpty() ? 'Still owed by '.Client::query()->whereKey($client)->value('name') : null,
        ]);
    }

    /**
     * What a client still owes you: their open invoices (number, job, what's still to come), issued invoices with no
     * client whose "Bill to" is their name, and their finished jobs with nothing billed.
     */
    public function owed(Request $request): JsonResponse
    {
        $f = $request->validate(['client' => 'required|integer|exists:clients,id'], [
            'client.required' => 'Pick the client first.', 'client.exists' => 'That client no longer exists: pick another.',
        ]);
        $id = (int) ClientIds::resolve((int) $f['client']);
        $client = Client::query()->findOrFail($id);
        $ids = ClientIds::withMerged($id);
        $key = JobCreator::clientKey($client->name);

        $named = Invoice::query()->with('job:id,title,client_id')->where('status', 'issued')
            ->where(fn (Builder $w) => $w->whereIn('client_id', $ids)
                ->orWhere(fn (Builder $n) => $n->whereNull('client_id')->whereHas('job', fn (Builder $j) => $j->whereIn('client_id', $ids))))
            ->orderBy('issue_date')->orderBy('id')->limit(200)->get();
        // Issued with no client at all (none on it or its job), billed to this client's name.
        $byName = $key === '' ? collect() : Invoice::query()->with('job:id,title,client_id')->where('status', 'issued')->whereNull('client_id')
            ->where(fn (Builder $w) => $w->whereNull('work_job_id')->orWhereHas('job', fn (Builder $j) => $j->whereNull('client_id')))
            ->whereNotNull('bill_to_name')->orderBy('issue_date')->orderBy('id')->limit(500)->get()
            ->filter(fn (Invoice $i) => JobCreator::clientKey($i->bill_to_name) === $key)->values();

        return response()->json([
            'client' => ['id' => $client->id, 'name' => $client->name],
            'invoices' => self::open($named->concat($byName)->unique('id')->values()),
            'jobs' => JobBilling::owed($id)->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'),
                'ends_on' => $j->ends_on->format('Y-m-d'), 'fee_pence' => $j->fee_pence, 'label' => $j->title.' · '.$j->ends_on->format('j M').' · nothing billed'])->values(),
            'label' => 'Still owed by '.$client->name,
        ]);
    }

    /** Open invoices (issued, with something still to come) by number, or by their total or what's still to come. */
    public function invoices(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => 'required|string|max:50'], ['q.required' => 'Type an invoice number or an amount.']);
        $q = trim($f['q']);
        $amount = preg_match('/^[£€$]?\s*\d[\d,]*(\.\d{1,2})?$/u', $q) ? Money::parse(preg_replace('/[£€$\s]/u', '', $q)) : null;

        $found = Invoice::query()->with('job:id,title,client_id')->where('status', 'issued')
            ->where(function (Builder $w) use ($q, $amount) {
                $w->where('number', 'like', '%'.$q.'%');
                if ($amount !== null && $amount > 0) {
                    $w->orWhere('total_minor', $amount)->orWhere('total_minor', '>', $amount); // what's still to come is checked below
                }
            })->orderByDesc('issue_date')->orderByDesc('id')->limit(300)->get();
        $rows = collect(self::open($found));
        if ($amount !== null && $amount > 0) {
            $rows = $rows->filter(fn (array $r) => str_contains(mb_strtolower((string) $r['number']), mb_strtolower($q))
                || $r['total_minor'] === $amount || $r['outstanding_minor'] === $amount);
        }

        return response()->json(['invoices' => $rows->take(self::INVOICES)->values()]);
    }

    /** @return array<string, mixed> a job as the pickers show it */
    private static function job(WorkJob $j): array
    {
        return ['id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'client_id' => $j->client_id, 'end_client' => $j->endClient?->name,
            'location' => $j->location, 'po_number' => $j->po_number, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'),
            'status' => $j->status, 'cancelled' => $j->status === 'cancelled'];
    }

    /**
     * Invoices with something still to come, as the drawer lists them, oldest first.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array<string, mixed>>
     */
    private static function open(Collection $invoices): array
    {
        $received = InvoicePayments::received($invoices);
        $clients = ClientIds::resolveMany($invoices->map(fn (Invoice $i) => $i->client_id ?? $i->job?->client_id));
        $names = Client::query()->whereKey(array_values($clients) ?: [0])->pluck('name', 'id');

        return $invoices->map(function (Invoice $i) use ($received, $clients, $names) {
            $r = $received[$i->id] ?? InvoicePayments::EMPTY;
            $raw = $i->client_id ?? $i->job?->client_id;
            $client = $raw === null ? null : ($clients[(int) $raw] ?? (int) $raw);

            return $r['outstanding'] > 0 ? ['id' => $i->id, 'number' => $i->number, 'job' => $i->job?->title, 'work_job_id' => $i->work_job_id,
                'client_id' => $client, 'client' => $client !== null ? ($names[$client] ?? null) : $i->bill_to_name, 'bill_to_name' => $i->bill_to_name,
                'currency' => InvoicePayments::currencyOf($i), 'total_minor' => (int) $i->total_minor, 'outstanding_minor' => (int) $r['outstanding'],
                'state' => $r['state'], 'issue_date' => $i->issue_date?->format('Y-m-d'), 'due_date' => $i->due_date?->format('Y-m-d')] : null;
        })->filter()->values()->all();
    }
}
