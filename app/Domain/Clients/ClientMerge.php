<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLog;
use App\Domain\Income\IncomeClient;
use App\Domain\Intel\AuditContext;
use App\Models\Allocation;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\TaxYear;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merging a duplicate client into another, and undoing it. Yours only (a tap in the app; never automation), each
 * audited with what it was before and after: client.merged and client.unmerged.
 *
 * A merge writes nothing in your records: the duplicate keeps its row with merged_into_id set, and everything that
 * names it (its jobs, invoices, payments) counts as the client it went into when read (ClientIds, IncomeClient).
 * It also:
 *  - re-points clients already merged into the duplicate, so a merge is always one step;
 *  - fills the kept client's empty details (name on invoices, billing email and address, terms, how invoices go,
 *    notes) from the duplicate's;
 *  - moves the duplicate's usual charge arrangements, only for kinds of cost the kept client has none for.
 * Unmerging clears merged_into_id; what was filled or moved stays with the kept client.
 *
 * A locked year changes nothing (your binding answer), also for moving income between clients: both are refused
 * while the duplicate has confirmed records in a locked tax year that would count for another client.
 */
final class ClientMerge
{
    /** The details a merge fills on the kept client when it has none. */
    public const DETAILS = ['invoice_name', 'billing_email', 'billing_address', 'payment_terms_days', 'invoice_route', 'notes'];

    /**
     * How many times a transaction is tried when the database ends it to break a deadlock (another writer took the same
     * rows the other way round): it reads everything again under its locks, so trying again is safe.
     */
    private const ATTEMPTS = 3;

    /**
     * Merge $from into $into: from then on, $from counts as $into.
     *
     * @param  string|null  $fp  fingerprint() of both clients as you were shown them: refused when either changed since
     *
     * @throws InvalidArgumentException when it can't be merged (with the reason in plain words)
     */
    public function merge(Client $from, Client $into, ?string $reason = null, ?string $fp = null): Client
    {
        self::yours('merge clients');
        if ((int) $from->id === (int) $into->id) {
            throw new InvalidArgumentException('A client can’t be merged into itself.');
        }

        return DB::transaction(function () use ($from, $into, $reason, $fp) {
            AuditLog::lockHead();
            $rows = Client::query()->whereKey([$from->id, $into->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $rows[$from->id] ?? throw new InvalidArgumentException('That client no longer exists.');
            $into = $rows[$into->id] ?? throw new InvalidArgumentException('That client no longer exists.');
            if ($fp !== null && ! hash_equals(self::fingerprint($from, $into), $fp)) {
                throw new InvalidArgumentException('This changed since: open it in Expend. Nothing was done.');
            }
            if ($into->merged_into_id !== null) {
                $kept = Client::query()->find($into->merged_into_id);
                throw new InvalidArgumentException("{$into->name} is merged into ".($kept?->name ?? 'another client').': merge into '.($kept?->name ?? 'that client').' instead.');
            }
            if ($from->merged_into_id !== null) {
                if ((int) $from->merged_into_id === (int) $into->id) {
                    return $into; // already merged into it
                }
                $other = Client::query()->find($from->merged_into_id);
                throw new InvalidArgumentException("{$from->name} is already merged into ".($other?->name ?? 'another client').': unmerge it first.');
            }
            self::assertOpen($from, true);

            $repointed = Client::query()->where('merged_into_id', $from->id)->orderBy('id')->lockForUpdate()->get();
            $before = ['merged_into_id' => null, 'into' => $into->id, 'into_details' => $into->only(self::DETAILS)];

            $from->forceFill(['merged_into_id' => $into->id])->save();
            foreach ($repointed as $c) {
                $c->forceFill(['merged_into_id' => $into->id])->save();
            }

            $filled = [];
            foreach (self::DETAILS as $field) {
                if (self::blank($into->{$field}) && ! self::blank($from->{$field})) {
                    $filled[$field] = $from->{$field};
                }
            }
            if ($filled !== []) {
                $into->forceFill($filled)->save();
            }

            $have = ChargeTerm::query()->ownedBy($into)->pluck('class')->flip();
            $moved = [];
            foreach (ChargeTerm::query()->ownedBy($from)->orderBy('id')->get() as $term) {
                if (isset($have[$term->class])) {
                    continue; // the kept client's own arrangement for this kind of cost stays
                }
                $term->forceFill(['client_id' => $into->id, 'owner_key' => ChargeTerm::ownerKey($into)])->save();
                $moved[] = $term->class;
            }

            AuditLog::record('client.merged', $from, $before, array_filter([
                'merged_into_id' => $into->id,
                're_pointed' => $repointed->pluck('id')->map(fn ($id) => (int) $id)->all() ?: null,
                'filled' => $filled ?: null,
                'terms_moved' => $moved ?: null,
            ], fn ($v) => $v !== null), $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 250) : null);

            return $into->fresh();
        }, self::ATTEMPTS);
    }

    /**
     * Undo a merge: the client counts as itself again. Details filled and arrangements moved by the merge stay with
     * the client it was merged into.
     *
     * @throws InvalidArgumentException when it isn't merged, or a locked year would change
     */
    public function unmerge(Client $client, ?string $reason = null): Client
    {
        self::yours('unmerge a client');

        return DB::transaction(function () use ($client, $reason) {
            AuditLog::lockHead();
            $client = Client::query()->lockForUpdate()->find($client->id) ?? throw new InvalidArgumentException('That client no longer exists.');
            if ($client->merged_into_id === null) {
                throw new InvalidArgumentException("{$client->name} isn’t merged into another client.");
            }
            self::assertOpen($client, false);

            $before = ['merged_into_id' => (int) $client->merged_into_id];
            $client->forceFill(['merged_into_id' => null])->save();
            AuditLog::record('client.unmerged', $client, $before, ['merged_into_id' => null],
                $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 250) : null);

            return $client->fresh();
        }, self::ATTEMPTS);
    }

    /**
     * Both clients as a merge sheet shows them (names, details, what each is merged into, and the clients merged into
     * the duplicate), so a merge pressed on an out-of-date sheet is refused.
     */
    public static function fingerprint(Client $from, Client $into): string
    {
        $state = fn (Client $c) => [(int) $c->id, (string) $c->name, $c->merged_into_id === null ? null : (int) $c->merged_into_id, $c->only(self::DETAILS)];
        $children = Client::query()->where('merged_into_id', $from->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return substr(hash('sha256', json_encode([$state($from), $state($into), $children])), 0, 16);
    }

    /**
     * The locked tax years with confirmed records that a merge or unmerge would move to another client: those whose
     * worked-out client is this client (with the clients merged into it, for a merge), or, for an unmerge, those naming
     * it that count now for the client it was merged into.
     *
     * @return list<string> tax year labels, oldest first
     */
    public static function lockedYears(Client $client, bool $merging = true): array
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->orderBy('starts_on')->pluck('label')->all();
        if ($locked === []) {
            return [];
        }
        // IncomeClient's joins: the line's invoice (ic_inv), its own job (ic_job) and the client for merges (ic_cm).
        $query = IncomeClient::scope(Allocation::query()->select('allocations.tax_year'))
            ->whereNotNull('allocations.confirmed_at')->whereIn('allocations.tax_year', $locked);
        $query = $merging
            ? $query->whereRaw(IncomeClient::expression().' = ?', [$client->id])
            : $query->whereRaw('COALESCE(ic_inv.client_id, ic_job.client_id, allocations.client_id) = ?', [$client->id]);
        $years = $query->distinct()->pluck('tax_year')->all();

        return array_values(array_filter($locked, fn (string $label) => in_array($label, $years, true)));
    }

    private static function assertOpen(Client $client, bool $merging): void
    {
        $years = self::lockedYears($client, $merging);
        if ($years === []) {
            return;
        }
        $list = count($years) === 1 ? $years[0] : implode(', ', array_slice($years, 0, -1)).' and '.end($years);
        $one = count($years) === 1;

        throw new InvalidArgumentException("{$client->name} has records in {$list}, which ".($one ? 'is' : 'are').' locked: unlock '.($one ? 'it' : 'them').' with a reason first.');
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Merging and unmerging are yours: never automation's. A Telegram tap counts as you. */
    private static function yours(string $what): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException("Only you can {$what}.");
        }
    }
}
