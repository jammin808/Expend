<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(): Response
    {
        return Inertia::render('invoices/index', [
            'invoices' => self::rows(Invoice::query()->with(['job', 'client'])->orderByDesc('id')->limit(300)->get()),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['lines', 'job', 'client', 'document']);

        return Inertia::render('invoices/show', [
            'invoice' => self::row($invoice) + [
                'bill_to_address' => $invoice->bill_to_address, 'bill_to_email' => $invoice->bill_to_email, 'notes' => $invoice->notes,
                'po_total_minor' => $invoice->po_total_minor, 'document' => $invoice->document ? ['id' => $invoice->document->id, 'name' => $invoice->document->original_name] : null,
                'void_reason' => $invoice->void_reason,
                'sent_at' => $invoice->sent_at?->format('Y-m-d H:i'), 'sent_to' => $invoice->sent_to,
                'reminded_at' => $invoice->reminded_at?->format('Y-m-d H:i'), 'reminder_count' => $invoice->reminder_count,
                'email_failed' => self::lastEmailFailed($invoice),
                'lines' => $invoice->lines->map(fn ($l) => [
                    'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) BigDecimal::ofUnscaledValue($l->unit_price_minor, 2),
                    'amount_minor' => $l->amount_minor, 'source_quote' => $l->source_quote,
                ]),
            ],
            'jobs' => WorkJob::query()->orderByDesc('starts_on')->limit(300)->get(['id', 'title', 'starts_on'])->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')]),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeForJob(WorkJob $job): RedirectResponse
    {
        return to_route('invoices.show', $this->invoices->draftForJob($job->load('client')));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            // An address the invoice can actually be emailed to ('filter' as well as 'rfc': the RFC alone lets
            // accounts@client, with no ending, through), checked the same way as when it's sent.
            'bill_to_name' => 'nullable|string|max:200', 'bill_to_address' => 'nullable|string|max:1000', 'bill_to_email' => 'nullable|email:rfc,filter|max:200',
            // The same limit as a job's or an opportunity's PO number, and what a PO's reading is cut to (DocumentReader).
            'po_number' => 'nullable|string|max:100', 'currency' => 'required|string|size:3', 'notes' => 'nullable|string|max:2000',
            'work_job_id' => 'nullable|integer|exists:work_jobs,id', 'client_id' => 'nullable|integer|exists:clients,id',
            'lines' => 'array', 'lines.*.description' => 'required|string|max:500', 'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|string', 'lines.*.source_quote' => 'nullable|string',
        ], [
            'bill_to_name.max' => '“Bill to” is too long (200 characters at most).',
            'bill_to_address.max' => 'The address is too long (1,000 characters at most).',
            'bill_to_email.email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.',
            'bill_to_email.max' => 'That email address is too long.',
            'po_number.max' => 'The PO number is too long (100 characters at most).',
            'notes.max' => 'The notes are too long (2,000 characters at most).',
            // Chosen from the page's lists: gone only if deleted since the page was opened.
            'work_job_id.exists' => 'That job no longer exists. Choose another, or None.',
            'client_id.exists' => 'That client no longer exists. Choose another, or None.',
            // The page shows the first of these as a message, so each says which line.
            'lines.*.description.required' => 'Line :position needs a description.',
            'lines.*.description.max' => 'The description on line :position is too long (500 characters at most).',
            'lines.*.quantity.required' => 'Line :position needs a quantity, like 1 or 0.5.',
            'lines.*.quantity.numeric' => 'The quantity on line :position must be a number, like 1 or 0.5.',
            'lines.*.quantity.min' => 'The quantity on line :position must be 0.01 or more.',
            'lines.*.unit_price.required' => 'Line :position needs a price, like 450.00.',
        ]);
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        $data['currency'] = strtoupper($data['currency']);

        try {
            $this->invoices->updateDraft($invoice, $data, $lines);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft saved.');
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        try {
            $issued = $this->invoices->issue($invoice, $request->string('issue_date')->toString() ?: null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Issued {$issued->number}. Email it to the client, or download the PDF to send it yourself.");
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        // Typed into the page's prompt, and shown with the voided invoice and in the audit log.
        $data = $request->validate(['reason' => 'required|string|min:3|max:250'], [
            'reason.required' => 'Say why you are voiding this invoice, in a few words.',
            'reason.min' => 'Say why you are voiding this invoice in a few more words (3 characters at least).',
            'reason.max' => 'The reason is too long (250 characters at most).',
        ]);
        try {
            $this->invoices->void($invoice, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Voided. Issue a new invoice if needed.');
    }

    public function pdf(Invoice $invoice): StreamedResponse|\Illuminate\Http\Response
    {
        if ($invoice->pdf_path) {
            return Storage::disk('local')->download($invoice->pdf_path, "{$invoice->number}.pdf");
        }

        return response($this->invoices->renderPdf($invoice->load('lines')), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="draft.pdf"']);
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== 'draft') {
            return back()->with('error', 'Only drafts can be deleted. Void an issued invoice instead.');
        }
        $invoice->delete();

        return to_route('invoices.index')->with('success', 'Draft deleted.');
    }

    /** Email the issued invoice to the client (or a reminder when it's overdue), from your mailbox if set up. */
    public function send(Request $request, Invoice $invoice, InvoiceMailer $mailer): RedirectResponse
    {
        // Checked as the mailer checks it ('filter' as well as 'rfc'), so a typo such as accounts@client gets this message.
        $data = $request->validate(['to' => 'nullable|email:rfc,filter|max:200', 'reminder' => 'boolean'], [
            'to.email' => 'That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.',
            'to.max' => 'That email address is too long.',
        ]);
        try {
            $message = $mailer->send($invoice, $data['to'] ?? null, (bool) ($data['reminder'] ?? false));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The email couldn’t be sent: '.$e->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * The last email for this invoice didn't send (and so it was put back as it was): what and why, for the page.
     * One that failed while a later email was already on its way isn't the last word: that later one is (it says
     * so itself if it fails too).
     *
     * @return array{to:?string, error:?string, at:string, reminder:bool}|null
     */
    private static function lastEmailFailed(Invoice $invoice): ?array
    {
        $last = AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $invoice->id)
            ->whereIn('action', ['invoice.sent', 'invoice.reminder_sent', 'invoice.send_failed', 'invoice.reminder_failed'])->orderByDesc('id')->limit(50)->get()
            ->first(fn (AuditEvent $e) => ! (str_ends_with($e->action, '_failed') && ($e->after['outcome'] ?? null) === 'later'));
        if (! $last || ! str_ends_with($last->action, '_failed')) {
            return null;
        }

        return ['to' => $last->after['to'] ?? null, 'error' => $last->after['error'] ?? null, 'at' => $last->occurred_at->format('Y-m-d H:i'), 'reminder' => $last->action === 'invoice.reminder_failed'];
    }

    /**
     * Rows for many invoices, with what has come in for them worked out together.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<int, array>
     */
    public static function rows(Collection $invoices): Collection
    {
        $received = InvoicePayments::received($invoices);

        return $invoices->map(fn (Invoice $i) => self::row($i, $received[$i->id]['paid'] ?? 0))->values();
    }

    /**
     * @param  int|null  $paid  what has come in for it (InvoicePayments::received()), if already worked out
     */
    public static function row(Invoice $i, ?int $paid = null): array
    {
        $paid ??= $i->paidMinor();

        return [
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status === 'issued' && $paid >= $i->total_minor && $i->total_minor > 0 ? 'paid' : $i->status,
            'client' => $i->client?->name ?? $i->bill_to_name, 'client_id' => $i->client_id, 'bill_to_name' => $i->bill_to_name,
            'job' => $i->job ? ['id' => $i->job->id, 'title' => $i->job->title] : null, 'work_job_id' => $i->work_job_id,
            'po_number' => $i->po_number, 'currency' => $i->currency, 'total_minor' => $i->total_minor, 'paid_minor' => $paid,
            'issue_date' => $i->issue_date?->format('Y-m-d'), 'due_date' => $i->due_date?->format('Y-m-d'),
            // Overdue from the day after it's due, as on the to-do list and in the Telegram nudges: on the due date it's still on time.
            'overdue' => $i->status === 'issued' && $paid < $i->total_minor && (bool) $i->due_date?->lt(today()),
        ];
    }
}
