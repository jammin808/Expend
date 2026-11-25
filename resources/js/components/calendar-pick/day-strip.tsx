import { Button } from '@/components/ui/button';
import { addDays, covers, datesFrom, dayLabel, defaultInclude, foldTarget, isIncluded, OVERNIGHT_UNTIL, type Selection } from '@/lib/day-pick';
import { cn } from '@/lib/utils';
import { Check, Plus } from 'lucide-react';
import { type MonthEntry } from './types';

export const timeText = (e: { all_day: boolean; start_time: string | null; end_time: string | null; date: string; last_day: string }) =>
    e.all_day
        ? e.last_day !== e.date
            ? `${dayLabel(e.date)} to ${dayLabel(e.last_day)}`
            : 'All day'
        : `${e.start_time ?? ''}${e.end_time ? '–' + e.end_time : ''}`;

/**
 * What's in the way of ticking an entry for you: you linked it to another job, or said it isn't work. One linked to
 * another job automatically is ticked, and moves here (as the review's Move, which is on for you).
 */
export function needsSay(e: MonthEntry, ownJobId?: number, included = true): string | null {
    if (e.job && e.job.id !== ownJobId)
        return e.by && e.by !== 'you'
            ? `Linked automatically to ${e.job.title}: ${included ? 'moves here' : 'stays there'}`
            : `On ${e.job.title}: needs your say`;
    if (e.not_job || e.state === 'not_job') return 'You said not a job: needs your say';
    return null;
}

const STRIP_ID = 'day-strip';

/**
 * After a tap on a phone: the day strip (the day's entries, with their ticks) scrolled up just enough to be clear of
 * the bar along the bottom, when the bar is covering it. Nothing moves when it's in view already.
 */
export function revealStrip() {
    window.setTimeout(() => {
        const strip = document.getElementById(STRIP_ID);
        if (!strip) return;
        const bar = document.querySelector<HTMLElement>('[data-pick-bar="page"]');
        strip.style.scrollMarginBottom = `${(bar?.offsetHeight ?? 0) + 8}px`;
        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        strip.scrollIntoView({ block: 'nearest', behavior: still ? 'auto' : 'smooth' });
    }, 60);
}

/**
 * The day you last tapped, under the grid: the calendar entries on it with a tick to include each one in the job,
 * and a way to drop the day (or pick it). Entries in the small hours after it (a load-out at 00:30) are offered here
 * too, since they're that evening's work.
 */
export function DayStrip({
    date,
    sel,
    entries,
    bases,
    ownJobId,
    ownDay = false,
    foldDays,
    onFold,
    onTap,
    onOverride,
    onRange,
}: {
    date: string;
    sel: Selection;
    /** Every entry the page knows about (this month and any loaded). */
    entries: MonthEntry[];
    /** Titles already included or linked to the job, for ticking the same title. */
    bases: string[];
    ownJobId?: number;
    /** On a job's page: the day is already one of the job's. */
    ownDay?: boolean;
    /** On a job's page: its own days, which a small-hours entry the morning after can go on (its finish on that day). */
    foldDays?: ReadonlySet<string>;
    /** Puts one of the job's own days in the pick, so an entry can go on it. */
    onFold?: (date: string) => void;
    onTap: (date: string) => void;
    onOverride: (key: string, value: 'in' | 'out' | null) => void;
    onRange: (from: string, to: string) => void;
}) {
    const picked = !!sel.dates[date];
    const next = addDays(date, 1);
    const prev = addDays(date, -1);
    const on = entries.filter((e) => covers(e, date));
    const early = (e: MonthEntry) => !e.all_day && !!e.start_time && e.start_time < OVERNIGHT_UNTIL;
    // Days a small-hours entry can go on: those picked, and (adding to a job) the job's own.
    const targets: Record<string, unknown> = { ...sel.dates };
    for (const d of foldDays ?? []) targets[d] = true;
    // The small hours of the next day, once this day is picked (or is one of the job's): offered as this evening's.
    const after = picked || ownDay ? entries.filter((e) => e.date === next && early(e) && !sel.dates[next]) : [];
    const ticked = (e: MonthEntry) => isIncluded(e, sel, bases, ownJobId);
    const onlyOne = on.length === 1;

    const row = (e: MonthEntry, folded: boolean) => {
        const mine = !!e.job && e.job.id === ownJobId;
        // On this job already: shown ticked, as the list below draws it (it can come off the job only from the job).
        const inc = mine || ticked(e);
        const say = needsSay(e, ownJobId, inc);
        const ask = !inc && !say && onlyOne && !defaultInclude(e, bases, ownJobId) && !sel.overrides[e.key];
        const days = datesFrom(e.date, e.last_day);
        const pickedOfIt = days.filter((d) => sel.dates[d]).length;
        const runsOn = !folded && !e.all_day && e.last_day > date && !sel.dates[e.last_day] && picked && e.last_day === next;
        const fromBefore = picked && !!targets[prev] && e.date === date && early(e) && foldTarget(e, targets) === prev;
        return (
            <li key={e.key + (folded ? ':after' : '')} className="flex items-start gap-2 py-1.5">
                <button
                    type="button"
                    aria-pressed={inc}
                    disabled={mine}
                    aria-label={mine ? `${e.summary} is on this job` : `${inc ? 'Leave out' : 'Include'} ${e.summary}`}
                    onClick={() => {
                        onOverride(e.key, inc ? 'out' : 'in');
                        // A small-hours entry ticked on one of the job's own days goes on that day.
                        if (folded && !inc && !picked && ownDay) onFold?.(date);
                    }}
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-full border-2',
                        inc
                            ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-400 dark:bg-sky-500'
                            : 'border-neutral-300 dark:border-neutral-600',
                        mine && 'border-emerald-600 bg-emerald-600 dark:border-emerald-500 dark:bg-emerald-600',
                    )}
                >
                    {inc ? <Check className="size-5" /> : folded ? <Plus className="size-5" /> : null}
                </button>
                <div className="min-w-0 flex-1 py-1 text-sm">
                    <p className="font-medium break-words">
                        {folded && '＋ '}
                        {e.summary}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {timeText(e)}
                        {folded && ` ${dayLabel(e.date).split(' ')[0]} (after midnight)`}
                        {e.location ? ` · ${e.location}` : ''}
                    </p>
                    {say && <p className="text-xs text-amber-800 dark:text-amber-300">{say}</p>}
                    {ask && <p className="text-xs text-sky-800 dark:text-sky-300">Include?</p>}
                    {mine && <p className="text-xs text-emerald-800 dark:text-emerald-300">On this job</p>}
                    {/* An entry that only runs into the next morning says so once, below. */}
                    {inc && !mine && days.length > 1 && pickedOfIt < days.length && !runsOn && (
                        <p className="text-xs">
                            Runs {dayLabel(e.date)} to {dayLabel(e.last_day)}: {pickedOfIt} of {days.length} days picked{' '}
                            <button
                                type="button"
                                className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                                onClick={() => onRange(e.date, e.last_day)}
                            >
                                Pick all {days.length}
                            </button>
                        </p>
                    )}
                    {runsOn && (
                        <p className="text-xs">
                            Runs to {dayLabel(e.last_day).split(' ')[0]} {e.end_time}; {dayLabel(e.last_day).split(' ')[0]} isn’t picked{' '}
                            <button
                                type="button"
                                className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                                onClick={() => onTap(e.last_day)}
                            >
                                Pick {dayLabel(e.last_day).split(' ')[0]}
                            </button>
                        </p>
                    )}
                    {fromBefore && (
                        <p className="text-xs">
                            Starts after midnight after {dayLabel(prev)}{' '}
                            <button
                                type="button"
                                className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                                onClick={() => {
                                    // Drop this day first, then tick the entry: it then belongs to the evening before
                                    // (one of the job's own days goes in the pick for it).
                                    onTap(date);
                                    onOverride(e.key, 'in');
                                    if (!sel.dates[prev]) onFold?.(prev);
                                }}
                            >
                                Put it on {dayLabel(prev)}
                            </button>
                        </p>
                    )}
                </div>
            </li>
        );
    };

    return (
        <section id={STRIP_ID} aria-label={`Entries on ${dayLabel(date)}`} className="space-y-1 rounded-xl border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{dayLabel(date)}</h2>
                {ownDay ? (
                    <span className="text-muted-foreground text-xs">Already a day of this job: remove it with ✕ in the list</span>
                ) : (
                    <Button type="button" variant="outline" className="h-11" onClick={() => onTap(date)}>
                        {picked ? 'Drop this day' : 'Pick this day'}
                    </Button>
                )}
            </div>
            {on.length === 0 && after.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {picked ? 'No calendar entries on this day. It’s added as a work day.' : 'No calendar entries on this day.'}
                </p>
            ) : (
                <ul className="divide-y">
                    {on.map((e) => row(e, false))}
                    {after.map((e) => row(e, true))}
                </ul>
            )}
        </section>
    );
}
