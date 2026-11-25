<?php

namespace App\Domain\Todo;

use App\Domain\Charges\CostRef;
use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\CandidateText;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Ledger\SourceState;
use App\Domain\Matching\TransferPairer;
use App\Domain\Quick\QuickEntries;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\JobCandidate;
use App\Models\MerchantRule;
use App\Models\MileageTrip;
use App\Models\Opportunity;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Veto;
use App\Models\WorkJob;

/**
 * Actions bound to what was shown (docs/intelligence-cycle.md 4.5 and 7.6, M7): a to-do button carries a short
 * fingerprint of the state it was offered on (the payment's lines, the invoice's status and total, the finding's
 * amount…), and a press is refused when the state has changed since: "This changed since: open it in Expend". On the
 * web it's sent with the action; in Telegram it's the fourth part of a button's data (t|code|id|fp), within Telegram's
 * 64 bytes.
 *
 * Only actions that decide something are bound. A re-check (recheck) decides nothing, and "Not relevant…" by a
 * finding's id alone only asks why: old buttons without a fingerprint keep working for those.
 */
final class Fingerprints
{
    public const LENGTH = 6;

    /** Actions that decide nothing: no fingerprint, and an old button without one still works. A 'link' only opens a page. */
    public const NEUTRAL = ['recheck', 'link'];

    /** Whether this action decides something (and so is bound to what was shown). */
    public static function decides(string $code, string $id): bool
    {
        return ! in_array($code, self::NEUTRAL, true) && ! ($code === 'fnd_dismiss' && ctype_digit($id));
    }

    /**
     * The fingerprint of what this action would act on now: 6 characters of lower-case hex. Null for an action that
     * decides nothing. Something that's gone has a fingerprint too, so a button offered on it never matches anything else.
     */
    public static function of(string $code, string $id): ?string
    {
        if (! self::decides($code, $id)) {
            return null;
        }

        return substr(hash('sha256', json_encode([$code, $id, self::state($code, $id)])), 0, self::LENGTH);
    }

    /**
     * Actions as a page shows them (TodoList::action()'s), each with the fingerprint of what it would act on now.
     *
     * @param  list<array{code:string, id:string}>  $actions
     * @return list<array<string, mixed>>
     */
    public static function attach(array $actions): array
    {
        return array_map(fn (array $a) => $a + ['fp' => self::of((string) $a['code'], (string) $a['id'])], $actions);
    }

    /**
     * Refuse a press whose fingerprint doesn't match what the action would act on now. No fingerprint (null) isn't
     * checked here: Telegram refuses deciding buttons without one itself (old buttons), and the web refuses one without
     * (TodoController::act). Something that has gone, or that has been dealt with since (settled()), isn't refused here
     * either: the action itself says it's already been dealt with, and changes nothing.
     *
     * @throws ChangedSince
     */
    public static function check(string $code, string $id, ?string $fp): void
    {
        if ($fp === null || ! self::decides($code, $id) || self::state($code, $id) === null) {
            return;
        }
        if (! hash_equals((string) self::of($code, $id), strtolower($fp)) && ! self::settled($code, $id)) {
            throw new ChangedSince;
        }
    }

    /**
     * Whether what the action is about has been dealt with since, so its own repeat check says "Already dealt with"
     * and changes nothing (TodoActions: every action is safe to repeat). A press after you did it yourself is then told
     * it's done, not that something changed. Only where that repeat check is certain to do nothing.
     */
    public static function settled(string $code, string $id): bool
    {
        $int = ctype_digit($id) ? (int) $id : 0;
        $lines = fn () => Allocation::query()->where('bank_transaction_id', $int)->get(['kind', 'is_manual', 'confirmed_at']);

        return match ($code) {
            'tx_accept' => ($l = $lines())->isNotEmpty() && $l->every(fn (Allocation $a) => $a->confirmed_at !== null),
            'tx_personal' => $lines()->contains(fn (Allocation $a) => $a->is_manual || $a->confirmed_at !== null),
            'tx_tax' => ($l = $lines())->isNotEmpty() && $l->every(fn (Allocation $a) => $a->kind === 'tax_payment' && $a->confirmed_at !== null),
            'rule_make' => ($key = BankTransaction::query()->whereKey($int)->value('merchant_key')) !== null && MerchantRule::query()->where('merchant_key', $key)->exists(),
            'cand_add', 'cand_dismiss' => JobCandidate::query()->whereKey($int)->where('status', '!=', 'open')->exists(),
            'cand_merge' => preg_match('/^(\d+):\d+$/', $id, $m) && JobCandidate::query()->whereKey((int) $m[1])->where('status', '!=', 'open')->exists(),
            'job_no_po' => (bool) WorkJob::query()->whereKey($int)->value('po_not_required'),
            'stay_accept', 'stay_personal' => TravelItem::query()->whereKey($int)->whereNotNull('decided_at')->exists(),
            'doc_approve' => Document::query()->whereKey($int)->where('status', '!=', 'held')->exists(),
            'opp_add', 'opp_decline', 'opp_dismiss' => ($o = Opportunity::query()->find($int)) !== null && ! $o->isOpen(),
            'fnd_snooze', 'fnd_dismiss' => preg_match('/^(\d+):/', $id, $m) && ($f = Finding::query()->find((int) $m[1])) !== null
                && in_array(Dismissals::hiddenNow($f->key), $code === 'fnd_snooze' ? ['dismissed', 'snoozed'] : ['dismissed'], true),
            'veto_lift' => Veto::query()->whereKey($int)->whereNotNull('lifted_at')->exists(),
            'qx' => ($p = QuickEntries::parseAction($id)) !== null && ($e = QuickEntry::query()->find($p[0])) !== null
                && ! in_array($e->status, QuickEntries::VERBS[$p[1]], true) && ! ($p[1] === 'attach' && $e->isWaiting()),
            // A job's status, as each button would leave it.
            'job_done' => WorkJob::query()->whereKey(self::lead($id))->value('status') === 'done',
            'job_reopen' => WorkJob::query()->whereKey(self::lead($id))->value('status') === 'booked',
            'job_uncancel' => ($status = WorkJob::query()->whereKey(self::lead($id))->value('status')) !== null && $status !== 'cancelled',
            'job_cancel' => str_contains($id, ':') && WorkJob::query()->whereKey(self::lead($id))->value('status') === 'cancelled'
                && WorkJob::query()->whereKey(self::lead($id))->value('cancel_charge') === substr($id, strrpos($id, ':') + 1),
            'job_not_yet' => Dismissals::hiddenNow('job_done:'.self::lead($id)) !== null,
            'todo_undo' => Dismissals::hiddenNow($id) === null,
            // An invoice sent (by you, or from Gmail), or its overdue item set aside for now.
            'inv_sent_self', 'inv_gmail_send' => Invoice::query()->whereKey($int)->whereNotNull('sent_at')->exists(),
            'inv_paid_wait', 'inv_chase_later' => Dismissals::hiddenNow('invoice_overdue:'.$int) !== null,
            'inv_replace' => preg_match('/^(\d+):(\d+)$/', $id, $m) === 1 && (int) Invoice::query()->whereKey((int) $m[1])->value('replaced_by_id') === (int) $m[2],
            'inv_close' => ($i = Invoice::query()->find(self::lead($id))) !== null && $i->closed_minor !== null
                && (InvoicePayments::received([$i])[$i->id] ?? InvoicePayments::EMPTY)['outstanding'] <= 0,
            // A payment decided as you since: its own repeat check says so.
            'tx_invoice', 'tx_client', 'tx_job' => ($l = Allocation::query()->where('bank_transaction_id', self::lead($id))->get(['confirmed_at', 'is_manual', 'work_job_id', 'invoice_id', 'client_id']))
                ->isNotEmpty() && $l->every(fn (Allocation $a) => $a->confirmed_at !== null) && self::txSettled($code, $id, $l),
            // Your decision about a cost, as the button would leave it.
            'cost_charge', 'cost_no_charge', 'cost_elsewhere', 'cost_claimed', 'cost_invoiced' => ($row = self::costRow($code, $id)) !== null
                && $row->charge === ['cost_charge' => 'recharge', 'cost_no_charge' => 'no_charge', 'cost_elsewhere' => 'elsewhere', 'cost_claimed' => 'elsewhere', 'cost_invoiced' => 'invoiced'][$code],
            'cost_paid_back', 'cost_not_paid' => ($row = self::costRow($code, $id)) !== null && $row->outcome !== null,
            'cost_clear' => ($ref = CostRef::fromId($id)) !== null && CostCharge::query()->where('cost_ref', $ref)->whereNotNull('charge')->doesntExist(),
            default => false,
        };
    }

    /** The first number of an id ("45" for "45:none"), or 0. */
    private static function lead(string $id): int
    {
        return preg_match('/^(\d+)/', $id, $m) === 1 ? (int) $m[1] : 0;
    }

    /** Whether a payment's confirmed lines already say what the button would have said. */
    private static function txSettled(string $code, string $id, $lines): bool
    {
        $target = preg_match('/^\d+:(\d+)$/', $id, $m) === 1 ? (int) $m[1] : 0;

        return match ($code) {
            'tx_invoice' => $lines->contains(fn (Allocation $a) => (int) $a->invoice_id === $target),
            'tx_job' => $lines->contains(fn (Allocation $a) => (int) $a->work_job_id === $target),
            default => $lines->contains(fn (Allocation $a) => (int) $a->client_id === $target),
        };
    }

    /** Your decision row for the cost a cost button is about (its id is CostRef::toId's, with ':{invoice}' for cost_invoiced). */
    private static function costRow(string $code, string $id): ?CostCharge
    {
        $ref = self::costRef($code, $id);

        return $ref === null ? null : CostCharge::query()->where('cost_ref', $ref)->first();
    }

    /** The cost a cost button is about. */
    public static function costRef(string $code, string $id): ?string
    {
        if ($code === 'cost_invoiced') {
            $id = str_contains($id, ':') ? substr($id, 0, strrpos($id, ':')) : '';
        }

        return CostRef::fromId($id);
    }

    /** What the action would act on, as it matters to the decision; null when it's gone (or the action is unknown). */
    private static function state(string $code, string $id): mixed
    {
        $int = ctype_digit($id) ? (int) $id : 0;

        return match ($code) {
            'tx_accept', 'tx_personal', 'tx_tax' => self::tx($int),
            'rule_make', 'rule_no' => self::ruleOffer($int),
            'cand_add', 'cand_dismiss' => JobCandidate::query()->find($int)?->only(self::CANDIDATE),
            'cand_merge' => self::merge($id),
            'job_invoice' => ($job = WorkJob::query()->find($int)) ? $job->only(['status', 'title', 'fee_pence', 'po_number', 'client_id'])
                + ['invoices' => Invoice::query()->where('work_job_id', $int)->orderBy('id')->get(['id', 'status'])->map(fn ($i) => [$i->id, $i->status])->all()] : null,
            'job_no_po' => WorkJob::query()->find($int)?->only(['po_number', 'po_not_required', 'status']),
            'stay_accept', 'stay_personal' => ($t = TravelItem::query()->find($int)) ? $t->only(['status', 'decision', 'work_job_id', 'bank_transaction_id', 'amount_minor', 'currency', 'starts_on', 'ends_on'])
                + ['decided' => $t->decided_at !== null, 'suggestion' => array_intersect_key((array) $t->suggestion, array_flip(['decision', 'job_id', 'business_nights', 'nights']))] : null,
            'doc_approve' => Document::query()->find($int)?->only(['status']),
            'inv_chase', 'inv_send' => ($i = Invoice::query()->find($int)) ? $i->only(['status', 'number', 'total_minor', 'currency', 'due_date', 'work_job_id'])
                + ['sent' => $i->sent_at?->getTimestamp(), 'reminded' => $i->reminded_at?->getTimestamp()] + self::closing($i) + self::openEmails($i)
                + ($code === 'inv_chase' && InvoiceEmails::canUse() ? ['route' => 'gmail'] : []) : null,
            // Your press on a job: its status, its dates and days, and its invoices; Back to booked also on the draft
            // Done made and whether you've touched it; Undo of Cancelled on what it was cancelled from.
            'job_done', 'job_not_yet', 'job_cancel' => self::job(self::lead($id)),
            'job_reopen', 'job_uncancel' => ($j = self::job(self::lead($id))) !== null ? $j + ['last' => self::lastStatus(self::lead($id))] : null,
            'todo_undo' => ['hidden' => Dismissals::hiddenNow($id)],
            // A draft left until Monday: its id, status and your last change.
            'inv_draft_later' => ($i = Invoice::query()->find($int)) ? ['id' => $i->id, 'status' => $i->status, 'last' => InvoiceDraftSync::lastChange($i), 'seen' => $i->lines_seen_event_id] : null,
            // An invoice's email in Gmail: the row as it stands, its draft's message id, the invoice's state and what's
            // still to come.
            'inv_to_gmail', 'inv_gmail_send', 'inv_sent_self' => self::emailState($int, 'invoice'),
            'inv_chase_send', 'inv_chase_later', 'inv_paid_wait' => self::emailState($int, 'reminder'),
            'inv_close', 'inv_over_keep' => ($i = Invoice::query()->find(self::lead($id))) ? self::invoiceState($i) : null,
            'inv_replace' => preg_match('/^(\d+):(\d+)$/', $id, $m) === 1 && ($void = Invoice::query()->find((int) $m[1]))
                ? ['void' => $void->only(['status', 'replaced_by_id', 'client_id', 'bill_to_name']), 'stranded' => InvoicePayments::stranded([$void])[$void->id] ?? 0,
                    'to' => ($to = Invoice::query()->find((int) $m[2])) ? self::invoiceState($to) : null] : null,
            // A payment and what the button links it to.
            'tx_invoice', 'tx_fee' => preg_match('/^(\d+):(\d+)$/', $id, $m) === 1 && ($tx = self::tx((int) $m[1])) !== null
                ? ['tx' => $tx, 'invoice' => ($i = Invoice::query()->find((int) $m[2])) ? self::invoiceState($i) : null] : null,
            'tx_client' => preg_match('/^(\d+):(\d+)$/', $id, $m) === 1 && ($tx = self::tx((int) $m[1])) !== null
                ? ['tx' => $tx, 'client' => Client::query()->find((int) $m[2])?->only(['id', 'name', 'merged_into_id'])] : null,
            'tx_job' => preg_match('/^(\d+):(\d+)$/', $id, $m) === 1 && ($tx = self::tx((int) $m[1])) !== null
                ? ['tx' => $tx, 'job' => WorkJob::query()->find((int) $m[2])?->only(['id', 'status', 'client_id', 'starts_on', 'ends_on'])] : null,
            // A cost: your decision about it, and the record behind it.
            'cost_charge', 'cost_no_charge', 'cost_elsewhere', 'cost_claimed', 'cost_invoiced', 'cost_paid_back', 'cost_not_paid', 'cost_clear', 'cost_invoice_now' => self::cost($code, $id),
            'transfers_ok' => TransferPairer::confirmable($int > 0 ? $int : null)['ids'],
            // What the item shows and the job would be made from (its title, client, role, venue, days, rate, PO, reply-by
            // date, hold), never when it was last seen, how sure the reading is, or the words a follow-up email added to
            // its evidence; adding the job also on its being ready for one tap (every detail found word for word:
            // Opportunity::oneTapReady()).
            'opp_add', 'opp_decline', 'opp_dismiss' => ($o = Opportunity::query()->find($int)) ? $o->only(['status', 'title', 'client_id', 'client_name', 'contact_name',
                'role', 'venue', 'postcode', 'po_number', 'hold_rank', 'rate_minor', 'rate_currency', 'rate_unit', 'work_job_id', 'dates'])
                + ['from' => $o->starts_on?->format('Y-m-d'), 'to' => $o->ends_on?->format('Y-m-d'), 'reply_by' => $o->reply_by?->format('Y-m-d')]
                + ($code === 'opp_add' ? ['ready' => $o->oneTapReady()] : []) : null,
            // The finding the press hides, by its key (what it's about): never its title or amount, which a night's run
            // brings up to date (the nights a check has failed, interest added to a penalty), so an alert's only button
            // still works the next day. Back again after it had gone (reopened) is a new one.
            'fnd_snooze', 'fnd_dismiss' => preg_match('/^(\d+):/', $id, $m) && ($f = Finding::query()->find((int) $m[1])) ? $f->only(['key', 'code', 'reopened']) : null,
            'trips_add' => TravelUnclaimed::keyFor($id), // the id itself binds the jobs, the vehicle and the miles (TodoActions::tripsAdd)
            'det_raise', 'det_lower' => DetectorLevels::levelOf(str_replace(':', '.', $id)),
            'veto_lift' => ($v = Veto::query()->find($int)) ? ['key' => $v->veto_key, 'lifted' => $v->lifted_at !== null] : null,
            'qx' => self::quick($id),
            default => null,
        };
    }

    /**
     * An entry from your phone as a button acts on it: its amount, currency, words, date, job, category, how it was paid,
     * its state, its line and the photo's check; for attaching it to a line or "It was the card", that line's decision too.
     */
    private static function quick(string $id): ?array
    {
        $p = QuickEntries::parseAction($id);
        $e = $p ? QuickEntry::query()->find($p[0]) : null;
        if (! $e) {
            return null;
        }
        $state = $e->only(['amount_minor', 'currency', 'what', 'work_job_id', 'category_id', 'paid_with', 'status', 'bank_transaction_id', 'business_pct', 'document_id'])
            + ['on' => $e->spent_on?->format('Y-m-d'), 'read' => ($e->read_check['differs'] ?? []) ?: null]; // a read that agrees changes nothing shown
        if (in_array($p[1], ['attach', 'to_card'], true) && ctype_digit((string) $p[2])) {
            $state['line'] = self::tx((int) $p[2]);
        }

        return $state;
    }

    /**
     * A job as its buttons act on it: its status and when it was done, what a cancellation owes, its dates and days
     * (with their times), and its invoices (id and status).
     */
    private static function job(int $id): ?array
    {
        $job = WorkJob::query()->with('days')->find($id);
        if ($job === null) {
            return null;
        }

        return [
            'status' => $job->status, 'done_at' => $job->done_at?->format('Y-m-d H:i:s'), 'cancel' => [$job->cancel_charge, $job->cancel_fee_pence],
            'dates' => [$job->starts_on?->format('Y-m-d'), $job->ends_on?->format('Y-m-d'), $job->start_time, $job->end_time],
            'days' => $job->days->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->start_time, $d->end_time])->sortBy(0)->values()->all(),
            'invoices' => Invoice::query()->where('work_job_id', $id)->orderBy('id')->get(['id', 'status'])->map(fn ($i) => [$i->id, $i->status])->all(),
        ];
    }

    /** The job's last status change (its event id), and the draft it made with what you've done on it since. */
    private static function lastStatus(int $job): ?array
    {
        $event = AuditEvent::query()->where('subject_type', 'WorkJob')->where('subject_id', $job)
            ->whereIn('action', ['job.done', 'job.cancelled', 'job.reopened', 'job.uncancelled'])->orderByDesc('id')->first(['id', 'after']);
        if ($event === null) {
            return null;
        }
        $made = is_array($event->after) && is_numeric($event->after['draft_made'] ?? null) ? (int) $event->after['draft_made'] : null;
        $draft = $made !== null ? Invoice::query()->find($made, ['id', 'status', 'lines_seen_event_id']) : null;

        return ['event' => $event->id, 'draft' => $draft ? [$draft->id, $draft->status, $draft->lines_seen_event_id] : null];
    }

    /** What closing an invoice and saying what replaced it changed, only when set (so an invoice without keeps its hash). */
    private static function closing(Invoice $invoice): array
    {
        return array_filter(['closed' => $invoice->closed_minor !== null ? [$invoice->closed_minor, $invoice->closed_reason] : null,
            'replaced_by' => $invoice->replaced_by_id], fn ($v) => $v !== null);
    }

    /**
     * The invoice's emails open in Gmail now (id, kind, status, draft message id), only when there are any: an old
     * "Email it" or "Send a reminder" is refused once Expend has put the invoice (or a reminder) in your drafts.
     */
    private static function openEmails(Invoice $invoice): array
    {
        $open = InvoiceEmail::query()->open()->where('invoice_id', $invoice->id)->orderBy('id')->get(['id', 'kind', 'status', 'draft_message_id'])
            ->map(fn (InvoiceEmail $e) => [$e->id, $e->kind, $e->status, $e->draft_message_id])->all();

        return $open === [] ? [] : ['emails' => $open];
    }

    /** An invoice as income sees it: its status, total, closing and replacement, and what's still to come. */
    private static function invoiceState(Invoice $invoice): array
    {
        $r = in_array($invoice->status, InvoicePayments::PAYABLE, true) ? (InvoicePayments::received([$invoice])[$invoice->id] ?? InvoicePayments::EMPTY) : InvoicePayments::EMPTY;

        return $invoice->only(['id', 'status', 'number', 'total_minor', 'currency', 'client_id', 'work_job_id', 'closed_minor', 'closed_reason', 'replaced_by_id'])
            + array_intersect_key($r, array_flip(['paid', 'paid_linked', 'refunded', 'waiting', 'outstanding', 'over', 'state']));
    }

    /** An invoice's email of this kind as a button acts on it: the latest row, its draft, the invoice and what's to come. */
    private static function emailState(int $invoiceId, string $kind): ?array
    {
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            return null;
        }
        $row = InvoiceEmail::query()->where('invoice_id', $invoiceId)->where('kind', $kind)->latest('id')->first(['id', 'status', 'draft_message_id', 'current_message_id', 'to_address']);

        return ['invoice' => self::invoiceState($invoice) + ['sent' => $invoice->sent_at?->getTimestamp(), 'reminded' => $invoice->reminded_at?->getTimestamp()],
            'row' => $row ? [$row->id, $row->status, $row->draft_message_id, $row->current_message_id, $row->to_address] : null];
    }

    /** A cost as its buttons act on it: your decision about it, and the record behind it as it stands. */
    private static function cost(string $code, string $id): ?array
    {
        if ($code === 'cost_invoice_now' || $code === 'cost_clear') {
            $ref = CostRef::fromId($id);
        } else {
            $ref = self::costRef($code, $id);
        }
        if ($ref === null) {
            return null;
        }
        $parsed = CostRef::parse($ref);
        $subject = match ($parsed['type']) {
            'tx' => self::tx($parsed['id']),
            'exp' => SourceState::ofExpense($parsed['id']),
            'travel' => TravelItem::query()->find($parsed['id'])?->only(['status', 'decision', 'work_job_id', 'bank_transaction_id', 'amount_minor', 'currency']),
            'trip' => ($t = MileageTrip::query()->find($parsed['id'])) ? ['job' => $t->work_job_id, 'miles' => (string) $t->miles, 'on' => $t->trip_on?->format('Y-m-d')] : null,
            default => ['ref' => $ref],
        };
        if ($subject === null) {
            return null;
        }
        $row = CostCharge::query()->where('cost_ref', $ref)->first(['charge', 'client_id', 'invoice_id', 'note', 'pair', 'pair_ref', 'outcome']);
        $invoice = $code === 'cost_invoiced' && preg_match('/:(\d+)$/', $id, $m) === 1 && ($i = Invoice::query()->find((int) $m[1])) ? $i->only(['id', 'status', 'number']) : null;

        return ['ref' => $ref, 'subject' => $subject, 'decision' => $row?->only(['charge', 'client_id', 'invoice_id', 'note', 'pair', 'pair_ref', 'outcome'])] + ($invoice ? ['invoice' => $invoice] : []);
    }

    /** What a possible job shows, and what adding it as a job would make it from. */
    private const CANDIDATE = ['status', 'title', 'client_name', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode', 'po_number'];

    /**
     * "Part of <job>" (id "<possible job>:<job>"): the possible job as shown, the job it would be added to (whose blank
     * details it would fill), and what you've said about its sources (CandidateText::refused), so a press made before
     * you said "Not this job" or "Not a job" is refused.
     */
    private static function merge(string $id): ?array
    {
        if (! preg_match('/^(\d+):(\d+)$/', $id, $m) || ! ($c = JobCandidate::query()->find((int) $m[1]))) {
            return null;
        }

        return [
            'candidate' => $c->only(self::CANDIDATE),
            'job' => WorkJob::query()->find((int) $m[2])?->only(['status', 'title', 'client_id', 'starts_on', 'ends_on', 'location', 'postcode', 'po_number', 'start_time', 'end_time']),
            'refused' => CandidateText::refused($c),
        ];
    }

    /**
     * A payment's decision as it stands: what each line is (with its own client when it has one), by whom, and whether
     * it's confirmed (SourceState, shared with "Confirm and issue" and "Shared with another job"; a line with no client
     * of its own keeps the hash it always had).
     */
    private static function tx(int $id): ?array
    {
        return SourceState::ofTransaction($id);
    }

    /** What "Always do this" for a payment's merchant would save: the rule there is now, or the one offered. */
    private static function ruleOffer(int $txId): ?array
    {
        $key = BankTransaction::query()->whereKey($txId)->value('merchant_key');
        if ($key === null) {
            return null;
        }
        $rule = MerchantRule::query()->where('merchant_key', $key)->first(['id', 'kind', 'category_id', 'business_pct', 'direction']);
        $offer = $rule ? null : app(RuleLearner::class)->offer($key);

        return [
            'rule' => $rule?->only(['id', 'kind', 'category_id', 'business_pct', 'direction']),
            'declined' => in_array($key, (array) Setting::get('rule_offers_declined'), true),
            'offer' => $offer ? array_intersect_key($offer, array_flip(['kind', 'category_id', 'business_pct', 'direction'])) : null,
        ];
    }
}
