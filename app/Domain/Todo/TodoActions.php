<?php

namespace App\Domain\Todo;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Verification\Verifier;
use App\Jobs\ReadDocument;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one-tap actions on the to-do list, shared by the app and Telegram buttons. Each is the same decision you'd make
 * on the item's own page, recorded as yours in the history.
 *
 * Every action is safe to repeat: a Telegram button stays pressable after the item's been dealt with (in the app, or
 * by pressing it twice while the first press waits its turn), so each one checks the item still needs it. One that's
 * been dealt with says so (a ModelNotFoundException shows as "Already dealt with") and changes nothing.
 */
final class TodoActions
{
    public const CODES = ['rule_make', 'rule_no', 'tx_accept', 'tx_personal', 'cand_add', 'cand_dismiss', 'job_invoice', 'job_no_po', 'stay_accept', 'stay_personal', 'doc_approve', 'recheck', 'inv_chase', 'inv_send', 'transfers_ok'];

    public function __construct(
        private AllocationWriter $writer, private DecisionService $decisions, private JobFinder $finder, private InvoiceService $invoices,
        private InvoiceMailer $mailer, private TravelMatcher $matcher, private Verifier $verifier,
    ) {}

    /**
     * @param  CarbonInterface|null  $offeredAt  when the button was offered (a Telegram message's date), so a press
     *                                           can tell something done since then from something done before
     * @return string what happened, for a message
     */
    public function run(string $code, string $id, ?CarbonInterface $offeredAt = null): string
    {
        return match ($code) {
            'rule_make' => $this->ruleMake((int) $id),
            'rule_no' => $this->ruleNo((int) $id),
            'tx_accept' => $this->txAccept((int) $id),
            'tx_personal' => $this->txPersonal((int) $id),
            'cand_add' => $this->candidateAdd((int) $id),
            'cand_dismiss' => $this->candidateDismiss((int) $id),
            'job_invoice' => $this->jobInvoice((int) $id),
            'job_no_po' => $this->noPoNeeded((int) $id),
            'stay_accept' => $this->stayAccept((int) $id),
            'stay_personal' => $this->stayDecide(TravelItem::query()->whereNull('decided_at')->findOrFail((int) $id), 'personal', null, null),
            'doc_approve' => $this->approve((int) $id),
            'recheck' => $this->recheck($id),
            'inv_chase' => $this->mailer->send(Invoice::query()->findOrFail((int) $id), null, true, once: true, offeredAt: $offeredAt),
            'inv_send' => $this->mailer->send(Invoice::query()->findOrFail((int) $id), once: true, offeredAt: $offeredAt),
            'transfers_ok' => $this->transfersOk((int) $id, $offeredAt),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
    }

    /**
     * Confirm, as your decision, the suggested transfers that are safe to confirm together: both sides of a pair, and
     * money leaving an account for another of yours. Only what was suggested when the button was offered ($upTo, the
     * last suggestion shown; or a Telegram message's date), so a later suggestion is never confirmed unseen. Money
     * paid in with no other side imported is never confirmed this way: it could be a client paying you.
     */
    private function transfersOk(int $upTo, ?CarbonInterface $offeredAt): string
    {
        $set = TransferPairer::confirmable($upTo > 0 ? $upTo : null, $offeredAt);
        foreach (BankTransaction::query()->whereIn('id', $set['ids'] ?: [0])->orderBy('id')->get() as $tx) {
            $allocations = $tx->allocations()->get();
            if ($allocations->isNotEmpty() && $allocations->every(fn ($a) => $a->confirmed_at !== null)) {
                continue; // confirmed with its other side
            }
            try {
                $this->writer->confirm($tx);
            } catch (InvalidArgumentException) {
                // a locked year or nothing to confirm any more
            }
        }
        $done = BankTransaction::query()->whereIn('id', $set['ids'] ?: [0])->where('review_status', 'confirmed')->get(['id', 'amount_pence']);
        $out = (int) -$done->where('amount_pence', '<', 0)->sum('amount_pence');
        $in = (int) $done->where('amount_pence', '>', 0)->sum('amount_pence');
        $left = $set['left'] ? ' '.$set['left'].' '.($set['left'] === 1 ? 'payment' : 'payments').' in ('.Money::format($set['left_pence']).') with no other side imported '.($set['left'] === 1 ? 'is' : 'are')
            .' left for you to check one by one in Transactions: a client could have paid '.($set['left'] === 1 ? 'it' : 'them').'.' : '';

        if (! $done->count()) {
            return 'Nothing left to confirm.'.$left;
        }

        return 'Confirmed '.$done->count().' '.($done->count() === 1 ? 'transfer' : 'transfers').' between your accounts ('.Money::format($out).' out'
            .($in ? ', '.Money::format($in).' in' : '').'). They’re now left out of your income and spending.'.$left;
    }

    private function ruleMake(int $txId): string
    {
        $tx = BankTransaction::query()->findOrFail($txId);
        if ($rule = MerchantRule::query()->where('merchant_key', $tx->merchant_key)->first()) {
            $name = $tx->counterparty ?: $tx->merchant_key;

            return RuleLearner::appliesTo($rule, $tx->amount_pence)
                ? $name.' is already done automatically. Change it in Settings › Rules.'
                : $name.' already has a rule, for '.RuleLearner::DIRECTIONS[$rule->direction].' only. Change it in Settings › Rules.';
        }
        $r = app(RuleLearner::class)->accept($tx->merchant_key);

        return 'From now on '.($tx->counterparty ?: $tx->merchant_key).' is done automatically'.($r['applied'] ? "; applied to {$r['applied']} waiting" : '').'. Undo it in Settings › Rules.';
    }

    private function ruleNo(int $txId): string
    {
        $tx = BankTransaction::query()->findOrFail($txId);
        app(RuleLearner::class)->decline($tx->merchant_key);

        return 'OK, it won’t ask about '.($tx->counterparty ?: $tx->merchant_key).' again.';
    }

    private function txAccept(int $id): string
    {
        $tx = BankTransaction::query()->findOrFail($id);
        $name = $tx->counterparty ?: $tx->description;
        $allocations = $tx->allocations()->get();
        if ($allocations->isNotEmpty() && $allocations->every(fn ($a) => $a->confirmed_at !== null)) {
            return "Already dealt with: {$name} is confirmed.";
        }
        $this->writer->confirm($tx);
        // Accepting teaches like deciding: the other payments waiting from there follow it (as the Inbox's confirm).
        $learned = app(Categoriser::class)->learnFrom(collect([$tx->fresh()]));

        return "Accepted: {$name}.".($learned ? ' Updated the suggestion for '.$learned.' other waiting '.($learned === 1 ? 'payment' : 'payments').' from there.' : '');
    }

    private function txPersonal(int $id): string
    {
        $tx = BankTransaction::query()->findOrFail($id);
        $name = $tx->counterparty ?: $tx->description;
        // Never over a decision you've made or confirmed since the button was offered (it stays pressable in Telegram).
        $r = $this->decisions->apply(['kind' => 'personal', 'reason' => 'From the to-do list'], collect([$tx]), includeProtected: false);
        if ($r['errors']) {
            throw new InvalidArgumentException($r['errors'][0]);
        }
        if (! $r['saved']) {
            return "Already dealt with: {$name} has been decided since, so it wasn’t changed.";
        }

        return "Marked personal: {$name}.";
    }

    /** Draft the invoice for a finished job, unless it already has one: then point at that instead of making another. */
    private function jobInvoice(int $id): string
    {
        return DB::transaction(function () use ($id) {
            $job = WorkJob::query()->with('client')->lockForUpdate()->findOrFail($id);
            $invoices = Invoice::query()->where('work_job_id', $job->id)->where('status', '!=', 'void')->orderByDesc('id')->get();
            if ($issued = $invoices->first(fn (Invoice $i) => in_array($i->status, ['issued', 'paid'], true))) {
                return "{$job->title} is already invoiced ({$issued->number}), so no new draft was made.";
            }
            if ($draft = $invoices->firstWhere('status', 'draft')) {
                return "{$job->title} already has a draft invoice, so no new one was made. Check it and press Issue: ".url('/invoices/'.$draft->id);
            }

            return 'Draft invoice '.$this->invoices->draftForJob($job)->id.' is ready: check it and press Issue.';
        });
    }

    private function candidateAdd(int $id): string
    {
        $c = JobCandidate::query()->where('status', 'open')->findOrFail($id);
        $job = $this->finder->accept($c, [
            'title' => $c->title, 'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on->format('Y-m-d'),
            'start_time' => $c->start_time, 'end_time' => $c->end_time, 'location' => $c->location, 'postcode' => $c->postcode, 'po_number' => $c->po_number,
            'client_id' => $c->client_name ? Client::query()->firstOrCreate(['name' => trim($c->client_name)])->id : null,
            'status' => $c->ends_on->isPast() ? 'done' : 'booked',
        ]);

        return "Added {$job->title} and linked what it was found from.";
    }

    private function candidateDismiss(int $id): string
    {
        $c = JobCandidate::query()->where('status', 'open')->findOrFail($id);
        $this->finder->dismiss($c, 'From the to-do list');

        return "Dismissed {$c->title}.";
    }

    private function noPoNeeded(int $id): string
    {
        $job = WorkJob::query()->findOrFail($id);
        if ($job->po_not_required) {
            return "{$job->title} is already marked as needing no PO.";
        }
        $before = $job->only(['po_not_required']);
        $job->update(['po_not_required' => true]);
        AuditLog::record('job.updated', $job, $before, ['po_not_required' => true], 'No PO needed');

        return "{$job->title}: marked as needing no PO.";
    }

    private function stayAccept(int $id): string
    {
        // Once you've decided a stay (here or in Stays & travel) its old suggestion is never applied over your decision.
        $item = TravelItem::query()->whereNull('decided_at')->findOrFail($id);
        $s = $item->suggestion ?? [];
        if (! in_array($s['decision'] ?? null, ['business', 'personal', 'mixed', 'client_booked'], true)) {
            throw new InvalidArgumentException('This one needs a look: open it in Stays & travel.');
        }

        return $this->stayDecide($item, $s['decision'], $s['job_id'] ?? null, $s['business_nights'] ?? null);
    }

    private function stayDecide(TravelItem $item, string $decision, ?int $jobId, ?int $nights): string
    {
        $this->matcher->decide($item, $decision, $jobId ?? $item->work_job_id, $nights, null);

        return ucfirst(TravelItem::DECISIONS[$decision] ?? $decision).': '.$item->provider.' '.$item->starts_on->format('j M').'.';
    }

    private function approve(int $id): string
    {
        // Only a held document: approving one that's already been read would reset it and read it again with AI.
        $doc = Document::query()->where('status', 'held')->findOrFail($id);
        $doc->update(['status' => 'new', 'error' => null]);
        $doc->children()->where('status', 'held')->update(['status' => 'new', 'error' => null]);
        AuditLog::record('document.updated', $doc, null, ['approve' => true]);
        if (Setting::get('ai_extraction') === 'on' && AiConfig::ready()) {
            foreach ($doc->children()->get()->push($doc)->where('kind', '!=', 'email') as $d) {
                ReadDocument::dispatch($d->id);
            }
        }

        return 'Approved: '.($doc->email_subject ?: $doc->original_name).'.';
    }

    private function recheck(string $id): string
    {
        [$type, $subject] = explode(':', $id, 2) + [null, null];
        $v = match ($type) {
            'document' => $this->verifier->document(Document::query()->findOrFail((int) $subject), true),
            'travel_item' => $this->verifier->travelItem(TravelItem::query()->findOrFail((int) $subject)),
            'job_candidate' => $this->verifier->candidate(JobCandidate::query()->findOrFail((int) $subject)),
            'calendar_link' => $this->calendarLink((int) $subject),
            'transaction' => $this->verifier->transaction(BankTransaction::query()->findOrFail((int) $subject)),
            default => throw new InvalidArgumentException('Unknown check.'),
        };

        return $v?->summary ?? 'Nothing to check any more.';
    }

    /**
     * A check of a calendar link is about the entry being on its job. If the link has gone, or no longer puts the
     * entry on a job, there's nothing left to check: the old result goes, so it isn't listed again.
     */
    private function calendarLink(int $id): ?Verification
    {
        $link = CalendarJobLink::query()->find($id);
        if (! $link || ! $link->onJob()) {
            $link ? $link->forgetCheck() : Verification::query()->where('subject_type', 'calendar_link')->where('subject_id', $id)->delete();

            return null;
        }

        return $this->verifier->calendarLink($link);
    }
}
