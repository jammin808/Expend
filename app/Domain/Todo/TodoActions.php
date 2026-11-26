<?php

namespace App\Domain\Todo;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostRef;
use App\Domain\Clients\ClientIds;
use App\Domain\Documents\AiConfig;
use App\Domain\Income\IncomeClient;
use App\Domain\Income\IncomeTargets;
use App\Domain\Income\IncomeText;
use App\Domain\Income\Lines;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Intel\Vetoes;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceClosing;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\CandidateText;
use App\Domain\Jobs\JobBilling;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Domain\Jobs\Opportunities;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Mileage\JobTrips;
use App\Domain\Quick\QuickEntries;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Verification\Verifier;
use App\Jobs\ReadDocument;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\MerchantRule;
use App\Models\MileageTrip;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one-tap actions on the to-do list, shared by the app and Telegram buttons. Each is the same decision you'd make
 * on the item's own page, recorded as yours in the history.
 *
 * Every action is safe to repeat: a Telegram button stays pressable after the item's been dealt with (in the app, or
 * by pressing it twice while the first press waits its turn), so each one checks the item still needs it. One that's
 * been dealt with says so (a ModelNotFoundException shows as "Already dealt with") and changes nothing.
 *
 * Each deciding action is bound to what was shown (M7): the button carries a fingerprint of the state it was offered on
 * (Fingerprints), and a press on something changed since is refused with a plain message (ChangedSince).
 *
 * After a press, followUp() gives what to offer next: links (Check the invoice, Add days) and Undo, for Telegram to put
 * on the message and the to-do page to show in its toast (a flash 'undo').
 *
 * Reminders are made only when you press for one (the owner's answer): inv_chase writes the reminder as your Gmail
 * draft (ChaseLadder::make, as a one-tap press), or, with no Gmail to write it in, emails it from Expend as before.
 * Nothing here is ever run on a schedule.
 */
final class TodoActions
{
    public const CODES = ['rule_make', 'rule_no', 'tx_accept', 'tx_personal', 'tx_tax', 'cand_add', 'cand_merge', 'cand_dismiss', 'job_invoice', 'job_no_po', 'stay_accept', 'stay_personal', 'doc_approve', 'recheck', 'inv_chase', 'inv_send', 'transfers_ok',
        'opp_add', 'opp_decline', 'opp_dismiss', 'fnd_snooze', 'fnd_dismiss', 'trips_add', 'det_raise', 'det_lower', 'veto_lift', 'qx',
        // Income (income design v3): a payment for an invoice, a client or a job, a fee taken off, and an invoice's closing.
        'tx_invoice', 'tx_client', 'tx_fee', 'tx_job', 'inv_close', 'inv_over_keep', 'inv_replace',
        // A job's status: Done, Not yet, Cancelled, and their Undo.
        'job_done', 'job_not_yet', 'job_cancel', 'job_reopen', 'job_uncancel', 'todo_undo',
        // An invoice's draft, its email in Gmail and its reminder.
        'inv_draft_later', 'inv_to_gmail', 'inv_gmail_send', 'inv_sent_self', 'inv_chase_send', 'inv_chase_later', 'inv_paid_wait',
        // Your decision about a cost (its id is CostRef::toId's).
        'cost_charge', 'cost_no_charge', 'cost_elsewhere', 'cost_claimed', 'cost_invoiced', 'cost_paid_back', 'cost_not_paid', 'cost_clear', 'cost_invoice_now'];

    /** How long "Snooze a week" snoozes for. */
    public const SNOOZE_DAYS = 7;

    /** "Not yet" on "Is it done?": asked again after this many days. */
    public const NOT_YET_DAYS = 3;

    /** "Not yet" on an overdue invoice, and "They've paid": how long it's left. */
    public const CHASE_LATER_DAYS = 7;

    /** Why an invoice is closed, as a button's id says it ({invoice}:{short}). */
    public const CLOSE_REASONS = ['sp' => 'short_paid', 'ch' => 'charges', 'wc' => 'wont_chase', 'fx' => 'paid_other_currency', 'ot' => 'other'];

    /** The snooze a "Not yet" keeps, so the to-do page and Telegram say why. */
    public const NOT_FINISHED_NOTE = 'Not finished yet';

    /** The items an Undo can put back on the list (todo_undo). */
    private const UNDOABLE = '/^(?:job_done|invoice|draft|invoice_overdue):\d+$/';

    public function __construct(
        private AllocationWriter $writer, private DecisionService $decisions, private JobFinder $finder, private InvoiceService $invoices,
        private InvoiceMailer $mailer, private TravelMatcher $matcher, private Verifier $verifier, private Opportunities $opportunities,
    ) {}

    /**
     * @param  CarbonInterface|null  $offeredAt  when the button was offered (a Telegram message's date), so a press
     *                                           can tell something done since then from something done before
     * @param  string|null  $fp  the fingerprint of what the button was offered on (Fingerprints): a press on something
     *                           changed since is refused (ChangedSince), and nothing is done
     * @return string what happened, for a message
     */
    public function run(string $code, string $id, ?CarbonInterface $offeredAt = null, ?string $fp = null): string
    {
        Fingerprints::check($code, $id, $fp);

        return match ($code) {
            'rule_make' => $this->ruleMake((int) $id),
            'rule_no' => $this->ruleNo((int) $id),
            'tx_accept' => $this->txAccept((int) $id),
            'tx_personal' => $this->txPersonal((int) $id),
            'tx_tax' => $this->txTax((int) $id, $offeredAt),
            'cand_add' => $this->candidateAdd((int) $id),
            'cand_merge' => $this->candidateMerge($id),
            'cand_dismiss' => $this->candidateDismiss((int) $id),
            'job_invoice' => $this->jobInvoice((int) $id),
            'job_no_po' => $this->noPoNeeded((int) $id),
            'stay_accept' => $this->stayAccept((int) $id),
            'stay_personal' => $this->stayDecide(TravelItem::query()->whereNull('decided_at')->findOrFail((int) $id), 'personal', null, null),
            'doc_approve' => $this->approve((int) $id),
            'recheck' => $this->recheck($id),
            'inv_chase' => $this->chase(Invoice::query()->findOrFail((int) $id), $offeredAt),
            'inv_send' => $this->mailer->send(Invoice::query()->findOrFail((int) $id), once: true, offeredAt: $offeredAt),
            'transfers_ok' => $this->transfersOk((int) $id, $offeredAt),
            'opp_add' => $this->opportunityAdd((int) $id, $offeredAt, $fp !== null),
            'opp_decline' => $this->opportunityDecide((int) $id, 'decline', $offeredAt, $fp !== null),
            'opp_dismiss' => $this->opportunityDecide((int) $id, 'dismiss', $offeredAt, $fp !== null),
            'fnd_snooze' => $this->findingHide($id, true),
            'fnd_dismiss' => $this->findingHide($id, false),
            'trips_add' => $this->tripsAdd($id),
            'det_raise' => DetectorLevels::raise(self::detectorCode($id)),
            'det_lower' => DetectorLevels::lower(self::detectorCode($id)),
            'veto_lift' => $this->vetoLift((int) $id),
            // Expenses from your phone: "<entry>:<verb>[:<argument>]" (Quick\QuickEntries::act()).
            'qx' => app(QuickEntries::class)->act($id),
            'tx_invoice', 'tx_client' => $this->txChip($code, $id),
            'tx_fee' => $this->txFee($id),
            'tx_job' => $this->txJob($id),
            'inv_close' => $this->invoiceClose($id),
            'inv_over_keep' => $this->overKeep((int) $id),
            'inv_replace' => $this->invoiceReplace($id),
            'job_done' => app(JobStatus::class)->markDone(self::job($id))['message'],
            'job_not_yet' => $this->jobNotYet(self::job($id)),
            'job_cancel' => $this->jobCancel($id),
            'job_reopen' => app(JobStatus::class)->reopen(self::job($id), 'Undo')['message'],
            'job_uncancel' => app(JobStatus::class)->undoCancel(self::job($id))['message'],
            'todo_undo' => $this->undo($id),
            'inv_draft_later' => $this->draftLater((int) $id),
            'inv_to_gmail' => $this->toGmail(Invoice::query()->findOrFail((int) $id)),
            'inv_gmail_send' => app(InvoiceEmails::class)->sendOneTap(Invoice::query()->findOrFail((int) $id), 'invoice'),
            // Today: a button can't ask when it went ("Pick a date" opens the invoice, which asks).
            'inv_sent_self' => app(InvoiceEmails::class)->markSentSelf(Invoice::query()->findOrFail((int) $id), null, CarbonImmutable::now(InvoiceEmails::TZ)->format('Y-m-d')),
            'inv_chase_send' => app(InvoiceEmails::class)->sendOneTap(Invoice::query()->findOrFail((int) $id), 'reminder'),
            'inv_chase_later' => $this->chaseLater(Invoice::query()->findOrFail((int) $id)),
            'inv_paid_wait' => $this->paidWait(Invoice::query()->findOrFail((int) $id)),
            'cost_charge', 'cost_no_charge', 'cost_elsewhere', 'cost_claimed', 'cost_invoiced', 'cost_paid_back', 'cost_not_paid', 'cost_clear' => $this->cost($code, $id),
            'cost_invoice_now' => $this->invoiceNow($id),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
    }

    /**
     * What to offer after a press, as Telegram buttons ([label, code, id], or ['url' => …, 'text' => …] for a link): a
     * link to what the press made or opened, and Undo where it can be undone. Worked out after the press, so each
     * button is bound to what it left. Empty when there's nothing to follow up.
     *
     * @return list<array>
     */
    public static function followUp(string $code, string $id): array
    {
        $job = in_array($code, ['job_done', 'job_not_yet', 'job_cancel', 'job_reopen', 'job_uncancel', 'job_invoice'], true) && preg_match('/^(\d+)/', $id, $m) === 1
            ? WorkJob::query()->find((int) $m[1]) : null;
        $draft = $job ? Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderByDesc('id')->first(['id']) : null;
        $check = $draft ? ['url' => url('/invoices/'.$draft->id), 'text' => 'Check the invoice'] : null;
        $invoice = str_starts_with($code, 'inv_') && ctype_digit($id) ? Invoice::query()->find((int) $id, ['id', 'number']) : null;
        $costRef = str_starts_with($code, 'cost_') ? Fingerprints::costRef($code, $id) : null;

        return array_values(array_filter(match (true) {
            $job === null && in_array($code, ['job_done', 'job_not_yet', 'job_cancel', 'job_reopen', 'job_uncancel', 'job_invoice'], true) => [],
            $code === 'job_done' => $job->status === 'done' ? [$check, ['Undo', 'job_reopen', (string) $job->id]] : [],
            $code === 'job_invoice' => [$check],
            $code === 'job_not_yet' => [['url' => url('/jobs/'.$job->id.'#days'), 'text' => 'Add days'], ['Undo', 'todo_undo', 'job_done:'.$job->id]],
            $code === 'job_cancel' && $job->status !== 'cancelled' => [
                ['No', 'job_cancel', $job->id.':none'], ['Just my costs', 'job_cancel', $job->id.':costs'],
                ['url' => url('/jobs/'.$job->id.'#cancel'), 'text' => 'A cancellation fee…'],
            ],
            $code === 'job_cancel' => [$check, ['Undo', 'job_uncancel', (string) $job->id]],
            $code === 'inv_paid_wait' && $invoice !== null => [['url' => url('/invoices/'.$invoice->id.'#payments'), 'text' => 'Link a payment'], ['Undo', 'todo_undo', 'invoice_overdue:'.$invoice->id]],
            $code === 'inv_chase_later' && $invoice !== null => [['Undo', 'todo_undo', 'invoice_overdue:'.$invoice->id]],
            $code === 'inv_draft_later' && $invoice !== null => [['url' => url('/invoices/'.$invoice->id), 'text' => 'Check the invoice']],
            in_array($code, ['inv_chase', 'inv_to_gmail'], true) && $invoice !== null => [['url' => url('/invoices/'.$invoice->id), 'text' => 'Open the invoice']],
            $costRef !== null && in_array($code, ['cost_charge', 'cost_no_charge', 'cost_elsewhere', 'cost_claimed', 'cost_invoiced'], true)
                && CostCharge::query()->where('cost_ref', $costRef)->whereNotNull('charge')->exists() => [['Undo', 'cost_clear', CostRef::toId($costRef)]],
            $code === 'cost_invoice_now' => ($d = self::clientDraftFor($costRef ?? CostRef::fromId($id))) ? [['url' => url('/invoices/'.$d), 'text' => 'Check the invoice']] : [],
            default => [],
        }));
    }

    /** The Undo among a press's follow-up buttons ([label, code, id]), or null: for the to-do page's toast. */
    public static function undoOf(string $code, string $id): ?array
    {
        foreach (self::followUp($code, $id) as $b) {
            if (isset($b[1]) && ($b[0] ?? null) === 'Undo') {
                return ['code' => $b[1], 'id' => (string) $b[2], 'label' => 'Undo', 'fp' => Fingerprints::of($b[1], (string) $b[2])];
            }
        }

        return null;
    }

    /** The job a job button is about ({job}, or {job}:{choice}). */
    private static function job(string $id): WorkJob
    {
        if (preg_match('/^(\d+)(?::[a-z]+)?$/', $id, $m) !== 1) {
            throw new InvalidArgumentException('Unknown job.');
        }

        return WorkJob::query()->findOrFail((int) $m[1]);
    }

    /**
     * Make a reminder, as you asked (the owner's answer: only when you ask). With Gmail, it's written as your Gmail
     * draft, worded for how overdue it is, for you to send (ChaseLadder::make, a one-tap press: held while something
     * may be for it). With no Gmail to write it in, Expend emails it as it always has (invoices sent by Expend's own
     * email keep their reminders).
     */
    private function chase(Invoice $invoice, ?CarbonInterface $offeredAt): string
    {
        // A reminder follows the invoice: one never sent gets none, by Gmail or by Expend's own email.
        if (in_array($invoice->status, InvoicePayments::PAYABLE, true) && $invoice->sent_at === null) {
            throw new InvalidArgumentException(ChaseLadder::NOT_SENT);
        }
        if (InvoiceEmails::canUse()) {
            return app(ChaseLadder::class)->make($invoice, once: true)['message'];
        }

        return $this->mailer->send($invoice, null, true, once: true, offeredAt: $offeredAt);
    }

    /** "Not yet" on "Is it done?": asked again in 3 days (Telegram asks again that morning). */
    private function jobNotYet(WorkJob $job): string
    {
        if ($job->status !== 'booked') {
            throw (new ModelNotFoundException)->setModel(WorkJob::class, [$job->id]); // done or cancelled since
        }
        Dismissals::dismiss('job_done:'.$job->id, self::NOT_YET_DAYS, self::NOT_FINISHED_NOTE, AuditContext::channel() ?? 'web', fromStartOfDay: true);
        $again = CarbonImmutable::now(JobStage::TZ)->addDays(self::NOT_YET_DAYS);

        return 'OK, I’ll ask again on '.$again->format('D j M').'. If it has more days, add them and I’ll ask after the last one.';
    }

    /**
     * Cancelled: {job} asks what they still owe (the follow-up offers No, Just my costs and a link for a fee);
     * {job}:none and {job}:costs cancel it (JobStatus::cancel). A fee needs its amount, so it's given on the job's page.
     */
    private function jobCancel(string $id): string
    {
        $job = self::job($id);
        $choice = str_contains($id, ':') ? substr($id, strrpos($id, ':') + 1) : null;
        if ($choice === null) {
            return 'Do they owe you anything for '.$job->title.'? For a cancellation fee, give it on the job’s page: '.url('/jobs/'.$job->id.'#cancel');
        }
        if (! in_array($choice, ['none', 'costs'], true)) {
            throw new InvalidArgumentException('Give the cancellation fee on the job’s page: '.url('/jobs/'.$job->id.'#cancel'));
        }

        return app(JobStatus::class)->cancel($job, $choice)['message'];
    }

    /** Undo of a snooze a button made ("Not yet", "They've paid", "Remind me Monday"): back on the list. */
    private function undo(string $key): string
    {
        if (preg_match(self::UNDOABLE, $key) !== 1) {
            throw new InvalidArgumentException('That can’t be undone from here: open the to-do list.');
        }
        Dismissals::undo($key, AuditContext::channel() ?? 'web');

        return str_starts_with($key, 'job_done:') ? 'Undone: I’ll ask whether it’s done again.' : 'Undone: it’s back on the list.';
    }

    /** "Remind me Monday" on a draft: its item is left until next Monday morning. */
    private function draftLater(int $id): string
    {
        $draft = Invoice::query()->where('status', 'draft')->findOrFail($id);
        $key = $draft->work_job_id !== null && ! Invoice::query()->where('work_job_id', $draft->work_job_id)->whereIn('status', InvoicePayments::PAYABLE)->exists()
            ? 'invoice:'.$draft->work_job_id : 'draft:'.$draft->id;
        $today = CarbonImmutable::now(JobStage::TZ)->startOfDay();
        $monday = $today->next(CarbonImmutable::MONDAY);
        Dismissals::dismiss($key, (int) $today->diffInDays($monday), 'Remind me Monday', AuditContext::channel() ?? 'web', fromStartOfDay: true);

        return 'OK, I’ll remind you on '.$monday->format('l j M').'.';
    }

    /** Put an issued invoice in your Gmail drafts again (Try again, Put it back in Gmail). */
    private function toGmail(Invoice $invoice): string
    {
        if (! InvoiceEmails::canUse()) {
            throw new InvalidArgumentException('Connect Gmail (with permission to write drafts) to put it there, or email it from Expend.');
        }
        if ($invoice->status !== 'issued' || $invoice->sent_at !== null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->id]); // sent or voided since
        }
        $row = app(InvoiceEmails::class)->prepareOrRetry($invoice, 'invoice', []);

        return match ($row?->status) {
            'drafted' => "Invoice {$invoice->number} is in your Gmail drafts, ready to send.",
            'pending' => "Putting invoice {$invoice->number} in Gmail… Gmail didn’t answer, so Expend will try again in a minute.",
            default => "Invoice {$invoice->number} isn’t in your Gmail drafts: ".InvoiceEmails::reason((string) ($row?->error ?: 'Gmail didn’t take it')).'.',
        };
    }

    /** "Not yet" on an overdue invoice: not asked about for a week. */
    private function chaseLater(Invoice $invoice): string
    {
        Dismissals::dismiss('invoice_overdue:'.$invoice->id, self::CHASE_LATER_DAYS, 'Not yet: chase it later', AuditContext::channel() ?? 'web');

        return 'OK: Expend won’t ask about '.$invoice->number.' for a week.';
    }

    /**
     * "They've paid": the overdue item waits a week for the payment, and Expend's own unedited reminder draft is taken
     * out so it isn't sent. Nothing is linked: you link the payment when it's in.
     */
    private function paidWait(Invoice $invoice): string
    {
        if (! in_array($invoice->status, InvoicePayments::PAYABLE, true)) {
            throw new InvalidArgumentException('Only an issued invoice can be paid.');
        }
        Dismissals::dismiss('invoice_overdue:'.$invoice->id, self::CHASE_LATER_DAYS, 'They’ve paid: waiting for the payment', AuditContext::channel() ?? 'web');
        $note = '';
        if ($row = InvoiceEmails::open($invoice, 'reminder')) {
            try {
                $note = app(InvoiceEmails::class)->whileYouWait()->takeOut($row, 'you said they’ve paid', 'you');
                $note = $note === '' ? ' Your edited reminder is still in your Gmail drafts: delete it so it isn’t sent.' : ' '.$note;
            } catch (\Throwable $e) {
                report($e);
                $note = ' Its reminder may still be in your Gmail drafts: delete it so it isn’t sent.';
            }
        }

        return 'OK: Expend won’t ask about '.$invoice->number.' for a week.'.$note.' Link the payment when it’s in: '.url('/invoices/'.$invoice->id.'#payments');
    }

    /**
     * A payment saved as one of its chips (IncomeTargets): "For INV-042" (tx_invoice, {tx}:{invoice}) or "Acme Events,
     * no job" (tx_client, {tx}:{client}), as you, exactly as the chip fills it. Only while that chip is still offered:
     * otherwise it's opened in the Inbox.
     */
    private function txChip(string $code, string $id): string
    {
        [$txId, $target] = self::pair($id);
        $tx = BankTransaction::query()->findOrFail($txId);
        $kind = $code === 'tx_invoice' ? 'invoice' : 'client';
        $chip = collect(IncomeTargets::for($tx))->first(fn (array $c) => $c['kind'] === $kind
            && ($kind === 'invoice' ? $c['invoice_ids'] === [$target] : $c['client_id'] === $target));
        $name = $tx->counterparty ?: $tx->description;
        if ($chip === null) {
            throw new InvalidArgumentException('That doesn’t fit '.$name.' any more: open it in the Inbox. '.url('/inbox'));
        }
        $this->decideLines($tx, $chip['lines'], 'From the to-do list: '.$chip['label']);

        return $kind === 'invoice'
            ? 'Saved: '.$name.' pays '.(Invoice::query()->whereKey($target)->value('number') ?: 'the invoice').'.'
            : 'Saved: '.$name.' is '.(Client::query()->whereKey($target)->value('name') ?? 'the client').'’s, with no job.';
    }

    /**
     * "It was for this job" on "Invoice <job>": the one payment the job's client made with no job after it
     * (JobBilling::clientOnlyAfter) goes on the job, as you. Its other lines stay as they are.
     */
    private function txJob(string $id): string
    {
        [$txId, $jobId] = self::pair($id);
        $job = WorkJob::query()->findOrFail($jobId);
        $tx = BankTransaction::query()->findOrFail($txId);
        $line = JobBilling::clientOnlyAfter($job)->first(fn (Allocation $a) => (int) $a->bank_transaction_id === $txId);
        if ($line === null) {
            if ($tx->allocations()->where('work_job_id', $job->id)->exists()) {
                throw (new ModelNotFoundException)->setModel(BankTransaction::class, [$txId]); // done already
            }
            throw new InvalidArgumentException('That payment isn’t one with no job any more: open it in Transactions. '.url('/transactions?q='.urlencode((string) ($tx->counterparty ?: $tx->description))));
        }
        $lines = array_map(fn (array $l, Allocation $a) => (int) $a->id === (int) $line->id ? ['work_job_id' => $job->id, 'client_id' => null] + $l : $l,
            Lines::from($tx->allocations()->orderBy('id')->get()), $tx->allocations()->orderBy('id')->get()->all());
        $this->decideLines($tx, $lines, 'From the to-do list: it was for '.$job->title, true);

        return 'Saved: '.IncomeText::money((int) $line->amount_pence).' from '.($tx->counterparty ?: $tx->description).' on '.$tx->posted_on->format('j M').' is for '.$job->title.'.';
    }

    /**
     * "£30 was a fee or commission" on a part-paid pound invoice ({tx}:{invoice}): the one confirmed payment linked to
     * it is saved gross (the invoice's line raised to what's still to come) with the shortfall as a fee taken off,
     * on the same job (or the client with no job). Not for a payer whose words look like a payslip, nor for more than a
     * quarter of the invoice.
     */
    private function txFee(string $id): string
    {
        [$txId, $invoiceId] = self::pair($id);
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $tx = BankTransaction::query()->findOrFail($txId);
        $fee = self::feeShortfall($invoice);
        if ($fee === null || $fee['tx'] !== $txId) {
            throw new InvalidArgumentException('That doesn’t fit '.($invoice->number ?: 'the invoice').' any more: open it to check. '.url('/invoices/'.$invoice->id));
        }
        $lines = Lines::from($tx->allocations()->orderBy('id')->get());
        $at = collect($lines)->search(fn (array $l) => $l['kind'] === 'business_income' && (int) $l['invoice_id'] === $invoiceId && $l['amount_pence'] > 0);
        $lines[$at]['amount_pence'] += $fee['fee'];
        $lines[] = Lines::deduction($fee['fee'], null, $lines[$at]['work_job_id'], $lines[$at]['work_job_id'] === null ? IncomeClient::ofInvoice($invoice) : null);
        $this->decideLines($tx, $lines, 'From the to-do list: '.IncomeText::money($fee['fee']).' taken off as a fee', true);

        return 'Saved: '.IncomeText::money($fee['fee']).' was taken off '.$invoice->number.' as a fee or commission, so it’s paid in full.';
    }

    /**
     * When "£x was a fee or commission" is offered for a part-paid invoice: a pound invoice with exactly one confirmed
     * pound payment linked to it, from a payer whose words don't look like a payslip, still owed at most a quarter of
     * its total, with nothing waiting for it.
     *
     * @return array{tx:int, fee:int}|null
     */
    public static function feeShortfall(Invoice $invoice, ?array $received = null): ?array
    {
        if ($invoice->status !== 'issued' || strtoupper((string) ($invoice->currency ?: 'GBP')) !== 'GBP') {
            return null;
        }
        $r = $received ?? (InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY);
        if ($r['state'] !== 'part_paid' || $r['outstanding'] <= 0 || (int) $invoice->total_minor < $r['outstanding'] * 4 || $r['waiting'] > 0 || $r['paid'] !== $r['paid_linked']) {
            return null;
        }
        $lines = Allocation::query()->where('invoice_id', $invoice->id)->whereNotNull('confirmed_at')->whereNotNull('bank_transaction_id')->where('amount_pence', '>', 0)
            ->with('transaction:id,amount_pence,original_currency,counterparty,description')->get();
        $txs = $lines->pluck('bank_transaction_id')->unique();
        if ($txs->count() !== 1 || ($tx = $lines->first()->transaction) === null
            || ($tx->original_currency !== null && strtoupper($tx->original_currency) !== 'GBP') || IncomeText::isPayslipLike($tx->counterparty, $tx->description)) {
            return null;
        }

        return ['tx' => (int) $tx->id, 'fee' => (int) $r['outstanding']];
    }

    /** Close an invoice from its button ({invoice}:{reason}): what's still to come isn't coming. */
    private function invoiceClose(string $id): string
    {
        if (preg_match('/^(\d+):([a-z]{2})$/', $id, $m) !== 1 || ! isset(self::CLOSE_REASONS[$m[2]])) {
            throw new InvalidArgumentException('Say why it’s being closed.');
        }
        $invoice = Invoice::query()->findOrFail((int) $m[1]);
        $outstanding = (InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY)['outstanding'];
        app(InvoiceClosing::class)->close($invoice, self::CLOSE_REASONS[$m[2]], 'From the to-do list');

        return 'Closed '.$invoice->number.': '.IncomeText::money($outstanding, InvoicePayments::currencyOf($invoice)).' isn’t coming. Reopen it on its page if it comes after all.';
    }

    /**
     * "Keep the £100 as Acme Events, no job" on an overpaid invoice: the latest payment linked to it gives the
     * overpayment to the invoice's client with no job (it pays no invoice), as you.
     */
    private function overKeep(int $id): string
    {
        $invoice = Invoice::query()->findOrFail($id);
        $over = (InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY)['over'];
        $client = IncomeClient::ofInvoice($invoice);
        if ($over <= 0 || strtoupper((string) ($invoice->currency ?: 'GBP')) !== 'GBP') {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$id]); // not overpaid any more
        }
        if ($client === null) {
            throw new InvalidArgumentException($invoice->number.' has no client: open it to say whose the £'.number_format($over / 100, 2).' is. '.url('/invoices/'.$invoice->id));
        }
        $line = Allocation::query()->where('invoice_id', $invoice->id)->whereNotNull('confirmed_at')->whereNotNull('bank_transaction_id')->where('amount_pence', '>=', $over)
            ->orderByDesc('id')->first();
        if ($line === null) {
            throw new InvalidArgumentException('No one payment for '.$invoice->number.' holds the '.IncomeText::money($over).': split it in Transactions.');
        }
        $tx = BankTransaction::query()->findOrFail($line->bank_transaction_id);
        $all = $tx->allocations()->orderBy('id')->get();
        $lines = [];
        foreach (Lines::from($all) as $n => $l) {
            if ((int) $all[$n]->id !== (int) $line->id) {
                $lines[] = $l;

                continue;
            }
            if ($l['amount_pence'] > $over) {
                $lines[] = ['amount_pence' => $l['amount_pence'] - $over] + $l;
            }
            $lines[] = Lines::income($over, null, null, $client, $l['category_id']);
        }
        $this->decideLines($tx, $lines, 'From the to-do list: the overpayment kept as the client’s', true);

        return 'Kept '.IncomeText::money($over).' as '.(Client::query()->whereKey($client)->value('name') ?? 'the client').'’s, with no job: '.$invoice->number.' is paid exactly.';
    }

    /** "INV-043 replaced it" ({void}:{replacement}): what was paid against the void invoice counts there. */
    private function invoiceReplace(string $id): string
    {
        [$voidId, $toId] = self::pair($id);
        $void = Invoice::query()->findOrFail($voidId);
        $to = Invoice::query()->findOrFail($toId);
        $stranded = InvoicePayments::stranded([$void])[$void->id] ?? 0;
        app(InvoiceClosing::class)->replaceWith($void, $to, 'From the to-do list');

        return 'Saved: '.$to->number.' replaced '.$void->number.($stranded > 0 ? ', so the '.IncomeText::money($stranded).' paid against it counts there.' : '.');
    }

    /** Your decision about a cost, from its button (CostCharges, as you). */
    private function cost(string $code, string $id): string
    {
        $ref = Fingerprints::costRef($code, $id) ?? throw new InvalidArgumentException('Unknown cost.');
        $costs = app(CostCharges::class);
        $invoiceId = $code === 'cost_invoiced' ? (int) substr($id, strrpos($id, ':') + 1) : null;

        match ($code) {
            'cost_charge' => $costs->decide($ref, 'recharge'),
            'cost_no_charge' => $costs->decide($ref, 'no_charge'),
            'cost_elsewhere' => $costs->decide($ref, 'elsewhere'),
            'cost_claimed' => $costs->claimed($ref),
            'cost_invoiced' => $costs->decide($ref, 'invoiced', null, $invoiceId),
            'cost_paid_back' => $costs->outcome($ref, 'paid_back'),
            'cost_not_paid' => $costs->outcome($ref, 'not_paid'),
            'cost_clear' => $costs->clear($ref),
        };

        return match ($code) {
            'cost_charge' => 'It’s to be charged: it’s ticked on the client’s next invoice.',
            'cost_no_charge' => 'Not charged: it stays your own cost.',
            'cost_elsewhere' => 'Charged another way: it won’t go on an invoice.',
            'cost_claimed' => 'Claimed on their expense form: Expend will ask in a month whether it was paid back.',
            'cost_invoiced' => 'Noted: it was on '.(Invoice::query()->whereKey($invoiceId)->value('number') ?? 'that invoice').'.',
            'cost_paid_back' => 'Paid back. Link their payment when it’s in the Inbox, as money back for the cost.',
            'cost_not_paid' => 'Not paid back: it stays your own cost.',
            'cost_clear' => 'Undone: it’s charged by its arrangement again.',
        };
    }

    /**
     * "Invoice it now" on a cost linked after its job's invoice was sent: the client's draft with no job (made, or the
     * open one), which the draft sync fills with their costs from earlier jobs.
     */
    private function invoiceNow(string $id): string
    {
        $ref = CostRef::fromId($id) ?? throw new InvalidArgumentException('Unknown cost.');
        $client = self::clientOfCost($ref);
        if ($client === null) {
            throw new InvalidArgumentException('That cost’s job has no client: open it to say who it’s for.');
        }
        $draft = app(InvoiceService::class)->draftForClient($client);

        return 'A draft for '.$client->name.' is ready with it: check it and press Issue: '.url('/invoices/'.$draft->id);
    }

    /** The client a cost is charged to: its job's paying client, or the client it's for (merges resolved). */
    private static function clientOfCost(?string $ref): ?Client
    {
        if ($ref === null) {
            return null;
        }
        $p = CostRef::parse($ref);
        $clientId = $p['client'];
        if ($clientId === null) {
            $job = $p['job'] ?? match ($p['type']) {
                'travel' => TravelItem::query()->whereKey($p['id'])->value('work_job_id'),
                'trip' => MileageTrip::query()->whereKey($p['id'])->value('work_job_id'),
                default => null,
            };
            $clientId = $job !== null ? WorkJob::query()->whereKey($job)->value('client_id') : null;
        }

        return $clientId === null ? null : Client::query()->find(ClientIds::resolve((int) $clientId));
    }

    /** The open draft with no job of a cost's client, if there is one. */
    private static function clientDraftFor(?string $ref): ?int
    {
        $client = self::clientOfCost($ref);

        return $client === null ? null : Invoice::query()->where('status', 'draft')->whereNull('work_job_id')->where('client_id', $client->id)->orderByDesc('id')->value('id');
    }

    /** Lines saved as your decision for one payment; refused with the writer's own words. */
    private function decideLines(BankTransaction $tx, array $lines, string $reason, bool $overYours = false): void
    {
        $r = $this->decisions->apply(['lines' => $lines, 'reason' => $reason], collect([$tx]), includeProtected: $overYours);
        if ($r['errors']) {
            throw new InvalidArgumentException($r['errors'][0]);
        }
        if (! $r['saved']) {
            throw (new ModelNotFoundException)->setModel(BankTransaction::class, [$tx->id]); // decided by you since
        }
    }

    /** @return array{0:int, 1:int} the two numbers of an id like "381:42" */
    private static function pair(string $id): array
    {
        if (preg_match('/^(\d+):(\d+)$/', $id, $m) !== 1) {
            throw new InvalidArgumentException('Unknown action.');
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Lift a "Not this one": what it held back may be suggested again (Intel\Vetoes::lift, audited as yours). One lifted
     * already says so.
     */
    private function vetoLift(int $id): string
    {
        $veto = Veto::query()->findOrFail($id);
        if (! $veto->isActive()) {
            throw (new ModelNotFoundException)->setModel(Veto::class, [$id]);
        }
        Vetoes::lift($veto, 'Lifted by you');

        return 'Lifted: Expend may suggest it again.';
    }

    /**
     * Add the mileage the to-do list offered for finished jobs (a mileage item: TravelUnclaimed), on your one vehicle
     * on simplified mileage, exactly as the Mileage page's "Add" does (JobTrips::add), as your decision. Bound to what
     * was shown: the id names the year, the set of jobs, the vehicle and the miles (TravelUnclaimed::actionId()), and
     * the item worked out now must offer exactly that again. If anything has changed (a job finished since, trips
     * added, another vehicle, or other miles because your home postcode changed), nothing is added, nothing is
     * looked up, and it says to look again. With no vehicle on simplified mileage, or more than one, it's done on the
     * Mileage page.
     */
    private function tripsAdd(string $id): string
    {
        $key = TravelUnclaimed::keyFor($id);
        if ($key === null) {
            throw new InvalidArgumentException('Unknown mileage.');
        }
        $label = explode(':', $key)[1];
        $vehicle = TravelUnclaimed::onlyVehicle();
        if (! $vehicle) {
            throw new InvalidArgumentException('Choose the vehicle on the Mileage page: '.url('/mileage?year='.$label));
        }
        if (TaxYear::query()->where('label', $label)->whereNotNull('locked_at')->exists()) {
            throw new InvalidArgumentException("{$label} is locked: unlock it on its year page first.");
        }
        $item = collect(TravelUnclaimed::found(TravelUnclaimed::excluded(TodoDismissal::activeKeys()))['items'])->firstWhere('key', $key);
        if ($item === null) {
            $now = collect(TravelUnclaimed::found(TravelUnclaimed::excluded(TodoDismissal::activeKeys()))['items'])->first(fn ($i) => ($i['year'] ?? null) === $label);
            if ($now === null) {
                throw (new ModelNotFoundException)->setModel(MileageTrip::class); // added since, or nothing left to add
            }
            throw new InvalidArgumentException('The jobs for '.$label.' have changed since this was shown. Open the to-do list or the Mileage page again: '.url('/mileage?year='.$label));
        }
        if (! collect($item['actions'] ?? [])->contains(fn (array $a) => $a['code'] === 'trips_add' && $a['id'] === $id)) {
            throw new InvalidArgumentException('The trips for '.$label.' have changed since this was shown (the vehicle or the miles). Open the to-do list or the Mileage page again: '
                .url('/mileage?year='.$label));
        }
        $added = DB::transaction(function () use ($label, $vehicle, $item) {
            $added = JobTrips::add($label, $vehicle->id, $item['job_ids']);
            AuditLog::record('mileage.from_jobs', 'Mileage', null, ['year' => $label, 'vehicle_id' => $vehicle->id, 'jobs' => $item['job_ids'], 'trips' => $added, 'from' => 'todo']);

            return $added;
        });

        return "Added {$added} ".($added === 1 ? 'trip' : 'trips').' for '.count($item['job_ids']).' '.(count($item['job_ids']) === 1 ? 'job' : 'jobs')." on {$vehicle->name}. "
            .'The distances are estimates from postcodes: correct any that differ from your route on the Mileage page.';
    }

    /** A detector's code from a button's id ("job:closeout" for job.closeout: a button's id can't hold a full stop). */
    private static function detectorCode(string $id): string
    {
        return str_replace(':', '.', $id);
    }

    /**
     * Snooze a finding for a week, or mark it not relevant, with a preset reason (a Telegram button: its id is
     * {finding}:{reason}), as from the to-do page (Dismissals). One hidden by you now, or gone, says so and changes
     * nothing. Whether it is, is read as the list reads it now (Dismissals::hiddenNow(), and the findings brought up
     * to date first): the finding's recorded state can be a day behind, and an item back on the list since must take
     * the press. A week's snooze runs to the start of the same weekday next week, so it's back for the next Monday
     * digest. Audited from wherever the press came from (Telegram, or the to-do page).
     */
    private function findingHide(string $id, bool $snooze): string
    {
        if (! preg_match('/^(\d+):([a-z_]+)$/', $id, $m)) {
            throw new InvalidArgumentException('Choose a reason first.');
        }
        $finding = Finding::query()->findOrFail((int) $m[1]);
        $reasons = Dismissals::reasonsFor($finding->code, $snooze);
        if (! isset($reasons[$m[2]])) {
            throw new InvalidArgumentException('Unknown reason.');
        }
        if (! self::stillShown($finding, $snooze)) {
            throw (new ModelNotFoundException)->setModel(Finding::class, [$finding->id]); // dealt with or hidden since
        }
        $message = Dismissals::dismiss($finding->key, $snooze ? self::SNOOZE_DAYS : null, Dismissals::note($m[2], null, $snooze, $finding->code),
            AuditContext::current()['channel'] ?? 'web', fromStartOfDay: true);

        return $snooze ? 'Snoozed for a week: '.$finding->title.'.' : 'Not relevant ('.$reasons[$m[2]].'): '.$finding->title.'. '.preg_replace('/^Marked not relevant[.:]\s*/', '', $message);
    }

    /**
     * Whether a finding's item can still be snoozed ($snooze) or marked not relevant: not hidden by you now (a snooze
     * still running refuses another snooze, not "not relevant"), and not gone. Gone is the finding's recorded state,
     * once the findings are brought up to date with what the list is made from (FindingsRegistry::refresh()).
     */
    public static function stillShown(Finding $finding, bool $snooze): bool
    {
        $hidden = Dismissals::hiddenNow($finding->key);
        if ($hidden === 'dismissed' || ($snooze && $hidden === 'snoozed')) {
            return false;
        }
        if (! in_array($finding->currentState(), ['open', 'unseen'], true)) {
            FindingsRegistry::refresh();
            $finding->refresh();
        }

        return $finding->currentState() !== 'resolved';
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
        // Turnover from a payer with one client is filled in for you to accept, never confirmed on its own: say so.
        if (($r['client'] ?? null) !== null) {
            return $r['message'].' Undo it in Settings › Rules.';
        }

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
        // Money in is never set aside with one tap: it may be a client paying you (income design v3).
        if ($tx->amount_pence > 0) {
            throw new InvalidArgumentException('Money in isn’t marked personal from here: open '.$name.' in the Inbox to say what it was. '.url('/inbox'));
        }
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

    /**
     * Record a payment to HMRC as tax (TodoList's "Payment to HMRC not recorded as tax"), as your decision: it replaces
     * what it was recorded as, which is why you pressed it. A Telegram button pressed after the payment was decided
     * again (in the app, since the message was sent) changes nothing: that later decision is yours too.
     */
    private function txTax(int $id, ?CarbonInterface $offeredAt): string
    {
        $tx = BankTransaction::query()->with('allocations')->findOrFail($id);
        $amount = Money::format(abs($tx->amount_pence), 'GBP', false);
        if ($tx->allocations->isNotEmpty() && $tx->allocations->every(fn ($a) => $a->kind === 'tax_payment' && $a->confirmed_at !== null)) {
            return "Already dealt with: the {$amount} payment to HMRC is recorded as tax.";
        }
        if ($tx->amount_pence >= 0 || ! preg_match(Suggester::HMRC_PATTERN, $tx->description.' '.$tx->counterparty)) {
            throw new InvalidArgumentException('That isn’t a payment to HMRC: decide it in the Inbox.');
        }
        // Split, with part of it tax already: recording it all as tax would undo a split you chose.
        if ($tx->allocations->contains('kind', 'tax_payment')) {
            throw new InvalidArgumentException('Part of it is already recorded as tax, so it wasn’t changed: change the split in Transactions if the rest was tax too.');
        }
        $changed = $tx->allocations->map(fn ($a) => $a->updated_at ?? $a->created_at)->filter()->max();
        if ($offeredAt && $changed && $changed->gt($offeredAt)) {
            throw new InvalidArgumentException('It’s been decided since this message, so it wasn’t changed. Open it in Expend to check: '.url('/transactions?year='.$tx->tax_year));
        }
        $r = $this->decisions->apply(['kind' => 'tax_payment', 'reason' => 'From the to-do list: a payment to HMRC'], collect([$tx]));
        if ($r['errors']) {
            throw new InvalidArgumentException($r['errors'][0]);
        }

        return "Recorded as tax: {$amount} to HMRC on ".$tx->effectiveDate()->format('j M Y').'. Fines and interest now count it.';
    }

    /**
     * Draft the invoice for a finished job, unless it already has one: then point at that instead of making another.
     * A job already invoiced gets a follow-up draft only for costs linked to it after the invoice went (owed late):
     * otherwise nothing new is made. Locks the audit head first, then the job (the order every audited writer uses).
     */
    private function jobInvoice(int $id): string
    {
        return DB::transaction(function () use ($id) {
            AuditLog::lockHead();
            $job = WorkJob::query()->with('client')->lockForUpdate()->findOrFail($id);
            $invoices = Invoice::query()->where('work_job_id', $job->id)->where('status', '!=', 'void')->orderByDesc('id')->get();
            if ($draft = $invoices->firstWhere('status', 'draft')) {
                return "{$job->title} already has a draft invoice, so no new one was made. Check it and press Issue: ".url('/invoices/'.$draft->id);
            }
            if ($issued = $invoices->first(fn (Invoice $i) => in_array($i->status, ['issued', 'paid'], true))) {
                $late = collect(ChargeStatus::forJob($job, false))->contains(fn (array $s) => $s['status'] === 'owed_late' && $s['expected']);
                if (! $late) {
                    return "{$job->title} is already invoiced ({$issued->number}), so no new draft was made.";
                }
                $draft = $this->invoices->draftForJob($job);

                return 'A follow-up draft for '.$job->title.'’s late costs is ready: check it and press Issue: '.url('/invoices/'.$draft->id);
            }
            if ($job->status === 'cancelled' && ! in_array($job->cancel_charge, ['fee', 'costs'], true)) {
                throw new InvalidArgumentException("{$job->title} was cancelled with nothing owed: there’s nothing to invoice.");
            }

            return 'Draft invoice '.$this->invoices->draftForJob($job)->id.' is ready: check it and press Issue.';
        }, 3);
    }

    private function candidateAdd(int $id): string
    {
        $c = JobCandidate::query()->where('status', 'open')->findOrFail($id);
        $job = $this->finder->accept($c, [
            'title' => $c->title, 'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on->format('Y-m-d'),
            'start_time' => $c->start_time, 'end_time' => $c->end_time, 'location' => $c->location, 'postcode' => $c->postcode, 'po_number' => $c->po_number,
            'client_id' => $c->client_name && trim($c->client_name) !== '' ? JobCreator::client($c->client_name, 'you')->id : null,
            'status' => $c->ends_on->isPast() ? 'done' : 'booked',
        ]);

        return "Added {$job->title} and linked what it was found from.";
    }

    /**
     * "Part of <job>": add what a possible job was found from to a job you have, as "Add to job" in the job finder does
     * (JobFinder::merge: only the job's blank details are filled in, and the job is searched again). The id is
     * "<possible job>:<job>", as the to-do list offered it (CandidateText::partOf). Nothing is merged when the job has
     * been cancelled or deleted since, or when you've said since that the entry isn't a job, or isn't part of that job
     * (CandidateText::refused): a press made before you said so never undoes it.
     */
    private function candidateMerge(string $id): string
    {
        if (! preg_match('/^(\d+):(\d+)$/', $id, $m)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        $c = JobCandidate::query()->where('status', 'open')->findOrFail((int) $m[1]);
        $job = WorkJob::query()->find((int) $m[2]);
        if (! $job || $job->status === 'cancelled') {
            throw new InvalidArgumentException('That job has been cancelled or deleted since, so nothing was added to it. Open the possible job to decide: '.url(CandidateText::href($c)));
        }
        $refused = CandidateText::refused($c);
        if ($refused['all'] || in_array($job->id, $refused['jobs'], true)) {
            throw new InvalidArgumentException('You’ve said '.($refused['all'] ? 'its calendar entry isn’t a job' : 'it isn’t part of '.$job->title).', so nothing was added. '.url('/jobs/finder'));
        }
        $this->finder->merge($c, $job, $links);
        $n = $links['linked'] ?? 0;
        $missed = count($links['missed'] ?? []);

        return "Added {$c->title} to {$job->title}: linked {$n} ".($n === 1 ? 'source' : 'sources')
            .($missed ? " ({$missed} not linked: gone, or on another job)" : '').'. Only its blank details were filled in. '.url('/jobs/'.$job->id);
    }

    /**
     * Make an opportunity a job with the details read from its emails (you said yes, or it's booked). A Telegram button
     * pressed after the opportunity changed (a later email moved its dates) does nothing: it's checked in the app first.
     *
     * @param  bool  $bound  the press carried a fingerprint of what it was shown on (already checked: Fingerprints), so
     *                       a follow-up email that changed nothing shown doesn't refuse it
     */
    private function opportunityAdd(int $id, ?CarbonInterface $offeredAt, bool $bound = false): string
    {
        $o = $this->openOpportunity($id, $offeredAt, $bound);
        if (! in_array($o->status, ['offered', 'confirmed'], true)) {
            throw new InvalidArgumentException('It isn’t offered or booked yet: open it under Jobs › Enquiries & offers to add it as a job.');
        }
        // Only on details found word for word in its emails (every day, the title, client and rate); otherwise it's
        // checked on its page first (as the list says).
        if (! $o->oneTapReady()) {
            throw new InvalidArgumentException('Check its details first: '.url('/opportunities/'.$o->id));
        }
        $job = $this->opportunities->accept($o, [], $offeredAt ? 'telegram' : 'you', $links);
        $days = $job->dayList();
        $linked = $links['linked'] ?? 0;

        return "Added {$job->title} (".count($days).' '.(count($days) === 1 ? 'day' : 'days').', '.$job->starts_on->format('j M Y')
            .($job->ends_on->isSameDay($job->starts_on) ? '' : ' to '.$job->ends_on->format('j M Y')).')'
            .($linked ? " and linked {$linked} ".($linked === 1 ? 'email' : 'emails') : '').'. Nothing was sent to anyone. '.url('/jobs/'.$job->id);
    }

    /** Record that you said no to an opportunity, or that it isn't work. Nothing is sent to anyone. */
    private function opportunityDecide(int $id, string $what, ?CarbonInterface $offeredAt, bool $bound = false): string
    {
        $o = $this->openOpportunity($id, $offeredAt, $bound);
        $by = $offeredAt ? 'telegram' : 'you';
        if ($what === 'decline') {
            $this->opportunities->decline($o, 'From the to-do list', $by);

            return "Marked as declined: {$o->title}. Nothing was sent: reply to them yourself if you haven’t.";
        }
        $this->opportunities->dismiss($o, 'From the to-do list', $by);

        return "Dismissed: {$o->title} isn’t work.";
    }

    /**
     * An opportunity still waiting on you, unchanged since the button was offered: what it shows, by the button's
     * fingerprint ($bound, checked already); or, for a press without one, not saved at all since the message's date.
     */
    private function openOpportunity(int $id, ?CarbonInterface $offeredAt, bool $bound = false): Opportunity
    {
        $o = Opportunity::query()->findOrFail($id);
        if (! $o->isOpen()) {
            throw (new ModelNotFoundException)->setModel(Opportunity::class, [$id]); // decided, a job, or closed since
        }
        if (! $bound && $offeredAt && $o->updated_at && $o->updated_at->gt($offeredAt)) {
            throw new InvalidArgumentException('It’s changed since this message. Open it in Expend to check: '.url('/opportunities/'.$o->id));
        }

        return $o;
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
