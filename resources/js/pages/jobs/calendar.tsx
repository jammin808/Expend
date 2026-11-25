import { JobForm } from '@/components/job-form';
import { JobsTabs } from '@/components/jobs-tabs';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { datesBetween, londonToday } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, ChevronLeft, ChevronRight, MapPin } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Entry {
    key: string;
    date: string;
    last_day: string;
    summary: string;
    location: string | null;
    all_day: boolean;
    start_time: string | null;
    end_time: string | null;
    job: { id: number; title: string } | null;
    by: string | null;
    not_job: boolean;
    looks_like_work: boolean;
    postcode: string | null;
    client_id: number | null;
}
interface JobOption {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    client: string | null;
    /** The days it's worked (a rig and a de-rig days later have a gap between them). Older servers don't send it. */
    days?: string[];
}

/**
 * Text cut to max characters at a sensible place: after a sentence or a line (an address, before the directions that
 * follow it), else after a part of it (a comma, a dash), else after a word, with "…" to show there's more. Counted in
 * characters as the server counts them; JobFinderController::shorten cuts the same way.
 */
function shorten(text: string, max: number): string {
    const chars = Array.from(text);
    if (chars.length <= max) return text;
    const tidy = (s: string) => s.replace(/[\s,;:.\-–·|]+$/u, '');
    const length = (s: string) => Array.from(s).length;
    // One character more, so a part that ends right at the limit is kept.
    const head = chars.slice(0, max + 1).join('');
    const parts: [string[], number][] = [
        [['. ', '\n', ' | '], max / 4],
        [[', ', '; ', ' - ', ' – ', ' · '], max / 2],
    ];
    for (const [marks, least] of parts) {
        const at = Math.max(...marks.map((m) => head.lastIndexOf(m)));
        const cut = at >= 0 && length(head.slice(0, at)) >= least ? tidy(head.slice(0, at)) : '';
        if (cut) return cut;
    }
    const words = chars.slice(0, max).join('');
    const space = words.lastIndexOf(' ');
    const hard = chars.slice(0, max - 1).join('');
    const cut = tidy(space >= 0 && length(words.slice(0, space)) >= max / 2 ? words.slice(0, space) : hard);
    return (cut || hard) + '…';
}

/**
 * A calendar entry's title and venue as a job takes them (150 and 200 characters at most): the calendar keeps longer
 * ones, such as an address with directions. What's shortened is kept whole in the notes, as the server does.
 */
export function fitEntry(title: string, location: string | null): { title: string; location: string | null; notes: string | null } {
    const kept: string[] = [];
    const fit = (text: string, max: number, label: string) => {
        const t = text.trim();
        if (Array.from(t).length <= max) return t;
        kept.push(`${label}: ${t}`);
        return shorten(t, max);
    };
    return {
        title: fit(title, 150, 'Full title from your calendar'),
        location: location === null ? null : fit(location, 200, 'Full venue from your calendar'),
        notes: kept.length ? kept.join('\n') : null,
    };
}

const dayLabel = (d: string) => new Date(d + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
const monthLabel = (m: string) => new Date(m + '-01T12:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

/** Your calendar a month at a time. Click any entry to say it's a job, part of one, or not work. */
export default function JobCalendar({
    month,
    prev,
    next,
    events,
    jobs,
    clients,
    open: opening = null,
}: {
    month: string;
    prev: string;
    next: string;
    events: Entry[];
    jobs: JobOption[];
    clients: { id: number; name: string }[];
    /** An entry to open on arrival (from a possible job on the to-do list), and a job it may be part of. */
    open?: { key: string; job_id: number | null } | null;
}) {
    const [open, setOpen] = useState<Entry | null>(() => (opening ? (events.find((e) => e.key === opening.key) ?? null) : null));
    // Opened once per arrival: the address loses ?entry= and &job= straight away, so an action in the entry that comes
    // back to this page (Not a job, Not part of this job) doesn't open the same entry again.
    useEffect(() => {
        if (!opening) return;
        const url = new URL(window.location.href);
        url.searchParams.delete('entry');
        url.searchParams.delete('job');
        router.replace({ url: url.pathname + url.search, props: (props) => ({ ...props, open: null }), preserveState: true, preserveScroll: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const [workOnly, setWorkOnly] = useState(false);
    // Worked out on every render, in UK time: the page can stay open past midnight.
    const today = londonToday();
    const shown = events.filter((e) => !workOnly || e.job || e.looks_like_work);
    const days = useMemo(() => {
        const out: Record<string, Entry[]> = {};
        for (const e of shown) (out[e.date] ??= []).push(e);
        return Object.entries(out);
    }, [shown]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Jobs', href: '/jobs' },
                { title: 'Calendar', href: '/jobs/calendar' },
            ]}
        >
            <Head title="Calendar" />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
                <JobsTabs />
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="max-w-2xl space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">Calendar</h1>
                        <p className="text-muted-foreground text-sm">
                            Click any entry to say it’s a job (and add the details), that it’s part of a job you have, or that it isn’t work. Expend
                            then finds what belongs to the job: its POs and documents, emails, payments on its days, stays and the fee.
                        </p>
                    </div>
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" asChild aria-label="Previous month">
                            <Link href={`/jobs/calendar?m=${prev}`} preserveScroll>
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <span className="w-36 text-center text-sm font-medium">{monthLabel(month)}</span>
                        <Button variant="outline" size="icon" asChild aria-label="Next month">
                            <Link href={`/jobs/calendar?m=${next}`} preserveScroll>
                                <ChevronRight />
                            </Link>
                        </Button>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/jobs/calendar">Today</Link>
                        </Button>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={workOnly} onChange={(e) => setWorkOnly(e.target.checked)} />
                    Only jobs and entries that look like work
                </label>

                {days.length === 0 && (
                    <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">
                        {events.length === 0
                            ? 'No calendar entries this month. Add your calendar in Settings › Ledger, then sync it.'
                            : 'Nothing that looks like work this month.'}
                    </p>
                )}

                <div className="space-y-3">
                    {days.map(([date, list]) => (
                        <section key={date} className="grid gap-2 sm:grid-cols-[7rem_1fr]">
                            <h2 className={cn('pt-2 text-sm font-medium', date === today && 'text-sky-700 dark:text-sky-300')}>{dayLabel(date)}</h2>
                            <ul className="space-y-1.5">
                                {list.map((e) => (
                                    <li key={e.key + e.date}>
                                        <button
                                            type="button"
                                            onClick={() => setOpen(e)}
                                            className={cn(
                                                'hover:bg-muted/60 flex w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-left text-sm',
                                                e.job && 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-800 dark:bg-emerald-950/30',
                                                !e.job && e.looks_like_work && 'border-amber-300 dark:border-amber-800',
                                                e.not_job && 'opacity-60',
                                            )}
                                        >
                                            <span className="text-muted-foreground w-20 shrink-0 tabular-nums">
                                                {e.all_day
                                                    ? e.last_day !== e.date
                                                        ? `to ${dayLabel(e.last_day)}`
                                                        : 'All day'
                                                    : `${e.start_time}${e.end_time ? '–' + e.end_time : ''}`}
                                            </span>
                                            <span className="min-w-0 flex-1 font-medium">{e.summary}</span>
                                            {e.location && (
                                                <span className="text-muted-foreground flex min-w-0 items-center gap-1 truncate text-xs">
                                                    <MapPin className="size-3 shrink-0" />
                                                    {e.location}
                                                </span>
                                            )}
                                            <Status e={e} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </div>

            <Dialog open={!!open} onOpenChange={(o) => !o && setOpen(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    {open && (
                        <EntryPanel
                            key={open.key + open.date}
                            e={open}
                            jobs={jobs}
                            clients={clients}
                            today={today}
                            partOf={opening?.key === open.key && jobs.some((j) => j.id === opening.job_id) ? opening.job_id : null}
                            onDone={() => setOpen(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Status({ e }: { e: Entry }) {
    if (e.job) {
        return (
            <span className="flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100">
                <Briefcase className="size-3" /> {e.job.title}
            </span>
        );
    }
    if (e.not_job) return <span className="text-muted-foreground text-xs">Not a job</span>;
    if (e.looks_like_work)
        return (
            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                Looks like work
            </span>
        );
    return null;
}

function EntryPanel({
    e,
    jobs,
    clients,
    today,
    partOf = null,
    onDone,
}: {
    e: Entry;
    jobs: JobOption[];
    clients: { id: number; name: string }[];
    today: string;
    /** A job it looks part of (same client or place, within a few days): opens on "Part of a job I have" with it chosen. */
    partOf?: number | null;
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'new' | 'existing'>(partOf ? 'existing' : 'new');
    const near = [...jobs].sort(
        (a, b) => Math.abs(Date.parse(a.starts_on) - Date.parse(e.date)) - Math.abs(Date.parse(b.starts_on) - Date.parse(e.date)),
    );
    const [jobId, setJobId] = useState<string>(partOf ? String(partOf) : near[0] ? String(near[0].id) : '');
    const chosen = jobs.find((j) => String(j.id) === jobId);
    // The entry's dates that aren't one of the job's days yet: linking adds them. Without the job's own days, only
    // its first and last are known, and a day in between may or may not be one of them.
    const entryDates = datesBetween(e.date, e.last_day);
    const added = chosen?.days ? entryDates.filter((d) => !chosen.days!.includes(d)) : null;
    const beyond = !!chosen && (e.date < chosen.starts_on || e.last_day > chosen.ends_on);
    const opts = { preserveScroll: true, onSuccess: onDone };
    const fitted = fitEntry(e.summary, e.location);

    return (
        <>
            <DialogHeader>
                <DialogTitle>{e.summary}</DialogTitle>
            </DialogHeader>
            <p className="text-muted-foreground -mt-2 text-sm">
                {dayLabel(e.date)}
                {e.last_day !== e.date ? ` to ${dayLabel(e.last_day)}` : ''}
                {e.start_time ? ` · ${e.start_time}${e.end_time ? '–' + e.end_time : ''}` : ''}
                {e.location ? ` · ${e.location}` : ''}
            </p>

            {e.job ? (
                <div className="space-y-3 text-sm">
                    <p>
                        Part of <strong>{e.job.title}</strong>
                        {e.by === 'you' ? ' (linked by you)' : e.by ? ` (linked automatically by ${e.by})` : ''}.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild>
                            <Link href={`/jobs/${e.job.id}`}>Open the job</Link>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(`/jobs/${e.job!.id}/links`, { type: 'calendar', key: e.key, date: e.date, action: 'unlink' }, opts)
                            }
                        >
                            Not part of this job
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap gap-1 border-b text-sm" role="tablist">
                        {(
                            [
                                ['new', 'This is a job'],
                                ['existing', 'Part of a job I have'],
                            ] as const
                        ).map(([m, label]) => (
                            <button
                                key={m}
                                role="tab"
                                aria-selected={mode === m}
                                onClick={() => setMode(m)}
                                className={cn(
                                    '-mb-px border-b-2 px-3 py-2',
                                    mode === m ? 'border-foreground font-medium' : 'text-muted-foreground border-transparent',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                        <span className="flex-1" />
                        {e.not_job ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.post('/calendar/not-job', { key: e.key, date: e.date, undo: true }, opts)}
                            >
                                Undo “not a job”
                            </Button>
                        ) : (
                            <Button variant="ghost" size="sm" onClick={() => router.post('/calendar/not-job', { key: e.key, date: e.date }, opts)}>
                                Not a job
                            </Button>
                        )}
                    </div>

                    {mode === 'new' ? (
                        <>
                            <p className="text-muted-foreground text-sm">
                                Check the details and add what you know: client, fee, PO number, the days it really covers. When you save, Expend
                                looks for its POs and documents (and searches Gmail), payments on its days, stays and journeys, and the fee coming in.
                                {fitted.notes &&
                                    ' The entry’s title or venue is too long for a job, so it’s shortened here; the whole text is in the notes.'}
                            </p>
                            <JobForm
                                clients={clients}
                                submitUrl="/calendar/job"
                                submitLabel="Save the job and find what belongs to it"
                                extra={{ key: e.key, date: e.date, then: 'job' }}
                                job={{
                                    title: fitted.title,
                                    client_id: e.client_id,
                                    starts_on: e.date,
                                    ends_on: e.last_day,
                                    start_time: e.start_time,
                                    end_time: e.end_time,
                                    location: fitted.location,
                                    postcode: e.postcode,
                                    fee_pence: null,
                                    status: e.last_day < today ? 'done' : 'booked',
                                    notes: fitted.notes,
                                }}
                                onDone={onDone}
                            />
                        </>
                    ) : jobs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No jobs around this month yet. Use “This is a job” instead.</p>
                    ) : (
                        <div className="space-y-3 text-sm">
                            <select
                                className="border-input bg-background h-9 w-full rounded-md border px-2"
                                value={jobId}
                                onChange={(ev) => setJobId(ev.target.value)}
                                aria-label="Job"
                            >
                                {near.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {j.title} · {dayLabel(j.starts_on)}
                                        {j.ends_on !== j.starts_on ? ` to ${dayLabel(j.ends_on)}` : ''}
                                        {j.client ? ` · ${j.client}` : ''}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground">
                                {!chosen
                                    ? null
                                    : added === null
                                      ? beyond
                                          ? 'This day is added to the job’s days (a rig, travel or de-rig day). The days in between aren’t counted unless you add them.'
                                          : 'If this day isn’t one of the job’s days yet, it’s added to them (a rig, travel or de-rig day).'
                                      : added.length === 0
                                        ? entryDates.length > 1
                                            ? 'This entry’s days are already the job’s days.'
                                            : 'This entry’s day is already one of the job’s days.'
                                        : `${added.map(dayLabel).join(', ')} ${added.length === 1 ? 'is' : 'are'} added to the job’s days (a rig, travel or de-rig day).${beyond ? ' The days in between aren’t counted unless you add them.' : ''}`}
                            </p>
                            <Button
                                disabled={!jobId}
                                onClick={() => router.post('/calendar/link-job', { key: e.key, date: e.date, job_id: Number(jobId) }, opts)}
                            >
                                Link and find what belongs to it
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}
