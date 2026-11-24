<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Mail\OutgoingMail;
use App\Domain\Telegram\Telegram;
use App\Mail\InvoiceEmail;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Emails an issued invoice to the client, and reminders when it's overdue. Sent from your mailbox if set up.
 *
 * The invoice is marked as sent (or reminded) when the email is queued, so a second press can see it's been done;
 * if the email then fails to send, that is undone and you're told (see failed()).
 */
final class InvoiceMailer
{
    /**
     * A one-tap reminder isn't sent within this many hours of the last one, whenever its button was offered: a
     * double-click, or the to-do list (which doesn't know a reminder just went) offering it again the same day.
     * Well under the week between the overdue nudges, so next week's button always works.
     */
    public const REPEAT_HOURS = 24;

    /**
     * @param  bool  $once  a one-tap button: do nothing if it's already been done (emailed, reminded since the button
     *                      was offered, or paid) or something has come in, so pressing it twice, or pressing an old
     *                      button, never emails the client again
     * @param  CarbonInterface|null  $offeredAt  when the button was offered (a Telegram message's date): a reminder
     *                                           sent since then means this press is a repeat
     * @return string what happened, for a message
     */
    public function send(Invoice $invoice, ?string $to = null, bool $reminder = false, bool $once = false, ?CarbonInterface $offeredAt = null): string
    {
        if ($invoice->status !== 'issued' && ! ($reminder === false && $invoice->status === 'paid')) {
            throw new InvalidArgumentException('Only issued invoices can be emailed.');
        }

        return DB::transaction(function () use ($invoice, $to, $reminder, $once, $offeredAt) {
            // Locked, so two presses handled at once can't both send.
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($reminder && ($held = $this->holdReminder($fresh, $once))) {
                return $held;
            }
            if ($once && ! $reminder && $fresh->sent_at) {
                return "Invoice {$fresh->number} was already ".($fresh->sent_to ? "emailed to {$fresh->sent_to}" : 'sent').' on '.$fresh->sent_at->format('j M Y').', so it wasn’t sent again.';
            }
            $last = $fresh->reminded_at;
            if ($once && $reminder && $last && ($last->gt(now()->subHours(self::REPEAT_HOURS)) || ($offeredAt && $last->gte($offeredAt)))) {
                return "A reminder for invoice {$fresh->number} went on ".$last->format('j M Y \a\t H:i').', so another wasn’t sent. To send one anyway, use the invoice’s page.';
            }
            if ($reminder && ! $fresh->due_date?->lt(today())) {
                throw new InvalidArgumentException("Invoice {$fresh->number} isn’t overdue".($fresh->due_date ? ' (it’s due on '.$fresh->due_date->format('j M Y').')' : '').', so it’s too early for a reminder.');
            }

            $typed = trim((string) $to);
            $to = $typed !== '' ? $typed : trim((string) $fresh->bill_to_email);
            if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                // An issued invoice can't be changed, so the fix is always to type the address when you send it.
                throw new InvalidArgumentException(match (true) {
                    $typed !== '' => 'That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.',
                    $to !== '' => "The client’s email address on the invoice, {$to}, doesn’t look right: email it from the invoice’s page and type the right address.",
                    default => 'There’s no email address for the client on this invoice: email it from the invoice’s page and type their address.',
                });
            }
            if (! $fresh->pdf_path) {
                throw new InvalidArgumentException('The invoice PDF is missing.');
            }
            if (OutgoingMail::method() === 'off') {
                throw new InvalidArgumentException('Sending email is switched off in Settings › Integrations.');
            }

            // What to put back if the email fails to send. Each email's mark is a different second, so a failure
            // can tell its own mark from a later email's.
            $field = $reminder ? 'reminded_at' : 'sent_at';
            $at = now()->startOfSecond();
            if ($fresh->{$field} && $fresh->{$field}->gte($at)) {
                $at = $fresh->{$field}->copy()->startOfSecond()->addSecond();
            }
            $before = $reminder
                ? ['reminded_at' => $fresh->reminded_at?->format('Y-m-d H:i:s'), 'reminder_count' => (int) $fresh->reminder_count]
                : ['sent_at' => $fresh->sent_at?->format('Y-m-d H:i:s'), 'sent_to' => $fresh->sent_to];
            // The invoice's own Message-ID, given once and kept: the invoice goes with it every time, and each
            // reminder points back to it.
            $thread = $fresh->message_id ?: self::newMessageId($fresh);
            $reminder
                ? $fresh->forceFill(['reminded_at' => $at, 'reminder_count' => $fresh->reminder_count + 1, 'message_id' => $thread])->save()
                : $fresh->forceFill(['sent_at' => $at, 'sent_to' => $to, 'message_id' => $thread])->save();
            AuditLog::record($reminder ? 'invoice.reminder_sent' : 'invoice.sent', $fresh, null, ['to' => $to, 'number' => $fresh->number, 'message_id' => $thread]);
            Mail::to($to)->queue(new InvoiceEmail($fresh, $reminder, $before, $at->format('Y-m-d H:i:s'), $thread));
            $invoice->setRawAttributes($fresh->getAttributes(), true);

            return ($reminder ? 'Reminder for ' : 'Invoice ').$fresh->number.' is on its way to '.$to.'.';
        });
    }

    /**
     * A Message-ID for an invoice's emails (without the angle brackets): unique, and on the domain the email is sent
     * from (the From address's, else the site's), as mail programs expect. invoice-43.3f9c1a7b2d4e6f80@example.co.uk
     */
    public static function newMessageId(Invoice $invoice): string
    {
        $from = OutgoingMail::method() !== null ? OutgoingMail::from() : (string) config('mail.from.address');
        $domain = strtolower(trim((string) substr((string) strrchr($from, '@'), 1)));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $domain)) {
            $domain = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        }
        if (! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            $domain = 'localhost';
        }
        $number = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $invoice->number)), '-') ?: (string) $invoice->id;

        return mb_substr('invoice-'.$number, 0, 80).'.'.bin2hex(random_bytes(8)).'@'.$domain;
    }

    /**
     * Whether a reminder should wait, by what has come in for the invoice, counted as the to-do list counts it (a
     * payment linked to it, income recorded for its job, money in another currency, or one waiting in the Inbox).
     * Paid in full: never. Anything else come in: not from a one-tap button, whose email tells the client no payment
     * has been seen (the to-do list doesn't offer it then); from the invoice's page you can still choose to.
     *
     * @return string|null why not, for a message (a one-tap button), or null to go ahead
     */
    private function holdReminder(Invoice $fresh, bool $once): ?string
    {
        ['paid' => $paid, 'elsewhere' => $elsewhere, 'waiting' => $waiting] = InvoicePayments::received([$fresh])[$fresh->id] ?? ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0];
        if ($paid >= $fresh->total_minor) {
            $message = "Invoice {$fresh->number} is paid in full, so no reminder was sent.";
            if ($once) {
                return $message;
            }
            throw new InvalidArgumentException($message);
        }
        if (! $once) {
            return null;
        }
        $after = ' To send one anyway, use the invoice’s page.';

        return match (true) {
            $waiting > 0 => 'No reminder was sent: '.Money::format($waiting).' that may be for invoice '.$fresh->number.' is waiting in the Inbox. Decide it first.'.$after,
            $paid > 0 => 'No reminder was sent: '.Money::format($paid, $fresh->currency ?: 'GBP').' of it has come in, and the reminder tells the client no payment has been seen.'.$after,
            $elsewhere > 0 => 'No reminder was sent: '.Money::format($elsewhere).' came in for this job in another currency, so check whether invoice '.$fresh->number.' has been paid first.'.$after,
            default => null,
        };
    }

    /**
     * The queued email couldn't be sent (a wrong mailbox password, the mail server refusing it…): put the invoice
     * back as it was before this email, so it isn't shown as sent and "Send invoice" comes back on the to-do list,
     * and tell you what it now shows.
     *
     * If a later email has marked it since, that mark is left alone (this failure is noted, so if the later one fails
     * too, it goes back past this one as well). An email queued before this was tracked has nothing to put back.
     *
     * @param  array<string, mixed>  $before  the sent or reminder fields as they were before this email
     * @param  string  $claimedAt  when this email marked the invoice sent or reminded, so a later send isn't undone
     */
    public function failed(Invoice $invoice, bool $reminder, array $before, string $claimedAt, string $to, Throwable $e): void
    {
        $field = $reminder ? 'reminded_at' : 'sent_at';
        $fields = $reminder ? ['reminded_at', 'reminder_count'] : ['sent_at', 'sent_to'];
        $why = Str::limit(trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''), 200) ?: class_basename($e);

        // Locked, and the failure noted in the same transaction, so a failure of another email for this invoice
        // handled at the same moment sees this one.
        [$fresh, $outcome] = DB::transaction(function () use ($invoice, $reminder, $before, $claimedAt, $field, $fields, $to, $why) {
            $fresh = Invoice::query()->lockForUpdate()->find($invoice->id) ?? $invoice;
            $was = self::fieldsOf($fresh, $fields);
            $mark = $fresh->{$field}?->format('Y-m-d H:i:s');
            $restore = $before === [] || $claimedAt === '' ? null : $this->beforeFailedEmails($fresh, $reminder, $before);
            $outcome = match (true) {
                ! $fresh->exists || $restore === null => 'untracked',
                $mark === $claimedAt => 'undone',
                $mark !== null && $mark > $claimedAt => 'later',
                default => 'untracked',
            };
            if ($outcome === 'undone') {
                $fresh->forceFill($restore)->save();
            } elseif ($outcome === 'later' && $reminder && (int) $fresh->reminder_count > 0) {
                // A later reminder holds the mark; this one didn't go, so it isn't counted.
                $fresh->forceFill(['reminder_count' => (int) $fresh->reminder_count - 1])->save();
            }
            $changed = $was !== self::fieldsOf($fresh, $fields);
            AuditLog::record($reminder ? 'invoice.reminder_failed' : 'invoice.send_failed', $fresh, $changed ? $was : null, [
                'to' => $to, 'number' => $fresh->number, 'error' => $why,
                // So that if a later email fails too, it goes back past this one.
                'claimed_at' => $claimedAt !== '' ? $claimedAt : null, 'before_this_email' => $restore, 'outcome' => $outcome,
            ] + ($changed ? self::fieldsOf($fresh, $fields) : []), 'The email didn’t send', 'mailer');

            return [$fresh, $outcome];
        });

        try {
            $chat = Setting::get('telegram_chat_id');
            if ($chat && Telegram::token()) {
                Telegram::reply($chat, '⚠ '.($reminder ? "The reminder for invoice {$fresh->number}" : "Invoice {$fresh->number}")." couldn’t be emailed to {$to}: {$why}"
                    ."\n".self::nowShows($fresh, $reminder, $outcome).' Check Settings › Integrations, then send it again if it’s still needed: '.url('/invoices/'.$fresh->id));
            }
        } catch (Throwable $telegram) {
            report($telegram);
        }
    }

    /**
     * What to put back for an email that failed: its own "before", unless that was itself the mark of an earlier
     * email that failed too (both were queued before either ran); then what was there before that one, and so on.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function beforeFailedEmails(Invoice $invoice, bool $reminder, array $before): array
    {
        $field = $reminder ? 'reminded_at' : 'sent_at';
        $failures = AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $invoice->id)
            ->where('action', $reminder ? 'invoice.reminder_failed' : 'invoice.send_failed')->orderBy('id')->get()
            ->filter(fn (AuditEvent $e) => is_string($e->after['claimed_at'] ?? null) && is_array($e->after['before_this_email'] ?? null))
            ->keyBy(fn (AuditEvent $e) => $e->after['claimed_at']);
        $seen = [];
        while (is_string($at = $before[$field] ?? null) && isset($failures[$at]) && ! isset($seen[$at])) {
            $seen[$at] = true;
            $before = $failures[$at]->after['before_this_email'];
        }

        return $before;
    }

    /** What the invoice shows after a failed email, in a sentence for the alert. */
    private static function nowShows(Invoice $invoice, bool $reminder, string $outcome): string
    {
        $on = $invoice->sent_at?->format('j M Y');

        return match (true) {
            $reminder && $outcome === 'undone' => 'The reminder isn’t counted.',
            $reminder && $outcome === 'later' => 'It isn’t counted. A later reminder still is: you’ll hear if that one fails too.',
            $reminder => 'It may still be counted as sent: check the invoice’s page.',
            $outcome === 'later' => 'A later email, to '.($invoice->sent_to ?? 'the client').', is still marked as sent: you’ll hear if that one fails too.',
            ! $invoice->sent_at => 'It isn’t marked as sent.',
            $outcome === 'undone' => $invoice->sent_to ? "It shows the earlier email again, to {$invoice->sent_to} on {$on}." : "It shows as sent outside Expend on {$on} again.",
            default => 'It still shows as '.($invoice->sent_to ? "emailed to {$invoice->sent_to}" : 'sent outside Expend')." on {$on}, so check whether it reached them.",
        };
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function fieldsOf(Invoice $invoice, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn ($f) => [$f => match (true) {
            $invoice->{$f} instanceof CarbonInterface => $invoice->{$f}->format('Y-m-d H:i:s'),
            $f === 'reminder_count' => (int) $invoice->{$f},
            default => $invoice->{$f},
        }])->all();
    }
}
