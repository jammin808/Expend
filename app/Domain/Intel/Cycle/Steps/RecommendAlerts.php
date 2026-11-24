<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Intel\DetectorLevels;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\TodoList;
use App\Models\Finding;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * recommend.alerts (docs/intelligence-cycle.md 7.6): critical findings go to Telegram at once, at most
 * intel_alerts_per_day (3) a day, and never twice for the same finding (again only if it was dealt with and came
 * back). Each alert is the finding's title and amount, cleaned of references and addresses (MorningReport::clean()),
 * a link, and its buttons: Snooze a week, and Not relevant unless it can only be snoozed (TodoList::snoozeOnly()).
 *
 * Not alerted: the audit log's own alarm (sent by verify.audit_chain), overdue invoices (the weekday nudge carries
 * them, with its Send a reminder button), and kinds of finding still below the fold (new detectors start at info).
 *
 * Most urgent first: a finding due within URGENT_DAYS (soonest first), then the newest. The first time alerts are
 * sent for real, critical findings open for over a day and not due within URGENT_DAYS are taken as known (they're on
 * the to-do list, and in the Monday digest), so switching the cycle on doesn't flood Telegram with what's long known;
 * from then on, whatever becomes critical is alerted. What was alerted (or taken as known) is kept in settings
 * intel_alerts_sent, also while you've snoozed or dismissed it, so putting it back on the list doesn't alert it again.
 * A shadow run only counts what it would send.
 */
final class RecommendAlerts implements CycleStep
{
    public const KEY = 'recommend.alerts';

    public const SENT = 'intel_alerts_sent';

    /** Never alerted here: the audit log's alarm goes on its own; overdue invoices are the weekday nudge's. */
    public const NOT_ALERTED = ['health.audit_chain', 'invoice.overdue'];

    /** Due within this many days: alerted before anything else. */
    public const URGENT_DAYS = 7;

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Recommend;
    }

    /** It sends and keeps what it sent, but a shadow run still works out what it would send. */
    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $max = max(0, (int) (Setting::get('intel_alerts_per_day') ?? 3));
        $kept = Setting::get(self::SENT);
        $kept = is_array($kept) ? $kept : [];
        $alerted = (array) ($kept['findings'] ?? []);
        $now = CarbonImmutable::now();
        $today = $now->tz('Europe/London')->format('Y-m-d');
        $sentToday = (int) (($kept['day'] ?? null) === $today ? ($kept['today'] ?? 0) : 0);
        $levels = self::levels();

        $due = Finding::query()->whereNull('resolved_at')->where('severity', 'critical')->where(fn ($q) => $q->whereNull('state')->orWhereIn('state', ['open', 'unseen']))
            ->where(fn ($q) => $q->whereNull('code')->orWhereNotIn('code', self::NOT_ALERTED))->where('key', 'not like', 'invoice_overdue:%')->get()
            ->reject(fn (Finding $f) => isset($alerted[self::episode($f)]) || self::belowFold($f->code, $levels))->values();

        // The first real run: what's long known and not close to its date is taken as known, not sent.
        $known = 0;
        if (! isset($kept['since'])) {
            foreach ($due as $f) {
                if ($f->first_seen_at !== null && $f->first_seen_at->lt($now->subDay()) && ! self::soon($f)) {
                    $alerted[self::episode($f)] = 'known';
                    $known++;
                }
            }
            $due = $due->reject(fn (Finding $f) => isset($alerted[self::episode($f)]))->values();
        }
        $due = $due->sortBy(fn (Finding $f) => [self::soon($f) ? 0 : 1, self::soon($f) ? $f->due_on->format('Y-m-d') : '', -($f->first_seen_at?->getTimestamp() ?? 0), $f->id])->values();

        $sent = 0;
        $held = 0;
        foreach ($due as $f) {
            if ($sentToday >= $max) {
                $held++;

                continue;
            }
            if ($ctx->shadow) {
                $sent++;
                $sentToday++;

                continue;
            }
            $text = MorningReport::clean('⚠ '.$f->title.($f->amount_pence !== null && ! in_array($f->direction, [null, 'none'], true) ? ' ('.Money::format(abs((int) $f->amount_pence), $f->currency ?? 'GBP').')' : ''))
                ."\n".self::link($f);
            if (Telegram::notify($text, [], Telegram::findingButtons(['snooze_only' => TodoList::snoozeOnly($f->key)], $f->id))) {
                $alerted[self::episode($f)] = $today;
                $sent++;
                $sentToday++;
            }
        }
        if (! $ctx->shadow) {
            Setting::put(self::SENT, ['day' => $today, 'today' => $sentToday, 'since' => $now->toIso8601String(), 'findings' => self::prune($alerted)]);
        }

        return StepOutcome::ok(($ctx->shadow ? ['would_send' => $sent] : ['sent' => $sent]) + ['held_back' => $held, 'critical' => $due->count()] + ($known ? ['known' => $known] : []));
    }

    /** The finding as alerted: its id, and how many times it has come back (a finding back again is alerted again). */
    private static function episode(Finding $f): string
    {
        return $f->id.':'.(int) $f->reopened;
    }

    /** Whether it's due within URGENT_DAYS (today included, not past). */
    private static function soon(Finding $f): bool
    {
        if ($f->due_on === null) {
            return false;
        }
        $days = CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($f->due_on->format('Y-m-d')), false);

        return $days >= 0 && $days <= self::URGENT_DAYS;
    }

    /**
     * What's remembered: alerts for findings still there in the same episode, open, or snoozed or dismissed by you (so
     * undoing that doesn't alert them again). One dealt with, or gone, is forgotten: back again, it's a new episode.
     *
     * @param  array<string, string>  $alerted
     * @return array<string, string>
     */
    private static function prune(array $alerted): array
    {
        $ids = array_map(fn ($episode) => (int) explode(':', (string) $episode)[0], array_keys($alerted));
        $rows = Finding::query()->whereIn('id', $ids ?: [0])->get(['id', 'reopened', 'resolved_at', 'resolution'])->keyBy('id');

        return array_filter($alerted, function ($day, $episode) use ($rows) {
            [$id, $reopened] = array_map('intval', explode(':', (string) $episode) + [1 => 0]);
            $f = $rows[$id] ?? null;

            return $f !== null && (int) $f->reopened === $reopened && ($f->resolved_at === null || in_array($f->resolution, ['snoozed', 'dismissed'], true));
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** The finding's page, or the to-do list when its link carries a reference or number that mustn't leave. */
    private static function link(Finding $f): string
    {
        $url = url($f->href ?: '/todo');

        return MorningReport::clean($url) === $url ? $url : url('/todo');
    }

    /** @return array<string, array>|null every kind's level (DetectorLevels, M4), worked out once */
    private static function levels(): ?array
    {
        try {
            return DetectorLevels::all();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Whether findings of this kind are kept below the fold, and so off Telegram (DetectorLevels, M4). */
    private static function belowFold(?string $code, ?array $levels): bool
    {
        if ($code === null || $levels === null) {
            return false;
        }

        return DetectorLevels::belowFold($code, $levels);
    }
}
