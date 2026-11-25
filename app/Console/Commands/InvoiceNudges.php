<?php

namespace App\Console\Commands;

use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * What needs you about your invoices, on Telegram, each morning (it replaces the ledger:invoice-nudges closure, under
 * the same name, so nothing is scheduled twice). It only ever tells you: every button is yours to press, and nothing
 * here writes a reminder draft, sends an email or changes a record (the owner's answer: reminders only when you ask).
 *
 *  1. "The invoice for Arena load-in is still a draft." [Check the invoice] [Remind me Monday]: 2 days after the job
 *     was marked done, then weekly while it stays a draft.
 *  2. "INV-0042 (Arena load-in) is issued but not sent." on day 1 and day 3 after issuing, then weekly until it's sent
 *     or voided, worded by where its email is, with [Send it] only when the Gmail draft is exactly as Expend made it
 *     and goes to an address an earlier invoice to this client went to (InvoiceEmails::oneTapProblem: checked again,
 *     live, when you press), [Open in Gmail] and [I sent it myself]; or [Email it] with no Gmail.
 *  3. "The Gmail draft for INV-0042 was deleted without being sent", once, with [Put it back in Gmail] and [I sent it
 *     another way].
 *  4. Overdue (the to-do list's open invoice.overdue findings, as before): at 3, 14 and 30 days overdue, then weekly
 *     (invoices.overdue_nudged_at), with its number, the bill-to name and the amount; [Make a reminder] (or [Send it]
 *     when your reminder is in your Gmail drafts as Expend made it), [They've paid] and [Not yet]. No reminder button
 *     while a payment that may be for it waits in the Inbox, or while the invoice's own Gmail draft is open.
 *  5. "Expend can't see Gmail", once (InvoiceEmails::cantSee, its 'told' kept); no "not sent" message while it can't.
 *  6. A reminder Expend took out of your drafts (a payment may be for it, it's paid…), and one you edited that it left
 *     there, once each.
 *
 * Quiet rules: nothing between 21:00 and 08:00 London; messages 1 to 3 wait while today is one of your job days, for
 * at most 2 days; at most one message per invoice a day. Late costs and draft changes go only in the Monday digest.
 * What was told is kept in settings invoice_nudges ({key: {at, n}}), not the audit log: a message isn't a change.
 */
class InvoiceNudges extends Command
{
    protected $signature = 'ledger:invoice-nudges {--dry-run : Say what would be sent, send nothing}';

    protected $description = 'Tell you on Telegram about drafts waiting, invoices not sent and invoices overdue, with buttons for you to press';

    public const TZ = 'Europe/London';

    /** Where what was told is kept. */
    public const SETTING = 'invoice_nudges';

    /** Nothing goes from this hour (London)... */
    public const QUIET_FROM = 21;

    /** ...until this one. */
    public const QUIET_UNTIL = 8;

    /** On one of your job days, messages 1 to 3 wait, for at most this many days. */
    public const MAX_WAIT_DAYS = 2;

    /** A draft is mentioned this many days after the job was marked done, then weekly. */
    public const DRAFT_AFTER_DAYS = 2;

    /** An invoice not sent is mentioned on these days after it was issued, then weekly. */
    public const NOT_SENT_DAYS = [1, 3];

    public const WEEK = 7;

    /** The days overdue a nudge goes at (ChaseLadder's stages), then weekly after the last. */
    public const OVERDUE_DAYS = ChaseLadder::STAGES;

    /** How long what was told is kept. */
    private const KEEP_DAYS = 400;

    /** @var array<string, array{at:string, n?:int}|string> */
    private array $state = [];

    /** @var array<int, true> invoices already messaged about today */
    private array $today = [];

    private int $overdue = 0;

    private int $other = 0;

    public function handle(InvoiceEmails $emails): int
    {
        $chat = Setting::get('telegram_chat_id');
        if (! $chat || ! Telegram::token()) {
            $this->line('Telegram isn’t paired.');

            return self::SUCCESS;
        }
        $now = CarbonImmutable::now(self::TZ);
        if ($now->hour >= self::QUIET_FROM || $now->hour < self::QUIET_UNTIL) {
            $this->line('Quiet hours (21:00 to 08:00): nothing sent.');

            return self::SUCCESS;
        }
        $today = $now->startOfDay();
        // A command object can be run more than once in one process (tests, the scheduler): start afresh each time.
        [$this->state, $this->today, $this->overdue, $this->other] = [self::state(), [], 0, 0];
        foreach ($this->state as $key => $v) {
            if (str_starts_with($key, 'day:') && $v === $today->format('Y-m-d')) {
                $this->today[(int) substr($key, 4)] = true;
            }
        }
        Telegram::allowButtons();
        $jobDay = self::jobDay($today);

        $this->blind($chat);
        $this->tidied($chat);
        $this->overdue($chat, $emails, $today);
        $this->drafts($chat, $today, $jobDay);
        if (InvoiceEmails::cantSee() === null) {
            $this->deleted($chat, $today, $jobDay);
            $this->notSent($chat, $emails, $today, $jobDay);
        }
        if (! $this->option('dry-run')) {
            $since = $today->subDays(self::KEEP_DAYS)->format('Y-m-d');
            Setting::put(self::SETTING, array_filter($this->state, fn ($v) => (is_array($v) ? ($v['at'] ?? '') : (string) $v) >= $since));
        }
        $this->info("Nudged about {$this->overdue} overdue invoices.");
        $this->line("Told you about {$this->other} other invoices.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $v = Setting::get(self::SETTING, []);

        return is_array($v) ? $v : [];
    }

    /** Whether today is one of your job days: a day of a job that isn't cancelled (its days, or its dates when it has none). */
    public static function jobDay(CarbonImmutable $today): bool
    {
        $day = $today->format('Y-m-d');

        return JobDay::query()->whereDate('on_date', $day)->whereHas('job', fn ($q) => $q->where('status', '!=', 'cancelled'))->exists()
            || WorkJob::query()->where('status', '!=', 'cancelled')->whereDoesntHave('days')->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->exists();
    }

    /** "Expend can't see Gmail", said once until a check works again. */
    private function blind(string|int $chat): void
    {
        $blind = InvoiceEmails::cantSee();
        if ($blind === null || ($blind['told'] ?? false)) {
            return;
        }
        $since = isset($blind['since']) ? ' since '.CarbonImmutable::parse($blind['since'])->setTimezone(self::TZ)->format('j M') : '';
        $text = 'Expend can’t see your Gmail'.$since.' ('.InvoiceEmails::reason((string) ($blind['why'] ?? 'Gmail didn’t let it in')).'), so it can’t tell whether invoices went. Until it can, it won’t say they weren’t sent.';
        if ($this->send($chat, $text, [[['url' => url('/settings/integrations#gmail'), 'text' => 'Reconnect Gmail']]]) && ! $this->option('dry-run')) {
            Setting::put(InvoiceEmails::BLIND_SETTING, ['told' => true] + $blind);
        }
    }

    /** Reminders Expend took out of your drafts, and ones you edited that it left there: once each. */
    private function tidied(string|int $chat): void
    {
        $rows = InvoiceEmail::query()->where('kind', 'reminder')->where('updated_at', '>=', now()->subDays(self::WEEK))
            ->where(fn ($q) => $q->where('status', 'removed')->orWhere(fn ($q) => $q->where('status', 'drafted')->where('error', 'like', 'You edited it, so it was left in your drafts:%')))
            ->with('invoice:id,number')->orderBy('id')->get();
        foreach ($rows as $row) {
            $key = ($row->status === 'removed' ? 'removed:' : 'edited:').$row->id;
            if (isset($this->state[$key]) || $row->invoice === null) {
                continue;
            }
            $why = InvoiceEmails::reason((string) ($row->error ?: 'it isn’t needed'));
            $text = $row->status === 'removed'
                ? 'The reminder for '.$row->invoice->number.' was taken out of your drafts: '.preg_replace('/^You edited it, so it was left in your drafts: /', '', $why).'.'
                : 'You edited the reminder for '.$row->invoice->number.', so it was left in your Gmail drafts: '.preg_replace('/^you edited it, so it was left in your drafts: /i', '', $why).'. Delete it there if it isn’t needed.';
            if ($this->send($chat, $text, [[['url' => url('/invoices/'.$row->invoice->id), 'text' => 'Open the invoice']]])) {
                $this->state[$key] = ['at' => today()->format('Y-m-d')];
                $this->told($row->invoice->id); // one message per invoice a day: its overdue nudge waits for tomorrow
                $this->other++;
            }
        }
    }

    /**
     * Overdue invoices: the to-do list's open invoice.overdue findings, brought up to date first (as the Monday digest
     * does), so one you snoozed or marked not relevant isn't nudged about. Your snoozes are read as they are now
     * (Dismissals::hiddenNow()), not only as last recorded.
     */
    private function overdue(string|int $chat, InvoiceEmails $emails, CarbonImmutable $today): void
    {
        FindingsRegistry::refresh();
        $ids = Finding::query()->where(fn ($q) => $q->whereNull('resolved_at')->orWhereIn('resolution', ['snoozed', 'dismissed']))
            ->where(fn ($q) => $q->where('code', 'invoice.overdue')->orWhere(fn ($q) => $q->whereNull('code')->where('key', 'like', 'invoice_overdue:%')))
            ->get(['key'])->filter(fn (Finding $f) => Dismissals::hiddenNow($f->key) === null)
            ->map(fn (Finding $f) => (int) substr($f->key, strlen('invoice_overdue:')))->filter()->values()->all();
        $invoices = Invoice::query()->whereIn('id', $ids ?: [0])->where('status', 'issued')->whereNotNull('sent_at')->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->format('Y-m-d'))->orderBy('id')->get();
        // What has come in for each, counted as the to-do list counts it (payments linked to it, income for its job).
        $received = InvoicePayments::received($invoices);
        foreach ($invoices as $invoice) {
            $r = $received[$invoice->id] ?? InvoicePayments::EMPTY;
            if ($r['outstanding'] <= 0 || isset($this->today[$invoice->id])) {
                continue;
            }
            // As the reminder counts it (ChaseLadder::overdue): from the later of the due date and the day it was sent plus
            // its terms, so an invoice sent late isn't called overdue before a reminder could be made.
            $days = ChaseLadder::daysOverdue($invoice, $today);
            if ($days === null || ! self::overdueDue($invoice, $days, $today)) {
                continue;
            }
            $currency = $invoice->currency ?: 'GBP';
            $money = fn (int $minor) => Money::format($minor, $currency, false);
            $come = match (true) {
                $r['waiting'] > 0 => ' '.Money::format($r['waiting'], 'GBP', false).' that may be for it is waiting in the Inbox: decide it first.',
                $r['refunded'] > 0 => ' '.ucfirst(ChaseLadder::paidBack($invoice, $r['refunded'], $currency)),
                $r['paid'] > 0 => ' '.$money($r['paid']).' of it has come in, so '.$money($r['outstanding']).' is still to come.',
                $r['elsewhere'] > 0 => ' '.Money::format($r['elsewhere'], 'GBP', false).' came in for this job in another currency: check whether it’s been paid.',
                default => null,
            };
            [$reminder, $words] = $this->reminderButton($invoice, $emails, $r, $come);
            $text = "Invoice {$invoice->number} to ".($invoice->bill_to_name ?: 'the client').' is '.$days.' days overdue ('.$money((int) $invoice->total_minor).').'
                .($come ?? '').($invoice->reminder_count ? " Reminders sent: {$invoice->reminder_count}." : '').$words;
            // The reminder on a row of its own, then They've paid and Not yet (none while a payment may be for it).
            $rows = array_values(array_filter([$reminder !== null ? [$reminder] : [],
                $r['waiting'] > 0 ? [] : [['They’ve paid', 'inv_paid_wait', (string) $invoice->id], ['Not yet', 'inv_chase_later', (string) $invoice->id]]]));
            if ($this->send($chat, $text, $rows)) {
                if (! $this->option('dry-run')) {
                    $invoice->forceFill(['overdue_nudged_at' => now()])->save();
                }
                $this->told($invoice->id);
                $this->overdue++;
            }
        }
    }

    /**
     * Whether an overdue invoice is nudged about today: once it's 3 days overdue, again at 14 and 30, then weekly; never
     * twice in a week except when it reaches a new stage. Days are London days (ChaseLadder::overdue's), so a run that
     * reaches it a few seconds sooner than last week's still nudges on the 7th day.
     */
    public static function overdueDue(Invoice $invoice, int $days, ?CarbonImmutable $today = null): bool
    {
        $reached = collect(self::OVERDUE_DAYS)->filter(fn (int $d) => $days >= $d)->count();
        if ($reached === 0) {
            return false;
        }
        $last = $invoice->overdue_nudged_at;
        if ($last === null) {
            return true;
        }
        $today ??= CarbonImmutable::now(self::TZ)->startOfDay();
        $lastDay = CarbonImmutable::parse(CarbonImmutable::parse($last)->setTimezone(self::TZ)->format('Y-m-d'), self::TZ);
        $then = ChaseLadder::overdue($invoice, $lastDay)['days'] ?? 0;
        $before = collect(self::OVERDUE_DAYS)->filter(fn (int $d) => $then >= $d)->count();

        return $reached > $before || ($reached === count(self::OVERDUE_DAYS) && $lastDay->addDays(self::WEEK)->lte($today));
    }

    /**
     * The reminder button for an overdue invoice, and words about its reminder: [Send it] when your reminder is in your
     * Gmail drafts as Expend made it (and can be sent with one tap), [Make a reminder] when there's none (Gmail), or
     * [Send a reminder] with no Gmail, as before (only when nothing has come in: that email says so). None while a
     * payment that may be for it waits, or the invoice's own Gmail draft is open.
     *
     * @return array{0:?array, 1:string}
     */
    private function reminderButton(Invoice $invoice, InvoiceEmails $emails, array $r, ?string $come): array
    {
        if ($r['waiting'] > 0 || InvoiceEmails::open($invoice) !== null) {
            return [null, ''];
        }
        if (! InvoiceEmails::canUse()) {
            return [$invoice->bill_to_email && $come === null ? ['Send a reminder', 'inv_chase', (string) $invoice->id] : null, ''];
        }
        $open = InvoiceEmails::open($invoice, 'reminder');
        if ($open === null) {
            return [['Make a reminder', 'inv_chase', (string) $invoice->id], ''];
        }
        $edited = $open->current_message_id !== null && $open->current_message_id !== $open->draft_message_id;
        if ($edited) {
            return [null, ' Your edited reminder is in your Gmail drafts: send it there.'];
        }

        return [$emails->oneTapProblem($invoice, 'reminder', live: false) === null ? ['Send it', 'inv_chase_send', (string) $invoice->id] : null, ' A reminder is in your Gmail drafts.'];
    }

    /**
     * Drafts of jobs marked done: 2 days after, then weekly, while they stay drafts. A follow-up draft (late costs on a
     * job already invoiced) isn't the job's invoice: it's in the Monday digest (its draft: item), not here.
     */
    private function drafts(string|int $chat, CarbonImmutable $today, bool $jobDay): void
    {
        $drafts = Invoice::query()->where('status', 'draft')->whereNotNull('work_job_id')
            ->whereHas('job', fn ($q) => $q->where('status', 'done')->whereNotNull('done_at'))
            ->whereNotIn('work_job_id', Invoice::query()->whereIn('status', InvoicePayments::PAYABLE)->whereNotNull('work_job_id')->select('work_job_id'))
            ->with('job:id,title,status,done_at')->orderBy('id')->get();
        foreach ($drafts as $draft) {
            $done = CarbonImmutable::parse($draft->job->done_at)->setTimezone(self::TZ)->startOfDay();
            $key = 'draft:'.$draft->id;
            if ($done->addDays(self::DRAFT_AFTER_DAYS)->gt($today) || ! $this->cadence($key, $today, [self::DRAFT_AFTER_DAYS], $done)
                || Dismissals::hiddenNow('invoice:'.$draft->work_job_id) !== null || Dismissals::hiddenNow('draft:'.$draft->id) !== null || isset($this->today[$draft->id])) {
                continue;
            }
            if ($this->waits($key, $today, $jobDay)) {
                continue;
            }
            $text = MorningReport::clean('The invoice for '.mb_substr(trim($draft->job->title), 0, 120).' is still a draft.');
            if ($this->send($chat, $text, [[['url' => url('/invoices/'.$draft->id), 'text' => 'Check the invoice'], ['Remind me Monday', 'inv_draft_later', (string) $draft->id]]])) {
                $this->mark($key, $today, [self::DRAFT_AFTER_DAYS], $done);
                $this->other++;
            }
        }
    }

    /** "The Gmail draft for INV-0042 was deleted without being sent", once per draft. */
    private function deleted(string|int $chat, CarbonImmutable $today, bool $jobDay): void
    {
        $rows = InvoiceEmail::query()->where('kind', 'invoice')->where('route', 'gmail')->where('status', 'deleted')
            ->whereHas('invoice', fn ($q) => $q->where('status', 'issued')->whereNull('sent_at'))->with('invoice')->orderBy('id')->get();
        foreach ($rows as $row) {
            $key = 'deleted:'.$row->id;
            $latest = InvoiceEmails::latest($row->invoice);
            if (isset($this->state[$key]) || $latest?->id !== $row->id || isset($this->today[$row->invoice_id]) || Dismissals::hiddenNow('unsent:'.$row->invoice_id) !== null) {
                continue;
            }
            if ($this->waits($key, $today, $jobDay)) {
                continue;
            }
            $text = 'The Gmail draft for '.$row->invoice->number.' was deleted without being sent.';
            $buttons = [['Put it back in Gmail', 'inv_to_gmail', (string) $row->invoice_id], ['I sent it another way', 'inv_sent_self', (string) $row->invoice_id]];
            if ($this->send($chat, $text, [$buttons])) {
                $this->mark($key, $today);
                $this->told($row->invoice_id);
                $this->other++;
            }
        }
    }

    /** Issued and not sent: day 1 and day 3 after issuing, then weekly. */
    private function notSent(string|int $chat, InvoiceEmails $emails, CarbonImmutable $today, bool $jobDay): void
    {
        $invoices = Invoice::query()->where('status', 'issued')->whereNull('sent_at')->whereNotNull('issue_date')->with('job:id,title')->orderBy('issue_date')->orderBy('id')->get();
        $received = InvoicePayments::received($invoices);
        $gmail = InvoiceEmails::canUse();
        foreach ($invoices as $invoice) {
            $r = $received[$invoice->id] ?? InvoicePayments::EMPTY;
            $key = 'unsent:'.$invoice->id;
            $issued = CarbonImmutable::parse($invoice->issue_date->format('Y-m-d'), self::TZ);
            $row = InvoiceEmails::latest($invoice);
            if ($r['outstanding'] <= 0 || $r['waiting'] > 0 || $r['refunded'] > 0 || isset($this->today[$invoice->id]) || $row?->status === 'deleted'
                || Dismissals::hiddenNow('unsent:'.$invoice->id) !== null || ! $this->cadence($key, $today, self::NOT_SENT_DAYS, $issued)) {
                continue;
            }
            if ($this->waits($key, $today, $jobDay)) {
                continue;
            }
            $name = $invoice->number.($invoice->job ? ' ('.mb_substr(trim($invoice->job->title), 0, 80).')' : '');
            $status = $row?->route === 'gmail' ? $row->status : null;
            [$text, $buttons] = match (true) {
                $status === 'drafted' => [$name.' is issued but not sent: it’s in your Gmail drafts.', array_values(array_filter([
                    $emails->oneTapProblem($invoice, 'invoice', live: false) === null ? ['Send it', 'inv_gmail_send', (string) $invoice->id] : null,
                    ['url' => self::gmailLink($row), 'text' => 'Open in Gmail'],
                    ['I sent it myself', 'inv_sent_self', (string) $invoice->id],
                ]))],
                in_array($status, ['pending', 'failed'], true) => [$name.' is issued but isn’t in your Gmail drafts yet: '.InvoiceEmails::reason((string) ($row->error ?: 'Gmail didn’t answer')).'.',
                    array_values(array_filter([$gmail ? ['Try again', 'inv_to_gmail', (string) $invoice->id] : null,
                        $invoice->bill_to_email ? ['Email it from Expend', 'inv_send', (string) $invoice->id] : null]))],
                $gmail => [$name.' is issued but not in Gmail'.($invoice->bill_to_email ? '.' : ': who should get it?'),
                    [['Put it in Gmail', 'inv_to_gmail', (string) $invoice->id], ['I sent it myself', 'inv_sent_self', (string) $invoice->id]]],
                default => [$name.' is issued but not sent.', array_values(array_filter([$invoice->bill_to_email ? ['Email it', 'inv_send', (string) $invoice->id] : null,
                    ['I sent it myself', 'inv_sent_self', (string) $invoice->id]]))],
            };
            if ($this->send($chat, MorningReport::clean($text), [$buttons, [['url' => url('/invoices/'.$invoice->id), 'text' => 'Open the invoice']]])) {
                $this->mark($key, $today, self::NOT_SENT_DAYS, $issued);
                $this->told($invoice->id);
                $this->other++;
            }
        }
    }

    /**
     * Whether a repeated message is due today: on each of the first days given (counted from $from), then weekly
     * after the last.
     *
     * @param  list<int>  $days
     */
    private function cadence(string $key, CarbonImmutable $today, array $days, CarbonImmutable $from): bool
    {
        $told = $this->state[$key] ?? null;
        $n = is_array($told) ? (int) ($told['n'] ?? 0) : 0;
        $since = (int) $from->diffInDays($today, false);
        if ($n < count($days)) {
            return $since >= $days[$n] && (! is_array($told) || ($told['at'] ?? '') < $today->format('Y-m-d'));
        }

        return is_array($told) && CarbonImmutable::parse($told['at'], self::TZ)->addDays(self::WEEK)->lte($today);
    }

    /** On one of your job days, a message waits: for at most 2 days, then it goes anyway. */
    private function waits(string $key, CarbonImmutable $today, bool $jobDay): bool
    {
        if (! $jobDay) {
            unset($this->state['held:'.$key]);

            return false;
        }
        $held = $this->state['held:'.$key] ?? null;
        if (! is_string($held)) {
            $this->state['held:'.$key] = $today->format('Y-m-d');

            return true;
        }

        return CarbonImmutable::parse($held, self::TZ)->addDays(self::MAX_WAIT_DAYS)->gt($today);
    }

    /**
     * Keep that it was told today. With its first days (counted from $from), the days already past count as told, so
     * one first seen late (an invoice issued long before this release) goes weekly from then, not again tomorrow.
     *
     * @param  list<int>  $days
     */
    private function mark(string $key, CarbonImmutable $today, array $days = [], ?CarbonImmutable $from = null): void
    {
        $told = $this->state[$key] ?? null;
        $n = (is_array($told) ? (int) ($told['n'] ?? 0) : 0) + 1;
        if ($from !== null) {
            $since = (int) $from->diffInDays($today, false);
            $n = max($n, count(array_filter($days, fn (int $d) => $since >= $d)));
        }
        $this->state[$key] = ['at' => $today->format('Y-m-d'), 'n' => $n];
        unset($this->state['held:'.$key]);
    }

    /** At most one message per invoice a day. */
    private function told(int $invoiceId): void
    {
        $this->today[$invoiceId] = true;
        $this->state['day:'.$invoiceId] = today()->format('Y-m-d');
    }

    /** Where "Open in Gmail" goes: the draft's conversation in Gmail on the web, else your drafts. */
    private static function gmailLink(?InvoiceEmail $row): string
    {
        return Telegram::GMAIL.'mail/u/0/'.($row?->gmail_thread_id ? '#all/'.$row->gmail_thread_id : '#drafts');
    }

    /**
     * Send one message with its rows of buttons ([label, code, id], or a link); in a dry run, only say it.
     *
     * @param  list<list<array>>  $rows
     */
    private function send(string|int $chat, string $text, array $rows): bool
    {
        if ($this->option('dry-run')) {
            $this->line($text);

            return true;
        }
        try {
            return Telegram::sendRows($chat, $text, array_values(array_filter($rows)));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
