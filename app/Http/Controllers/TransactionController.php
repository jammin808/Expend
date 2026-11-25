<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarSync;
use App\Domain\Charges\CostCharges;
use App\Domain\Clients\ClientIds;
use App\Domain\Income\IncomeClient;
use App\Domain\Income\IncomeTargets;
use App\Domain\Income\IncomeText;
use App\Domain\Income\PayerAliases;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoList;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Document;
use App\Models\JobFind;
use App\Models\ManualExpense;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Income design v3: who money in was for (the worked-out client, merges resolved), and confirmed turnover
            // not assigned to any client, job or invoice yet.
            'client' => 'nullable|integer', 'unassigned' => 'nullable|boolean',
        ]);
        $client = isset($f['client']) ? ClientIds::resolve((int) $f['client']) : null;

        $query = BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job', ...Present::ALLOCATION_LINKS])
            ->when($f['year'] ?? null, fn ($q, $v) => $q->where('tax_year', $v))
            ->when($f['account'] ?? null, fn ($q, $v) => $q->where('account_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('amount_pence', $v === 'in' ? '>' : '<', 0))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('description', 'like', "%{$v}%")->orWhere('counterparty', 'like', "%{$v}%")->orWhere('merchant_key', 'like', '%'.strtoupper($v).'%')))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('kind', $v)))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('category_id', $v)))
            ->when($f['decided_by'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('decided_by', $v)))
            ->when(($f['job'] ?? null) === 'none', fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('kind', 'business_expense')->whereNull('work_job_id')))
            ->when(is_numeric($f['job'] ?? null), fn ($q) => $q->whereHas('allocations', fn ($a) => $a->where('work_job_id', (int) $f['job'])))
            ->when($client !== null, fn ($q) => $q->whereIn('id', self::paymentsWhere(fn ($a) => $a->whereRaw(IncomeClient::expression().' = ?', [$client]))))
            ->when((bool) ($f['unassigned'] ?? false), fn ($q) => $q->whereIn('id', self::paymentsWhere(fn ($a) => $a->where('allocations.kind', 'business_income')
                ->whereNotNull('allocations.confirmed_at')->whereNull('allocations.work_job_id')->whereNull('allocations.invoice_id')->whereRaw(IncomeClient::expression().' IS NULL'))));

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

        $manual = ManualExpense::query()->with(['allocations.category', 'allocations.job', ...Present::ALLOCATION_LINKS])
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

    /**
     * The payments with a line matching $where, its worked-out client to hand (IncomeClient's joins), as one column for
     * a whereIn.
     */
    private static function paymentsWhere(callable $where): QueryBuilder
    {
        $lines = IncomeClient::scope(Allocation::query()->select('allocations.bank_transaction_id'))->whereNotNull('allocations.bank_transaction_id');
        $where($lines);

        return DB::query()->fromSub($lines, 'tx_lines')->select('tx_lines.bank_transaction_id');
    }

    /**
     * Money in, for the drawer's "What is this payment for?": who the payer usually pays for ("ACME PAYROLL · usually
     * Acme Events (3 payments)", or "pays for several clients"), and the client to start from (the line's, else the
     * payer's sole client).
     *
     * @return array{payer:?string, client_id:?int, client:?string, shared:bool, payslip_like:bool, deduction_note:string}
     */
    private static function income(BankTransaction|ManualExpense $item): array
    {
        $key = $item->merchant_key;
        $aliases = PayerAliases::forKeys([$key]);
        $sole = $aliases->sole($key, 1);
        $shared = $aliases->shared($key);
        $own = $item->allocations->map(fn (Allocation $a) => IncomeClient::of($a))->filter()->unique()->values();
        $start = $own->count() === 1 ? (int) $own->first() : $sole;
        $names = Client::query()->whereKey(array_filter([$sole, $start]))->pluck('name', 'id');
        $who = $item instanceof BankTransaction ? ($item->counterparty ?: $item->description) : $item->description;

        return [
            'payer' => $shared ? $key.' · pays for several clients'
                : ($sole !== null ? $key.' · usually '.($names[$sole] ?? 'one client').' ('.$aliases->count($key, $sole).' '.($aliases->count($key, $sole) === 1 ? 'payment' : 'payments').')' : null),
            'client_id' => $start, 'client' => $start !== null ? ($names[$start] ?? null) : null, 'shared' => $shared,
            'payslip_like' => IncomeText::isPayslipLike($who, $key),
            'deduction_note' => IncomeText::DEDUCTION_NOTE,
        ];
    }

    /**
     * A check of the line (verification.*, by the checker) in plain words for its history: what the check found, as
     * the Checks page words it, with its summary as the reason. Nothing for any other event.
     *
     * @return array{label?:string, reason?:?string}
     */
    private static function checkWords(AuditEvent $e): array
    {
        if (! str_starts_with($e->action, 'verification.')) {
            return [];
        }
        $status = substr($e->action, strlen('verification.'));

        return ['label' => Verification::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)), 'reason' => $e->reason ?? ($e->after['summary'] ?? null)];
    }

    /** Detail for the side panel: history and what was on that day. */
    public function show(string $source, int $id): JsonResponse
    {
        $item = $source === 'manual'
            ? ManualExpense::query()->with(['allocations.category', 'allocations.job', ...Present::ALLOCATION_LINKS])->findOrFail($id)
            : BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job', 'batch', ...Present::ALLOCATION_LINKS])->findOrFail($id);

        $history = AuditEvent::query()->where('subject_type', class_basename($item))->where('subject_id', $item->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditEvent $e) => self::checkWords($e)
                + ['at' => $e->occurred_at->format('Y-m-d H:i'), 'actor' => $e->actor, 'action' => $e->action, 'reason' => $e->reason, 'after' => $e->after]);

        $date = $item instanceof BankTransaction ? $item->effectiveDate()->format('Y-m-d') : $item->spent_on->format('Y-m-d');
        $moneyIn = $item->amount_pence > 0;

        return response()->json([
            'item' => $item instanceof BankTransaction ? Present::tx($item) + ['import' => $item->batch?->original_filename, 'raw' => $item->raw] : Present::manual($item),
            'history' => $history,
            'events' => CalendarSync::eventsOn($date),
            // Money in is put on a client, a job or invoices with the chips and "Still owed by" (income design v3), so
            // "Jobs around then" is for money out.
            'jobs_near' => $moneyIn ? [] : Present::jobsNear($date),
            'income' => $moneyIn ? self::income($item) : null,
            'targets' => $item instanceof BankTransaction ? IncomeTargets::for($item) : [],
            // Your decisions about its costs that no longer apply (it moved to another job), shown on the payment.
            'charges_gone' => CostCharges::gone(($item instanceof BankTransaction ? 'tx:' : 'exp:').$item->id)
                ->map(fn (CostCharge $c) => ['ref' => $c->cost_ref, 'charge' => $c->charge, 'note' => $c->note])->values(),
            'links' => self::links($item),
            // What the why-panels explain: the line's decision, and the calendar entries that day on a job.
            'why' => ['decision' => ($item instanceof BankTransaction ? 'tx:' : 'exp:').$item->id, 'calendar' => self::calendarLinksOn($date)],
        ]);
    }

    /**
     * Calendar entries on a UK day that are on a job, by you or automatically: each with the ref its why-panel explains.
     * Timed entries are kept in UTC, so the day runs from UK midnight; an all-day entry is kept at its date's midnight.
     *
     * @return list<array{ref:string, summary:?string, job:?string, linked_by:?string}>
     */
    private static function calendarLinksOn(string $date): array
    {
        $day = CarbonImmutable::parse(substr($date, 0, 10), CalendarEvent::TZ)->startOfDay();

        return CalendarJobLink::query()->with('job:id,title')->whereNotNull('work_job_id')->where('not_job', false)
            ->where(fn ($q) => $q->whereBetween('event_starts_at', [$day->utc()->format('Y-m-d H:i:s'), $day->addDay()->subSecond()->utc()->format('Y-m-d H:i:s')])
                ->orWhere('event_starts_at', $day->format('Y-m-d 00:00:00')))
            ->orderBy('event_starts_at')->limit(10)->get()
            ->map(fn (CalendarJobLink $l) => ['ref' => 'cal:'.$l->event_key, 'summary' => $l->summary, 'job' => $l->job?->title, 'linked_by' => $l->linked_by])->all();
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
            // A cost added on the phone: its photo is its receipt.
            foreach (ReceiptMatch::ownedBy($item) as $d) {
                $out[] = self::phoneReceipt($d);
            }

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
        // Receipts read from email or uploads for this amount, dated within a few days of it (Intel\ReceiptMatch: the
        // same rule the receipt detectors use, so the page and the to-do list always agree).
        foreach (ReceiptMatch::forPayment($item) as $d) {
            if (ReceiptMatch::owner($d) !== null) {
                $out[] = self::phoneReceipt($d);

                continue;
            }
            $out[] = ['type' => 'receipt', 'label' => 'Receipt: '.($d->extraction['receipt']['merchant'] ?? $d->email_subject ?? $d->original_name), 'detail' => 'Same amount'.(($d->extraction['receipt']['date'] ?? null) ? ', '.CarbonImmutable::parse($d->extraction['receipt']['date'])->format('j M Y') : ''),
                'href' => ReceiptMatch::href($d)];
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

    /**
     * Your receipt photo from the phone, as a link on the item it belongs to: on a bank line, with "Not this payment's
     * receipt" (detach_href); on an expense you added, with its entry page.
     *
     * @return array<string, mixed>
     */
    private static function phoneReceipt(Document $d): array
    {
        $entry = ReceiptMatch::owner($d);

        // What the drawer offers on it: an expense added from the phone can go, or have been the business card.
        $actions = $entry && $entry->status === 'added' ? Fingerprints::attach([
            TodoList::action('qx', $entry->id.':cancel', 'Remove this expense'), TodoList::action('qx', $entry->id.':to_card', 'It was the business card'),
        ]) : [];

        return ['type' => 'receipt', 'label' => 'Your receipt photo'.($entry?->what ? ': '.$entry->what : ''), 'actions' => $actions,
            'detail' => trim(($entry?->spent_on?->format('j M Y') ?? '').($entry?->attached_by === 'matcher' ? ' · matched by amount and date' : ($entry?->attached_by === 'you' ? ' · you chose this payment' : '')), ' ·'),
            'href' => $entry ? '/quick/entries/'.$entry->id : ReceiptMatch::href($d), 'document_id' => $d->id, 'file' => '/documents/'.$d->id.'/file', 'quick_id' => $entry?->id,
            'detach_href' => $entry && $entry->status === 'attached' ? '/quick/entries/'.$entry->id.'/detach' : null];
    }
}
