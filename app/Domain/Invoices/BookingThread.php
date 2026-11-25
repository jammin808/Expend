<?php

namespace App\Domain\Invoices;

use App\Domain\Clients\ClientIds;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailLabeller;
use App\Domain\Jobs\PoStatus;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\WorkJob;
use Throwable;

/**
 * Which Gmail conversation an invoice's email goes in: the booking's, so the client sees it beside their PO or their
 * offer of work. In order:
 *  1. the job's PO email's conversation (GmailLabeller::poMessages);
 *  2. the conversation the job's enquiry or offer came in (opportunities.thread_key);
 *  3. other conversations linked to the job (its emails Gmail found) from the client's own domains, newest first.
 * None of them: a new email. A client whose invoices go as a new email (invoice_route 'new'), or who pays without an
 * invoice from you ('none'), never gets a conversation. 'Use another conversation' on the issue sheet lists them all.
 *
 * Read only. Without $search only what Expend holds is used, so a page can list them without calling Gmail.
 */
final class BookingThread
{
    public function __construct(private GmailLabeller $labeller) {}

    /**
     * @return list<array{thread_id:string, how:string, subject:?string, from:?string, at:?string}> most fitting first;
     *                                                                                              how: po | enquiry | job
     */
    public function candidates(Invoice $invoice, bool $search = false): array
    {
        $job = $invoice->work_job_id ? WorkJob::query()->find($invoice->work_job_id) : null;
        if ($job === null || in_array($this->route($invoice, $job), ['new', 'none'], true)) {
            return [];
        }
        $out = [];
        $add = function (?string $thread, string $how) use (&$out) {
            if ($thread !== null && $thread !== '' && ! isset($out[$thread])) {
                $out[$thread] = ['thread_id' => $thread, 'how' => $how] + self::about($thread);
            }
        };

        try {
            $po = $this->labeller->poMessages($job, $search && GmailClient::connected());
        } catch (Throwable $e) {
            report($e); // Gmail couldn't be searched: what Expend holds still counts
            $po = $this->labeller->poMessages($job, false);
        }
        $add($po['thread'], 'po');

        Opportunity::query()->where('work_job_id', $job->id)->where('thread_key', 'like', 'gmail:%')->orderByDesc('last_seen_at')->orderByDesc('id')
            ->pluck('thread_key')->each(fn (string $key) => $add(substr($key, 6), 'enquiry'));

        $domains = $this->domains($invoice, $job);
        if ($domains !== []) {
            GmailMessage::query()->join('documents', 'documents.id', '=', 'gmail_messages.document_id')
                ->where('documents.work_job_id', $job->id)->whereNotNull('gmail_messages.thread_id')
                ->orderByDesc('documents.received_at')->orderByDesc('gmail_messages.id')
                ->get(['gmail_messages.thread_id', 'documents.email_from'])
                ->filter(fn ($m) => in_array(self::domainOf($m->email_from), $domains, true))
                ->each(fn ($m) => $add($m->thread_id, 'job'));
        }

        return array_values($out);
    }

    /** How the invoice's client gets invoices: thread (the default), new or none. */
    public function route(Invoice $invoice, ?WorkJob $job = null): string
    {
        $id = $invoice->client_id ?? ($job ?? ($invoice->work_job_id ? WorkJob::query()->find($invoice->work_job_id) : null))?->client_id;
        $client = $id === null ? null : Client::query()->find(ClientIds::resolve((int) $id));

        return $client?->invoiceRoute() ?? 'thread';
    }

    /** The domain of the address in a From or To header ("Acme Buyer <buyer@acme.example>"), lower case. */
    public static function domainOf(?string $address): ?string
    {
        return preg_match('/@([A-Z0-9.\-]+\.[A-Z]{2,})/i', (string) $address, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * The client's own email domains: the invoice's and the client's billing addresses, the job's PO emails' senders
     * and its offers' contacts.
     *
     * @return list<string>
     */
    private function domains(Invoice $invoice, WorkJob $job): array
    {
        $client = $invoice->client_id ?? $job->client_id;
        $addresses = collect([$invoice->bill_to_email, $client ? Client::query()->find(ClientIds::resolve((int) $client))?->billing_email : null]);
        $pos = Document::query()->where('work_job_id', $job->id)->get()->filter(fn (Document $d) => PoStatus::isPo($d));
        $addresses = $addresses->merge($pos->pluck('email_from'))
            ->merge(Document::query()->whereIn('id', $pos->pluck('parent_id')->filter())->pluck('email_from'))
            ->merge(Opportunity::query()->where('work_job_id', $job->id)->pluck('contact_email'));

        return $addresses->map(fn ($a) => self::domainOf($a))->filter()->unique()->values()->all();
    }

    /**
     * What Expend knows of a conversation: its newest email's subject, sender and date.
     *
     * @return array{subject:?string, from:?string, at:?string}
     */
    private static function about(string $thread): array
    {
        $doc = Document::query()->whereIn('id', GmailMessage::query()->where('thread_id', $thread)->whereNotNull('document_id')->pluck('document_id'))
            ->orderByDesc('received_at')->orderByDesc('id')->first();

        return ['subject' => $doc?->email_subject, 'from' => $doc?->email_from, 'at' => $doc?->received_at?->toIso8601String()];
    }
}
