import { Button } from '@/components/ui/button';
import { dayLabel, entryPicked, fillDates, includedEntries, monthName, monthOf, plural, shiftMonth } from '@/lib/day-pick';
import { londonToday } from '@/lib/format';
import { cn } from '@/lib/utils';
import { CalendarPlus, Check, ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { DayStrip, needsSay, revealStrip, timeText } from './day-strip';
import { entriesByDate, MonthGrid } from './month-grid';
import { PickBar } from './pick-bar';
import { hasEdits, PickReview, type ReviewDraft } from './pick-review';
import { ResponsiveSheet, SheetBody, SheetFooter } from './responsive-sheet';
import { type JobDayMark, type MonthEntry } from './types';
import { jobKey, useDayPick } from './use-day-pick';
import { useMinWidth } from './use-min-width';
import { useMonths } from './use-months';

/**
 * "Add days" on a job's page: the calendar in a sheet, opening on the job's first month, with the job's own days
 * drawn solid. Pick days, runs of days and entries as on the calendar page, check them, and they're added to the job
 * (with the entries you ticked linked to it). What you pick is kept for this job until it's saved.
 */
export function JobDayPicker({
    jobId,
    job,
    days,
    kinds,
}: {
    jobId: number;
    job: { title: string; starts_on: string; ends_on: string; status: string };
    days: { date: string }[];
    kinds: Record<string, string>;
}) {
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<'pick' | 'review'>('pick');
    const own = useMemo(() => new Set(days.map((d) => d.date)), [days]);
    // The job's own days are never picked by a fill, a range, a drag or an entry's circle: they're its days already.
    const pick = useDayPick<ReviewDraft>(jobKey(jobId), own);
    const today = londonToday();
    const [month, setMonth] = useState(() => monthOf(job.starts_on));
    const wide = useMinWidth(1024);
    const months = useMemo(() => (wide ? [month, shiftMonth(month, 1)] : [month]), [wide, month]);
    const cache = useMonths(null);
    const { ensure } = cache;
    const [strip, setStrip] = useState<string | null>(null);

    useEffect(() => {
        if (open) ensure(months);
    }, [open, months, ensure]);

    const known = useMemo(() => {
        const seen = new Map<string, MonthEntry>();
        for (const m of Object.values(cache.months)) for (const e of m.events) if (!seen.has(e.key)) seen.set(e.key, e);
        return [...seen.values()];
    }, [cache.months]);
    // The job's own days as the page has them now (a month fetched earlier may be from before a save).
    const jobDays = useMemo(() => {
        const out: Record<string, JobDayMark[]> = {};
        for (const m of Object.values(cache.months))
            for (const [d, list] of Object.entries(m.job_days)) out[d] = list.filter((j) => j.job_id !== jobId);
        for (const d of own) out[d] = [...(out[d] ?? []), { job_id: jobId, title: job.title, cancelled: false }];
        return out;
    }, [cache.months, own, jobId, job.title]);
    const lockedYears = useMemo(() => Object.values(cache.months)[0]?.locked_years ?? [], [cache.months]);
    const byDate = useMemo(() => entriesByDate(known, months), [known, months]);
    const entriesOn = useCallback((d: string) => byDate[d] ?? [], [byDate]);
    const included = useMemo(() => includedEntries(known, pick.sel, [], jobId), [known, pick.sel, jobId]);
    const bases = useMemo(() => included.map((e) => e.base ?? '').filter((b) => b.length >= 4), [included]);
    const visible = known
        .filter((e) => months.some((m) => monthOf(e.date) <= m && monthOf(e.last_day) >= m))
        .sort((a, b) => (a.date + (a.start_time ?? '') < b.date + (b.start_time ?? '') ? -1 : 1));
    const loading = months.some((m) => !cache.months[m] && !cache.failed[m]);
    const failed = months.find((m) => cache.failed[m]);
    const before = pick.dates.filter((d) => monthOf(d) < months[0]).length;
    const after = pick.dates.filter((d) => monthOf(d) > months[months.length - 1]).length;
    const tap = (d: string) => {
        setStrip(d);
        if (!own.has(d)) pick.tap(d);
        revealStrip();
    };
    // Days that would be new to the job (one of its own days can be in the pick only for an entry after midnight).
    const fresh = pick.dates.filter((d) => !own.has(d)).length;

    return (
        <>
            <Button type="button" variant="outline" className="h-11 w-full sm:w-auto" onClick={() => setOpen(true)}>
                <CalendarPlus /> Add days
            </Button>
            <ResponsiveSheet
                open={open}
                onOpenChange={(o) => {
                    setOpen(o);
                    if (!o) setStep('pick');
                }}
                title={
                    step === 'review'
                        ? fresh
                            ? `Add ${plural(fresh, 'day')} to ${job.title}`
                            : `Change ${job.title}’s days`
                        : `Add days to ${job.title}`
                }
                description={step === 'pick' ? 'Tap days to pick them. Tap a picked day to drop it.' : undefined}
                dirty={step === 'review' && hasEdits(pick.draft)}
                onDiscard={() => pick.setDraft(null)}
                wide
            >
                {step === 'review' ? (
                    <PickReview
                        mode="add"
                        pick={pick}
                        clients={[]}
                        kinds={kinds}
                        today={today}
                        active={open && step === 'review'}
                        fixedJob={{ id: jobId, title: job.title, starts_on: job.starts_on, ends_on: job.ends_on, days: [...own] }}
                        onBack={() => setStep('pick')}
                        onSaved={() => {
                            setOpen(false);
                            setStep('pick');
                            cache.forget(); // its entries and days have changed: fetched afresh next time
                        }}
                    />
                ) : (
                    <>
                        <SheetBody className="space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                <Button
                                    variant="outline"
                                    className="relative size-11 p-0"
                                    aria-label={before ? `Previous month (${plural(before, 'picked day')} there)` : 'Previous month'}
                                    onClick={() => setMonth((m) => shiftMonth(m, -1))}
                                >
                                    <ChevronLeft />
                                    {before > 0 && <Badge n={before} />}
                                </Button>
                                <span className="text-sm font-medium">{months.map((m) => monthName(m)).join(' – ')}</span>
                                <Button
                                    variant="outline"
                                    className="relative size-11 p-0"
                                    aria-label={after ? `Next month (${plural(after, 'picked day')} there)` : 'Next month'}
                                    onClick={() => setMonth((m) => shiftMonth(m, 1))}
                                >
                                    <ChevronRight />
                                    {after > 0 && <Badge n={after} />}
                                </Button>
                            </div>
                            {failed && (
                                <p className="text-sm">
                                    {cache.failed[failed]}{' '}
                                    <button type="button" className="min-h-11 underline" onClick={() => cache.retry(failed)}>
                                        Try again
                                    </button>
                                </p>
                            )}
                            {loading && (
                                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                    <Loader2 className="size-4 animate-spin" /> Getting your calendar…
                                </p>
                            )}
                            <MonthGrid
                                months={months}
                                today={today}
                                sel={pick.sel}
                                preview={new Set(fillDates(pick.offer))}
                                entriesOn={entriesOn}
                                jobDays={jobDays}
                                lockedYears={lockedYears}
                                ownJobId={jobId}
                                onTap={tap}
                                onRange={pick.range}
                                onDrag={pick.drag}
                                onMonth={(delta) => setMonth((m) => shiftMonth(m, delta))}
                                onFocusDate={setStrip}
                            />
                            {strip && months.includes(monthOf(strip)) && (
                                <DayStrip
                                    date={strip}
                                    sel={pick.sel}
                                    entries={known}
                                    bases={bases}
                                    ownJobId={jobId}
                                    ownDay={own.has(strip)}
                                    foldDays={own}
                                    onFold={pick.addDay}
                                    onTap={tap}
                                    onOverride={pick.setOverride}
                                    onRange={pick.range}
                                />
                            )}
                            {visible.length > 0 && (
                                <section className="space-y-1.5" aria-label="Entries this month">
                                    <h3 className="text-sm font-semibold">In your calendar</h3>
                                    <ul className="space-y-1.5">
                                        {visible.map((e) => {
                                            const on = entryPicked(pick.sel, e.key);
                                            const mine = e.job?.id === jobId;
                                            return (
                                                <li key={e.key} className="flex items-start gap-2 text-sm">
                                                    <button
                                                        type="button"
                                                        aria-pressed={on}
                                                        disabled={mine}
                                                        aria-label={
                                                            mine
                                                                ? `${e.summary} is on this job`
                                                                : on
                                                                  ? `Leave out ${e.summary} and its days`
                                                                  : `Pick ${e.summary} and its days`
                                                        }
                                                        onClick={() => (on ? pick.unpickEntry(e.key) : pick.pickEntry(e))}
                                                        className={cn(
                                                            'flex size-11 shrink-0 items-center justify-center rounded-full border-2',
                                                            on || mine
                                                                ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-400 dark:bg-sky-500'
                                                                : 'border-neutral-300 dark:border-neutral-600',
                                                            mine && 'border-emerald-600 bg-emerald-600',
                                                        )}
                                                    >
                                                        {(on || mine) && <Check className="size-5" />}
                                                    </button>
                                                    <div className="min-w-0 flex-1 py-1">
                                                        <p className="font-medium break-words">{e.summary}</p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {e.all_day && e.last_day === e.date
                                                                ? `${dayLabel(e.date)} · All day`
                                                                : e.all_day
                                                                  ? timeText(e)
                                                                  : `${dayLabel(e.date)} · ${timeText(e)}`}
                                                            {mine ? ' · on this job' : needsSay(e, jobId) ? ` · ${needsSay(e, jobId)}` : ''}
                                                        </p>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </section>
                            )}
                        </SheetBody>
                        <SheetFooter>
                            <PickBar
                                pick={pick}
                                included={included}
                                entries={known}
                                jobDays={jobDays}
                                lockedYears={lockedYears}
                                year={Number(today.slice(0, 4))}
                                ownJobId={jobId}
                                own={own}
                                inSheet
                            >
                                <Button type="button" className="h-11" disabled={pick.dates.length === 0} onClick={() => setStep('review')}>
                                    {fresh ? `Check ${plural(fresh, 'day')}` : 'Check the change'}
                                </Button>
                            </PickBar>
                            {pick.dates.length === 0 && <p className="text-muted-foreground text-sm">No days picked yet.</p>}
                        </SheetFooter>
                    </>
                )}
            </ResponsiveSheet>
        </>
    );
}

function Badge({ n }: { n: number }) {
    return (
        <span className="absolute -top-1.5 -right-1.5 min-w-5 rounded-full bg-sky-600 px-1 text-center text-xs leading-5 font-semibold text-white">
            {n}
        </span>
    );
}
