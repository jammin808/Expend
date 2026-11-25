<?php

namespace App\Domain\Invoices;

use App\Domain\Ai\DocumentReader;
use App\Domain\Audit\AuditLog;
use App\Domain\Gmail\GmailLabeller;
use App\Jobs\SyncGmailLabels;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Drafts invoices (from a PO or a job), and issues them: sequential number, PDF, then locked.
 * Corrections are made by voiding and issuing a new invoice, never by editing an issued one.
 */
final class InvoiceService
{
    public function draftFromDocument(Document $document): Invoice
    {
        $po = $document->extraction['po'] ?? null;
        if (! $po || empty($po['lines'])) {
            throw new InvalidArgumentException('No purchase order lines were read from this document.');
        }

        return DB::transaction(function () use ($document, $po) {
            $job = $document->job;
            $client = $job?->client ?? ($po['bill_to_name'] ? Client::query()->firstOrCreate(['name' => mb_substr($po['bill_to_name'], 0, 120)]) : null);
            $terms = $po['payment_terms_days'] ?? (int) Setting::get('invoice_terms_days');

            // What the AI read fits the draft form (InvoiceController::update), so the draft saves as it opens, also
            // from a PO read before readings were cut to fit.
            $invoice = Invoice::query()->create([
                'status' => 'draft',
                'client_id' => $client?->id,
                'work_job_id' => $job?->id,
                'document_id' => $document->id,
                'po_number' => DocumentReader::clip($po['po_number'] ?? null, 100),
                'currency' => $po['currency'] ?? 'GBP',
                'bill_to_name' => DocumentReader::clip($po['bill_to_name'] ?? null, 200) ?? $client?->name,
                'bill_to_address' => DocumentReader::clip($po['bill_to_address'] ?? null, 1000, true),
                'bill_to_email' => self::emailAddress($po['bill_to_email'] ?? null),
                'po_total_minor' => $po['stated_total_minor'],
                'notes' => "Payment due within {$terms} days.",
            ]);
            foreach ($po['lines'] as $i => $line) {
                $invoice->allLines()->create([
                    'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor'],
                    'amount_minor' => $line['amount_minor'], 'source_quote' => $line['quote'], 'sort' => $i,
                ]);
            }
            $this->recalculate($invoice);
            AuditLog::record('invoice.drafted', $invoice, null, ['from_document' => $document->id, 'lines' => count($po['lines'])]);

            return $invoice->fresh('lines');
        });
    }

    public function draftForJob(WorkJob $job): Invoice
    {
        return DB::transaction(function () use ($job) {
            $invoice = Invoice::query()->create([
                'status' => 'draft',
                'client_id' => $job->client_id, 'work_job_id' => $job->id, 'bill_to_name' => $job->client?->name,
                'notes' => 'Payment due within '.(int) Setting::get('invoice_terms_days').' days.',
            ]);
            if ($job->fee_pence) {
                $invoice->allLines()->create(['description' => $job->title.' ('.$job->starts_on->format('j M Y').')', 'quantity' => 1, 'unit_price_minor' => $job->fee_pence, 'amount_minor' => $job->fee_pence]);
            }
            $this->recalculate($invoice);
            AuditLog::record('invoice.drafted', $invoice, null, ['from_job' => $job->id]);

            return $invoice->fresh('lines');
        });
    }

    /** @param list<array{description:string, quantity:float|string, unit_price:string}> $lines */
    public function updateDraft(Invoice $invoice, array $data, array $lines): Invoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $data, $lines) {
            $this->lockDraft($invoice);
            $invoice->update($data);
            // Every line, ticked or not: the form's lines replace them all (Invoice::lines() is ticked lines only). Each
            // is deleted as a model, so its own guard (only a stored draft's lines change) applies too.
            $invoice->allLines()->get()->each->delete();
            foreach (array_values($lines) as $i => $line) {
                $unit = Money::parse((string) $line['unit_price']);
                $qty = self::quantity($line['quantity'] ?? null);
                if ($unit === null || $qty === null || trim($line['description']) === '') {
                    throw new InvalidArgumentException('Line '.($i + 1).' needs a description, a quantity (up to 2 decimals) and a price.');
                }
                $invoice->allLines()->create([
                    'description' => $line['description'], 'quantity' => (string) $qty, 'unit_price_minor' => $unit,
                    'amount_minor' => self::lineAmount($qty, $unit), 'source_quote' => $line['source_quote'] ?? null, 'sort' => $i,
                ]);
            }
            $this->recalculate($invoice);
            AuditLog::record('invoice.updated', $invoice, null, ['total' => $invoice->fresh()->total_minor]);

            return $invoice->fresh('lines');
        });
    }

    public function issue(Invoice $invoice, ?string $issueDate = null): Invoice
    {
        $this->assertDraft($invoice);
        if ($invoice->lines()->count() === 0 || $invoice->total_minor <= 0) {
            throw new InvalidArgumentException('Add at least one line before issuing.');
        }
        if (! $invoice->bill_to_name) {
            throw new InvalidArgumentException('Add who the invoice is for before issuing.');
        }

        return DB::transaction(function () use ($invoice, $issueDate) {
            $this->lockDraft($invoice);
            $issued = CarbonImmutable::parse($issueDate ?? 'today');
            $terms = (int) Setting::get('invoice_terms_days') ?: 30;
            if (preg_match('/within (\d+) days/', (string) $invoice->notes, $m)) {
                $terms = (int) $m[1];
            }

            $invoice->forceFill([
                'number' => $this->nextNumber(),
                'issue_date' => $issued->format('Y-m-d'),
                'due_date' => $issued->addDays($terms)->format('Y-m-d'),
            ])->save();

            $pdf = $this->renderPdf($invoice->fresh(['lines', 'client']));
            $path = "invoices/{$invoice->number}.pdf";
            Storage::disk('local')->put($path, $pdf);

            $invoice->forceFill(['status' => 'issued', 'issued_at' => now(), 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf)])->save();
            if ($invoice->work_job_id && GmailLabeller::enabled()) {
                SyncGmailLabels::dispatch($invoice->work_job_id)->afterCommit();
            }
            AuditLog::record('invoice.issued', $invoice, null, ['number' => $invoice->number, 'total' => $invoice->total_minor, 'pdf_sha256' => $invoice->pdf_sha256]);

            return $invoice->fresh('lines');
        });
    }

    public function void(Invoice $invoice, string $reason): void
    {
        if ($invoice->status !== 'issued') {
            throw new InvalidArgumentException('Only issued invoices can be voided.');
        }
        $invoice->update(['status' => 'void', 'void_reason' => $reason]);
        if ($invoice->work_job_id && GmailLabeller::enabled()) {
            SyncGmailLabels::dispatch($invoice->work_job_id)->afterCommit();
        }
        AuditLog::record('invoice.voided', $invoice, null, null, $reason);
    }

    public function renderPdf(Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.invoice', [
            'invoice' => $invoice,
            'business' => [
                'name' => Setting::get('business_name'),
                'address' => Setting::get('business_address'),
                'email' => Setting::get('business_email'),
                'bank' => Setting::get('business_bank_details'),
            ],
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function nextNumber(): string
    {
        $prefix = (string) (Setting::get('invoice_prefix') ?? 'INV-');
        $last = Invoice::query()->whereNotNull('number')->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    private function recalculate(Invoice $invoice): void
    {
        // What it charges: ticked lines only.
        $invoice->forceFill(['total_minor' => (int) $invoice->lines()->sum('amount_minor')])->save();
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException('This invoice has been issued and can’t be changed. Void it and issue a new one.');
        }
    }

    /**
     * The invoice as stored, locked until the surrounding transaction ends, and still a draft: a draft page loaded
     * before it was issued elsewhere can't change or issue it again. The audit head is locked first (the lock order
     * everywhere: the head, then rows), as the audit event written after needs it.
     */
    private function lockDraft(Invoice $invoice): void
    {
        AuditLog::lockHead();
        $this->assertDraft(Invoice::query()->lockForUpdate()->findOrFail($invoice->id));
    }

    /**
     * One address the invoice can be emailed to, checked as the draft form and sending check it, or null. Two
     * addresses ("accounts@client.co.uk, producer@client.co.uk"), a name with the address, or one with no ending
     * (accounts@client) are left out rather than put on the invoice.
     */
    public static function emailAddress(mixed $value): ?string
    {
        $email = is_string($value) ? trim($value) : '';

        return $email !== '' && Validator::make(['email' => $email], ['email' => 'email:rfc,filter|max:200'])->passes() ? $email : null;
    }

    /** A quantity as an exact decimal with at most 2 places, or null if it isn't a positive number. */
    public static function quantity(mixed $value): ?BigDecimal
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', $text)) {
            return null;
        }
        $qty = BigDecimal::of($text)->toScale(2);

        return $qty->isPositive() ? $qty : null;
    }

    /** Quantity × unit price in exact decimal arithmetic, rounded half up to whole pence: no floating point. */
    public static function lineAmount(BigDecimal|string|int $quantity, int $unitMinor): int
    {
        return BigDecimal::of((string) $quantity)->multipliedBy($unitMinor)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }
}
