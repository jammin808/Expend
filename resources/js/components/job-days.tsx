// Imported whole, so the job page's own node test (which stands in for every import by name) still loads this file.
import * as calendarPick from '@/components/calendar-pick/job-day-picker';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, useForm } from '@inertiajs/react';
import { CalendarDays, X } from 'lucide-react';

export interface JobDayRow {
    id: number;
    date: string;
    kind: string;
    start_time: string | null;
    end_time: string | null;
    note: string | null;
    source: string;
    from_calendar: boolean;
}

const label = (d: string) =>
    new Date(d + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

const hhmm = (t: string | null) => (t ? t.slice(0, 5) : '');

/** A change that wasn't saved says why, rather than doing nothing. */
const refused = (errors: Record<string, string>) => toast(Object.values(errors)[0] ?? 'That wasn’t saved. Try again.', 'error');

/**
 * A day's own start or finish time, saved when you leave the box (a de-rig finishing at 13:00 while the show ran to
 * 23:00). Once you set it, it's yours: a calendar entry or the job's own times no longer change it.
 */
function DayTime({ day: d, field, label: aria }: { day: JobDayRow; field: 'start_time' | 'end_time'; label: string }) {
    const saved = hhmm(d[field]);

    return (
        <Input
            key={`${d.id}-${field}-${saved}`}
            type="time"
            className="h-11 w-28"
            aria-label={aria}
            defaultValue={saved}
            onBlur={(e) => {
                const box = e.target;
                const value = box.value;
                if (value === saved) return;
                router.patch(
                    `/job-days/${d.id}`,
                    {
                        kind: d.kind,
                        start_time: hhmm(d.start_time) || null,
                        end_time: hhmm(d.end_time) || null,
                        note: d.note,
                        [field]: value || null,
                    },
                    {
                        preserveScroll: true,
                        onError: (errors) => {
                            box.value = saved; // the box shows the time that's kept, not one that wasn't saved
                            refused(errors);
                        },
                    },
                );
            }}
        />
    );
}

/**
 * The days a job covers, which needn't be consecutive: a rig day, show days, a de-rig a few days later. Costs,
 * stays and mileage are judged against these days.
 */
export function JobDays({
    jobId,
    days,
    kinds,
    job,
}: {
    jobId: number;
    days: JobDayRow[];
    kinds: Record<string, string>;
    /** The job itself: with it, days are added by picking them on the calendar ("Add days"). */
    job?: { title: string; starts_on: string; ends_on: string; status: string };
}) {
    const picker = !!job && job.status !== 'cancelled';
    const form = useForm({ date: '', kind: 'work', start_time: '', end_time: '' });
    const opts = { preserveScroll: true, onError: refused };
    const gaps = days.filter((d, i) => i > 0 && (Date.parse(d.date) - Date.parse(days[i - 1].date)) / 86_400_000 > 1).length;

    return (
        // #days: "Add days" (after "Not yet" on the to-do list or Telegram) lands here.
        <section id="days" className="scroll-mt-16 space-y-2">
            <div>
                <h2 className="flex items-center gap-2 font-semibold">
                    <CalendarDays className="size-4" /> Days ({days.length})
                </h2>
                <p className="text-muted-foreground text-xs">
                    {gaps > 0
                        ? 'The days in between aren’t part of the job, so costs and nights then aren’t counted for it.'
                        : 'Costs on these days and the day either side, and nights between them, are looked at for this job.'}{' '}
                    {picker
                        ? 'Add days by picking them on the calendar, with the entries that go with them.'
                        : 'Linking a calendar entry to the job adds its day.'}
                </p>
            </div>
            <ul className="divide-y rounded-xl border text-sm">
                {days.map((d) => (
                    <li key={d.id} className="flex flex-wrap items-center gap-2 p-2">
                        <span className="w-36 font-medium">{label(d.date)}</span>
                        <select
                            className="border-input bg-background h-11 rounded-md border px-2 text-sm"
                            value={d.kind}
                            aria-label="Kind of day"
                            onChange={(e) =>
                                router.patch(
                                    `/job-days/${d.id}`,
                                    {
                                        kind: e.target.value,
                                        start_time: hhmm(d.start_time) || null,
                                        end_time: hhmm(d.end_time) || null,
                                        note: d.note,
                                    },
                                    opts,
                                )
                            }
                        >
                            {Object.entries(kinds).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <DayTime day={d} field="start_time" label={`Start on ${label(d.date)}`} />
                        <span className="text-muted-foreground text-xs">–</span>
                        <DayTime day={d} field="end_time" label={`Finish on ${label(d.date)}`} />
                        {d.from_calendar && <span className="text-muted-foreground text-xs">from your calendar</span>}
                        <span className="flex-1" />
                        {days.length > 1 && (
                            <Button
                                size="icon"
                                className="size-11"
                                variant="ghost"
                                aria-label={`Remove ${label(d.date)}`}
                                onClick={() => router.delete(`/job-days/${d.id}`, opts)}
                            >
                                <X />
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            {picker && <calendarPick.JobDayPicker jobId={jobId} job={job} days={days} kinds={kinds} />}
            <details open={!picker} className="text-sm">
                <summary className="text-muted-foreground flex min-h-11 cursor-pointer items-center">Type a date instead</summary>
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/jobs/${jobId}/days`, { ...opts, onSuccess: () => form.reset() });
                    }}
                >
                    <Input
                        type="date"
                        className="h-11 w-40"
                        aria-label="Day to add"
                        value={form.data.date}
                        onChange={(e) => form.setData('date', e.target.value)}
                    />
                    <select
                        className="border-input bg-background h-11 rounded-md border px-2 text-sm"
                        aria-label="Kind of day"
                        value={form.data.kind}
                        onChange={(e) => form.setData('kind', e.target.value)}
                    >
                        {Object.entries(kinds).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </select>
                    <Input
                        type="time"
                        className="h-11 w-28"
                        aria-label="Start"
                        value={form.data.start_time}
                        onChange={(e) => form.setData('start_time', e.target.value)}
                    />
                    <Input
                        type="time"
                        className="h-11 w-28"
                        aria-label="Finish"
                        value={form.data.end_time}
                        onChange={(e) => form.setData('end_time', e.target.value)}
                    />
                    <Button size="sm" variant="outline" className="h-11" disabled={!form.data.date || form.processing}>
                        Add a day
                    </Button>
                </form>
            </details>
        </section>
    );
}
