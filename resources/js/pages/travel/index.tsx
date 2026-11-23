import { CheckBadge, type Check } from '@/components/check-badge';
import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BedDouble, Car, Mail, ParkingCircle, Plane, RefreshCw, Search, TrainFront } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Evidence {
    quote: string | null;
    verified: boolean;
    note: string | null;
}

interface Item {
    id: number;
    kind: string;
    provider: string;
    reference: string | null;
    status: string;
    starts_on: string;
    ends_on: string | null;
    nights: number | null;
    start_time: string | null;
    place: string | null;
    destination: string | null;
    amount_minor: number | null;
    currency: string;
    payment: string;
    booker: string | null;
    source: string;
    evidence: Record<string, Evidence>;
    unverified_count: number;
    check: Check | null;
    document: { id: number; subject: string } | null;
    transaction: { id: number; date: string; amount_pence: number; description: string; account: string | null; review_status: string } | null;
    job: { id: number; title: string; starts_on: string } | null;
    suggestion: { decision: string; job_id: number | null; business_nights: number; nights: number; reasons: string[] } | null;
    decision: string | null;
    business_nights: number | null;
    decided_at: string | null;
    note: string | null;
}

interface Progress {
    found: number;
    new: number;
    state: string;
    error: string | null;
}

interface LogRow {
    id: number;
    search: string;
    from: string | null;
    subject: string | null;
    sent_at: string | null;
    status: string;
    error: string | null;
    document_id: number | null;
}

const icons: Record<string, typeof BedDouble> = { hotel: BedDouble, rail: TrainFront, flight: Plane, car_hire: Car, parking: ParkingCircle };

const suggestionLabel: Record<string, string> = {
    business: 'Business',
    personal: 'Personal',
    mixed: 'Part business',
    review: 'Check',
    client_booked: 'Booked by the client',
    cancelled: 'Cancelled',
};

const pill: Record<string, string> = {
    business: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    recharge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    mixed: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    personal: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    review: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    client_booked: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    cancelled: 'bg-slate-100 text-slate-500 line-through dark:bg-slate-800',
};

const paymentLabel: Record<string, string> = {
    paid: 'paid',
    pay_at_property: 'pay at the hotel',
    paid_by_other: 'paid by someone else',
    unknown: '',
};

const logStatus: Record<string, string> = {
    stored: 'Waiting to be read',
    read: 'Read',
    no_booking: 'Not a booking',
    needs_ai: 'Needs AI to read',
    failed: 'Couldn’t be read',
};

function when(i: Item): string {
    const d = (s: string) =>
        new Date(s + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    if (i.kind === 'hotel') return `${d(i.starts_on)} → ${i.ends_on ? d(i.ends_on) : '?'} · ${i.nights} night${i.nights === 1 ? '' : 's'}`;
    return `${d(i.starts_on)}${i.start_time ? ` ${i.start_time}` : ''}${i.place ? ` · ${i.place}` : ''}${i.destination ? ` → ${i.destination}` : ''}`;
}

function ItemCard({
    item,
    jobs,
    decisions,
}: {
    item: Item;
    jobs: { id: number; title: string; starts_on: string }[];
    decisions: Record<string, string>;
}) {
    const s = item.suggestion;
    const [jobId, setJobId] = useState<string>(String(item.job?.id ?? s?.job_id ?? ''));
    const [nights, setNights] = useState<string>(String(item.business_nights ?? s?.business_nights ?? 1));
    const [note, setNote] = useState(item.note ?? '');
    const [editing, setEditing] = useState(!item.decided_at);
    const Icon = icons[item.kind] ?? Car;
    const cancelled = item.status === 'cancelled';

    // The suggested choice gets the filled button, so confirming a good suggestion is one tap.
    const suggested = s?.decision === 'review' ? (s.job_id ? 'business' : 'personal') : s?.decision;
    const variant = (decision: string) =>
        item.decided_at ? (item.decision === decision ? 'default' : 'outline') : suggested === decision ? 'default' : 'outline';

    const decide = (decision: string) =>
        router.post(
            `/travel/${item.id}/decide`,
            { decision, job_id: jobId ? Number(jobId) : null, business_nights: decision === 'mixed' ? Number(nights) : null, note: note || null },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );

    const unverified = Object.entries(item.evidence).filter(([, e]) => e && !e.verified);

    return (
        <li className={cn('space-y-3 p-4', cancelled && 'opacity-60')}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 gap-3">
                    <Icon className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                    <div className="min-w-0">
                        <p className="font-medium">{item.kind === 'hotel' ? item.place || item.provider : `${item.provider}`}</p>
                        <p className="text-muted-foreground text-sm">{when(item)}</p>
                        <p className="text-muted-foreground text-xs">
                            {item.provider}
                            {item.reference ? ` · ref ${item.reference}` : ''}
                            {item.booker ? ` · booked by ${item.booker}` : ''}
                            {item.source === 'structured_data' ? ' · from the booking data' : item.source === 'ai' ? ' · read by AI' : ''}
                        </p>
                        {item.source !== 'manual' && (
                            <CheckBadge check={item.check} subject={{ type: 'travel_item', id: item.id }} className="mt-1" />
                        )}
                    </div>
                </div>
                <div className="text-right text-sm">
                    {item.amount_minor !== null && <Money pence={-item.amount_minor} currency={item.currency} />}
                    {paymentLabel[item.payment] && <p className="text-muted-foreground text-xs">{paymentLabel[item.payment]}</p>}
                </div>
            </div>

            {s && !item.decided_at && (
                <div className="bg-muted/40 space-y-1 rounded-lg p-3 text-sm">
                    <p>
                        <span className={cn('mr-2 rounded-full px-2 py-0.5 text-xs font-medium', pill[s.decision] ?? pill.review)}>
                            {suggestionLabel[s.decision] ?? s.decision}
                        </span>
                        Suggested
                        {s.job_id && jobs.find((j) => j.id === s.job_id) ? ` for ${jobs.find((j) => j.id === s.job_id)?.title}` : ''}
                    </p>
                    <ul className="text-muted-foreground list-disc space-y-0.5 pl-5 text-xs">
                        {s.reasons.map((r, i) => (
                            <li key={i}>{r}</li>
                        ))}
                    </ul>
                </div>
            )}

            {unverified.length > 0 && (
                <div className="flex gap-2 rounded-lg border border-amber-300 p-2 text-xs dark:border-amber-800">
                    <AlertTriangle className="size-4 shrink-0 text-amber-600" />
                    <div>
                        {unverified.map(([field, e]) => (
                            <p key={field}>
                                <strong>{field.replace('_', ' ')}</strong>: {e.note} {e.quote ? `(“${e.quote}”)` : ''}
                            </p>
                        ))}
                    </div>
                </div>
            )}

            <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                {item.transaction ? (
                    <span>
                        Payment: {item.transaction.date} · {item.transaction.description} · <Money pence={item.transaction.amount_pence} />
                        {item.transaction.account ? ` · ${item.transaction.account}` : ''}
                    </span>
                ) : (
                    <span>No payment matched in your statements</span>
                )}
                {item.document && (
                    <Link className="underline underline-offset-2" href={`/documents/${item.document.id}`}>
                        <Mail className="mr-1 inline size-3" />
                        {item.document.subject}
                    </Link>
                )}
            </div>

            {!cancelled &&
                (editing ? (
                    <div className="space-y-2">
                        <div className="grid gap-2 sm:grid-cols-[1fr_1fr]">
                            <NativeSelect value={jobId} onChange={(e) => setJobId(e.target.value)} aria-label="Job">
                                <option value="">No job</option>
                                {jobs.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {j.starts_on} · {j.title}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                placeholder="Note, e.g. early start, client asked (optional)"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button size="sm" variant={variant('business')} onClick={() => decide('business')} disabled={!jobId}>
                                Business
                            </Button>
                            {item.kind === 'hotel' && (item.nights ?? 1) > 1 && (
                                <span className="flex items-center gap-1">
                                    <Button size="sm" variant={variant('mixed')} onClick={() => decide('mixed')} disabled={!jobId}>
                                        Part business:
                                    </Button>
                                    <Input className="h-8 w-14" inputMode="numeric" value={nights} onChange={(e) => setNights(e.target.value)} />
                                    <span className="text-muted-foreground text-xs">of {item.nights} nights</span>
                                </span>
                            )}
                            <Button size="sm" variant={variant('personal')} onClick={() => decide('personal')}>
                                Personal
                            </Button>
                            <Button size="sm" variant={variant('client_booked')} onClick={() => decide('client_booked')}>
                                Client booked and paid
                            </Button>
                            <Button size="sm" variant={variant('recharge')} onClick={() => decide('recharge')} disabled={!jobId}>
                                Recharge to client
                            </Button>
                            {item.decided_at && (
                                <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                        {item.transaction && (
                            <p className="text-muted-foreground text-xs">Your choice is also applied to the matched payment, as your decision.</p>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', pill[item.decision ?? ''] ?? pill.review)}>
                            {decisions[item.decision ?? ''] ?? item.decision}
                        </span>
                        {item.job && <span className="text-muted-foreground">{item.job.title}</span>}
                        {item.decision === 'mixed' && <span className="text-muted-foreground">{item.business_nights} nights for work</span>}
                        {item.note && <span className="text-muted-foreground">· {item.note}</span>}
                        <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
                            Change
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => router.post(`/travel/${item.id}/reopen`, {}, { preserveScroll: true })}>
                            Reopen
                        </Button>
                    </div>
                ))}
        </li>
    );
}

export default function Travel({
    year,
    gmail,
    aiReady,
    items,
    jobs,
    log,
    homePostcode,
    decisions,
}: {
    year: string;
    gmail: {
        configured: boolean;
        connected: boolean;
        account: string | null;
        progress: Record<string, Progress>;
        searches: Record<string, string>;
        waiting: number;
    };
    aiReady: boolean;
    items: Item[];
    jobs: { id: number; title: string; starts_on: string }[];
    log: LogRow[];
    homePostcode: string | null;
    decisions: Record<string, string>;
}) {
    const { nav } = usePage<SharedData>().props;
    const [show, setShow] = useState<'open' | 'all'>('open');
    const [searches, setSearches] = useState<string[]>(['hotels', 'travel']);
    const [showLog, setShowLog] = useState(false);

    const busy = Object.values(gmail.progress).some((p) => p.state === 'running' || p.state === 'queued') || gmail.waiting > 0;
    useEffect(() => {
        if (!busy) return;
        const timer = window.setInterval(() => router.reload({ only: ['gmail', 'items', 'log'] }), 8000);
        return () => window.clearInterval(timer);
    }, [busy]);

    const open = items.filter((i) => !i.decided_at && i.status !== 'cancelled');
    const shown = show === 'open' ? open : items;
    const counts = useMemo(
        () => ({
            business: items.filter((i) => i.decision === 'business' || i.decision === 'recharge' || i.decision === 'mixed').length,
            personal: items.filter((i) => i.decision === 'personal').length,
            client: items.filter((i) => i.decision === 'client_booked').length,
        }),
        [items],
    );
    const years = nav?.years ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Stays & travel', href: '/travel' }]}>
            <Head title="Stays & travel" />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Stays & travel</h1>
                        <p className="text-muted-foreground text-sm">
                            Hotel bookings and travel receipts from Gmail, lined up with your jobs night by night. Nothing is decided until you
                            choose.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {years.map((y) => (
                            <Link
                                key={y.label}
                                href={`/travel?year=${y.label}`}
                                className={cn('rounded-full border px-3 py-1 text-sm', y.label === year && 'bg-foreground text-background')}
                            >
                                {y.label}
                            </Link>
                        ))}
                    </div>
                </div>

                <section className="space-y-3 rounded-xl border p-4">
                    {!gmail.connected ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <p>
                                <Mail className="mr-2 inline size-4" />
                                Connect Gmail so the app can find your hotel bookings, travel receipts and POs.
                            </p>
                            <Button size="sm" asChild>
                                <Link href="/settings/integrations#gmail">Set up Gmail</Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm">
                                    <Mail className="mr-2 inline size-4" />
                                    Gmail: <strong>{gmail.account}</strong>
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() => router.post('/travel/scan', { tax_year: year, searches }, { preserveScroll: true })}
                                        disabled={!searches.length}
                                    >
                                        <Search className="size-4" /> Search Gmail for {year}
                                    </Button>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm">
                                {Object.entries(gmail.searches).map(([key, label]) => (
                                    <label key={key} className="flex items-center gap-2">
                                        <Checkbox
                                            checked={searches.includes(key)}
                                            onCheckedChange={(v) => setSearches((list) => (v ? [...list, key] : list.filter((k) => k !== key)))}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            {Object.keys(gmail.progress).length > 0 && (
                                <ul className="text-muted-foreground space-y-0.5 text-xs">
                                    {Object.entries(gmail.progress).map(([key, p]) => (
                                        <li key={key}>
                                            {gmail.searches[key] ?? key}:{' '}
                                            {p.state === 'failed'
                                                ? `couldn’t finish (${p.error})`
                                                : p.state === 'partial'
                                                  ? `stopped after ${p.found} emails, ${p.new} new. Search again to carry on.`
                                                  : `${p.state === 'done' ? 'done' : 'searching…'} ${p.found} found, ${p.new} new`}
                                        </li>
                                    ))}
                                    {gmail.waiting > 0 && <li>{gmail.waiting} emails waiting to be read…</li>}
                                </ul>
                            )}
                        </>
                    )}
                    {!homePostcode && (
                        <p className="text-sm text-amber-700 dark:text-amber-400">
                            Add your home postcode in{' '}
                            <Link className="underline" href="/settings/ledger">
                                Settings › Ledger
                            </Link>{' '}
                            and postcodes on your jobs, so the night-before and night-after rules can use the distance.
                        </p>
                    )}
                    {!aiReady && gmail.connected && (
                        <p className="text-muted-foreground text-xs">
                            Without AI, only emails with booking data (most hotel and train bookings) can be read. Receipts like Uber’s need AI.
                        </p>
                    )}
                </section>

                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            className={cn('rounded-full border px-3 py-1', show === 'open' && 'bg-foreground text-background')}
                            onClick={() => setShow('open')}
                        >
                            To decide ({open.length})
                        </button>
                        <button
                            className={cn('rounded-full border px-3 py-1', show === 'all' && 'bg-foreground text-background')}
                            onClick={() => setShow('all')}
                        >
                            All ({items.length})
                        </button>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-muted-foreground">
                            {counts.business} business · {counts.personal} personal · {counts.client} client-booked
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.post('/travel/check', { tax_year: year }, { preserveScroll: true })}
                        >
                            <RefreshCw className="size-4" /> Check against jobs again
                        </Button>
                    </div>
                </div>

                <ul className="divide-y rounded-xl border">
                    {shown.map((i) => (
                        <ItemCard key={i.id} item={i} jobs={jobs} decisions={decisions} />
                    ))}
                    {shown.length === 0 && (
                        <li className="text-muted-foreground p-6 text-center text-sm">
                            {items.length === 0 ? `No stays or journeys found for ${year} yet.` : 'Everything here is decided.'}
                        </li>
                    )}
                </ul>
                <p className="text-muted-foreground text-xs">
                    TfL, taxis, trains and parking that only appear on your statements are suggested in your Inbox the same way: business on a job’s
                    travel days, personal otherwise.
                </p>

                <section className="space-y-2">
                    <button className="text-sm font-medium underline underline-offset-4" onClick={() => setShowLog((v) => !v)}>
                        {showLog ? 'Hide' : 'Show'} what the app read from Gmail ({log.length})
                    </button>
                    {showLog && (
                        <ul className="divide-y rounded-xl border text-xs">
                            {log.map((m) => (
                                <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                                    <span className="min-w-0">
                                        <span className="text-muted-foreground">{m.sent_at} · </span>
                                        {m.document_id ? (
                                            <Link className="underline underline-offset-2" href={`/documents/${m.document_id}`}>
                                                {m.subject || '(no subject)'}
                                            </Link>
                                        ) : (
                                            m.subject
                                        )}
                                        <span className="text-muted-foreground"> · {m.from}</span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <span className={cn(m.status === 'failed' && 'text-rose-600')}>{logStatus[m.status] ?? m.status}</span>
                                        {(m.status === 'failed' || m.status === 'needs_ai') && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-6 px-2"
                                                onClick={() => router.post(`/travel/messages/${m.id}/read`, {}, { preserveScroll: true })}
                                            >
                                                Read again
                                            </Button>
                                        )}
                                    </span>
                                    {m.error && <span className="text-muted-foreground w-full">{m.error}</span>}
                                </li>
                            ))}
                            {log.length === 0 && <li className="text-muted-foreground p-3">Nothing read yet.</li>}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
