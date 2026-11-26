<?php

namespace App\Domain\Invoices;

use App\Domain\Income\IncomeClient;
use App\Domain\Income\IncomeText;
use App\Domain\Jobs\JobCreator;
use App\Models\Allocation;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Reminders for an unpaid invoice, as Expend's own Gmail drafts, made only when you ask (owner's answer: "Make a
 * reminder" on the invoice, the to-do list or Telegram; Expend never writes one on a schedule). Nothing is sent
 * without your press: the draft waits in Gmail, and you send it there or with Send in Expend.
 *
 * Its words go by how overdue it is, counted from the later of the due date and the day it was sent plus its terms
 * (an invoice sent late isn't chased early): from 3 days (stage 1: due on …, not received yet, attached again, "if
 * you've already paid, thank you"), 14 (stage 2: asks when it will be paid) and 30 (stage 3: asks for payment this
 * week, or to say if there's a problem). Part paid, it thanks them for what came in and says what's still to come.
 * No statutory interest is added.
 *
 * A reminder replies to the invoice as it was sent from Gmail, in the same conversation, to the address it went to,
 * with the PDF again; an invoice sent another way gets a new email. One reminder draft open at a time: asked again at a
 * later stage, Expend's earlier unedited draft is replaced; one you edited stays, and a new one waits until you've sent
 * or deleted it.
 *
 * Expend takes its own unedited reminder draft out again (tidy(), from CheckInvoiceEmails) once the invoice is paid,
 * closed or voided, or a payment that may be for it is waiting in the Inbox; one you edited is left, with a note.
 */
final class ChaseLadder
{
    /** The days overdue each stage's words start at. */
    public const STAGES = [1 => 3, 2 => 14, 3 => 30];

    public function __construct(private InvoiceEmails $emails) {}

    /** Why a reminder isn't made or sent for an invoice that hasn't been sent: a reminder can only follow it. */
    public const NOT_SENT = 'Send the invoice first: a reminder can only follow it.';

    /**
     * How overdue an invoice is, for a reminder: days since the later of its due date and the day it was sent plus its
     * terms, and the stage its words are for (stage 1 below 14 days, even under 3: you asked).
     *
     * @return array{days:int, stage:int, from:string}|null null when it isn't overdue
     */
    public static function overdue(Invoice $invoice, ?CarbonInterface $today = null): ?array
    {
        if ($invoice->due_date === null) {
            return null;
        }
        $today = CarbonImmutable::parse(($today ?? now())->format('Y-m-d'));
        $from = CarbonImmutable::parse($invoice->due_date->format('Y-m-d'));
        if ($invoice->sent_at !== null && $invoice->issue_date !== null) {
            $terms = max(0, (int) $invoice->issue_date->diffInDays($invoice->due_date, false));
            $sentDue = CarbonImmutable::parse($invoice->sent_at->format('Y-m-d'))->addDays($terms);
            $from = $sentDue->gt($from) ? $sentDue : $from;
        }
        $days = (int) $from->diffInDays($today, false);
        if ($days <= 0) {
            return null;
        }

        return ['days' => $days, 'stage' => $days >= self::STAGES[3] ? 3 : ($days >= self::STAGES[2] ? 2 : 1), 'from' => $from->format('Y-m-d')];
    }

    /**
     * How many days overdue an invoice is wherever it's shown (lists, the job's stage, the to-do list, Telegram): as
     * overdue() counts them, and only once it was sent. An invoice that was never sent isn't overdue: it's "Not sent".
     * Null when it isn't overdue.
     */
    public static function daysOverdue(Invoice $invoice, ?CarbonInterface $today = null): ?int
    {
        return $invoice->sent_at === null ? null : (self::overdue($invoice, $today)['days'] ?? null);
    }

    /**
     * Make a reminder: put it in your Gmail drafts, worded for how overdue it is.
     *
     * @param  bool  $once  a one-tap button (the to-do list, Telegram): held while something has come in that may be for
     *                      it (a waiting payment, money paid back, money in another currency, a payment on a voided
     *                      invoice of this client). From the invoice's page you can still choose to.
     * @return array{email:?InvoiceEmail, message:string}
     *
     * @throws InvalidArgumentException when it can't be made (not sent, paid, not overdue, an edited one waiting)
     */
    public function make(Invoice $invoice, bool $once = false, ?CarbonInterface $today = null): array
    {
        $invoice = $invoice->fresh() ?? $invoice;
        if (! InvoiceEmails::canUse()) {
            throw new InvalidArgumentException('Connect Gmail (with permission to write drafts) to make a reminder there, or send one from Expend.');
        }
        if ($held = $this->hold($invoice, $once, $today)) {
            if ($once) {
                return ['email' => null, 'message' => $held];
            }
            throw new InvalidArgumentException($held);
        }
        $stage = self::overdue($invoice, $today)['stage'];

        if ($open = InvoiceEmails::open($invoice, 'reminder')) {
            try {
                $outcome = $this->emails->whileYouWait()->check($open);
                if ($outcome === 'drafted' && (int) $open->stage !== $stage
                    && $this->emails->whileYouWait()->takeOut($open, 'a later reminder replaced it', 'you', 'replaced') === '') {
                    $outcome = 'edited'; // edited in Gmail just now: it stays
                }
            } catch (Throwable $e) {
                if (! InvoiceEmails::transient($e) && ! InvoiceEmails::isBlind($e)) {
                    report($e);
                }
                throw new InvalidArgumentException('Gmail didn’t answer, so no reminder was made: try again in a minute.');
            }
            if ($outcome === 'edited') {
                throw new InvalidArgumentException("Your edited reminder for {$invoice->number} is still in your Gmail drafts: send or delete it there first.");
            }
            if ($outcome === 'drafted' && (int) $open->stage === $stage) {
                return ['email' => $open, 'message' => "The reminder for {$invoice->number} is already in your Gmail drafts, ready to send."];
            }
            if ($outcome === 'sent') {
                return ['email' => $open->fresh(), 'message' => "The reminder for {$invoice->number} was sent from your Gmail just now."];
            }
        }

        $sent = InvoiceEmail::query()->where('invoice_id', $invoice->id)->where('kind', 'invoice')->where('route', 'gmail')->where('status', 'sent')->latest('id')->first();
        $email = $this->emails->prepareOrRetry($invoice, 'reminder', [
            'stage' => $stage,
            'to' => $invoice->sent_to ?: ($sent?->to_address ?: ($invoice->bill_to_email ?: '')),
            'thread' => $sent?->gmail_thread_id ?: 'new',
        ]);
        $days = self::overdue($invoice, $today)['days'];
        $message = match ($email?->status) {
            'drafted' => "A reminder for {$invoice->number} ({$days} ".($days === 1 ? 'day' : 'days').' overdue) is in your Gmail drafts, ready to send.',
            'pending' => "Putting the reminder for {$invoice->number} in Gmail… Gmail didn’t answer, so Expend will try again in a minute.",
            default => "The reminder for {$invoice->number} isn’t in your Gmail drafts: ".InvoiceEmails::reason((string) ($email?->error ?: 'Gmail didn’t take it')).'.',
        };

        return ['email' => $email, 'message' => $message];
    }

    /**
     * Why a reminder can't be made now, or null. Always: not issued, not sent yet, paid in full or closed, not overdue.
     * From a one-tap button also: something come in that may be for it.
     */
    public function hold(Invoice $invoice, bool $once, ?CarbonInterface $today = null): ?string
    {
        if ($invoice->status !== 'issued') {
            return 'Only an issued invoice can be chased.';
        }
        if ($invoice->sent_at === null) {
            return "Invoice {$invoice->number} hasn’t been sent yet: send it first.";
        }
        $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;
        $currency = InvoicePayments::currencyOf($invoice);
        if ($r['outstanding'] <= 0 && in_array($r['state'], ['paid', 'closed'], true)) {
            return "Invoice {$invoice->number} is ".($r['state'] === 'closed' ? 'closed' : 'paid in full').', so no reminder was made.';
        }
        if (self::overdue($invoice, $today) === null) {
            return "Invoice {$invoice->number} isn’t overdue yet".($invoice->due_date ? ' (it’s due on '.$invoice->due_date->format('j M Y').')' : '').', so it’s too early for a reminder.';
        }
        if (! $once) {
            return null;
        }
        $after = ' To make one anyway, use the invoice’s page.';

        return match (true) {
            $r['waiting'] > 0 => 'No reminder was made: '.Money::format($r['waiting']).' that may be for invoice '.$invoice->number.' is waiting in the Inbox. Decide it first.'.$after,
            $r['refunded'] > 0 => 'No reminder was made: '.self::paidBack($invoice, $r['refunded'], $currency).$after,
            ($s = self::stranded($invoice)) !== null => 'No reminder was made: '.$s.$after,
            $r['state'] === 'check_currency' => 'No reminder was made: '.Money::format($r['elsewhere']).' came in for this job in another currency, so check whether invoice '.$invoice->number.' has been paid first.'.$after,
            default => null,
        };
    }

    /**
     * Take Expend's own unedited reminder drafts out of Gmail for invoices now paid, closed or voided, or with a payment
     * waiting that may be for them. One you edited is left (with a note on its row).
     *
     * @return list<string> what was taken out, for messages ('The reminder for INV-0042 was taken out of your drafts: a payment may be for it.')
     */
    public function tidy(?int $invoiceId = null): array
    {
        $rows = InvoiceEmail::query()->open()->where('kind', 'reminder')->when($invoiceId, fn ($q) => $q->where('invoice_id', $invoiceId))->orderBy('id')->get();
        if ($rows->isEmpty() || ! InvoiceEmails::canUse()) {
            return [];
        }
        $invoices = Invoice::query()->whereKey($rows->pluck('invoice_id')->unique())->get()->keyBy('id');
        $received = InvoicePayments::received($invoices->filter(fn (Invoice $i) => in_array($i->status, InvoicePayments::PAYABLE, true)));
        $out = [];
        foreach ($rows as $row) {
            $invoice = $invoices[$row->invoice_id] ?? null;
            $r = $received[$row->invoice_id] ?? InvoicePayments::EMPTY;
            $why = match (true) {
                $invoice === null || $invoice->status === 'void' => 'it was voided',
                $r['outstanding'] <= 0 && $r['state'] === 'closed' => 'it’s closed',
                $r['outstanding'] <= 0 => 'it’s paid',
                $r['waiting'] > 0 => 'a payment may be for it',
                default => null,
            };
            if ($why === null) {
                continue;
            }
            try {
                $message = $this->emails->takeOut($row, $why, 'system');
            } catch (Throwable $e) {
                if (! InvoiceEmails::transient($e)) {
                    report($e);
                }

                continue;
            }
            if ($message !== '') {
                $out[] = $message;
            }
        }

        return $out;
    }

    /** The reminder's words for its stage (see the class), standing on their own like the invoice's email. */
    public static function text(Invoice $invoice, int $stage, ?CarbonInterface $today = null): string
    {
        $first = self::opening($invoice, $stage, $today);
        $paras = ['Hello,', $first.' The invoice is attached again.'];
        if ($stage === 2) {
            $paras[] = 'Could you let me know when it will be paid?';
        } elseif ($stage >= 3) {
            $paras[] = 'Please could you pay it this week, or let me know if there’s a problem with it?';
        }
        $paras[] = 'If you’ve already paid, thank you, and please ignore this.';
        if (trim((string) Setting::get('business_bank_details')) !== '') {
            $paras[] = 'My bank details are on the invoice.';
        }
        $paras[] = 'Many thanks,'."\n".trim((string) Setting::get('business_name'));

        return implode("\n\n", $paras)."\n";
    }

    /**
     * The reminder's first sentence for its stage: part paid, it thanks them for what came in and says what's still to
     * come; otherwise when it was due and that it hasn't been paid (the reminder Expend emails itself starts the same).
     */
    public static function opening(Invoice $invoice, int $stage, ?CarbonInterface $today = null): string
    {
        $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;
        $currency = InvoicePayments::currencyOf($invoice);
        $about = InvoiceEmails::about($invoice);
        $due = $invoice->due_date?->format('j F Y');
        $paid = $r['paid'] > 0 && $r['outstanding'] > 0;

        return match (true) {
            $paid => 'Thank you for the '.IncomeText::money($r['paid'], $currency).' received '.self::receivedOn($invoice, $today).'. '
                .IncomeText::money($r['outstanding'], $currency).' of invoice '.$invoice->number.$about.' is still to come: it was due on '.$due.'.',
            $stage >= 3 => 'Invoice '.$invoice->number.$about.', for '.Money::format((int) $invoice->total_minor, $currency, false).', was due on '.$due.' and is now over a month overdue.',
            $stage === 2 => 'Invoice '.$invoice->number.$about.', for '.Money::format((int) $invoice->total_minor, $currency, false).', was due on '.$due.' and is still unpaid.',
            default => 'A reminder that invoice '.$invoice->number.$about.', for '.Money::format((int) $invoice->total_minor, $currency, false).', was due on '.$due.' and I haven’t received payment yet.',
        };
    }

    /** Whether some of the invoice has come in and some is still to come (its reminder thanks them for what came in). */
    public static function partPaid(Invoice $invoice): bool
    {
        $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;

        return $r['paid'] > 0 && $r['outstanding'] > 0;
    }

    /**
     * A payment on a voided invoice of the same client (or billed to the same name) that counts nowhere: reminders
     * wait until you say which invoice replaced it (income design v3).
     */
    public static function stranded(Invoice $invoice): ?string
    {
        $stranded = InvoicePayments::stranded();
        if ($stranded === []) {
            return null;
        }
        $client = IncomeClient::ofInvoice($invoice);
        $key = JobCreator::clientKey($invoice->bill_to_name);
        foreach (Invoice::query()->whereKey(array_keys($stranded))->orderBy('id')->get() as $void) {
            $voidClient = IncomeClient::ofInvoice($void);
            $same = $client !== null ? $voidClient === $client : ($voidClient === null && $key !== '' && JobCreator::clientKey($void->bill_to_name) === $key);
            if ($same) {
                return Money::format($stranded[$void->id]).' was paid against '.$void->number.', which you voided. Say which invoice replaced it first.';
            }
        }

        return null;
    }

    /** "you paid back £450.00 against invoice INV-042. Void it if the work was cancelled, or close it if the rest isn't owed." */
    public static function paidBack(Invoice $invoice, int $refunded, string $currency): string
    {
        return 'you paid back '.Money::format($refunded, $currency, false).' against invoice '.$invoice->number.'. Void it if the work was cancelled, or close it if the rest isn’t owed.';
    }

    /** "on 3 January" when what came in came on one day, else "so far". */
    private static function receivedOn(Invoice $invoice, ?CarbonInterface $today): string
    {
        $lines = Allocation::query()->whereNotNull('confirmed_at')->where('amount_pence', '>', 0)
            ->where(fn ($q) => $q->where('invoice_id', $invoice->id)
                ->when($invoice->work_job_id, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('invoice_id')->where('kind', 'business_income')->where('work_job_id', $invoice->work_job_id))))
            ->with(['transaction:id,posted_on', 'manualExpense:id,spent_on'])->get();
        $dates = $lines->map(fn (Allocation $a) => $a->transaction?->posted_on?->format('Y-m-d') ?? $a->manualExpense?->spent_on?->format('Y-m-d'))->filter()->unique()->values();
        if ($dates->count() !== 1) {
            return 'so far';
        }
        $on = CarbonImmutable::parse($dates[0]);

        return 'on '.$on->format($on->year === CarbonImmutable::parse(($today ?? now())->format('Y-m-d'))->year ? 'j F' : 'j F Y');
    }
}
