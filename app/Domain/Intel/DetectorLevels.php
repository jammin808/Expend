<?php

namespace App\Domain\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Todo\Dismissals;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * How prominent each kind of finding is (docs/intelligence-cycle.md sections 2.3, 4.5 and 5.6). A new detector starts
 * low: its findings are shown below the fold on the to-do page, at 'info' severity, and kept off Telegram, until you
 * raise it (a button on the to-do page).
 *
 * A kind shown in full (normal, or raised by you) is demoted on its own, back below the fold, by the findings run
 * (FindingsRegistry::sync(), nightly), when too many of its findings were marked not relevant for a reason that says
 * the finding was wrong ("Not a problem", "Not mine", "Both charges were real": Dismissals' false positives), over the
 * last WINDOW_DAYS days or since you last raised it, whichever is shorter:
 *
 *  - one of M4's new kinds (STARTS_INFO), raised by you: more than one in ten of at least MIN_SEEN findings seen in
 *    that time (so at least two);
 *  - a kind shown in full before M4 (LONG_STANDING: M1's three and charges in twice): section 2.3's rule, at least
 *    MIN_DISMISSALS dismissals in that time and more than half of them saying it was wrong. A single "Both charges
 *    were real" or "Not a problem" never moves them.
 *
 * A demotion is kept (in the setting, with when and why) and audited as detector.demoted by system, and stays until
 * you raise the kind again: Expend may lower a kind on its own, never raise one, and never undoes a demotion by itself
 * as dismissals age out. Reading the levels is one settings read.
 *
 * Levels: 'normal' (as the Ranker rates it), 'info' (new, or lowered by you), 'raised' (by you), 'demoted' (by its
 * false positives). Kept in the setting intel_detector_levels as {code: {level: raised|lowered|demoted, at, reason?}}.
 */
final class DetectorLevels
{
    public const SETTING = 'intel_detector_levels';

    /** The detectors that start below the fold: the ones M4 added (section 4.4). */
    public const STARTS_INFO = ['job.closeout', 'travel.double_claim', 'income.unlinked', 'receipt.unmatched', 'cost.no_receipt', 'dupe.cross_source', 'rule.drift', 'tax.saving',
        'acct.unchecked', 'check.unverifiable', 'year.locked_drift'];

    /** Kinds shown in full before M4 (M1's three and charges in twice): demoted only by section 2.3's rule. */
    public const LONG_STANDING = ['tax.hmrc_unallocated', 'travel.unclaimed', 'year.filed_drift', 'tx.duplicate'];

    /**
     * The kinds that can be raised, lowered and demoted: the new ones and the long-standing ones (each has reasons to
     * say a finding was wrong). Never something about Expend itself (health), a deadline, a fine or a held statement.
     */
    public const DEMOTABLE = [...self::STARTS_INFO, ...self::LONG_STANDING];

    public const WINDOW_DAYS = 90;

    /** A new kind is demoted with more than one false positive in this many findings... */
    public const ONE_IN = 10;

    /** ...once at least this many were seen. */
    public const MIN_SEEN = 10;

    /** A long-standing kind: at least this many dismissals, more than half of them false positives (section 2.3). */
    public const MIN_DISMISSALS = 10;

    public const LEVELS = ['normal', 'info', 'raised', 'demoted'];

    /**
     * Every kind's level now, with the reason in plain words where it isn't normal. One settings read.
     *
     * @return array<string, array{level:string, reason:?string}> by code (a code not listed is normal)
     */
    public static function all(): array
    {
        $chosen = self::chosen();
        $out = [];
        foreach (array_unique([...self::DEMOTABLE, ...array_keys($chosen)]) as $code) {
            $choice = $chosen[$code]['level'] ?? null;
            $at = isset($chosen[$code]['at']) ? CarbonImmutable::parse($chosen[$code]['at']) : null;
            if ($choice === 'demoted') {
                $out[$code] = ['level' => 'demoted', 'reason' => ($chosen[$code]['reason'] ?? 'Demoted by its false alarms').' Shown below the fold until you raise it again.'];
            } elseif ($choice === 'raised') {
                $out[$code] = ['level' => 'raised', 'reason' => 'Raised by you on '.$at?->format('j M Y').'.'];
            } elseif ($choice === 'lowered' || in_array($code, self::STARTS_INFO, true)) {
                $out[$code] = ['level' => 'info', 'reason' => $choice === 'lowered' ? 'Lowered by you on '.$at?->format('j M Y').'.' : 'A new check: shown below the fold until you raise it.'];
            }
        }

        return $out;
    }

    /**
     * Demote the kinds shown in full whose false alarms say so now (see the class): each is kept as demoted, with
     * when and why, and audited as detector.demoted by system. Run with the findings (FindingsRegistry::sync()).
     *
     * @return array<string, string> the kinds demoted now, with why
     */
    public static function settle(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $chosen = self::chosen();
        $starts = [];
        foreach (self::DEMOTABLE as $code) {
            $choice = $chosen[$code]['level'] ?? null;
            // Only a kind shown in full can be demoted: a new one you haven't raised, or one you lowered, is below already.
            if ($choice === 'demoted' || $choice === 'lowered' || ($choice !== 'raised' && in_array($code, self::STARTS_INFO, true))) {
                continue;
            }
            $window = $at->subDays(self::WINDOW_DAYS);
            $raisedAt = $choice === 'raised' && isset($chosen[$code]['at']) ? CarbonImmutable::parse($chosen[$code]['at']) : null;
            // Counted from your last raise, if that's later: what came before it, you'd seen when you raised it.
            $starts[$code] = $raisedAt !== null && $raisedAt->lte($at) && $raisedAt->gt($window) ? $raisedAt : $window;
        }
        $demoted = [];
        foreach (self::tally($starts, $at) as $code => [$wrong, $dismissed, $seen]) {
            $since = $starts[$code]->format('j M Y');
            if (in_array($code, self::LONG_STANDING, true)) {
                if ($dismissed >= self::MIN_DISMISSALS && $wrong * 2 > $dismissed) {
                    $demoted[$code] = $wrong.' of the '.$dismissed.' you marked not relevant since '.$since.' were marked as wrong.';
                }
            } elseif ($seen >= self::MIN_SEEN && $wrong * self::ONE_IN > $seen) {
                $demoted[$code] = $wrong.' of '.$seen.' findings since '.$since.' were marked not relevant as wrong.';
            }
        }
        if ($demoted === []) {
            return [];
        }
        $after = $chosen;
        foreach ($demoted as $code => $reason) {
            $after[$code] = ['level' => 'demoted', 'at' => $at->toIso8601String(), 'reason' => $reason];
        }
        DB::transaction(function () use ($after, $demoted, $chosen) {
            Setting::put(self::SETTING, $after);
            foreach ($demoted as $code => $reason) {
                AuditLog::record('detector.demoted', 'Settings', ['code' => $code, 'level' => $chosen[$code]['level'] ?? null], ['code' => $code, 'level' => 'demoted'], $reason, 'system');
            }
        });

        return $demoted;
    }

    /** A kind's level ('normal' when it isn't listed). @param  array<string, array>|null  $all  all()'s, when already worked out */
    public static function levelOf(?string $code, ?array $all = null): string
    {
        return $code === null ? 'normal' : (($all ?? self::all())[$code]['level'] ?? 'normal');
    }

    /** Whether a kind's findings are shown below the fold, at info severity and off Telegram. */
    public static function belowFold(?string $code, ?array $all = null): bool
    {
        return in_array(self::levelOf($code, $all), ['info', 'demoted'], true);
    }

    /**
     * Codes whose findings were demoted (by false positives: dismissed as not relevant) since $since, each with a
     * plain-words reason.
     *
     * @return array<string,string>
     */
    public static function demotedSince(CarbonInterface $since): array
    {
        $out = [];
        foreach (self::chosen() as $code => $c) {
            if ($c['level'] === 'demoted' && isset($c['at']) && CarbonImmutable::parse($c['at'])->gte($since)) {
                $out[$code] = ($c['reason'] ?? 'Demoted by its false alarms.').' Shown below the fold until you raise it again.';
            }
        }

        return $out;
    }

    /** Show a kind's findings in full (your choice, audited). Returns the message to show. */
    public static function raise(string $code): string
    {
        return self::choose($code, 'raised');
    }

    /** Show a kind's findings below the fold again (your choice, audited). Returns the message to show. */
    public static function lower(string $code): string
    {
        return self::choose($code, 'lowered');
    }

    /** What a kind of finding is called on the page, in a few words. */
    public static function name(string $code): string
    {
        return [
            'job.closeout' => 'Jobs to close out', 'travel.double_claim' => 'Travel claimed twice', 'income.unlinked' => 'Income not assigned to a client, job or invoice',
            'receipt.unmatched' => 'Receipts with no payment', 'cost.no_receipt' => 'Costs with no receipt', 'dupe.cross_source' => 'Cash expenses also on a card',
            'rule.drift' => 'Rules you keep changing', 'tax.saving' => 'Tax you could save', 'acct.unchecked' => 'Accounts that can’t be checked',
            'check.unverifiable' => 'Money that can’t be checked word for word', 'year.locked_drift' => 'Locked years that have changed',
            'tax.hmrc_unallocated' => 'Payments to HMRC not recorded as tax', 'travel.unclaimed' => 'Mileage to claim', 'year.filed_drift' => 'Filed returns that differ',
            'tx.duplicate' => 'Possible duplicate charges',
            'job.done_due' => 'Jobs to mark done', 'invoice.draft' => 'Follow-up and client drafts', 'invoice.overpaid' => 'Invoices paid more than they were for',
            'invoice.void_paid' => 'Void invoices that were paid', 'cost.claimed' => 'Costs claimed another way', 'cost.late' => 'Costs linked after the invoice went',
        ][$code] ?? str_replace(['.', '_'], ' ', $code);
    }

    private static function choose(string $code, string $level): string
    {
        if (! in_array($code, self::DEMOTABLE, true)) {
            throw new InvalidArgumentException('That kind of finding can’t be moved.');
        }
        $before = self::chosen();
        $after = $before;
        $after[$code] = ['level' => $level, 'at' => now()->toIso8601String()];
        if ($level === 'lowered' && in_array($before[$code]['level'] ?? null, ['lowered', 'demoted'], true)) {
            return self::name($code).' are already shown below the fold.';
        }
        Setting::put(self::SETTING, $after);
        AuditLog::record($level === 'raised' ? 'detector.raised' : 'detector.lowered', 'Settings', ['code' => $code, 'level' => $before[$code]['level'] ?? null],
            ['code' => $code, 'level' => $level]);

        return $level === 'raised' ? self::name($code).' are now shown in full, with the rest of the list.' : self::name($code).' are now shown below the fold.';
    }

    /** @return array<string, array{level:string, at?:string, reason?:string}> your choices, and the demotions kept */
    private static function chosen(): array
    {
        $v = Setting::get(self::SETTING, []);

        return is_array($v) ? array_filter($v, fn ($c) => is_array($c) && in_array($c['level'] ?? null, ['raised', 'lowered', 'demoted'], true)) : [];
    }

    /**
     * For each code: its findings dismissed as wrong (a false-positive reason) between its start and $at, its findings
     * dismissed in that time, and its findings seen in that time (only where it could matter). One query for the
     * dismissals, then one count for each kind with two or more marked wrong.
     *
     * @param  array<string, CarbonImmutable>  $starts
     * @return array<string, array{0:int, 1:int, 2:int}>
     */
    private static function tally(array $starts, CarbonImmutable $at): array
    {
        if ($starts === []) {
            return [];
        }
        $earliest = collect($starts)->sortBy(fn (CarbonImmutable $d) => $d->getTimestamp())->first();
        $events = FindingEvent::query()->join('findings', 'findings.id', '=', 'finding_events.finding_id')->whereIn('findings.code', array_keys($starts))
            ->where('finding_events.event', 'dismissed')->where('finding_events.actor', 'you')
            ->where('finding_events.created_at', '>', $earliest)->where('finding_events.created_at', '<=', $at)
            ->get(['finding_events.finding_id', 'finding_events.note', 'finding_events.created_at', 'findings.code']);
        $wrong = [];
        $dismissed = [];
        foreach ($events as $e) {
            // Only after the window starts: a dismissal at the moment you raised it was seen when you raised it.
            if (! $e->created_at->gt($starts[$e->code])) {
                continue;
            }
            $dismissed[$e->code][$e->finding_id] = true;
            if (Dismissals::falsePositive($e->note)) {
                $wrong[$e->code][$e->finding_id] = true;
            }
        }
        $out = [];
        foreach ($starts as $code => $start) {
            $w = count($wrong[$code] ?? []);
            if ($w < 2) {
                continue; // never demoted on fewer than two
            }
            $seen = in_array($code, self::LONG_STANDING, true) ? 0 : Finding::query()->where('code', $code)->where('first_seen_at', '<=', $at)
                ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start)->orWhere('last_seen_at', '>=', $start))->count();
            $out[$code] = [$w, count($dismissed[$code] ?? []), max($seen, $w)];
        }

        return $out;
    }
}
