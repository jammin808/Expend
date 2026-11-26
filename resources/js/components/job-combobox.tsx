import { PickerGroup, PickerOption, PickerTrigger, usePicker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getJson, problemText, wasCalledOff } from '@/lib/api';
import { day } from '@/lib/format';
import { type JobHit, type JobRef } from '@/types';
import { useEffect, useId, useMemo, useState } from 'react';

/** What GET /lookups/jobs answers. */
interface Found {
    owed: JobHit[];
    jobs: JobHit[];
    owed_label: string | null;
}

/** How long to wait after a key before searching. */
const DEBOUNCE_MS = 250;

/** "12–13 Oct 2025 · Acme Events · Leeds Arena", the details under a job's title. */
function jobDetail(j: JobHit): string {
    const dates = j.starts_on === j.ends_on ? day(j.starts_on) : `${day(j.starts_on, false)} – ${day(j.ends_on)}`;
    return [dates, j.client, j.end_client ? `for ${j.end_client}` : null, j.location].filter(Boolean).join(' · ');
}

/**
 * Pick a job by typing its title, venue, PO number or client: searched on Expend, not limited by date. For money in
 * (forIncome, with the client), the client's finished jobs with nothing billed come first ("Still owed by Acme
 * Events"), then the rest nearest the payment's date (on), cancelled jobs last and marked. Nothing is picked until you
 * tap a row.
 */
export function JobCombobox({
    value,
    label,
    known = [],
    onChange,
    client,
    on,
    forIncome = false,
    id,
    noneLabel = 'No job',
    placeholder = 'Pick a job',
    disabled,
}: {
    value: number | null | undefined;
    /** The picked job's title, when the page knows it. */
    label?: string | null;
    /** Jobs the page has already (to name the picked one). */
    known?: JobRef[];
    onChange: (job: JobHit | null) => void;
    client?: number | null;
    on?: string | null;
    forIncome?: boolean;
    id?: string;
    noneLabel?: string;
    placeholder?: string;
    disabled?: boolean;
}) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Found | null>(null);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [picked, setPicked] = useState<JobHit | null>(null);

    const rows = useMemo(() => [null, ...(found?.owed ?? []), ...(found?.jobs ?? [])] as (JobHit | null)[], [found]);
    const picker = usePicker(rows.length, (i) => choose(rows[i] ?? null));

    useEffect(() => {
        if (!picker.open) return;
        const stop = new AbortController();
        const timer = window.setTimeout(
            () => {
                setLoading(true);
                setProblem(null);
                getJson<Found>(
                    '/lookups/jobs',
                    { q: query.trim(), client: client ?? null, for: forIncome ? 'income' : null, on: on ?? null },
                    stop.signal,
                )
                    .then((f) => setFound(f))
                    .catch((e) => !wasCalledOff(e) && setProblem(problemText(e)))
                    .finally(() => !stop.signal.aborted && setLoading(false));
            },
            query === '' ? 0 : DEBOUNCE_MS,
        );
        return () => {
            window.clearTimeout(timer);
            stop.abort();
        };
    }, [picker.open, query, client, on, forIncome]);

    const close = () => {
        picker.setOpen(false);
        setQuery('');
    };

    function choose(job: JobHit | null) {
        setPicked(job);
        onChange(job);
        close();
    }

    const name = value ? ((picked?.id === value ? picked.title : null) ?? label ?? known.find((j) => j.id === value)?.title ?? 'A job') : null;

    if (!picker.open) {
        return (
            <PickerTrigger
                id={id}
                open={false}
                disabled={disabled}
                placeholder={placeholder}
                onOpen={() => {
                    picker.setActive(0);
                    picker.setOpen(true);
                }}
            >
                {name ?? (value === null ? noneLabel : undefined)}
            </PickerTrigger>
        );
    }

    const owed = found?.owed ?? [];
    const jobs = found?.jobs ?? [];

    return (
        <div ref={picker.box} className="bg-background space-y-2 rounded-md border p-2 shadow-sm">
            <Input
                id={id}
                autoFocus
                role="combobox"
                aria-expanded
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={`${listId}-${picker.active}`}
                className="h-11"
                placeholder="Title, venue, PO or client"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    picker.setActive(0);
                }}
                onKeyDown={picker.onKeyDown}
            />
            <ul
                id={listId}
                role="listbox"
                aria-label="Jobs"
                aria-busy={loading}
                className="max-h-72 overflow-y-auto overscroll-contain rounded-md border"
            >
                <PickerOption
                    id={`${listId}-0`}
                    active={picker.active === 0}
                    selected={!value}
                    onHover={() => picker.setActive(0)}
                    onPick={() => choose(null)}
                >
                    <span className="text-muted-foreground">{noneLabel}</span>
                </PickerOption>
                {owed.length > 0 && <PickerGroup>{found?.owed_label ?? 'Still owed'}</PickerGroup>}
                {owed.map((j, k) => (
                    <JobRow key={`o${j.id}`} job={j} index={1 + k} listId={listId} picker={picker} value={value} onPick={() => choose(j)} />
                ))}
                {jobs.length > 0 && <PickerGroup>{on ? `Nearest to ${day(on)}` : 'Jobs'}</PickerGroup>}
                {jobs.map((j, k) => (
                    <JobRow key={j.id} job={j} index={1 + owed.length + k} listId={listId} picker={picker} value={value} onPick={() => choose(j)} />
                ))}
                {!loading && found && owed.length + jobs.length === 0 && <PickerGroup>No job matches that</PickerGroup>}
                {loading && !found && <PickerGroup>Searching…</PickerGroup>}
            </ul>
            {problem && (
                <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                    {problem}
                </p>
            )}
            <div className="flex justify-end">
                <Button variant="ghost" className="h-11" onClick={close}>
                    Close
                </Button>
            </div>
        </div>
    );
}

function JobRow({
    job,
    index,
    listId,
    picker,
    value,
    onPick,
}: {
    job: JobHit;
    index: number;
    listId: string;
    picker: { active: number; setActive: (i: number) => void };
    value: number | null | undefined;
    onPick: () => void;
}) {
    return (
        <PickerOption
            id={`${listId}-${index}`}
            active={picker.active === index}
            selected={job.id === value}
            onHover={() => picker.setActive(index)}
            onPick={onPick}
        >
            <span className={job.cancelled ? 'text-muted-foreground' : 'font-medium'}>{job.title}</span>
            {job.cancelled && (
                <span className="ml-2 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    cancelled
                </span>
            )}
            <span className="text-muted-foreground block text-xs">{jobDetail(job)}</span>
        </PickerOption>
    );
}
