<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarSync;
use App\Domain\Matching\OwnAccounts;
use App\Http\Presenters\Present;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\JobFind;
use App\Models\ManualExpense;
use App\Models\TravelItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'year' => 'nullable|string|max:7', 'account' => 'nullable|integer', 'status' => 'nullable|in:unreviewed,suggested,confirmed',
            'kind' => 'nullable|string', 'category' => 'nullable|integer', 'job' => 'nullable|string', 'q' => 'nullable|string|max:100',
            'direction' => 'nullable|in:in,out', 'min' => 'nullable|string', 'max' => 'nullable|string', 'decided_by' => 'nullable|in:you,rule,matcher,ai',
            'sort' => 'nullable|in:date,amount', 'page' => 'nullable|integer',
        ]);

        $query = BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job'])
            ->when($f['year'] ?? null, fn ($q, $v) => $q->where('tax_year', $v))
            ->when($f['account'] ?? null, fn ($q, $v) => $q->where('account_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('amount_pence', $v === 'in' ? '>' : '<', 0))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('description', 'like', "%{$v}%")->orWhere('counterparty', 'like', "%{$v}%")->orWhere('merchant_key', 'like', '%'.strtoupper($v).'%')))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('kind', $v)))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('category_id', $v)))
            ->when($f['decided_by'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('decided_by', $v)))
            ->when(($f['job'] ?? null) === 'none', fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('kind', 'business_expense')->whereNull('work_job_id')))
            ->when(is_numeric($f['job'] ?? null), fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('work_job_id', (int) $f['job'])));

        foreach (['min' => '>=', 'max' => '<='] as $key => $op) {
            if (! empty($f[$key])) {
                $query->whereRaw('ABS(amount_pence) '.$op.' ?', [abs(Money::parse($f[$key]) ?? 0)]);
            }
        }

        ($f['sort'] ?? 'date') === 'amount'
            ? $query->orderByRaw('ABS(amount_pence) DESC')
            : $query->orderByDesc('posted_on')->orderByDesc('id');

        $page = $query->paginate(50)->withQueryString();
        // Money moving between your own accounts is shown, but it isn't spending or income, so it's totalled apart.
        $sums = 'COUNT(*) as n, SUM(CASE WHEN amount_pence < 0 THEN amount_pence ELSE 0 END) as out_pence, SUM(CASE WHEN amount_pence > 0 THEN amount_pence ELSE 0 END) as in_pence';
        $count = (clone $query)->reorder()->count();
        $totals = OwnAccounts::scopeNotTransfers((clone $query)->reorder())->selectRaw($sums)->first();
        $moved = OwnAccounts::scopeTransfers((clone $query)->reorder())->selectRaw($sums)->first();

        $manual = ManualExpense::query()->with(['allocations.category', 'allocations.job'])
            ->when($f['year'] ?? null, fn ($q, $v) => $q->where('tax_year', $v))->orderByDesc('spent_on')->limit(100)->get();

        return Inertia::render('transactions/index', [
            'page' => [
                'data' => collect($page->items())->map(fn ($tx) => Present::tx($tx))->values(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            ],
            'totals' => ['count' => $count, 'out_pence' => (int) $totals->out_pence, 'in_pence' => (int) $totals->in_pence,
                'transfer_count' => (int) $moved->n, 'transfer_out_pence' => (int) $moved->out_pence, 'transfer_in_pence' => (int) $moved->in_pence],
            'manual' => $manual->map(fn ($e) => Present::manual($e))->values(),
            'filters' => $f,
            'lookups' => Present::lookups(),
        ]);
    }

    /** Detail for the side panel: history and what was on that day. */
    public function show(string $source, int $id): JsonResponse
    {
        $item = $source === 'manual'
            ? ManualExpense::query()->with(['allocations.category', 'allocations.job'])->findOrFail($id)
            : BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job', 'batch'])->findOrFail($id);

        $history = AuditEvent::query()->where('subject_type', class_basename($item))->where('subject_id', $item->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditEvent $e) => ['at' => $e->occurred_at->format('Y-m-d H:i'), 'actor' => $e->actor, 'action' => $e->action, 'reason' => $e->reason, 'after' => $e->after]);

        $date = $item instanceof BankTransaction ? $item->effectiveDate()->format('Y-m-d') : $item->spent_on->format('Y-m-d');

        return response()->json([
            'item' => $item instanceof BankTransaction ? Present::tx($item) + ['import' => $item->batch?->original_filename, 'raw' => $item->raw] : Present::manual($item),
            'history' => $history,
            'events' => CalendarSync::eventsOn($date),
            'jobs_near' => Present::jobsNear($date),
            'links' => self::links($item),
        ]);
    }

    /**
     * Everything this payment is connected to: its jobs, invoice, stay or journey, receipts, the other side of a
     * transfer, the rule that decided it, and anything a job's search found it for.
     *
     * @return list<array{type:string, label:string, detail:?string, href:?string}>
     */
    public static function links(BankTransaction|ManualExpense $item): array
    {
        $out = [];
        $item->loadMissing(['allocations.job', 'allocations.invoice', 'allocations.rule']);
        foreach ($item->allocations->pluck('job')->filter()->unique('id') as $job) {
            $out[] = ['type' => 'job', 'label' => $job->title, 'detail' => $job->starts_on->format('j M Y').($job->ends_on->ne($job->starts_on) ? ' – '.$job->ends_on->format('j M Y') : ''), 'href' => '/jobs/'.$job->id];
        }
        foreach ($item->allocations->pluck('invoice')->filter()->unique('id') as $inv) {
            $out[] = ['type' => 'invoice', 'label' => 'Invoice '.($inv->number ?? 'draft'), 'detail' => Money::format($inv->total_minor, $inv->currency ?? 'GBP'), 'href' => '/invoices/'.$inv->id];
        }
        foreach ($item->allocations->pluck('rule')->filter()->unique('id') as $rule) {
            $out[] = ['type' => 'rule', 'label' => 'Your rule for '.$rule->merchant_key, 'detail' => $rule->auto_confirm ? 'Confirms on its own' : 'Suggests', 'href' => '/settings/rules'];
        }
        if (! $item instanceof BankTransaction) {
            return $out;
        }
        foreach (TravelItem::query()->where('bank_transaction_id', $item->id)->get() as $t) {
            $out[] = ['type' => 'travel', 'label' => (TravelItem::KINDS[$t->kind] ?? $t->kind).': '.($t->kind === 'hotel' ? ($t->place ?: $t->provider) : $t->provider),
                'detail' => $t->starts_on->format('j M Y').($t->decision ? ' · '.(TravelItem::DECISIONS[$t->decision] ?? $t->decision) : ''), 'href' => '/travel?year='.$item->tax_year];
        }
        if ($item->transfer_pair_id && ($pair = BankTransaction::query()->with('account')->find($item->transfer_pair_id))) {
            $out[] = ['type' => 'transfer', 'label' => 'Other side: '.($pair->account?->name ?? 'another account'), 'detail' => $pair->effectiveDate()->format('j M Y').' · '.Money::format($pair->amount_pence),
                'href' => '/transactions?account='.$pair->account_id.'&q='.urlencode($pair->counterparty ?: $pair->description)];
        }
        // Receipts read from email or uploads for this amount, within a few days.
        $amounts = array_values(array_filter([abs($item->amount_pence), $item->original_amount_minor ? abs($item->original_amount_minor) : null]));
        $date = $item->effectiveDate();
        $receipts = Document::query()->where('kind', 'receipt')->whereNotNull('extraction')
            ->where(fn ($q) => $q->whereNull('received_at')->orWhereBetween('received_at', [$date->copy()->subDays(10)->format('Y-m-d 00:00:00'), $date->copy()->addDays(10)->format('Y-m-d 23:59:59')]))
            ->limit(200)->get(['id', 'parent_id', 'original_name', 'email_subject', 'extraction', 'received_at'])
            ->filter(function (Document $d) use ($amounts, $date) {
                $r = $d->extraction['receipt'] ?? null;
                $on = $r['date'] ?? null;

                return $r && in_array((int) ($r['amount_minor'] ?? 0), $amounts, true) && (! $on || abs(CarbonImmutable::parse($on)->diffInDays($date)) <= 4);
            });
        foreach ($receipts as $d) {
            $out[] = ['type' => 'receipt', 'label' => 'Receipt: '.($d->extraction['receipt']['merchant'] ?? $d->email_subject ?? $d->original_name), 'detail' => 'Same amount'.(($d->extraction['receipt']['date'] ?? null) ? ', '.CarbonImmutable::parse($d->extraction['receipt']['date'])->format('j M Y') : ''),
                'href' => '/documents/'.($d->parent_id ?? $d->id)];
        }
        foreach (JobFind::query()->with('job')->whereIn('type', ['payment', 'income'])->where('ref', (string) $item->id)->whereIn('status', ['possible', 'linked'])->get() as $f) {
            if ($f->job && ! collect($out)->contains(fn ($o) => $o['type'] === 'job' && $o['href'] === '/jobs/'.$f->job->id)) {
                $out[] = ['type' => 'job', 'label' => 'May be for '.$f->job->title, 'detail' => $f->detail, 'href' => '/jobs/'.$f->job->id];
            }
        }

        return $out;
    }
}
