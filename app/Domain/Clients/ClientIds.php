<?php

namespace App\Domain\Clients;

use App\Domain\Income\IncomeClient;
use App\Domain\Jobs\JobCreator;
use App\Models\Client;
use App\Models\TodoDismissal;

/**
 * Which client a stored id means now that duplicates can be merged, worked out when read: a client you merged into
 * another keeps its row with merged_into_id set (always one step: ClientMerge re-points the clients merged into it),
 * so every id resolves to COALESCE(merged_into_id, id). Pickers and lists offer only clients that weren't merged.
 *
 * Also the clients that may be the same, to offer merging: a suggestion only, never acted on by itself.
 */
final class ClientIds
{
    /** The to-do key "Not the same" is remembered under, for a pair of clients (lower id first). */
    public const DUPE_PREFIX = 'client_dupe:';

    /** The client a merged duplicate was merged into, or the client itself (null stays null). */
    public static function resolve(?int $clientId): ?int
    {
        return IncomeClient::resolve($clientId);
    }

    /**
     * Several at once, with one query.
     *
     * @param  iterable<int|null>  $clientIds
     * @return array<int, int> by the id given: the id it means now
     */
    public static function resolveMany(iterable $clientIds): array
    {
        $ids = collect($clientIds)->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($ids->chunk(500) as $chunk) {
            $into = Client::query()->whereKey($chunk->all())->pluck('merged_into_id', 'id');
            foreach ($chunk as $id) {
                $out[$id] = isset($into[$id]) && $into[$id] !== null ? (int) $into[$id] : $id;
            }
        }

        return $out;
    }

    /**
     * A client and every client merged into it (for a merged client: the one it went into and its other duplicates):
     * the rows that count as one client.
     *
     * @return list<int>
     */
    public static function withMerged(int $clientId): array
    {
        $into = self::resolve($clientId) ?? $clientId;

        return Client::query()->whereKey($into)->orWhere('merged_into_id', $into)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The key a pair's "Not the same" is kept under: client_dupe:{lower id}:{higher id}. */
    public static function dupeKey(int $a, int $b): string
    {
        return self::DUPE_PREFIX.min($a, $b).':'.max($a, $b);
    }

    /**
     * Clients that may be the same: two that weren't merged with the same name once "Ltd", "Limited", "The" and
     * capitals are left out (JobCreator::clientKey), or the same billing email. Pairs you said aren't the same (a
     * "Not the same" kept as dupeKey()) are left out unless $all.
     *
     * @return list<array{a:int, b:int, why:string}> a is the lower id; why is 'name' or 'email'; oldest pairs first
     */
    public static function possibleDuplicates(bool $all = false): array
    {
        $clients = Client::query()->unmerged()->orderBy('id')->get(['id', 'name', 'billing_email']);
        $pairs = [];
        foreach (['name' => fn (Client $c) => JobCreator::clientKey($c->name), 'email' => fn (Client $c) => mb_strtolower(trim((string) $c->billing_email))] as $why => $key) {
            foreach ($clients->groupBy($key) as $k => $group) {
                if ($k === '' || $group->count() < 2) {
                    continue;
                }
                $ids = $group->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                for ($i = 0; $i < count($ids); $i++) {
                    for ($j = $i + 1; $j < count($ids); $j++) {
                        $pairs[self::dupeKey($ids[$i], $ids[$j])] ??= ['a' => $ids[$i], 'b' => $ids[$j], 'why' => $why];
                    }
                }
            }
        }
        if (! $all && $pairs !== []) {
            $notSame = TodoDismissal::query()->where('key', 'like', self::DUPE_PREFIX.'%')
                ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))->pluck('key')->flip();
            $pairs = array_filter($pairs, fn (string $key) => ! isset($notSame[$key]), ARRAY_FILTER_USE_KEY);
        }
        uasort($pairs, fn (array $x, array $y) => [$x['a'], $x['b']] <=> [$y['a'], $y['b']]);

        return array_values($pairs);
    }
}
