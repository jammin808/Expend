<?php

namespace App\Domain\Todo;

use App\Domain\Gmail\GmailClient;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\PoStatus;
use App\Domain\Ledger\Reconciler;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Tax\PenaltyEstimator;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything that needs you, in one list, most urgent first: a reconnect, a held statement, unpaid invoices, failed
 * checks, a filing deadline, invoices to send, possible jobs, payments and stays to decide, jobs with no PO, payments
 * missing from your statements and documents held for approval. Each item carries one-tap actions (TodoActions),
 * used by the to-do page and by Telegram buttons.
 *
 * Building it reads most of the ledger, so the last list is kept (see cached()) for the dashboard, and built afresh
 * as soon as anything it's made from changes: a change made by this process is seen as it's written (watch()), one
 * made elsewhere (the queue, the scheduler) by the data's fingerprint (stamp()). The menu badge on every other page
 * shows the last list's total for a couple of minutes after a change (see badge()), so deciding payment after payment
 * doesn't build the list again each time.
 */
final class TodoList
{
    public const GROUPS = [
        'gmail' => 'Gmail', 'imports' => 'Statements held', 'income' => 'Unpaid invoices', 'revenue' => 'Money you may be owed', 'checks' => 'Failed checks', 'deadline' => 'Tax return', 'penalties' => 'Fines and interest',
        'invoice' => 'Invoices to send', 'jobs' => 'Possible jobs', 'transfers' => 'Transfers between your accounts', 'payments' => 'Payments to decide', 'rules' => 'Make it automatic', 'duplicates' => 'Possible duplicate charges', 'stays' => 'Stays and travel to decide',
        'no_po' => 'Jobs with no PO', 'reconcile' => 'Accounts that don’t match the bank', 'missing' => 'Payments missing from statements', 'documents' => 'Documents held',
    ];

    /** Where the last list is kept. */
    public const CACHE_KEY = 'todo_list';

    /** The list's total. A to-do action forgets it (forget()), which also makes the kept list be built afresh. */
    public const TOTAL_KEY = 'todo_total';

    /** The last list's total, for the menu badge (see badge()). */
    public const BADGE_KEY = 'todo_badge';

    /** The longest a kept list is used for, even if nothing it's made from seems to have changed. */
    private const KEEP_SECONDS = 1800;

    /** How long the menu badge may show the last list's total after something it's made from changed. */
    private const BADGE_SECONDS = 120;

    /** At least this many of each kind are kept, so the dashboard, the menu badge and the to-do page share one list. */
    private const KEEP_PER_GROUP = 15;

    /** The tables the list is made from (directly, or through the rule offers, PO status and bank checks). */
    private const WATCHED = [
        'accounts', 'allocations', 'bank_transactions', 'calendar_job_links', 'categories', 'clients', 'document_facts', 'documents', 'import_batches',
        'invoices', 'job_candidates', 'manual_expenses', 'merchant_rules', 'statement_balances', 'tax_years', 'travel_items', 'verifications', 'work_jobs',
    ];

    /** The settings it reads: the Gmail connection, and merchants you said no to a rule for. */
    private const WATCHED_SETTINGS = ['google_client_id', 'google_client_secret', 'gmail_refresh_token', 'gmail_last_result', 'rule_offers_declined'];

    /** This process has written to what the list is made from since it last built it (see watch()). */
    private static bool $changed = false;

    /**
     * The list, built now. Counts and the total cover everything (less what you snoozed or marked not relevant),
     * whatever $perGroup is; only the items shown are limited to the first $perGroup of each kind.
     *
     * @return array{items:list<array>, counts:array<string,int>, total:int}
     */
    public static function build(int $perGroup = 15): array
    {
        self::watch();
        $hidden = TodoDismissal::activeKeys();
        $stamp = self::stamp($hidden);
        $list = self::compute(max($perGroup, self::KEEP_PER_GROUP), $hidden);
        self::keep($list, $stamp);

        return self::cut($list, $perGroup);
    }

    /**
     * The list as last built, when nothing it's made from has changed since (no payment, decision, invoice, job,
     * document, check, statement, rule or snooze added, changed or removed, the date is the same, and no to-do action
     * has run); otherwise built now. For the dashboard, and the menu badge once its last total is too old (badge()).
     *
     * @return array{items:list<array>, counts:array<string,int>, total:int}
     */
    public static function cached(int $perGroup = 15): array
    {
        self::watch();
        $hidden = TodoDismissal::activeKeys();
        $stamp = self::stamp($hidden);
        $kept = self::$changed ? null : Cache::get(self::CACHE_KEY);
        if (is_array($kept) && ($kept['stamp'] ?? null) === $stamp && ($kept['per_group'] ?? 0) >= $perGroup && Cache::get(self::TOTAL_KEY) === ($kept['total'] ?? null)) {
            return self::cut($kept, $perGroup);
        }
        $list = self::compute(max($perGroup, self::KEEP_PER_GROUP), $hidden);
        self::keep($list, $stamp);

        return self::cut($list, $perGroup);
    }

    /**
     * The total for the menu badge: the last list's, if it was built in the last couple of minutes (on this day),
     * whatever has changed since; otherwise the list's as it is now (cached()). The dashboard and the to-do page build
     * the list afresh when it's changed, so the badge on them always agrees with them; on other pages it catches up
     * within a couple of minutes, as it always has, rather than the list being built again after every decision.
     */
    public static function badge(): int
    {
        $last = Cache::get(self::BADGE_KEY);
        if (is_array($last) && ($last['day'] ?? null) === today()->format('Y-m-d') && is_int($last['total'] ?? null)) {
            return $last['total'];
        }

        return self::cached(1)['total'];
    }

    /** Make the next cached() build the list afresh. (The menu badge may show the last total a little longer.) */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TOTAL_KEY);
    }

    /**
     * Notice this process's own writes to what the list is made from, however they're made (a decision saved, an
     * invoice emailed, a document approved), so the kept list isn't used after one, even within the same second
     * (which the fingerprint can't tell apart). Call settle() once the work is done, so other requests know too.
     */
    public static function watch(): void
    {
        if (app()->bound(self::class.'.watching')) {
            return;
        }
        app()->instance(self::class.'.watching', true);
        DB::listen(function (QueryExecuted $query) {
            if (! self::$changed && self::writesTo($query->sql, $query->bindings)) {
                self::$changed = true;
            }
        });
    }

    /** After a request: if it changed what the list is made from, the kept list is forgotten. */
    public static function settle(): void
    {
        if (self::$changed) {
            self::$changed = false;
            self::forget();
        }
    }

    /**
     * To-dos that come back under the same key every time they apply again: Gmail needing reconnecting, a tax return
     * falling due or overdue, transfers to confirm. "Not relevant" hides a key for good, so it would hide every later
     * one too (the next disconnection, the overdue return). These can only be snoozed. A new item whose key never
     * changes belongs here too.
     */
    public static function snoozeOnly(string $key): bool
    {
        return in_array($key, ['gmail', 'transfers'], true) || str_starts_with($key, 'deadline:') || str_starts_with($key, 'penalty:');
    }

    public static function action(string $code, int|string $id, string $label, string $style = 'default'): array
    {
        return ['code' => $code, 'id' => (string) $id, 'label' => $label, 'style' => $style];
    }

    /**
     * What has come in for each invoice, in the invoice's own currency (see InvoicePayments::received()).
     *
     * @param  iterable<Invoice>  $invoices
     * @return array<int, array{paid:int, elsewhere:int, waiting:int}> by invoice id
     */
    public static function received(iterable $invoices): array
    {
        return InvoicePayments::received($invoices);
    }

    /**
     * @param  array<string, true>  $hidden  keys you snoozed or marked not relevant
     * @return array{items:list<array>, counts:array<string,int>, total:int, per_group:int} every item, most urgent first
     */
    private static function compute(int $limit, array $hidden): array
    {
        self::$changed = false; // what's been written so far is in this list
        $items = [];
        $add = function (string $group, int $priority, string $key, string $title, ?string $detail, ?string $href, array $actions = [], array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'group' => $group, 'priority' => $priority, 'title' => $title, 'detail' => $detail, 'href' => $href, 'actions' => $actions,
                'snooze_only' => self::snoozeOnly($key)] + $extra;
        };

        // Gmail access ended (Google's 7-day limit in Testing).
        if (GmailClient::configured() && ! GmailClient::connected() && (Setting::get('gmail_last_result')['ok'] ?? true) === false) {
            $add('gmail', 1, 'gmail', 'Gmail needs reconnecting', 'Google ended the connection, so searches and the ✓ Invoiced label have stopped.', '/settings/integrations#gmail');
        }

        // Statements held because the balance didn't add up.
        $held = ImportBatch::query()->where('status', 'quarantined')->with('account')->get();
        foreach ($held as $b) {
            $add('imports', 1, 'import:'.$b->id, 'Statement held: '.$b->original_filename, ($b->account?->name ?? '').': the running balance doesn’t add up. Accept it with a reason or discard it.', '/imports');
        }

        // Issued invoices past their due date or never emailed, with what has come in for each (see received()).
        $open = Invoice::query()->where('status', 'issued')
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere(fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', today())))
            ->orderBy('due_date')->orderBy('id')->get();
        $received = self::received($open);

        // Past their due date and not paid in full.
        // The one-tap reminder is only offered when nothing at all has come in: its email tells the client so.
        foreach ($open->filter(fn (Invoice $i) => $i->due_date && $i->due_date->lt(today())) as $i) {
            ['paid' => $paid, 'elsewhere' => $elsewhere, 'waiting' => $waiting] = $received[$i->id];
            if ($paid >= $i->total_minor) {
                continue;
            }
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $due = 'due '.$i->due_date->format('j M Y').' ('.(int) $i->due_date->diffInDays(today()).' days ago)';
            $client = $i->bill_to_name ?: 'the client';
            $inbox = $waiting > 0 ? ' '.Money::format($waiting).' that may be for it is waiting in the Inbox: decide it first.' : '';
            $extra = ['amount' => $i->total_minor - $paid, 'currency' => $i->currency ?: 'GBP'];
            if ($elsewhere > 0) {
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number}: check it’s been paid",
                    $money($i->total_minor).' from '.$client.', '.$due.'. '.Money::format($elsewhere).' came in for this job in another currency, so Expend can’t tell how much of the invoice it covers. If it’s all paid, mark this not relevant.'.$inbox,
                    '/invoices/'.$i->id, [], $extra);
            } elseif ($paid > 0) {
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} part-paid",
                    $money($paid).' of '.$money($i->total_minor).' received from '.$client.', '.$due.'; '.$money($i->total_minor - $paid).' still to come.'.$inbox, '/invoices/'.$i->id, [], $extra);
            } else {
                // A reminder that went in the last day isn't offered again (the button would only say so).
                $reminded = $i->reminded_at && $i->reminded_at->gt(now()->subHours(InvoiceMailer::REPEAT_HOURS));
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} unpaid", $money($i->total_minor).' from '.$client.', '.$due.'.'
                    .($reminded ? ' Reminder sent '.$i->reminded_at->format('j M Y, H:i').'.' : '').$inbox,
                    $waiting > 0 ? '/inbox' : '/invoices/'.$i->id, array_values(array_filter([$i->bill_to_email && $waiting <= 0 && ! $reminded ? self::action('inv_chase', $i->id, 'Send a reminder') : null])), $extra);
            }
        }

        // Money you may be owed: an invoice for less than its PO, and costs to recharge to the client.
        foreach (Invoice::query()->whereIn('status', ['issued', 'draft'])->whereNotNull('po_total_minor')->whereColumn('total_minor', '<', 'po_total_minor')->get() as $i) {
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $add('revenue', 1, 'underbilled:'.$i->id, ($i->number ? "Invoice {$i->number}" : 'Draft invoice').' is '.$money($i->po_total_minor - $i->total_minor).' less than its PO',
                'The PO authorises '.$money($i->po_total_minor).' and the invoice totals '.$money($i->total_minor).'. Check nothing was left off'.($i->status === 'issued' ? ' (an issued invoice can be voided and re-issued).' : '.'),
                '/invoices/'.$i->id, [], ['amount' => $i->po_total_minor - $i->total_minor, 'currency' => $i->currency ?: 'GBP']);
        }
        $recharges = TravelItem::query()->where('decision', 'recharge')->whereNotNull('work_job_id')->with('job')->get();
        $onInvoice = Invoice::query()->whereIn('work_job_id', $recharges->pluck('work_job_id')->unique()->values())->whereIn('status', ['issued', 'paid'])
            ->orderByDesc('issue_date')->orderByDesc('id')->get()->unique('work_job_id')->keyBy('work_job_id');
        foreach ($recharges as $t) {
            $invoice = $onInvoice[$t->work_job_id] ?? null;
            $add('revenue', 1, 'recharge:'.$t->id, 'Recharge '.$t->provider.' to the client', (TravelItem::KINDS[$t->kind] ?? 'Booking').' '.$t->starts_on->format('j M Y').' for '.$t->job?->title
                .($invoice ? ": check it's on invoice {$invoice->number}." : ': add it to the invoice when you draft it.'), $invoice ? '/invoices/'.$invoice->id : '/jobs/'.$t->work_job_id, [],
                ['amount' => $t->amount_minor, 'currency' => $t->currency ?: 'GBP']);
        }

        // AI findings that didn't stand up to the double-check, about something that's still there.
        $failed = Verification::query()->whereIn('status', ['mismatch', 'partly', 'source_missing', 'second_read_disagreed'])->orderByDesc('checked_at')->get();
        $subjects = self::subjects($failed);
        foreach ($failed as $v) {
            [$label, $href] = $subjects[$v->subject_type][$v->subject_id] ?? [null, null];
            if ($label === null) {
                continue;
            }
            $add('checks', 1, 'check:'.$v->subject_type.':'.$v->subject_id, $label, $v->summary, $href, [self::action('recheck', $v->subject_type.':'.$v->subject_id, 'Re-check')]);
        }

        // A return due within 60 days. One you filed and unlocked to amend isn't late: it can be amended until a year
        // after the filing deadline, so that's the date shown as it gets close.
        // Both dates are whole days: a return is on time until the end of its deadline day.
        foreach (TaxYear::query()->where('status', '!=', 'filed')->get() as $year) {
            if ($year->status === 'amending') {
                $by = $year->period()->filingDeadline()->addYear();
                if (today()->lte($by) && $by->lte(now()->addDays(60))) {
                    $add('deadline', 1, 'deadline:'.$year->label.':amend', "Tax return {$year->label}: amend by ".$by->format('j F Y'),
                        'You unlocked it to make changes. HMRC accepts an amended return online until then; lock the year again once it’s sent.', '/years/'.$year->label);
                }

                continue;
            }
            $deadline = $year->period()->filingDeadline();
            if ($deadline->lte(now()->addDays(60))) {
                $add('deadline', 1, 'deadline:'.$year->label, "Tax return {$year->label}: ".(today()->gt($deadline) ? 'overdue since ' : 'due ').$deadline->format('j F Y'),
                    'Work through the year, then copy the figures into HMRC online.', '/years/'.$year->label);
            }
        }

        // Fines and interest: penalties already due or still growing, or the next one coming within 60 days.
        try {
            $fines = PenaltyEstimator::all()['years'];
        } catch (Throwable $e) {
            report($e);
            $fines = [];
        }
        foreach ($fines as $y) {
            $owed = $y['penalties_pence'] + $y['interest_pence'];
            $soon = collect($y['upcoming'])->first(fn ($p) => $p['date'] <= now()->addDays(60)->format('Y-m-d'));
            if ($owed <= 0 && ! $soon) {
                continue;
            }
            $detail = implode(' ', array_filter([
                $owed > 0 ? Money::format($y['penalties_pence'], 'GBP', false).' in penalties and '.Money::format($y['interest_pence'], 'GBP', false).' interest so far'
                    .($y['per_day_pence'] > 0 ? ', growing by about '.Money::format($y['per_day_pence'], 'GBP', false).' a day' : '').'.' : null,
                $soon ? 'Next: '.$soon['label'].' on '.CarbonImmutable::parse($soon['date'])->format('j F Y').' ('.Money::format($soon['amount_pence'], 'GBP', false).').' : null,
                $y['actions'][0] ?? null,
                'An estimate from your records.',
            ]));
            $add('penalties', 1, 'penalty:'.$y['tax_year'], $owed > 0 ? "Fines and interest for {$y['tax_year']}: ".Money::format($owed, 'GBP', false) : "Avoid a fine for {$y['tax_year']}",
                $detail, '/years/'.$y['tax_year'], [], ['amount' => -($owed > 0 ? $owed : $soon['amount_pence'])]);
        }

        // Finished jobs with no invoice issued (as PoStatus counts it: an issued or paid invoice for the job).
        $finished = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->whereDate('ends_on', '<', today())->orderByDesc('ends_on')->limit(300)->get();
        $invoiced = Invoice::query()->whereIn('work_job_id', $finished->pluck('id'))->whereIn('status', ['issued', 'paid'])->distinct()->pluck('work_job_id')->flip();
        $drafts = Invoice::query()->where('status', 'draft')->whereIn('work_job_id', $finished->pluck('id'))->pluck('id', 'work_job_id');
        foreach ($finished->filter(fn (WorkJob $j) => ! isset($invoiced[$j->id])) as $j) {
            $draft = $drafts[$j->id] ?? null;
            $add('invoice', 2, 'invoice:'.$j->id, 'Invoice '.$j->title, ($j->client?->name ? $j->client->name.' · ' : '').'finished '.$j->ends_on->format('j M Y').($draft ? ' · draft ready to check and issue' : ''),
                $draft ? '/invoices/'.$draft : '/jobs/'.$j->id, $draft ? [] : [self::action('job_invoice', $j->id, 'Draft the invoice')]);
        }

        // Issued but never emailed, and not paid in full (a client who's paid it all has had the invoice; one who's
        // paid a deposit still needs it). Left until you've decided a payment waiting in the Inbox that may be for it.
        $unsent = $open->whereNull('sent_at')->sortBy(fn (Invoice $i) => [$i->issue_date?->format('Y-m-d') ?? '', $i->id])
            ->filter(fn (Invoice $i) => $received[$i->id]['paid'] < $i->total_minor && $received[$i->id]['waiting'] <= 0);
        foreach ($unsent as $i) {
            ['paid' => $paid, 'elsewhere' => $elsewhere] = $received[$i->id];
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $add('invoice', 2, 'unsent:'.$i->id, "Send invoice {$i->number}", $money($i->total_minor).' to '.($i->bill_to_name ?: 'the client').($i->bill_to_email ? ' at '.$i->bill_to_email : ': add their email address on the invoice')
                .($paid > 0 ? ' · '.$money($paid).' already received' : '')
                .($elsewhere > 0 ? ' · '.Money::format($elsewhere).' came in for this job in another currency: if that paid it, mark this not relevant' : ''),
                '/invoices/'.$i->id, $i->bill_to_email ? [self::action('inv_send', $i->id, 'Email it')] : [], ['amount' => $i->total_minor - $paid, 'currency' => $i->currency ?: 'GBP']);
        }

        // Possible jobs from the job finder.
        $candidates = JobCandidate::query()->where('status', 'open')->orderBy('starts_on')->get();
        foreach ($candidates as $c) {
            $add('jobs', 2, 'candidate:'.$c->id, 'Possible job: '.$c->title, $c->starts_on->format('j M Y').($c->client_name ? ' · '.$c->client_name : '').' · from '.count($c->sources).' '.(count($c->sources) === 1 ? 'source' : 'sources'),
                '/connections?year='.UkTaxYear::fromDate($c->starts_on)->label(), [self::action('cand_add', $c->id, 'Add as a job'), self::action('cand_dismiss', $c->id, 'Not a job', 'ghost')]);
        }

        // Money moving between your own accounts, suggested by the matcher: confirm them all in one go.
        $moves = self::suggestedTransfers();
        if ($moves['count'] > 0) {
            $add('transfers', 2, 'transfers', 'Confirm '.$moves['count'].' '.($moves['count'] === 1 ? 'transfer' : 'transfers').' between your accounts',
                Money::format($moves['pence']).' moved between your own accounts (card bills, top-ups, savings, your own name). They aren’t spending or income, so they don’t change your tax.',
                '/transactions?kind=transfer&status=suggested', [self::action('transfers_ok', 0, 'Confirm all')]);
        }

        // Payments to decide: suggestions first (one tap to accept), then the rest. Ones you snoozed are left out
        // before the first few are picked, so the next ones show instead, and the count is of what's left.
        $hiddenTx = array_map(fn ($k) => (int) substr($k, 3), array_values(array_filter(array_keys($hidden), fn ($k) => str_starts_with($k, 'tx:'))));
        $waiting = fn () => BankTransaction::query()->whereIn('review_status', ['suggested', 'unreviewed'])->whereNotIn('id', $hiddenTx)
            ->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'));
        $txs = $waiting()->with(['allocations.category', 'allocations.job'])
            ->orderByRaw("CASE WHEN review_status = 'suggested' THEN 0 ELSE 1 END")->orderByDesc('posted_on')->limit($limit)->get();
        foreach ($txs as $t) {
            $s = $t->allocations->first();
            $suggestion = $s && ! $s->confirmed_at ? (Allocation::KINDS[$s->kind] ?? $s->kind).($s->category ? ': '.$s->category->name : '').($s->job ? ' · '.$s->job->title : '') : null;
            $add('payments', 2, 'tx:'.$t->id, ($t->counterparty ?: $t->description), $t->effectiveDate()->format('j M Y').($suggestion ? ' · suggested: '.$suggestion : ''), '/inbox',
                array_values(array_filter([$suggestion ? self::action('tx_accept', $t->id, 'Accept') : null, self::action('tx_personal', $t->id, 'Personal', 'ghost')])), ['amount' => $t->amount_pence]);
        }
        $payments = $txs->count() < $limit ? $txs->count() : $waiting()->count();

        // The same amount to the same merchant twice in a day (£20 or more): charged twice?
        $dupes = OwnAccounts::scopeNotTransfers(BankTransaction::query())->where('amount_pence', '<=', -2000)->whereNull('transfer_pair_id')
            ->select('account_id', 'merchant_key', 'amount_pence', 'posted_on')->selectRaw('COUNT(*) as n, MIN(id) as first_id')
            ->groupBy('account_id', 'merchant_key', 'amount_pence', 'posted_on')->havingRaw('COUNT(*) > 1')->orderByDesc('posted_on')->limit(50)->get();
        $firsts = BankTransaction::query()->whereIn('id', $dupes->pluck('first_id'))->get()->keyBy('id');
        foreach ($dupes as $d) {
            if (! ($tx = $firsts[$d->first_id] ?? null)) {
                continue;
            }
            $add('duplicates', 2, 'dupe:'.$d->first_id, 'Charged twice? '.($tx->counterparty ?: $tx->description), $d->n.' payments of '.Money::format(abs($d->amount_pence)).' on '.$tx->posted_on->format('j M Y').' from the same account. If one was a mistake, ask for a refund; if both were real, snooze this.',
                '/transactions?year='.$tx->tax_year.'&q='.urlencode($tx->counterparty ?: $tx->description), [], ['amount' => $d->amount_pence * $d->n]);
        }

        // Merchants you always decide the same way: make it automatic. All of them, so the count doesn't depend on
        // how many are shown, and the next ones show once you snooze the first.
        foreach (app(RuleLearner::class)->offers(PHP_INT_MAX) as $o) {
            $add('rules', 2, 'rule:'.$o['merchant_key'], 'Always do this for '.$o['name'].'?', $o['text'], '/settings/rules',
                [self::action('rule_make', $o['example_id'], 'Always do this'), self::action('rule_no', $o['example_id'], 'No', 'ghost')]);
        }

        // Stays and journeys to decide.
        $stays = TravelItem::query()->whereNull('decided_at')->where('status', '!=', 'cancelled')->whereNotNull('suggestion')->orderBy('starts_on')->get()
            ->filter(fn (TravelItem $i) => in_array($i->suggestion['decision'] ?? null, ['business', 'personal', 'mixed', 'client_booked', 'review'], true));
        foreach ($stays as $i) {
            $decision = $i->suggestion['decision'];
            $add('stays', 2, 'stay:'.$i->id, (TravelItem::KINDS[$i->kind] ?? $i->kind).': '.($i->kind === 'hotel' ? ($i->place ?: $i->provider) : $i->provider),
                $i->starts_on->format('j M Y').' · suggested: '.(TravelItem::DECISIONS[$decision] ?? 'check it'), '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(),
                array_values(array_filter([$decision !== 'review' ? self::action('stay_accept', $i->id, 'Accept') : null, self::action('stay_personal', $i->id, 'Personal', 'ghost')])),
                ['amount' => $i->amount_minor ? -$i->amount_minor : null, 'currency' => $i->currency ?: 'GBP']);
        }

        // Jobs with no PO.
        foreach (self::withoutPo(WorkJob::query()->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->limit(300)->get()) as $j) {
            $add('no_po', 2, 'no_po:'.$j->id, 'No PO: '.$j->title, $j->starts_on->format('j M Y').'. Link the PO email or attachment, add the number, or mark it not needed.', '/jobs/'.$j->id,
                [self::action('job_no_po', $j->id, 'No PO needed', 'ghost')]);
        }

        // Accounts that don't match the bank's own figures, and statements not imported for a while.
        foreach (Account::query()->where('is_active', true)->get() as $acc) {
            $r = Reconciler::account($acc);
            $currency = $r['currency'] ?? 'GBP';
            foreach ($r['gaps'] as $g) {
                $add('reconcile', 2, 'gap:'.$acc->id.':'.$g['from'].':'.$g['missing_pence'], $acc->name.': '.Money::format(abs($g['missing_pence']), $currency).' missing', $g['text'].' Import the statement for those dates (or the missing rows); if they’re already in another account, check the right account was chosen.',
                    '/imports', [], ['amount' => $g['missing_pence'], 'currency' => $currency]);
            }
            if ($r['stale']) {
                $add('reconcile', 3, 'stale:'.$acc->id.':'.$r['last_line'], $acc->name.': nothing imported since '.CarbonImmutable::parse($r['last_line'])->format('j M Y'),
                    'Import its latest statement, or choose “Hide from checks” on Accounts if it’s closed.', '/imports');
            }
        }

        // Paid bookings with no payment in your statements.
        $unpaid = TravelItem::query()->whereNull('bank_transaction_id')->whereNotNull('amount_minor')->where('status', '!=', 'cancelled')
            ->whereIn('payment', ['paid', 'pay_at_property'])->where(fn ($q) => $q->whereNull('decision')->orWhereNotIn('decision', ['client_booked', 'personal']))
            ->whereDate('starts_on', '<', now()->subDays(10))->orderByDesc('starts_on')->get();
        foreach ($unpaid as $i) {
            $add('missing', 3, 'missing:'.$i->id, 'No payment found: '.$i->provider, $i->starts_on->format('j M Y').'. The booking says it was paid; import the card or account it was paid with, or add it as a manual expense.',
                '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(), [], ['amount' => -$i->amount_minor, 'currency' => $i->currency ?: 'GBP']);
        }

        // Documents held because the sender isn't on your list.
        $docs = Document::query()->where('status', 'held')->whereNull('parent_id')->latest()->get();
        foreach ($docs as $d) {
            $add('documents', 3, 'doc:'.$d->id, 'Held: '.($d->email_subject ?: $d->original_name), 'From '.($d->email_from ?? 'an unknown sender').'. Approve it to read it.', '/documents/'.$d->id,
                [self::action('doc_approve', $d->id, 'Approve')]);
        }

        // Hide what you snoozed or marked not relevant, count what's left, most urgent first.
        $items = array_values(array_filter($items, fn ($i) => ! isset($hidden[$i['key']])));
        $counts = array_count_values(array_column($items, 'group'));
        $counts['payments'] = $payments; // only the first few are listed
        $counts = array_filter($counts);
        $order = array_flip(array_keys(self::GROUPS));
        usort($items, fn ($a, $b) => [$a['priority'], $order[$a['group']]] <=> [$b['priority'], $order[$b['group']]]);

        return ['items' => $items, 'counts' => $counts, 'total' => array_sum($counts), 'per_group' => $limit];
    }

    /**
     * The jobs among these that have no PO (see PoStatus). Only a job with no PO number typed and not marked "no PO
     * needed" can be missing one, so only those are looked up.
     *
     * @param  Collection<int, WorkJob>  $jobs
     * @return Collection<int, WorkJob>
     */
    public static function withoutPo(Collection $jobs): Collection
    {
        $candidates = $jobs->filter(fn (WorkJob $j) => ! $j->po_not_required && ! filled($j->po_number) && $j->status !== 'cancelled');
        $missing = array_filter(PoStatus::forJobs($candidates), fn ($s) => $s['missing']);

        return $candidates->filter(fn (WorkJob $j) => isset($missing[$j->id]))->values();
    }

    /** The first $perGroup items of each kind, with the counts and total of everything. */
    private static function cut(array $list, int $perGroup): array
    {
        $seen = [];
        $items = array_values(array_filter($list['items'], function ($i) use (&$seen, $perGroup) {
            $seen[$i['group']] = ($seen[$i['group']] ?? 0) + 1;

            return $seen[$i['group']] <= $perGroup;
        }));

        return ['items' => $items, 'counts' => $list['counts'], 'total' => $list['total']];
    }

    private static function keep(array $list, string $stamp): void
    {
        Cache::put(self::CACHE_KEY, $list + ['stamp' => $stamp], self::KEEP_SECONDS);
        Cache::put(self::TOTAL_KEY, $list['total'], self::KEEP_SECONDS);
        Cache::put(self::BADGE_KEY, ['total' => $list['total'], 'day' => today()->format('Y-m-d')], self::BADGE_SECONDS);
    }

    /**
     * A fingerprint of everything the list is made from: today's date (items fall due, snoozes end), what's hidden
     * now, this code, and for each table how many rows it has, its newest row and its latest change. One query.
     *
     * @param  array<string, true>  $hidden
     */
    private static function stamp(array $hidden): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $parts = array_map(fn (string $t) => 'SELECT '.DB::getPdo()->quote($t).' AS t, COUNT(*) AS n, MAX('.$grammar->wrap('id').') AS i, MAX('.$grammar->wrap('updated_at').') AS u FROM '.$grammar->wrapTable($t), self::WATCHED);
        $parts[] = 'SELECT '.DB::getPdo()->quote('settings').', COUNT(*), NULL, MAX('.$grammar->wrap('updated_at').') FROM '.$grammar->wrapTable('settings')
            .' WHERE '.$grammar->wrap('key').' IN ('.implode(', ', array_fill(0, count(self::WATCHED_SETTINGS), '?')).')';
        $rows = DB::select(implode(' UNION ALL ', $parts), self::WATCHED_SETTINGS);

        return md5(json_encode([today()->format('Y-m-d'), array_keys($hidden), filemtime(__FILE__), $rows]));
    }

    /** Whether a query adds, changes or removes rows the list is made from. */
    private static function writesTo(string $sql, array $bindings): bool
    {
        if (! preg_match('/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete\s+from)\s+[`"\[]?(\w+)/i', $sql, $m)) {
            return false;
        }
        $prefix = DB::getTablePrefix();
        $table = $prefix !== '' && str_starts_with($m[1], $prefix) ? substr($m[1], strlen($prefix)) : $m[1];
        if ($table === 'settings') {
            return array_intersect(array_filter($bindings, 'is_string'), self::WATCHED_SETTINGS) !== [];
        }

        return in_array($table, self::WATCHED, true);
    }

    /**
     * Labels and links for what failed checks were about, loaded together; a check whose subject has gone (or, for
     * a calendar link, no longer links the entry to a job) has none, so it isn't listed.
     *
     * @param  Collection<int, Verification>  $checks
     * @return array<string, array<int, array{0:string, 1:string}>> by subject type and id
     */
    private static function subjects(Collection $checks): array
    {
        $out = [];
        $load = function (string $type, callable $query, callable $label) use ($checks, &$out) {
            $ids = $checks->where('subject_type', $type)->pluck('subject_id')->unique()->values();
            if ($ids->isNotEmpty()) {
                foreach ($query($ids) as $row) {
                    $out[$type][$row->id] = $label($row);
                }
            }
        };
        $load('document', fn ($ids) => Document::query()->whereIn('id', $ids)->get(['id', 'email_subject', 'original_name']),
            fn (Document $d) => ['Check: '.($d->email_subject ?: $d->original_name), '/documents/'.$d->id]);
        $load('travel_item', fn ($ids) => TravelItem::query()->whereIn('id', $ids)->get(['id', 'provider', 'starts_on']),
            fn (TravelItem $t) => ['Check: '.$t->provider.' '.$t->starts_on->format('j M'), '/travel?year='.UkTaxYear::fromDate($t->starts_on)->label()]);
        $load('transaction', fn ($ids) => BankTransaction::query()->whereIn('id', $ids)->get(['id', 'counterparty', 'description']),
            fn (BankTransaction $t) => ['Check the suggestion: '.($t->counterparty ?: $t->description), '/inbox']);
        $load('job_candidate', fn ($ids) => JobCandidate::query()->whereIn('id', $ids)->where('status', 'open')->get(['id']),
            fn () => ['Check a possible job', '/jobs/finder']);
        // Unlinking an entry, or removing its link, ends the check: only links that still put an entry on a job count.
        $load('calendar_link', fn ($ids) => CalendarJobLink::query()->whereIn('id', $ids)->whereNotNull('work_job_id')->where('not_job', false)->get(['id', 'summary']),
            fn (CalendarJobLink $l) => ['Check a calendar link'.($l->summary ? ': '.$l->summary : ''), '/jobs/finder']);

        return $out;
    }

    /** Transfers the matcher suggested and you haven't confirmed. @return array{count:int, pence:int, ids:list<int>} */
    public static function suggestedTransfers(): array
    {
        $txs = BankTransaction::query()->where('review_status', 'suggested')
            ->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where('kind', '!=', 'transfer'))
            ->whereNotIn('tax_year', TaxYear::query()->whereNotNull('locked_at')->pluck('label'))
            ->get(['id', 'amount_pence']);

        return ['count' => $txs->count(), 'pence' => (int) $txs->where('amount_pence', '<', 0)->sum(fn ($t) => -$t->amount_pence), 'ids' => $txs->pluck('id')->all()];
    }
}
