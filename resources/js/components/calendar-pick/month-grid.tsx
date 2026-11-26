import { addDays, dayLabelLong, monthCells, monthName, monthOf, plural, shiftMonth, type Selection } from '@/lib/day-pick';
import { cn } from '@/lib/utils';
import { Check, Lock } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type JobDayMark, type LockedYear, type MonthEntry } from './types';

const WEEKDAYS = [
    ['M', 'Monday'],
    ['T', 'Tuesday'],
    ['W', 'Wednesday'],
    ['T', 'Thursday'],
    ['F', 'Friday'],
    ['S', 'Saturday'],
    ['S', 'Sunday'],
];

/** The entries covering each day of these months, worked out once rather than for every day drawn. */
export function entriesByDate<T extends { date: string; last_day: string }>(entries: T[], months: string[]): Record<string, T[]> {
    const out: Record<string, T[]> = {};
    const first = `${months[0]}-01`;
    const last = addDays(`${shiftMonth(months[months.length - 1], 1)}-01`, -1);
    for (const e of entries) {
        const from = e.date > first ? e.date : first;
        const to = e.last_day < last ? e.last_day : last;
        for (let d = from; d <= to; d = addDays(d, 1)) (out[d] ??= []).push(e);
    }
    return out;
}

export function lockedYearOf(date: string, years: LockedYear[]): LockedYear | null {
    return years.find((y) => y.starts_on <= date && date <= y.ends_on) ?? null;
}

/** An entry that looks like work and is on no job: a filled amber dot. Anything else: a grey ring. */
export const looksFree = (e: MonthEntry) => !e.job && !e.not_job && (e.state ?? 'free') === 'free' && (e.default_include ?? e.looks_like_work);

/**
 * The month as a grid you tap to pick days: Monday first, each day at least 44 by 52 pixels. Dots are calendar
 * entries (filled amber for one that looks like work, a grey ring for any other), green bars are days of a job
 * (faded for a cancelled one), and a lock is a tax year you've locked. A picked day is filled with a tick; days a
 * Fill would add have a dashed outline. With a mouse, shift-click picks a run and dragging picks or drops one; with
 * the keyboard, arrows move, Space picks, Shift+Space picks a run and Page Up/Down change month.
 */
export function MonthGrid({
    months,
    today,
    sel,
    preview,
    entriesOn,
    jobDays,
    lockedYears,
    ownJobId,
    askDone,
    onTap,
    onRange,
    onDrag,
    onMonth,
    onFocusDate,
}: {
    months: string[];
    today: string;
    sel: Selection;
    /** Days a Fill would add. */
    preview: Set<string>;
    entriesOn: (date: string) => MonthEntry[];
    jobDays: Record<string, JobDayMark[]>;
    lockedYears: LockedYear[];
    /** On a job's page: that job's own days, drawn solid. */
    ownJobId?: number;
    /** Jobs whose last day has finished and that are still booked ("Done?"), by that last day: a "?" on its bar. */
    askDone?: Record<string, number[]>;
    onTap: (date: string) => void;
    onRange: (from: string, to: string) => void;
    onDrag: (from: string, to: string, mode: 'pick' | 'drop') => void;
    onMonth: (delta: number) => void;
    onFocusDate?: (date: string) => void;
}) {
    const shown = months[0];
    const [focus, setFocus] = useState(() => (monthOf(today) === shown ? today : `${shown}-01`));
    const cells = useRef(new Map<string, HTMLButtonElement>());
    const wantFocus = useRef(false);
    const drag = useRef<{ from: string; to: string; mode: 'pick' | 'drop'; moved: boolean } | null>(null);
    const [dragging, setDragging] = useState<{ from: string; to: string } | null>(null);
    const suppressClick = useRef(false);

    // The focus stays in the months on screen: changing month moves it to the same day there (or the 1st).
    // Once the months on screen change, the focus stays on a day there: the day it was moved to (Page Down), else the
    // 1st (or today).
    useEffect(() => {
        setFocus((f) => (months.includes(monthOf(f)) ? f : monthOf(today) === shown ? today : `${shown}-01`));
    }, [months, shown, today]);

    useEffect(() => {
        if (!wantFocus.current) return;
        const cell = cells.current.get(focus);
        if (!cell) return; // the month is still on its way
        wantFocus.current = false;
        cell.focus();
    }, [focus, months]);

    // A drag ends wherever the mouse is let go, even off the grid.
    useEffect(() => {
        const up = () => {
            const d = drag.current;
            drag.current = null;
            setDragging(null);
            if (d && d.moved) {
                suppressClick.current = true;
                onDrag(d.from, d.to, d.mode);
                window.setTimeout(() => (suppressClick.current = false), 0);
            }
        };
        window.addEventListener('pointerup', up);
        return () => window.removeEventListener('pointerup', up);
    }, [onDrag]);

    const move = (date: string) => {
        const m = monthOf(date);
        if (!months.includes(m)) onMonth(m < shown ? -1 : 1);
        wantFocus.current = true;
        setFocus(date);
    };

    const inDrag = (date: string) => {
        if (!dragging) return false;
        const [a, b] = dragging.from <= dragging.to ? [dragging.from, dragging.to] : [dragging.to, dragging.from];
        return a <= date && date <= b;
    };

    return (
        <div className={cn('grid gap-4', months.length > 1 && 'lg:grid-cols-2')}>
            {months.map((month) => (
                <div key={month} role="group" aria-label={monthName(month)}>
                    {months.length > 1 && <p className="mb-1 text-sm font-medium">{monthName(month)}</p>}
                    <div className="grid grid-cols-7 gap-0.5" aria-hidden>
                        {WEEKDAYS.map(([short, long]) => (
                            <span key={long} title={long} className="text-muted-foreground py-1 text-center text-xs font-medium">
                                {short}
                            </span>
                        ))}
                    </div>
                    <div className="grid grid-cols-7 gap-0.5">
                        {monthCells(month).map((c) => {
                            if (!c.inMonth) return <span key={c.date} aria-hidden />;
                            const date = c.date;
                            const picked = !!sel.dates[date];
                            const entries = entriesOn(date);
                            const jobs = jobDays[date] ?? [];
                            const own = ownJobId !== undefined && jobs.some((j) => j.job_id === ownJobId);
                            const others = jobs.filter((j) => j.job_id !== ownJobId);
                            const locked = lockedYearOf(date, lockedYears);
                            const asking = jobs.filter((j) => askDone?.[date]?.includes(j.job_id));
                            const free = entries.filter(looksFree);
                            const markers = [...free.map(() => 'dot'), ...entries.filter((e) => !looksFree(e)).map(() => 'ring')].slice(0, 3);
                            const label = [
                                dayLabelLong(date),
                                date === today ? 'today' : null,
                                entries.length ? plural(entries.length, 'calendar entry', 'calendar entries') : null,
                                own ? 'a day of this job' : null,
                                ...others.map((j) => `a day of ${j.title}${j.cancelled ? ' (cancelled)' : ''}`),
                                ...asking.map((j) => `${j.title} may be done`),
                                locked ? `in ${locked.label}, which is locked` : null,
                                picked ? 'picked' : null,
                            ]
                                .filter(Boolean)
                                .join(', ');
                            return (
                                <button
                                    key={date}
                                    ref={(el) => {
                                        if (el) cells.current.set(date, el);
                                        else cells.current.delete(date);
                                    }}
                                    type="button"
                                    tabIndex={date === focus ? 0 : -1}
                                    aria-pressed={own ? undefined : picked}
                                    aria-label={label}
                                    title={entries.map((e) => e.summary).join('\n') || undefined}
                                    onFocus={() => setFocus(date)}
                                    onClick={(e) => {
                                        if (suppressClick.current) return;
                                        setFocus(date);
                                        onFocusDate?.(date);
                                        if (e.shiftKey && sel.last && sel.last !== date && !own) onRange(sel.last, date);
                                        else onTap(date);
                                    }}
                                    onPointerDown={(e) => {
                                        if (e.pointerType !== 'mouse' || e.button !== 0 || e.shiftKey || own) return;
                                        drag.current = { from: date, to: date, mode: picked ? 'drop' : 'pick', moved: false };
                                    }}
                                    onPointerEnter={(e) => {
                                        const d = drag.current;
                                        if (!d || e.pointerType !== 'mouse') return;
                                        d.to = date;
                                        d.moved = d.moved || date !== d.from;
                                        if (d.moved) setDragging({ from: d.from, to: date });
                                    }}
                                    onKeyDown={(e) => {
                                        const step: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
                                        if (e.key in step) {
                                            e.preventDefault();
                                            move(addDays(date, step[e.key]));
                                        } else if (e.key === 'PageUp' || e.key === 'PageDown') {
                                            e.preventDefault();
                                            const delta = e.key === 'PageUp' ? -1 : 1;
                                            onMonth(delta);
                                            wantFocus.current = true;
                                            const [y, m] = monthOf(date).split('-').map(Number);
                                            const target = new Date(Date.UTC(y, m - 1 + delta, 1, 12));
                                            const last = new Date(Date.UTC(y, m + delta, 0, 12)).getUTCDate();
                                            target.setUTCDate(Math.min(c.day, last));
                                            setFocus(target.toISOString().slice(0, 10));
                                        } else if (e.key === ' ' && e.shiftKey) {
                                            e.preventDefault();
                                            onFocusDate?.(date);
                                            if (sel.last && sel.last !== date && !own) onRange(sel.last, date);
                                            else onTap(date);
                                        }
                                    }}
                                    className={cn(
                                        'relative flex min-h-[52px] min-w-[44px] touch-manipulation flex-col items-center justify-start rounded-lg border pt-1 pb-2 text-sm tabular-nums select-none',
                                        'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none',
                                        picked
                                            ? 'border-sky-700 bg-sky-600 font-semibold text-white dark:border-sky-400 dark:bg-sky-500 dark:text-neutral-950'
                                            : own
                                              ? 'border-emerald-600 bg-emerald-100 text-emerald-950 dark:border-emerald-500 dark:bg-emerald-900/60 dark:text-emerald-50'
                                              : 'bg-background border-transparent',
                                        !picked && (preview.has(date) || inDrag(date)) && 'border-2 border-dashed border-sky-600 dark:border-sky-400',
                                        date === today && !picked && 'ring-foreground/70 ring-2 ring-inset',
                                        date < today && !picked && !own && 'text-muted-foreground',
                                    )}
                                >
                                    {picked && <Check className="absolute top-1 left-1 size-3" aria-hidden />}
                                    {locked && <Lock className="absolute top-1 right-1 size-3 opacity-70" aria-hidden />}
                                    {asking.length > 0 && (
                                        <span
                                            className={cn(
                                                'absolute right-0.5 bottom-0.5 flex size-3.5 items-center justify-center rounded-full text-[10px] leading-none font-bold',
                                                picked
                                                    ? 'bg-white text-sky-700'
                                                    : 'bg-amber-500 text-neutral-950 dark:bg-amber-400 dark:text-neutral-950',
                                            )}
                                            aria-hidden
                                        >
                                            ?
                                        </span>
                                    )}
                                    <span className={cn(date === today && 'underline underline-offset-2')}>{c.day}</span>
                                    <span className="mt-1 flex h-2 items-center gap-0.5" aria-hidden>
                                        {markers.map((m, i) =>
                                            m === 'dot' ? (
                                                <span key={i} className={cn('size-1.5 rounded-full', picked ? 'bg-amber-200' : 'bg-amber-500')} />
                                            ) : (
                                                <span
                                                    key={i}
                                                    className={cn(
                                                        'size-1.5 rounded-full border',
                                                        picked ? 'border-white/80' : 'border-neutral-400 dark:border-neutral-500',
                                                    )}
                                                />
                                            ),
                                        )}
                                    </span>
                                    <span className="absolute inset-x-1.5 bottom-1 flex flex-col gap-0.5" aria-hidden>
                                        {others.slice(0, 2).map((j) => (
                                            <span
                                                key={j.job_id}
                                                className={cn(
                                                    'h-0.5 rounded-full',
                                                    picked ? 'bg-emerald-200' : 'bg-emerald-600',
                                                    j.cancelled && 'opacity-40',
                                                )}
                                            />
                                        ))}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}
