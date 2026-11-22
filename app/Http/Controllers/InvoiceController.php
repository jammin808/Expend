<?php

namespace App\Http\Controllers;

use App\Domain\Invoices\InvoiceService;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(): Response
    {
        return Inertia::render('invoices/index', [
            'invoices' => Invoice::query()->with(['job', 'client'])->orderByDesc('id')->limit(300)->get()->map(fn (Invoice $i) => self::row($i)),
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
                'lines' => $invoice->lines->map(fn ($l) => [
                    'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', ''),
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
            'bill_to_name' => 'nullable|string|max:200', 'bill_to_address' => 'nullable|string|max:1000', 'bill_to_email' => 'nullable|email|max:200',
            'po_number' => 'nullable|string|max:60', 'currency' => 'required|string|size:3', 'notes' => 'nullable|string|max:2000',
            'work_job_id' => 'nullable|integer|exists:work_jobs,id', 'client_id' => 'nullable|integer|exists:clients,id',
            'lines' => 'array', 'lines.*.description' => 'required|string|max:500', 'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|string', 'lines.*.source_quote' => 'nullable|string',
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

        return back()->with('success', "Issued {$issued->number}. Download the PDF to send it.");
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);
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

    public static function row(Invoice $i): array
    {
        $paid = $i->paidMinor();

        return [
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status === 'issued' && $paid >= $i->total_minor && $i->total_minor > 0 ? 'paid' : $i->status,
            'client' => $i->client?->name ?? $i->bill_to_name, 'client_id' => $i->client_id, 'bill_to_name' => $i->bill_to_name,
            'job' => $i->job ? ['id' => $i->job->id, 'title' => $i->job->title] : null, 'work_job_id' => $i->work_job_id,
            'po_number' => $i->po_number, 'currency' => $i->currency, 'total_minor' => $i->total_minor, 'paid_minor' => $paid,
            'issue_date' => $i->issue_date?->format('Y-m-d'), 'due_date' => $i->due_date?->format('Y-m-d'),
            'overdue' => $i->status === 'issued' && $paid < $i->total_minor && $i->due_date?->isPast(),
        ];
    }
}
