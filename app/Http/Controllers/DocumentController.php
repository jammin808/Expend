<?php

namespace App\Http\Controllers;

use App\Domain\Ai\DocumentReader;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\PoStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Verification;
use App\Models\WorkJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|string', 'job' => 'nullable|integer', 'status' => 'nullable|string']);
        $docs = Document::query()->with('job')->whereNull('parent_id')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('original_name', 'like', "%{$v}%")->orWhere('email_subject', 'like', "%{$v}%")
                ->orWhere('summary', 'like', "%{$v}%")->orWhere('text', 'like', "%{$v}%")
                ->orWhereHas('children', fn ($c) => $c->where('text', 'like', "%{$v}%")->orWhere('summary', 'like', "%{$v}%"))))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('kind', $v)->orWhereHas('children', fn ($c) => $c->where('kind', $v))))
            ->when($f['job'] ?? null, fn ($q, $v) => $q->where('work_job_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->withCount('children')->orderByDesc('received_at')->limit(200)->get();

        $invoiced = PoStatus::invoicedDocuments($docs);

        return Inertia::render('documents/index', [
            'documents' => $docs->map(fn (Document $d) => self::row($d) + ['invoiced' => $invoiced[$d->id] ?? null]),
            'filters' => $f,
            'kinds' => Document::KINDS,
            'aiReady' => AiConfig::ready(),
        ]);
    }

    public function show(Document $document): Response
    {
        $document->load(['facts', 'job', 'children.facts', 'parent']);
        $invoiced = PoStatus::invoicedDocuments(collect([$document])->merge($document->children));

        return Inertia::render('documents/show', [
            'document' => array_merge(self::row($document), ['invoiced' => $invoiced[$document->id] ?? null], [
                'text' => $document->text ? mb_substr($document->text, 0, 60_000) : null,
                'text_status' => $document->text_status,
                'extraction' => $document->extraction,
                'error' => $document->error,
                'facts' => $document->facts->map(fn ($f) => $f->only(['id', 'field', 'label', 'value', 'at_time', 'quote', 'page', 'verified', 'verification_note']) + ['on_date' => $f->on_date?->format('Y-m-d')]),
                'children' => $document->children->map(fn (Document $c) => self::row($c) + ['invoiced' => $invoiced[$c->id] ?? null]),
                'parent' => $document->parent ? self::row($document->parent) : null,
                'invoices' => Invoice::query()->where('document_id', $document->id)->get(['id', 'number', 'status', 'total_minor']),
                'check' => Verification::for('document', $document->id)?->present(),
                'linked_by' => $document->job_linked_by,
                'link_hint' => ($hint = $document->extraction['job_link_hint'] ?? null) && ! $document->work_job_id && ($hintJob = WorkJob::query()->find($hint['job_id']))
                    ? $hint + ['title' => $hintJob->title] : null,
            ]),
            'jobs' => WorkJob::query()->orderByDesc('starts_on')->limit(300)->get(['id', 'title', 'starts_on'])->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')]),
            'kinds' => Document::KINDS,
            'aiReady' => AiConfig::ready(),
        ]);
    }

    public function store(Request $request, DocumentIntake $intake): RedirectResponse
    {
        $request->validate(['files' => 'required|array|max:20', 'files.*' => 'file|max:30720', 'work_job_id' => 'nullable|integer|exists:work_jobs,id']);
        $count = 0;
        foreach ($request->file('files') as $file) {
            try {
                $intake->store($file->get(), $file->getClientOriginalName(), $file->getMimeType(), 'upload', ['work_job_id' => $request->integer('work_job_id') ?: null]);
                $count++;
            } catch (Throwable $e) {
                return back()->with('error', $file->getClientOriginalName().': '.$e->getMessage());
            }
        }

        return back()->with('success', "Added {$count} ".($count === 1 ? 'document' : 'documents').'.');
    }

    /** Read (or re-read) a document with AI now. */
    public function read(Document $document, DocumentReader $reader): RedirectResponse
    {
        if (! AiConfig::ready()) {
            return back()->with('error', 'Add your Claude API key in Settings › AI first.');
        }
        if ($document->status === 'held') {
            return back()->with('error', 'Approve this document before reading it.');
        }
        try {
            $reader->read($document);
        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return back()->with('error', 'Couldn’t read it: '.$e->getMessage());
        }

        return back()->with('success', 'Read. Check anything marked unverified.');
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate(['work_job_id' => 'nullable|integer|exists:work_jobs,id', 'kind' => 'nullable|string', 'approve' => 'boolean']);
        // What it was, so the history shows the job a document was moved off (or onto), not only where it went.
        $before = ['work_job_id' => $document->work_job_id, 'job_linked_by' => $document->job_linked_by, 'kind' => $document->kind, 'status' => $document->status];
        if ($request->has('work_job_id')) {
            DocumentReader::relink($document, $data['work_job_id'], 'you');
        }
        if (! empty($data['kind']) && isset(Document::KINDS[$data['kind']])) {
            $document->update(['kind' => $data['kind']]);
        }
        if ($data['approve'] ?? false) {
            $document->update(['status' => 'new', 'error' => null]);
            $document->children()->where('status', 'held')->update(['status' => 'new', 'error' => null]);
        }
        AuditLog::record('document.updated', $document, $before, $data);

        return back()->with('success', 'Saved.');
    }

    /** Create a job from what the document says (you check the details first in the form). */
    public function createJob(Request $request, Document $document): RedirectResponse
    {
        $new = $document->extraction['new_job'] ?? null;
        $data = $request->validate([
            'title' => 'required|string|max:150', 'client' => 'nullable|string|max:120', 'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on', 'location' => 'nullable|string|max:200', 'postcode' => 'nullable|string|max:10',
        ], [
            'title.required' => 'Give the job a title.',
            'title.max' => 'The title is too long (150 characters at most).',
            'client.max' => 'The client’s name is too long (120 characters at most).',
            'location.max' => 'The venue is too long (200 characters at most).',
            'postcode.max' => 'A postcode is 10 characters at most, like SE10 0DX.',
            'starts_on.required' => 'Add the date the job starts.',
            'starts_on.date' => 'The start date isn’t a date.',
            'ends_on.required' => 'Add the date the job ends (the same date for a one-day job).',
            'ends_on.date' => 'The end date isn’t a date.',
            'ends_on.after_or_equal' => 'The job can’t end before it starts.',
        ]);
        try {
            $job = DB::transaction(function () use ($data, $document, $new) {
                $job = app(JobCreator::class)->create([
                    'title' => $data['title'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'client_name' => $data['client'] ?? null,
                    'location' => $data['location'] ?? null, 'postcode' => $data['postcode'] ?? null,
                ], 'you', [['type' => 'document', 'id' => $document->id, 'quote' => $new['quote'] ?? null, 'verified' => (bool) ($new['verified'] ?? false)]], 'From a document');
                app(JobFinder::class)->linkDocument($document, $job, 'you');

                return $job;
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('jobs.show', $job)->with('success', "Created {$job->title} and linked the document.");
    }

    public function invoice(Document $document, InvoiceService $invoices): RedirectResponse
    {
        // The job's open draft before: a PO arriving after Done converts it rather than making a second draft.
        $open = $document->work_job_id ? Invoice::query()->where('work_job_id', $document->work_job_id)->where('status', 'draft')->orderByDesc('id')->first(['id', 'document_id']) : null;
        try {
            $invoice = $invoices->draftFromDocument($document);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $made = match (true) {
            $open !== null && (int) $open->id === (int) $invoice->id && (int) $open->document_id === (int) $document->id => 'The job’s draft was made from this PO already: here it is, up to date.',
            $open !== null && (int) $open->id === (int) $invoice->id => 'The job’s draft now follows the PO: its lines replace the work, and its costs and your own lines stay. Check it, then issue it.',
            default => 'Draft invoice created from the PO. Check it, then issue it.',
        };
        // An email address the PO gives that isn't one the invoice can be sent to is left off the draft: say so.
        $read = DocumentReader::clip($document->extraction['po']['bill_to_email'] ?? null, 200);
        $note = $read !== null && $invoice->bill_to_email === null
            ? " The PO gives “{$read}” as the email for invoices. That isn’t one address an invoice can be sent to, so it was left out: add the right one before you email it."
            : '';

        return to_route('invoices.show', $invoice)->with('success', $made.$note);
    }

    public function download(Document $document): StreamedResponse
    {
        return Storage::disk('local')->download($document->stored_path, $document->original_name);
    }

    public function destroy(Document $document): RedirectResponse
    {
        AuditLog::record('document.deleted', $document, $document->only(['original_name', 'sha256', 'source']));
        foreach ([$document, ...$document->children] as $d) {
            Storage::disk('local')->delete($d->stored_path);
        }
        $document->children()->delete();
        $document->delete();

        return to_route('documents.index')->with('success', 'Document removed.');
    }

    public static function row(Document $d): array
    {
        return [
            'id' => $d->id, 'name' => $d->original_name, 'kind' => $d->kind, 'status' => $d->status, 'source' => $d->source,
            'summary' => $d->summary, 'subject' => $d->email_subject, 'from' => $d->email_from, 'mime' => $d->mime, 'size' => $d->size,
            'received' => $d->received_at?->format('Y-m-d H:i'), 'job' => $d->job ? ['id' => $d->job->id, 'title' => $d->job->title] : null,
            'children' => $d->children_count ?? null, 'unverified' => $d->extraction['unverified_count'] ?? null, 'held_reason' => $d->status === 'held' ? $d->error : null,
        ];
    }
}
