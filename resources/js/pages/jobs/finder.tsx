import { CheckBadge, type Check } from '@/components/check-badge';
import { JobForm } from '@/components/job-form';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BedDouble, CalendarDays, FileText, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';

interface Source {
    key: string;
    type: 'calendar' | 'document' | 'travel';
    id: number | string;
    label: string;
    date: string | null;
    to: string | null;
    quote: string | null;
    verified: boolean;
    note: string | null;
    ai: boolean;
}

interface Candidate {
    id: number;
    title: string;
    client_name: string | null;
    starts_on: string;
    ends_on: string;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    postcode: string | null;
    po_number: string | null;
    score: number;
    strength: 'strong' | 'likely' | 'possible';
    found_by: 'rules' | 'ai';
    sources: Source[];
    why: string[];
    options: number[];
    edited: boolean;
    check: Check | null;
}

interface JobOption {
    id: number;
    title: string;
    client: string | null;
    starts_on: string;
    ends_on: string;
}

interface AutoLink {
    type: 'calendar' | 'document';
    id: number;
    label: string | null;
    date: string | null;
    by: 'rules' | 'ai';
    reason: string | null;
    job: { id: number; title: string };
    check: Check | null;
}

const icons = { calendar: CalendarDays, document: FileText, travel: BedDouble };
const strengthStyle = {
    strong: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    likely: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    possible: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};
const range = (a: string, b: string) => (a === b ? day(a) : `${day(a, false)} – ${day(b)}`);

export default function JobFinder({
    candidates,
    jobs,
    auto,
    lastRun,
    ai,
    clients,
}: {
    candidates: Candidate[];
    jobs: JobOption[];
    auto: AutoLink[];
    lastRun: { linked: number; proposed: number; ai_checked: number; ai_rejected: number; at: string; year: string | null } | null;
    ai: boolean;
    clients: { id: number; name: string }[];
}) {
    const { nav } = usePage<SharedData>().props;
    const [year, setYear] = useState('');
    const [running, setRunning] = useState(false);
    const [adding, setAdding] = useState<Candidate | null>(null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Jobs', href: '/jobs' },
                { title: 'Find jobs', href: '/jobs/finder' },
            ]}
        >
            <Head title="Find jobs" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="max-w-2xl">
                        <h1 className="text-2xl font-semibold tracking-tight">Find jobs</h1>
                        <p className="text-muted-foreground text-sm">
                            Looks through your calendar, the POs, job invites, production packs and call sheets read from email, and your stays and
                            journeys. Anything that clearly belongs to one of your jobs is linked; the rest is grouped into possible jobs for you to
                            add, add to a job you have, or dismiss. Links you make are never changed automatically.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <select
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={year}
                            onChange={(e) => setYear(e.target.value)}
                            aria-label="Tax year"
                        >
                            <option value="">Recent and upcoming</option>
                            {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                        </select>
                        <Button
                            disabled={running}
                            onClick={() =>
                                router.post(
                                    '/jobs/finder/run',
                                    { year: year || null },
                                    { preserveScroll: true, onStart: () => setRunning(true), onFinish: () => setRunning(false) },
                                )
                            }
                        >
                            <Search /> {running ? 'Looking…' : 'Find jobs now'}
                        </Button>
                    </div>
                </div>

                <p className="text-muted-foreground text-xs">
                    {lastRun
                        ? `Last run ${new Date(lastRun.at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}: ${lastRun.linked} linked, ${lastRun.proposed} possible.`
                        : 'Not run yet. It also runs every night and after new documents or bookings arrive.'}{' '}
                    {ai
                        ? 'Calendar entries the rules can’t call are asked about with AI; an answer only counts when the words it quotes are in the entry.'
                        : 'AI suggestions are off, so only the rules are used.'}
                    {lastRun?.ai_rejected ? ` Last run, ${lastRun.ai_rejected} AI answers failed that check and were ignored.` : ''}
                </p>

                <section className="space-y-3">
                    <h2 className="font-semibold">Possible jobs ({candidates.length})</h2>
                    {candidates.length === 0 && (
                        <p className="text-muted-foreground rounded-xl border p-4 text-sm">
                            Nothing waiting. Press Find jobs now after syncing your calendar or a Gmail search.
                        </p>
                    )}
                    {candidates.map((c) => (
                        <CandidateCard key={c.id} c={c} jobs={jobs} onAdd={() => setAdding(c)} />
                    ))}
                </section>

                {auto.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Linked automatically</h2>
                        <p className="text-muted-foreground text-sm">
                            Confirm to make the link yours, or unlink it if it’s wrong (it won’t be linked to that job again).
                        </p>
                        <ul className="divide-y rounded-xl border text-sm">
                            {auto.map((a) => {
                                const Icon = icons[a.type];
                                return (
                                    <li key={a.type + a.id} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <span className="min-w-0 flex-1 space-y-1">
                                            <span className="flex items-center gap-2">
                                                <Icon className="text-muted-foreground size-4 shrink-0" />
                                                <span className="truncate">{a.label ?? '(no title)'}</span>
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {a.date ? day(a.date) + ' · ' : ''}→{' '}
                                                <Link href={`/jobs/${a.job.id}`} className="underline underline-offset-2">
                                                    {a.job.title}
                                                </Link>{' '}
                                                · by {a.by === 'ai' ? 'AI (checked)' : 'the rules'}
                                                {a.reason ? ` · ${a.reason}` : ''}
                                            </span>
                                            <CheckBadge
                                                check={a.check}
                                                subject={{ type: a.type === 'calendar' ? 'calendar_link' : 'document', id: a.id }}
                                            />
                                        </span>
                                        <span className="flex shrink-0 gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        '/jobs/finder/links',
                                                        { type: a.type, id: a.id, action: 'confirm' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Confirm
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        '/jobs/finder/links',
                                                        { type: a.type, id: a.id, action: 'unlink' },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Unlink
                                            </Button>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}
            </div>

            <Dialog open={!!adding} onOpenChange={(o) => !o && setAdding(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add as a new job</DialogTitle>
                    </DialogHeader>
                    {adding && (
                        <JobForm
                            key={adding.id}
                            clients={clients}
                            submitUrl={`/jobs/finder/${adding.id}/accept`}
                            submitLabel="Add job and link its sources"
                            newClient={clients.some((cl) => cl.name === adding.client_name) ? null : adding.client_name}
                            job={{
                                title: adding.title,
                                client_id: clients.find((cl) => cl.name === adding.client_name)?.id ?? null,
                                starts_on: adding.starts_on,
                                ends_on: adding.ends_on,
                                start_time: adding.start_time,
                                end_time: adding.end_time,
                                location: adding.location,
                                postcode: adding.postcode,
                                fee_pence: null,
                                status: new Date(adding.ends_on) < new Date() ? 'done' : 'booked',
                                notes: null,
                                po_number: adding.po_number,
                            }}
                            onDone={() => setAdding(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function CandidateCard({ c, jobs, onAdd }: { c: Candidate; jobs: JobOption[]; onAdd: () => void }) {
    const first = c.options.find((id) => jobs.some((j) => j.id === id));
    const [target, setTarget] = useState(first ? String(first) : '');
    const near = jobs.filter((j) => c.options.includes(j.id));
    const others = jobs.filter((j) => !c.options.includes(j.id));

    return (
        <article className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="flex flex-wrap items-center gap-2 font-medium">
                        {c.title}
                        <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', strengthStyle[c.strength])}>
                            {c.strength === 'strong' ? 'Strong match' : c.strength === 'likely' ? 'Likely' : 'Possible'}
                        </span>
                        {c.found_by === 'ai' && (
                            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                <Sparkles className="size-3" /> AI helped (checked)
                            </span>
                        )}
                    </h3>
                    <p className="text-muted-foreground text-sm">
                        {range(c.starts_on, c.ends_on)}
                        {c.start_time ? ` · from ${c.start_time}` : ''}
                        {c.client_name ? ` · ${c.client_name}` : ''}
                        {c.location ? ` · ${c.location}` : ''} {c.postcode ?? ''}
                        {c.po_number ? ` · PO ${c.po_number}` : ''}
                    </p>
                </div>
                <CheckBadge check={c.check} subject={{ type: 'job_candidate', id: c.id }} />
            </div>

            <ul className="space-y-1.5 text-sm">
                {c.sources.map((s) => {
                    const Icon = icons[s.type];
                    return (
                        <li key={s.key} className="flex gap-2">
                            <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                            <span className="min-w-0">
                                {s.type === 'document' ? (
                                    <Link href={`/documents/${s.id}`} className="underline-offset-2 hover:underline">
                                        {s.label}
                                    </Link>
                                ) : (
                                    <span>{s.label}</span>
                                )}
                                <span className="text-muted-foreground text-xs">
                                    {s.date ? ` · ${s.to && s.to !== s.date ? range(s.date, s.to) : day(s.date)}` : ''}
                                    {s.note ? ` · ${s.verified ? '✓ ' : '⚠ '}${s.note}` : ''}
                                </span>
                                {s.quote && s.type === 'document' && <span className="text-muted-foreground block text-xs italic">“{s.quote}”</span>}
                            </span>
                        </li>
                    );
                })}
            </ul>
            {c.why.length > c.sources.length && <p className="text-muted-foreground text-xs">{c.why[c.why.length - 1]}</p>}

            <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                <Button size="sm" onClick={onAdd}>
                    Add as a new job
                </Button>
                <span className="flex items-center gap-1.5">
                    <NativeSelect
                        className="h-8 max-w-56 text-sm"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        aria-label="Existing job"
                    >
                        <option value="">Add to a job you have…</option>
                        {near.length > 0 && (
                            <optgroup label="Around these dates">
                                {near.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {j.title} ({day(j.starts_on, false)})
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        <optgroup label="All jobs">
                            {others.map((j) => (
                                <option key={j.id} value={j.id}>
                                    {j.title} ({day(j.starts_on)})
                                </option>
                            ))}
                        </optgroup>
                    </NativeSelect>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!target}
                        onClick={() => router.post(`/jobs/finder/${c.id}/merge`, { work_job_id: Number(target) }, { preserveScroll: true })}
                    >
                        Add to job
                    </Button>
                </span>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        const note = window.prompt('Not a job? Add a note if you like (optional):', '');
                        if (note !== null) router.post(`/jobs/finder/${c.id}/dismiss`, { note }, { preserveScroll: true });
                    }}
                >
                    Not a job
                </Button>
            </div>
        </article>
    );
}
