<?php

namespace App\Domain\Todo;

use App\Domain\Gmail\GmailClient;
use App\Domain\Jobs\PoStatus;
use App\Domain\Ledger\Reconciler;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\OwnAccounts;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
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

/**
 * Everything that needs you, in one list, most urgent first: a reconnect, a held statement, unpaid invoices, failed
 * checks, a filing deadline, invoices to send, possible jobs, payments and stays to decide, jobs with no PO, payments
 * missing from your statements and documents held for approval. Each item carries one-tap actions (TodoActions),
 * used by the to-do page and by Telegram buttons.
 */
final class TodoList
{
    public const GROUPS = [
        'gmail' => 'Gmail', 'imports' => 'Statements held', 'income' => 'Unpaid invoices', 'revenue' => 'Money you may be owed', 'checks' => 'Failed checks', 'deadline' => 'Tax return',
        'invoice' => 'Invoices to send', 'jobs' => 'Possible jobs', 'transfers' => 'Transfers between your accounts', 'payments' => 'Payments to decide', 'rules' => 'Make it automatic', 'duplicates' => 'Possible duplicate charges', 'stays' => 'Stays and travel to decide',
        'no_po' => 'Jobs with no PO', 'reconcile' => 'Accounts that don’t match the bank', 'missing' => 'Payments missing from statements', 'documents' => 'Documents held',
    ];

    /**
     * @return array{items:list<array>, counts:array<string,int>, total:int}
     */
    public static function build(int $perGroup = 15): array
    {
        $items = [];
        $counts = [];
        $add = function (string $group, int $priority, string $key, string $title, ?string $detail, ?string $href, array $actions = [], array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'group' => $group, 'priority' => $priority, 'title' => $title, 'detail' => $detail, 'href' => $href, 'actions' => $actions] + $extra;
        };

        // Gmail access ended (Google's 7-day limit in Testing).
        if (GmailClient::configured() && ! GmailClient::connected() && (Setting::get('gmail_last_result')['ok'] ?? true) === false) {
            $add('gmail', 1, 'gmail', 'Gmail needs reconnecting', 'Google ended the connection, so searches and the ✓ Invoiced label have stopped.', '/settings/integrations#gmail');
            $counts['gmail'] = 1;
        }

        // Statements held because the balance didn't add up.
        $held = ImportBatch::query()->where('status', 'quarantined')->with('account')->get();
        foreach ($held as $b) {
            $add('imports', 1, 'import:'.$b->id, 'Statement held: '.$b->original_filename, ($b->account?->name ?? '').': the running balance doesn’t add up. Accept it with a reason or discard it.', '/imports');
        }

        // Invoices past their due date with no payment linked to the job.
        $overdue = Invoice::query()->where('status', 'issued')->whereNotNull('due_date')->whereDate('due_date', '<', today())->orderBy('due_date')->get()
            ->filter(fn (Invoice $i) => $i->paidMinor() < $i->total_minor);
        foreach ($overdue as $i) {
            $paid = $i->paidMinor();
            $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} ".($paid > 0 ? 'part-paid' : 'unpaid'), ($paid > 0 ? Money::format($paid).' of '.Money::format($i->total_minor).' received' : Money::format($i->total_minor)).' from '.($i->bill_to_name ?: 'the client').', due '.$i->due_date->format('j M Y').' ('.(int) $i->due_date->diffInDays(today()).' days ago).',
                '/invoices/'.$i->id, array_values(array_filter([$i->bill_to_email ? self::action('inv_chase', $i->id, 'Send a reminder') : null])), ['amount' => $i->total_minor]);
        }

        // Money you may be owed: an invoice for less than its PO, and costs to recharge to the client.
        foreach (Invoice::query()->whereIn('status', ['issued', 'draft'])->whereNotNull('po_total_minor')->whereColumn('total_minor', '<', 'po_total_minor')->get() as $i) {
            $add('revenue', 1, 'underbilled:'.$i->id, ($i->number ? "Invoice {$i->number}" : 'Draft invoice').' is '.Money::format($i->po_total_minor - $i->total_minor).' less than its PO',
                'The PO authorises '.Money::format($i->po_total_minor).' and the invoice totals '.Money::format($i->total_minor).'. Check nothing was left off'.($i->status === 'issued' ? ' (an issued invoice can be voided and re-issued).' : '.'),
                '/invoices/'.$i->id, [], ['amount' => $i->po_total_minor - $i->total_minor]);
        }
        foreach (TravelItem::query()->where('decision', 'recharge')->whereNotNull('work_job_id')->with('job')->get() as $t) {
            $invoice = Invoice::query()->where('work_job_id', $t->work_job_id)->whereIn('status', ['issued', 'paid'])->latest('issue_date')->first();
            $add('revenue', 1, 'recharge:'.$t->id, 'Recharge '.$t->provider.' to the client', (TravelItem::KINDS[$t->kind] ?? 'Booking').' '.$t->starts_on->format('j M Y').' for '.$t->job?->title
                .($invoice ? ": check it's on invoice {$invoice->number}." : ': add it to the invoice when you draft it.'), $invoice ? '/invoices/'.$invoice->id : '/jobs/'.$t->work_job_id, [], ['amount' => $t->amount_minor]);
        }

        // AI findings that didn't stand up to the double-check.
        $failed = Verification::query()->whereIn('status', ['mismatch', 'partly', 'source_missing', 'second_read_disagreed'])->orderByDesc('checked_at')->get();
        foreach ($failed as $v) {
            [$label, $href] = self::subject($v);
            if ($label === null) {
                continue;
            }
            $add('checks', 1, 'check:'.$v->subject_type.':'.$v->subject_id, $label, $v->summary, $href, [self::action('recheck', $v->subject_type.':'.$v->subject_id, 'Re-check')]);
        }

        // A return due within 60 days.
        foreach (TaxYear::query()->where('status', '!=', 'filed')->get() as $year) {
            $deadline = $year->period()->filingDeadline();
            if ($deadline->lte(now()->addDays(60))) {
                $add('deadline', 1, 'deadline:'.$year->label, "Tax return {$year->label}: ".($deadline->isPast() ? 'overdue since ' : 'due ').$deadline->format('j F Y'),
                    'Work through the year, then copy the figures into HMRC online.', '/years/'.$year->label);
            }
        }

        // Finished jobs with no invoice issued.
        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->whereDate('ends_on', '<', today())->orderByDesc('ends_on')->limit(300)->get();
        $po = PoStatus::forJobs($jobs->keyBy('id'));
        $drafts = Invoice::query()->where('status', 'draft')->whereIn('work_job_id', $jobs->pluck('id'))->pluck('id', 'work_job_id');
        $toInvoice = $jobs->filter(fn (WorkJob $j) => ! $po[$j->id]['invoice']);
        foreach ($toInvoice as $j) {
            $draft = $drafts[$j->id] ?? null;
            $add('invoice', 2, 'invoice:'.$j->id, 'Invoice '.$j->title, ($j->client?->name ? $j->client->name.' · ' : '').'finished '.$j->ends_on->format('j M Y').($draft ? ' · draft ready to check and issue' : ''),
                $draft ? '/invoices/'.$draft : '/jobs/'.$j->id, $draft ? [] : [self::action('job_invoice', $j->id, 'Draft the invoice')]);
        }

        // Issued but never emailed.
        $unsent = Invoice::query()->where('status', 'issued')->whereNull('sent_at')->orderBy('issue_date')->get()->filter(fn (Invoice $i) => $i->paidMinor() < $i->total_minor);
        foreach ($unsent as $i) {
            $add('invoice', 2, 'unsent:'.$i->id, "Send invoice {$i->number}", Money::format($i->total_minor).' to '.($i->bill_to_name ?: 'the client').($i->bill_to_email ? ' at '.$i->bill_to_email : ': add their email address on the invoice'),
                '/invoices/'.$i->id, $i->bill_to_email ? [self::action('inv_send', $i->id, 'Email it')] : [], ['amount' => $i->total_minor]);
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
            $counts['transfers'] = $moves['count'];
        }

        // Payments to decide: suggestions first (one tap to accept), then the rest.
        $txs = BankTransaction::query()->with(['allocations.category', 'allocations.job'])->whereIn('review_status', ['suggested', 'unreviewed'])
            ->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'))
            ->orderByRaw("CASE WHEN review_status = 'suggested' THEN 0 ELSE 1 END")->orderByDesc('posted_on')->limit($perGroup)->get();
        foreach ($txs as $t) {
            $s = $t->allocations->first();
            $suggestion = $s && ! $s->confirmed_at ? (Allocation::KINDS[$s->kind] ?? $s->kind).($s->category ? ': '.$s->category->name : '').($s->job ? ' · '.$s->job->title : '') : null;
            $add('payments', 2, 'tx:'.$t->id, ($t->counterparty ?: $t->description), $t->effectiveDate()->format('j M Y').($suggestion ? ' · suggested: '.$suggestion : ''), '/inbox',
                array_values(array_filter([$suggestion ? self::action('tx_accept', $t->id, 'Accept') : null, self::action('tx_personal', $t->id, 'Personal', 'ghost')])), ['amount' => $t->amount_pence]);
        }
        $counts['payments'] = BankTransaction::query()->whereIn('review_status', ['suggested', 'unreviewed'])
            ->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'))->count();

        // The same amount to the same merchant twice in a day (£20 or more): charged twice?
        $dupes = OwnAccounts::scopeNotTransfers(BankTransaction::query())->where('amount_pence', '<=', -2000)->whereNull('transfer_pair_id')
            ->select('account_id', 'merchant_key', 'amount_pence', 'posted_on')->selectRaw('COUNT(*) as n, MIN(id) as first_id')
            ->groupBy('account_id', 'merchant_key', 'amount_pence', 'posted_on')->havingRaw('COUNT(*) > 1')->orderByDesc('posted_on')->limit(50)->get();
        foreach ($dupes as $d) {
            $tx = BankTransaction::query()->find($d->first_id);
            $add('duplicates', 2, 'dupe:'.$d->first_id, 'Charged twice? '.($tx->counterparty ?: $tx->description), $d->n.' payments of '.Money::format(abs($d->amount_pence)).' on '.$tx->posted_on->format('j M Y').' from the same account. If one was a mistake, ask for a refund; if both were real, snooze this.',
                '/transactions?year='.$tx->tax_year.'&q='.urlencode($tx->counterparty ?: $tx->description), [], ['amount' => $d->amount_pence * $d->n]);
        }

        // Merchants you always decide the same way: make it automatic.
        $offers = app(RuleLearner::class)->offers($perGroup);
        foreach ($offers as $o) {
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
                ['amount' => $i->amount_minor ? -$i->amount_minor : null]);
        }

        // Jobs with no PO.
        $noPo = WorkJob::query()->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->limit(300)->get();
        $poAll = PoStatus::forJobs($noPo->keyBy('id'));
        $missing = $noPo->filter(fn (WorkJob $j) => $poAll[$j->id]['missing']);
        foreach ($missing as $j) {
            $add('no_po', 2, 'no_po:'.$j->id, 'No PO: '.$j->title, $j->starts_on->format('j M Y').'. Link the PO email or attachment, add the number, or mark it not needed.', '/jobs/'.$j->id,
                [self::action('job_no_po', $j->id, 'No PO needed', 'ghost')]);
        }

        // Accounts that don't match the bank's own figures, and statements not imported for a while.
        $reconcile = 0;
        foreach (Account::query()->where('is_active', true)->get() as $acc) {
            $r = Reconciler::account($acc);
            foreach ($r['gaps'] as $g) {
                $reconcile++;
                $add('reconcile', 2, 'gap:'.$acc->id.':'.$g['from'].':'.$g['missing_pence'], $acc->name.': '.Money::format(abs($g['missing_pence'])).' missing', $g['text'].' Import the statement for those dates (or the missing rows); if they’re already in another account, check the right account was chosen.',
                    '/imports', [], ['amount' => $g['missing_pence']]);
            }
            if ($r['stale']) {
                $reconcile++;
                $add('reconcile', 3, 'stale:'.$acc->id.':'.$r['last_line'], $acc->name.': nothing imported since '.CarbonImmutable::parse($r['last_line'])->format('j M Y'),
                    'Import its latest statement, or choose “Hide from checks” on Accounts if it’s closed.', '/imports');
            }
        }
        $counts['reconcile'] = $reconcile;

        // Paid bookings with no payment in your statements.
        $unpaid = TravelItem::query()->whereNull('bank_transaction_id')->whereNotNull('amount_minor')->where('status', '!=', 'cancelled')
            ->whereIn('payment', ['paid', 'pay_at_property'])->where(fn ($q) => $q->whereNull('decision')->orWhereNotIn('decision', ['client_booked', 'personal']))
            ->whereDate('starts_on', '<', now()->subDays(10))->orderByDesc('starts_on')->get();
        foreach ($unpaid as $i) {
            $add('missing', 3, 'missing:'.$i->id, 'No payment found: '.$i->provider, $i->starts_on->format('j M Y').'. The booking says it was paid; import the card or account it was paid with, or add it as a manual expense.',
                '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(), [], ['amount' => -$i->amount_minor]);
        }

        // Documents held because the sender isn't on your list.
        $docs = Document::query()->where('status', 'held')->whereNull('parent_id')->latest()->get();
        foreach ($docs as $d) {
            $add('documents', 3, 'doc:'.$d->id, 'Held: '.($d->email_subject ?: $d->original_name), 'From '.($d->email_from ?? 'an unknown sender').'. Approve it to read it.', '/documents/'.$d->id,
                [self::action('doc_approve', $d->id, 'Approve')]);
        }

        // Hide what you snoozed or marked not relevant; count what's left; show the first few of each kind.
        $hidden = TodoDismissal::activeKeys();
        $items = array_values(array_filter($items, fn ($i) => ! isset($hidden[$i['key']])));
        $payments = $counts['payments'] ?? 0;
        $counts = array_count_values(array_column($items, 'group'));
        if ($payments) {
            $counts['payments'] = $payments - count(array_filter(array_keys($hidden), fn ($k) => str_starts_with($k, 'tx:')));
        }
        usort($items, fn ($a, $b) => [$a['priority'], array_search($a['group'], array_keys(self::GROUPS), true)] <=> [$b['priority'], array_search($b['group'], array_keys(self::GROUPS), true)]);
        $seen = [];
        $items = array_values(array_filter($items, function ($i) use (&$seen, $perGroup) {
            $seen[$i['group']] = ($seen[$i['group']] ?? 0) + 1;

            return $seen[$i['group']] <= $perGroup;
        }));
        $counts = array_filter($counts);

        return ['items' => $items, 'counts' => $counts, 'total' => array_sum($counts)];
    }

    public static function action(string $code, int|string $id, string $label, string $style = 'default'): array
    {
        return ['code' => $code, 'id' => (string) $id, 'label' => $label, 'style' => $style];
    }

    /** @return array{0:?string,1:?string} a label and link for what a check was about */
    private static function subject(Verification $v): array
    {
        return match ($v->subject_type) {
            'document' => ($d = Document::query()->find($v->subject_id)) ? ['Check: '.($d->email_subject ?: $d->original_name), '/documents/'.$d->id] : [null, null],
            'travel_item' => ($t = TravelItem::query()->find($v->subject_id)) ? ['Check: '.$t->provider.' '.$t->starts_on->format('j M'), '/travel?year='.UkTaxYear::fromDate($t->starts_on)->label()] : [null, null],
            'transaction' => ($t = BankTransaction::query()->find($v->subject_id)) ? ['Check the suggestion: '.($t->counterparty ?: $t->description), '/inbox'] : [null, null],
            'job_candidate' => JobCandidate::query()->whereKey($v->subject_id)->where('status', 'open')->exists() ? ['Check a possible job', '/jobs/finder'] : [null, null],
            'calendar_link' => ['Check a calendar link', '/jobs/finder'],
            default => [null, null],
        };
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
