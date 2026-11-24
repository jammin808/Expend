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
        // Money moving between your own accounts is shown, but it isn't spending or income, so it's totalled apart,
        // line by line: a currency exchange split into a £1,000 transfer and its £5 fee is £5 spent and £1,000 moved.
        $sums = OwnAccounts::lineTotals((clone $query)->reorder());

        $manual = ManualExpense::query()->with(['allocations.category', 'allocations.job'])
            ->when($f['year'] ?? null, fn ($q, $v) => $q->where('tax_year', $v))->orderByDesc('spent_on')->limit(100)->get();

        return Inertia::render('transactions/index', [
            'page' => [
                'data' => collect($page->items())->map(fn ($tx) => Present::tx($tx))->values(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            ],
            'totals' => $sums,
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
        // Receipts read from email or uploads for this amount, dated within a few days of it. A receipt is often
        // uploaded or forwarded long after the payment (at the month's end, at tax time), so it's found by its
        // amount and the date on it, whenever it arrived; one with no date on it by when it arrived.
        // A receipt's amount is in hundredths of its currency (as read); the line's other currencies (the account's
        // own, and the one the shop charged in) are kept in each currency's own units, so they're put in hundredths.
        $amounts = array_values(array_unique(array_filter([abs($item->amount_pence),
            $item->original_amount_minor ? Money::toHundredths(abs($item->original_amount_minor), $item->original_currency) : null,
            $item->merchant_amount_minor ? Money::toHundredths(abs((int) $item->merchant_amount_minor), $item->merchant_currency) : null])));
        $date = CarbonImmutable::parse($item->effectiveDate()->format('Y-m-d'));
        $receipts = Document::query()->where('kind', 'receipt')->whereNotNull('extraction')
            ->whereIn('extraction->receipt->amount_minor', array_merge($amounts, array_map('strval', $amounts)))
            ->where(fn ($q) => $q->whereNull('received_at')->orWhere('received_at', '>=', $date->subDays(10)->format('Y-m-d 00:00:00')))
            ->orderBy('received_at')->orderBy('id')->limit(200)->get(['id', 'parent_id', 'original_name', 'email_subject', 'extraction', 'received_at'])
            ->filter(function (Document $d) use ($amounts, $date) {
                $r = $d->extraction['receipt'] ?? null;
                if (! $r || ! in_array((int) ($r['amount_minor'] ?? 0), $amounts, true)) {
                    return false;
                }
                $on = $r['date'] ?? null;
                if ($on) {
                    try {
                        return abs(CarbonImmutable::parse($on)->startOfDay()->diffInDays($date)) <= 4;
                    } catch (\Throwable) {
                        return false; // a date that can't be read isn't a match
                    }
                }

                return ! $d->received_at || abs(CarbonImmutable::parse($d->received_at->format('Y-m-d'))->diffInDays($date)) <= 10;
            });
        foreach ($receipts as $d) {
            $out[] = ['type' => 'receipt', 'label' => 'Receipt: '.($d->extraction['receipt']['merchant'] ?? $d->email_subject ?? $d->original_name), 'detail' => 'Same amount'.(($d->extraction['receipt']['date'] ?? null) ? ', '.CarbonImmutable::parse($d->extraction['receipt']['date'])->format('j M Y') : ''),
                'href' => '/documents/'.($d->parent_id ?? $d->id)];
        }
        // What a job's search found it for, as things stand now (as the job page shows it): not once it's on a job
        // (that job is listed above), nor once you've decided it as something a job can't take.
        $onAJob = $item->allocations->contains(fn ($a) => $a->work_job_id !== null);
        $decidedOtherwise = $item->allocations->contains(fn ($a) => $a->confirmed_at !== null || $a->is_manual)
            && ! $item->allocations->contains(fn ($a) => in_array($a->kind, ['business_expense', 'business_income'], true));
        foreach ($onAJob || $decidedOtherwise ? [] : JobFind::query()->with('job')->whereIn('type', ['payment', 'income'])->where('ref', (string) $item->id)->whereIn('status', ['possible', 'linked'])->get() as $f) {
            if ($f->job && ! collect($out)->contains(fn ($o) => $o['type'] === 'job' && $o['href'] === '/jobs/'.$f->job->id)) {
                $out[] = ['type' => 'job', 'label' => 'May be for '.$f->job->title, 'detail' => $f->detail, 'href' => '/jobs/'.$f->job->id];
            }
        }

        return $out;
    }
}
