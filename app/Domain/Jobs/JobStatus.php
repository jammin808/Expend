<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Clients\ClientIds;
use App\Domain\Income\IncomeText;
use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\InvoiceLine;
use App\Models\JobDay;
use App\Models\WorkJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A job's status: Done, back to booked, and Cancelled. Every status change goes through here, from a button (the job
 * page, the to-do list, Telegram) or from the job form, with the same refusals and the same history: job.done,
 * job.reopened, job.cancelled and job.uncancelled (Undo of Cancelled). Yours only: automation never changes a status
 * (the date suggests Done, JobStage::doneDue, but never sets it).
 *
 * Marking a job done gets its invoice ready: the open draft it has, or a new draft (InvoiceService::draftForJob),
 * unless it's already invoiced or paid, or its client pays without an invoice from you. A job made already done only
 * offers "Draft its invoice?" (offersDraft, draftInvoice), so adding old jobs doesn't make a pile of drafts.
 *
 * No ledger line is written, so a locked year doesn't stop any of it. Each takes its locks in the one order every
 * audited writer uses: the audit head, then the job, then its invoices.
 *
 * Each returns what happened: the job, whether it changed, the invoice involved (the draft, or the issued invoice that
 * stays), what happened to the draft (made, reused, kept or removed), why there's none (invoiced, paid, no_invoice), and
 * the message to show.
 */
final class JobStatus
{
    public const STATUSES = ['booked', 'done', 'cancelled'];

    /**
     * How many times a transaction is tried when the database ends it to break a deadlock (another writer took the same
     * rows the other way round): it reads everything again under its locks, so trying again is safe.
     */
    private const ATTEMPTS = 3;

    public function __construct(private InvoiceService $invoices) {}

    /**
     * Mark it done, and get its invoice ready. A cancelled job is refused (set it back to booked first); a job already
     * done is left as it is.
     *
     * @param  list<string>  $removeDays  days still to come that didn't happen ("Did it finish early?"), removed first
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function markDone(WorkJob $job, array $removeDays = []): array
    {
        self::yours('mark a job done');

        return DB::transaction(function () use ($job, $removeDays) {
            AuditLog::lockHead();
            $job = self::lock($job);
            if ($job->status === 'cancelled') {
                throw new InvalidArgumentException("{$job->title} was cancelled: set it back to booked before marking it done.");
            }
            if ($job->status === 'done') {
                $draft = self::openDraft($job);

                return self::result($job, false, $draft, $draft ? 'reused' : null, null, "{$job->title} is already done.");
            }
            if ($removeDays !== []) {
                self::removeDays($job, $removeDays);
                $job = self::lock($job);
            }

            $before = self::fields($job);
            $job->update(['status' => 'done', 'done_at' => now()]);
            [$invoice, $draft, $why] = $this->getDraftReady($job, false);
            AuditLog::record('job.done', $job, $before, self::fields($job) + ($draft === 'made' ? ['draft_made' => $invoice->id] : []));

            return self::result($job, true, $invoice, $draft, $why, 'Marked done. '.self::draftWords($job, $invoice, $draft, $why));
        }, self::ATTEMPTS);
    }

    /**
     * Back to booked, from done or cancelled. The draft that marking it done (or cancelling it) made is removed only
     * when you haven't touched it (no edit, tick, save or "Got it" by you; the sync's changes don't count): otherwise
     * it stays.
     *
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function reopen(WorkJob $job, ?string $reason = null): array
    {
        self::yours('set a job back to booked');

        return DB::transaction(function () use ($job, $reason) {
            AuditLog::lockHead();
            $job = self::lock($job);
            if ($job->status === 'booked') {
                return self::result($job, false, null, null, null, "{$job->title} is already booked.");
            }
            $made = self::draftMadeBy(self::lastStatusEvent($job));
            $before = self::fields($job);
            $job->update(['status' => 'booked', 'done_at' => null, 'cancel_charge' => null, 'cancel_fee_pence' => null]);
            [$outcome, $kept] = $made !== null ? self::dropUntouched($made) : [null, null];
            AuditLog::record('job.reopened', $job, $before, self::fields($job) + ($outcome === 'removed' ? ['draft_removed' => $made] : []), self::reason($reason));

            return self::result($job, true, $kept, $outcome, null, $outcome === 'kept' ? 'Back to booked. The draft stays: you’ve changed it.' : 'Back to booked.');
        }, self::ATTEMPTS);
    }

    /**
     * Cancel it, saying what they still owe: nothing, a cancellation fee, or your costs. With a fee or costs, the job
     * keeps (or gets) a draft: the cancellation fee and its costs by its arrangement. With nothing owed, its open draft
     * is removed when you haven't touched it (Done's, as Back to booked does); one you changed stays, and its lines
     * from the job go (their costs aren't owed any more). An issued invoice stays (it's allowed): void it only if it's
     * wrong. Cancelling a cancelled job again changes what they owe.
     *
     * @param  string  $charge  one of WorkJob::CANCEL_CHARGES
     * @param  int|null  $feePence  the cancellation fee, for charge 'fee'
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function cancel(WorkJob $job, string $charge = 'none', ?int $feePence = null): array
    {
        self::yours('cancel a job');
        if (! in_array($charge, WorkJob::CANCEL_CHARGES, true)) {
            throw new InvalidArgumentException('Say whether they owe you anything: nothing, a cancellation fee or your costs.');
        }
        if ($charge === 'fee' && ($feePence === null || $feePence <= 0)) {
            throw new InvalidArgumentException('Give the cancellation fee: an amount above zero.');
        }
        $feePence = $charge === 'fee' ? $feePence : null;

        return DB::transaction(function () use ($job, $charge, $feePence) {
            AuditLog::lockHead();
            $job = self::lock($job);
            if ($job->status === 'cancelled' && $job->cancel_charge === $charge && $job->cancel_fee_pence === $feePence) {
                return self::result($job, false, self::openDraft($job), null, null, "{$job->title} is already cancelled.");
            }
            $before = self::fields($job);
            $job->update(['status' => 'cancelled', 'done_at' => null, 'cancel_charge' => $charge, 'cancel_fee_pence' => $feePence]);
            $issued = self::issued($job);
            $removed = null;
            if ($charge !== 'none') {
                [$invoice, $draft, $why] = $this->getDraftReady($job, false);
            } else {
                // Nothing owed: the draft goes if you haven't touched it; one you changed stays, its job lines removed.
                [$invoice, $draft, $why] = [$issued, null, null];
                $open = self::openDraft($job);
                [$outcome, $kept] = $open !== null ? self::dropUntouched((int) $open->id, 'Cancelled with nothing owed: the draft, untouched') : [null, null];
                if ($outcome === 'removed') {
                    $removed = (int) $open->id;
                } elseif ($kept !== null) {
                    [$invoice, $draft] = [$kept, 'kept'];
                }
            }
            AuditLog::record('job.cancelled', $job, $before, self::fields($job) + ($draft === 'made' ? ['draft_made' => $invoice->id] : [])
                + ($removed !== null ? ['draft_removed' => $removed] : []));
            if ($draft === 'kept') {
                app(InvoiceDraftSync::class)->sync($invoice);
            }

            $message = 'Cancelled.'
                .($issued !== null ? ' '.($issued->number ?: 'Its invoice').' stays: void it only if it’s wrong.' : '')
                .($draft === 'kept' ? ' Its draft stays: you’ve changed it. They owe nothing for the job now, so check it.' : '')
                .($draft !== null && $draft !== 'kept' ? ' Its invoice is ready to check.' : '');

            return self::result($job, true, $invoice, $draft, $why, $message);
        }, self::ATTEMPTS);
    }

    /**
     * Undo of Cancelled: the job goes back to what it was before (booked or done, with what it owed if it was
     * cancelled before), and the draft cancelling made goes if you haven't touched it.
     *
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function undoCancel(WorkJob $job): array
    {
        self::yours('undo cancelling a job');

        return DB::transaction(function () use ($job) {
            AuditLog::lockHead();
            $job = self::lock($job);
            if ($job->status !== 'cancelled') {
                throw new InvalidArgumentException("{$job->title} isn’t cancelled.");
            }
            $event = self::lastStatusEvent($job);
            $was = $event?->action === 'job.cancelled' && is_array($event->before) ? $event->before : ['status' => 'booked'];
            $status = in_array($was['status'] ?? null, self::STATUSES, true) ? $was['status'] : 'booked';
            $made = self::draftMadeBy($event);
            $before = self::fields($job);
            $job->update([
                'status' => $status,
                'done_at' => $status === 'done' ? ($was['done_at'] ?? now()) : null,
                'cancel_charge' => $status === 'cancelled' ? ($was['cancel_charge'] ?? null) : null,
                'cancel_fee_pence' => $status === 'cancelled' ? ($was['cancel_fee_pence'] ?? null) : null,
            ]);
            [$outcome, $kept] = $made !== null ? self::dropUntouched($made) : [null, null];
            // Back to done after cancelling removed its draft: the draft is made again.
            $remade = null;
            $wasRemoved = is_array($event?->after) && isset($event->after['draft_removed']);
            if ($wasRemoved && $kept === null && ($status === 'done' || ($status === 'cancelled' && in_array($job->cancel_charge, ['fee', 'costs'], true)))) {
                [$remade, $how] = $this->getDraftReady($job, false);
                $remade = $how === 'made' || $how === 'reused' ? $remade : null;
            }
            AuditLog::record('job.uncancelled', $job, $before, self::fields($job) + ($outcome === 'removed' ? ['draft_removed' => $made] : [])
                + ($remade !== null ? ['draft_made' => $remade->id] : []), 'Undo');

            $back = ['booked' => 'Back to booked.', 'done' => 'Back to done.', 'cancelled' => 'Back to what they owed before.'][$status];

            return self::result($job, true, $kept ?? $remade, $remade !== null ? 'made' : $outcome, null,
                $outcome === 'kept' ? $back.' The draft stays: you’ve changed it.' : ($remade !== null ? $back.' Its invoice is ready to check.' : $back));
        }, self::ATTEMPTS);
    }

    /**
     * The job form's status select: the change it asks for, through the same steps as the buttons (booked to done
     * gets the invoice ready; done to booked clears when it was done). Nothing changes when it's the status the job
     * already has, apart from what a cancelled job owes.
     *
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function change(WorkJob $job, string $to, ?string $charge = null, ?int $feePence = null): array
    {
        if (! in_array($to, self::STATUSES, true)) {
            throw new InvalidArgumentException('A job is booked, done or cancelled.');
        }

        return match ($to) {
            'done' => $this->markDone($job),
            'booked' => $this->reopen($job),
            'cancelled' => $this->cancel($job, $charge ?? $job->fresh()?->cancel_charge ?? 'none', $feePence ?? ($charge === null ? $job->fresh()?->cancel_fee_pence : null)),
        };
    }

    /**
     * Whether to offer "Draft its invoice?" for a job: it's done, with no invoice (draft or issued), nothing paid for
     * it (or only part of its work, a deposit), and a client who isn't paid without an invoice. A job made already
     * done is offered this instead of a draft.
     */
    public static function offersDraft(WorkJob $job): bool
    {
        return $job->status === 'done'
            && ! Invoice::query()->where('work_job_id', $job->id)->where('status', '!=', 'void')->exists()
            && (! JobBilling::isBilled($job) || isset(JobBilling::short([$job])[$job->id]))
            && self::route($job) !== 'none';
    }

    /**
     * "Draft it": the job's open draft, or a new one. Not for a job cancelled with nothing owed, and not a second
     * invoice for a job already invoiced.
     *
     * @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string}
     */
    public function draftInvoice(WorkJob $job): array
    {
        self::yours('draft an invoice');

        return DB::transaction(function () use ($job) {
            AuditLog::lockHead();
            $job = self::lock($job);
            if ($job->status === 'cancelled' && ! in_array($job->cancel_charge, ['fee', 'costs'], true)) {
                throw new InvalidArgumentException("{$job->title} was cancelled with nothing owed: there’s nothing to invoice.");
            }
            [$invoice, $draft, $why] = $this->getDraftReady($job, true);

            return self::result($job, $draft === 'made', $invoice, $draft, $why, self::draftWords($job, $invoice, $draft, $why));
        }, self::ATTEMPTS);
    }

    /**
     * The job's open draft, or a new one (InvoiceService::draftForJob), unless it's already invoiced, or (unless you
     * asked for one) money for it has come in or its client pays without an invoice from you.
     *
     * @return array{0:?Invoice, 1:?string, 2:?string} the draft (or the issued invoice), made or reused, and why there's none
     */
    private function getDraftReady(WorkJob $job, bool $asked): array
    {
        if ($draft = self::openDraft($job)) {
            return [$draft, 'reused', null];
        }
        if ($issued = self::issued($job)) {
            return [$issued, null, 'invoiced'];
        }
        // Money for it came in: no draft, unless it's short of the work (a deposit), when the draft is for the rest.
        if (! $asked && JobBilling::isBilled($job) && ! isset(JobBilling::short([$job])[$job->id])) {
            return [null, null, 'paid'];
        }
        if (! $asked && self::route($job) === 'none') {
            return [null, null, 'no_invoice'];
        }

        return [$this->invoices->draftForJob($job), 'made', null];
    }

    /** What happened to its invoice, in words. */
    private static function draftWords(WorkJob $job, ?Invoice $invoice, ?string $draft, ?string $why): string
    {
        $part = $invoice !== null && $draft !== null && $why === null ? (JobBilling::short([$job])[$job->id] ?? null) : null;

        return match (true) {
            $part !== null => IncomeText::money($part['paid'], 'GBP').' of '.IncomeText::money($part['expected'], 'GBP')
                .' has come in for it: put what’s still owed on its draft.',
            $why === 'invoiced' => 'It’s already invoiced'.($invoice?->number ? " ({$invoice->number})" : '').'.',
            $why === 'paid' => 'Money for it has come in already, so no invoice was drafted.',
            $why === 'no_invoice' => (self::client($job)?->name ?? 'The client').' pays without an invoice from you, so no invoice was drafted.',
            $invoice !== null && $draft !== null && ! $invoice->lines()->exists() => 'What did you charge for it?',
            $invoice !== null && $draft !== null && $invoice->client_id === null && ! $invoice->bill_to_name => 'Its invoice is ready to check: say who it’s for first.',
            default => 'Its invoice is ready to check.'.self::unsetTravel($job, $invoice),
        };
    }

    /**
     * " Acme's travel isn't set yet: 2 costs aren't ticked." when the client has no arrangement for travel and
     * expenses, so the draft lists the job's costs unticked; nothing otherwise.
     */
    private static function unsetTravel(WorkJob $job, ?Invoice $invoice): string
    {
        if ($invoice === null || $invoice->status !== 'draft' || ChargeTerms::any(ChargeTerms::forJob($job))) {
            return '';
        }
        $n = InvoiceLine::query()->where('invoice_id', $invoice->id)->whereIn('source', ['cost', 'mileage'])->where('included', false)->count();

        return $n === 0 ? '' : ' '.(self::client($job)?->name ?? 'The client').'’s travel isn’t set yet: '.$n.' '.($n === 1 ? 'cost isn’t' : 'costs aren’t').' ticked.';
    }

    /**
     * Remove a draft that marking done (or cancelling) made, if you haven't touched it.
     *
     * @return array{0:?string, 1:?Invoice} removed or kept (with the draft kept), or nothing when it's gone or issued
     */
    private static function dropUntouched(int $invoiceId, string $why = 'Back to booked: the draft Done made, untouched'): array
    {
        $draft = Invoice::query()->lockForUpdate()->find($invoiceId);
        if (! $draft || $draft->status !== 'draft') {
            return [null, null];
        }
        if (! self::untouched($draft)) {
            return ['kept', $draft];
        }
        $before = ['status' => 'draft', 'work_job_id' => $draft->work_job_id, 'client_id' => $draft->client_id, 'total_minor' => (int) $draft->total_minor,
            'lines' => $draft->allLines()->count()];
        $draft->allLines()->get()->each->delete();
        $draft->delete();
        AuditLog::record('invoice.deleted', 'Invoice', $before, null, $why, subjectId: $invoiceId);

        return ['removed', null];
    }

    /**
     * Whether you've left a draft as it was made: no change by you (an edit, a tick, a save, "Got it"), no payment
     * naming it and no email for it. What the draft sync changed doesn't count.
     */
    private static function untouched(Invoice $draft): bool
    {
        if ($draft->lines_seen_event_id !== null
            || Allocation::query()->where('invoice_id', $draft->id)->exists()
            || InvoiceEmail::query()->where('invoice_id', $draft->id)->exists()
            || InvoiceLine::query()->where('invoice_id', $draft->id)->where(fn ($q) => $q->whereNotNull('ticked_by')->orWhere('own_text', true)->orWhere('own_amount', true))->exists()) {
            return false;
        }
        $lines = InvoiceLine::query()->where('invoice_id', $draft->id)->pluck('id')->all();

        return ! AuditEvent::query()->whereIn('actor', JobCreator::OWNER)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'Invoice')->where('subject_id', $draft->id)->where('action', '!=', 'invoice.drafted'))
                ->orWhere(fn ($q) => $q->where('subject_type', 'InvoiceLine')->whereIn('subject_id', $lines ?: [0])))
            ->exists();
    }

    /** The last time its status was set to done or cancelled (as you did it), if it was ever. */
    private static function lastStatusEvent(WorkJob $job): ?AuditEvent
    {
        return AuditEvent::query()->where('subject_type', 'WorkJob')->where('subject_id', $job->id)
            ->whereIn('action', ['job.done', 'job.cancelled', 'job.reopened', 'job.uncancelled'])->orderByDesc('id')->first();
    }

    /** The draft that status change made, if it made one. */
    private static function draftMadeBy(?AuditEvent $event): ?int
    {
        $id = in_array($event?->action, ['job.done', 'job.cancelled'], true) && is_array($event->after) ? ($event->after['draft_made'] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Days still to come that didn't happen, removed as you (JobDays::remove, audited job.day_removed). Only days
     * still to come can go this way.
     *
     * @param  list<string>  $dates
     */
    private static function removeDays(WorkJob $job, array $dates): void
    {
        $toCome = JobStage::daysToCome($job);
        foreach (array_values(array_unique($dates)) as $date) {
            $date = (string) $date;
            if (! in_array($date, $toCome, true)) {
                throw new InvalidArgumentException("{$date} isn’t one of {$job->title}’s days still to come.");
            }
            $day = JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', $date)->first();
            if ($day) {
                $day->setRelation('job', $job);
                JobDays::remove($day);
            }
        }
    }

    private static function openDraft(WorkJob $job): ?Invoice
    {
        return Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderByDesc('id')->first();
    }

    /** Its latest issued (or legacy paid) invoice. */
    private static function issued(WorkJob $job): ?Invoice
    {
        return Invoice::query()->where('work_job_id', $job->id)->whereIn('status', InvoicePayments::PAYABLE)->orderByDesc('id')->first();
    }

    /** Its paying client as it is now (a client merged into another is that one). */
    private static function client(WorkJob $job): ?Client
    {
        return $job->client_id === null ? null : Client::query()->find(ClientIds::resolve((int) $job->client_id));
    }

    /** How its client gets invoices: thread, new, or none (they pay without an invoice from you); null with no client. */
    private static function route(WorkJob $job): ?string
    {
        return self::client($job)?->invoiceRoute();
    }

    /** The job as stored, locked until the surrounding transaction ends (the audit head is locked before it). */
    private static function lock(WorkJob $job): WorkJob
    {
        return WorkJob::query()->with('client')->lockForUpdate()->find($job->id)
            ?? throw new InvalidArgumentException('That job no longer exists.');
    }

    /** @return array{status:string, done_at:?string, cancel_charge:?string, cancel_fee_pence:?int} */
    private static function fields(WorkJob $job): array
    {
        return [
            'status' => $job->status,
            'done_at' => $job->done_at?->format('Y-m-d H:i:s'),
            'cancel_charge' => $job->cancel_charge,
            'cancel_fee_pence' => $job->cancel_fee_pence,
        ];
    }

    private static function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === '' || $reason === null ? null : mb_substr($reason, 0, 250);
    }

    /** @return array{job:WorkJob, changed:bool, invoice:?Invoice, draft:?string, why:?string, message:string} */
    private static function result(WorkJob $job, bool $changed, ?Invoice $invoice, ?string $draft, ?string $why, string $message): array
    {
        return ['job' => $job, 'changed' => $changed, 'invoice' => $invoice, 'draft' => $draft, 'why' => $why, 'message' => $message];
    }

    /** Status changes are yours: never automation's. A Telegram tap counts as you. */
    private static function yours(string $what): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException("Only you can {$what}.");
        }
    }
}
