<?php

namespace App\Domain\Charges;

use InvalidArgumentException;

/**
 * How a cost (or a job's work) is cited: on an invoice line (invoice_lines.source_ref), in your decision about it
 * (cost_charges.cost_ref) and on a to-do or Telegram button.
 *
 *  - tx:{id}#j{job}, exp:{id}#j{job}: a payment's (or a hand-entered expense's) business cost on a job. Cited by the
 *    bank line or the expense, never by its ledger lines: those are written afresh on every decision.
 *  - tx:{id}#c{client}, exp:{id}#c{client}: its cost for a client, with no job.
 *  - travel:{id}: a booking with no payment yet. trip:{id}: a mileage trip.
 *  - job:{id}#{part}: the job's own work (fee, days, hours, extra, tdays, tfee, meals, cancel).
 *  - doc:{id}#l{n}: a PO's line.
 *
 * A cost's subject is the record behind it (tx:381, exp:12, travel:7, trip:3, job:45): one payment split between two
 * jobs is two costs of one subject, so it's never charged twice. A booking and its payment, once linked or paired as
 * the same cost, are one subject too (the payment's): CostUnits gives the booking's ref as an alias.
 *
 * Button ids can't hold '#' (the to-do route takes [A-Za-z0-9_:]+), so toId() swaps it for ':' ('tx:381#j45' is
 * 'tx:381:j45') and fromId() swaps it back. An id stays well under Telegram's 64 bytes.
 */
final class CostRef
{
    /** The parts of a job's own work that make lines. */
    public const WORK_PARTS = ['fee', 'days', 'hours', 'extra', 'tdays', 'tfee', 'meals', 'cancel'];

    /** Every ref this design writes. */
    public const PATTERN = '/^(?:(?:tx|exp):\d{1,19}#[jc]\d{1,19}|(?:travel|trip):\d{1,19}|job:\d{1,19}#(?:fee|days|hours|extra|tdays|tfee|meals|cancel)|doc:\d{1,19}#l\d{1,4})$/';

    /** The to-do route's pattern for an action id. */
    public const ID_PATTERN = '/^[A-Za-z0-9_:]+$/';

    /** A payment's cost on a job, or for a client with no job. */
    public static function tx(int $id, ?int $job = null, ?int $client = null): string
    {
        return 'tx:'.$id.self::owner($job, $client);
    }

    /** A hand-entered expense's cost on a job, or for a client with no job. */
    public static function exp(int $id, ?int $job = null, ?int $client = null): string
    {
        return 'exp:'.$id.self::owner($job, $client);
    }

    public static function travel(int $id): string
    {
        return 'travel:'.$id;
    }

    public static function trip(int $id): string
    {
        return 'trip:'.$id;
    }

    /** A part of a job's own work: job:45#fee. */
    public static function work(int $job, string $part): string
    {
        if (! in_array($part, self::WORK_PARTS, true)) {
            throw new InvalidArgumentException("A job's work has no part '{$part}'.");
        }

        return 'job:'.$job.'#'.$part;
    }

    public static function valid(?string $ref): bool
    {
        return is_string($ref) && strlen($ref) <= 191 && preg_match(self::PATTERN, $ref) === 1;
    }

    /** The record behind a cost: 'tx:381' for 'tx:381#j45'. */
    public static function subject(string $ref): string
    {
        $hash = strpos($ref, '#');

        return $hash === false ? $ref : substr($ref, 0, $hash);
    }

    /**
     * A ref's parts: its type (tx, exp, travel, trip, job, doc), its record's id, and the job, client or part it names.
     *
     * @return array{type:string, id:int, job:?int, client:?int, part:?string}
     *
     * @throws InvalidArgumentException for something that isn't a ref
     */
    public static function parse(string $ref): array
    {
        if (! self::valid($ref)) {
            throw new InvalidArgumentException("'{$ref}' isn't a cost.");
        }
        [$head, $tail] = array_pad(explode('#', $ref, 2), 2, null);
        [$type, $id] = explode(':', $head, 2);
        $out = ['type' => $type, 'id' => (int) $id, 'job' => null, 'client' => null, 'part' => null];
        if ($tail !== null && in_array($type, ['tx', 'exp'], true)) {
            $out[$tail[0] === 'j' ? 'job' : 'client'] = (int) substr($tail, 1);
        } elseif ($tail !== null) {
            $out['part'] = $tail;
            if ($type === 'job') {
                $out['job'] = (int) $id;
            }
        }

        return $out;
    }

    /** A ref as a button id: '#' becomes ':' ('tx:381#j45' is 'tx:381:j45'). */
    public static function toId(string $ref): string
    {
        if (! self::valid($ref)) {
            throw new InvalidArgumentException("'{$ref}' isn't a cost.");
        }

        return str_replace('#', ':', $ref);
    }

    /** A button id back as its ref, or null when it isn't one. */
    public static function fromId(?string $id): ?string
    {
        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $parts = explode(':', $id);
        $ref = match (count($parts)) {
            2 => $id,
            3 => $parts[0].':'.$parts[1].'#'.$parts[2],
            default => null,
        };

        return self::valid($ref) ? $ref : null;
    }

    private static function owner(?int $job, ?int $client): string
    {
        if (($job === null) === ($client === null)) {
            throw new InvalidArgumentException('A cost is on one job, or for one client with no job.');
        }

        return $job !== null ? '#j'.$job : '#c'.$client;
    }
}
