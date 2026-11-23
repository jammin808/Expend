<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarSync;
use App\Domain\Matching\OwnAccounts;
use App\Http\Presenters\Present;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Support\Money;
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
        ]);
    }
}
