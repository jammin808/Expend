import { Button } from '@/components/ui/button';
import { covers, daysBetween, fillDates, MAX_SPAN, plural, span, summary, type PickEntry } from '@/lib/day-pick';
import { cn } from '@/lib/utils';
import { Plus } from 'lucide-react';
import { type ReactNode } from 'react';
import { lockedYearOf } from './month-grid';
import { type JobDayMark, type LockedYear, type MonthEntry } from './types';
import { type DayPick } from './use-day-pick';

const chip =
    'inline-flex min-h-11 shrink-0 items-center gap-1 rounded-full border px-3 text-left text-sm whitespace-nowrap md:shrink md:whitespace-normal focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** Free entries with the same title as one included, within 14 days of a picked day and not picked yet: "De-rig: Winter Expo". */
export function relatedEntries(entries: MonthEntry[], included: PickEntry[], dates: string[]): MonthEntry[] {
    const bases = new Set(included.map((e) => e.base).filter((b): b is string => !!b && b.length >= 4));
    const keys = new Set(included.map((e) => e.key));
    if (bases.size === 0 || dates.length === 0) return [];
    const seen = new Set<string>();
    return entries.filter((e) => {
        if (seen.has(e.key) || keys.has(e.key) || !e.base || !bases.has(e.base)) return false;
        if (e.job || e.not_job || (e.state ?? 'free') !== 'free') return false;
        if (dates.some((d) => covers(e, d))) return false;
        if (!dates.some((d) => Math.abs(daysBetween(d, e.date)) <= 14)) return false;
        seen.add(e.key);
        return true;
    });
}

/**
 * The bar along the bottom while you pick: how many days and entries, a few words for which days, chips for what
 * needs a look (a gap to fill, days already on a job, days in a locked year, the rest of a job in your calendar),
 * and the buttons. Clear can be undone until your next tap.
 */
export function PickBar({
    pick,
    included,
    entries,
    jobDays,
    lockedYears,
    year,
    ownJobId,
    own,
    inSheet = false,
    children,
}: {
    pick: Pick<DayPick<never>, 'dates' | 'offer' | 'undo' | 'notice' | 'restored' | 'keep' | 'clear' | 'undoLast' | 'doFill' | 'pickEntry'>;
    included: PickEntry[];
    entries: MonthEntry[];
    jobDays: Record<string, JobDayMark[]>;
    lockedYears: LockedYear[];
    /** This year, so the words leave it out. */
    year: number;
    ownJobId?: number;
    /** Adding to a job: its own days, which aren't counted as days to add. */
    own?: ReadonlySet<string>;
    /** In a sheet's footer rather than along the bottom of the page. */
    inSheet?: boolean;
    /** The buttons (Create job, Add to job; or Review on a job's page). */
    children?: ReactNode;
}) {
    const { offer } = pick;
    const dates = own ? pick.dates.filter((d) => !own.has(d)) : pick.dates;
    const n = dates.length;
    if (n === 0 && !pick.undo && !pick.notice && !inSheet) return null;

    // Days already on another job, by job.
    const onJobs = new Map<number, { title: string; n: number }>();
    for (const d of dates) {
        for (const j of jobDays[d] ?? []) {
            if (j.job_id === ownJobId) continue;
            const o = onJobs.get(j.job_id) ?? { title: j.title, n: 0 };
            o.n++;
            onJobs.set(j.job_id, o);
        }
    }
    const locked = new Map<string, number>();
    for (const d of dates) {
        const y = lockedYearOf(d, lockedYears);
        if (y) locked.set(y.label, (locked.get(y.label) ?? 0) + 1);
    }
    const related = relatedEntries(entries, included, dates).slice(0, 2);
    const tooLong = span(dates) > MAX_SPAN;

    return (
        <div
            role="region"
            aria-label="Picked days"
            data-pick-bar={inSheet ? 'sheet' : 'page'}
            className={cn(
                'space-y-2',
                !inSheet &&
                    'bg-background/95 supports-[backdrop-filter]:bg-background/85 sticky bottom-0 z-20 -mx-4 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur md:-mx-6 md:px-6',
            )}
        >
            <div aria-live="polite" className="space-y-1">
                {pick.restored && n > 0 && (
                    <p className="flex flex-wrap items-center gap-x-2 text-sm">
                        <span>Picked earlier: {summary(dates, { year })}</span>
                        <button type="button" className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400" onClick={pick.keep}>
                            Keep
                        </button>
                        <span aria-hidden>·</span>
                        <button type="button" className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400" onClick={pick.clear}>
                            Start again
                        </button>
                    </p>
                )}
                {pick.undo && (
                    <p className="flex flex-wrap items-center gap-x-2 text-sm">
                        <span>{pick.undo.label}</span>
                        <span aria-hidden>·</span>
                        <button type="button" className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400" onClick={pick.undoLast}>
                            Undo
                        </button>
                    </p>
                )}
                {pick.notice && (
                    // Amber only for what was refused; news (an entry's days picked) is plain.
                    <p className={cn('text-sm', pick.notice.refused ? 'text-amber-800 dark:text-amber-300' : 'text-sky-800 dark:text-sky-300')}>
                        {pick.notice.text}
                    </p>
                )}
                {tooLong && (
                    <p className="text-sm text-rose-700 dark:text-rose-400">
                        From the first day to the last is more than {MAX_SPAN} days. Make it two jobs.
                    </p>
                )}
            </div>
            {(n > 0 || pick.dates.length > 0) && (
                <>
                    <div className="text-sm">
                        <p className="font-semibold">
                            {plural(n, 'day')} · {plural(included.length, 'entry', 'entries')}
                        </p>
                        <p className="text-muted-foreground">{summary(dates, { year })}</p>
                    </div>
                    {(offer || onJobs.size > 0 || locked.size > 0 || related.length > 0) && (
                        // Sized by the bar, not by its chips: a long chip scrolls here rather than widening the page.
                        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [contain:inline-size] md:-mx-6 md:flex-wrap md:px-6">
                            {offer && (
                                <button
                                    type="button"
                                    className={cn(chip, 'border-sky-600 bg-sky-50 font-medium text-sky-900 dark:bg-sky-950 dark:text-sky-100')}
                                    onClick={pick.doFill}
                                >
                                    Fill {summary(fillDates(offer), { year })} ({plural(offer.count, 'day')})
                                </button>
                            )}
                            {[...onJobs.values()].map((j) => (
                                <span key={j.title} className={cn(chip, 'border-emerald-600/50 text-emerald-900 dark:text-emerald-100')}>
                                    {j.n} already a day of {j.title}
                                </span>
                            ))}
                            {[...locked.entries()].map(([label, k]) => (
                                <span key={label} className={cn(chip, 'border-amber-600/50 text-amber-900 dark:text-amber-100')}>
                                    {k} in {label} (locked)
                                </span>
                            ))}
                            {related.map((e) => (
                                <button key={e.key} type="button" className={chip} onClick={() => pick.pickEntry(e)}>
                                    Also in your calendar: {e.summary}, {summary([e.date], { year })} <Plus className="size-4" aria-hidden />
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="ghost" className="h-11" onClick={pick.clear}>
                            Clear
                        </Button>
                        <span className="flex-1" />
                        {children}
                    </div>
                </>
            )}
        </div>
    );
}
