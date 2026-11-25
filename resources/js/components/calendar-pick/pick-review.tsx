import { JOB_FIELDS, JobForm, jobFormData, type JobFormData } from '@/components/job-form';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    covers,
    dayDefaults,
    dayLabel,
    defaultInclude,
    entryOn,
    foldTarget,
    latest as laterFinish,
    MAX_SPAN,
    plural,
    span,
    summary,
    touchedEntries,
    type PickEntry,
    type Selection,
} from '@/lib/day-pick';
import { cn } from '@/lib/utils';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Loader2, Search, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { getJson, postJson, problemText } from './api';
import { timeText } from './day-strip';
import { SheetBody, SheetFooter } from './responsive-sheet';
import { DAY_KINDS, type JobOption, type Preview, type PreviewDay, type PreviewEntry } from './types';
import { type DayPick } from './use-day-pick';

interface DayValues {
    kind: string;
    start_time: string;
    end_time: string;
    note: string;
}

/** What you've changed in the review, kept with the pick so a reload or the app reopening doesn't lose it. */
export interface ReviewDraft {
    days?: Record<string, Partial<DayValues>>;
    /** Entries on another job, or marked not a job: Move here / Include anyway. */
    takes?: Record<string, boolean>;
    /** Possible jobs made of these entries: whether their other finds (a PO, a stay) come too. */
    candidates?: Record<string, boolean>;
    job?: JobFormData | null;
    jobId?: number | null;
    /** Adding to a job: Set all and Use on all change the job's own days too. */
    alsoExisting?: boolean;
}

export const hasEdits = (d: ReviewDraft | null | undefined) =>
    !!d && (Object.keys(d.days ?? {}).length > 0 || Object.keys(d.takes ?? {}).length > 0 || Object.keys(d.candidates ?? {}).length > 0 || !!d.job);

const hhmm = (t: string | null | undefined) => (t ? t.slice(0, 5) : '');
const FORM_ID = 'pick-job-details';
const JOB_SENT = ['title', 'client_id', 'new_client', 'location', 'postcode', 'fee', 'po_number', 'po_not_required', 'status', 'notes'] as const;

/** The preview as the review reads it, whatever shape details an older or newer server uses. */
export function normalisePreview(raw: unknown): Preview & { dayMap: Record<string, PreviewDay> } {
    const p = (raw ?? {}) as Record<string, unknown>;
    const daysRaw = (p.days ?? p.dates ?? []) as PreviewDay[] | Record<string, Omit<PreviewDay, 'date'>>;
    const days: PreviewDay[] = Array.isArray(daysRaw) ? daysRaw : Object.entries(daysRaw).map(([date, d]) => ({ ...d, date }));
    const entries = ((p.entries ?? []) as Record<string, unknown>[]).map((e) => {
        // A state sent as an object ({type: 'other_job', id, title, ...}) is read as the type and the job.
        const st = e.state as unknown;
        const state = (
            typeof st === 'object' && st !== null ? ((st as { type?: string; state?: string }).type ?? (st as { state?: string }).state) : st
        ) as PreviewEntry['state'];
        const job = (e.job ?? e.other_job ?? (typeof st === 'object' && st !== null && 'id' in st ? st : null)) as PreviewEntry['job'];
        return {
            ...e,
            state: state ?? 'free',
            by: (e.by ?? (typeof st === 'object' && st !== null ? (st as { by?: string }).by : null) ?? null) as string | null,
            job: job ? { ...job, loses: job.loses ?? [], only_day: !!job.only_day, cancelled: !!job.cancelled, by: job.by ?? null } : null,
            partial: (e.partial as PreviewEntry['partial']) ?? null,
            fold_onto: (e.fold_onto as string | null) ?? null,
            default_include: !!e.default_include,
            move_default: !!e.move_default,
            recurring: !!e.recurring,
            base: (e.base as string) ?? '',
        } as PreviewEntry;
    });
    const jobsRaw = p.job_options as JobOption[] | undefined;
    return {
        ...(p as unknown as Preview),
        pick: String(p.pick ?? ''),
        days,
        dayMap: Object.fromEntries(days.map((d) => [d.date, d])),
        entries,
        related: (p.related as Preview['related']) ?? [],
        candidates: (p.candidates as Preview['candidates']) ?? [],
        partly_covered: (p.partly_covered ?? p.candidates_partial ?? 0) as number,
        job_options: jobsRaw,
    };
}

const asPick = (e: PreviewEntry): PickEntry => ({
    key: e.key,
    date: e.date,
    last_day: e.last_day,
    summary: e.summary,
    all_day: e.all_day,
    start_time: e.start_time,
    end_time: e.end_time,
    state: e.state,
    recurring: e.recurring,
    base: e.base,
    score: e.score,
    default_include: e.default_include,
});

const jobDates = (j: JobOption) => `${dayLabel(j.starts_on)}${j.ends_on !== j.starts_on ? ` to ${dayLabel(j.ends_on)}` : ''}`;

type Load =
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'ready'; preview: ReturnType<typeof normalisePreview>; refreshing?: boolean };

/**
 * Reviewing the picked days before they become a job (Create) or are added to one (Add): each day's kind and times,
 * the calendar entries that come with them, what's on other jobs, days in a locked year, and (making a job) its
 * details. What you change is kept with the pick until it's saved; a lost connection keeps everything and the retry
 * uses the same pick, so it can't make a second job.
 */
export function PickReview({
    mode,
    pick,
    clients,
    kinds = DAY_KINDS,
    today,
    active,
    fixedJob,
    onSwitchToAdd,
    onSaved,
    onBack,
}: {
    mode: 'create' | 'add';
    pick: DayPick<ReviewDraft>;
    clients: { id: number; name: string }[];
    kinds?: Record<string, string>;
    today: string;
    /** The review is on screen: the preview is loaded each time it opens. */
    active: boolean;
    /** Adding from a job's own page: that job, with no choosing. */
    fixedJob?: { id: number; title: string; starts_on: string; ends_on: string; days?: string[] } | null;
    /** "Add to Winter Expo instead". */
    onSwitchToAdd?: (jobId: number) => void;
    /** Saved: the job page is showing (or about to). */
    onSaved?: () => void;
    /** Back to picking (on a job's page). */
    onBack?: () => void;
}) {
    const draft: ReviewDraft = pick.draft ?? {};
    const setDraft = (change: (d: ReviewDraft) => ReviewDraft) => pick.setDraft((d) => change(d ?? {}));
    const jobId = mode === 'add' ? (fixedJob?.id ?? draft.jobId ?? null) : null;
    const [load, setLoad] = useState<Load>({ status: 'loading' });
    const [options, setOptions] = useState<JobOption[]>([]);
    const [found, setFound] = useState<JobOption[] | null>(null);
    const [query, setQuery] = useState('');
    const [expanded, setExpanded] = useState(false);
    const [timesFrom, setTimesFrom] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [top, setTop] = useState<string[]>([]);
    const [rowErrors, setRowErrors] = useState<Record<string, string[]>>({});
    const form = useForm<JobFormData>(draft.job ?? jobFormData());
    const seeded = useRef(!!draft.job);
    // The page the review is on: after a save, the scroll is kept only when the answer comes back to it.
    const component = usePage().component;

    // The latest pick, read when the preview is asked for.
    const latest = useRef({ dates: pick.dates, overrides: pick.sel.overrides, token: pick.sel.token });
    latest.current = { dates: pick.dates, overrides: pick.sel.overrides, token: pick.sel.token };
    const asked = useRef(0);
    const setToken = pick.setToken;

    const fetchPreview = useCallback(async () => {
        const { dates, overrides, token } = latest.current;
        if (dates.length === 0) return;
        const n = ++asked.current;
        setLoad((l) => (l.status === 'ready' ? { ...l, refreshing: true } : { status: 'loading' }));
        try {
            const raw = await postJson<unknown>('/calendar/pick/preview', {
                ...(token ? { pick: token } : {}),
                dates,
                overrides: Object.fromEntries(Object.entries(overrides).slice(0, 150)),
                ...(jobId ? { job_id: jobId } : {}),
            });
            if (n !== asked.current) return;
            const p = normalisePreview(raw);
            if (p.pick) setToken(p.pick);
            if (p.job_options) setOptions(p.job_options);
            setLoad({ status: 'ready', preview: p });
        } catch (e) {
            if (n !== asked.current) return;
            setLoad({ status: 'error', message: problemText(e) });
        }
    }, [jobId, setToken]);

    useEffect(() => {
        if (active) void fetchPreview();
    }, [active, fetchPreview]);

    // A day added in the review (Pick all 7) isn't in the preview yet: ask again.
    const missing = load.status === 'ready' && !load.preview.done && pick.dates.some((d) => !load.preview.dayMap[d]);
    useEffect(() => {
        if (active && missing) void fetchPreview();
    }, [active, missing, fetchPreview]);

    // The job's details are filled in from the entries once, unless you'd already changed them.
    const preview = load.status === 'ready' ? load.preview : null;
    useEffect(() => {
        if (!preview || seeded.current || mode !== 'create') return;
        seeded.current = true;
        const s = preview.suggest;
        const last = pick.dates[pick.dates.length - 1];
        const seed: JobFormData = {
            ...jobFormData(),
            title: s?.title ?? '',
            client_id: s?.client_id ? String(s.client_id) : '',
            new_client: s?.client_id ? '' : (s?.new_client ?? ''),
            location: s?.location ?? '',
            postcode: s?.postcode ?? '',
            po_number: s?.po_number ?? '',
            status: s?.status ?? (last && last < today ? 'done' : 'booked'),
        };
        form.setDefaults(seed);
        form.setData(seed);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- seeded once, from the first preview
    }, [preview]);

    // Your changes to the details are kept with the pick.
    useEffect(() => {
        if (mode === 'create' && seeded.current && form.isDirty) setDraft((d) => ({ ...d, job: form.data }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data, form.isDirty, mode]);

    // Searching for a job to add to.
    useEffect(() => {
        if (mode !== 'add' || fixedJob) return;
        const q = query.trim();
        if (!q) {
            setFound(null);
            return;
        }
        const timer = window.setTimeout(() => {
            getJson<JobOption[] | { jobs: JobOption[] }>('/calendar/pick/jobs', { q, near: pick.dates[0] })
                .then((r) => setFound(Array.isArray(r) ? r : (r.jobs ?? [])))
                .catch(() => setFound([]));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [query, mode, fixedJob, pick.dates]);

    const sel: Selection = pick.sel;
    const picked = sel.dates;
    const dates = pick.dates;

    const view = useMemo(() => {
        if (!preview) return null;
        const touched = touchedEntries(preview.entries, sel) as PreviewEntry[];
        // Adding to a job: the titles of every entry on it (as its search links by), not only those on the days picked.
        const jobBases =
            mode === 'add'
                ? (preview.job?.bases ?? preview.entries.filter((e) => e.state === 'this_job' && e.base.length >= 4).map((e) => e.base))
                : [];
        const tickedWith = (e: PreviewEntry, bases: string[]): boolean => {
            const own = sel.overrides[e.key];
            const take = draft.takes?.[e.key];
            switch (e.state) {
                case 'this_job':
                    return e.by === 'you' ? true : own !== 'out';
                case 'other_job':
                    // Move is on for you for an automatic link (the phone ticks it too); your own tick or untick wins.
                    return take ?? (own ? own === 'in' : e.move_default);
                case 'not_job':
                case 'not_this_job':
                    return take ?? own === 'in';
                default:
                    // One only in the small hours after a picked day is offered, not ticked for you.
                    return own ? own === 'in' : dates.some((d) => covers(e, d)) && defaultInclude(asPick(e), bases);
            }
        };
        const first = touched.filter((e) => tickedWith(e, jobBases));
        const bases = [...new Set([...jobBases, ...first.map((e) => e.base).filter((b) => b.length >= 4)])];
        const ticked = (e: PreviewEntry) => tickedWith(e, bases);
        const byDefault = (e: PreviewEntry) =>
            e.state === 'this_job'
                ? true
                : e.state === 'other_job'
                  ? e.move_default
                  : e.state === 'free'
                    ? dates.some((d) => covers(e, d)) && defaultInclude(asPick(e), bases)
                    : false;
        const included = touched.filter(ticked);
        const leftOut = touched.filter((e) => byDefault(e) && !ticked(e) && !(e.state === 'this_job' && e.by === 'you'));
        const existing = (d: string) => mode === 'add' && !!preview.dayMap[d]?.on_job;
        const values = (d: string): DayValues => {
            const onJob = preview.dayMap[d]?.on_job;
            const base: DayValues = onJob
                ? (() => {
                      // A day the job has, with a small-hours entry put on it (a load-out after the show): its finish.
                      let end: string | null = hhmm(onJob.end_time) || null;
                      for (const e of included) if (!e.all_day && e.end_time && foldTarget(e, picked) === d) end = laterFinish(end, e.end_time);
                      return { kind: onJob.kind, start_time: hhmm(onJob.start_time), end_time: end ?? '', note: onJob.note ?? '' };
                  })()
                : (() => {
                      const s = dayDefaults(d, included, picked);
                      return { kind: s.kind, start_time: s.start_time ?? '', end_time: s.end_time ?? '', note: '' };
                  })();
            return { ...base, ...(draft.days?.[d] ?? {}) };
        };
        const changed = (d: string) => {
            const onJob = preview.dayMap[d]?.on_job;
            if (!onJob) return false;
            const v = values(d);
            return (
                v.kind !== onJob.kind ||
                v.start_time !== hhmm(onJob.start_time) ||
                v.end_time !== hhmm(onJob.end_time) ||
                v.note !== (onJob.note ?? '')
            );
        };
        // Possible jobs made only of entries that end up on the job: closed with it (the server checks again).
        const inKeys = new Set(included.map((e) => e.key));
        const shownKeys = new Set(preview.entries.map((e) => e.key));
        const candidates = preview.candidates.filter((c) => (c.calendar_keys ?? []).every((k) => inKeys.has(k) || !shownKeys.has(k)));
        return { touched, included, leftOut, ticked, byDefault, existing, values, changed, bases, candidates };
    }, [preview, sel, draft.takes, draft.days, mode, picked, dates]);

    const kindOptions = Object.entries(kinds);
    const targets = (d: string) => !view?.existing(d) || !!draft.alsoExisting;
    const setDay = (d: string, change: Partial<DayValues>) =>
        setDraft((x) => ({ ...x, days: { ...(x.days ?? {}), [d]: { ...(x.days?.[d] ?? {}), ...change } } }));
    const setTake = (key: string, value: boolean) => setDraft((x) => ({ ...x, takes: { ...(x.takes ?? {}), [key]: value } }));

    /** Ticks or unticks an entry from the review: an entry on another job, or marked, is a Move or an Include anyway. */
    const toggleEntry = (e: PreviewEntry) => {
        if (!view) return;
        const on = view.ticked(e);
        if (e.state === 'other_job' || e.state === 'not_job' || e.state === 'not_this_job') setTake(e.key, !on);
        else pick.setOverride(e.key, on ? 'out' : 'in');
    };

    const payload = () => {
        if (!view || !preview) return null;
        // Entries already yours on the job go too: the days they cover are named after them.
        const entries = view.included
            .map((e) => ({
                key: e.key,
                date: e.date,
                on: entryOn(e, picked),
                seen: e.state,
                seen_job_id: e.state === 'other_job' ? (e.job?.id ?? null) : null,
                // Who had it there: a Move that was on for you doesn't happen once you've confirmed it there since.
                seen_by: e.state === 'other_job' ? (e.job?.by ?? e.by ?? null) : null,
                take: true,
            }))
            .filter((e) => e.on);
        const holds = new Set(entries.map((e) => e.on));
        const foldOnly = (d: string) => view.existing(d) && (picked[d] ?? []).every((o) => o === 'fold') && !view.changed(d) && !holds.has(d);
        const days = dates
            .filter((d) => !foldOnly(d))
            .map((d) => {
                const v = view.values(d);
                return { date: d, kind: v.kind, start_time: v.start_time || null, end_time: v.end_time || null, note: v.note.trim() || null };
            });
        const left_out = view.leftOut.map((e) => ({ key: e.key, date: e.date }));
        const candidates = view.candidates.map((c) => ({ id: c.id, others: draft.candidates?.[String(c.id)] ?? othersByDefault(c) }));
        return { pick: sel.token, days, entries, left_out, candidates };
    };

    const submit = () => {
        const body = payload();
        if (!body || !body.pick || saving) return;
        if (mode === 'add' && !jobId) {
            setTop(['Pick the job to add these days to.']);
            return;
        }
        const job: Record<string, unknown> = {};
        if (mode === 'create') for (const f of JOB_SENT) job[f] = form.data[f];
        const url = mode === 'create' ? '/calendar/pick' : `/jobs/${jobId}/days/pick`;
        let settled = false;
        let status = 0;
        // While this save is out, a lost connection or an answer that isn't a page (signed out, a server error) is
        // said here, in the sheet, instead of Inertia throwing or covering the page with the error.
        const quiet = [
            router.on('exception', (e) => e.preventDefault()),
            router.on('invalid', (e) => {
                e.preventDefault();
                status = e.detail.response.status;
            }),
        ];
        setSaving(true);
        setTop([]);
        setRowErrors({});
        form.clearErrors();
        router.post(
            url,
            { ...body, ...job },
            {
                preserveState: true,
                // Kept on a refusal or an error (the same page comes back); a new job's page opens at its top.
                preserveScroll: (page) => page.component === component,
                onSuccess: (page) => {
                    settled = true;
                    const flash = (page.props as { flash?: { error?: string | null } }).flash;
                    if (flash?.error) {
                        // Refused: nothing was saved, and everything here is kept.
                        setTop([flash.error]);
                        return;
                    }
                    pick.finished();
                    onSaved?.();
                },
                onError: (errors) => {
                    settled = true;
                    const rows: Record<string, string[]> = {};
                    const general: string[] = [];
                    const own: Partial<Record<keyof JobFormData, string>> = {};
                    for (const [field, message] of Object.entries(errors)) {
                        const m = /^days\.(\d+)\./.exec(field);
                        if (m && body.days[Number(m[1])]) {
                            const d = body.days[Number(m[1])].date;
                            (rows[d] ??= []).push(message);
                        } else if ((JOB_FIELDS as readonly string[]).includes(field)) {
                            own[field] = message;
                        } else {
                            general.push(message);
                        }
                    }
                    setRowErrors(rows);
                    if (Object.keys(rows).length) setExpanded(true);
                    if (Object.keys(own).length) form.setError(own as Record<keyof JobFormData, string>);
                    setTop([...new Set(general.length || Object.keys(rows).length ? general : ['Some of the details need a look.'])]);
                    // The first thing to put right, in view in the sheet (under its field, or the message at the top).
                    window.setTimeout(() => {
                        const at = document.querySelector('[data-field-error]') ?? document.querySelector('[data-review-alert]');
                        at?.scrollIntoView({ block: 'center' });
                    }, 50);
                },
                onFinish: () => {
                    quiet.forEach((stop) => stop());
                    setSaving(false);
                    // No answer (no signal, a timeout, a server error): everything is kept, and the retry uses the
                    // same pick, so it can't make a second job.
                    if (settled) return;
                    if (status === 419 || status === 401)
                        setTop(['Not saved: you’ve been signed out, or the page is too old. Reload the page; everything here is kept.']);
                    else if (status === 429)
                        setTop(['Not saved: that’s a lot of tries in a minute. Wait a moment, then try again; everything here is kept.']);
                    else if (status >= 500)
                        setTop(['Not saved: something went wrong on Expend’s side. Everything here is kept. Try again in a moment.']);
                    else setTop(['Not saved: no signal, or Expend didn’t answer. Everything here is kept. Try again.']);
                },
            },
        );
    };

    // ------------------------------------------------------------------ drawing

    if (dates.length === 0) {
        return (
            <SheetBody>
                <p className="text-muted-foreground text-sm">No days picked. Tap days on the calendar to pick them.</p>
                {onBack && (
                    <Button variant="outline" className="mt-3 h-11" onClick={onBack}>
                        Back to the calendar
                    </Button>
                )}
            </SheetBody>
        );
    }
    if (load.status === 'loading') {
        return (
            <SheetBody>
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <Loader2 className="size-4 animate-spin" /> Looking at {plural(dates.length, 'day')}…
                </p>
            </SheetBody>
        );
    }
    if (load.status === 'error') {
        return (
            <SheetBody className="space-y-3">
                <p className="text-sm">{load.message}</p>
                <div className="flex flex-wrap gap-2">
                    <Button className="h-11" onClick={() => void fetchPreview()}>
                        Try again
                    </Button>
                    {onBack && (
                        <Button variant="outline" className="h-11" onClick={onBack}>
                            Back to the calendar
                        </Button>
                    )}
                </div>
            </SheetBody>
        );
    }
    const p = load.preview;
    if (p.done) {
        return (
            <SheetBody className="space-y-3">
                <p className="text-sm">
                    Already {p.done.mode === 'add' ? 'added to' : 'made'}: <strong>{p.done.title}</strong>. These days were saved earlier.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button
                        className="h-11"
                        onClick={() => {
                            pick.finished();
                            router.visit(`/jobs/${p.done!.job_id}`);
                        }}
                    >
                        Open
                    </Button>
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() => {
                            pick.setToken(null);
                            latest.current = { ...latest.current, token: null };
                            void fetchPreview();
                        }}
                    >
                        Start a new pick with these days
                    </Button>
                </div>
            </SheetBody>
        );
    }
    if (!view) return null;

    const added = dates.filter((d) => !view.existing(d));
    const kept = dates.filter((d) => view.existing(d));
    const changedDays = kept.filter((d) => view.changed(d));
    const links = view.included.filter((e) => e.state !== 'this_job').length;
    const confirms = view.included.filter((e) => e.state === 'this_job' && e.by !== 'you').length;
    const chosen = fixedJob ?? options.find((j) => j.id === jobId) ?? found?.find((j) => j.id === jobId) ?? null;
    const nothing = mode === 'add' && added.length + changedDays.length + links + confirms + view.leftOut.length + view.candidates.length === 0;
    const partly = (p.partly_covered ?? 0) + p.candidates.length - view.candidates.length;
    const farDays = mode === 'add' ? dates.filter((d) => p.dayMap[d]?.too_far) : [];
    // Adding to a job: from its first day to its last can't be more than 400 days (the save would refuse it).
    const target0 = p.job ?? fixedJob ?? options.find((j) => j.id === jobId) ?? null;
    const tooLong =
        mode === 'add' &&
        (dates.some((d) => p.dayMap[d]?.too_long) || (!!target0 && span([target0.starts_on, target0.ends_on, ...dates]) > MAX_SPAN));
    const year = Number(today.slice(0, 4));
    const tooMany = view.included.length > 150 || view.leftOut.length > 150;

    // The entries already on one other job: making a new job, adding to that one may be what's meant.
    const onOther = new Map<number, { title: string; n: number }>();
    for (const e of view.touched) {
        if (e.state !== 'other_job' || !e.job || e.job.cancelled) continue;
        const o = onOther.get(e.job.id) ?? { title: e.job.title, n: 0 };
        o.n++;
        onOther.set(e.job.id, o);
    }
    const nudge = mode === 'create' && onSwitchToAdd ? [...onOther.entries()].sort((a, b) => b[1].n - a[1].n)[0] : undefined;

    const kindCounts = new Map<string, number>();
    for (const d of dates) kindCounts.set(view.values(d).kind, (kindCounts.get(view.values(d).kind) ?? 0) + 1);
    const collapsed = dates.length > 7 && !expanded;
    const timed = timesFrom ? view.values(timesFrom) : null;
    const timeTargets = timesFrom ? dates.filter((d) => d !== timesFrom && targets(d)) : [];

    // The job's days once these are added (adding to a job).
    let after: string | null = null;
    const target = p.job ?? chosen;
    if (mode === 'add' && target && added.length) {
        const all = [target.starts_on, target.ends_on, ...dates].sort();
        const first = all[0];
        const last = all[all.length - 1];
        const known = Array.isArray(target.days) ? new Set([...target.days, ...dates]) : null;
        const gaps = known ? known.size < Math.round((Date.parse(last) - Date.parse(first)) / 86_400_000) + 1 : true;
        after = `Adds ${plural(added.length, 'day')}. The job will run ${dayLabel(first)} – ${dayLabel(last)}${gaps ? ' (only its days count, not the days between)' : ''}.`;
    }

    const entryNotes = (e: PreviewEntry) => {
        const on = view.ticked(e);
        const notes: ReactNode[] = [];
        if (e.state === 'other_job' && e.job) {
            const cost = e.job.only_day
                ? `${e.job.title} keeps ${dayLabel(e.job.keeps ?? e.date)}, its only day${e.job.loses.length ? `; it loses ${e.job.loses.map(dayLabel).join(', ')}` : ''}`
                : e.job.loses.length
                  ? `${e.job.title} loses ${e.job.loses.map(dayLabel).join(', ')}`
                  : `${e.job.title} keeps its days`;
            notes.push(
                <span key="move" className="flex flex-wrap items-center gap-x-2">
                    <span>
                        {e.job.by === 'you' || e.by === 'you' ? `On ${e.job.title} (you linked it)` : `Linked automatically to ${e.job.title}`}
                        {e.job.cancelled ? ' (cancelled)' : ''}
                    </span>
                    <Toggle on={on} onClick={() => toggleEntry(e)} label="Move here" />
                    {on && <span className="text-muted-foreground">{cost}</span>}
                </span>,
            );
        }
        if (e.state === 'not_job' || e.state === 'not_this_job') {
            notes.push(
                <span key="said" className="flex flex-wrap items-center gap-x-2">
                    <span>{e.state === 'not_job' ? 'You said not a job' : 'You said it isn’t part of this job'}</span>
                    <Toggle on={on} onClick={() => toggleEntry(e)} label="Include anyway" />
                </span>,
            );
        }
        if (!on && view.byDefault(e) && e.state === 'this_job')
            notes.push(<span key="left">Left out: it comes off this job and won’t be linked to it automatically.</span>);
        if (!on && view.byDefault(e) && e.state === 'free')
            notes.push(<span key="left">Left out: it won’t be linked to this job automatically.</span>);
        if (!on && view.byDefault(e) && e.state === 'other_job' && e.job)
            notes.push(<span key="left">Left on {e.job.title}: it won’t be linked to this job automatically.</span>);
        const total = Math.round((Date.parse(e.last_day) - Date.parse(e.date)) / 86_400_000) + 1;
        const pickedOfIt = dates.filter((d) => covers(e, d)).length;
        // A timed entry that only runs into the next morning says so once (Runs to …, below).
        const runsTo = on && !e.all_day && e.last_day > e.date && !picked[e.last_day] && !foldTarget(e, picked);
        if (on && total > 1 && pickedOfIt > 0 && pickedOfIt < total && !(runsTo && total === 2)) {
            notes.push(
                <span key="part" className="flex flex-wrap items-center gap-x-2">
                    <span>
                        {e.summary}: {pickedOfIt} of {total} days
                    </span>
                    {total <= 120 && (
                        <button
                            type="button"
                            className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                            onClick={() => pick.range(e.date, e.last_day)}
                        >
                            Pick all {total}
                        </button>
                    )}
                </span>,
            );
        }
        if (runsTo) {
            notes.push(
                <span key="runs" className="flex flex-wrap items-center gap-x-2">
                    <span>
                        Runs to {dayLabel(e.last_day)} {e.end_time}; {dayLabel(e.last_day)} isn’t picked, so its finish isn’t used
                    </span>
                    <button
                        type="button"
                        className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                        onClick={() => pick.tap(e.last_day)}
                    >
                        Pick {dayLabel(e.last_day)}
                    </button>
                </span>,
            );
        }
        return notes;
    };

    const entryChip = (e: PreviewEntry, d: string) => {
        const on = view.ticked(e);
        const locked = e.state === 'this_job' && e.by === 'you';
        const folded = !covers(e, d);
        return (
            <button
                key={e.key}
                type="button"
                disabled={locked}
                aria-pressed={on}
                onClick={() => toggleEntry(e)}
                className={cn(
                    'inline-flex min-h-11 max-w-full items-center gap-1.5 rounded-full border px-3 py-1 text-left text-sm',
                    on ? 'border-sky-600 bg-sky-50 text-sky-950 dark:bg-sky-950 dark:text-sky-50' : 'border-neutral-300 dark:border-neutral-600',
                    locked && 'opacity-80',
                )}
                title={locked ? 'Linked to this job by you' : undefined}
            >
                <span
                    aria-hidden
                    className={cn(
                        'flex size-4 shrink-0 items-center justify-center rounded border',
                        on ? 'border-sky-600 bg-sky-600 text-white' : 'border-neutral-400',
                    )}
                >
                    {on && <Check className="size-3" />}
                </span>
                {/* Wraps rather than cutting off the finish and "(after midnight)". */}
                <span className="break-words whitespace-normal">
                    {folded && '＋ '}
                    {e.summary} {e.all_day ? '' : timeText(e)}
                    {folded ? ` ${dayLabel(e.date).split(' ')[0]} (after midnight)` : ''}
                </span>
            </button>
        );
    };

    const dayRow = (d: string) => {
        const pd = p.dayMap[d];
        const v = view.values(d);
        const own = view.existing(d);
        // A small-hours entry folded onto the evening before is that evening's: its chip is there only.
        const on = view.touched.filter((e) => (covers(e, d) && foldTarget(e, picked) === null) || foldTarget(e, picked) === d);
        const mine = view.touched.filter((e) => entryOn(e, picked) === d);
        const label = dayLabel(d);
        return (
            <li key={d} className="space-y-2 py-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="min-w-24 font-medium">{label}</span>
                    {mode === 'add' && jobId && (
                        <span className={cn('text-xs', own ? 'text-muted-foreground' : 'text-emerald-800 dark:text-emerald-300')}>
                            {own ? 'Already a day of this job' : 'Adds this day'}
                        </span>
                    )}
                    <span className="flex-1" />
                    {!own && (
                        <Button type="button" variant="ghost" className="h-11 px-3" onClick={() => pick.tap(d)} aria-label={`Not this day: ${label}`}>
                            <X /> Not this day
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <NativeSelect
                        className="h-11 w-full sm:w-auto sm:min-w-40"
                        aria-label={`${label} kind of day`}
                        value={v.kind}
                        onChange={(e) => setDay(d, { kind: e.target.value })}
                    >
                        {kindOptions.map(([k, name]) => (
                            <option key={k} value={k}>
                                {name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="time"
                        className="h-11 min-w-0 flex-1 sm:w-32 sm:flex-none"
                        aria-label={`${label} start time`}
                        value={v.start_time}
                        onChange={(e) => {
                            setDay(d, { start_time: e.target.value });
                            setTimesFrom(d);
                        }}
                    />
                    <span className="text-muted-foreground text-xs" aria-hidden>
                        to
                    </span>
                    <Input
                        type="time"
                        className="h-11 min-w-0 flex-1 sm:w-32 sm:flex-none"
                        aria-label={`${label} finish time`}
                        value={v.end_time}
                        onChange={(e) => {
                            setDay(d, { end_time: e.target.value });
                            setTimesFrom(d);
                        }}
                    />
                </div>
                {on.length > 0 && <div className="flex flex-wrap gap-2">{on.map((e) => entryChip(e, d))}</div>}
                <div className="space-y-1 text-xs">
                    {pd?.other_jobs.map((j) => (
                        <p key={j.id} className="text-muted-foreground">
                            Also a day of {j.title}
                            {j.cancelled ? ' (cancelled)' : ''}: it keeps that day.
                        </p>
                    ))}
                    {pd?.locked && (
                        <p className="text-amber-800 dark:text-amber-300">
                            In {pd.tax_year}, which is locked: it goes on the job; that year’s figures stay as they are.
                        </p>
                    )}
                    {mode === 'add' && pd?.too_far && (
                        <p className="text-rose-700 dark:text-rose-400">
                            More than four months from the job: make it a separate job, or drop this day.
                        </p>
                    )}
                    {mode === 'add' && pd?.too_long && !pd.too_far && (
                        <p className="text-rose-700 dark:text-rose-400">
                            The job would run for more than {MAX_SPAN} days: make it a separate job, or drop this day.
                        </p>
                    )}
                    {mine.flatMap((e) => entryNotes(e).map((n, i) => <div key={`${e.key}-${i}`}>{n}</div>))}
                    {(rowErrors[d] ?? []).map((m) => (
                        <p key={m} data-field-error className="text-rose-600">
                            {m}
                        </p>
                    ))}
                </div>
            </li>
        );
    };

    return (
        <>
            <SheetBody className="space-y-6">
                {top.length > 0 && (
                    <div
                        role="alert"
                        data-review-alert
                        className="space-y-1 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100"
                    >
                        {top.map((m) => (
                            <p key={m}>{m}</p>
                        ))}
                    </div>
                )}

                {mode === 'add' && !fixedJob && (
                    <section className="space-y-2">
                        <h3 className="font-semibold">Which job?</h3>
                        <div className="relative">
                            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                            <Input
                                className="h-11 pl-9"
                                placeholder="Find a job by name or client"
                                aria-label="Find a job by name or client"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        {(found ?? options).length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {found ? 'No jobs match that.' : 'No jobs near these days. Search for one, or make a new job.'}
                            </p>
                        ) : (
                            <div role="radiogroup" aria-label="Job" className="space-y-2">
                                {(found ?? options).map((j) => (
                                    <button
                                        key={j.id}
                                        type="button"
                                        role="radio"
                                        aria-checked={j.id === jobId}
                                        onClick={() => setDraft((x) => ({ ...x, jobId: j.id }))}
                                        className={cn(
                                            'flex min-h-11 w-full items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm',
                                            j.id === jobId ? 'border-sky-600 bg-sky-50 dark:bg-sky-950' : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'size-4 shrink-0 rounded-full border-2',
                                                j.id === jobId ? 'border-sky-600 bg-sky-600' : 'border-neutral-400',
                                            )}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">{j.title}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {jobDates(j)}
                                                {j.client ? ` · ${j.client}` : ''}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </section>
                )}

                {nudge && (
                    <p className="rounded-lg border border-sky-300 bg-sky-50 p-3 text-sm dark:border-sky-800 dark:bg-sky-950">
                        {plural(nudge[1].n, 'of these entries is', 'of these entries are')} on {nudge[1].title}.{' '}
                        <button
                            type="button"
                            className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                            onClick={() => onSwitchToAdd?.(nudge[0])}
                        >
                            Add to {nudge[1].title} instead
                        </button>
                    </p>
                )}

                <section className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-semibold">Days ({dates.length})</h3>
                        <NativeSelect
                            className="h-11 w-auto"
                            aria-label="Set every day’s kind"
                            value=""
                            onChange={(e) => {
                                const kind = e.target.value;
                                if (!kind) return;
                                setDraft((x) => {
                                    const days = { ...(x.days ?? {}) };
                                    for (const d of dates) if (targets(d)) days[d] = { ...(days[d] ?? {}), kind };
                                    return { ...x, days };
                                });
                            }}
                        >
                            <option value="">Set all to…</option>
                            {kindOptions.map(([k, name]) => (
                                <option key={k} value={k}>
                                    {name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <p className="text-muted-foreground text-sm">{summary(dates, { year })}</p>
                    {mode === 'add' && kept.length > 0 && added.length > 0 && (
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={!!draft.alsoExisting}
                                onChange={(e) => setDraft((x) => ({ ...x, alsoExisting: e.target.checked }))}
                            />
                            Also change the {plural(kept.length, 'day')} the job already has when setting every day
                        </label>
                    )}
                    {timed && (timed.start_time || timed.end_time) && timeTargets.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setDraft((x) => {
                                    const days = { ...(x.days ?? {}) };
                                    for (const d of timeTargets)
                                        days[d] = { ...(days[d] ?? {}), start_time: timed.start_time, end_time: timed.end_time };
                                    return { ...x, days };
                                });
                                setTimesFrom(null);
                            }}
                        >
                            Use {timed.start_time || '…'}–{timed.end_time || '…'} on all {plural(timeTargets.length + 1, 'day')}
                        </Button>
                    )}
                    {collapsed ? (
                        <div className="space-y-2 rounded-lg border p-3 text-sm">
                            <p>
                                {plural(dates.length, 'day')}: {[...kindCounts.entries()].map(([k, n]) => `${n} ${kinds[k] ?? k}`).join(', ')}{' '}
                                <button
                                    type="button"
                                    className="min-h-11 font-medium text-sky-700 underline dark:text-sky-400"
                                    onClick={() => setExpanded(true)}
                                >
                                    Edit days
                                </button>
                            </p>
                            <CollapsedNotes p={p} dates={dates} />
                            {view.touched.map((e) => {
                                const notes = entryNotes(e);
                                if (!notes.length) return null;
                                // Each entry's notes under its name and day, so "Move here" says which entry it moves.
                                return (
                                    <div key={e.key} className="space-y-0.5 text-xs">
                                        <p className="font-medium">
                                            {e.summary}, {dayLabel(entryOn(e, picked) ?? e.date)}
                                        </p>
                                        {notes.map((n, i) => (
                                            <div key={i}>{n}</div>
                                        ))}
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <ul className="divide-y rounded-lg border px-3">{dates.map(dayRow)}</ul>
                    )}
                    {after && <p className="text-muted-foreground text-sm">{after}</p>}
                    {tooMany && <p className="text-sm text-rose-700">That’s more than 150 calendar entries at once. Pick fewer days.</p>}
                </section>

                {mode === 'create' && (
                    <section className="space-y-2">
                        <h3 className="font-semibold">Details</h3>
                        {p.suggest?.from_candidate ? (
                            <p className="text-muted-foreground text-sm">From the possible job you edited: its title, client, venue and PO.</p>
                        ) : null}
                        <JobForm form={form} clients={clients} submitUrl="/calendar/pick" formId={FORM_ID} hideSubmit hideDates onSubmit={submit} />
                    </section>
                )}

                {view.candidates.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="font-semibold">Found with these entries</h3>
                        <ul className="space-y-2">
                            {view.candidates.map((c) => {
                                const others = draft.candidates?.[String(c.id)] ?? othersByDefault(c);
                                const vetoed = c.others.filter((o) => o.rejected);
                                return (
                                    <li key={c.id} className="space-y-1 rounded-lg border p-3 text-sm">
                                        <p>
                                            The possible job <strong>{c.title}</strong> is made of these entries.{' '}
                                            {c.others.length === 0 || others
                                                ? 'It comes off your to-do list.'
                                                : 'It stays on your to-do list, for what else was found with it.'}
                                        </p>
                                        {c.others.length > 0 && (
                                            <label className="flex min-h-11 items-start gap-2">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1 size-5"
                                                    checked={others}
                                                    onChange={(e) =>
                                                        setDraft((x) => ({
                                                            ...x,
                                                            candidates: { ...(x.candidates ?? {}), [String(c.id)]: e.target.checked },
                                                        }))
                                                    }
                                                />
                                                <span>Also link what was found with it: {c.others.map((o) => o.label || o.type).join(', ')}</span>
                                            </label>
                                        )}
                                        {vetoed.length > 0 && (
                                            <p className="text-muted-foreground">
                                                You said {vetoed.map((o) => o.label || 'a document').join(', ')}{' '}
                                                {vetoed.length === 1 ? 'isn’t' : 'aren’t'} part of this job:{' '}
                                                {vetoed.length === 1 ? 'it stays' : 'they stay'} off it either way.
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}
                {partly > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {plural(partly, 'possible job only partly matches', 'possible jobs only partly match')} these entries: they stay on your to-do
                        list.
                    </p>
                )}
                {mode === 'add' && p.job?.has_mileage && added.length > 0 && (
                    <p className="text-muted-foreground text-sm">It has mileage already: trips for the new days aren’t added automatically.</p>
                )}
            </SheetBody>
            <SheetFooter className="space-y-2">
                {nothing && <p className="text-muted-foreground text-sm">Nothing to add: these days are already the job’s, as they are.</p>}
                {farDays.length > 0 && (
                    <p className="text-sm text-rose-700 dark:text-rose-400">
                        {summary(farDays, { year })} {farDays.length === 1 ? 'is' : 'are'} more than four months from the job. Drop{' '}
                        {farDays.length === 1 ? 'it' : 'them'}, or make a separate job.
                    </p>
                )}
                {tooLong && farDays.length === 0 && (
                    <p className="text-sm text-rose-700 dark:text-rose-400">
                        The job would run for more than {MAX_SPAN} days from its first day to its last. Drop the furthest days, or make a separate
                        job.
                    </p>
                )}
                {mode === 'add' && !jobId && <p className="text-muted-foreground text-sm">Pick the job to add these days to.</p>}
                <div className="flex gap-2">
                    {onBack && (
                        <Button type="button" variant="outline" className="h-11" onClick={onBack}>
                            Back
                        </Button>
                    )}
                    <Button
                        type="button"
                        className="h-11 flex-1"
                        disabled={
                            saving ||
                            !sel.token ||
                            nothing ||
                            tooMany ||
                            farDays.length > 0 ||
                            tooLong ||
                            (mode === 'add' && !jobId) ||
                            load.refreshing
                        }
                        onClick={submit}
                    >
                        {saving && <Loader2 className="animate-spin" />}
                        {mode === 'create'
                            ? 'Create job and find what belongs to it'
                            : added.length
                              ? `Add ${plural(added.length, 'day')} and look again`
                              : changedDays.length
                                ? `Save ${plural(changedDays.length, 'day')} and look again`
                                : links + confirms
                                  ? `Link ${plural(links + confirms, 'entry', 'entries')} and look again`
                                  : nothing
                                    ? 'Nothing to add'
                                    : 'Save and look again'}
                    </Button>
                </div>
            </SheetFooter>
        </>
    );
}

/** A possible job's other finds come too unless you said one of them (a document) isn't part of this job. */
const othersByDefault = (c: { others: { rejected?: boolean }[] }) => !c.others.some((o) => o.rejected);

/** A Move here or Include anyway switch, 44 pixels high. */
function Toggle({ on, onClick, label }: { on: boolean; onClick: () => void; label: string }) {
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={onClick}
            className={cn(
                'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-sm font-medium',
                on ? 'border-sky-600 bg-sky-600 text-white dark:bg-sky-500' : 'border-neutral-300 dark:border-neutral-600',
            )}
        >
            {on && <Check className="size-4" aria-hidden />}
            {label}
        </button>
    );
}

/** With more than 7 days, what the rows would say about the days themselves. */
function CollapsedNotes({ p, dates }: { p: ReturnType<typeof normalisePreview>; dates: string[] }) {
    const others = new Map<string, string[]>();
    const locked = new Map<string, string[]>();
    for (const d of dates) {
        const pd = p.dayMap[d];
        if (!pd) continue;
        for (const j of pd.other_jobs) others.set(j.title, [...(others.get(j.title) ?? []), d]);
        if (pd.locked) locked.set(pd.tax_year, [...(locked.get(pd.tax_year) ?? []), d]);
    }
    return (
        <div className="space-y-1 text-xs">
            {[...others.entries()].map(([title, ds]) => (
                <p key={title} className="text-muted-foreground">
                    Also days of {title} ({summary(ds)}): it keeps those days.
                </p>
            ))}
            {[...locked.entries()].map(([label, ds]) => (
                <p key={label} className="text-amber-800 dark:text-amber-300">
                    {plural(ds.length, 'day')} in {label}, which is locked: they go on the job; that year’s figures stay as they are.
                </p>
            ))}
        </div>
    );
}
