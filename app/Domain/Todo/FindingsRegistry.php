<?php

namespace App\Domain\Todo;

use App\Models\Finding;
use App\Models\TodoDismissal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a history of what the to-do list finds: money you may be owed, gaps with the bank, missing POs, duplicate
 * charges, failed checks, unpaid invoices and the rest. Each finding is recorded when first seen, and marked
 * resolved when it no longer appears (or snoozed or dismissed if you hid it), so you can see what was found and
 * dealt with, and the money involved. Routine items (payments to decide, rule offers) aren't findings.
 *
 * It reads every item (TodoList::everything()), not the quick list: an item past the list's limits (an old job, the
 * 51st duplicate charge) hasn't been dealt with, and one you snoozed is told apart from one that's gone. A snoozed
 * finding that's then dealt with counts as dealt with. A gap with the bank that's partly filled stays one finding,
 * however its dates and amount change, and the money dealt with is the most it involved (the whole gap, not what
 * was left of it at the end).
 */
final class FindingsRegistry
{
    public const ROUTINE = ['payments', 'rules', 'transfers', 'stays'];

    /** How it ended: dealt with, snoozed, marked not relevant, or (a gap) merged into another gap still open. */
    public const RESOLUTIONS = ['resolved', 'snoozed', 'dismissed', 'superseded'];

    /** When the findings were last recorded, and from what (TodoList::fingerprint()). */
    public const SYNCED_KEY = 'findings_synced';

    /**
     * Record what's found now. One at a time: the history page and the nightly run can overlap, and two at once
     * would both add the same new finding.
     *
     * @return array{new:int, resolved:int, open:int}
     */
    public static function sync(): array
    {
        try {
            return Cache::lock('findings-sync', 300)->block(20, fn () => self::record());
        } catch (LockTimeoutException) {
            // Another run is recording the same findings right now.
            return ['new' => 0, 'resolved' => 0, 'open' => Finding::query()->whereNull('resolved_at')->count()];
        }
    }

    /**
     * For the history page: record what's found now only if anything the to-do list is made from has changed since
     * the last time (or it's a new day), and never wait for another run. Working out every finding reads every job,
     * account and duplicate charge, so a page view that changes nothing doesn't do it again, and one that comes
     * while the nightly run (or another view) is recording shows what's recorded so far.
     *
     * @return bool whether they were recorded now
     */
    public static function refresh(): bool
    {
        $now = TodoList::fingerprint();
        if (Cache::get(self::SYNCED_KEY) === $now) {
            return false;
        }
        $lock = Cache::lock('findings-sync', 300);
        if (! $lock->get()) {
            return false;
        }
        try {
            self::record($now);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @param  ?string  $fingerprint  what the list was made from, taken before it's read (so a change made meanwhile
     *                                is recorded next time)
     * @return array{new:int, resolved:int, open:int}
     */
    private static function record(?string $fingerprint = null): array
    {
        $fingerprint ??= TodoList::fingerprint();
        $now = Carbon::now();
        $all = collect(TodoList::everything())->reject(fn ($i) => in_array($i['group'], self::ROUTINE, true))->keyBy(fn ($i) => mb_substr($i['key'], 0, 191));
        $shown = $all->reject(fn ($i) => $i['hidden'] ?? false);
        self::followGaps($all, $now);
        $existing = Finding::query()->whereIn('key', $all->keys())->get()->keyBy('key');

        $new = 0;
        foreach ($shown as $key => $i) {
            $fields = ['group' => $i['group'], 'title' => mb_substr($i['title'], 0, 255), 'detail' => $i['detail'], 'href' => $i['href'] ? mb_substr($i['href'], 0, 255) : null,
                'amount_pence' => isset($i['amount']) ? (int) $i['amount'] : null, 'currency' => self::currency($i)];
            $row = $existing[$key] ?? null;
            if (! $row) {
                $row = Finding::unguarded(fn () => Finding::query()->createOrFirst(['key' => $key], ['first_seen_at' => $now, 'last_seen_at' => $now, 'peak_amount_pence' => $fields['amount_pence']] + $fields));
                if ($row->wasRecentlyCreated) {
                    $new++;

                    continue;
                }
            }
            $row->fill($fields + ($row->resolved_at !== null ? ['resolved_at' => null, 'resolution' => null, 'reopened' => $row->reopened + 1] : []));
            // The most it involved: a gap partly filled has less left, but the gap was what it was.
            $peak = $row->peak_amount_pence === null ? null : (int) $row->peak_amount_pence;
            if ($fields['amount_pence'] !== null && ($peak === null || abs($fields['amount_pence']) > abs($peak))) {
                $row->forceFill(['peak_amount_pence' => $fields['amount_pence']]);
            }
            if ($row->isDirty()) {
                $row->save();
            }
        }
        // Seen again: noted without changing when the finding last changed, so the history lists what happened.
        foreach ($shown->keys()->chunk(500) as $keys) {
            Finding::query()->whereIn('key', $keys->values()->all())->toBase()->update(['last_seen_at' => $now]);
        }

        // Hidden by you: snoozed or marked not relevant, as you hid it now.
        $resolved = 0;
        $dismissals = TodoDismissal::query()->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', $now))->get()->keyBy('key');
        foreach ($all->filter(fn ($i) => $i['hidden'] ?? false) as $key => $i) {
            $row = $existing[$key] ?? null;
            $label = $dismissals->get($i['hidden_by'] ?? $key)?->until ? 'snoozed' : 'dismissed';
            if (! $row) {
                continue;
            }
            if ($row->resolved_at === null) {
                $row->update(['resolved_at' => $now, 'resolution' => $label]);
                $resolved++;
            } elseif (in_array($row->resolution, ['snoozed', 'dismissed'], true) && $row->resolution !== $label) {
                $row->update(['resolution' => $label]);
            }
        }

        // Gone: dealt with. One that was snoozed and has gone since was dealt with too (a snooze isn't an answer).
        Finding::query()->whereNotIn('key', $all->keys()->all() ?: [''])->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolution', 'snoozed'))->get()
            ->each(function (Finding $f) use ($now, &$resolved) {
                if ($f->resolved_at === null) {
                    $resolved++;
                }
                $f->update(['resolved_at' => $now, 'resolution' => 'resolved']);
            });

        Cache::forever(self::SYNCED_KEY, $fingerprint);

        return ['new' => $new, 'resolved' => $resolved, 'open' => $shown->count()];
    }

    /**
     * A gap with the bank is kept by its dates and amount, which change as it's filled (the statement for part of it
     * imported) or when another balance splits or joins it. A gap finding that's gone while a new gap of the same kind
     * overlaps its dates is the same gap: it takes the new key and keeps its history (and the most money it
     * involved). One that overlaps a gap already recorded was joined into it: 'superseded', not dealt with (one you
     * marked not relevant stays so). Money missing and a line that may be in twice are different findings, even for
     * the same days.
     *
     * @param  Collection<string, array>  $all
     */
    private static function followGaps(Collection $all, Carbon $now): void
    {
        $current = $all->keys()->mapWithKeys(fn ($k) => [$k => self::gapDates($k)])->filter();
        if ($current->isEmpty()) {
            return;
        }
        $known = Finding::query()->whereIn('key', $current->keys())->pluck('key')->flip();
        $fresh = $current->reject(fn ($d, $k) => isset($known[$k]));
        $overlaps = fn (array $a, array $b) => $a[0] === $b[0] && $a[3] === $b[3] && $a[1] <= $b[2] && $b[1] <= $a[2];
        Finding::query()->where('key', 'like', 'gap:%')->whereNotIn('key', $current->keys()->all())
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhereIn('resolution', ['snoozed', 'dismissed']))->orderBy('id')->get()
            ->each(function (Finding $f) use (&$fresh, $current, $overlaps, $now) {
                $dates = self::gapDates($f->key);
                if (! $dates) {
                    return;
                }
                $to = $fresh->search(fn ($d) => $overlaps($d, $dates));
                if ($to !== false) {
                    $f->update(['key' => $to]);
                    $fresh->forget($to);
                } elseif ($f->resolution !== 'dismissed' && $current->contains(fn ($d) => $overlaps($d, $dates))) {
                    $f->update(['resolution' => 'superseded'] + ($f->resolved_at === null ? ['resolved_at' => $now] : []));
                }
            });
    }

    /**
     * [account id, from, to, kind] of a gap's key: gap:{account}:{from}:{to}:{amount}, with ':twice' for a line that
     * may be in twice and ':date' for one that may be dated differently (a kind of money missing). One kept before
     * gaps were kept by their dates (gap:{account}:{from}:{amount}) has only its first day.
     *
     * @return array{0:int, 1:string, 2:string, 3:string}|null
     */
    private static function gapDates(string $key): ?array
    {
        $date = '\d{4}-\d{2}-\d{2}';
        if (preg_match('/^gap:(\d+):('.$date.'):('.$date.')(?::-?\d+)?(?::(twice|date))?$/', $key, $m)) {
            return [(int) $m[1], $m[2], $m[3], ($m[4] ?? '') === 'twice' ? 'twice' : 'missing'];
        }
        if (preg_match('/^gap:(\d+):('.$date.'):-?\d+$/', $key, $m)) {
            return [(int) $m[1], $m[2], $m[2], 'missing'];
        }

        return null;
    }

    /** The currency of a to-do's amount: its own (an invoice, booking or pocket in another currency), or null for pounds. */
    private static function currency(array $item): ?string
    {
        $code = strtoupper((string) ($item['currency'] ?? ''));

        return $code === '' || $code === 'GBP' || ! isset($item['amount']) ? null : $code;
    }

    /**
     * The money totals are in pounds; amounts in other currencies are added up separately, by currency, never
     * mixed in. The list has the open findings and, separately, the latest to have ended, so neither crowds out the
     * other.
     *
     * @return array{open:int, resolved:int, money_open:int, money_resolved:int, money_open_other:array<string,int>, money_resolved_other:array<string,int>, by_group:array, recent:list<array>}
     */
    public static function summary(?string $since = null): array
    {
        $since ??= Carbon::now()->subYear()->format('Y-m-d');
        $open = Finding::query()->whereNull('resolved_at');
        $done = Finding::query()->where('resolution', 'resolved')->where('resolved_at', '>=', $since);
        // Open: what's still involved now. Dealt with: the most each involved (a gap filled in stages, all of it).
        $byCurrency = fn ($q, bool $peak) => (clone $q)->whereNotNull('amount_pence')->get(['amount_pence', 'peak_amount_pence', 'currency'])
            ->groupBy(fn (Finding $f) => $f->currency ?: 'GBP')
            ->map(fn ($fs) => (int) $fs->sum(fn (Finding $f) => abs((int) ($peak ? ($f->peak_amount_pence ?? $f->amount_pence) : $f->amount_pence))));
        $openMoney = $byCurrency($open, false);
        $doneMoney = $byCurrency($done, true);
        $row = fn (Finding $f) => [
            'key' => $f->key, 'group' => TodoList::GROUPS[$f->group] ?? $f->group, 'title' => $f->title, 'detail' => $f->detail, 'href' => $f->href,
            'amount_pence' => $f->resolution === 'resolved' && $f->peak_amount_pence !== null ? (int) $f->peak_amount_pence : $f->amount_pence, 'currency' => $f->currency ?: 'GBP', 'first_seen' => $f->first_seen_at->format('Y-m-d'), 'resolved' => $f->resolved_at?->format('Y-m-d'),
            'resolution' => $f->resolution, 'days_open' => (int) $f->first_seen_at->diffInDays($f->resolved_at ?? Carbon::now()), 'reopened' => $f->reopened,
        ];

        return [
            'open' => (clone $open)->count(),
            'resolved' => (clone $done)->count(),
            'money_open' => (int) ($openMoney['GBP'] ?? 0),
            'money_resolved' => (int) ($doneMoney['GBP'] ?? 0),
            'money_open_other' => $openMoney->except('GBP')->sortKeys()->all(),
            'money_resolved_other' => $doneMoney->except('GBP')->sortKeys()->all(),
            // Found: everything first seen since (less gaps joined into another); dealt with: those that were.
            'by_group' => Finding::query()->where('first_seen_at', '>=', $since)->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', '!=', 'superseded'))
                ->selectRaw("`group`, COUNT(*) n, SUM(CASE WHEN resolution = 'resolved' THEN 1 ELSE 0 END) done")
                ->groupBy('group')->get()->map(fn ($r) => ['group' => $r->group, 'label' => TodoList::GROUPS[$r->group] ?? $r->group, 'found' => (int) $r->n, 'dealt_with' => (int) $r->done])->values()->all(),
            'recent' => (clone $open)->orderByDesc('first_seen_at')->orderByDesc('id')->limit(100)->get()->map($row)
                ->concat(Finding::query()->whereNotNull('resolved_at')->orderByDesc('resolved_at')->orderByDesc('id')->limit(100)->get()->map($row))->values()->all(),
        ];
    }
}
