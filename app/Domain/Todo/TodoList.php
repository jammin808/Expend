<?php

namespace App\Domain\Todo;

use App\Domain\Gmail\GmailClient;
use App\Domain\Jobs\PoStatus;
use App\Domain\Ledger\RuleLearner;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;

/**
 * Everything that needs you, in one list, most urgent first: a reconnect, a held statement, unpaid invoices, failed
 * checks, a filing deadline, invoices to send, possible jobs, payments and stays to decide, jobs with no PO, payments
 * missing from your statements and documents held for approval. Each item carries one-tap actions (TodoActions),
 * used by the to-do page and by Telegram buttons.
 */
final class TodoList
{
    public const GROUPS = [
        'gmail' => 'Gmail', 'imports' => 'Statements held', 'income' => 'Unpaid invoices', 'checks' => 'Failed checks', 'deadline' => 'Tax return',
        'invoice' => 'Invoices to send', 'jobs' => 'Possible jobs', 'payments' => 'Payments to decide', 'rules' => 'Make it automatic', 'stays' => 'Stays and travel to decide',
        'no_po' => 'Jobs with no PO', 'missing' => 'Payments missing from statements', 'documents' => 'Documents held',
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
        $counts['imports'] = $held->count();

        // Invoices past their due date with no payment linked to the job.
        $overdue = Invoice::query()->where('status', 'issued')->whereNotNull('due_date')->whereDate('due_date', '<', today())->orderBy('due_date')->get()
            ->filter(fn (Invoice $i) => $i->paidMinor() < $i->total_minor);
        foreach ($overdue->take($perGroup) as $i) {
            $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} unpaid", Money::format($i->total_minor).' to '.($i->bill_to_name ?: 'the client').', due '.$i->due_date->format('j M Y').' ('.(int) $i->due_date->diffInDays(today()).' days ago).',
                '/invoices/'.$i->id, array_values(array_filter([$i->bill_to_email ? self::action('inv_chase', $i->id, 'Send a reminder') : null])), ['amount' => $i->total_minor]);
        }
        $counts['income'] = $overdue->count();

        // AI findings that didn't stand up to the double-check.
        $failed = Verification::query()->whereIn('status', ['mismatch', 'partly', 'source_missing', 'second_read_disagreed'])->orderByDesc('checked_at')->get();
        foreach ($failed->take($perGroup) as $v) {
            [$label, $href] = self::subject($v);
            if ($label === null) {
                continue;
            }
            $add('checks', 1, 'check:'.$v->subject_type.':'.$v->subject_id, $label, $v->summary, $href, [self::action('recheck', $v->subject_type.':'.$v->subject_id, 'Re-check')]);
        }
        $counts['checks'] = $failed->count();

        // A return due within 60 days.
        foreach (TaxYear::query()->where('status', '!=', 'filed')->get() as $year) {
            $deadline = $year->period()->filingDeadline();
            if ($deadline->lte(now()->addDays(60))) {
                $add('deadline', 1, 'deadline:'.$year->label, "Tax return {$year->label}: ".($deadline->isPast() ? 'overdue since ' : 'due ').$deadline->format('j F Y'),
                    'Work through the year, then copy the figures into HMRC online.', '/years/'.$year->label);
                $counts['deadline'] = ($counts['deadline'] ?? 0) + 1;
            }
        }

        // Finished jobs with no invoice issued.
        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->whereDate('ends_on', '<', today())->orderByDesc('ends_on')->limit(300)->get();
        $po = PoStatus::forJobs($jobs->keyBy('id'));
        $drafts = Invoice::query()->where('status', 'draft')->whereIn('work_job_id', $jobs->pluck('id'))->pluck('id', 'work_job_id');
        $toInvoice = $jobs->filter(fn (WorkJob $j) => ! $po[$j->id]['invoice']);
        foreach ($toInvoice->take($perGroup) as $j) {
            $draft = $drafts[$j->id] ?? null;
            $add('invoice', 2, 'invoice:'.$j->id, 'Invoice '.$j->title, ($j->client?->name ? $j->client->name.' · ' : '').'finished '.$j->ends_on->format('j M Y').($draft ? ' · draft ready to check and issue' : ''),
                $draft ? '/invoices/'.$draft : '/jobs/'.$j->id, $draft ? [] : [self::action('job_invoice', $j->id, 'Draft the invoice')]);
        }
        $counts['invoice'] = $toInvoice->count();

        // Issued but never emailed.
        $unsent = Invoice::query()->where('status', 'issued')->whereNull('sent_at')->orderBy('issue_date')->get()->filter(fn (Invoice $i) => $i->paidMinor() < $i->total_minor);
        foreach ($unsent->take($perGroup) as $i) {
            $add('invoice', 2, 'unsent:'.$i->id, "Send invoice {$i->number}", Money::format($i->total_minor).' to '.($i->bill_to_name ?: 'the client').($i->bill_to_email ? ' at '.$i->bill_to_email : ': add their email address on the invoice'),
                '/invoices/'.$i->id, $i->bill_to_email ? [self::action('inv_send', $i->id, 'Email it')] : [], ['amount' => $i->total_minor]);
        }
        $counts['invoice'] += $unsent->count();

        // Possible jobs from the job finder.
        $candidates = JobCandidate::query()->where('status', 'open')->orderBy('starts_on')->get();
        foreach ($candidates->take($perGroup) as $c) {
            $add('jobs', 2, 'candidate:'.$c->id, 'Possible job: '.$c->title, $c->starts_on->format('j M Y').($c->client_name ? ' · '.$c->client_name : '').' · from '.count($c->sources).' '.(count($c->sources) === 1 ? 'source' : 'sources'),
                '/connections?year='.UkTaxYear::fromDate($c->starts_on)->label(), [self::action('cand_add', $c->id, 'Add as a job'), self::action('cand_dismiss', $c->id, 'Not a job', 'ghost')]);
        }
        $counts['jobs'] = $candidates->count();

        // Payments to decide: suggestions first (one tap to accept), then the rest.
        $txs = BankTransaction::query()->with(['allocations.category', 'allocations.job'])->whereIn('review_status', ['suggested', 'unreviewed'])
            ->orderByRaw("CASE WHEN review_status = 'suggested' THEN 0 ELSE 1 END")->orderByDesc('posted_on')->limit($perGroup)->get();
        foreach ($txs as $t) {
            $s = $t->allocations->first();
            $suggestion = $s && ! $s->confirmed_at ? (Allocation::KINDS[$s->kind] ?? $s->kind).($s->category ? ': '.$s->category->name : '').($s->job ? ' · '.$s->job->title : '') : null;
            $add('payments', 2, 'tx:'.$t->id, ($t->counterparty ?: $t->description), $t->effectiveDate()->format('j M Y').($suggestion ? ' · suggested: '.$suggestion : ''), '/inbox',
                array_values(array_filter([$suggestion ? self::action('tx_accept', $t->id, 'Accept') : null, self::action('tx_personal', $t->id, 'Personal', 'ghost')])), ['amount' => $t->amount_pence]);
        }
        $counts['payments'] = BankTransaction::query()->whereIn('review_status', ['suggested', 'unreviewed'])->count();

        // Merchants you always decide the same way: make it automatic.
        $offers = app(RuleLearner::class)->offers($perGroup);
        foreach ($offers as $o) {
            $add('rules', 2, 'rule:'.$o['merchant_key'], 'Always do this for '.$o['name'].'?', $o['text'], '/settings/rules',
                [self::action('rule_make', $o['example_id'], 'Always do this'), self::action('rule_no', $o['example_id'], 'No', 'ghost')]);
        }
        $counts['rules'] = count($offers);

        // Stays and journeys to decide.
        $stays = TravelItem::query()->whereNull('decided_at')->where('status', '!=', 'cancelled')->whereNotNull('suggestion')->orderBy('starts_on')->get()
            ->filter(fn (TravelItem $i) => in_array($i->suggestion['decision'] ?? null, ['business', 'personal', 'mixed', 'client_booked', 'review'], true));
        foreach ($stays->take($perGroup) as $i) {
            $decision = $i->suggestion['decision'];
            $add('stays', 2, 'stay:'.$i->id, (TravelItem::KINDS[$i->kind] ?? $i->kind).': '.($i->kind === 'hotel' ? ($i->place ?: $i->provider) : $i->provider),
                $i->starts_on->format('j M Y').' · suggested: '.(TravelItem::DECISIONS[$decision] ?? 'check it'), '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(),
                array_values(array_filter([$decision !== 'review' ? self::action('stay_accept', $i->id, 'Accept') : null, self::action('stay_personal', $i->id, 'Personal', 'ghost')])),
                ['amount' => $i->amount_minor ? -$i->amount_minor : null]);
        }
        $counts['stays'] = $stays->count();

        // Jobs with no PO.
        $noPo = WorkJob::query()->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->limit(300)->get();
        $poAll = PoStatus::forJobs($noPo->keyBy('id'));
        $missing = $noPo->filter(fn (WorkJob $j) => $poAll[$j->id]['missing']);
        foreach ($missing->take($perGroup) as $j) {
            $add('no_po', 2, 'no_po:'.$j->id, 'No PO: '.$j->title, $j->starts_on->format('j M Y').'. Link the PO email or attachment, add the number, or mark it not needed.', '/jobs/'.$j->id,
                [self::action('job_no_po', $j->id, 'No PO needed', 'ghost')]);
        }
        $counts['no_po'] = $missing->count();

        // Paid bookings with no payment in your statements.
        $unpaid = TravelItem::query()->whereNull('bank_transaction_id')->whereNotNull('amount_minor')->where('status', '!=', 'cancelled')
            ->whereIn('payment', ['paid', 'pay_at_property'])->where(fn ($q) => $q->whereNull('decision')->orWhereNotIn('decision', ['client_booked', 'personal']))
            ->whereDate('starts_on', '<', now()->subDays(10))->orderByDesc('starts_on')->get();
        foreach ($unpaid->take($perGroup) as $i) {
            $add('missing', 3, 'missing:'.$i->id, 'No payment found: '.$i->provider, $i->starts_on->format('j M Y').'. The booking says it was paid; import the card or account it was paid with, or add it as a manual expense.',
                '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(), [], ['amount' => -$i->amount_minor]);
        }
        $counts['missing'] = $unpaid->count();

        // Documents held because the sender isn't on your list.
        $docs = Document::query()->where('status', 'held')->whereNull('parent_id')->latest()->get();
        foreach ($docs->take($perGroup) as $d) {
            $add('documents', 3, 'doc:'.$d->id, 'Held: '.($d->email_subject ?: $d->original_name), 'From '.($d->email_from ?? 'an unknown sender').'. Approve it to read it.', '/documents/'.$d->id,
                [self::action('doc_approve', $d->id, 'Approve')]);
        }
        $counts['documents'] = $docs->count();

        usort($items, fn ($a, $b) => [$a['priority'], array_search($a['group'], array_keys(self::GROUPS), true)] <=> [$b['priority'], array_search($b['group'], array_keys(self::GROUPS), true)]);
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
}
