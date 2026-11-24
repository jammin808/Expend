<?php

namespace App\Domain\Todo;

use App\Models\Finding;
use App\Models\TodoDismissal;
use Illuminate\Support\Carbon;

/**
 * Keeps a history of what the to-do list finds: money you may be owed, gaps with the bank, missing POs, duplicate
 * charges, failed checks, unpaid invoices and the rest. Each finding is recorded when first seen, and marked
 * resolved when it no longer appears (or snoozed or dismissed if you hid it), so you can see what was found and
 * dealt with, and the money involved. Routine items (payments to decide, rule offers) aren't findings.
 */
final class FindingsRegistry
{
    public const ROUTINE = ['payments', 'rules', 'transfers', 'stays'];

    /** @return array{new:int, resolved:int, open:int} */
    public static function sync(): array
    {
        $now = Carbon::now();
        $current = collect(TodoList::build(1000)['items'])->reject(fn ($i) => in_array($i['group'], self::ROUTINE, true))->keyBy('key');
        $existing = Finding::query()->whereIn('key', $current->keys())->get()->keyBy('key');
        $new = 0;
        foreach ($current as $key => $i) {
            $fields = ['group' => $i['group'], 'title' => mb_substr($i['title'], 0, 255), 'detail' => $i['detail'], 'href' => $i['href'] ? mb_substr($i['href'], 0, 255) : null,
                'amount_pence' => isset($i['amount']) ? (int) $i['amount'] : null, 'last_seen_at' => $now];
            $row = $existing[$key] ?? null;
            if (! $row) {
                Finding::query()->create(['key' => mb_substr($key, 0, 191), 'first_seen_at' => $now] + $fields);
                $new++;
            } else {
                $reopen = $row->resolved_at !== null;
                $row->update($fields + ($reopen ? ['resolved_at' => null, 'resolution' => null, 'reopened' => $row->reopened + 1] : []));
            }
        }
        $hidden = TodoDismissal::query()->get()->keyBy('key');
        $resolved = 0;
        Finding::query()->whereNull('resolved_at')->whereNotIn('key', $current->keys()->all() ?: [''])->get()
            ->each(function (Finding $f) use ($hidden, $now, &$resolved) {
                $d = $hidden[$f->key] ?? null;
                $f->update(['resolved_at' => $now, 'resolution' => $d ? ($d->until ? 'snoozed' : 'dismissed') : 'resolved']);
                $resolved++;
            });

        return ['new' => $new, 'resolved' => $resolved, 'open' => $current->count()];
    }

    /** @return array{open:int, resolved:int, money_open:int, money_resolved:int, by_group:array, recent:list<array>} */
    public static function summary(?string $since = null): array
    {
        $since ??= Carbon::now()->subYear()->format('Y-m-d');
        $open = Finding::query()->whereNull('resolved_at');
        $done = Finding::query()->where('resolution', 'resolved')->where('resolved_at', '>=', $since);
        $money = fn ($q) => (int) (clone $q)->get(['amount_pence'])->sum(fn ($f) => abs((int) $f->amount_pence));

        return [
            'open' => (clone $open)->count(),
            'resolved' => (clone $done)->count(),
            'money_open' => $money($open),
            'money_resolved' => $money($done),
            'by_group' => Finding::query()->where('first_seen_at', '>=', $since)->selectRaw('`group`, COUNT(*) n, SUM(CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END) done')
                ->groupBy('group')->get()->map(fn ($r) => ['group' => $r->group, 'label' => TodoList::GROUPS[$r->group] ?? $r->group, 'found' => (int) $r->n, 'dealt_with' => (int) $r->done])->values()->all(),
            'recent' => Finding::query()->orderByDesc('updated_at')->limit(100)->get()->map(fn (Finding $f) => [
                'key' => $f->key, 'group' => TodoList::GROUPS[$f->group] ?? $f->group, 'title' => $f->title, 'detail' => $f->detail, 'href' => $f->href,
                'amount_pence' => $f->amount_pence, 'first_seen' => $f->first_seen_at->format('Y-m-d'), 'resolved' => $f->resolved_at?->format('Y-m-d'),
                'resolution' => $f->resolution, 'days_open' => (int) $f->first_seen_at->diffInDays($f->resolved_at ?? Carbon::now()), 'reopened' => $f->reopened,
            ])->all(),
        ];
    }
}
