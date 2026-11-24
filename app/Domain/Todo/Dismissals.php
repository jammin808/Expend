<?php

namespace App\Domain\Todo;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Backfills\Findings as FindingsBackfill;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\Ref;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\TodoDismissal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Snoozing a to-do item, marking it not relevant, and undoing either: the same from the to-do page and from Telegram.
 * Each is your decision: it's audited, its reason is kept (todo_dismissals.note and the finding's note), and the
 * finding records it as an event that points at the audit entry. Nothing automatic ever dismisses a finding.
 *
 * "Not relevant" on mileage to claim means you didn't drive to those jobs: each is left out from then on (a dismissal
 * of its own, TodoList::mileageJobKey()), so a job that finishes later is listed on its own. Undo puts them back.
 * A gap with the bank, or an account with nothing imported lately, is hidden as you saw it (TodoList::dismissKeyFor()):
 * one that changes comes back. A job's close-out is hidden by its parts as you saw them: a new part comes back.
 */
final class Dismissals
{
    /**
     * Why it isn't relevant: the reasons offered, in plain words. 'false_positive' marks the ones that say the
     * finding was wrong (they tune its detector later); 'only' limits a reason to some kinds of finding.
     */
    public const DISMISS_REASONS = [
        'not_problem' => ['label' => 'Not a problem', 'false_positive' => true],
        'sorted' => ['label' => 'Already sorted', 'false_positive' => false],
        'real' => ['label' => 'Both charges were real', 'false_positive' => true, 'only' => ['tx.duplicate', 'dupe.cross_source']],
        'refunded' => ['label' => 'Refunded', 'false_positive' => false, 'only' => ['tx.duplicate', 'dupe.cross_source']],
        'not_mine' => ['label' => 'Not mine', 'false_positive' => true],
        'other' => ['label' => 'Other', 'false_positive' => false],
    ];

    /** Why it's snoozed. */
    public const SNOOZE_REASONS = [
        'later' => ['label' => 'Later'],
        'waiting' => ['label' => 'Waiting to hear back'],
        'other' => ['label' => 'Other'],
    ];

    /** A dismissal's note fits todo_dismissals.note. */
    private const NOTE_MAX = 250;

    /**
     * The reasons offered for a kind of finding (its code; null for a to-do item that isn't a finding).
     *
     * @return array<string, string> code => label
     */
    public static function reasonsFor(?string $code, bool $snooze = false): array
    {
        $out = [];
        foreach ($snooze ? self::SNOOZE_REASONS : self::DISMISS_REASONS as $reason => $r) {
            if (! isset($r['only']) || in_array($code, $r['only'], true)) {
                $out[$reason] = $r['label'];
            }
        }

        return $out;
    }

    /**
     * Whether a dismissal's note (as note() keeps it) gives a reason that says the finding was wrong: "Not a problem",
     * "Not mine", "Both charges were real" (with or without your words after it). These tune its detector
     * (Intel\DetectorLevels). Your own words alone ("Other") never count.
     */
    public static function falsePositive(?string $note): bool
    {
        if ($note === null || $note === '') {
            return false;
        }
        foreach (self::DISMISS_REASONS as $r) {
            if ($r['false_positive'] && ($note === $r['label'] || str_starts_with($note, $r['label'].': '))) {
                return true;
            }
        }

        return false;
    }

    /** The kind of finding an item with this key is (its code), for the reasons it's offered; null for one that isn't. */
    public static function codeFor(string $key): ?string
    {
        return Finding::query()->where('key', TodoList::findingKey($key))->value('code') ?? (TodoGroupsDetector::classify($key)['code'] ?? null);
    }

    /**
     * The note a reason keeps: the words you typed for "Other", or the reason's label. Only a reason offered for this
     * kind of finding ($code: reasonsFor()) is taken, whether it comes from the to-do page or a Telegram button ("Both
     * charges were real" is for a charge that may be in twice, nothing else).
     */
    public static function note(?string $reason, ?string $text, bool $snooze = false, ?string $code = null): ?string
    {
        $text = $text !== null ? trim($text) : null;
        $label = $reason !== null ? (self::reasonsFor($code, $snooze)[$reason] ?? null) : null;
        if ($reason !== null && $label === null) {
            throw new InvalidArgumentException('Choose one of the reasons offered.');
        }
        $note = $text !== null && $text !== '' ? ($label !== null && $reason !== 'other' ? $label.': '.$text : $text) : $label;

        return $note === null ? null : mb_substr($note, 0, self::NOTE_MAX);
    }

    /**
     * Whether you've hidden the item behind a finding right now, read from your snoozes and "not relevant"s as the list
     * reads them, not from the finding's recorded state (which catches up at the next findings run): 'snoozed',
     * 'dismissed' (not relevant), or null.
     */
    public static function hiddenNow(string $findingKey): ?string
    {
        $keys = array_values(array_unique([$findingKey, TodoList::dismissKeyFor($findingKey)]));
        $row = TodoDismissal::query()->whereIn('key', $keys)->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->orderByRaw('until IS NULL DESC')->first();

        return $row === null ? null : ($row->until === null ? 'dismissed' : 'snoozed');
    }

    /**
     * Snooze for $days, or mark not relevant (no days). Returns the message to show.
     *
     * A gap with the bank, or an account with nothing imported lately, is hidden as you saw it (its dates and amount,
     * or its last line: TodoList::dismissKeyFor()). One that's gone since (filled, or imported, while the page or the
     * message was open) is refused: kept under the gap's first day or the account alone, it would hide every later gap
     * from that day, or every later warning for that account, for good.
     *
     * @param  string  $channel  where you did it ('web', 'telegram'), for the audit context
     * @param  bool  $fromStartOfDay  a snooze ends at the start of its last day, not the time of day you pressed it
     *                                (Telegram: "Snooze a week" from Monday's digest is back for the next one)
     */
    public static function dismiss(string $key, ?int $days, ?string $note, string $channel = 'web', bool $fromStartOfDay = false): string
    {
        if ($days === null && TodoList::snoozeOnly($key)) {
            throw new InvalidArgumentException('This one can only be snoozed. It comes back whenever it applies again, and “Not relevant” would hide it for good.');
        }

        return AuditContext::with('you', function () use ($key, $days, $note, $fromStartOfDay) {
            $target = TodoList::dismissKeyFor($key);
            if ($target === $key && preg_match('/^(gap|stale|closeout):/', $key) && TodoList::findingKey($key) === $key) {
                throw new InvalidArgumentException('This has changed since you saw it. Open the to-do list again to see it as it is now.');
            }
            $findingKey = TodoList::findingKey($target);
            $jobs = $days === null ? TodoList::mileageJobs($key) : null;
            $until = $days !== null ? ($fromStartOfDay ? now()->addDays($days)->startOfDay() : now()->addDays($days)) : null;
            DB::transaction(function () use ($key, $target, $findingKey, $jobs, $until, $days, $note) {
                TodoDismissal::query()->updateOrCreate(['key' => $target], ['until' => $until, 'note' => $note]);
                foreach ($jobs ?? [] as $job) {
                    TodoDismissal::query()->updateOrCreate(['key' => TodoList::mileageJobKey($job, $key)], ['until' => null, 'note' => $note]);
                }
                $event = AuditLog::record('todo.dismissed', 'Todo', null, array_filter(['key' => $key, 'kept_as' => $target !== $key ? $target : null, 'days' => $days, 'note' => $note,
                    'no_mileage_for_jobs' => $jobs ?: null], fn ($v) => $v !== null), $note);
                $finding = Finding::query()->where('key', $findingKey)->first();
                if ($finding) {
                    $label = $days !== null ? 'snoozed' : 'dismissed';
                    FindingsBackfill::adopt($finding);
                    $finding->forceFill(['state' => $label, 'note' => $note, 'resolution' => $label] + ($finding->resolved_at === null ? ['resolved_at' => now()] : []))->save();
                    FindingsRegistry::event($finding, $label, actor: 'you', cause: self::auditRef($event), note: $note);
                }
            });
            TodoList::forget();

            return $days !== null ? "Snoozed for {$days} days."
                : ($jobs ? 'Marked not relevant: '.(count($jobs) === 1 ? 'that job won’t' : 'those '.count($jobs).' jobs won’t').' be listed for mileage again.' : 'Marked not relevant.');
        }, $channel);
    }

    /**
     * Put it back on the list: the snooze or "not relevant" goes, and for mileage, the jobs it left out come back.
     * Audited with what was removed. Returns the message to show.
     */
    public static function undo(string $key, string $channel = 'web'): string
    {
        return AuditContext::with('you', function () use ($key) {
            $keys = array_values(array_unique([$key, TodoList::dismissKeyFor($key)]));
            $rows = TodoDismissal::query()->whereIn('key', $keys)->get();
            if (preg_match('/^mileage:\d{4}-\d{2}:([0-9a-f]+)$/', $key, $m)) {
                $rows = $rows->concat(TodoDismissal::query()->where('key', 'like', TodoList::MILEAGE_JOB.'%:'.$m[1])->get());
            }
            // A close-out's parts hidden at different times: all of them come back.
            if (preg_match('/^closeout:(\d+)$/', $key, $m)) {
                $rows = $rows->concat(TodoDismissal::query()->where('key', 'like', 'closeout:'.$m[1].':%')->get())->unique('id');
            }
            if ($rows->isEmpty()) {
                return 'It’s back on the list.';
            }
            $findingKey = TodoList::findingKey($key);
            DB::transaction(function () use ($rows, $key, $findingKey) {
                TodoDismissal::query()->whereIn('id', $rows->pluck('id'))->delete();
                $event = AuditLog::record('todo.restored', 'Todo', ['dismissals' => $rows->map(fn (TodoDismissal $d) => ['key' => $d->key, 'until' => $d->until?->toIso8601String(), 'note' => $d->note])->values()->all()],
                    ['key' => $key], 'Undone');
                $finding = Finding::query()->where('key', $findingKey)->first();
                if ($finding && in_array($finding->currentState(), ['snoozed', 'dismissed'], true)) {
                    FindingsBackfill::adopt($finding);
                    $finding->forceFill(['state' => 'open', 'note' => null, 'resolved_at' => null, 'resolution' => null])->save();
                    FindingsRegistry::event($finding, 'reopened', actor: 'you', cause: self::auditRef($event), note: 'Undone');
                }
            });
            TodoList::forget();

            return 'It’s back on the list.';
        }, $channel);
    }

    private static function auditRef(AuditEvent $event): string
    {
        return Ref::make('audit', $event->id, null, $event->hash);
    }
}
