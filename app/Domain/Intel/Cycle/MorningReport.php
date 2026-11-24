<?php

namespace App\Domain\Intel\Cycle;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Ranker;
use App\Domain\Intel\Ref;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * "What Expend found and did overnight" (docs/intelligence-cycle.md 7.1): built by the morning run, kept with it, sent
 * to Telegram at intel_report_time when anything happened, and shown at /intel (the dashboard shows its first lines).
 *
 * Sections, each left out when it's empty:
 *  1. found: findings new since the last report, most urgent first (each as rated when it was recorded), with their
 *     money by direction and currency, never one mixed total. Kinds of finding kept below the fold (new checks, and
 *     ones turned down by their false alarms: DetectorLevels) are listed apart on /intel, and kept off Telegram and out
 *     of the totals;
 *  2. did: what the automation did on its own, from audit events by anyone but you (until M9 records acts);
 *  3. spot_check: sampled automatic confirmations (from M9; empty until then);
 *  4. dealt: findings dealt with since, with what dealt with them (an import, you, the records changing);
 *  5. needs: what needs you, by group;
 *  6. health: sources not up to date, steps that failed overnight, checks turned down, the AI spent overnight.
 *
 * On a quiet night it's one line, and no message: quiet means nothing new found, nothing done on its own but routine
 * double-checks, nothing dealt with, and nothing in health worse than a note (the AI spent, reads waiting for budget, a
 * check turned down); those notes are still on /intel. Telegram carries titles and amounts, as the Monday digest does,
 * never account numbers, card numbers, sort codes, references, email addresses or postcodes (clean()); with
 * intel_report_detail = counts, only counts and a link.
 */
final class MorningReport
{
    /** Items listed in a section; the rest are counted. */
    public const ITEMS = 6;

    /** How what the automation did reads, by audit action (counted; transfers are recorded once for each side). */
    private const DID = [
        'allocation.suggested' => ['suggested what %d payments are', 'suggested what 1 payment is', 1],
        'allocation.confirmed' => ['confirmed %d payments by your rules or matching', 'confirmed 1 payment by your rules or matching', 1],
        'allocation.cleared' => ['took back %d suggestions that no longer hold', 'took back 1 suggestion that no longer holds', 1],
        'transfer.paired' => ['paired %d transfers between your accounts', 'paired 1 transfer between your accounts', 2],
        'transfer.unpaired' => ['unpaired %d transfers that no longer match', 'unpaired 1 transfer that no longer matches', 2],
        'calendar.linked' => ['linked %d calendar entries to jobs', 'linked 1 calendar entry to a job', 1],
        'document.updated' => ['linked %d documents to jobs', 'linked 1 document to a job', 1],
        'travel.job_linked' => ['linked %d bookings to jobs', 'linked 1 booking to a job', 1],
        'gmail.labelled' => ['labelled %d PO emails “✓ Invoiced” in Gmail', 'labelled 1 PO email “✓ Invoiced” in Gmail', 1],
        'import.committed' => ['imported %d statements', 'imported 1 statement', 1],
        'opportunity.found' => ['found %d possible pieces of work', 'found 1 possible piece of work', 1],
    ];

    /** Kept out of "did": the run log itself, and routine records of a run. */
    private const NOT_DID = ['cycle.completed', 'jobs.finder_run', 'opportunities.refreshed'];

    /**
     * The report for a run: what happened since the last report (or the last day).
     *
     * @return array<string, mixed>
     */
    public static function build(CycleRun $run, ?CarbonInterface $since = null): array
    {
        $now = CarbonImmutable::now();
        // In the app's own timezone, as the dates it's compared with are kept.
        $since = CarbonImmutable::instance($since ?? self::since($run))->setTimezone((string) config('app.timezone'));
        $report = ['at' => $now->toIso8601String(), 'since' => $since->toIso8601String(), 'run_id' => $run->id, 'mode' => $run->mode];

        $found = self::found($since);
        $did = self::did($since);
        $dealt = self::dealt($since);
        $needs = self::needs();
        $health = self::health($run, $since);

        $report += array_filter(['found' => $found, 'did' => $did, 'spot_check' => null, 'dealt' => $dealt, 'needs' => $needs, 'health' => $health]);
        // Notes (the AI spent, routine double-checks, something below the fold) never make a night worth a message.
        $news = (int) ($found['count'] ?? 0) > 0
            || collect($did['items'] ?? [])->contains(fn ($i) => $i['action'] !== 'verification')
            || $dealt !== null
            || self::problems($health) > 0;
        $report['quiet'] = ! $news;
        $report['line'] = self::line($report);

        return $report;
    }

    /** When the last report was made (its run's report time), or a day ago. */
    public static function since(CycleRun $run): CarbonImmutable
    {
        $last = CycleRun::query()->where('id', '!=', $run->id)->where('kind', 'morning')->whereNotNull('report')->orderByDesc('id')->first();
        $at = is_array($last?->report) && isset($last->report['at']) ? CarbonImmutable::parse($last->report['at']) : null;

        return $at && $at->gt(CarbonImmutable::now()->subDays(7)) ? $at : CarbonImmutable::now()->subDay();
    }

    /** @return array<string, mixed>|null new findings since, most urgent first; those below the fold apart */
    private static function found(CarbonImmutable $since): ?array
    {
        $rows = Finding::query()->whereNull('resolved_at')->where('first_seen_at', '>=', $since)->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $levels = self::levels();
        $severity = array_flip(Ranker::SEVERITIES);
        $items = $rows->map(function (Finding $f) use ($levels) {
            $item = Ranker::annotate(['key' => $f->key, 'subject_ref' => $f->subject_ref, 'title' => $f->title, 'href' => $f->href, 'group' => $f->group, 'code' => $f->code,
                'amount' => $f->amount_pence, 'currency' => $f->currency ?? 'GBP', 'direction' => $f->direction, 'grade' => $f->grade, 'due_on' => $f->due_on?->format('Y-m-d')]);
            // As rated when it was recorded, with what only its detector knew (nights failing, a check below the fold).
            $item['severity'] = $f->severity ?? $item['severity'];
            $item['below_fold'] = $levels !== null && $f->code !== null && DetectorLevels::belowFold($f->code, $levels);

            return $item;
        })->sort(fn ($a, $b) => [$severity[$a['severity']] ?? 2, -$a['score']] <=> [$severity[$b['severity']] ?? 2, -$b['score']])->values();
        [$below, $shown] = $items->partition(fn ($i) => $i['below_fold']);
        $pick = fn ($i) => array_intersect_key($i, array_flip(['key', 'title', 'href', 'amount', 'currency', 'direction', 'grade', 'severity']));

        return ['count' => $shown->count(), 'money' => self::money($shown->values()->all()), 'items' => $shown->take(self::ITEMS)->map($pick)->values()->all()]
            + ($below->isNotEmpty() ? ['below_fold' => $below->count(), 'below_items' => $below->take(self::ITEMS)->map($pick)->values()->all()] : []);
    }

    /** @return array<string, array>|null every kind's level (DetectorLevels, M4), worked out once; null if it can't be */
    private static function levels(): ?array
    {
        try {
            return DetectorLevels::all();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Money by direction and currency, never added across either. Two findings about the same record (an invoice
     * unpaid, and not yet sent) count its money once: the larger.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{direction:string, currency:string, pence:int, words:string}>
     */
    private static function money(array $items): array
    {
        $bySubject = [];
        foreach ($items as $i) {
            if (($i['amount'] ?? null) === null || in_array($i['direction'] ?? null, [null, 'none'], true)) {
                continue;
            }
            $k = $i['direction'].':'.($i['currency'] ?? 'GBP');
            $subject = $k.'|'.($i['subject_ref'] ?? 'key:'.$i['key']);
            $bySubject[$subject] = ['k' => $k, 'direction' => $i['direction'], 'currency' => $i['currency'] ?? 'GBP',
                'pence' => max(abs((int) $i['amount']), $bySubject[$subject]['pence'] ?? 0)];
        }
        $out = [];
        foreach ($bySubject as $m) {
            $out[$m['k']] ??= ['direction' => $m['direction'], 'currency' => $m['currency'], 'pence' => 0,
                'words' => FindingsRegistry::DIRECTION_TOTALS[$m['direction']] ?? (FindingDraft::DIRECTION_WORDS[$m['direction']] ?? $m['direction'])];
            $out[$m['k']]['pence'] += $m['pence'];
        }

        return array_values($out);
    }

    /** @return array<string, mixed>|null what the automation did on its own since: audit events by anyone but you */
    private static function did(CarbonImmutable $since): ?array
    {
        $counts = AuditEvent::query()->where('occurred_at', '>=', $since->utc()->format('Y-m-d H:i:s'))->where('actor', '!=', 'you')
            ->whereNotIn('action', self::NOT_DID)->where('action', 'not like', 'verification.%')
            ->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
        $checked = AuditEvent::query()->where('occurred_at', '>=', $since->utc()->format('Y-m-d H:i:s'))->where('actor', '!=', 'you')
            ->where('action', 'like', 'verification.%')->count();
        $items = [];
        $other = 0;
        foreach ($counts as $action => $n) {
            [$many, $one, $per] = self::DID[$action] ?? [null, null, 1];
            if ($many === null) {
                $other += (int) $n;

                continue;
            }
            $n = max(1, intdiv((int) $n, $per));
            $items[] = ['action' => $action, 'count' => $n, 'text' => $n === 1 ? $one : sprintf($many, $n)];
        }
        if ($checked > 0) {
            $items[] = ['action' => 'verification', 'count' => $checked, 'text' => $checked === 1 ? 'double-checked 1 AI finding against its source' : "double-checked {$checked} AI findings against their sources"];
        }
        if ($other > 0) {
            $items[] = ['action' => 'other', 'count' => $other, 'text' => $other === 1 ? 'made 1 other change' : "made {$other} other changes"];
        }

        return $items === [] ? null : ['items' => $items];
    }

    /** @return array<string, mixed>|null findings dealt with since, and what dealt with them */
    private static function dealt(CarbonImmutable $since): ?array
    {
        $rows = Finding::query()->whereNotNull('resolved_at')->where('resolved_at', '>=', $since)->where('resolution', 'resolved')->orderByDesc('resolved_at')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $events = FindingEvent::query()->whereIn('finding_id', $rows->pluck('id'))->where('event', 'resolved')->orderByDesc('id')->get()->unique('finding_id')->keyBy('finding_id');
        $audits = AuditEvent::query()->whereIn('id', $events->map(fn (FindingEvent $e) => Ref::kind($e->cause_ref) === 'audit' ? (int) Ref::parse($e->cause_ref)['id'] : null)->filter()->values())
            ->pluck('actor', 'id');
        $by = ['import' => 0, 'you' => 0, 'automatic' => 0, 'records' => 0];
        $items = $rows->map(function (Finding $f) use ($events, $audits, &$by) {
            $cause = $events[$f->id]->cause_ref ?? null;
            $kind = Ref::kind($cause);
            $why = match (true) {
                $kind === 'batch' => 'import',
                $kind === 'audit' => ($audits[(int) Ref::parse($cause)['id']] ?? null) === 'you' ? 'you' : 'automatic',
                default => 'records',
            };
            $by[$why]++;

            return ['key' => $f->key, 'title' => $f->title, 'href' => $f->href, 'amount' => $f->peak_amount_pence ?? $f->amount_pence, 'currency' => $f->currency ?? 'GBP',
                'direction' => $f->direction, 'cause' => $why];
        });

        return ['count' => $rows->count(), 'by' => array_filter($by), 'items' => $items->take(self::ITEMS)->values()->all()];
    }

    /** @return array<string, mixed>|null what needs you now, by group (as the to-do list counts it) */
    private static function needs(): ?array
    {
        try {
            $list = TodoList::cached(1);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (($list['total'] ?? 0) === 0) {
            return null;
        }
        $groups = [];
        foreach ((array) ($list['counts'] ?? []) as $group => $n) {
            if ($n > 0) {
                $groups[] = ['group' => $group, 'label' => TodoList::GROUPS[$group] ?? $group, 'count' => (int) $n];
            }
        }

        return ['total' => (int) $list['total'], 'groups' => $groups];
    }

    /** @return array<string, mixed>|null what isn't working, and the AI spent overnight */
    private static function health(CycleRun $run, CarbonImmutable $since): ?array
    {
        $lines = [];
        // What this run's acquire.health found, or the same looked at now.
        $snapshot = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', Steps\AcquireHealth::KEY)->where('status', 'ok')->first()?->counts;
        $snapshot = is_array($snapshot) && $snapshot !== [] ? $snapshot : Steps\AcquireHealth::snapshot();
        if (($snapshot['audit']['ok'] ?? true) === false) {
            $lines[] = ['level' => 'critical', 'text' => 'The audit log check found the records altered outside the app.'];
        }
        if (($snapshot['starling']['failing'] ?? 0) > 0) {
            $n = (int) $snapshot['starling']['failing'];
            $lines[] = ['level' => 'important', 'text' => 'The Starling import failed for '.$n.' '.($n === 1 ? 'account' : 'accounts').'.'];
        }
        if (($snapshot['calendar']['stale'] ?? false) === true) {
            $lines[] = ['level' => 'important', 'text' => 'The calendar hasn’t been read for over '.TodoList::CALENDAR_STALE_HOURS.' hours.'];
        }
        if (isset($snapshot['gmail']) && (! ($snapshot['gmail']['connected'] ?? true) || ! ($snapshot['gmail']['ok'] ?? true))) {
            $lines[] = ['level' => 'important', 'text' => 'Gmail needs connecting again.'];
        }
        if (($snapshot['failed_jobs'] ?? 0) > 0) {
            $n = (int) $snapshot['failed_jobs'];
            $lines[] = ['level' => 'important', 'text' => $n.' background '.($n === 1 ? 'task' : 'tasks').' failed this week.'];
        }
        if (($snapshot['reads_waiting'] ?? 0) > 0) {
            $n = (int) $snapshot['reads_waiting'];
            $lines[] = ['level' => 'info', 'text' => $n.' '.($n === 1 ? 'read waits' : 'reads wait').' for AI budget: '.($n === 1 ? 'it’s' : 'they’re').' read on '.($n === 1 ? 'its' : 'their').' own once there’s room.'];
        }

        // The steps that failed or were cut short overnight, and the AI the night spent.
        $night = CycleRun::query()->where('kind', 'nightly')->where('id', '<=', $run->id)->where('created_at', '>=', CarbonImmutable::now()->subDay())->orderByDesc('id')->first();
        if ($night) {
            foreach (CycleRunStep::query()->where('cycle_run_id', $night->id)->whereIn('status', ['failed', 'budget'])->orderBy('seq')->get() as $s) {
                $lines[] = $s->status === 'failed'
                    ? ['level' => 'important', 'text' => 'Overnight, '.CycleRunner::stepWords($s->step).' didn’t work.', 'step' => $s->step]
                    : ['level' => 'info', 'text' => 'Overnight, '.CycleRunner::stepWords($s->step).' stopped at the AI allowance: the rest carries on tonight.', 'step' => $s->step];
            }
            if ($night->status === 'abandoned') {
                $lines[] = ['level' => 'important', 'text' => 'The nightly check stopped answering and was given up.'];
            }
        }
        // A kind of finding turned down by its false alarms, named once: in the first report after.
        foreach (self::demoted($since) as $code => $reason) {
            $lines[] = ['level' => 'info', 'text' => DetectorLevels::name($code).': turned down. '.$reason, 'code' => $code];
        }
        $ai = $night && $night->ai_spent_micro > 0 ? ['spent_usd' => round($night->ai_spent_micro / 1_000_000, 2), 'allowance_usd' => round($night->ai_budget_micro / 1_000_000, 2)] : null;

        return $lines === [] && $ai === null ? null : array_filter(['lines' => $lines ?: null, 'ai' => $ai]);
    }

    /**
     * @return array<string, string> kinds of finding demoted since by their false alarms (DetectorLevels::settle(), run
     *                               with the findings), each with why: named once, in the first report after
     */
    private static function demoted(CarbonImmutable $since): array
    {
        try {
            return DetectorLevels::demotedSince($since);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** The one line that sums it up (the whole report on a quiet night). */
    private static function line(array $report): string
    {
        $needs = (int) ($report['needs']['total'] ?? 0);
        $needsText = $needs === 0 ? 'nothing needs you' : ($needs === 1 ? '1 thing needs you' : "{$needs} things need you");
        if ($report['quiet']) {
            return 'A quiet night: nothing new found, and '.$needsText.'.';
        }
        $parts = [];
        if ($n = (int) ($report['found']['count'] ?? 0)) {
            $parts[] = $n.' new '.($n === 1 ? 'finding' : 'findings');
        }
        if ($n = (int) ($report['dealt']['count'] ?? 0)) {
            $parts[] = $n.' dealt with';
        }
        $problems = self::problems($report['health'] ?? null);
        if ($problems > 0) {
            $parts[] = $problems.' '.($problems === 1 ? 'thing isn’t' : 'things aren’t').' working';
        }
        $parts[] = $needsText;

        return ucfirst(implode(', ', $parts)).'.';
    }

    /** How many of the health lines are problems (not notes). */
    private static function problems(?array $health): int
    {
        return count(array_filter((array) ($health['lines'] ?? []), fn ($l) => ($l['level'] ?? 'info') !== 'info'));
    }

    /**
     * The report as the Telegram message. Titles and amounts only, cleaned of account numbers and the like (clean());
     * 'counts' detail gives only the counts and the link.
     */
    public static function telegram(array $report, string $detail = 'full'): string
    {
        // The link is added after the text is cleaned, so it's never broken by it.
        $link = url('/intel');
        if ($detail === 'counts' || ! empty($report['quiet'])) {
            return self::clean("What Expend found and did overnight\n".$report['line'])."\nMore: ".$link;
        }
        $out = ['What Expend found and did overnight', $report['line']];
        if (($found = $report['found'] ?? null) && $found['count'] > 0) {
            $money = implode(' · ', array_map(fn ($m) => $m['words'].' '.Money::format($m['pence'], $m['currency']), $found['money']));
            $out[] = '';
            $out[] = 'Found '.$found['count'].' new'.($money !== '' ? ': '.$money : '');
            foreach ($found['items'] as $i) {
                $out[] = '• '.$i['title'].($i['amount'] !== null && ! in_array($i['direction'], [null, 'none'], true) ? ' ('.Money::format(abs((int) $i['amount']), $i['currency']).')' : '');
            }
            if ($found['count'] > count($found['items'])) {
                $out[] = '• and '.($found['count'] - count($found['items'])).' more';
            }
        }
        if ($did = $report['did'] ?? null) {
            $out[] = '';
            $out[] = 'Did on its own: '.implode(', ', array_column($did['items'], 'text')).'.';
        }
        if ($dealt = $report['dealt'] ?? null) {
            $words = ['import' => 'by an import', 'you' => 'by you', 'automatic' => 'automatically', 'records' => 'as the records changed'];
            $by = implode(', ', array_map(fn ($k, $n) => $n.' '.$words[$k], array_keys($dealt['by']), $dealt['by']));
            $out[] = '';
            $out[] = 'Dealt with: '.$dealt['count'].($by !== '' ? ' ('.$by.')' : '').'.';
        }
        if ($needs = $report['needs'] ?? null) {
            $top = array_slice($needs['groups'], 0, 4);
            $out[] = '';
            $out[] = 'Needs you: '.$needs['total'].($top ? ' ('.implode(', ', array_map(fn ($g) => $g['label'].': '.$g['count'], $top)).')' : '').'.';
        }
        if ($health = $report['health'] ?? null) {
            $lines = array_column((array) ($health['lines'] ?? []), 'text');
            if (isset($health['ai'])) {
                $lines[] = 'AI overnight: $'.number_format($health['ai']['spent_usd'], 2).' of $'.number_format($health['ai']['allowance_usd'], 2).'.';
            }
            $out[] = '';
            $out[] = 'Health: '.implode(' ', $lines);
        }

        return self::clean(implode("\n", $out))."\n\nMore: ".$link;
    }

    /**
     * Text with what must never leave in a message taken out: email addresses, card numbers, account numbers, sort
     * codes, IBANs, long references (all digits, or letters and digits mixed) and UK postcodes. Amounts, dates, short
     * numbers and invoice numbers (INV-0001) stay. For text only: a link is added after it's cleaned.
     */
    public static function clean(string $text): string
    {
        $patterns = [
            '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i' => '[email]',
            '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?\b/' => '[account]',     // IBAN
            '/\b(?:\d{4}[ -]?){3}\d{1,7}\b/' => '[card]',                                         // card numbers
            '/\b\d{2}-\d{2}-\d{2}\b/' => '[sort code]',
            '/(?<![\d,.£$€])\b\d{6,}\b(?![.,]\d)/' => '[number]',                                // account numbers, references
            '/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/' => '[postcode]',
            '/\b(?=(?:[A-Za-z]*\d){3})(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{8,}\b/' => '[ref]',      // references of letters and digits
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }
}
