<?php

namespace App\Domain\Intel;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * An evidence reference: one string that points at a record, used for storage, the to-do list, Telegram and the
 * findings history (docs/intelligence-cycle.md section 2.1):
 *
 *     ref     = kind ":" id [ "#" locator ] [ "@" pin ]        at most 191 characters, so it can be indexed
 *     locator = p3 | l12 | c120-188 | row41 | f=po_number
 *     pin     = 8 hex characters: a prefix of the content hash the claim relied on
 *
 * Only stable ids are cited: a bank line (tx:381), never an allocation (they're re-created on every decision); a
 * decision is cited by its audit event (audit:9120@1a2b3c4d). A settings key that's kept encrypted is never cited.
 */
final class Ref
{
    public const MAX = 191;

    /** The most refs a list keeps (a finding's evidence). */
    public const LIST_MAX = 20;

    public const KINDS = ['tx', 'exp', 'batch', 'acct', 'sbal', 'doc', 'gmail', 'cal', 'travel', 'job', 'jday', 'cand', 'jfind', 'trip', 'rule', 'inv', 'check', 'audit',
        'fx', 'law', 'taxp', 'year', 'run', 'fnd', 'ask', 'set', 'quick'];

    private const PATTERN = '/^([a-z]+):([^#@\s]+)(?:#([^@\s]+))?(?:@([0-9a-f]{8}))?$/';

    /** @param  string|null  $pin  a content hash (or its first 8 characters); kept as its first 8, in lower case */
    public static function make(string $kind, string|int $id, ?string $locator = null, ?string $pin = null): string
    {
        $pin = $pin !== null && $pin !== '' ? strtolower(substr($pin, 0, 8)) : null;
        $ref = $kind.':'.$id.($locator !== null && $locator !== '' ? '#'.$locator : '').($pin !== null ? '@'.$pin : '');
        if (! self::valid($ref)) {
            throw new InvalidArgumentException("Not a valid evidence ref: “{$ref}”.");
        }

        return $ref;
    }

    /** The ref, or null when there's nothing to point at (a null id). */
    public static function maybe(string $kind, string|int|null $id, ?string $locator = null, ?string $pin = null): ?string
    {
        return $id === null || $id === '' ? null : self::make($kind, $id, $locator, $pin);
    }

    /** @return array{kind:string, id:string, locator:?string, pin:?string}|null */
    public static function parse(?string $ref): ?array
    {
        if ($ref === null || strlen($ref) > self::MAX || ! preg_match(self::PATTERN, $ref, $m) || ! in_array($m[1], self::KINDS, true)) {
            return null;
        }
        if ($m[1] === 'set' && in_array($m[2], Setting::ENCRYPTED, true)) {
            return null; // never cited: its value is a secret
        }

        return ['kind' => $m[1], 'id' => $m[2], 'locator' => ($m[3] ?? '') !== '' ? $m[3] : null, 'pin' => ($m[4] ?? '') !== '' ? $m[4] : null];
    }

    public static function valid(?string $ref): bool
    {
        return self::parse($ref) !== null;
    }

    /** The kind of a ref ("tx"), or null for one that isn't valid. */
    public static function kind(?string $ref): ?string
    {
        return self::parse($ref)['kind'] ?? null;
    }

    /**
     * A ref list as it's stored: valid refs only, each once, in the order given, at most LIST_MAX.
     *
     * @param  iterable<?string>  $refs
     * @return list<string>
     */
    public static function list(iterable $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if ($ref !== null && self::valid($ref) && ! in_array($ref, $out, true)) {
                $out[] = $ref;
                if (count($out) === self::LIST_MAX) {
                    break;
                }
            }
        }

        return $out;
    }
}
