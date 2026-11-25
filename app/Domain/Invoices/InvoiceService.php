<?php

namespace App\Domain\Invoices;

use App\Domain\Ai\DocumentReader;
use App\Domain\Audit\AuditLog;
use App\Domain\Charges\CostClass;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Clients\ClientIds;
use App\Domain\Gmail\GmailLabeller;
use App\Domain\Income\InvoiceMatcher;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobCreator;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\SourceState;
use App\Domain\Mileage\JobTrips;
use App\Jobs\SyncGmailLabels;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Drafts invoices (for a job, for a client's costs, or from a PO), keeps a draft's lines, and issues them: sequential
 * number, PDF of the ticked lines, then locked. Corrections are made by voiding and issuing a new invoice, never by
 * editing an issued one.
 *
 * A draft's lines follow what's linked to its job (InvoiceDraftSync), so a job's draft is made once and reused: Done,
 * "Draft the invoice" and a PO that arrives later all come back to the same draft. A job already invoiced gets a
 * follow-up draft holding only what's still owed on it (costs linked after the invoice went, a day added since).
 *
 * Issuing is tied to what you saw: it syncs first, and is refused when the sync changed anything, or the lines or the
 * changes you were shown aren't the ones there now (lines_version, seen_up_to; with no issue sheet, any change since
 * you last looked), every check made on the draft under its lock. It's refused while a ticked line needs
 * you (gone, no price, a check), a ticked cost is ticked on another draft or charged on another invoice, your name or
 * address is missing (an invoice must carry them), or the job already has an issued invoice for its work (unless you
 * say it's a second invoice). A ticked cost whose payment isn't confirmed is confirmed as you when you issue it, but
 * only for a payment whose whole split you were shown (SourceState's fingerprint); one in a locked year must be
 * unticked. After the commit, money already waiting that may be for it is suggested (InvoiceMatcher::forInvoice).
 */
final class InvoiceService
{
    /**
     * How many times a transaction is tried when the database ends it to break a deadlock (another writer took the same
     * rows the other way round): it reads everything again under its locks, so trying again is safe.
     */
    private const ATTEMPTS = 3;

    /** The code of a refusal that means "it changed since you looked": the issue sheet is shown again with the changes. */
    public const CHANGED = 409;

    /** Waiting payments the on-issue match now suggests for the invoice issued last (null: it didn't run). */
    public ?int $matched = null;

    public function __construct(private InvoiceDraftSync $sync) {}

    /**
     * A draft from a PO. When the PO's job already has an open draft, that draft is converted instead of a second one
     * being made: the PO's lines replace its work lines (fee, days, hours, extra days, travel fee and travel days), its
     * costs, mileage and your own lines stay, and the PO number, total and any empty bill-to details are filled in.
     */
    public function draftFromDocument(Document $document): Invoice
    {
        $po = $document->extraction['po'] ?? null;
        if (! $po || empty($po['lines'])) {
            throw new InvalidArgumentException('No purchase order lines were read from this document.');
        }

        return DB::transaction(function () use ($document, $po) {
            AuditLog::lockHead();
            $job = $document->work_job_id ? WorkJob::query()->with('client')->lockForUpdate()->find($document->work_job_id) : null;
            $open = $job ? Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderByDesc('id')->lockForUpdate()->first() : null;
            if ($open !== null && (int) $open->document_id === (int) $document->id) {
                // Made from this PO already: brought up to date, never converted again (your changes to its lines stay).
                $this->sync->sync($open);

                return $open->fresh('lines');
            }
            if ($open !== null) {
                return $this->convert($open, $document, $po);
            }
            $client = $job?->client_id !== null ? Client::query()->find(ClientIds::resolve((int) $job->client_id))
                : (trim((string) ($po['bill_to_name'] ?? '')) !== '' ? JobCreator::client((string) $po['bill_to_name'], 'you', 'From a PO you drafted an invoice from') : null);
            $terms = $po['payment_terms_days'] ?? $client?->payment_terms_days ?? (int) Setting::get('invoice_terms_days');

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
            self::poLines($invoice, $document, $po['lines'], 0);
            $this->sync->sync($invoice);
            $this->recalculate($invoice);
            AuditLog::record('invoice.drafted', $invoice, null, ['from_document' => $document->id, 'lines' => count($po['lines'])]);

            return $invoice->fresh('lines');
        });
    }

    /**
     * The job's draft: its open draft (brought up to date), or a new one with the client's details and terms and the
     * job's PO number, filled by the sync. With exactly one PO read for the job and not invoiced yet, it's made from
     * that PO. A job already invoiced gets a follow-up draft: only what's still owed on it.
     */
    public function draftForJob(WorkJob $job): Invoice
    {
        return DB::transaction(function () use ($job) {
            AuditLog::lockHead();
            $job = WorkJob::query()->with('client')->lockForUpdate()->find($job->id) ?? throw new InvalidArgumentException('That job no longer exists.');
            $open = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderByDesc('id')->first();
            if ($open !== null) {
                $this->sync->sync($open);

                return $open->fresh('lines');
            }
            if ($po = self::onlyPo($job)) {
                return $this->draftFromDocument($po);
            }
            $followUp = Invoice::query()->where('work_job_id', $job->id)->whereIn('status', InvoicePayments::PAYABLE)->exists();
            $client = $job->client_id !== null ? Client::query()->find(ClientIds::resolve((int) $job->client_id)) : null;
            $invoice = Invoice::query()->create([
                'status' => 'draft', 'client_id' => $client?->id, 'work_job_id' => $job->id,
                'bill_to_name' => $client ? ($client->invoice_name ?: $client->name) : null,
                'bill_to_email' => self::emailAddress($client?->billing_email), 'bill_to_address' => $client?->billing_address ?: null,
                'po_number' => trim((string) $job->po_number) !== '' ? mb_substr((string) $job->po_number, 0, 100) : null,
                'notes' => 'Payment due within '.self::terms($client).' days.',
            ]);
            $this->sync->sync($invoice);
            $this->recalculate($invoice);
            AuditLog::record('invoice.drafted', $invoice, null, ['from_job' => $job->id] + ($followUp ? ['follow_up' => true] : []));

            return $invoice->fresh('lines');
        }, self::ATTEMPTS);
    }

    /**
     * The job's paying client changed: its open drafts bill the new client. Each draft's client changes, and its name,
     * email, address and terms note follow the new client where they still hold what the old client's details would
     * give them (or are empty): anything you typed on the draft yourself stays. The sync then brings its lines in line
     * with the new client's arrangement. Audited per draft (invoice.client_changed).
     *
     * @return list<Invoice> the drafts that now bill the new client
     */
    public function rebillDrafts(WorkJob $job, ?int $fromClientId): array
    {
        return DB::transaction(function () use ($job, $fromClientId) {
            AuditLog::lockHead();
            $drafts = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderBy('id')->lockForUpdate()->get();
            $job = WorkJob::query()->find($job->id);
            if ($drafts->isEmpty() || $job === null) {
                return [];
            }
            $new = $job->client_id !== null ? Client::query()->find(ClientIds::resolve((int) $job->client_id)) : null;
            $old = $fromClientId !== null ? Client::query()->find(ClientIds::resolve($fromClientId)) : null;
            $details = fn (?Client $c) => [
                'bill_to_name' => $c ? ($c->invoice_name ?: $c->name) : null, 'bill_to_email' => self::emailAddress($c?->billing_email),
                'bill_to_address' => $c?->billing_address ?: null, 'notes' => 'Payment due within '.self::terms($c).' days.',
            ];
            [$was, $now] = [$details($old), $details($new)];
            $moved = [];
            foreach ($drafts as $draft) {
                $current = $draft->client_id !== null ? ClientIds::resolve((int) $draft->client_id) : null;
                if ($current === $new?->id) {
                    continue;
                }
                $changes = ['client_id' => $new?->id];
                foreach ($now as $field => $value) {
                    $have = $draft->{$field};
                    if ($have === null || trim((string) $have) === '' || $have === $was[$field]) {
                        $changes[$field] = $value;
                    }
                }
                $before = $draft->only(array_keys($changes));
                $draft->update($changes);
                AuditLog::record('invoice.client_changed', $draft, $before, $draft->only(array_keys($changes)),
                    'The job’s client is now '.($new?->name ?? 'no one'));
                $this->sync->sync($draft);
                $this->recalculate($draft);
                $moved[] = $draft->fresh();
            }

            return $moved;
        }, self::ATTEMPTS);
    }

    /**
     * A draft for a client with no job: their costs from earlier jobs not on an invoice yet, and their own costs you
     * marked to recharge. Their open draft with no job is reused.
     */
    public function draftForClient(Client $client): Invoice
    {
        return DB::transaction(function () use ($client) {
            AuditLog::lockHead();
            $id = ClientIds::resolve((int) $client->id);
            $client = Client::query()->lockForUpdate()->find($id) ?? throw new InvalidArgumentException('That client no longer exists: pick another.');
            $open = Invoice::query()->where('status', 'draft')->whereNull('work_job_id')->whereIn('client_id', ClientIds::withMerged($id))->orderBy('id')->first();
            if ($open !== null) {
                $this->sync->sync($open);

                return $open->fresh('lines');
            }
            $invoice = Invoice::query()->create([
                'status' => 'draft', 'client_id' => $client->id, 'bill_to_name' => $client->invoice_name ?: $client->name,
                'bill_to_email' => self::emailAddress($client->billing_email), 'bill_to_address' => $client->billing_address ?: null,
                'notes' => 'Payment due within '.self::terms($client).' days.',
            ]);
            $this->sync->sync($invoice);
            $this->recalculate($invoice);
            AuditLog::record('invoice.drafted', $invoice, null, ['from_client' => $client->id]);

            return $invoice->fresh('lines');
        }, self::ATTEMPTS);
    }

    /**
     * Save a draft. Lines are saved one by one, in place by id (with the version you saw: a line changed since isn't
     * saved, and the save is refused saying which), and new lines without an id are your own typed lines. Changing a
     * line's wording keeps your wording from then on (own_text), its quantity or price your amount (own_amount), its
     * tick your tick (ticked_by 'you'). A line from the job can't be removed (untick it instead) unless it has gone
     * from the job; your own lines can. lines_version (every line as you saw it) ties a whole-form save to the draft
     * you opened, and seen_up_to marks the changes you were shown as seen.
     *
     * A form with no line ids (the page from before line sources) is read the old way: its lines are the ticked lines
     * as shown. A line kept as it was stays; one changed or added is your own typed line; a typed line left out is
     * deleted, and a line from the job left out is unticked (by you), never deleted. With lines_version (the lines as
     * the page showed them) it's refused when they changed since, so a page left open never unticks a line the sync
     * added after it loaded.
     *
     * @param  array<string, mixed>  $data  the draft's own fields, plus lines_version and seen_up_to
     * @param  list<array{id?:int|string|null, version?:?string, description:string, quantity:float|string, unit_price:string, included?:mixed, sort?:mixed, source_quote?:?string}>  $lines
     */
    public function updateDraft(Invoice $invoice, array $data, array $lines): Invoice
    {
        $this->assertDraft($invoice);
        $perLine = collect($lines)->contains(fn ($l) => is_array($l) && array_key_exists('id', $l) && $l['id'] !== null && $l['id'] !== '');
        $linesVersion = $data['lines_version'] ?? null;
        $seenUpTo = isset($data['seen_up_to']) && is_numeric($data['seen_up_to']) ? (int) $data['seen_up_to'] : null;
        unset($data['lines_version'], $data['seen_up_to']);
        $parsed = [];
        foreach (array_values($lines) as $i => $line) {
            $unit = Money::parse((string) ($line['unit_price'] ?? ''));
            $qty = self::quantity($line['quantity'] ?? null);
            if ($unit === null || $qty === null || trim((string) ($line['description'] ?? '')) === '') {
                throw new InvalidArgumentException('Line '.($i + 1).' needs a description, a quantity (up to 2 decimals) and a price.');
            }
            $parsed[] = [
                'n' => $i + 1, 'id' => isset($line['id']) && $line['id'] !== '' && $line['id'] !== null ? (int) $line['id'] : null, 'version' => $line['version'] ?? null,
                'description' => (string) $line['description'], 'quantity' => $qty, 'unit' => $unit,
                'included' => array_key_exists('included', $line) && $line['included'] !== null ? filter_var($line['included'], FILTER_VALIDATE_BOOL) : null,
                'sort' => isset($line['sort']) && is_numeric($line['sort']) ? max(0, (int) $line['sort']) : $i, 'source_quote' => $line['source_quote'] ?? null,
            ];
        }

        return DB::transaction(function () use ($invoice, $data, $parsed, $perLine, $linesVersion, $seenUpTo) {
            $this->lockDraft($invoice);
            if ($linesVersion !== null && $linesVersion !== InvoiceDraftSync::linesVersion($invoice)) {
                throw new InvalidArgumentException('The draft’s lines changed since you opened it: check them and save again.', self::CHANGED);
            }
            $invoice->update($data);
            $existing = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            [$before, $after] = $perLine ? $this->saveLines($invoice, $existing, $parsed) : $this->saveForm($invoice, $existing, $parsed, $linesVersion === null);
            $this->recalculate($invoice);
            if ($perLine && $seenUpTo !== null) {
                $this->markSeen($invoice, $seenUpTo);
            }
            AuditLog::record('invoice.updated', $invoice, null, ['total' => $invoice->fresh()->total_minor]);
            if ($before !== [] || $after !== []) {
                AuditLog::record('invoice.lines_edited', $invoice, ['lines' => $before], ['lines' => $after]);
            }

            return $invoice->fresh('lines');
        });
    }

    /**
     * One action on one line of a draft, tied to the version of the line you saw:
     *  - tick / untick: on the invoice or not (your tick from then on);
     *  - reset: "Use Expend's figures again" (your wording, amount and tick forgotten, and the line follows its cost);
     *  - remove: your own line, or one whose cost has gone ("Untick it instead" for any other);
     *  - keep: a line whose cost has gone kept as your own line;
     *  - same: "It's the same": the cost moves onto your own line $otherId, keeping your wording and price.
     */
    public function line(Invoice $invoice, int $lineId, string $action, ?string $version = null, ?int $otherId = null): Invoice
    {
        $this->assertDraft($invoice);

        $result = DB::transaction(function () use ($invoice, $lineId, $action, $version, $otherId) {
            $this->lockDraft($invoice);
            $line = InvoiceLine::query()->where('invoice_id', $invoice->id)->lockForUpdate()->find($lineId)
                ?? throw new InvalidArgumentException('That line is no longer on the draft: open it again.', self::CHANGED);
            if ($version !== null && $version !== InvoiceDraftSync::version($line)) {
                throw new InvalidArgumentException('“'.$line->description.'” changed since you looked: check it and try again.', self::CHANGED);
            }
            $before = [self::lineState($line)];
            switch ($action) {
                case 'tick':
                case 'untick':
                    $line->forceFill(['included' => $action === 'tick', 'ticked_by' => 'you'])->save();
                    break;
                case 'reset':
                    if ($line->source === null) {
                        throw new InvalidArgumentException('That’s your own line: there are no figures of Expend’s to go back to.');
                    }
                    $line->forceFill(['own_text' => false, 'own_amount' => false, 'ticked_by' => null])->save();
                    break;
                case 'remove':
                    if ($line->source !== null && $line->flag !== 'gone') {
                        throw new InvalidArgumentException('“'.$line->description.'” comes from the job: untick it instead.');
                    }
                    $line->delete();
                    break;
                case 'keep':
                    $line->forceFill(['source' => null, 'source_ref' => null, 'source_job_id' => null, 'flag' => null, 'own_text' => false, 'own_amount' => false,
                        'ticked_by' => null, 'source_amount_minor' => null, 'source_quantity' => null])->save();
                    break;
                case 'same':
                    $other = InvoiceLine::query()->where('invoice_id', $invoice->id)->lockForUpdate()->find((int) $otherId);
                    if ($line->source === null || $other === null || $other->source !== null) {
                        throw new InvalidArgumentException('Pick one of your own lines it’s the same as.');
                    }
                    $before[] = self::lineState($other);
                    $moved = $line->only(['source', 'source_ref', 'source_job_id', 'source_amount_minor', 'source_quantity']);
                    $line->delete();
                    $other->forceFill($moved + ['own_text' => true, 'own_amount' => true, 'flag' => null])->save();
                    $line = $other;
                    break;
                default:
                    throw new InvalidArgumentException('Unknown action.');
            }
            $this->recalculate($invoice);
            $after = $line->exists ? [self::lineState($line->fresh())] : [];
            AuditLog::record('invoice.lines_edited', $invoice, ['lines' => $before], ['lines' => $after], match ($action) {
                'reset' => 'Use Expend’s figures again', 'keep' => 'Kept as your own line', 'same' => 'It’s the same as your line', default => null,
            });

            return $invoice->fresh('lines');
        });
        if ($action === 'reset') {
            $this->sync->sync($invoice);
        }

        return $action === 'reset' ? $invoice->fresh('lines') : $result;
    }

    /** "Got it": the changes up to $upTo (the last one shown) are seen; a change that came after stays listed. */
    public function seen(Invoice $invoice, int $upTo): void
    {
        $this->assertDraft($invoice);
        DB::transaction(function () use ($invoice, $upTo) {
            $this->lockDraft($invoice);
            $this->markSeen($invoice, $upTo);
        });
    }

    /**
     * Issue a draft: the next number, a PDF of the ticked lines, then locked.
     *
     * @param  array{lines_version?:?string, seen_up_to?:?int, confirm?:list<array{source:string, fp:string}>, second_invoice?:bool}|null  $shown
     *                                                                                                                                             what the issue sheet showed you: the lines, the last change, each payment to confirm with its fingerprint, and whether you said it's a second
     *                                                                                                                                             invoice for the job. Null from a page that has no issue sheet: then it's issued only when nothing needs
     *                                                                                                                                             confirming or saying, and nothing changed since you last looked.
     *
     * @throws InvalidArgumentException with code CHANGED when it changed since you looked
     */
    public function issue(Invoice $invoice, ?string $issueDate = null, ?array $shown = null): Invoice
    {
        $this->matched = null;
        $this->assertDraft($invoice);
        $this->assertStoredDraft($invoice);
        $sync = $this->sync->sync($invoice);
        if ($sync['changed'] > 0) {
            throw new InvalidArgumentException(self::things($sync['changed']).' changed since you looked: check '.($sync['changed'] === 1 ? 'it' : 'them').' and issue again.', self::CHANGED);
        }

        return DB::transaction(function () use ($invoice, $issueDate, $shown) {
            // Every check is made on the draft as it is under its lock: a save, a tick or a sync that landed after you
            // looked is refused, never issued unseen.
            $this->lockDraft($invoice);
            $invoice = $invoice->fresh();
            $confirm = $this->assertReady($invoice, $shown);
            foreach ($confirm as ['source' => $source, 'fp' => $fp]) {
                if (! SourceState::matches($source, $fp)) {
                    throw new InvalidArgumentException('A payment you were shown changed since: check its split and issue again.', self::CHANGED);
                }
                app(AllocationWriter::class)->confirm($source, 'Confirmed when you issued the invoice');
            }
            $issued = CarbonImmutable::parse($issueDate ?? 'today');
            $terms = (int) Setting::get('invoice_terms_days') ?: 30;
            if (preg_match('/within (\d+) days/', (string) $invoice->notes, $m)) {
                $terms = (int) $m[1];
            }
            $invoice->refresh();
            $last = InvoiceDraftSync::lastChange($invoice);

            $invoice->forceFill([
                'number' => $this->nextNumber(),
                'issue_date' => $issued->format('Y-m-d'),
                'due_date' => $issued->addDays($terms)->format('Y-m-d'),
                // What changed is seen once it's issued; set while it's still a draft (an issued invoice can't take it).
                'lines_seen_event_id' => $last !== null ? max($last, (int) $invoice->lines_seen_event_id) : $invoice->lines_seen_event_id,
            ])->save();

            $pdf = $this->renderPdf($invoice->fresh(['lines', 'client']));
            $path = "invoices/{$invoice->number}.pdf";
            Storage::disk('local')->put($path, $pdf);

            $invoice->forceFill(['status' => 'issued', 'issued_at' => now(), 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf)])->save();
            if ($invoice->work_job_id && GmailLabeller::enabled()) {
                SyncGmailLabels::dispatch($invoice->work_job_id)->afterCommit();
            }
            AuditLog::record('invoice.issued', $invoice, null, ['number' => $invoice->number, 'total' => $invoice->total_minor, 'pdf_sha256' => $invoice->pdf_sha256]);
            // Money already waiting that may be for it is suggested, once it's issued. Never fails the issue.
            DB::afterCommit(function () use ($invoice) {
                try {
                    $this->matched = app(InvoiceMatcher::class)->forInvoice($invoice);
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return $invoice->fresh('lines');
        }, self::ATTEMPTS);
    }

    /**
     * Whether the draft, as it is now (under its lock), can be issued as you were shown it: refused when it has no
     * line, no bill-to or not your details; when the lines or the changes you were shown aren't the ones there now
     * (with no issue sheet, when any change since you last looked is there); when a ticked line needs you, a ticked
     * cost is charged elsewhere, or it's a second invoice you didn't say. The payments to confirm, each tied to what
     * you were shown.
     *
     * @return list<array{source:BankTransaction|ManualExpense, fp:string}>
     */
    private function assertReady(Invoice $invoice, ?array $shown): array
    {
        if ($invoice->lines()->count() === 0 || $invoice->total_minor <= 0) {
            throw new InvalidArgumentException('Add at least one line before issuing.');
        }
        if (! $invoice->bill_to_name) {
            throw new InvalidArgumentException('Add who the invoice is for before issuing.');
        }
        if (trim((string) Setting::get('business_name')) === '' || trim((string) Setting::get('business_address')) === '') {
            throw new InvalidArgumentException('Your name and address must be on the invoice: add them in Settings first.');
        }
        if ($shown !== null && ($shown['lines_version'] ?? null) !== InvoiceDraftSync::linesVersion($invoice)) {
            throw new InvalidArgumentException('The lines changed since you opened the issue sheet: check them and issue again.', self::CHANGED);
        }
        // What the sync changed since you last looked: after the last change the sheet showed, or (with no sheet) any.
        $unseen = InvoiceDraftSync::changes($invoice)->filter(fn (AuditEvent $e) => (int) $e->id > (int) ($shown['seen_up_to'] ?? 0))->count();
        if ($unseen > 0) {
            throw new InvalidArgumentException(self::things($unseen).' changed since you looked: check '.($unseen === 1 ? 'it' : 'them').' and issue again.', self::CHANGED);
        }
        $ticked = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('included', true)->orderBy('sort')->orderBy('id')->get();
        self::assertLinesReady($ticked, $invoice);
        $wanted = $this->sync->wanted($invoice);
        $this->assertExclusive($invoice, $ticked, $wanted);
        $this->assertSecondInvoice($invoice, $ticked, $shown);

        return $this->confirmations($invoice, $ticked, $wanted, $shown);
    }

    /**
     * The payments a draft's ticked costs aren't confirmed for: issuing confirms each, as you, when you were shown its
     * whole split (state: SourceState::of, with its fingerprint). One in a locked year can't be: untick its line.
     *
     * @return list<array{source:string, fp:string, state:?array, locked:bool, tax_year:?string, lines:list<int>}>
     */
    public function toConfirm(Invoice $invoice): array
    {
        $ticked = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('included', true)->get();

        return array_values(self::unconfirmed($ticked, $this->sync->wanted($invoice)));
    }

    /**
     * "Save these ticks as Acme's usual?", offered at issue when the client has no arrangement: the rows the draft's
     * ticks say, kind of cost by kind (ticked: at cost, or driving per mile at its line's rate; only unticked: not
     * charged), ready for ChargeTermsWriter::set as you.
     *
     * @return array<string, string|array{charge:string, rate_minor:int}>
     */
    public function ticksAsUsual(Invoice $invoice): array
    {
        $wanted = $this->sync->wanted($invoice);
        $rows = [];
        foreach (InvoiceLine::query()->where('invoice_id', $invoice->id)->whereIn('source', ['cost', 'mileage'])->orderBy('sort')->get() as $line) {
            $class = $wanted[$line->source_ref]['unit']['class'] ?? null;
            if ($class === null || ! isset(CostClass::CLASSES[$class])) {
                continue;
            }
            if ($line->included) {
                $rows[$class] = $class === 'mileage' ? ['charge' => 'per_mile', 'rate_minor' => (int) $line->unit_price_minor] : 'at_cost';
            } else {
                $rows[$class] ??= 'none';
            }
        }

        return array_filter($rows, fn ($row, $class) => CostClass::allows($class, is_array($row) ? $row['charge'] : $row)
            && (! is_array($row) || $row['rate_minor'] > 0), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * What needs you on a draft before it's issued (blocks) and what's worth a look (warnings), for the check screen.
     *
     * @return array{blocks:list<array{code:string, message:string, line:?int}>, warnings:list<array{code:string, message:string}>}
     */
    public function checks(Invoice $invoice): array
    {
        $blocks = [];
        $warnings = [];
        $ticked = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('included', true)->orderBy('sort')->orderBy('id')->get();
        foreach ($ticked as $l) {
            if ($message = self::lineProblem($l, $invoice)) {
                $blocks[] = ['code' => 'line', 'message' => $message, 'line' => (int) $l->id];
            }
        }
        if (trim((string) Setting::get('business_name')) === '' || trim((string) Setting::get('business_address')) === '') {
            $blocks[] = ['code' => 'your_details', 'message' => 'Your name and address must be on the invoice.', 'line' => null];
        }
        foreach ($this->toConfirm($invoice) as $c) {
            $blocks[] = $c['locked']
                ? ['code' => 'locked', 'message' => 'A cost’s payment isn’t confirmed and '.$c['tax_year'].' is locked: untick it, or unlock the year first.', 'line' => $c['lines'][0] ?? null]
                : ['code' => 'unconfirmed', 'message' => 'A cost isn’t confirmed yet. Issuing confirms it: see the payment’s split.', 'line' => $c['lines'][0] ?? null];
        }
        $job = $invoice->work_job_id ? WorkJob::query()->with('days')->find($invoice->work_job_id) : null;
        if ($invoice->po_total_minor !== null && (int) $invoice->total_minor > (int) $invoice->po_total_minor) {
            $warnings[] = ['code' => 'po_over', 'message' => Money::format((int) $invoice->total_minor - (int) $invoice->po_total_minor, strtoupper($invoice->currency ?: 'GBP'), false)
                .' of costs aren’t on PO '.($invoice->po_number ?: '').': check '.($invoice->bill_to_name ?: 'the client').' accepts them on this PO'];
        }
        if (AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $invoice->id)->where('action', InvoiceDraftSync::LEGACY_EVENT)->exists()) {
            $warnings[] = ['code' => 'legacy', 'message' => InvoiceDraftSync::LEGACY_NOTE];
        }
        if ($job !== null) {
            if ($invoice->client_id !== null && $job->client_id !== null && ClientIds::resolve((int) $invoice->client_id) !== ClientIds::resolve((int) $job->client_id)) {
                $warnings[] = ['code' => 'client_differs', 'message' => 'This draft is for another client than the job now has: check who it’s for.'];
            }
            foreach (CostUnits::mileagePayments($job) as $m) {
                $warnings[] = ['code' => 'mileage_payment', 'message' => $m['check']];
            }
            $year = UkTaxYear::fromDate($job->ends_on->format('Y-m-d'))->label();
            $missing = collect(JobTrips::proposals($year))->firstWhere('job_id', (int) $job->id);
            if ($missing !== null) {
                $warnings[] = ['code' => 'mileage_missing', 'message' => 'Mileage not recorded yet: '.$missing['trips'].' '.($missing['trips'] === 1 ? 'trip' : 'trips').', '
                    .number_format((float) $missing['miles'], 0).' miles'];
            }
        }

        return ['blocks' => $blocks, 'warnings' => $warnings];
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

    /**
     * A PO's lines on a draft: source 'po', each cited as the document's line (doc:{id}#l{n}), from sort $from on.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private static function poLines(Invoice $invoice, Document $document, array $lines, int $from): int
    {
        foreach (array_values($lines) as $i => $line) {
            $invoice->allLines()->create([
                'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor'],
                'amount_minor' => $line['amount_minor'], 'source_quote' => $line['quote'] ?? null, 'sort' => $from + $i,
                'source' => 'po', 'source_ref' => $i < 10000 ? 'doc:'.$document->id.'#l'.$i : null, 'included' => true,
            ]);
        }

        return count($lines);
    }

    /** The job's open draft made into the PO's: its lines replace the work, costs and your own lines stay. */
    private function convert(Invoice $open, Document $document, array $po): Invoice
    {
        $this->assertDraft($open);
        $replaced = InvoiceLine::query()->where('invoice_id', $open->id)->whereIn('source', array_diff(InvoiceDraftSync::WORK_SOURCES, ['meals']))->get();
        $removed = $replaced->map(fn (InvoiceLine $l) => self::lineState($l))->values()->all();
        $replaced->each->delete();
        // The PO's lines go first, as its work.
        $n = count($po['lines']);
        foreach (InvoiceLine::query()->where('invoice_id', $open->id)->orderBy('sort')->orderBy('id')->get() as $i => $line) {
            $line->forceFill(['sort' => $n + $i])->save();
        }
        self::poLines($open, $document, $po['lines'], 0);
        $before = $open->only(['document_id', 'po_number', 'po_total_minor', 'bill_to_name', 'bill_to_address', 'bill_to_email', 'currency']);
        $fill = ['document_id' => $document->id, 'po_total_minor' => $po['stated_total_minor'] ?? null];
        if ($number = DocumentReader::clip($po['po_number'] ?? null, 100)) {
            $fill['po_number'] = $number;
        }
        if (! empty($po['currency'])) {
            $fill['currency'] = strtoupper((string) $po['currency']);
        }
        foreach (['bill_to_name' => DocumentReader::clip($po['bill_to_name'] ?? null, 200), 'bill_to_address' => DocumentReader::clip($po['bill_to_address'] ?? null, 1000, true),
            'bill_to_email' => self::emailAddress($po['bill_to_email'] ?? null)] as $field => $value) {
            if (trim((string) $open->{$field}) === '' && $value !== null) {
                $fill[$field] = $value;
            }
        }
        $open->forceFill($fill)->save();
        AuditLog::record('invoice.converted', $open, ['document_id' => $before['document_id'], 'lines' => $removed],
            ['document_id' => $document->id, 'po_number' => $open->po_number, 'added' => $n], 'Made from the PO: its lines replace the work');
        $this->sync->sync($open);
        $this->recalculate($open);

        return $open->fresh('lines');
    }

    /**
     * The one PO read for the job, with lines, when no invoice of the job was made from a PO yet: a job's draft is
     * made from it. None when there are two or more.
     */
    private static function onlyPo(WorkJob $job): ?Document
    {
        $used = Invoice::query()->where('work_job_id', $job->id)->where('status', '!=', 'void')->whereNotNull('document_id')->pluck('document_id')->all();
        $pos = Document::query()->where('work_job_id', $job->id)->where('kind', 'po')->whereNotNull('extraction')->get()
            ->filter(fn (Document $d) => ! empty($d->extraction['po']['lines'] ?? null));
        if ($pos->count() !== 1 || $used !== []) {
            return null;
        }

        return $pos->first();
    }

    /**
     * Lines saved one by one, in place.
     *
     * @param  Collection<int, InvoiceLine>  $existing
     * @return array{0:list<array>, 1:list<array>} the lines before and after, as changed
     */
    private function saveLines(Invoice $invoice, Collection $existing, array $parsed): array
    {
        $before = [];
        $after = [];
        $kept = [];
        foreach ($parsed as $p) {
            if ($p['id'] === null) {
                $line = $invoice->allLines()->create([
                    'description' => $p['description'], 'quantity' => (string) $p['quantity'], 'unit_price_minor' => $p['unit'],
                    'amount_minor' => self::lineAmount($p['quantity'], $p['unit']), 'source_quote' => $p['source_quote'], 'sort' => $p['sort'],
                    'included' => $p['included'] ?? true,
                ]);
                $after[] = self::lineState($line);

                continue;
            }
            $line = $existing[$p['id']] ?? throw new InvalidArgumentException('Line '.$p['n'].' is no longer on the draft: open it again.', self::CHANGED);
            if ($p['version'] !== null && $p['version'] !== InvoiceDraftSync::version($line)) {
                throw new InvalidArgumentException('Line '.$p['n'].' (“'.$line->description.'”) changed since you opened the draft: check it and save again.', self::CHANGED);
            }
            $kept[$line->id] = true;
            $source = $line->source !== null;
            $set = [];
            if ($p['description'] !== $line->description) {
                $set += ['description' => $p['description']] + ($source ? ['own_text' => true] : []);
            }
            if (! $p['quantity']->isEqualTo((string) $line->quantity) || $p['unit'] !== (int) $line->unit_price_minor) {
                $set += ['quantity' => (string) $p['quantity'], 'unit_price_minor' => $p['unit'], 'amount_minor' => self::lineAmount($p['quantity'], $p['unit'])]
                    + ($source ? ['own_amount' => true] : []);
                if ($source && in_array($line->flag, ['no_amount', 'check'], true) && $p['unit'] !== 0) {
                    $set['flag'] = null;
                }
            }
            if ($p['included'] !== null && $p['included'] !== (bool) $line->included) {
                $set += ['included' => $p['included'], 'ticked_by' => 'you'];
            }
            if ($p['sort'] !== (int) $line->sort) {
                $set['sort'] = $p['sort'];
            }
            if ($set !== []) {
                $before[] = self::lineState($line);
                $line->forceFill($set)->save();
                $after[] = self::lineState($line);
            }
        }
        foreach ($existing as $line) {
            if (isset($kept[$line->id])) {
                continue;
            }
            if ($line->source !== null && $line->flag !== 'gone') {
                throw new InvalidArgumentException('“'.$line->description.'” comes from the job: untick it instead of removing it.');
            }
            $before[] = self::lineState($line);
            $line->delete();
        }

        return [$before, $after];
    }

    /**
     * A form from before line sources: its lines are the ticked lines as shown. A form that doesn't say which lines it
     * showed ($blind: no lines_version, a page from before) leaves alone a line the sync added or changed since you last
     * looked: it may never have been on that page, so leaving it off isn't unticking it.
     *
     * @param  Collection<int, InvoiceLine>  $existing
     * @return array{0:list<array>, 1:list<array>}
     */
    private function saveForm(Invoice $invoice, Collection $existing, array $parsed, bool $blind = false): array
    {
        $before = [];
        $after = [];
        $unseen = $blind ? InvoiceDraftSync::changes($invoice)->whereIn('action', ['invoice.line_added', 'invoice.line_changed'])
            ->map(fn (AuditEvent $e) => (int) ($e->after['line'] ?? 0))->filter()->flip() : collect();
        $ticked = $existing->filter(fn (InvoiceLine $l) => (bool) $l->included && ! ($l->source !== null && $unseen->has((int) $l->id)))->values();
        $used = [];
        foreach ($parsed as $i => $p) {
            $same = $ticked->first(fn (InvoiceLine $l) => ! isset($used[$l->id]) && $l->description === $p['description']
                && $p['quantity']->isEqualTo((string) $l->quantity) && (int) $l->unit_price_minor === $p['unit']);
            if ($same !== null) {
                $used[$same->id] = true;
                if ((int) $same->sort !== $i) {
                    $same->forceFill(['sort' => $i])->save();
                }

                continue;
            }
            $line = $invoice->allLines()->create([
                'description' => $p['description'], 'quantity' => (string) $p['quantity'], 'unit_price_minor' => $p['unit'],
                'amount_minor' => self::lineAmount($p['quantity'], $p['unit']), 'source_quote' => $p['source_quote'], 'sort' => $i,
            ]);
            $after[] = self::lineState($line);
        }
        foreach ($ticked as $line) {
            if (isset($used[$line->id])) {
                continue;
            }
            $before[] = self::lineState($line);
            if ($line->source === null) {
                $line->delete();
            } else {
                // A line from the job left off the form is left off the invoice, by you: it isn't deleted.
                $line->forceFill(['included' => false, 'ticked_by' => 'you'])->save();
                $after[] = self::lineState($line);
            }
        }

        return [$before, $after];
    }

    /** @param  Collection<int, InvoiceLine>  $ticked */
    private static function assertLinesReady(Collection $ticked, Invoice $invoice): void
    {
        foreach ($ticked as $line) {
            if ($message = self::lineProblem($line, $invoice)) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    /** What stops a ticked line being issued, or null. */
    private static function lineProblem(InvoiceLine $line, Invoice $invoice): ?string
    {
        return match (true) {
            $line->flag === 'gone' => '“'.$line->description.'” is no longer linked to the job: remove it, or keep it as your own line.',
            $line->flag === 'no_amount' => '“'.$line->description.'” has no price: type the price in '.strtoupper($invoice->currency ?: 'GBP').', or untick it.',
            $line->flag === 'check' && ! $line->own_amount => '“'.$line->description.'” needs a look first: check its quantity and price, or untick it.',
            default => null,
        };
    }

    /**
     * A ticked cost goes on one invoice only: refused while it's ticked on another draft or charged on another invoice
     * (by its ref or any alias).
     *
     * @param  Collection<int, InvoiceLine>  $ticked
     */
    private function assertExclusive(Invoice $invoice, Collection $ticked, array $wanted): void
    {
        $refs = [];
        foreach ($ticked as $line) {
            if ($line->source_ref === null || $line->source === 'po') {
                continue;
            }
            foreach ([$line->source_ref, ...($wanted[$line->source_ref]['aliases'] ?? [])] as $r) {
                $refs[$r] = $line;
            }
        }
        if ($refs === []) {
            return;
        }
        $other = InvoiceLine::query()->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')->where('invoices.id', '!=', $invoice->id)
            ->whereIn('invoices.status', ['draft', ...InvoicePayments::PAYABLE])->where('invoice_lines.included', true)->whereIn('invoice_lines.source_ref', array_keys($refs))
            ->orderBy('invoices.id')->first(['invoice_lines.source_ref', 'invoices.id', 'invoices.status', 'invoices.number']);
        if ($other !== null) {
            $line = $refs[$other->source_ref];
            throw new InvalidArgumentException($other->status === 'draft'
                ? '“'.$line->description.'” is ticked on draft #'.$other->id.' too: untick it on one of them.'
                : '“'.$line->description.'” is already charged on '.($other->number ?: 'another invoice').': untick it.');
        }
    }

    /**
     * A job already invoiced for its work: another invoice with work lines on it is issued only once you say it's a
     * second invoice (a PO arriving after Done, or a draft made twice, would otherwise bill the work twice).
     *
     * @param  Collection<int, InvoiceLine>  $ticked
     */
    private function assertSecondInvoice(Invoice $invoice, Collection $ticked, ?array $shown): void
    {
        if ($invoice->work_job_id === null || ! $ticked->contains(fn (InvoiceLine $l) => in_array($l->source, InvoiceDraftSync::WORK_SOURCES, true))) {
            return;
        }
        $prior = Invoice::query()->where('work_job_id', $invoice->work_job_id)->where('id', '!=', $invoice->id)->whereIn('status', InvoicePayments::PAYABLE)
            ->whereHas('lines', fn ($q) => $q->where(fn ($q) => $q->whereNull('source')->orWhereIn('source', InvoiceDraftSync::WORK_SOURCES)))
            ->orderBy('id')->first(['id', 'number']);
        if ($prior !== null && ! ($shown['second_invoice'] ?? false)) {
            $title = WorkJob::query()->whereKey($invoice->work_job_id)->value('title');
            throw new InvalidArgumentException('It’s a second invoice for '.$title.' ('.($prior->number ?: 'an earlier invoice').' has its work): say so to issue it.', self::CHANGED);
        }
    }

    /**
     * The payments to confirm on issuing, each tied to the fingerprint you were shown.
     *
     * @param  Collection<int, InvoiceLine>  $ticked
     * @return list<array{source:BankTransaction|ManualExpense, fp:string}>
     */
    private function confirmations(Invoice $invoice, Collection $ticked, array $wanted, ?array $shown): array
    {
        $open = self::unconfirmed($ticked, $wanted);
        if ($open === []) {
            return [];
        }
        foreach ($open as $c) {
            if ($c['locked']) {
                $line = $ticked->firstWhere('id', $c['lines'][0]);
                throw new InvalidArgumentException('“'.$line?->description.'” isn’t confirmed and '.$c['tax_year'].' is locked: untick it, or unlock the year first.');
            }
        }
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException('Only you can confirm a payment when issuing.');
        }
        $given = collect($shown['confirm'] ?? [])->filter(fn ($c) => is_array($c))->mapWithKeys(fn (array $c) => [(string) ($c['source'] ?? '') => (string) ($c['fp'] ?? '')]);
        $out = [];
        foreach ($open as $subject => $c) {
            [$type, $id] = explode(':', $subject, 2);
            $source = $type === 'tx' ? BankTransaction::query()->find((int) $id) : ManualExpense::query()->find((int) $id);
            if ($source === null || ! isset($given[$subject]) || ! SourceState::matches($source, $given[$subject])) {
                $n = count($open);
                throw new InvalidArgumentException(($n === 1 ? 'A cost isn’t' : $n.' costs aren’t').' confirmed yet. Issuing confirms '.($n === 1 ? 'it' : 'them')
                    .': check each payment’s split and issue again.', self::CHANGED);
            }
            $out[] = ['source' => $source, 'fp' => $given[$subject]];
        }

        return $out;
    }

    /**
     * Ticked costs whose payment isn't confirmed, by payment (tx:381, exp:12).
     *
     * @param  Collection<int, InvoiceLine>  $ticked
     * @return array<string, array{source:string, fp:string, state:?array, locked:bool, tax_year:?string, lines:list<int>}>
     */
    private static function unconfirmed(Collection $ticked, array $wanted): array
    {
        $out = [];
        foreach ($ticked as $line) {
            $u = $line->source_ref !== null ? ($wanted[$line->source_ref]['unit'] ?? null) : null;
            if ($u === null || ! in_array($u['kind'], ['cost', 'credit'], true) || $u['confirmed'] !== false) {
                continue;
            }
            $subject = CostRef::subject($u['ref']);
            if (! isset($out[$subject])) {
                [$type, $id] = explode(':', $subject, 2);
                $source = $type === 'tx' ? BankTransaction::query()->find((int) $id) : ManualExpense::query()->find((int) $id);
                $out[$subject] = ['source' => $subject, 'fp' => $source ? SourceState::fingerprint($source) : '', 'state' => $source ? SourceState::of($source) : null,
                    'locked' => (bool) $u['locked'], 'tax_year' => $u['tax_year'], 'lines' => []];
            }
            $out[$subject]['lines'][] = (int) $line->id;
        }

        return $out;
    }

    private function markSeen(Invoice $invoice, int $upTo): void
    {
        $last = InvoiceDraftSync::lastChange($invoice);
        $upTo = $last === null ? null : min($upTo, $last);
        $stored = (int) Invoice::query()->whereKey($invoice->id)->value('lines_seen_event_id');
        if ($upTo !== null && $upTo > $stored) {
            Invoice::query()->whereKey($invoice->id)->first()?->forceFill(['lines_seen_event_id' => $upTo])->save();
            $invoice->lines_seen_event_id = $upTo;
        }
    }

    /** @return array<string, mixed> a line as an event records it */
    private static function lineState(InvoiceLine $line): array
    {
        return ['line' => (int) $line->id, 'ref' => $line->source_ref, 'description' => $line->description, 'quantity' => (string) $line->quantity,
            'unit_price' => (int) $line->unit_price_minor, 'amount' => (int) $line->amount_minor, 'included' => (bool) $line->included];
    }

    private static function things(int $n): string
    {
        return $n === 1 ? '1 thing' : $n.' things';
    }

    /** A client's payment terms in days, else the usual ones (Settings). */
    private static function terms(?Client $client): int
    {
        return (int) ($client?->payment_terms_days ?: Setting::get('invoice_terms_days'));
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

    /** The invoice as stored is still a draft (a page loaded before it was issued elsewhere can't issue it again). */
    private function assertStoredDraft(Invoice $invoice): void
    {
        if (Invoice::query()->whereKey($invoice->id)->value('status') !== 'draft') {
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
