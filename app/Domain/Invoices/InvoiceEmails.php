<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\CostClass;
use App\Domain\Charges\CostWords;
use App\Domain\Clients\ClientIds;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailDrafts;
use App\Domain\Income\IncomeClient;
use App\Domain\Jobs\JobCreator;
use App\Jobs\CheckInvoiceEmails;
use App\Jobs\PrepareInvoiceEmail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * An issued invoice's email, through your Gmail: put in your drafts in the booking's conversation, then sent by your
 * press (in Gmail, with Send in Expend, or Issue and send), never by Expend on its own.
 *
 *  - prepare(): writes the draft (BookingThread picks the conversation; GmailDrafts builds it). It's idempotent: a
 *    retry adopts the draft an earlier try made (its X-Expend-Ref header, or in the conversation its PDF when your edit
 *    dropped the header), so there's never a second draft; one you changed in Gmail meanwhile is never taken for
 *    Expend's own as made (no one-tap Send, never deleted), and one you already sent from Gmail is recorded as sent. In
 *    the issuing request it waits at most 10 seconds for Gmail; if Gmail doesn't answer, PrepareInvoiceEmail tries
 *    again (the page says 'Putting it in Gmail…' meanwhile). A try that Gmail answered without Expend hearing never
 *    leaves a draft nobody tracks: failed() looks for it first, and when the invoice goes another way (emailed from
 *    Expend, marked sent) Expend's own draft is taken out (clearStray). A draft made while the invoice was sent,
 *    voided or (a reminder) paid is taken out again.
 *  - preview() and send(): Send now reads the draft as it is in Gmail and sends exactly that version; edited in Gmail
 *    since, it's refused ('You've changed it in Gmail since').
 *  - issueToGmail() and issueAndSend(): Issue and put in Gmail drafts, and Issue and send to <address>, in one press.
 *  - markSentSelf(): 'I sent it myself'.
 *  - check(): sees a draft sent from Gmail (sent_at, sent_to and, when it has none, the invoice's Message-ID from the
 *    sent message), deleted unsent, or edited; Expend's own unedited draft of a voided invoice is taken out.
 *  - oneTapProblem() and sendOneTap(): Telegram's one-tap Send, only for a draft exactly as Expend made it, going to an
 *    address an earlier invoice to this client went to.
 *
 * One route at a time: while a draft of the invoice (or of its reminder) is open, InvoiceMailer refuses to send that
 * kind. Rows in invoice_emails record each draft and send; every change you'd care about is audited (invoice.
 * gmail_drafted and reminder_drafted, sent and reminder_sent, marked_sent, gmail_draft_deleted, gmail_draft_removed,
 * gmail_failed). Allowed in any year: none of it touches the ledger.
 */
final class InvoiceEmails
{
    /** How long a call to Gmail may take while you wait (the issuing request, Send now), in seconds. */
    public const TIMEOUT = 10;

    /** The code of a refusal that means the draft changed since you looked (InvoiceService::CHANGED). */
    public const CHANGED = InvoiceService::CHANGED;

    /** Where "Expend can't see Gmail" is kept: since when, and why (cleared by the next check that works). */
    public const BLIND_SETTING = 'invoice_emails_blind';

    /** How long a try to put a draft in Gmail holds its lock (seconds), so two tries never both make one. */
    private const LOCK_SECONDS = 120;

    /** The retry's first try, after the issuing request's (seconds). */
    private const RETRY_AFTER = 60;

    private ?int $timeout = null;

    public function __construct(private BookingThread $threads) {}

    /** The same, waiting at most TIMEOUT seconds for each call to Gmail: for a request you're waiting on. */
    public function whileYouWait(): self
    {
        $copy = clone $this;
        $copy->timeout = self::TIMEOUT;

        return $copy;
    }

    /** Whether invoices can go through Gmail: connected, with permission to write drafts. */
    public static function canUse(): bool
    {
        return GmailClient::canModify();
    }

    /** The draft of this kind open in Gmail now, if any. */
    public static function open(Invoice $invoice, string $kind = 'invoice'): ?InvoiceEmail
    {
        return InvoiceEmail::query()->open()->where('invoice_id', $invoice->id)->where('kind', $kind)->latest('id')->first();
    }

    /** The newest row of this kind (any route and status). */
    public static function latest(Invoice $invoice, string $kind = 'invoice'): ?InvoiceEmail
    {
        return InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', $kind)->latest('id')->first();
    }

    /**
     * "Expend can't see Gmail": since when and why, while checks can't read it (null when they can).
     *
     * @return array{since:string, why:string, told?:bool}|null
     */
    public static function cantSee(): ?array
    {
        $blind = Setting::get(self::BLIND_SETTING);

        return is_array($blind) ? $blind : null;
    }

    /** Who it could go to, for the issue sheet's To chips: the invoice's address, the client's, and where earlier invoices went. */
    public function addresses(Invoice $invoice): array
    {
        $client = IncomeClient::ofInvoice($invoice);

        return collect([$invoice->bill_to_email, $client ? Client::query()->find($client)?->billing_email : null])
            ->merge($this->usedAddresses($invoice, false))
            ->map(fn ($a) => InvoiceService::emailAddress($a))->filter()->unique(fn (string $a) => strtolower($a))->values()->all();
    }

    /**
     * Issue the draft and put it in your Gmail drafts, in the booking's conversation (Issue and put in Gmail drafts).
     * The invoice is issued whatever Gmail does; if Gmail doesn't answer, Expend tries again and says so.
     *
     * @param  array|null  $shown  what the issue sheet showed you (InvoiceService::issue)
     * @param  string|null  $to  the address chosen on the sheet ('' for none: you add it in Gmail; null: the invoice's)
     * @param  string|null  $thread  a conversation from BookingThread::candidates, 'new' for a new email, or null for the best one
     * @return array{invoice:Invoice, email:?InvoiceEmail, message:string}
     */
    public function issueToGmail(Invoice $draft, ?string $issueDate = null, ?array $shown = null, ?string $to = null, ?string $thread = null): array
    {
        $this->assertCanUse();
        self::assertThread($thread);
        // '' (no address: you add it in Gmail) is kept apart from null (the invoice's own address).
        $to = $to === null ? null : (self::address($to, true) ?? '');
        $invoice = app(InvoiceService::class)->issue($draft, $issueDate, $shown);
        // After the issue's commit (at once, unless a caller holds a transaction open: then when it commits).
        $email = false;
        DB::afterCommit(function () use (&$email, $invoice, $to, $thread) {
            $email = $this->prepareOrRetry($invoice, 'invoice', ['to' => $to, 'thread' => $thread]);
        });
        if ($email === false) {
            return ['invoice' => $invoice, 'email' => null, 'message' => $invoice->number.' issued. Putting it in Gmail…'];
        }

        return ['invoice' => $invoice, 'email' => $email, 'message' => $invoice->number.' issued. '.self::whereItIs($invoice, $email)];
    }

    /**
     * Issue the draft, put it in the booking's conversation and send it, in one press (Issue and send to <address>).
     * The sheet showed the To, the subject and the amount; the address must be one. If Gmail doesn't answer, the
     * invoice stays issued and goes in your drafts when it can, never sent twice: sending is tied to the draft.
     *
     * @param  array|null  $shown  what the issue sheet showed you (InvoiceService::issue), plus total (pence) when shown
     * @return array{invoice:Invoice, email:?InvoiceEmail, message:string, sent:bool}
     */
    public function issueAndSend(Invoice $draft, string $to, ?string $issueDate = null, ?array $shown = null, ?string $thread = null): array
    {
        $this->assertCanUse();
        self::assertThread($thread);
        if (trim($to) === '') {
            throw new InvalidArgumentException('Issue and send needs the address it goes to: pick one, or put it in Gmail drafts instead.');
        }
        $to = (string) self::address($to, false);
        if (isset($shown['total']) && (int) $shown['total'] !== (int) Invoice::query()->whereKey($draft->id)->value('total_minor')) {
            throw new InvalidArgumentException('The total changed since you looked: check it and issue again.', self::CHANGED);
        }
        $shown = $shown === null ? null : (array_diff_key($shown, ['total' => true]) ?: null);
        $invoice = app(InvoiceService::class)->issue($draft, $issueDate, $shown);
        // After the issue's commit (at once, unless a caller holds a transaction open: then when it commits).
        $result = null;
        DB::afterCommit(function () use (&$result, $invoice, $to, $thread) {
            $result = $this->draftAndSend($invoice, $to, $thread);
        });

        return $result ?? ['invoice' => $invoice, 'email' => null, 'sent' => false, 'message' => $invoice->number.' issued. Putting it in Gmail…'];
    }

    /**
     * The rest of Issue and send, once it's issued: put it in Gmail, then send exactly that draft.
     *
     * @return array{invoice:Invoice, email:?InvoiceEmail, message:string, sent:bool}
     */
    private function draftAndSend(Invoice $invoice, string $to, ?string $thread): array
    {
        $email = $this->prepareOrRetry($invoice, 'invoice', ['to' => $to, 'thread' => $thread]);
        if ($email?->status !== 'drafted') {
            return ['invoice' => $invoice, 'email' => $email, 'sent' => false,
                'message' => $invoice->number.' issued, but it isn’t sent: '.lcfirst(self::whereItIs($invoice, $email))];
        }
        try {
            $message = $this->whileYouWait()->send($invoice->fresh(), (string) $email->draft_message_id);
        } catch (Throwable $e) {
            report($e);

            return ['invoice' => $invoice, 'email' => $email->fresh(), 'sent' => false,
                'message' => $invoice->number.' issued and in your Gmail drafts, but Gmail didn’t send it: send it from there.'];
        }

        return ['invoice' => $invoice->fresh(), 'email' => $email->fresh(), 'sent' => true, 'message' => $invoice->number.' issued. '.$message];
    }

    /**
     * Put it in Gmail, waiting at most TIMEOUT seconds; if Gmail doesn't answer, PrepareInvoiceEmail tries again. Never
     * throws for Gmail's sake (the invoice is already issued): the row says what happened.
     *
     * @param  array{to?:?string, thread?:?string, stage?:?int}  $options
     */
    public function prepareOrRetry(Invoice $invoice, string $kind, array $options): ?InvoiceEmail
    {
        try {
            return $this->whileYouWait()->prepare($invoice, $kind, $options);
        } catch (InvalidArgumentException $e) {
            return $this->failed($invoice, $kind, $e->getMessage());
        } catch (Throwable $e) {
            if (! self::transient($e)) {
                report($e);

                return $this->failed($invoice, $kind, self::why($e));
            }
            if (Queue::connection() instanceof SyncQueue) {
                // No queue to try later from: say so now, with [Try again] on the page.
                return $this->failed($invoice, $kind, 'Gmail didn’t answer');
            }
            PrepareInvoiceEmail::dispatch((int) $invoice->id, $kind)->delay(now()->addSeconds(self::RETRY_AFTER));

            return self::latest($invoice, $kind);
        }
    }

    /**
     * Put the invoice (or a reminder) in your Gmail drafts, or find the draft already there.
     *
     * Idempotent: a draft open for it is returned as it is; a draft an earlier try made in Gmail (its X-Expend-Ref) is
     * adopted. Takes the audit head, then the invoice's row, to claim the row (one try at a time per invoice and kind),
     * calls Gmail outside the lock, then records it under the lock again.
     *
     * @param  array{to?:?string, thread?:?string, stage?:?int}|null  $options  what you chose; null for a retry of the
     *                                                                          try that's waiting (nothing to do when none is)
     * @return InvoiceEmail|null the row: drafted, or still pending when another try holds it
     *
     * @throws InvalidArgumentException when it can't go in Gmail (not issued, already sent, the PDF isn't the one issued)
     * @throws Throwable when Gmail fails (see transient())
     */
    public function prepare(Invoice $invoice, string $kind = 'invoice', ?array $options = []): ?InvoiceEmail
    {
        if (! in_array($kind, InvoiceEmail::KINDS, true)) {
            throw new InvalidArgumentException("An invoice email can't be a '{$kind}'.");
        }
        $this->assertCanUse();
        $choice = $options === null ? null : $this->choose($invoice, $kind, $options);

        $claim = DB::transaction(function () use ($invoice, $kind, $options, $choice) {
            AuditLog::lockHead();
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($fresh->status !== 'issued') {
                throw new InvalidArgumentException('Only an issued invoice can go in Gmail.');
            }
            if ($open = self::open($fresh, $kind)) {
                return $open;
            }
            $pending = InvoiceEmail::query()->where('invoice_id', $fresh->id)->where('kind', $kind)->where('status', 'pending')->latest('id')->first();
            if ($kind === 'invoice' && $fresh->sent_at !== null) {
                // Kept (not rolled back with a refusal): a try still waiting is over, another route took over.
                $pending?->forceFill(['status' => 'replaced', 'error' => 'Sent another way'])->save();

                return ['refused' => "Invoice {$fresh->number} was already sent on ".$fresh->sent_at->format('j M Y').', so it wasn’t put in Gmail again.',
                    'retired' => $pending ?? self::stray($fresh)];
            }
            if ($kind === 'reminder' && $pending !== null && ($over = $this->reminderOver($fresh, $pending)) !== null) {
                // Paid, closed or reminded another way since you asked: the waiting try writes nothing.
                $pending->forceFill(['status' => 'replaced', 'error' => Str::limit($over, 240)])->save();

                return ['refused' => $over, 'retired' => $pending];
            }
            if ($options === null) {
                return $pending;
            }
            $values = ['to_address' => $choice['to'], 'gmail_thread_id' => $choice['thread'], 'stage' => $choice['stage'], 'error' => null];
            if ($pending) {
                $pending->forceFill($values)->save();

                return $pending;
            }

            return InvoiceEmail::query()->create($values + ['invoice_id' => $fresh->id, 'kind' => $kind, 'route' => 'gmail', 'status' => 'pending']);
        });
        if (is_array($claim)) {
            if ($claim['retired'] !== null) {
                // Gmail may have made the retired try's draft without saying so: it isn't left there to be sent too.
                $this->clearStray($invoice, $claim['retired']);
            }
            throw new InvalidArgumentException($claim['refused']);
        }
        if ($claim === null || $claim->status !== 'pending') {
            return $claim;
        }

        $lock = Cache::lock('invoice-email:'.$invoice->id.':'.$kind, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $claim->fresh(); // another try is putting it in Gmail right now
        }
        try {
            try {
                $made = $this->draft(Invoice::query()->findOrFail($invoice->id), $claim);
            } catch (Throwable $e) {
                $claim->forceFill(['error' => Str::limit(self::why($e), 240)])->save();
                throw $e;
            }
            if (isset($made['sent'])) {
                // An earlier try's draft, sent by you from Gmail before this try: recorded, never drafted again.
                $this->recordSent($claim, $made['sent'], 'system', 'Sent from your Gmail');

                return $claim->fresh();
            }
            $row = $this->record($invoice, $claim, $made, $kind);
        } finally {
            $lock->release();
        }
        if ($kind === 'invoice' && $row->status === 'drafted') {
            CheckInvoiceEmails::later((int) $invoice->id);
        }

        return $row;
    }

    /**
     * A try that won't be retried: the waiting row is marked failed with why, for the to-do ('INV-0042 isn't in your
     * Gmail drafts yet: Gmail didn't answer [Try again] [Email it from Expend]').
     */
    public function failed(Invoice $invoice, string $kind, string $why): ?InvoiceEmail
    {
        // Gmail may have made the draft without answering: once it answers, that draft is kept (adopted), never hidden
        // behind 'failed'. While it still doesn't answer, the row is failed, and the mailer looks again before it sends.
        $pending = InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', $kind)->where('status', 'pending')->latest('id')->first();
        $issued = Invoice::query()->find($invoice->id);
        if ($pending !== null && $issued?->status === 'issued' && self::canUse()) {
            try {
                $found = $this->whileYouWait()->locate($issued, $pending);
                if ($found !== null && isset($found['sent'])) {
                    $this->recordSent($pending, $found['sent'], 'system', 'Sent from your Gmail');

                    return $pending->fresh();
                }
                if ($found !== null) {
                    $row = $this->record($issued, $pending, $found, $kind);
                    if ($kind === 'invoice' && $row->status === 'drafted') {
                        CheckInvoiceEmails::later((int) $issued->id);
                    }

                    return $row;
                }
            } catch (Throwable $e) {
                if (! self::transient($e) && ! self::isBlind($e)) {
                    report($e);
                }
            }
        }

        return DB::transaction(function () use ($invoice, $kind, $why) {
            AuditLog::lockHead();
            $fresh = Invoice::query()->lockForUpdate()->find($invoice->id);
            $row = InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', $kind)->where('status', 'pending')->latest('id')->first();
            if ($fresh === null || $row === null) {
                return $row ?? ($fresh ? self::latest($fresh, $kind) : null);
            }
            $row->forceFill(['status' => 'failed', 'error' => Str::limit($why, 240)])->save();
            AuditLog::record('invoice.gmail_failed', $fresh, null, ['number' => $fresh->number, 'kind' => $kind, 'error' => $row->error], 'It isn’t in your Gmail drafts', 'system');

            return $row;
        });
    }

    /**
     * The draft as it is in Gmail now, for the Send sheet: From, To, subject, attachments and its first lines, with its
     * version (send() sends only that version).
     *
     * @return array{email_id:int, version:string, edited:bool, from:?string, to:?string, subject:?string, files:list<string>, text:string}
     */
    public function preview(Invoice $invoice, string $kind = 'invoice'): array
    {
        $row = self::open($invoice, $kind) ?? throw new InvalidArgumentException(self::name($invoice, $kind).' isn’t in your Gmail drafts.');
        $draft = $this->gmail()->get((string) $row->gmail_draft_id, 'full');
        if ($draft === null) {
            throw new InvalidArgumentException($this->gone($row));
        }
        $m = $draft['message'];
        $row->forceFill(['current_message_id' => $m['id'], 'checked_at' => now()])->save();
        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $m['text']) ?: []), fn ($l) => $l !== '')), 0, 8);

        return ['email_id' => (int) $row->id, 'version' => $m['id'], 'edited' => $m['id'] !== $row->draft_message_id, 'from' => $m['headers']['from'] ?? null,
            'to' => $m['headers']['to'] ?? null, 'subject' => $m['headers']['subject'] ?? null, 'files' => $m['files'], 'text' => implode("\n", $lines)];
    }

    /**
     * Send now: the draft in Gmail, exactly as the version you were shown (preview()'s version, or for a one-tap Send the
     * draft as Expend made it). Edited since: refused, with code CHANGED.
     *
     * @return string what happened, for a message
     */
    public function send(Invoice $invoice, string $version, string $kind = 'invoice'): string
    {
        $row = self::open($invoice, $kind) ?? throw new InvalidArgumentException(self::name($invoice, $kind).' isn’t in your Gmail drafts.');
        if ($invoice->fresh()?->status !== 'issued') {
            throw new InvalidArgumentException('Only an issued invoice can be sent.');
        }
        if ($kind === 'reminder') {
            $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;
            if ($r['outstanding'] <= 0) {
                throw new InvalidArgumentException("Invoice {$invoice->number} is ".($r['state'] === 'closed' ? 'closed' : 'paid in full').', so the reminder wasn’t sent.');
            }
        }
        $lock = Cache::lock('invoice-email:'.$invoice->id.':'.$kind, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new InvalidArgumentException('It’s being sent or put in Gmail right now: look again in a minute.');
        }
        try {
            $gmail = $this->gmail();
            $draft = $gmail->get((string) $row->gmail_draft_id);
            if ($draft === null) {
                throw new InvalidArgumentException($this->gone($row));
            }
            if ($draft['message']['id'] !== $version) {
                $row->forceFill(['current_message_id' => $draft['message']['id'], 'checked_at' => now()])->save();
                throw new InvalidArgumentException('You’ve changed it in Gmail since: send it there, or look again.', self::CHANGED);
            }
            $sent = $gmail->send((string) $row->gmail_draft_id);
            $message = $gmail->message($sent['message_id']) ?? ['id' => $sent['message_id'], 'thread_id' => $sent['thread_id'], 'headers' => $draft['message']['headers'], 'at' => now()->timestamp];
            $to = $this->recordSent($row, $message, 'you');
        } finally {
            $lock->release();
        }

        return ($kind === 'reminder' ? 'The reminder for '.$invoice->number : 'Invoice '.$invoice->number).' is on its way'.($to ? ' to '.$to : '').'.';
    }

    /**
     * Why Telegram's one-tap Send can't be offered (or pressed) for this draft, or null when it can: the draft is exactly
     * as Expend made it (live: read from Gmail), and goes to an address an earlier invoice to this client was sent to
     * (for a reminder, the invoice itself counts). A reminder isn't one-tap while it's paid or a payment that may be for
     * it is waiting. The reason never shows the address.
     */
    public function oneTapProblem(Invoice $invoice, string $kind = 'invoice', bool $live = true): ?string
    {
        $row = self::open($invoice, $kind);
        if ($row === null) {
            return 'It isn’t in your Gmail drafts.';
        }
        if ($invoice->fresh()?->status !== 'issued') {
            return 'Only an issued invoice can be sent.';
        }
        if ($kind === 'reminder') {
            $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;
            if ($r['outstanding'] <= 0) {
                return 'It’s paid or closed.';
            }
            if ($r['waiting'] > 0) {
                return 'A payment that may be for it is waiting in the Inbox.';
            }
        }
        $to = strtolower(trim((string) $row->to_address));
        if ($to === '') {
            return 'It has no address yet: add one in Gmail and send it there.';
        }
        if (! in_array($to, array_map('strtolower', $this->usedAddresses($invoice, $kind === 'reminder')), true)) {
            return 'No earlier invoice to this client went to that address: send it from Gmail or Expend, where you can see it.';
        }
        if (! $live) {
            return $row->current_message_id !== null && $row->current_message_id !== $row->draft_message_id ? 'It was changed in Gmail: send it there.' : null;
        }
        $draft = $this->gmail()->get((string) $row->gmail_draft_id);
        if ($draft === null) {
            return 'It isn’t in your Gmail drafts any more.';
        }
        $row->forceFill(['current_message_id' => $draft['message']['id'], 'checked_at' => now()])->save();
        $sentTo = strtolower((string) (self::addressIn($draft['message']['headers']['to'] ?? null) ?? ''));
        if ($draft['message']['id'] !== $row->draft_message_id || $sentTo !== $to || isset($draft['message']['headers']['cc'])) {
            return 'It was changed in Gmail: send it there.';
        }

        return null;
    }

    /** Telegram's one-tap Send: only when oneTapProblem() finds none, then exactly the draft Expend made. */
    public function sendOneTap(Invoice $invoice, string $kind = 'invoice'): string
    {
        if ($problem = $this->oneTapProblem($invoice, $kind)) {
            return 'Not sent: '.lcfirst($problem);
        }

        return $this->send($invoice, (string) self::open($invoice, $kind)->draft_message_id, $kind);
    }

    /**
     * "I sent it myself" (or "I sent it another way"): marked as sent, by hand. A draft of it still in Gmail is checked
     * first (it may just have been sent from there); one still unsent is taken out when it's Expend's own and unedited,
     * so it can't be sent a second time.
     */
    public function markSentSelf(Invoice $invoice, ?string $to = null): string
    {
        $to = $to === null || trim($to) === '' ? null : (self::address($to, false) ?? throw new InvalidArgumentException('That email address doesn’t look right.'));
        $open = self::open($invoice, 'invoice');
        if ($open !== null && self::canUse()) {
            try {
                if ($this->check($open) === 'sent') {
                    return "Invoice {$invoice->number} was sent from your Gmail: it’s marked as sent.";
                }
            } catch (Throwable $e) {
                report($e); // marked by hand all the same
            }
        }

        $done = DB::transaction(function () use ($invoice, $to) {
            AuditLog::lockHead();
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($fresh->status, InvoicePayments::PAYABLE, true)) {
                throw new InvalidArgumentException('Only an issued invoice can be marked as sent.');
            }
            if ($fresh->sent_at !== null) {
                return [$fresh, null, false];
            }
            $fresh->forceFill(['sent_at' => now()->startOfSecond(), 'sent_to' => $to])->save();
            $draft = self::open($fresh, 'invoice');
            $draft?->forceFill(['status' => 'replaced', 'error' => 'Sent another way'])->save();
            $pending = self::retirePending($fresh);
            InvoiceEmail::query()->create(['invoice_id' => $fresh->id, 'kind' => 'invoice', 'route' => 'by_hand', 'status' => 'sent', 'to_address' => $to, 'sent_at' => $fresh->sent_at]);
            AuditLog::record('invoice.marked_sent', $fresh, null, array_filter(['number' => $fresh->number, 'to' => $to], fn ($v) => $v !== null), 'You sent it yourself');

            return [$fresh, $draft, true, $pending];
        });
        [$fresh, $draft, $marked, $pending] = $done + [3 => null];
        // A try Gmail may have answered without Expend hearing (waiting, or failed): its draft isn't left to be sent too.
        $stray = $marked ? ($pending ?? self::stray($fresh)) : null;
        if ($stray !== null) {
            $this->clearStray($fresh, $stray);
        }
        if (! $marked) {
            return "Invoice {$fresh->number} was already marked as sent on ".$fresh->sent_at->format('j M Y').'.';
        }

        $note = '';
        if ($draft !== null) {
            try {
                $note = $this->takeOut($draft, 'you sent it another way', 'you');
                $note = $note === '' ? ' You edited its Gmail draft, so it was left there: delete it so it isn’t sent twice.' : ' '.$note;
            } catch (Throwable $e) {
                report($e);
                $note = ' Its Gmail draft may still be there: delete it so it isn’t sent twice.';
            }
        }

        return "Invoice {$fresh->number} is marked as sent.".$note;
    }

    /**
     * Check every open draft (or one invoice's), as CheckInvoiceEmails does: sent, deleted, edited or still there.
     * When Gmail can't be read, nothing else is done and "Expend can't see Gmail" is kept until a check works.
     *
     * @return array{checked:int, sent:int, deleted:int, removed:int, later:int, blind:bool}
     */
    public function checkAll(?int $invoiceId = null): array
    {
        $out = ['checked' => 0, 'sent' => 0, 'deleted' => 0, 'removed' => 0, 'later' => 0, 'blind' => false];
        $rows = InvoiceEmail::query()->open()->when($invoiceId, fn ($q) => $q->where('invoice_id', $invoiceId))->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return $out;
        }
        if (! self::canUse()) {
            self::blind('Gmail isn’t connected, or Expend can’t write drafts there');

            return ['blind' => true] + $out;
        }
        foreach ($rows as $row) {
            try {
                $outcome = $this->check($row);
            } catch (Throwable $e) {
                if (self::transient($e)) {
                    $out['later']++;

                    continue;
                }
                if (self::isBlind($e)) {
                    self::blind(self::why($e));

                    return ['blind' => true] + $out;
                }
                report($e);
                $out['later']++;

                continue;
            }
            $out['checked']++;
            if (isset($out[$outcome])) {
                $out[$outcome]++;
            }
        }
        if ($out['later'] === 0 || $out['checked'] > 0) {
            Setting::put(self::BLIND_SETTING, null);
        }

        return $out;
    }

    /**
     * Check one open draft against Gmail.
     *
     * @return string drafted (still there, as made), edited (still there, changed in Gmail), sent (sent from Gmail:
     *                recorded), deleted (gone, nothing sent), removed (Expend took its own draft of a void invoice out),
     *                or the row's status when it isn't open
     */
    public function check(InvoiceEmail $row): string
    {
        if ($row->status !== 'drafted') {
            return $row->status;
        }
        $gmail = $this->gmail();
        $draft = $gmail->get((string) $row->gmail_draft_id);
        if ($draft !== null) {
            $row->forceFill(['current_message_id' => $draft['message']['id'], 'checked_at' => now()])->save();
            if (Invoice::query()->whereKey($row->invoice_id)->value('status') === 'void') {
                return $this->takeOut($row, 'it was voided', 'system') === '' ? 'edited' : 'removed';
            }

            return $draft['message']['id'] === $row->draft_message_id ? 'drafted' : 'edited';
        }

        $invoice = Invoice::query()->find($row->invoice_id);
        $sent = $invoice ? $this->sentMessage($row, $invoice) : null;
        if ($sent !== null) {
            $this->recordSent($row, $sent, 'system', 'Sent from your Gmail');

            return 'sent';
        }
        DB::transaction(function () use ($row, $invoice) {
            AuditLog::lockHead();
            $fresh = InvoiceEmail::query()->lockForUpdate()->find($row->id);
            if ($fresh?->status !== 'drafted') {
                return;
            }
            $fresh->forceFill(['status' => 'deleted', 'checked_at' => now()])->save();
            if ($invoice) {
                AuditLog::record('invoice.gmail_draft_deleted', $invoice, null, ['number' => $invoice->number, 'kind' => $fresh->kind, 'draft' => $fresh->gmail_draft_id],
                    'The Gmail draft was deleted without being sent', 'system');
            }
        });
        $row->refresh();

        return 'deleted';
    }

    /**
     * Take Expend's own draft out of Gmail, when you haven't edited it: the row is marked removed (or replaced, when
     * you sent it another way or a later reminder takes its place). An edited draft is left, with a note on the row.
     *
     * @return string what happened, for a message ('' when an edited draft was left)
     */
    public function takeOut(InvoiceEmail $row, string $why, string $actor = 'system', string $status = 'removed'): string
    {
        $what = $row->kind === 'reminder' ? 'The reminder for ' : 'The Gmail draft of ';
        $number = (string) Invoice::query()->whereKey($row->invoice_id)->value('number');
        $gmail = $this->gmail();
        $draft = $row->gmail_draft_id ? $gmail->get((string) $row->gmail_draft_id) : null;
        if ($draft !== null && $draft['message']['id'] !== $row->draft_message_id) {
            $note = 'You edited it, so it was left in your drafts: '.$why.'.';
            if ($row->error !== $note) {
                $row->forceFill(['current_message_id' => $draft['message']['id'], 'error' => Str::limit($note, 240), 'checked_at' => now()])->save();
            }

            return '';
        }
        if ($draft !== null) {
            $gmail->delete((string) $row->gmail_draft_id);
        }
        DB::transaction(function () use ($row, $why, $actor, $status) {
            AuditLog::lockHead();
            $invoice = Invoice::query()->lockForUpdate()->find($row->invoice_id);
            $fresh = InvoiceEmail::query()->lockForUpdate()->find($row->id);
            if ($fresh === null || ! in_array($fresh->status, ['drafted', 'replaced'], true)) {
                return;
            }
            $fresh->forceFill(['status' => $fresh->status === 'replaced' ? 'replaced' : $status, 'error' => Str::limit(ucfirst($why), 240), 'checked_at' => now()])->save();
            if ($invoice) {
                AuditLog::record('invoice.gmail_draft_removed', $invoice, null, ['number' => $invoice->number, 'kind' => $fresh->kind, 'draft' => $fresh->gmail_draft_id],
                    Str::limit(ucfirst($why), 200), $actor);
            }
        });
        $row->refresh();

        return $what.$number.' was taken out of your drafts: '.$why.'.';
    }

    /** Whether a Gmail failure may pass if tried again: no answer, too many requests, or Google's own error. */
    public static function transient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RuntimeException && in_array($e->getCode(), [408, 429], true))
            || ($e instanceof RuntimeException && $e->getCode() >= 500 && $e->getCode() < 600);
    }

    /** Whether a failure means Expend can't read Gmail at all (not connected, access ended or refused). */
    public static function isBlind(Throwable $e): bool
    {
        return $e instanceof RuntimeException && ! $e instanceof InvalidArgumentException && in_array($e->getCode(), [0, 401, 403], true);
    }

    /** What the to-do and Telegram say a draft is called: "Invoice INV-0042", "The reminder for INV-0042". */
    private static function name(Invoice $invoice, string $kind): string
    {
        return $kind === 'reminder' ? 'The reminder for '.$invoice->number : 'Invoice '.$invoice->number;
    }

    /** Where the invoice's email is now, after issuing it, in a sentence. */
    private static function whereItIs(Invoice $invoice, ?InvoiceEmail $email): string
    {
        return match ($email?->status) {
            'drafted' => 'The email is in your Gmail drafts, ready to send.',
            'pending' => 'Putting it in Gmail… Gmail didn’t answer, so Expend will try again in a minute.',
            'failed' => 'It isn’t in your Gmail drafts: '.self::reason((string) $email->error).'. Try again, or email it from Expend.',
            default => 'It isn’t in your Gmail drafts.',
        };
    }

    /** A reason to follow a colon: lower case unless it starts with a name (Gmail, Google, Expend, INV-0042). */
    public static function reason(string $why): string
    {
        $why = rtrim(trim($why), '.');

        return preg_match('/^(Gmail|Google|Expend|[A-Z]{2,}|[A-Z]+-\d)/', $why) === 1 ? $why : lcfirst($why);
    }

    private function assertCanUse(): void
    {
        if (! self::canUse()) {
            throw new InvalidArgumentException('Connect Gmail (with permission to write drafts) to put invoices in the booking conversation: issue it only, or email it from Expend.');
        }
    }

    /**
     * The To and the conversation chosen, worked out before any lock (finding the booking's conversation may search Gmail).
     *
     * @return array{to:?string, thread:?string, stage:?int}
     */
    private function choose(Invoice $invoice, string $kind, array $options): array
    {
        $route = $this->threads->route($invoice);
        if ($kind === 'invoice') {
            $to = array_key_exists('to', $options) && $options['to'] !== null
                ? self::address((string) $options['to'], true)
                : ($this->addresses($invoice)[0] ?? null);
            $thread = $options['thread'] ?? null;
            $thread = match (true) {
                in_array($route, ['new', 'none'], true), $thread === 'new' => null,
                is_string($thread) && $thread !== '' => self::threadId($thread),
                default => $this->threads->candidates($invoice, true)[0]['thread_id'] ?? null,
            };

            return ['to' => $to, 'thread' => $thread, 'stage' => null];
        }

        $stage = (int) ($options['stage'] ?? 0);
        if (! in_array($stage, [1, 2, 3], true)) {
            throw new InvalidArgumentException('A reminder needs its stage (1, 2 or 3).');
        }
        $thread = $options['thread'] ?? null;

        return [
            'to' => array_key_exists('to', $options) && $options['to'] !== null ? self::address((string) $options['to'], true) : null,
            'thread' => $thread === null || $thread === 'new' ? null : self::threadId($thread), 'stage' => $stage,
        ];
    }

    /**
     * Make the draft in Gmail, or find what an earlier try left: its draft (adopted), or that draft already sent by you
     * from Gmail (['sent' => the message], recorded by the caller).
     *
     * @return array{id:string, message_id:string, thread_id:?string, to:?string, subject:string, adopted:bool, edited:bool, new_email:bool}|array{sent:array}
     */
    private function draft(Invoice $invoice, InvoiceEmail $row): array
    {
        $pdf = $invoice->pdf_path ? Storage::disk('local')->get($invoice->pdf_path) : null;
        if ($pdf === null || ! $invoice->pdf_sha256 || ! hash_equals((string) $invoice->pdf_sha256, hash('sha256', $pdf))) {
            throw new InvalidArgumentException("The PDF of invoice {$invoice->number} isn’t the one issued, so it wasn’t put in Gmail.");
        }
        $plan = $this->plan($invoice, $row);
        if ($found = $this->locate($invoice, $row, $plan)) {
            return $found;
        }
        ['thread' => $thread, 'target' => $target, 'subject' => $subject, 'ref' => $ref, 'to' => $to] = $plan;
        $references = $target === null ? [] : array_slice(array_values(array_unique([
            ...GmailDrafts::ids($target['headers']['references'] ?? null), ...GmailDrafts::ids($target['headers']['message-id'] ?? null),
        ])), -20);
        // A reminder for an invoice emailed from Expend (no Gmail conversation) points back to the invoice's own
        // Message-ID, as the mailer's reminders do, so the client's mail program can still show them together.
        $inReplyTo = $target === null ? null : (GmailDrafts::ids($target['headers']['message-id'] ?? null)[0] ?? null);
        if ($target === null && $row->kind === 'reminder' && $invoice->message_id) {
            $inReplyTo = $invoice->message_id;
            $references = [$invoice->message_id];
        }
        $raw = GmailDrafts::mime([
            'from' => (string) (Setting::get('gmail_account') ?: Setting::get('business_email')),
            'from_name' => (string) Setting::get('business_name'),
            'to' => $to,
            'subject' => $subject,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'ref' => $ref,
            'text' => self::words($invoice, $row),
            'attachment' => ['name' => $invoice->number.'.pdf', 'data' => $pdf, 'mime' => 'application/pdf'],
        ]);

        return $this->gmail()->create($raw, $thread) + ['to' => $to, 'subject' => $subject, 'adopted' => false, 'edited' => false, 'new_email' => $thread === null];
    }

    /**
     * Where the draft goes and what it's called: the conversation (gone: a new email) and its messages (format full),
     * the message it replies to, the subject, its X-Expend-Ref and To.
     *
     * @return array{thread:?string, messages:?list<array>, target:?array, subject:string, ref:string, to:?string}
     */
    private function plan(Invoice $invoice, InvoiceEmail $row): array
    {
        $thread = $row->gmail_thread_id;
        $messages = $thread ? $this->gmail()->thread($thread, 'full') : null;
        if ($thread && $messages === null) {
            $thread = null; // the conversation is gone: a new email
        }
        $target = $messages ? $this->replyTo($invoice, $row, $messages) : null;
        $subject = $target !== null && trim((string) ($target['headers']['subject'] ?? '')) !== ''
            ? self::re((string) $target['headers']['subject'])
            : self::subjectFor($invoice, $row->kind);

        return ['thread' => $target === null ? null : $thread, 'messages' => $messages, 'target' => $target, 'subject' => $subject,
            'ref' => self::ref($invoice, $row->kind, $row->stage), 'to' => $row->to_address ?: null];
    }

    /**
     * What an earlier try of this row left in Gmail: its draft (as made, or edited by you since: never taken for
     * Expend's own), or that draft already sent by you. Null when it left nothing.
     *
     * @return array{id:string, message_id:string, thread_id:?string, to:?string, subject:string, adopted:bool, edited:bool, new_email:bool}|array{sent:array}|null
     */
    private function locate(Invoice $invoice, InvoiceEmail $row, ?array $plan = null): ?array
    {
        $plan ??= $this->plan($invoice, $row);
        $gmail = $this->gmail();
        $pdf = $invoice->number.'.pdf';
        $since = ($row->created_at?->timestamp ?? now()->timestamp) - 60;
        // A reminder carries the invoice's PDF too: only its own X-Expend-Ref says it's this reminder.
        $sent = $gmail->findSent($plan['thread'], $pdf, $row->kind === 'reminder' ? $plan['ref'] : null, $since, $plan['messages'], $plan['thread'] === null);
        if ($sent !== null) {
            return ['sent' => $sent];
        }
        $found = $gmail->findDraft($plan['thread'], $plan['ref'], $plan['subject'], $plan['messages'], $pdf);
        if ($found === null) {
            return null;
        }
        $now = $gmail->get($found['id'], 'full');
        $edited = $now === null || ! $this->asMade($now['message'], $invoice, $row, $plan['subject']);

        return ['id' => $found['id'], 'message_id' => $now['message']['id'] ?? $found['message_id'], 'thread_id' => $found['thread_id'],
            'to' => $edited ? (self::addressIn($now['message']['headers']['to'] ?? null) ?? $plan['to']) : $plan['to'],
            'subject' => $edited ? (string) ($now['message']['headers']['subject'] ?? $plan['subject']) : $plan['subject'],
            'adopted' => true, 'edited' => $edited, 'new_email' => $plan['thread'] === null];
    }

    /**
     * Whether a draft (format full) is exactly as Expend writes it for this row: its words, To (or none), subject and
     * PDF, and no Cc. Anything else is yours: Expend never deletes it, sends it in one tap, or calls it its own.
     */
    private function asMade(array $message, Invoice $invoice, InvoiceEmail $row, string $subject): bool
    {
        $norm = fn (?string $text) => trim((string) preg_replace("/[ \t]*\r?\n/", "\n", (string) $text));
        $to = strtolower((string) (self::addressIn($message['headers']['to'] ?? null) ?? ''));

        return $norm($message['text'] ?? null) === $norm(self::words($invoice, $row))
            && $to === strtolower((string) $row->to_address)
            && trim((string) ($message['headers']['subject'] ?? '')) === trim($subject)
            && ! isset($message['headers']['cc'])
            && GmailDrafts::carries($message, $invoice->number.'.pdf');
    }

    /** The words Expend writes in this row's draft: the invoice's email, or the reminder for its stage. */
    private static function words(Invoice $invoice, InvoiceEmail $row): string
    {
        return $row->kind === 'reminder' ? ChaseLadder::text($invoice, (int) $row->stage) : self::text($invoice);
    }

    /**
     * Record a try's draft on its row (drafted), under the invoice's lock. A row that moved on meanwhile, or an invoice
     * sent, voided or (for a reminder) paid or reminded another way meanwhile, leaves the row as it is: the draft just
     * made is taken out of Gmail, so it can't be sent too (one you edited is left, with a note).
     */
    private function record(Invoice $invoice, InvoiceEmail $claim, array $made, string $kind): InvoiceEmail
    {
        [$row, $stale] = DB::transaction(function () use ($invoice, $claim, $made, $kind) {
            AuditLog::lockHead();
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $row = InvoiceEmail::query()->lockForUpdate()->findOrFail($claim->id);
            if ($row->status !== 'pending') {
                return [$row, $row->gmail_draft_id !== $made['id']];
            }
            $over = $kind === 'invoice'
                ? ($fresh->status !== 'issued' ? 'It was voided' : ($fresh->sent_at !== null ? 'Sent another way' : null))
                : $this->reminderOver($fresh, $row);
            if ($over !== null) {
                $row->forceFill(['status' => 'replaced', 'error' => Str::limit($over, 240)])->save();

                return [$row, true];
            }
            $row->forceFill([
                'status' => 'drafted', 'gmail_draft_id' => $made['id'],
                // One you changed in Gmail before it was recorded is never taken for Expend's own as made.
                'draft_message_id' => $made['edited'] ? null : $made['message_id'], 'current_message_id' => $made['message_id'],
                'gmail_thread_id' => $made['thread_id'], 'to_address' => $made['to'], 'subject' => Str::limit($made['subject'], 245), 'drafted_at' => now(),
                'checked_at' => now(), 'error' => $made['edited'] ? 'You changed it in Gmail: send it from there.' : null,
            ])->save();
            AuditLog::record($kind === 'invoice' ? 'invoice.gmail_drafted' : 'invoice.reminder_drafted', $fresh, null, array_filter([
                'number' => $fresh->number, 'to' => $made['to'], 'subject' => $row->subject, 'thread' => $made['thread_id'], 'new_email' => $made['new_email'] ? true : null,
                'draft' => $made['id'], 'adopted' => $made['adopted'] ? true : null, 'edited' => $made['edited'] ? true : null, 'stage' => $row->stage,
            ], fn ($v) => $v !== null));

            return [$row, false];
        });
        if ($stale && ! $made['edited']) {
            // Sent, voided or paid another way while Gmail was making it: Expend's own draft mustn't be left to be sent.
            $this->gmail()->delete($made['id']);
        } elseif ($stale) {
            $row->forceFill(['error' => Str::limit('You changed its Gmail draft: delete it there so it isn’t sent twice.', 240)])->save();
        }

        return $row;
    }

    /**
     * Why a reminder waiting to go in Gmail shouldn't any more: the invoice paid, closed, voided or not overdue (as
     * ChaseLadder holds it), or a reminder sent another way since it was asked for. Null when it still should.
     */
    private function reminderOver(Invoice $fresh, InvoiceEmail $row): ?string
    {
        if ($fresh->reminded_at !== null && $row->created_at !== null && $fresh->reminded_at->gt($row->created_at)) {
            return 'A reminder for invoice '.$fresh->number.' was sent another way since.';
        }

        return app(ChaseLadder::class)->hold($fresh, false);
    }

    /**
     * After a try was retired (sent another way, or no longer wanted) with Gmail not having said whether it made the
     * draft: that draft, when it's there, is taken out if it's Expend's own as made, else left with a note on the row
     * (delete it so it isn't sent twice). Never throws: Gmail not answering leaves a note.
     *
     * @return string a note for a message ('' when there was nothing to do)
     */
    public function clearStray(Invoice $invoice, InvoiceEmail $row): string
    {
        if (! self::canUse() || $row->route !== 'gmail' || $row->gmail_draft_id !== null) {
            return '';
        }
        try {
            $found = $this->whileYouWait()->locate($invoice, $row);
            if ($found === null || isset($found['sent'])) {
                return '';
            }
            if (! $found['edited']) {
                $this->gmail()->delete($found['id']);
                DB::transaction(function () use ($invoice, $row, $found) {
                    AuditLog::lockHead();
                    $fresh = Invoice::query()->lockForUpdate()->find($invoice->id);
                    if ($fresh) {
                        AuditLog::record('invoice.gmail_draft_removed', $fresh, null, ['number' => $fresh->number, 'kind' => $row->kind, 'draft' => $found['id']],
                            'An earlier try’s draft, no longer wanted', 'system');
                    }
                });

                return '';
            }
            $note = 'An earlier try’s draft of it is in your Gmail drafts, changed by you: delete it there so it isn’t sent twice.';
        } catch (Throwable $e) {
            if (! self::transient($e) && ! self::isBlind($e)) {
                report($e);
            }

            // Looked for again by the next try, or the next press (stray()).
            return 'If an earlier try put it in your Gmail drafts, delete it there so it isn’t sent twice.';
        }
        $row->forceFill(['error' => Str::limit($note, 240)])->save();

        return $note;
    }

    /**
     * What a try with no draft recorded (stray()) left in Gmail, looked at before the invoice goes another way: 'sent'
     * when you sent its draft from Gmail (recorded now, so it isn't sent again), 'draft' when its draft is there, null
     * when there's nothing. Throws when Gmail can't be read.
     */
    public function settleStray(Invoice $invoice, InvoiceEmail $row): ?string
    {
        $found = $this->locate($invoice, $row);
        if ($found !== null && isset($found['sent'])) {
            $this->recordSent($row, $found['sent'], 'system', 'Sent from your Gmail');

            return 'sent';
        }

        return $found === null ? null : 'draft';
    }

    /**
     * A try still waiting to put the invoice in Gmail, retired because it went another way (under the invoice's lock).
     * The caller then clears what it may have left in Gmail (clearStray), after the commit.
     */
    public static function retirePending(Invoice $fresh): ?InvoiceEmail
    {
        $pending = InvoiceEmail::query()->where('invoice_id', $fresh->id)->where('kind', 'invoice')->where('status', 'pending')->latest('id')->first();
        $pending?->forceFill(['status' => 'replaced', 'error' => 'Sent another way'])->save();

        return $pending;
    }

    /**
     * The latest try that may have left a draft in Gmail without Expend knowing (Gmail made it without answering): a
     * Gmail row with no draft recorded that was waiting, failed or was replaced. Null when the latest row isn't one.
     */
    public static function stray(Invoice $invoice, string $kind = 'invoice'): ?InvoiceEmail
    {
        $latest = InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', $kind)->where('route', 'gmail')->latest('id')->first();

        return $latest !== null && $latest->gmail_draft_id === null && in_array($latest->status, ['pending', 'failed', 'replaced'], true) ? $latest : null;
    }

    /**
     * The message a draft replies to: for a reminder, the invoice as it was sent from Gmail (in the same conversation),
     * else the conversation's newest message that isn't a draft.
     */
    private function replyTo(Invoice $invoice, InvoiceEmail $row, array $messages): ?array
    {
        $real = array_values(array_filter($messages, fn (array $m) => ! in_array('DRAFT', $m['labels'], true)));
        if ($row->kind === 'reminder') {
            $sent = InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', 'invoice')->where('status', 'sent')->whereNotNull('gmail_message_id')->latest('id')->value('gmail_message_id');
            foreach ($real as $m) {
                if ($sent !== null && $m['id'] === $sent) {
                    return $m;
                }
            }
        }

        return $real === [] ? null : $real[count($real) - 1];
    }

    /** "Re: <their subject>", never "Re: Re:". */
    public static function re(string $subject): string
    {
        $subject = trim($subject);

        return preg_match('/^re\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    /** A new email's subject: "Invoice INV-0042 · Arena load-in · PO 4471" (a reminder's starts "Reminder: invoice"). */
    public static function subjectFor(Invoice $invoice, string $kind = 'invoice'): string
    {
        $job = $invoice->work_job_id ? WorkJob::query()->find($invoice->work_job_id) : null;

        return implode(' · ', array_filter([
            ($kind === 'reminder' ? 'Reminder: invoice ' : 'Invoice ').$invoice->number,
            $job ? self::clean((string) $job->title) : null,
            $invoice->po_number ? self::po((string) $invoice->po_number) : null,
        ], fn ($p) => $p !== null && $p !== ''));
    }

    /** The draft's X-Expend-Ref: "42/invoice/7531ebc0" (a reminder's kind carries its stage: "reminder2"). */
    public static function ref(Invoice $invoice, string $kind, ?int $stage = null): string
    {
        return $invoice->id.'/'.($kind === 'reminder' ? 'reminder'.(int) $stage : 'invoice').'/'.substr((string) $invoice->pdf_sha256, 0, 8);
    }

    /**
     * The invoice email's words. It stands on its own (the client's mail program may not show the conversation): the
     * invoice and PO numbers, the job and its dates, the amount and due date, the travel and expenses at cost by kind,
     * and that the bank details are on the invoice. No card digits, references or postcodes: only kinds and totals.
     */
    public static function text(Invoice $invoice): string
    {
        $currency = InvoicePayments::currencyOf($invoice);
        $paras = ['Hello,', 'Please find attached invoice '.$invoice->number.self::about($invoice).'.',
            'The amount is '.Money::format((int) $invoice->total_minor, $currency, false).($invoice->due_date ? ', due by '.$invoice->due_date->format('j F Y') : '').'.'];
        $costs = self::costsByKind($invoice);
        if ($costs !== []) {
            $paras[] = 'It includes travel and expenses at cost: '.implode(', ', array_map(fn ($label, $minor) => $label.' '.Money::format($minor, $currency, false), array_keys($costs), $costs))
                .'. Receipts are available if you need them.';
        }
        if (trim((string) Setting::get('business_bank_details')) !== '') {
            $paras[] = 'My bank details are on the invoice.';
        }
        $paras[] = 'Many thanks,'."\n".trim((string) Setting::get('business_name'));

        return implode("\n\n", $paras)."\n";
    }

    /** " for Arena load-in, 3–4 Jun 2026 (PO 4471)": the job and its dates, and the PO, when there are. */
    public static function about(Invoice $invoice): string
    {
        $job = $invoice->work_job_id ? WorkJob::query()->with('days')->find($invoice->work_job_id) : null;
        $title = $job ? self::clean((string) $job->title) : '';

        return ($title !== '' ? ' for '.$title.', '.CostWords::dates($job) : '')
            .($invoice->po_number ? ' ('.self::po((string) $invoice->po_number).')' : '');
    }

    /**
     * The ticked travel and expense lines' totals by kind ("train" => 4500, "mileage" => 6413), from how each line was
     * made (its source and the kind its words start with), never from a payment's own text.
     *
     * @return array<string, int>
     */
    private static function costsByKind(Invoice $invoice): array
    {
        $labels = collect(CostClass::LABELS)->except(['travel_fee', 'travel_days'])->sortByDesc(fn ($l) => mb_strlen($l));
        $out = [];
        foreach ($invoice->lines()->whereIn('source', ['cost', 'mileage'])->get() as $line) {
            if ($line->source === 'mileage') {
                $label = 'mileage';
            } else {
                $words = (string) preg_replace('/^Refund:\s*/', '', (string) $line->description);
                $class = $line->own_text ? null : $labels->search(fn (string $l) => preg_match('/^'.preg_quote($l, '/').'(?=[:\s(]|$)/u', $words) === 1);
                $label = $class === null || $class === false || $class === 'other' ? 'other expenses' : mb_strtolower(CostClass::label($class));
            }
            $out[$label] = ($out[$label] ?? 0) + (int) $line->amount_minor;
        }

        return array_filter($out, fn (int $minor) => $minor > 0);
    }

    /** "PO 4471", whether the number was stored with its "PO" or without. */
    private static function po(string $number): string
    {
        $number = self::clean($number);

        return preg_match('/^P\.?O\b/i', $number) === 1 ? $number : 'PO '.$number;
    }

    /** Free text for an email: no postcodes and no long runs of digits (a card number, a booking or bank reference). */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', '', $text);
        $text = (string) preg_replace('/\d[\d\s-]{5,}\d/', '', $text);

        return trim((string) preg_replace('/\s{2,}/', ' ', $text), " \t\n\r\0\x0B,-·");
    }

    /**
     * The message that sent a draft that's gone: in its conversation, sent after the draft was made, carrying the
     * invoice's PDF. Null when there's none (deleted unsent, or the PDF was taken off: then you're asked).
     */
    private function sentMessage(InvoiceEmail $row, Invoice $invoice): ?array
    {
        $pdf = $invoice->number.'.pdf';
        $after = ($row->drafted_at?->timestamp ?? 0) - 60;
        $gmail = $this->gmail();

        // In its conversation, carrying the PDF; else anywhere in your sent mail (Gmail files a reply whose subject you
        // changed in a new conversation): there, a reminder only with its own X-Expend-Ref (the invoice carries the PDF too).
        return $gmail->findSent($row->gmail_thread_id, $pdf, null, $after, null, false)
            ?? $gmail->findSent(null, $pdf, $row->kind === 'reminder' ? self::ref($invoice, 'reminder', $row->stage) : null, $after);
    }

    /**
     * Record a send: the row as sent, and on the invoice when and to whom (its Message-ID too, when it has none yet),
     * or for a reminder when it was chased. Audited as invoice.sent (or reminder_sent) by the actor.
     *
     * @return string|null the address it went to
     */
    private function recordSent(InvoiceEmail $row, array $message, string $actor, ?string $reason = null): ?string
    {
        return DB::transaction(function () use ($row, $message, $actor, $reason) {
            AuditLog::lockHead();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($row->invoice_id);
            $fresh = InvoiceEmail::query()->lockForUpdate()->findOrFail($row->id);
            if ($fresh->status === 'sent') {
                return $fresh->to_address;
            }
            $to = self::addressIn($message['headers']['to'] ?? null) ?? $fresh->to_address;
            $rfc = GmailDrafts::ids($message['headers']['message-id'] ?? null)[0] ?? null;
            $at = CarbonImmutable::createFromTimestamp(($message['at'] ?? 0) ?: now()->timestamp)->setTimezone(config('app.timezone'));
            $fresh->forceFill([
                'status' => 'sent', 'gmail_message_id' => $message['id'], 'rfc_message_id' => $rfc !== null ? Str::limit($rfc, 185, '') : null,
                'gmail_thread_id' => $message['thread_id'] ?? $fresh->gmail_thread_id, 'to_address' => $to, 'sent_at' => $at, 'checked_at' => now(), 'error' => null,
            ])->save();
            if ($fresh->kind === 'invoice') {
                $invoice->forceFill(['sent_at' => $at, 'sent_to' => $to] + ($invoice->message_id === null && $rfc !== null ? ['message_id' => $rfc] : []))->save();
            } else {
                $invoice->forceFill(['reminded_at' => $at, 'reminder_count' => (int) $invoice->reminder_count + 1])->save();
            }
            AuditLog::record($fresh->kind === 'invoice' ? 'invoice.sent' : 'invoice.reminder_sent', $invoice, null,
                array_filter(['to' => $to, 'number' => $invoice->number, 'message_id' => $rfc, 'route' => 'gmail'], fn ($v) => $v !== null), $reason, $actor);
            $row->setRawAttributes($fresh->getAttributes(), true);

            return $to;
        });
    }

    /** What happened to a draft that's gone, after checking it: for a refusal. */
    private function gone(InvoiceEmail $row): string
    {
        $outcome = $this->check($row);
        $number = (string) Invoice::query()->whereKey($row->invoice_id)->value('number');

        return $outcome === 'sent'
            ? ($row->kind === 'reminder' ? 'The reminder for ' : 'Invoice ').$number.' was already sent from your Gmail.'
            : 'The Gmail draft for '.$number.' was deleted without being sent.';
    }

    /**
     * Addresses earlier invoices to this client were sent to (its merged duplicates included; with no client, invoices
     * billed to the same name), and for a reminder this invoice's own.
     *
     * @return list<string>
     */
    private function usedAddresses(Invoice $invoice, bool $withItself): array
    {
        $client = IncomeClient::ofInvoice($invoice);
        $query = Invoice::query()->whereNotNull('sent_to')->whereIn('status', ['issued', 'paid', 'void'])
            ->when(! $withItself, fn ($q) => $q->whereKeyNot($invoice->id));
        if ($client !== null) {
            $ids = ClientIds::withMerged($client);
            $query->where(fn ($q) => $q->whereIn('client_id', $ids)
                ->orWhere(fn ($q) => $q->whereNull('client_id')->whereIn('work_job_id', WorkJob::query()->whereIn('client_id', $ids)->select('id'))));
            $sent = $query->pluck('sent_to');
        } else {
            $key = JobCreator::clientKey($invoice->bill_to_name);
            if ($key === '') {
                return [];
            }
            $sent = $query->whereNull('client_id')->get(['id', 'bill_to_name', 'sent_to'])
                ->filter(fn (Invoice $i) => JobCreator::clientKey($i->bill_to_name) === $key)->pluck('sent_to');
        }

        return $sent->map(fn ($a) => self::addressIn($a))->filter()->unique()->values()->all();
    }

    /** The email address in a header or field ("Acme <a@b.example>" gives a@b.example). */
    private static function addressIn(?string $value): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $value, $m) === 1 ? $m[0] : null;
    }

    /** A typed address: one, valid; '' is "no address" when allowed (you add it in Gmail). */
    private static function address(string $value, bool $allowNone): ?string
    {
        $value = trim($value);
        if ($value === '' && $allowNone) {
            return null;
        }
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.');
        }

        return $value;
    }

    /** A conversation chosen on the sheet, checked before anything is issued: null, 'new', or a Gmail conversation id. */
    private static function assertThread(?string $thread): void
    {
        if (is_string($thread) && $thread !== '' && $thread !== 'new') {
            self::threadId($thread);
        }
    }

    private static function threadId(string $thread): string
    {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $thread) !== 1) {
            throw new InvalidArgumentException('That isn’t a Gmail conversation.');
        }

        return $thread;
    }

    private static function blind(string $why): void
    {
        $was = self::cantSee();
        Setting::put(self::BLIND_SETTING, ['since' => $was['since'] ?? now()->toIso8601String(), 'why' => Str::limit($why, 200), 'told' => (bool) ($was['told'] ?? false)]);
    }

    private static function why(Throwable $e): string
    {
        return $e instanceof ConnectionException ? 'Gmail didn’t answer' : (Str::limit(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 200) ?: class_basename($e));
    }

    private function gmail(): GmailDrafts
    {
        return new GmailDrafts($this->timeout);
    }
}
