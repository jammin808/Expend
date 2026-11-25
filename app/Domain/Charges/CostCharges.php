<?php

namespace App\Domain\Charges;

use App\Domain\Audit\AuditLog;
use App\Domain\Clients\ClientIds;
use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Your own decisions about costs (cost_charges): whether one is charged to the client (recharge), not charged
 * (no_charge), charged another way (elsewhere, or claimed on their expense form), or was on a given invoice (invoiced);
 * whether two records are the same cost (pair); and whether a cost you claimed was paid back (outcome).
 *
 * Yours only: refused for automation (a policy, a matcher, the AI); a Telegram tap counts as you. Each is audited:
 * cost.charge_set, cost.claimed, cost.charge_cleared, cost.paired and cost.outcome_set. A row's audit_event_id is the
 * event that last set its charge, so the newest decision is known.
 *
 * A cost can have decisions under several refs (its own and its aliases: a booking and its payment). The newest wins,
 * counting a booking you decided 'recharge' (travel_items.decided_at), with nothing backfilled. A decision under a ref
 * the cost no longer has (its payment moved to another job) stays with the payment (gone()) and is never applied to
 * the cost it became.
 *
 * A cost on a job is recharged to that job's paying client only (it goes on the job's invoices): another client is
 * refused, saying to recharge it there or move it to one of their jobs.
 *
 * None of these is a ledger write, so a locked year doesn't stop them. "Recharge to Acme" (rechargeToClient) also
 * counts a job-less cost in Acme's costs when its payment is confirmed in an open year (DecisionService::
 * setExpenseClient); otherwise it says why it doesn't.
 */
final class CostCharges
{
    /** What claimed() notes, so a cost claimed on the client's expense form reads as that. */
    public const CLAIMED_NOTE = 'Claimed on their expense form';

    /**
     * Your decision about a cost.
     *
     * @param  int|null  $clientId  for a recharge: who it's charged to (a cost for a client with no job: that client by default)
     * @param  int|null  $invoiceId  for 'invoiced': the invoice it was on (issued or paid)
     *
     * @throws InvalidArgumentException
     */
    public function decide(string $ref, string $charge, ?int $clientId = null, ?int $invoiceId = null, ?string $note = null): CostCharge
    {
        self::yours('decide how a cost is charged');
        self::assertCost($ref);
        if (! in_array($charge, CostCharge::CHARGES, true)) {
            throw new InvalidArgumentException("A cost can't be charged '{$charge}'.");
        }
        $parsed = CostRef::parse($ref);
        $clientId = $charge === 'recharge' ? ($clientId ?? $parsed['client']) : null;
        if ($clientId !== null) {
            if (! Client::query()->whereKey($clientId)->exists()) {
                throw new InvalidArgumentException('That client no longer exists: pick another.');
            }
            $clientId = ClientIds::resolve($clientId);
            self::assertJobsClient($ref, $clientId);
        }
        if ($charge === 'invoiced') {
            $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId, ['id', 'status', 'number']);
            if ($invoice === null || ! in_array($invoice->status, InvoicePayments::PAYABLE, true)) {
                throw new InvalidArgumentException('Pick the issued invoice it was on.');
            }
        } else {
            $invoiceId = null;
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : null;

        return $this->write($ref, 'cost.charge_set', ['charge' => $charge, 'client_id' => $clientId, 'invoice_id' => $invoiceId, 'note' => $note, 'outcome' => null, 'outcome_at' => null]);
    }

    /** A cost you charged on the client's own expense form: charged another way, noted as claimed. */
    public function claimed(string $ref, ?string $note = null): CostCharge
    {
        self::yours('mark a cost as claimed');
        self::assertCost($ref);
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : self::CLAIMED_NOTE;

        return $this->write($ref, 'cost.claimed', ['charge' => 'elsewhere', 'client_id' => null, 'invoice_id' => null, 'note' => $note, 'outcome' => null, 'outcome_at' => null]);
    }

    /**
     * Whether a cost you charged another way (or claimed) was paid back: paid_back or not_paid. Not paid back, it stays
     * an ordinary expense.
     */
    public function outcome(string $ref, string $outcome): CostCharge
    {
        self::yours('say whether a cost was paid back');
        self::assertCost($ref);
        if (! in_array($outcome, CostCharge::OUTCOMES, true)) {
            throw new InvalidArgumentException("A claimed cost can't be '{$outcome}'.");
        }

        return DB::transaction(function () use ($ref, $outcome) {
            AuditLog::lockHead();
            $row = CostCharge::query()->where('cost_ref', $ref)->lockForUpdate()->first();
            if ($row === null || $row->charge !== 'elsewhere') {
                throw new InvalidArgumentException('Only a cost charged another way, or claimed, can be paid back.');
            }
            $before = $row->only(['outcome']);
            $row->fill(['outcome' => $outcome, 'outcome_at' => now()])->save();
            AuditLog::record('cost.outcome_set', $row, $before, $row->only(['cost_ref', 'outcome']));

            return $row;
        });
    }

    /**
     * Forget your decision about a cost: it's charged by the arrangement again. A pairing you made stays.
     *
     * @return CostCharge|null the row, when a pairing or outcome keeps it
     */
    public function clear(string $ref): ?CostCharge
    {
        self::yours('clear how a cost is charged');
        self::assertCost($ref);

        return DB::transaction(function () use ($ref) {
            AuditLog::lockHead();
            $row = CostCharge::query()->where('cost_ref', $ref)->lockForUpdate()->first();
            if ($row === null || $row->charge === null) {
                return $row;
            }
            $before = $row->only(['cost_ref', 'charge', 'client_id', 'invoice_id', 'note', 'outcome']);
            if ($row->pair === null) {
                $id = (int) $row->id;
                $row->delete();
                AuditLog::record('cost.charge_cleared', 'CostCharge', $before, null, null, null, $id);

                return null;
            }
            $row->fill(['charge' => null, 'client_id' => null, 'invoice_id' => null, 'note' => null, 'outcome' => null, 'outcome_at' => null])->save();
            $event = AuditLog::record('cost.charge_cleared', $row, $before, $row->only(['cost_ref', 'charge']));
            $row->forceFill(['audit_event_id' => $event->id])->save();

            return $row;
        });
    }

    /**
     * Two records of one cost ('same': they count once, the second as the first's alias) or not ('distinct': they
     * aren't flagged again). Filed under $ref, naming $other.
     */
    public function pair(string $ref, string $other, string $pair): CostCharge
    {
        self::yours('say whether two costs are the same');
        self::assertCost($ref);
        self::assertCost($other);
        if ($ref === $other) {
            throw new InvalidArgumentException('A cost is always the same as itself.');
        }
        if (! in_array($pair, CostCharge::PAIRS, true)) {
            throw new InvalidArgumentException("Two costs can't be '{$pair}'.");
        }

        return DB::transaction(function () use ($ref, $other, $pair) {
            AuditLog::lockHead();
            $rows = CostCharge::query()->whereIn('cost_ref', [$ref, $other])->orderBy('id')->lockForUpdate()->get()->keyBy('cost_ref');
            // One answer for the two: one filed the other way round is replaced by this one.
            $mirror = $rows[$other] ?? null;
            if ($mirror !== null && $mirror->pair_ref === $ref) {
                $mirror->charge === null && $mirror->outcome === null ? $mirror->delete() : $mirror->fill(['pair' => null, 'pair_ref' => null])->save();
            }
            $row = $rows[$ref] ?? new CostCharge(['cost_ref' => $ref, 'subject_ref' => CostRef::subject($ref)]);
            $before = $row->exists ? $row->only(['pair', 'pair_ref']) : null;
            $row->fill(['pair' => $pair, 'pair_ref' => $other])->save();
            AuditLog::record('cost.paired', $row, $before, $row->only(['cost_ref', 'pair', 'pair_ref']));

            return $row;
        });
    }

    /**
     * "Recharge to Acme": the cost goes on the client's next draft. For a cost with no job whose payment is confirmed
     * in an open year, it's also counted in the client's costs (its lines name the client: setExpenseClient).
     *
     * @return array{row:CostCharge, counted:bool, message:string}
     */
    public function rechargeToClient(BankTransaction|ManualExpense $source, int $clientId, ?string $note = null): array
    {
        self::yours('recharge a cost to a client');
        if (! Client::query()->whereKey($clientId)->exists()) {
            throw new InvalidArgumentException('That client no longer exists: pick another.');
        }
        $client = Client::query()->findOrFail(ClientIds::resolve($clientId));
        $lines = $source->allocations()->where('kind', 'business_expense')->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Decide it as a business cost first, then recharge it.');
        }
        $jobs = $lines->pluck('work_job_id')->filter()->unique();
        if ($lines->every(fn (Allocation $a) => $a->work_job_id !== null)) {
            if ($jobs->count() !== 1) {
                throw new InvalidArgumentException('It’s on more than one job: recharge it from each job’s draft.');
            }
            $ref = $source instanceof BankTransaction ? CostRef::tx((int) $source->id, (int) $jobs->first()) : CostRef::exp((int) $source->id, (int) $jobs->first());
            $row = $this->decide($ref, 'recharge', $clientId, null, $note);

            return ['row' => $row, 'counted' => true, 'message' => 'Charged to '.$client->name.': it goes on the job’s next draft.'];
        }
        $ref = $source instanceof BankTransaction ? CostRef::tx((int) $source->id, null, (int) $client->id) : CostRef::exp((int) $source->id, null, (int) $client->id);
        $row = $this->decide($ref, 'recharge', (int) $client->id, null, $note);
        $year = app(AllocationWriter::class)->taxYearOf($source);
        $next = 'It goes on '.$client->name.'’s next draft.';
        if (TaxYear::query()->where('label', $year)->first()?->isLocked()) {
            return ['row' => $row, 'counted' => false, 'message' => "The {$year} records stay as filed. This cost is charged to {$client->name} but isn’t counted in {$client->name}’s {$year} costs. ".$next];
        }
        if ($source->allocations()->get()->contains(fn (Allocation $a) => ! $a->is_manual && $a->confirmed_at === null)) {
            return ['row' => $row, 'counted' => false, 'message' => 'Charged to '.$client->name.'. Confirm the payment to count it in '.$client->name.'’s costs. '.$next];
        }
        app(DecisionService::class)->setExpenseClient($source, (int) $client->id);

        return ['row' => $row, 'counted' => true, 'message' => 'Charged to '.$client->name.' and counted in their costs. '.$next];
    }

    /**
     * The decision that applies to each cost: the newest across its ref and its aliases, counting a booking you
     * decided 'recharge'. Null for a cost you haven't decided.
     *
     * @param  list<array{ref:string, aliases?:list<string>}>  $units
     * @return array<string, array{charge:string, client_id:?int, invoice_id:?int, note:?string, at:?CarbonImmutable, from:string, booking:bool, id:?int}|null> by ref
     */
    public static function winning(array $units): array
    {
        $refs = collect($units)->flatMap(fn (array $u) => [$u['ref'], ...($u['aliases'] ?? [])])->unique()->values();
        $rows = collect();
        foreach ($refs->chunk(500) as $chunk) {
            $rows = $rows->concat(CostCharge::query()->whereIn('cost_ref', $chunk->values()->all())->whereNotNull('charge')->get());
        }
        $rows = $rows->keyBy('cost_ref');
        $at = AuditEvent::query()->whereIn('id', $rows->pluck('audit_event_id')->filter()->values()->all() ?: [0])->pluck('occurred_at', 'id');
        $bookings = TravelItem::query()->whereIn('id', $refs->filter(fn ($r) => str_starts_with($r, 'travel:'))->map(fn ($r) => (int) substr($r, 7))->values()->all() ?: [0])
            ->where('decision', 'recharge')->whereNotNull('decided_at')->get(['id', 'decided_at'])->keyBy('id');
        $out = [];
        foreach ($units as $u) {
            $best = null;
            foreach ([$u['ref'], ...($u['aliases'] ?? [])] as $ref) {
                $candidates = [];
                if (isset($rows[$ref])) {
                    $r = $rows[$ref];
                    $when = $r->audit_event_id !== null && isset($at[$r->audit_event_id]) ? CarbonImmutable::parse($at[$r->audit_event_id]) : CarbonImmutable::parse($r->updated_at);
                    $candidates[] = ['charge' => $r->charge, 'client_id' => $r->client_id, 'invoice_id' => $r->invoice_id, 'note' => $r->note, 'at' => $when,
                        'from' => $ref, 'booking' => false, 'id' => (int) $r->id, 'order' => (int) ($r->audit_event_id ?? 0)];
                }
                if (str_starts_with($ref, 'travel:') && isset($bookings[(int) substr($ref, 7)])) {
                    $candidates[] = ['charge' => 'recharge', 'client_id' => null, 'invoice_id' => null, 'note' => null,
                        'at' => CarbonImmutable::parse($bookings[(int) substr($ref, 7)]->decided_at), 'from' => $ref, 'booking' => true, 'id' => null, 'order' => -1];
                }
                foreach ($candidates as $c) {
                    // Newest first; at the same moment, your decision about the cost beats the booking's.
                    if ($best === null || [$c['at']->getTimestamp(), $c['order']] > [$best['at']->getTimestamp(), $best['order']]) {
                        $best = $c;
                    }
                }
            }
            if ($best !== null) {
                unset($best['order']);
            }
            $out[$u['ref']] = $best;
        }

        return $out;
    }

    /**
     * What you said about pairs of costs that may be the same, both ways round.
     *
     * @param  list<string>  $refs
     * @return array<string, array<string, string>> ref => other ref => same|distinct
     */
    public static function pairs(array $refs): array
    {
        $refs = array_values(array_unique($refs));
        $out = [];
        foreach (array_chunk($refs, 400) as $chunk) {
            $rows = CostCharge::query()->whereNotNull('pair')->where(fn ($q) => $q->whereIn('cost_ref', $chunk)->orWhereIn('pair_ref', $chunk))->get(['cost_ref', 'pair', 'pair_ref']);
            foreach ($rows as $r) {
                $out[$r->cost_ref][$r->pair_ref] = $r->pair;
                $out[$r->pair_ref][$r->cost_ref] = $r->pair;
            }
        }

        return $out;
    }

    /**
     * Your decisions about a payment, expense, booking or trip that no longer apply: filed under a ref its costs don't
     * have now (it moved to another job, or isn't a cost any more). Shown on the payment; never applied to the new job.
     *
     * @return Collection<int, CostCharge>
     */
    public static function gone(string $subject): Collection
    {
        $current = array_flip(CostUnits::refsOf($subject));

        return CostCharge::query()->where('subject_ref', $subject)->whereNotNull('charge')->orderBy('id')->get()
            ->reject(fn (CostCharge $r) => isset($current[$r->cost_ref]))->values();
    }

    /**
     * One decision written: the row for the ref, made or changed, audited, and the event kept as the row's newest.
     *
     * @param  array<string, mixed>  $fields
     */
    private function write(string $ref, string $action, array $fields): CostCharge
    {
        return DB::transaction(function () use ($ref, $action, $fields) {
            AuditLog::lockHead();
            $row = CostCharge::query()->where('cost_ref', $ref)->lockForUpdate()->first()
                ?? new CostCharge(['cost_ref' => $ref, 'subject_ref' => CostRef::subject($ref)]);
            $keys = ['charge', 'client_id', 'invoice_id', 'note'];
            $before = $row->exists ? $row->only($keys) : null;
            $row->fill($fields)->save();
            $event = AuditLog::record($action, $row, $before, ['cost_ref' => $ref] + $row->only($keys));
            $row->forceFill(['audit_event_id' => $event->id])->save();

            return $row;
        });
    }

    /**
     * A cost on a job goes on that job's invoices, to the job's paying client: recharging it to another client is
     * refused (move it to one of their jobs, or off the job, first).
     */
    private static function assertJobsClient(string $ref, int $clientId): void
    {
        $parsed = CostRef::parse($ref);
        $jobId = match ($parsed['type']) {
            'tx', 'exp' => $parsed['job'],
            'travel' => TravelItem::query()->whereKey($parsed['id'])->value('work_job_id'),
            'trip' => MileageTrip::query()->whereKey($parsed['id'])->value('work_job_id'),
            default => null,
        };
        $job = $jobId === null ? null : WorkJob::query()->find((int) $jobId, ['id', 'title', 'client_id']);
        if ($job === null) {
            return;
        }
        $jobClient = $job->client_id === null ? null : ClientIds::resolve((int) $job->client_id);
        if ($jobClient === $clientId) {
            return;
        }
        $name = Client::query()->whereKey($clientId)->value('name') ?? 'that client';
        throw new InvalidArgumentException($jobClient === null
            ? 'It’s on '.$job->title.', which has no client yet: give the job its client first, or move the cost to one of '.$name.'’s jobs.'
            : 'It’s on '.$job->title.' for '.(Client::query()->whereKey($jobClient)->value('name') ?? 'another client').': recharge it there, or move it to one of '.$name.'’s jobs.');
    }

    /** Only a cost's decision: a payment's, an expense's, a booking's or a trip's (not a job's work or a PO's line). */
    private static function assertCost(string $ref): void
    {
        if (! CostRef::valid($ref) || ! in_array(CostRef::parse($ref)['type'], ['tx', 'exp', 'travel', 'trip'], true)) {
            throw new InvalidArgumentException('That isn’t a cost Expend knows.');
        }
    }

    /** Decisions about costs are yours: never automation's. A Telegram tap counts as you. */
    private static function yours(string $what): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException("Only you can {$what}.");
        }
    }
}
