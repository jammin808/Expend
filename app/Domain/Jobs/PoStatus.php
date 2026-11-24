<?php

namespace App\Domain\Jobs;

use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Support\Collection;

/**
 * Has a job got a purchase order, and has it been invoiced?
 *
 * A job has a PO when you've typed its number on the job, a document linked to it is a PO (or the AI read a
 * PO number from one), or an invoice for it quotes a PO number. Jobs marked "no PO needed" and cancelled jobs
 * are never flagged. A job counts as invoiced once an invoice for it is issued or paid (drafts and voided
 * invoices don't count).
 */
final class PoStatus
{
    /**
     * @param  iterable<WorkJob>  $jobs
     * @return array<int, array{po: ?array{number: ?string, document_id: ?int, source: string}, missing: bool, invoice: ?array{id: int, number: ?string, status: string}}>
     */
    public static function forJobs(iterable $jobs): array
    {
        $jobs = collect($jobs)->keyBy('id');
        $ids = $jobs->keys()->all();
        if (! $ids) {
            return [];
        }

        // Grouped by job once, so each job looks only at its own (not every document of every job).
        $invoices = Invoice::query()->whereIn('work_job_id', $ids)->where('status', '!=', 'void')->orderByDesc('issued_at')->orderByDesc('id')->get()->groupBy('work_job_id');
        $documents = Document::query()->whereIn('work_job_id', $ids)->get(['id', 'parent_id', 'work_job_id', 'kind', 'extraction', 'received_at'])
            ->filter(fn (Document $d) => self::isPo($d))->sortBy('received_at')->groupBy('work_job_id');
        $facts = DocumentFact::query()->whereIn('work_job_id', $ids)->where('field', 'po_number')->orderBy('id')->get(['work_job_id', 'value', 'document_id'])->groupBy('work_job_id');
        $none = collect();

        $out = [];
        foreach ($jobs as $id => $job) {
            $doc = ($documents[$id] ?? $none)->first();
            $fact = ($facts[$id] ?? $none)->first();
            $jobInvoices = $invoices[$id] ?? $none;
            $quoted = $jobInvoices->first(fn ($i) => filled($i->po_number));
            $po = match (true) {
                filled($job->po_number) => ['number' => $job->po_number, 'document_id' => $doc?->parent_id ?? $doc?->id, 'source' => 'job'],
                $doc !== null => ['number' => $doc->extraction['po']['po_number'] ?? $fact?->value, 'document_id' => $doc->parent_id ?? $doc->id, 'source' => 'document'],
                $fact !== null => ['number' => $fact->value, 'document_id' => $fact->document_id, 'source' => 'document'],
                $quoted !== null => ['number' => $quoted->po_number, 'document_id' => null, 'source' => 'invoice'],
                default => null,
            };
            $invoice = $jobInvoices->first(fn ($i) => in_array($i->status, ['issued', 'paid'], true));

            $out[$id] = [
                'po' => $po,
                'missing' => $po === null && ! $job->po_not_required && $job->status !== 'cancelled',
                'invoice' => $invoice ? ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status] : null,
            ];
        }

        return $out;
    }

    /**
     * The green tick for documents: for each document that is (or carries) a PO, the issued or paid invoice of
     * its job, or the invoice drafted from it.
     *
     * @param  Collection<int, Document>  $documents  top-level documents or attachments
     * @return array<int, array{id: int, number: ?string, status: string}>
     */
    public static function invoicedDocuments(Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }
        $children = Document::query()->whereIn('parent_id', $documents->pluck('id'))->get(['id', 'parent_id', 'work_job_id', 'kind', 'extraction']);
        $family = fn (Document $d) => collect([$d])->merge($children->where('parent_id', $d->id));

        $jobIds = $documents->pluck('work_job_id')->merge($children->pluck('work_job_id'))->filter()->unique();
        $docIds = $documents->pluck('id')->merge($children->pluck('id'));
        $invoices = Invoice::query()->whereIn('status', ['issued', 'paid'])
            ->where(fn ($q) => $q->whereIn('work_job_id', $jobIds)->orWhereIn('document_id', $docIds))
            ->orderByDesc('issued_at')->orderByDesc('id')->get(['id', 'number', 'status', 'work_job_id', 'document_id']);

        $out = [];
        foreach ($documents as $document) {
            $members = $family($document);
            $invoice = $invoices->first(fn ($i) => $members->pluck('id')->contains($i->document_id));
            if (! $invoice && $members->contains(fn ($d) => self::isPo($d))) {
                $jobs = $members->filter(fn ($d) => self::isPo($d) || $d->id === $document->id)->pluck('work_job_id')->filter();
                $invoice = $invoices->first(fn ($i) => $jobs->contains($i->work_job_id));
            }
            if ($invoice) {
                $out[$document->id] = ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status];
            }
        }

        return $out;
    }

    public static function isPo(Document $document): bool
    {
        return $document->kind === 'po' || filled($document->extraction['po']['po_number'] ?? null);
    }
}
