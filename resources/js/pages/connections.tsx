import { CheckBadge, type Check } from '@/components/check-badge';
import { Explain } from '@/components/explain';
import { ItemDrawer } from '@/components/item-drawer';
import { JobForm } from '@/components/job-form';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day, londonToday, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { fitEntry } from '@/pages/jobs/calendar';
import { type Lookups, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BedDouble,
    Briefcase,
    CalendarDays,
    FileText,
    PoundSterling,
    ReceiptText,
    Sparkles,
    Wallet,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type NodeType = 'job' | 'candidate' | 'calendar' | 'document' | 'travel' | 'expense' | 'income' | 'invoice';
interface GNode {
    id: string;
    type: NodeType;
    label: string;
    sub: string;
    date: string;
    end?: string;
    amount?: number | null;
    href?: string;
    flags: string[];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    data: Record<string, any>;
}
interface GEdge {
    from: string;
    to: string;
    kind: 'confirmed' | 'auto' | 'possible';
    label: string | null;
}
interface Props {
    nodes: GNode[];
    edges: GEdge[];
    attention: { code: string; label: string; count: number; ids: string[] }[];
    range: { from: string; to: string; year: string | null; job: number | null; all: boolean };
    jobs: { id: number; title: string; starts_on: string }[];
    clients: { id: number; name: string }[];
    lookups: Lookups;
}

const LANES: { key: string; label: string; types: NodeType[]; icon: typeof Briefcase }[] = [
    { key: 'calendar', label: 'Calendar', types: ['calendar'], icon: CalendarDays },
    { key: 'document', label: 'Documents & POs', types: ['document'], icon: FileText },
    { key: 'job', label: 'Jobs', types: ['job', 'candidate'], icon: Briefcase },
    { key: 'travel', label: 'Stays & travel', types: ['travel'], icon: BedDouble },
    { key: 'expense', label: 'Costs', types: ['expense'], icon: Wallet },
    { key: 'income', label: 'Income & invoices', types: ['income', 'invoice'], icon: PoundSterling },
];

const NODE_STYLE: Record<NodeType, string> = {
    job: 'border-emerald-500 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-950/60',
    candidate: 'border-dashed border-violet-500 bg-violet-50 dark:bg-violet-950/50',
    calendar: 'border-sky-400 bg-sky-50 dark:border-sky-700 dark:bg-sky-950/50',
    document: 'border-indigo-300 bg-indigo-50 dark:border-indigo-700 dark:bg-indigo-950/50',
    travel: 'border-orange-300 bg-orange-50 dark:border-orange-700 dark:bg-orange-950/50',
    expense: 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900',
    income: 'border-teal-400 bg-teal-50 dark:border-teal-700 dark:bg-teal-950/50',
    invoice: 'border-teal-400 bg-white dark:border-teal-700 dark:bg-slate-900',
};

const FLAG: Record<string, { short: string; text: string; tone: string }> = {
    no_po: {
        short: 'No PO',
        text: 'No PO found: no PO email or attachment, upload or PO number is linked to this job.',
        tone: 'bg-rose-600 text-white',
    },
    not_invoiced: { short: 'Not invoiced', text: 'Finished over two weeks ago and not invoiced yet.', tone: 'bg-amber-500 text-white' },
    calendar_unallocated: {
        short: 'Not set up',
        text: 'Looks like a job, but it isn’t linked to one. Make it a job, link it to one, or mark it not a job.',
        tone: 'bg-orange-500 text-white',
    },
    cost_no_job: {
        short: 'Which job?',
        text: 'A business cost on this job’s dates that isn’t linked to any job. If it was for the job, link it so it counts as that job’s cost.',
        tone: 'bg-amber-500 text-white',
    },
    undecided_on_job_day: {
        short: 'Undecided',
        text: 'Not decided yet, and on a job’s dates: it may be a cost of that job.',
        tone: 'bg-amber-500 text-white',
    },
    stay_no_job: { short: 'No job', text: 'A stay or journey that isn’t linked to a job.', tone: 'bg-amber-500 text-white' },
    doc_no_job: { short: 'No job', text: 'A PO or job document that isn’t linked to a job.', tone: 'bg-amber-500 text-white' },
    check: { short: 'Check', text: 'An AI finding here didn’t pass the double-check against its source.', tone: 'bg-rose-600 text-white' },
    income_missing: {
        short: 'Unpaid',
        text: 'Invoice past its due date and no payment from the client is linked: chase it, or link the payment if it arrived.',
        tone: 'bg-rose-600 text-white',
    },
    payment_missing: {
        short: 'No payment',
        text: 'The booking says it was paid, but no matching payment is in your statements. Import the card or account it was paid with, or add it as a manual expense.',
        tone: 'bg-amber-500 text-white',
    },
    possible_job: { short: 'Possible', text: 'A possible job from the job finder.', tone: 'bg-violet-600 text-white' },
    po: { short: 'PO', text: 'A purchase order.', tone: 'bg-indigo-600 text-white' },
    not_job: { short: 'Not a job', text: 'You marked this “not a job”.', tone: 'bg-slate-400 text-white' },
    cancelled: { short: 'Cancelled', text: 'Cancelled job.', tone: 'bg-slate-400 text-white' },
};

const EDGE_TEXT = { confirmed: 'linked', auto: 'linked automatically', possible: 'possibly linked' };
const ZOOMS = [4, 7, 12, 20, 32];
const NODE_W = 156;
const NODE_H = 42;
const GAP = 8;
const LANE_PAD = 26;
const LEFT = 16;

const DAY = 86_400_000;
const toDays = (from: string, d: string) => Math.round((Date.parse(d) - Date.parse(from)) / DAY);

export default function Connections({ nodes, edges, attention, range, jobs, clients, lookups }: Props) {
    const { nav } = usePage<SharedData>().props;
    const origin = useMemo(() => nodes.reduce((min, n) => (n.date < min ? n.date : min), range.from), [nodes, range.from]);
    const totalDays = toDays(origin, range.to) + 1;
    const [zoom, setZoom] = useState(totalDays > 200 ? 1 : 2);
    const ppd = ZOOMS[zoom];
    const [selected, setSelected] = useState<string | null>(null);
    const [highlight, setHighlight] = useState<string | null>(null);
    const [makeJob, setMakeJob] = useState<GNode | null>(null);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const scroller = useRef<HTMLDivElement>(null);

    const byId = useMemo(() => Object.fromEntries(nodes.map((n) => [n.id, n])), [nodes]);

    // Lay nodes out on the timeline: x by date, packed into rows within each lane so they don't overlap.
    const layout = useMemo(() => {
        const pos: Record<string, { x: number; y: number; w: number }> = {};
        const lanes: { key: string; top: number; height: number }[] = [];
        let top = 30;
        for (const lane of LANES) {
            const items = nodes.filter((n) => lane.types.includes(n.type)).sort((a, b) => a.date.localeCompare(b.date) || a.id.localeCompare(b.id));
            const rows: number[] = [];
            for (const n of items) {
                const x = LEFT + toDays(origin, n.date) * ppd;
                const span = n.end ? (toDays(n.date, n.end) + 1) * ppd : 0;
                const w = Math.max(NODE_W, span);
                let r = rows.findIndex((right) => right + GAP <= x);
                if (r === -1) {
                    rows.push(0);
                    r = rows.length - 1;
                }
                rows[r] = x + w;
                pos[n.id] = { x, y: top + LANE_PAD + r * (NODE_H + GAP), w };
            }
            const height = LANE_PAD + Math.max(1, rows.length) * (NODE_H + GAP) + 10;
            lanes.push({ key: lane.key, top, height });
            top += height;
        }
        return { pos, lanes, height: top + 10, width: LEFT + totalDays * ppd + NODE_W + 40 };
    }, [nodes, ppd, origin, totalDays]);

    const connected = useMemo(() => {
        if (!selected) return null;
        const set = new Set([selected]);
        edges.forEach((e) => {
            if (e.from === selected) set.add(e.to);
            if (e.to === selected) set.add(e.from);
        });
        return set;
    }, [selected, edges]);
    const flagged = useMemo(() => (highlight ? new Set(attention.find((a) => a.code === highlight)?.ids ?? []) : null), [highlight, attention]);
    const dim = (id: string) => (connected ? !connected.has(id) : flagged ? !flagged.has(id) : false);

    const focusNode = (id: string) => {
        setSelected(id);
        const p = layout.pos[id];
        if (p && scroller.current)
            scroller.current.scrollTo({ left: Math.max(0, p.x - scroller.current.clientWidth / 2 + p.w / 2), behavior: 'smooth' });
    };

    // Start the view at today when it's in range.
    useEffect(() => {
        const today = londonToday();
        if (scroller.current && today >= range.from && today <= range.to) {
            scroller.current.scrollLeft = Math.max(0, LEFT + toDays(origin, today) * ppd - scroller.current.clientWidth * 0.7);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range.from, range.to]);

    const months = useMemo(() => {
        const out: { x: number; label: string }[] = [];
        const d = new Date(origin + 'T00:00:00Z');
        d.setUTCDate(1);
        while (d.toISOString().slice(0, 10) <= range.to) {
            const iso = d.toISOString().slice(0, 10);
            out.push({
                x: LEFT + Math.max(0, toDays(origin, iso)) * ppd,
                label: d.toLocaleDateString('en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC' }),
            });
            d.setUTCMonth(d.getUTCMonth() + 1);
        }
        return out;
    }, [origin, range.to, ppd]);

    const go = (patch: Record<string, string | number | boolean | null>) => {
        const q: Record<string, string | number | boolean | null> = { year: range.year, job: range.job, all: range.all ? 1 : null, ...patch };
        const kept = Object.entries(q).filter(([, v]) => v !== null && v !== false && v !== '');
        router.get('/connections', Object.fromEntries(kept.map(([k, v]) => [k, v === true ? 1 : v])) as Record<string, string | number>);
    };

    const sel = selected ? byId[selected] : null;
    const today = londonToday();
    // A title or venue longer than a job's is shortened, with the whole text in the notes.
    const fitted = makeJob ? fitEntry(makeJob.label, makeJob.data.location ?? null) : null;

    return (
        <AppLayout breadcrumbs={[{ title: 'Connections', href: '/connections' }]}>
            <Head title="Connections" />
            <div className="w-full space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="max-w-3xl">
                        <h1 className="text-2xl font-semibold tracking-tight">Connections</h1>
                        <p className="text-muted-foreground text-sm">
                            Your jobs in the middle, joined to their calendar entries, documents and POs, stays, costs, income and invoices, on a
                            timeline. Select anything to see what it’s linked to and fix it.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {range.job ? (
                            <Button variant="outline" size="sm" onClick={() => go({ job: null })}>
                                Show everything
                            </Button>
                        ) : (
                            <select
                                className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={range.year ?? ''}
                                onChange={(e) => go({ year: e.target.value || null })}
                                aria-label="Period"
                            >
                                <option value="">Recent months</option>
                                {nav?.years.map((y) => (
                                    <option key={y.label} value={y.label}>
                                        Tax year {y.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        <label className="flex items-center gap-1.5 text-sm">
                            <input type="checkbox" className="size-4" checked={range.all} onChange={(e) => go({ all: e.target.checked })} />
                            All calendar entries
                        </label>
                        <span className="flex">
                            <Button variant="outline" size="icon" aria-label="Zoom out" disabled={zoom === 0} onClick={() => setZoom(zoom - 1)}>
                                <ZoomOut />
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Zoom in"
                                disabled={zoom === ZOOMS.length - 1}
                                onClick={() => setZoom(zoom + 1)}
                            >
                                <ZoomIn />
                            </Button>
                        </span>
                    </div>
                </div>

                {attention.length > 0 && (
                    <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/20">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <AlertTriangle className="size-4 text-amber-600" /> Needs attention
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {attention.map((a) => (
                                <button
                                    key={a.code}
                                    type="button"
                                    onClick={() => {
                                        setSelected(null);
                                        setHighlight(highlight === a.code ? null : a.code);
                                    }}
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-xs',
                                        highlight === a.code ? 'border-amber-600 bg-amber-600 text-white' : 'bg-background hover:bg-muted',
                                    )}
                                >
                                    {a.label} ({a.count})
                                </button>
                            ))}
                        </div>
                        {highlight && (
                            <ul className="flex flex-wrap gap-1.5 text-xs">
                                {attention
                                    .find((a) => a.code === highlight)
                                    ?.ids.slice(0, 40)
                                    .map((id) => (
                                        <li key={id}>
                                            <button
                                                type="button"
                                                className="bg-background rounded-md border px-2 py-0.5 hover:underline"
                                                onClick={() => focusNode(id)}
                                            >
                                                {byId[id]?.label} · {day(byId[id]?.date, false)}
                                            </button>
                                        </li>
                                    ))}
                            </ul>
                        )}
                    </div>
                )}

                <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span className="flex items-center gap-1.5">
                        <svg width="28" height="6" aria-hidden>
                            <line x1="0" y1="3" x2="28" y2="3" className="stroke-slate-500" strokeWidth="2" />
                        </svg>
                        linked (by you, or confirmed)
                    </span>
                    <span className="flex items-center gap-1.5">
                        <svg width="28" height="6" aria-hidden>
                            <line x1="0" y1="3" x2="28" y2="3" className="stroke-sky-500" strokeWidth="2" strokeDasharray="6 4" />
                        </svg>
                        linked automatically
                    </span>
                    <span className="flex items-center gap-1.5">
                        <svg width="28" height="6" aria-hidden>
                            <line
                                x1="0"
                                y1="3"
                                x2="28"
                                y2="3"
                                className="stroke-amber-500"
                                strokeWidth="2.5"
                                strokeDasharray="1 4"
                                strokeLinecap="round"
                            />
                        </svg>
                        possible link: check it
                    </span>
                </div>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="flex overflow-hidden rounded-xl border">
                        <div className="bg-muted/30 relative w-20 shrink-0 border-r text-[11px] sm:w-36 sm:text-xs" style={{ height: layout.height }}>
                            {layout.lanes.map((l, i) => {
                                const lane = LANES[i];
                                return (
                                    <div
                                        key={l.key}
                                        className="absolute inset-x-0 flex items-start gap-1.5 border-b px-2 pt-2 font-medium"
                                        style={{ top: l.top, height: l.height }}
                                    >
                                        <lane.icon className="text-muted-foreground size-3.5 shrink-0" /> {lane.label}
                                    </div>
                                );
                            })}
                        </div>
                        <div
                            ref={scroller}
                            className="relative min-w-0 flex-1 overflow-x-auto"
                            onClick={(e) => e.target === e.currentTarget && setSelected(null)}
                        >
                            <div
                                className="relative"
                                style={{ width: layout.width, height: layout.height }}
                                onClick={(e) => e.target === e.currentTarget && setSelected(null)}
                            >
                                <svg className="pointer-events-none absolute inset-0" width={layout.width} height={layout.height} aria-hidden>
                                    {layout.lanes.map((l) => (
                                        <line
                                            key={l.key}
                                            x1="0"
                                            x2={layout.width}
                                            y1={l.top + l.height}
                                            y2={l.top + l.height}
                                            className="stroke-border"
                                        />
                                    ))}
                                    {months.map((m) => (
                                        <g key={m.label}>
                                            <line x1={m.x} x2={m.x} y1="20" y2={layout.height} className="stroke-border" strokeDasharray="2 4" />
                                            <text x={m.x + 4} y="16" className="fill-muted-foreground text-[11px]">
                                                {m.label}
                                            </text>
                                        </g>
                                    ))}
                                    {today >= range.from && today <= range.to && (
                                        <line
                                            x1={LEFT + toDays(origin, today) * ppd}
                                            x2={LEFT + toDays(origin, today) * ppd}
                                            y1="20"
                                            y2={layout.height}
                                            className="stroke-rose-400"
                                            strokeWidth="1.5"
                                        />
                                    )}
                                    {edges.map((e) => {
                                        const a = layout.pos[e.from];
                                        const b = layout.pos[e.to];
                                        if (!a || !b) return null;
                                        const [top, bottom] = a.y <= b.y ? [a, b] : [b, a];
                                        const cx = (p: typeof a, other: typeof a) =>
                                            Math.min(p.x + p.w - 14, Math.max(p.x + 14, other.x + other.w / 2));
                                        const x1 = cx(top, bottom);
                                        const x2 = cx(bottom, top);
                                        const y1 = top.y + NODE_H;
                                        const y2 = bottom.y;
                                        const mid = (y1 + y2) / 2;
                                        const on = connected
                                            ? connected.has(e.from) && connected.has(e.to) && (e.from === selected || e.to === selected)
                                            : !flagged;
                                        return (
                                            <path
                                                key={e.from + e.to}
                                                d={`M${x1},${y1} C${x1},${mid} ${x2},${mid} ${x2},${y2}`}
                                                fill="none"
                                                className={cn(
                                                    e.kind === 'confirmed'
                                                        ? 'stroke-slate-500'
                                                        : e.kind === 'auto'
                                                          ? 'stroke-sky-500'
                                                          : 'stroke-amber-500',
                                                    'transition-opacity',
                                                )}
                                                strokeWidth={on && connected ? 2.5 : e.kind === 'possible' ? 2 : 1.5}
                                                strokeDasharray={e.kind === 'auto' ? '6 4' : e.kind === 'possible' ? '1 5' : undefined}
                                                strokeLinecap="round"
                                                opacity={on ? 0.9 : 0.12}
                                            />
                                        );
                                    })}
                                </svg>
                                {nodes.map((n) => {
                                    const p = layout.pos[n.id];
                                    if (!p) return null;
                                    const warn = n.flags.filter((f) => !['po', 'not_job', 'cancelled'].includes(f));
                                    return (
                                        <button
                                            key={n.id}
                                            type="button"
                                            onClick={() => setSelected(selected === n.id ? null : n.id)}
                                            aria-label={`${n.label}, ${day(n.date)}${warn.length ? ', ' + warn.map((f) => FLAG[f]?.short).join(', ') : ''}`}
                                            className={cn(
                                                'absolute rounded-lg border px-2 py-1 text-left shadow-xs transition-opacity focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none',
                                                NODE_STYLE[n.type],
                                                n.flags.includes('no_po') && 'ring-2 ring-rose-500',
                                                n.flags.includes('calendar_unallocated') && 'ring-2 ring-orange-500',
                                                selected === n.id && 'ring-2 ring-sky-600',
                                                (n.flags.includes('not_job') || n.flags.includes('cancelled')) && 'opacity-60',
                                                dim(n.id) && 'opacity-25',
                                            )}
                                            style={{ left: p.x, top: p.y, width: p.w, height: NODE_H }}
                                        >
                                            <span className="flex min-w-0 items-center gap-1 overflow-hidden text-xs leading-tight font-medium">
                                                {n.type === 'candidate' && <Sparkles className="size-3 shrink-0 text-violet-600" />}
                                                <span className="truncate">{n.label}</span>
                                            </span>
                                            <span className="text-muted-foreground flex min-w-0 items-center gap-1 overflow-hidden text-[10px] leading-tight">
                                                <span className="truncate">
                                                    {n.amount !== undefined && n.amount !== null ? money(n.amount) + ' · ' : ''}
                                                    {n.sub || day(n.date, false)}
                                                </span>
                                            </span>
                                            {(warn.length > 0 || n.flags.includes('po')) && (
                                                <span className="absolute -top-2 right-1 flex gap-0.5">
                                                    {[...(n.flags.includes('po') ? ['po'] : []), ...warn].slice(0, 2).map((f) => (
                                                        <span
                                                            key={f}
                                                            className={cn('rounded px-1 text-[9px] leading-[14px] font-semibold', FLAG[f]?.tone)}
                                                        >
                                                            {FLAG[f]?.short ?? f}
                                                        </span>
                                                    ))}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    <aside className="space-y-3 rounded-xl border p-4 text-sm lg:sticky lg:top-4 lg:self-start">
                        {!sel ? (
                            <div className="text-muted-foreground space-y-2">
                                <p>Select a job, entry, document, stay or cost to see its links and what to do.</p>
                                <p>
                                    {nodes.filter((n) => n.type === 'job').length} jobs, {edges.length} links between {day(range.from)} and{' '}
                                    {day(range.to)}.
                                </p>
                            </div>
                        ) : (
                            <NodePanel
                                key={sel.id}
                                n={sel}
                                edges={edges.filter((e) => e.from === sel.id || e.to === sel.id)}
                                byId={byId}
                                jobs={jobs}
                                onSelect={focusNode}
                                onMakeJob={() => setMakeJob(sel)}
                                onDecide={() => setDrawer({ source: sel.data.source, id: sel.data.tx })}
                            />
                        )}
                    </aside>
                </div>
            </div>

            <Dialog open={!!makeJob} onOpenChange={(o) => !o && setMakeJob(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Make this calendar entry a job</DialogTitle>
                    </DialogHeader>
                    {fitted?.notes && (
                        <p className="text-muted-foreground text-sm">
                            The entry’s title or venue is too long for a job, so it’s shortened here; the whole text is in the notes.
                        </p>
                    )}
                    {makeJob && fitted && (
                        <JobForm
                            key={makeJob.id}
                            clients={clients}
                            submitUrl="/calendar/job"
                            submitLabel="Add job and link the entry"
                            extra={{ key: makeJob.data.key, date: makeJob.date }}
                            job={{
                                title: fitted.title,
                                client_id: null,
                                starts_on: makeJob.date,
                                ends_on: makeJob.end ?? makeJob.date,
                                start_time: makeJob.data.time,
                                end_time: makeJob.data.end_time,
                                location: fitted.location,
                                postcode: null,
                                fee_pence: null,
                                status: (makeJob.end ?? makeJob.date) < today ? 'done' : 'booked',
                                notes: fitted.notes,
                            }}
                            onDone={() => setMakeJob(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
            <ItemDrawer
                target={drawer}
                lookups={lookups}
                onClose={() => {
                    setDrawer(null);
                    router.reload({ only: ['nodes', 'edges', 'attention'] });
                }}
            />
        </AppLayout>
    );
}

const TYPE_LABEL: Record<NodeType, string> = {
    job: 'Job',
    candidate: 'Possible job',
    calendar: 'Calendar entry',
    document: 'Document',
    travel: 'Stay or journey',
    expense: 'Cost',
    income: 'Income',
    invoice: 'Invoice',
};

function NodePanel({
    n,
    edges,
    byId,
    jobs,
    onSelect,
    onMakeJob,
    onDecide,
}: {
    n: GNode;
    edges: GEdge[];
    byId: Record<string, GNode>;
    jobs: { id: number; title: string; starts_on: string }[];
    onSelect: (id: string) => void;
    onMakeJob: () => void;
    onDecide: () => void;
}) {
    const linkedJob = edges
        .map((e) => (e.from === n.id ? e.to : e.from))
        .find((id) => id.startsWith('job:') && edges.some((e) => (e.from === id || e.to === id) && e.kind !== 'possible'));
    const possibleJob = edges.find((e) => e.kind === 'possible' && (e.to.startsWith('job:') || e.from.startsWith('job:')));
    const nearby = [...jobs].sort(
        (a, b) => Math.abs(Date.parse(a.starts_on) - Date.parse(n.date)) - Math.abs(Date.parse(b.starts_on) - Date.parse(n.date)),
    );
    const [target, setTarget] = useState(possibleJob ? (possibleJob.to.startsWith('job:') ? possibleJob.to : possibleJob.from).slice(4) : '');
    const opts = { preserveScroll: true, preserveState: true };

    const jobPicker = (onLink: (jobId: number) => void, label = 'Link') => (
        <div className="flex gap-1.5">
            <NativeSelect className="h-8 min-w-0 flex-1 text-sm" value={target} onChange={(e) => setTarget(e.target.value)} aria-label="Job">
                <option value="">Choose a job…</option>
                {nearby.slice(0, 60).map((j) => (
                    <option key={j.id} value={j.id}>
                        {j.title} ({day(j.starts_on, false)})
                    </option>
                ))}
            </NativeSelect>
            <Button size="sm" variant="outline" disabled={!target} onClick={() => onLink(Number(target))}>
                {label}
            </Button>
        </div>
    );

    return (
        <div className="space-y-3">
            <div>
                <p className="text-muted-foreground text-xs">{TYPE_LABEL[n.type]}</p>
                <h2 className="font-semibold">{n.label}</h2>
                <p className="text-muted-foreground text-xs">
                    {n.end && n.end !== n.date ? `${day(n.date, false)} – ${day(n.end)}` : day(n.date)}
                    {n.amount !== undefined && n.amount !== null ? ` · ${money(n.amount)}` : ''}
                    {n.sub ? ` · ${n.sub}` : ''}
                </p>
            </div>

            {n.flags
                .filter((f) => FLAG[f] && f !== 'po')
                .map((f) => (
                    <p key={f} className="flex gap-2 rounded-md border border-amber-300 p-2 text-xs dark:border-amber-800">
                        <AlertTriangle className="size-4 shrink-0 text-amber-600" /> {FLAG[f].text}
                    </p>
                ))}
            {n.data.check && (
                <CheckBadge
                    check={n.data.check as Check}
                    subject={{ type: n.type === 'travel' ? 'travel_item' : 'document', id: Number(n.id.split(':')[1]) }}
                />
            )}
            {(n.type === 'expense' || n.type === 'income') && n.data.tx && (
                <p className="flex items-center gap-1.5 text-xs">
                    <Explain url={`/explain/${n.data.source}/${n.data.tx}`} /> What is this, and can I claim it?
                </p>
            )}
            {n.type === 'expense' && n.data.category && (
                <p className="text-xs">
                    {n.data.category}: {n.data.allowable === false ? 'not allowable for tax' : 'an allowable business cost'}.
                </p>
            )}

            <div>
                <p className="mb-1 text-xs font-medium">Links ({edges.length})</p>
                {edges.length === 0 ? (
                    <p className="text-muted-foreground text-xs">Not linked to anything.</p>
                ) : (
                    <ul className="space-y-1 text-xs">
                        {edges.map((e) => {
                            const other = byId[e.from === n.id ? e.to : e.from];
                            return (
                                <li key={e.from + e.to}>
                                    <button type="button" className="text-left hover:underline" onClick={() => onSelect(other.id)}>
                                        <span className={cn(e.kind === 'possible' && 'text-amber-700 dark:text-amber-400')}>{EDGE_TEXT[e.kind]}</span>
                                        : {TYPE_LABEL[other.type].toLowerCase()} <strong>{other.label}</strong>
                                    </button>
                                    {e.label && <span className="text-muted-foreground block">{e.label}</span>}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <div className="space-y-2 border-t pt-3">
                {n.href && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={n.href}>Open</Link>
                    </Button>
                )}
                {n.type === 'job' && (
                    <Button size="sm" variant="ghost" onClick={() => router.get('/connections', { job: n.id.slice(4) })}>
                        Show only this job
                    </Button>
                )}
                {n.type === 'job' && n.flags.includes('no_po') && (
                    <p className="text-muted-foreground text-xs">
                        Link the PO email or attachment from Documents, upload it on the job, or add the PO number (or mark it not needed) with Edit
                        job.
                    </p>
                )}
                {n.type === 'calendar' && !linkedJob && (
                    <>
                        <Button size="sm" onClick={onMakeJob}>
                            This is a job: add it
                        </Button>
                        <p className="text-muted-foreground text-xs">Or link it to a job you have:</p>
                        {jobPicker((jobId) =>
                            router.post(`/jobs/${jobId}/links`, { type: 'calendar', key: n.data.key, date: n.date, action: 'link' }, opts),
                        )}
                        {n.data.not_job ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post('/calendar/not-job', { key: n.data.key, date: n.date, undo: true }, opts)}
                            >
                                Undo “not a job”
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post('/calendar/not-job', { key: n.data.key, date: n.date }, opts)}
                            >
                                Not a job
                            </Button>
                        )}
                    </>
                )}
                {n.type === 'calendar' && linkedJob && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/jobs/${linkedJob.slice(4)}/links`,
                                { type: 'calendar', key: n.data.key, date: n.date, action: 'unlink' },
                                opts,
                            )
                        }
                    >
                        Unlink from {byId[linkedJob]?.label}
                    </Button>
                )}
                {n.type === 'document' &&
                    jobPicker((jobId) => router.patch(`/documents/${n.id.slice(4)}`, { work_job_id: jobId }, opts), linkedJob ? 'Move' : 'Link')}
                {n.type === 'travel' &&
                    (linkedJob ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    `/jobs/${linkedJob.slice(4)}/links`,
                                    { type: 'travel', id: Number(n.id.slice(7)), action: 'unlink' },
                                    opts,
                                )
                            }
                        >
                            Unlink from {byId[linkedJob]?.label}
                        </Button>
                    ) : (
                        jobPicker((jobId) => router.post(`/jobs/${jobId}/links`, { type: 'travel', id: Number(n.id.slice(7)), action: 'link' }, opts))
                    ))}
                {(n.type === 'expense' || n.type === 'income') && n.data.tx && (
                    <>
                        <Button size="sm" onClick={onDecide}>
                            {n.flags.length ? 'Decide or link to a job' : 'Open the decision'}
                        </Button>
                        {possibleJob && (
                            <p className="text-muted-foreground text-xs">
                                On the dates of {byId[possibleJob.to.startsWith('job:') ? possibleJob.to : possibleJob.from]?.label}. If it was for
                                that job, choose it in the decision so it counts as the job’s cost.
                            </p>
                        )}
                    </>
                )}
                {n.type === 'candidate' && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" onClick={() => router.post(`/todo/cand_add/${n.id.slice(10)}`, { fp: n.data.fp_add }, opts)}>
                                Add as a job
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post(`/todo/cand_dismiss/${n.id.slice(10)}`, { fp: n.data.fp_dismiss }, opts)}
                            >
                                Not a job
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">Or add what it was found from to a job you have:</p>
                        {jobPicker((jobId) => router.post(`/jobs/finder/${n.id.slice(10)}/merge`, { work_job_id: jobId }, opts), 'Add to job')}
                        <Link href="/jobs/finder" className="text-muted-foreground text-xs underline underline-offset-2">
                            Edit the details first
                        </Link>
                    </>
                )}
                {n.type === 'invoice' && <ReceiptText className="text-muted-foreground size-4" />}
            </div>
        </div>
    );
}
