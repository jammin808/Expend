<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Clients\ClientIds;
use App\Domain\Income\IncomeClient;
use App\Domain\Income\IncomeText;
use App\Domain\Income\PayerAliases;
use App\Domain\Invoices\BookingThread;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceClosing;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\SourceState;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\Fingerprints;
use App\Http\Presenters\Present;
use App\Jobs\CheckInvoiceEmails;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\InvoiceLine;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Invoices: the list, a draft's check screen and an issued invoice's view, and every press on them. The controller
 * holds no ledger logic: drafts change through InvoiceService (lines, seen, issue) and InvoiceDraftSync, email through
 * InvoiceEmails (Gmail drafts, Send now, I sent it myself), reminders only when you press "Make a reminder"
 * (ChaseLadder::make; Expend never writes one by itself), closing and replacing through InvoiceClosing, and sharing a
 * cost through DecisionService, all as you.
 *
 * What a press is tied to (a line's version, the lines and changes the sheet showed, a payment's fingerprint, the
 * Gmail draft's version) is checked by the domain; when it changed since, nothing is done: a sheet asking for JSON gets
 * 409 with the sheet as it is now, a page visit goes back to the page as it is now with the reason.
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(): Response
    {
        $invoices = Invoice::query()->with(['job', 'client'])->orderByDesc('id')->limit(300)->get();

        return Inertia::render('invoices/index', [
            'invoices' => self::rows($invoices),
            // Each draft's changes since you last looked, by invoice id (none: left out), for its "2 changes" chip.
            'changes' => (object) InvoiceDraftSync::changeCounts($invoices->where('status', 'draft')->pluck('id')->all()),
        ]);
    }

    /**
     * A draft's check screen (brought up to date with what's linked to its job first; nothing is written when nothing
     * changed), or an issued or void invoice's view. Gmail is never called here: an open Gmail draft is looked at in
     * the background (CheckInvoiceEmails).
     */
    public function show(Invoice $invoice, InvoiceDraftSync $sync, InvoiceEmails $emails, BookingThread $threads): Response
    {
        if ($invoice->status === 'draft') {
            $sync->sync($invoice);
            $invoice->refresh();
        }
        $invoice->load(['job.client', 'job.endClient', 'client', 'document']);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get();
        $draft = $invoice->status === 'draft';
        $jobTitles = WorkJob::query()->whereKey($lines->pluck('source_job_id')->filter()->unique()->values()->all() ?: [0])->pluck('title', 'id');
        if (! $draft && InvoiceEmails::canUse() && InvoiceEmail::query()->open()->where('invoice_id', $invoice->id)->exists() && ! (Queue::connection() instanceof SyncQueue)) {
            CheckInvoiceEmails::dispatch((int) $invoice->id);
        }

        return Inertia::render('invoices/show', [
            'invoice' => self::row($invoice) + [
                'bill_to_address' => $invoice->bill_to_address, 'bill_to_email' => $invoice->bill_to_email, 'notes' => $invoice->notes,
                'po_total_minor' => $invoice->po_total_minor, 'document' => $invoice->document ? ['id' => $invoice->document->id, 'name' => $invoice->document->original_name] : null,
                'void_reason' => $invoice->void_reason,
                'sent_at' => self::london($invoice->sent_at), 'sent_to' => $invoice->sent_to,
                'reminded_at' => self::london($invoice->reminded_at), 'reminder_count' => $invoice->reminder_count,
                'email_failed' => self::lastEmailFailed($invoice),
                // The draft's lines as this page shows them, and the last change made to them: a save or an issue from a
                // page left open while they changed is refused, and the page starts again from the lines as they are.
                'lines_version' => $draft ? InvoiceDraftSync::linesVersion($invoice) : null,
                'seen_up_to' => $draft ? InvoiceDraftSync::lastChange($invoice) : null,
                // Every line, ticked or not, with where it came from and what you changed on it.
                'lines' => self::linesProps($lines, $jobTitles, $draft),
                'end_client' => $invoice->job?->endClient?->name,
                'closed_reason' => $invoice->closed_reason, 'closed_minor' => $invoice->closed_minor, 'replaced_by_id' => $invoice->replaced_by_id,
            ],
            'draft' => $draft ? $this->draftSheet($invoice, $emails, $threads) : null,
            'issued' => $draft ? null : $this->issuedView($invoice),
            'jobs' => WorkJob::query()->orderByDesc('starts_on')->limit(300)->get(['id', 'title', 'starts_on'])->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')]),
            'clients' => Client::query()->unmerged()->orderBy('name')->get(['id', 'name']),
            // For a payment's drawer ("Link a payment", a cost to add) and a payment's whole split in words.
            'lookups' => Present::lookups(),
        ]);
    }

    /**
     * "Draft invoice" on the job's page: as "Draft its invoice?" does (JobStatus::draftInvoice), so the two buttons
     * agree: not for a job cancelled with nothing owed. A job already invoiced gets a follow-up draft for what's still
     * owed on it (InvoiceService::draftForJob reuses an open draft either way).
     */
    public function storeForJob(WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        try {
            $r = $statuses->draftInvoice($job);
            $invoice = $r['invoice']?->status === 'draft' ? $r['invoice'] : $this->invoices->draftForJob($job->load('client'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('invoices.show', $invoice);
    }

    /**
     * Save the draft: its own fields, and its lines (one by one, by id, each tied to the version you saw; new lines
     * without an id are your own typed lines). lines_version ties the save to the draft you opened; seen_up_to marks
     * the changes you were shown as seen.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
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
            'lines.*.id' => 'nullable|integer', 'lines.*.version' => 'nullable|string|max:64', 'lines.*.included' => 'nullable|boolean', 'lines.*.sort' => 'nullable|integer|min:0',
            'lines_version' => 'nullable|string|max:64', 'seen_up_to' => 'nullable|integer',
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
        foreach (['lines_version', 'seen_up_to'] as $key) {
            if (($data[$key] ?? null) === null) {
                unset($data[$key]);
            }
        }
        $data['currency'] = strtoupper($data['currency']);

        try {
            $this->invoices->updateDraft($invoice, $data, $lines);
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }

        return self::wantsJson($request) ? response()->json(['message' => 'Draft saved.']) : back()->with('success', 'Draft saved.');
    }

    /**
     * One press on one line of a draft, tied to the version of the line you saw: tick, untick, reset ("Use Expend's
     * figures again"), remove (your own line, or one gone from the job), keep (a gone line as your own) or same ("It's
     * the same" as your line other_id).
     */
    public function line(Request $request, Invoice $invoice, int $line): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['tick', 'untick', 'reset', 'remove', 'keep', 'same'])],
            'version' => 'nullable|string|max:64', 'other_id' => 'nullable|integer|required_if:action,same',
        ], ['action.required' => 'Say what to do with the line.', 'action.in' => 'Say what to do with the line.',
            'other_id.required_if' => 'Pick one of your own lines it’s the same as.']);
        try {
            $this->invoices->line($invoice, $line, $data['action'], $data['version'] ?? null, isset($data['other_id']) ? (int) $data['other_id'] : null);
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }
        $message = match ($data['action']) {
            'tick' => 'On the invoice.', 'untick' => 'Left off the invoice.', 'reset' => 'Back to Expend’s figures.',
            'remove' => 'Removed.', 'keep' => 'Kept as your own line.', 'same' => 'Noted: it’s the same as your line.',
        };

        return self::wantsJson($request) ? response()->json(['message' => $message] + self::linesState($invoice)) : back()->with('success', $message);
    }

    /** "Got it": the changes up to the last one shown are seen; one that came after stays listed. */
    public function seen(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['up_to' => 'required|integer|min:1'], ['up_to.required' => 'Open the draft again: there’s nothing to mark as seen.']);
        try {
            $this->invoices->seen($invoice, (int) $data['up_to']);
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }

        return self::wantsJson($request) ? response()->json(['message' => 'Got it.'] + self::linesState($invoice)) : back();
    }

    /**
     * Issue only: the next number, a PDF of the ticked lines, then locked. Tied to what the issue sheet showed (the
     * lines, the last change, each payment it confirms with its fingerprint, and whether you said it's a second
     * invoice); from a page with no sheet, issued only when nothing needs confirming or saying and nothing changed.
     */
    public function issue(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        $data = $this->issueRequest($request);
        $usual = $this->usualRows($invoice, (bool) ($data['save_usual'] ?? false));
        try {
            $issued = $this->invoices->issue($invoice, $data['issue_date'] ?? null, self::shown($data));
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }
        $message = "Issued {$issued->number}.".$this->afterIssue($issued, $usual)
            .(InvoiceEmails::canUse() ? ' Put it in Gmail or send it from here.' : ' Email it to the client, or download the PDF to send it yourself.');

        return self::wantsJson($request) ? response()->json(['message' => $message, 'id' => $issued->id]) : back()->with('success', $message);
    }

    /**
     * Issue and put in Gmail drafts (a draft), or for an issued invoice "Try again" / "Put it back in Gmail": Expend's
     * draft in the booking's conversation, for you to send. to '' means no address (you add it in Gmail).
     */
    public function toGmail(Request $request, Invoice $invoice, InvoiceEmails $emails): JsonResponse|RedirectResponse
    {
        $data = $this->issueRequest($request) + $request->validate(['to' => 'nullable|string|max:200', 'thread' => 'nullable|string|max:64']);
        if (! in_array($invoice->status, ['draft', 'issued'], true)) {
            return $this->refused($request, new InvalidArgumentException('Only an issued invoice can go in Gmail.'), $invoice);
        }
        $failed = false;
        try {
            if ($invoice->status === 'draft') {
                $usual = $this->usualRows($invoice, (bool) ($data['save_usual'] ?? false));
                $to = $request->has('to') ? (string) ($data['to'] ?? '') : null;
                $r = $emails->issueToGmail($invoice, $data['issue_date'] ?? null, self::shown($data), $to, $data['thread'] ?? null);
                $message = $r['message'].$this->afterIssue($r['invoice'], $usual);
            } else {
                $row = $emails->prepareOrRetry($invoice, 'invoice', array_filter(['to' => $request->has('to') ? (string) ($data['to'] ?? '') : null, 'thread' => $data['thread'] ?? null], fn ($v) => $v !== null));
                $message = match ($row?->status) {
                    'drafted' => "Invoice {$invoice->number} is in your Gmail drafts, ready to send.",
                    'pending' => 'Putting it in Gmail… Gmail didn’t answer, so Expend will try again in a minute.',
                    'sent' => "Invoice {$invoice->number} was sent already.",
                    default => "Invoice {$invoice->number} isn’t in your Gmail drafts: ".InvoiceEmails::reason((string) ($row?->error ?: 'Gmail didn’t take it')).'.',
                };
                $failed = ! in_array($row?->status, ['drafted', 'pending', 'sent'], true);
            }
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }
        if ($failed) {
            return $this->refused($request, new InvalidArgumentException($message), $invoice);
        }

        return self::wantsJson($request) ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    /**
     * Issue and send to <address>, in one press (owner decision): issued, put in the booking's conversation and sent,
     * with the To, subject and amount shown on the sheet first (the total is checked against what it showed).
     */
    public function issueAndSend(Request $request, Invoice $invoice, InvoiceEmails $emails): JsonResponse|RedirectResponse
    {
        $data = $this->issueRequest($request) + $request->validate(['to' => 'required|email:rfc,filter|max:200', 'thread' => 'nullable|string|max:64', 'total' => 'nullable|integer'], [
            'to.required' => 'Pick the address it goes to, or put it in Gmail drafts instead.',
            'to.email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.',
        ]);
        $usual = $this->usualRows($invoice, (bool) ($data['save_usual'] ?? false));
        $shown = self::shown($data);
        if (isset($data['total'])) {
            $shown = ($shown ?? []) + ['total' => (int) $data['total']];
        }
        try {
            $r = $emails->issueAndSend($invoice, $data['to'], $data['issue_date'] ?? null, $shown, $data['thread'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $e, $invoice);
        }
        $message = $r['message'].$this->afterIssue($r['invoice'], $usual);

        return self::wantsJson($request) ? response()->json(['message' => $message, 'sent' => $r['sent']]) : back()->with('success', $message);
    }

    /** "Send it now": the Gmail draft as it is in Gmail now (From, To, subject, files, first lines) and its version. */
    public function gmailPreview(Request $request, Invoice $invoice, InvoiceEmails $emails): JsonResponse
    {
        $kind = $request->query('kind') === 'reminder' ? 'reminder' : 'invoice';
        try {
            return response()->json($emails->whileYouWait()->preview($invoice, $kind));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gmail didn’t answer: try again in a minute, or send it from Gmail.'], 503);
        }
    }

    /** "Send now": exactly the version of the Gmail draft you were shown; edited in Gmail since, it's refused. */
    public function gmailSend(Request $request, Invoice $invoice, InvoiceEmails $emails): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['version' => 'required|string|max:64', 'kind' => 'nullable|in:invoice,reminder'], ['version.required' => 'Look at the draft first, then send it.']);
        $kind = $data['kind'] ?? 'invoice';
        try {
            $message = $emails->whileYouWait()->send($invoice, $data['version'], $kind);
        } catch (InvalidArgumentException $e) {
            if ($e->getCode() === InvoiceEmails::CHANGED && self::wantsJson($request)) {
                try {
                    $sheet = $emails->whileYouWait()->preview($invoice, $kind);
                } catch (Throwable) {
                    $sheet = null;
                }

                return response()->json(['message' => $e->getMessage(), 'changed' => true, 'sheet' => $sheet], 409);
            }

            return self::wantsJson($request) ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $message = 'Gmail didn’t send it: send it from Gmail, or try again in a minute.';

            return self::wantsJson($request) ? response()->json(['message' => $message], 503) : back()->with('error', $message);
        }

        return self::wantsJson($request) ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    /**
     * "I sent it myself" (or "I sent it another way"): marked as sent by hand on the day you say it went ("When did it
     * go?": today unless you pick another, never before it was issued or after today); Expend's own unsent draft is
     * taken out.
     */
    public function sentSelf(Request $request, Invoice $invoice, InvoiceEmails $emails): RedirectResponse
    {
        $data = $request->validate(['to' => 'nullable|email:rfc,filter|max:200', 'on' => 'nullable|date_format:Y-m-d'], [
            'to.email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.',
            'on.date_format' => 'Pick the day it went.',
        ]);
        if ($problem = InvoiceEmails::sentOnProblem($invoice, $data['on'] ?? null)) {
            return back()->withErrors(['on' => $problem]);
        }
        try {
            $message = $emails->markSentSelf($invoice, $data['to'] ?? null, $data['on'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * "Use another conversation": every conversation the invoice could go in (the PO's, the enquiry's, others for the
     * job from the client's addresses), searching Gmail too, then "A new email".
     */
    public function threads(Invoice $invoice, BookingThread $threads): JsonResponse
    {
        try {
            $found = $threads->candidates($invoice, true);
        } catch (Throwable $e) {
            report($e);
            $found = $threads->candidates($invoice);
        }

        return response()->json(['threads' => [...$found, ['thread_id' => 'new', 'how' => 'new', 'subject' => 'A new email', 'from' => null, 'at' => null]]]);
    }

    /** "Open in Gmail": Expend looks at the draft again in 2, 10 and 30 minutes, to see it sent. */
    public function checkGmail(Invoice $invoice): JsonResponse|RedirectResponse
    {
        CheckInvoiceEmails::later((int) $invoice->id);

        return request()->expectsJson() && ! request()->header('X-Inertia') ? response()->json(['message' => 'OK.']) : back();
    }

    /**
     * "Make a reminder": a reminder in your Gmail drafts, worded for how overdue it is, for you to send. Only when you
     * press it: Expend never writes a reminder by itself.
     */
    public function reminder(Request $request, Invoice $invoice, ChaseLadder $ladder): JsonResponse|RedirectResponse
    {
        try {
            if (in_array($invoice->status, InvoicePayments::PAYABLE, true) && $invoice->sent_at === null) {
                throw new InvalidArgumentException(ChaseLadder::NOT_SENT);
            }
            $r = $ladder->make($invoice);
        } catch (InvalidArgumentException $e) {
            return self::wantsJson($request) ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }

        return self::wantsJson($request) ? response()->json(['message' => $r['message']]) : back()->with('success', $r['message']);
    }

    /**
     * "They've paid": the overdue item waits a week, Expend's own unedited reminder draft is taken out of Gmail, and
     * the payments that may be it are listed for you to link (the likeliest first). Nothing is linked here.
     */
    public function paidWait(Request $request, Invoice $invoice, InvoiceEmails $emails): JsonResponse|RedirectResponse
    {
        if (! in_array($invoice->status, InvoicePayments::PAYABLE, true)) {
            // Voided or paid in another tab since the page loaded: the reason in words, to the page's JSON press too.
            $words = 'Only an issued invoice can be paid.';

            return self::wantsJson($request) ? response()->json(['message' => $words], 422) : back()->with('error', $words);
        }
        Dismissals::dismiss('invoice_overdue:'.$invoice->id, 7, 'They’ve paid: waiting for the payment');
        $note = '';
        if ($row = InvoiceEmails::open($invoice, 'reminder')) {
            try {
                $note = $emails->whileYouWait()->takeOut($row, 'you said they’ve paid', 'you');
                $note = $note === '' ? ' Your edited reminder is still in your Gmail drafts: delete it so it isn’t sent.' : ' '.$note;
            } catch (Throwable $e) {
                report($e);
                $note = ' Its reminder may still be in your Gmail drafts: delete it so it isn’t sent.';
            }
        }
        $payments = self::paymentsToLink($invoice);
        $message = 'OK: Expend won’t ask about '.$invoice->number.' for a week.'.$note.($payments === [] ? ' Its payment isn’t in yet: link it when it comes in.' : ' Link its payment below.');

        return self::wantsJson($request) ? response()->json(['message' => $message, 'payments' => $payments, 'selected' => $payments[0]['id'] ?? null])
            : back()->with('success', $message);
    }

    /**
     * "Costs for this job, not linked to it" ("+ Add a cost"): money out from 60 days before the job's first day to
     * 7 days after its last, bookings by travel date and mileage trips then, that aren't on this job; with a search.
     * Read only: ticking one decides it on the job as you (the decide endpoint), and one already on another job is
     * shared, not moved.
     */
    public function costs(Request $request, Invoice $invoice): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $job = $invoice->work_job_id ? WorkJob::query()->find($invoice->work_job_id) : null;
        if ($job === null) {
            return response()->json(['payments' => [], 'bookings' => [], 'trips' => [], 'message' => 'This draft has no job: costs are added on a job.']);
        }
        $from = $job->starts_on->copy()->subDays(60)->format('Y-m-d');
        $to = $job->ends_on->copy()->addDays(7)->format('Y-m-d');
        $payments = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with(['allocations.job:id,title', 'allocations.category:id,name'])
            ->where('amount_pence', '<', 0)->whereBetween('posted_on', [$from, $to])
            ->whereDoesntHave('allocations', fn ($a) => $a->where('work_job_id', $job->id))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('description', 'like', '%'.$q.'%')->orWhere('counterparty', 'like', '%'.$q.'%')))
            ->orderBy('posted_on')->limit(60)->get()
            ->reject(fn (BankTransaction $t) => $t->allocations->isNotEmpty() && ! $t->allocations->contains('kind', 'business_expense'))
            ->map(fn (BankTransaction $t) => ['source' => 'bank', 'id' => $t->id, 'date' => $t->effectiveDate()->format('Y-m-d'), 'description' => $t->counterparty ?: $t->description,
                'amount_pence' => (int) $t->amount_pence, 'fp' => SourceState::fingerprint($t),
                'on_job' => $t->allocations->firstWhere('work_job_id', '!==', null)?->job?->title, 'on_job_id' => $t->allocations->firstWhere('work_job_id', '!==', null)?->work_job_id,
                'category' => $t->allocations->first()?->category?->name, 'decided' => $t->allocations->isNotEmpty()])->values();
        $bookings = TravelItem::query()->where('status', '!=', 'cancelled')->whereBetween('starts_on', [$from, $to])
            ->where(fn ($w) => $w->whereNull('work_job_id')->orWhere('work_job_id', '!=', $job->id))->orderBy('starts_on')->limit(30)->get()
            ->map(fn (TravelItem $t) => ['id' => $t->id, 'kind' => TravelItem::KINDS[$t->kind] ?? $t->kind, 'provider' => $t->provider, 'starts_on' => $t->starts_on->format('Y-m-d'),
                'amount_minor' => $t->amount_minor, 'currency' => $t->currency, 'on_job_id' => $t->work_job_id])->values();
        $trips = MileageTrip::query()->whereBetween('trip_on', [$from, $to])->where(fn ($w) => $w->whereNull('work_job_id')->orWhere('work_job_id', '!=', $job->id))
            ->orderBy('trip_on')->limit(30)->get()
            ->map(fn (MileageTrip $t) => ['id' => $t->id, 'date' => $t->trip_on->format('Y-m-d'), 'miles' => (string) $t->miles, 'purpose' => $t->purpose, 'on_job_id' => $t->work_job_id])->values();

        return response()->json(['job' => ['id' => $job->id, 'title' => $job->title], 'payments' => $payments, 'bookings' => $bookings, 'trips' => $trips]);
    }

    /**
     * "Shared with another job…" on a cost line: the payment's cost on one job is split with another (half each by
     * default), decided as you through DecisionService, tied to the payment as you were shown it (fp). Refused in a
     * locked year.
     */
    public function share(Request $request, Invoice $invoice, DecisionService $decisions, InvoiceDraftSync $sync): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['line' => 'required|integer', 'job_id' => 'required|integer|exists:work_jobs,id', 'amount' => 'nullable|string|max:20', 'fp' => 'nullable|string|max:64'], [
            'job_id.required' => 'Pick the job it was shared with.', 'job_id.exists' => 'That job no longer exists: pick another.',
        ]);
        $line = InvoiceLine::query()->where('invoice_id', $invoice->id)->find($data['line']);
        $ref = $line?->source_ref;
        $parsed = $ref !== null && CostRef::valid($ref) ? CostRef::parse($ref) : null;
        $no = fn (string $words) => self::wantsJson($request) ? response()->json(['message' => $words], 422) : back()->with('error', $words);
        if ($parsed === null || ! in_array($parsed['type'], ['tx', 'exp'], true) || $parsed['job'] === null) {
            return $no('Only a payment’s cost on a job can be shared with another job.');
        }
        $source = $parsed['type'] === 'tx' ? BankTransaction::query()->find($parsed['id']) : ManualExpense::query()->find($parsed['id']);
        try {
            $pence = Money::parse($data['amount'] ?? null);
        } catch (InvalidArgumentException) {
            return $this->refused($request, new InvalidArgumentException('Give the other job’s share as an amount, like 42.25.'), $invoice);
        }
        if ($source === null) {
            return $no('That payment no longer exists.');
        }
        try {
            $decisions->shareCost($source, (int) $parsed['job'], (int) $data['job_id'], $pence, $data['fp'] ?? null);
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'changed since you looked') && self::wantsJson($request)) {
                // What the payment is now, so the sheet can show it and the next press is tied to it.
                return response()->json(['message' => 'This payment changed since you looked: check its split below, then press Share it again. Nothing was moved.',
                    'changed' => true, 'sheet' => ['fp' => SourceState::fingerprint($source), 'state' => SourceState::of($source),
                        'payment' => self::paymentNow($parsed['type'], (int) $parsed['id'])]], 409);
            }

            return $no($e->getMessage());
        }
        if ($invoice->status === 'draft') {
            $sync->sync($invoice);
        }
        $title = WorkJob::query()->whereKey($data['job_id'])->value('title');
        $message = 'Shared with '.$title.'. The draft follows the new split.';

        return self::wantsJson($request) ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    /** "Save these ticks as Acme's usual": the draft's ticks written as the client's arrangement, as you. */
    public function usual(Request $request, Invoice $invoice, ChargeTermsWriter $writer): RedirectResponse
    {
        $client = self::payingClient($invoice);
        if ($client === null) {
            return back()->with('error', 'Say who the invoice is for first.');
        }
        $rows = $this->invoices->ticksAsUsual($invoice);
        if ($rows === []) {
            return back()->with('error', 'There are no costs ticked or left off on it to go by yet.');
        }
        try {
            $writer->set($client, $rows, 'From the ticks on '.($invoice->number ?: 'a draft invoice'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved as '.$client->name.'’s usual: Expend ticks these on their drafts.');
    }

    /** Close it: what's still to come isn't coming (they paid less, charges, you won't chase it, paid in another currency, other). */
    public function close(Request $request, Invoice $invoice, InvoiceClosing $closing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(Invoice::CLOSED_REASONS)], 'note' => 'nullable|string|max:250'], [
            'reason.required' => 'Say why it’s being closed.', 'reason.in' => 'Say why it’s being closed.', 'note.max' => 'The note is too long (250 characters at most).',
        ]);
        try {
            $closed = $closing->close($invoice, $data['reason'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Closed {$closed->number}: ".IncomeText::money((int) $closed->closed_minor, InvoicePayments::currencyOf($closed))
            .' isn’t coming. You’re only taxed on what came in, so there’s nothing to claim for it.');
    }

    /** Reopen a closed invoice: what was written off is owed again. */
    public function reopen(Request $request, Invoice $invoice, InvoiceClosing $closing): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:250']);
        try {
            $closing->reopen($invoice, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reopened: '.($invoice->number ?: 'it').' is owed again.');
    }

    /** "It was replaced by…": a void invoice's payments count for the invoice that replaced it (none: null). */
    public function replacedBy(Request $request, Invoice $invoice, InvoiceClosing $closing): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => 'nullable|integer|exists:invoices,id', 'note' => 'nullable|string|max:250'], [
            'invoice_id.exists' => 'That invoice no longer exists: pick another.',
        ]);
        $to = isset($data['invoice_id']) ? Invoice::query()->find($data['invoice_id']) : null;
        try {
            $closing->replaceWith($invoice, $to, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $to ? 'Noted: '.$to->number.' replaced '.$invoice->number.'. What was paid against it counts there.' : 'Noted: nothing replaced '.$invoice->number.'.');
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

    /**
     * Delete a draft, every line with it (ticked or not), audited as invoice.deleted. "I invoiced this another way"
     * (another_way) also sets the job's "Invoice <job>" aside as Already sorted, which counts the job as covered.
     */
    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $anotherWay = $request->boolean('another_way');
        try {
            $job = DB::transaction(function () use ($invoice, $anotherWay) {
                AuditLog::lockHead();
                $draft = Invoice::query()->lockForUpdate()->find($invoice->id);
                if ($draft === null || $draft->status !== 'draft') {
                    throw new InvalidArgumentException('Only drafts can be deleted. Void an issued invoice instead.');
                }
                $lines = $draft->allLines()->get();
                $before = ['status' => 'draft', 'work_job_id' => $draft->work_job_id, 'client_id' => $draft->client_id, 'total_minor' => (int) $draft->total_minor,
                    'lines' => $lines->map(fn (InvoiceLine $l) => ['ref' => $l->source_ref, 'description' => $l->description, 'amount' => (int) $l->amount_minor, 'included' => (bool) $l->included])->values()->all()];
                $lines->each->delete();
                $draft->delete();
                AuditLog::record('invoice.deleted', 'Invoice', $before, null, $anotherWay ? 'You invoiced it another way' : 'You deleted the draft', subjectId: (int) $invoice->id);

                return $draft->work_job_id;
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($anotherWay && $job !== null) {
            Dismissals::dismiss('invoice:'.$job, null, 'Already sorted');

            return to_route('jobs.show', $job)->with('success', 'Draft deleted. The job counts as invoiced another way.');
        }

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
     * What the check screen needs on a draft: the changes since you last looked, what blocks issuing and what's worth
     * a look (InvoiceService::checks), the payments issuing confirms (each with its whole split and fingerprint), the
     * issue sheet's addresses and conversations (no Gmail call), and the "usual" offer.
     *
     * @return array<string, mixed>
     */
    private function draftSheet(Invoice $invoice, InvoiceEmails $emails, BookingThread $threads): array
    {
        $changes = InvoiceDraftSync::changes($invoice);
        $client = self::payingClient($invoice);
        $priorWork = $invoice->work_job_id === null ? null : Invoice::query()->where('work_job_id', $invoice->work_job_id)->where('id', '!=', $invoice->id)
            ->whereIn('status', InvoicePayments::PAYABLE)->orderBy('id')->value('number');

        return [
            'changes' => self::changes($changes, $invoice),
            // The number it would be issued as now (the issue sheet asks "Issue it as INV-0005?").
            'next_number' => InvoiceService::upcomingNumber(),
            // Due this many days after it's issued (the issue sheet shows the due date).
            'terms_days' => InvoiceService::termsDays($invoice),
            'changes_since' => $invoice->lines_seen_event_id ? self::london(AuditEvent::query()->whereKey($invoice->lines_seen_event_id)->value('occurred_at')) : null,
            'checks' => $this->invoices->checks($invoice),
            'to_confirm' => collect($this->invoices->toConfirm($invoice))->map(fn (array $c) => ['source' => $c['source'], 'fp' => $c['fp'], 'state' => $c['state'],
                'locked' => $c['locked'], 'tax_year' => $c['tax_year'], 'lines' => $c['lines']])->values(),
            'second_invoice' => $priorWork !== null ? ['number' => $priorWork] : null,
            'gmail' => InvoiceEmails::canUse(),
            'addresses' => $emails->addresses($invoice),
            'threads' => [...$threads->candidates($invoice), ['thread_id' => 'new', 'how' => 'new', 'subject' => 'A new email', 'from' => null, 'at' => null]],
            'route' => $threads->route($invoice),
            'client' => $client ? self::clientSheet($client) : null,
            'stage' => $invoice->work_job_id && ($job = WorkJob::query()->find($invoice->work_job_id))
                ? (JobStage::of([$job], null, InvoiceDraftSync::changeCounts([(int) $invoice->id]))[$job->id] ?? null) : null,
        ];
    }

    /**
     * The client on the check screen: whether they have a usual arrangement for travel and expenses, and when they
     * don't, the one-tap answers to "How does Acme pay travel?" (a fixed travel fee needs its amount: set up on their
     * page), tied to the arrangement as it is now.
     *
     * @return array{id:int, name:string, has_terms:bool, terms:?array{fp:string, presets:list<array{key:string, label:string}>}}
     */
    private static function clientSheet(Client $client): array
    {
        $has = ChargeTerms::any(ChargeTerms::forClient($client));
        $rate = ChargeTermsWriter::mileageRate();

        return ['id' => (int) $client->id, 'name' => $client->name, 'has_terms' => $has, 'terms' => $has ? null : [
            'fp' => ChargeTermsWriter::fingerprint($client),
            'presets' => collect(['at_cost', 'mileage', 'in_rate'])->map(fn (string $p) => ['key' => $p, 'label' => ChargeTermsWriter::label($p, $p === 'mileage' ? $rate : null)])->values()->all(),
        ]];
    }

    /**
     * Costs linked to the invoice's job after it was sent (ChargeStatus owed_late), shown on the job's latest issued
     * invoice: "on Acme's next invoice", with Invoice it now and Not charged as the to-do list offers them (each tied to
     * the cost as it is now). The sent invoice itself never changes.
     *
     * @return list<array{id:string, what:string, amount_minor:?int, currency:string, actions:list<array<string, mixed>>}>
     */
    private static function lateCosts(Invoice $invoice): array
    {
        if (! in_array($invoice->status, InvoicePayments::PAYABLE, true) || $invoice->work_job_id === null || $invoice->sent_at === null) {
            return [];
        }
        $latest = Invoice::query()->where('work_job_id', $invoice->work_job_id)->whereIn('status', InvoicePayments::PAYABLE)->orderByDesc('id')->value('id');
        $job = WorkJob::query()->find($invoice->work_job_id);
        if ($job === null || (int) $latest !== (int) $invoice->id) {
            return [];
        }
        $units = collect(CostUnits::forJobs([$job])[$job->id] ?? [])->filter(fn (array $u) => $u['kind'] !== 'work')->values()->all();
        $statuses = $units === [] ? [] : ChargeStatus::for($units);
        $out = [];
        foreach ($units as $u) {
            $st = $statuses[$u['ref']] ?? null;
            if ($st === null || $st['status'] !== 'owed_late' || ! $st['expected'] || ! CostRef::valid($u['ref'])) {
                continue;
            }
            $id = CostRef::toId($u['ref']);
            $out[] = [
                'id' => $id, 'what' => trim((string) ($u['description'] ?? 'A cost')),
                'amount_minor' => $u['amount_minor'] !== null ? abs((int) $u['amount_minor']) : null, 'currency' => (string) ($u['currency'] ?? 'GBP'),
                'actions' => Fingerprints::attach([['code' => 'cost_invoice_now', 'id' => $id, 'label' => 'Invoice it now'], ['code' => 'cost_no_charge', 'id' => $id, 'label' => 'Not charged']]),
            ];
        }

        return $out;
    }

    /**
     * An issued (or void) invoice's view: where its money stands (income v3's state), its payments (linked, or waiting
     * in the Inbox, and job income that paid it without naming it), its emails, whether a reminder can be made, and
     * for a void one with payments, which invoices could have replaced it.
     *
     * @return array<string, mixed>
     */
    private function issuedView(Invoice $invoice): array
    {
        $r = InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY;
        $currency = InvoicePayments::currencyOf($invoice);
        $lines = Allocation::query()->with(['transaction:id,posted_on,transaction_on,counterparty,description,amount_pence,merchant_key', 'manualExpense:id,spent_on,description,amount_pence'])
            ->where('invoice_id', $invoice->id)->where('kind', 'business_income')->orderBy('id')->get();
        $payments = $lines->map(fn (Allocation $a) => [
            'source' => $a->bank_transaction_id ? 'bank' : 'manual', 'id' => (int) ($a->bank_transaction_id ?? $a->manual_expense_id),
            'date' => ($a->transaction?->effectiveDate() ?? $a->manualExpense?->spent_on)?->format('Y-m-d'),
            'payer' => $a->transaction ? ($a->transaction->counterparty ?: $a->transaction->description) : $a->manualExpense?->description,
            'amount_pence' => (int) $a->amount_pence, 'linked' => $a->confirmed_at !== null, 'waiting' => $a->confirmed_at === null,
        ])->values();
        $email = InvoiceEmails::latest($invoice);
        $reminder = InvoiceEmails::latest($invoice, 'reminder');
        $replaceWith = [];
        if ($invoice->status === 'void' && $lines->isNotEmpty()) {
            $client = IncomeClient::ofInvoice($invoice);
            $ids = $client !== null ? ClientIds::withMerged($client) : [];
            $options = Invoice::query()->with('job:id,client_id')->whereIn('status', InvoicePayments::PAYABLE)->where('id', '!=', $invoice->id)
                ->where(fn ($q) => $client !== null
                    ? $q->whereIn('client_id', $ids)->orWhere(fn ($q) => $q->whereNull('client_id')->whereHas('job', fn ($j) => $j->whereIn('client_id', $ids)))
                    : $q->whereNull('client_id')->where('bill_to_name', $invoice->bill_to_name))
                ->orderByDesc('issue_date')->limit(30)->get();
            $owed = InvoicePayments::received($options);
            $replaceWith = $options->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'issue_date' => $i->issue_date?->format('Y-m-d'),
                'total_minor' => (int) $i->total_minor, 'outstanding_minor' => (int) ($owed[$i->id]['outstanding'] ?? 0)])->values()->all();
        }
        $overdue = $invoice->status === 'issued' ? ChaseLadder::overdue($invoice) : null;
        $blind = InvoiceEmails::cantSee();

        return [
            'state' => $r['state'], 'paid_minor' => $r['paid'], 'paid_linked_minor' => $r['paid_linked'], 'outstanding_minor' => $r['outstanding'], 'over_minor' => $r['over'],
            'closed_minor' => $r['closed'], 'waiting_pence' => $r['waiting'], 'refunded_minor' => $r['refunded'], 'currency' => $currency,
            'state_words' => self::stateWords($invoice, $r),
            'payments' => $payments,
            'paid_by_job_minor' => max(0, $r['paid'] - $r['paid_linked']),
            'gmail' => InvoiceEmails::canUse(),
            'email' => self::emailRow($email),
            'reminder' => self::emailRow($reminder),
            'can_remind' => $invoice->status === 'issued' && $invoice->sent_at !== null && $overdue !== null && $r['outstanding'] > 0,
            'overdue' => $overdue,
            'blind' => $blind ? ['since' => $blind['since'] ?? null, 'why' => $blind['why'] ?? null] : null,
            'close_reasons' => Invoice::CLOSED_REASONS,
            'replace_with' => $replaceWith,
            'replaced_by' => $invoice->replaced_by_id ? Invoice::query()->whereKey($invoice->replaced_by_id)->value('number') : null,
            // "Link a payment": recent money in from this client's payers, the likeliest first (nothing is linked here).
            'to_link' => in_array($invoice->status, InvoicePayments::PAYABLE, true) && $r['outstanding'] > 0 ? self::paymentsToLink($invoice) : [],
            // "When did it go?" (I sent it myself): not before the day it was issued.
            'sent_from' => InvoiceEmails::issuedOn($invoice),
            'late_costs' => self::lateCosts($invoice),
            'stale_date' => $invoice->status === 'issued' && $invoice->sent_at === null && $invoice->issue_date !== null && $invoice->issue_date->lte(today()->subDays(7))
                ? 'Dated '.$invoice->issue_date->format('j M').' and still not sent: send it today, or void it and issue a new one dated today if the date matters.' : null,
        ];
    }

    /** @return array<string, mixed>|null an email row as the page shows it (no Gmail ids beyond the conversation's, no tokens) */
    private static function emailRow(?InvoiceEmail $row): ?array
    {
        return $row === null ? null : ['id' => $row->id, 'kind' => $row->kind, 'stage' => $row->stage, 'route' => $row->route, 'status' => $row->status,
            'to' => $row->to_address, 'subject' => $row->subject, 'thread_id' => $row->gmail_thread_id, 'error' => $row->error,
            'edited' => $row->current_message_id !== null && $row->draft_message_id !== null && $row->current_message_id !== $row->draft_message_id,
            'drafted_at' => self::london($row->drafted_at), 'sent_at' => self::london($row->sent_at)];
    }

    /** The state pill's words: Paid, Part paid £x of £y, Closed · £30 not paid, Overpaid £100, Check: paid in another currency, Unpaid, Overdue. */
    private static function stateWords(Invoice $invoice, array $r): string
    {
        $cur = InvoicePayments::currencyOf($invoice);

        return match ($r['state']) {
            'paid' => $r['over'] > 0 ? 'Overpaid '.IncomeText::money($r['over'], $cur) : 'Paid',
            'part_paid' => 'Part paid '.IncomeText::money($r['paid'], $cur).' of '.IncomeText::money((int) $invoice->total_minor, $cur),
            'closed' => 'Closed · '.IncomeText::money($r['closed'], $cur).' not paid',
            'check_currency' => 'Check: paid in another currency',
            'draft' => 'Draft',
            'void' => 'Void',
            default => ChaseLadder::daysOverdue($invoice, today()) !== null ? 'Overdue' : 'Unpaid',
        };
    }

    /**
     * Recent unassigned money in from this invoice's client's payers (and any payment waiting with it), for "Link a
     * payment": the likeliest first (the amount still to come, then the newest).
     *
     * @return list<array<string, mixed>>
     */
    private static function paymentsToLink(Invoice $invoice): array
    {
        $client = IncomeClient::ofInvoice($invoice);
        $keys = $client !== null ? array_keys(PayerAliases::keysFor($client)) : [];
        $outstanding = (int) (InvoicePayments::received([$invoice])[$invoice->id]['outstanding'] ?? 0);
        $txs = BankTransaction::query()->with('allocations')->where('amount_pence', '>', 0)->whereIn('review_status', ['unreviewed', 'suggested'])
            ->where(fn ($q) => $q->whereIn('merchant_key', $keys ?: [''])->orWhereHas('allocations', fn ($a) => $a->where('invoice_id', $invoice->id)))
            ->when($invoice->issue_date, fn ($q) => $q->whereDate('posted_on', '>=', $invoice->issue_date->copy()->subDays(30)->format('Y-m-d')))
            ->orderByDesc('posted_on')->limit(20)->get();

        return $txs->sortBy(fn (BankTransaction $t) => [$t->allocations->contains('invoice_id', $invoice->id) ? 0 : 1, (int) $t->amount_pence === $outstanding ? 0 : 1, -$t->id])
            ->map(fn (BankTransaction $t) => ['source' => 'bank', 'id' => $t->id, 'date' => $t->effectiveDate()->format('Y-m-d'), 'payer' => $t->counterparty ?: $t->description,
                'amount_pence' => (int) $t->amount_pence, 'suggested' => $t->allocations->contains('invoice_id', $invoice->id)])->values()->all();
    }

    /** @return array<string, mixed> a line as the check screen shows it */
    /**
     * The lines as the page shows them. On a draft, a payment's cost on a job (one that can be shared with another job)
     * also carries the payment as it is now: its fingerprint, sent back with "Shared with another job…" so a share from
     * a page left open is refused when the payment changed since, and its whole split in words, shown in that step.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @return list<array<string, mixed>>
     */
    private static function linesProps(Collection $lines, Collection $jobTitles, bool $draft): array
    {
        $payments = [];
        if ($draft) {
            foreach ($lines as $l) {
                $parsed = $l->source_ref !== null && CostRef::valid($l->source_ref) ? CostRef::parse($l->source_ref) : null;
                if ($parsed !== null && in_array($parsed['type'], ['tx', 'exp'], true) && $parsed['job'] !== null) {
                    $subject = CostRef::subject($l->source_ref);
                    $payments[$subject] ??= self::paymentNow($parsed['type'], $parsed['id']);
                }
            }
        }

        return $lines->map(fn (InvoiceLine $l) => self::lineProps($l, $jobTitles)
            + ($draft ? ['payment' => $l->source_ref !== null ? ($payments[CostRef::subject($l->source_ref)] ?? null) : null] : []))->values()->all();
    }

    /**
     * A payment as it is now, for the share step: its fingerprint (SourceState) and each part of its split in words.
     *
     * @return array{fp:string, split:list<array{amount_minor:int, words:string}>}|null
     */
    private static function paymentNow(string $type, int $id): ?array
    {
        $source = $type === 'tx' ? BankTransaction::query()->find($id) : ManualExpense::query()->find($id);
        if ($source === null) {
            return null;
        }
        $split = $source->allocations()->with(['job:id,title', 'category:id,name'])->orderBy('id')->get()
            ->map(fn (Allocation $a) => ['amount_minor' => abs((int) $a->amount_pence), 'words' => match (true) {
                $a->job !== null => $a->job->title,
                $a->kind === 'business_expense' => ($a->category?->name ?? 'Business cost').', no job',
                default => Allocation::KINDS[$a->kind] ?? $a->kind,
            }])->values()->all();

        return ['fp' => SourceState::fingerprint($source), 'split' => $split];
    }

    private static function lineProps(InvoiceLine $l, Collection $jobTitles): array
    {
        return [
            'id' => $l->id, 'version' => InvoiceDraftSync::version($l), 'sort' => (int) $l->sort,
            'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) BigDecimal::ofUnscaledValue($l->unit_price_minor, 2),
            'amount_minor' => $l->amount_minor, 'source_quote' => $l->source_quote, 'included' => (bool) $l->included,
            'source' => $l->source, 'source_ref' => $l->source_ref, 'source_job_id' => $l->source_job_id,
            'source_job' => $l->source_job_id !== null ? ($jobTitles[$l->source_job_id] ?? null) : null,
            'flag' => $l->flag, 'own_text' => (bool) $l->own_text, 'own_amount' => (bool) $l->own_amount, 'ticked_by' => $l->ticked_by,
        ];
    }

    /** @return array{lines_version:string, seen_up_to:?int} the draft's lines and changes now, after a press on one */
    private static function linesState(Invoice $invoice): array
    {
        $fresh = $invoice->fresh() ?? $invoice;

        return ['lines_version' => InvoiceDraftSync::linesVersion($fresh), 'seen_up_to' => InvoiceDraftSync::lastChange($fresh)];
    }

    /**
     * The changes since you last looked, in words. A change that records no description (only a price or a quantity
     * changed) names the line by its words now, so a draft with several lines says which one.
     *
     * @param  Collection<int, AuditEvent>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private static function changes(Collection $events, Invoice $invoice): Collection
    {
        $ids = $events->map(fn (AuditEvent $e) => ((array) ($e->after ?? []))['line'] ?? ((array) ($e->before ?? []))['line'] ?? null)->filter()->unique()->values()->all();
        $names = $ids === [] ? collect() : InvoiceLine::query()->where('invoice_id', $invoice->id)->whereKey($ids)->pluck('description', 'id');

        return $events->map(fn (AuditEvent $e) => self::change($e, $names))->values();
    }

    /**
     * @param  Collection<int, string>|null  $names  each line's words now, by id
     * @return array<string, mixed> a change since you last looked, in words
     */
    private static function change(AuditEvent $e, ?Collection $names = null): array
    {
        $after = (array) ($e->after ?? []);
        $before = (array) ($e->before ?? []);
        $money = fn (?int $minor) => $minor === null ? '' : ' '.Money::format($minor);
        $named = $names?->get((int) ($after['line'] ?? $before['line'] ?? 0));
        $words = match ($e->action) {
            'invoice.line_added' => 'Added: '.($after['description'] ?? 'a line').$money(isset($after['amount']) ? (int) $after['amount'] : null),
            'invoice.line_changed' => 'Changed: '.($after['description'] ?? $before['description'] ?? $named ?? 'a line')
                .(isset($before['quantity'], $after['quantity']) ? ' ('.$before['quantity'].' → '.$after['quantity'].')' : '')
                .(isset($before['amount_minor'], $after['amount_minor']) ? ' ('.Money::format((int) $before['amount_minor']).' → '.Money::format((int) $after['amount_minor']).')' : ''),
            'invoice.line_removed' => 'Removed: '.($before['description'] ?? 'a line').' (no longer linked to the job)',
            'invoice.line_flagged' => 'Gone from the job: a line you changed. Remove it, or keep it as your own line.',
            'invoice.converted' => 'Made from the PO: its lines replace the work',
            default => $e->action,
        };

        return ['id' => $e->id, 'action' => $e->action, 'at' => self::london($e->occurred_at), 'line' => $after['line'] ?? $before['line'] ?? null, 'words' => $words];
    }

    /** The issue sheet's fields: the date, and what it showed. */
    private function issueRequest(Request $request): array
    {
        return $request->validate([
            'issue_date' => 'nullable|date', 'lines_version' => 'nullable|string|max:64', 'seen_up_to' => 'nullable|integer',
            'confirm' => 'nullable|array', 'confirm.*.source' => 'required_with:confirm|string|max:40', 'confirm.*.fp' => 'required_with:confirm|string|max:64',
            'second_invoice' => 'boolean', 'save_usual' => 'boolean',
        ], ['issue_date.date' => 'The issue date isn’t a date.']);
    }

    /** What the issue sheet showed, for InvoiceService::issue; null from a page that has no sheet (no lines_version). */
    private static function shown(array $data): ?array
    {
        if (($data['lines_version'] ?? null) === null) {
            return null;
        }

        return ['lines_version' => (string) $data['lines_version'], 'seen_up_to' => isset($data['seen_up_to']) ? (int) $data['seen_up_to'] : null,
            'confirm' => array_values(array_map(fn ($c) => ['source' => (string) $c['source'], 'fp' => (string) $c['fp']], $data['confirm'] ?? [])),
            'second_invoice' => (bool) ($data['second_invoice'] ?? false)];
    }

    /**
     * The ticks to save as the client's usual when you ticked "Save these ticks as Acme's usual" (worked out before
     * issuing, saved after it issues), or null.
     *
     * @return array{client:Client, rows:array}|null
     */
    private function usualRows(Invoice $invoice, bool $asked): ?array
    {
        $client = $asked ? self::payingClient($invoice) : null;
        if ($client === null) {
            return null;
        }
        $rows = $this->invoices->ticksAsUsual($invoice);

        return $rows === [] ? null : ['client' => $client, 'rows' => $rows];
    }

    /** What issuing went on to say: the ticks saved as the client's usual, and the waiting payments that may be for it. */
    private function afterIssue(Invoice $issued, ?array $usual): string
    {
        $out = '';
        if ($usual !== null) {
            try {
                app(ChargeTermsWriter::class)->set($usual['client'], $usual['rows'], 'From the ticks on '.$issued->number);
                $out .= ' Saved its ticks as '.$usual['client']->name.'’s usual.';
            } catch (InvalidArgumentException $e) {
                $out .= ' Its ticks weren’t saved as the usual: '.lcfirst($e->getMessage());
            }
        }
        // What the on-issue match suggested (InvoiceService::matched; issued through Gmail it ran on its own instance):
        // the payments now suggested as paying it.
        $matched = (int) ($this->invoices->matched ?? Allocation::query()->where('invoice_id', $issued->id)->whereNull('confirmed_at')
            ->whereNotNull('bank_transaction_id')->distinct()->count('bank_transaction_id'));
        if ($matched > 0) {
            $out .= ' '.$matched.' waiting '.($matched === 1 ? 'payment looks like it’s' : 'payments look like they’re').' for it.';
        }

        return $out;
    }

    /** Who pays for the invoice: its client, else its job's (a merged client as the one it went into). */
    private static function payingClient(Invoice $invoice): ?Client
    {
        $id = IncomeClient::ofInvoice($invoice);

        return $id === null ? null : Client::query()->find($id);
    }

    /**
     * A refusal: one meaning it changed since you looked (InvoiceService::CHANGED) is 409 with the draft's lines as
     * they are now to a sheet asking for JSON; any other is 422 there. A page visit goes back with the reason, and
     * shows the draft as it is now.
     */
    private function refused(Request $request, InvalidArgumentException $e, Invoice $invoice): JsonResponse|RedirectResponse
    {
        if (! self::wantsJson($request)) {
            return back()->with('error', $e->getMessage());
        }
        if ($e->getCode() !== InvoiceService::CHANGED) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $fresh = Invoice::query()->find($invoice->id);
        $sheet = null;
        if ($fresh !== null && $fresh->status === 'draft') {
            $lines = InvoiceLine::query()->where('invoice_id', $fresh->id)->orderBy('sort')->orderBy('id')->get();
            $titles = WorkJob::query()->whereKey($lines->pluck('source_job_id')->filter()->unique()->values()->all() ?: [0])->pluck('title', 'id');
            $sheet = self::linesState($fresh) + [
                'lines' => self::linesProps($lines, $titles, true),
                'changes' => self::changes(InvoiceDraftSync::changes($fresh), $fresh),
                'checks' => $this->invoices->checks($fresh),
                'to_confirm' => $this->invoices->toConfirm($fresh),
                'total_minor' => (int) $fresh->total_minor,
            ];
        }

        return response()->json(['message' => $e->getMessage(), 'changed' => true, 'sheet' => $sheet], 409);
    }

    /** A request from a sheet that reads JSON (not an Inertia visit). */
    /**
     * A moment as the page shows it ("2026-06-12 10:30"): in UK time, as the owner lives it. Stored in UTC, so a send at
     * 00:30 in June would otherwise read as the day before.
     */
    private static function london(mixed $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }

        $moment = $at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse((string) $at, (string) config('app.timezone'));

        return $moment->setTimezone('Europe/London')->format('Y-m-d H:i');
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
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

        return ['to' => $last->after['to'] ?? null, 'error' => $last->after['error'] ?? null, 'at' => self::london($last->occurred_at), 'reminder' => $last->action === 'invoice.reminder_failed'];
    }

    /**
     * Rows for many invoices, with what has come in for them worked out together (a fixed number of queries).
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<int, array>
     */
    public static function rows(Collection $invoices): Collection
    {
        $received = InvoicePayments::received($invoices);

        return $invoices->map(fn (Invoice $i) => self::row($i, $received[$i->id] ?? null))->values();
    }

    /**
     * One invoice as lists show it. status stays draft, issued, paid or void (paid once nothing is still to come and
     * it's paid); state is income v3's (paid, part_paid, closed, check_currency, unpaid, or draft and void), with what's
     * still to come and how many days it's overdue.
     *
     * @param  array<string, mixed>|null  $received  its row from InvoicePayments::received(), if already worked out
     */
    public static function row(Invoice $i, ?array $received = null): array
    {
        $r = $received ?? (InvoicePayments::received([$i])[$i->id] ?? InvoicePayments::EMPTY);
        // Overdue as everywhere else counts it (ChaseLadder::daysOverdue): once sent, from the later of the due date and
        // the day it was sent plus its terms. An invoice never sent isn't overdue: it's not sent.
        $days = $i->status === 'issued' && $r['outstanding'] > 0 ? ChaseLadder::daysOverdue($i, today()) : null;
        $overdue = $days !== null;

        return [
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status === 'issued' && $r['state'] === 'paid' && $i->total_minor > 0 ? 'paid' : $i->status,
            'state' => $r['state'], 'outstanding_minor' => (int) $r['outstanding'],
            'client' => $i->client?->name ?? $i->bill_to_name, 'client_id' => $i->client_id, 'bill_to_name' => $i->bill_to_name,
            'job' => $i->job ? ['id' => $i->job->id, 'title' => $i->job->title] : null, 'work_job_id' => $i->work_job_id,
            'po_number' => $i->po_number, 'currency' => $i->currency, 'total_minor' => $i->total_minor, 'paid_minor' => (int) $r['paid'],
            'issue_date' => $i->issue_date?->format('Y-m-d'), 'due_date' => $i->due_date?->format('Y-m-d'),
            // Whether it has gone to the client (by any route, or marked as sent by you): one not sent isn't overdue.
            'sent' => $i->sent_at !== null,
            // Overdue from the day after it's due, as on the to-do list and in the Telegram nudges: on the due date it's still on time.
            'overdue' => $overdue,
            'overdue_days' => $days,
        ];
    }
}
