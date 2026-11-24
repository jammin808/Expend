import { JobsTabs } from '@/components/jobs-tabs';
import { NativeSelect } from '@/components/native-select';
import { DaysEditor, OpportunityForm, type DayRow, type OpportunityFormData } from '@/components/opportunity-form';
import { OpportunityStatus, daysText, ordinal, type OpportunityRow } from '@/components/opportunity-status';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { currencyDigits, day } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Briefcase, CheckCircle2, FileText, Pencil, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface Evidence {
    type: string;
    id: number | null;
    field: string | null;
    value: string | null;
    quote: string | null;
    verified: boolean;
    href: string | null;
    source: string;
    received: string | null;
}

interface Prefill {
    title: string;
    client_id: number | null;
    client_name: string | null;
    days: DayRow[];
    venue: string | null;
    postcode: string | null;
    po_number: string | null;
    rate_amount: string;
    rate_currency: string;
    rate_unit: string;
    note: string;
    status: 'booked' | 'done';
}

interface Props {
    opportunity: OpportunityRow & { evidence: Evidence[]; edited: string[]; rejected_jobs: number };
    documents: { id: number; name: string; subject: string | null; from: string | null; kind: string; received: string | null; job: number | null }[];
    history: { id: number; at: string | null; action: string; actor: string; reason: string | null }[];
    prefill: Prefill;
    clients: { id: number; name: string }[];
    day_kinds: Record<string, string>;
    opportunity_day_kinds: Record<string, string>;
    rate_units: Record<string, string>;
    statuses: Record<string, string>;
    edit_statuses: Record<string, string>;
}

const FIELDS: [string, string][] = [
    ['status', 'Where it stands'],
    ['title', 'What it is'],
    ['client', 'Client'],
    ['contact', 'Contact'],
    ['role', 'Your role'],
    ['dates', 'Days'],
    ['venue', 'Venue'],
    ['postcode', 'Postcode'],
    ['rate', 'Rate'],
    ['reply_by', 'Reply by'],
    ['po_number', 'PO number'],
    ['hold_rank', 'Option'],
];

const ACTIONS: Record<string, string> = {
    'opportunity.found': 'Found',
    'opportunity.recorded': 'Added by you',
    'opportunity.updated': 'Updated from an email',
    'opportunity.edited': 'Changed by you',
    'opportunity.accepted': 'Made a job',
    'opportunity.declined': 'Declined',
    'opportunity.dismissed': 'Dismissed: not work',
    'opportunity.reopened': 'Reopened',
    'opportunity.expired': 'Passed with no answer',
    'opportunity.linked_to_job': 'Matched to a job you have',
    'opportunity.link_confirmed': 'You said it’s that job',
};

const ACTORS: Record<string, string> = {
    you: 'you',
    ai: 'read with AI',
    rules: 'the rules',
    matcher: 'the rules',
    assistant: 'you, through the assistant',
    telegram: 'you, in Telegram',
};

/** One piece of work you might get: every detail with the words it came from, and what you can decide about it. */
export default function OpportunityShow({
    opportunity: o,
    documents,
    history,
    prefill,
    clients,
    day_kinds,
    opportunity_day_kinds,
    rate_units,
    edit_statuses,
}: Props) {
    const [accepting, setAccepting] = useState(false);
    const [editing, setEditing] = useState(false);
    const closed = ['declined', 'dismissed', 'lost', 'expired'].includes(o.status);
    const decide = (what: 'decline' | 'dismiss') => {
        const note = window.prompt(
            what === 'decline' ? 'You said no. Add a note if you like (optional):' : 'Not work? Add a note if you like (optional):',
            '',
        );
        if (note !== null) router.post(`/opportunities/${o.id}/${what}`, { note }, { preserveScroll: true });
    };

    const value = (field: string): string | null => {
        switch (field) {
            case 'status':
                return o.status_label;
            case 'title':
                return o.title;
            case 'client':
                return o.client_name;
            case 'contact':
                return o.contact_name || o.contact_email
                    ? [o.contact_name, o.contact_email ? `<${o.contact_email}>` : null].filter(Boolean).join(' ')
                    : null;
            case 'role':
                return o.role;
            case 'dates':
                return o.days.length ? o.days.map((d) => `${day(d.date)} (${(day_kinds[d.kind] ?? d.kind).toLowerCase()})`).join(', ') : null;
            case 'venue':
                return o.venue;
            case 'postcode':
                return o.postcode;
            case 'rate':
                return o.rate?.text ?? null;
            case 'reply_by':
                return o.reply_by ? day(o.reply_by) : null;
            case 'po_number':
                return o.po_number;
            case 'hold_rank':
                return o.hold_rank ? `${ordinal(o.hold_rank)} option` : null;
        }
        return null;
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Jobs', href: '/jobs' },
                { title: 'Enquiries & offers', href: '/opportunities' },
                { title: o.title, href: `/opportunities/${o.id}` },
            ]}
        >
            <Head title={o.title} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <JobsTabs />
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight">
                            {o.title}
                            <OpportunityStatus status={o.status} label={o.status_label} />
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {daysText(o.days) || 'No dates yet'}
                            {o.client_name ? ` · ${o.client_name}` : ''}
                            {o.venue ? ` · ${o.venue}` : ''}
                            {o.rate ? ` · ${o.rate.text}` : ''}
                        </p>
                    </div>
                    {!o.job && (
                        <div className="flex flex-wrap gap-2">
                            {!closed && (
                                <Button onClick={() => setAccepting(true)}>
                                    <Briefcase /> Make it a job
                                </Button>
                            )}
                            {!['declined', 'dismissed'].includes(o.status) && (
                                <Button variant="outline" onClick={() => setEditing(true)}>
                                    <Pencil /> Edit details
                                </Button>
                            )}
                            {!closed && (
                                <>
                                    <Button variant="ghost" onClick={() => decide('decline')}>
                                        I said no
                                    </Button>
                                    <Button variant="ghost" onClick={() => decide('dismiss')}>
                                        Not work
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                </div>

                {o.accepted && o.job && (
                    <p className="flex flex-wrap items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-900 dark:bg-emerald-950/30">
                        <CheckCircle2 className="size-4 text-emerald-600" aria-hidden />
                        You made this a job:{' '}
                        <Link href={`/jobs/${o.job.id}`} className="font-medium underline underline-offset-2">
                            {o.job.title}
                        </Link>
                        {o.decided_at ? ` (${day(o.decided_at.slice(0, 10))})` : ''}
                    </p>
                )}
                {o.linked_by_rules && o.job && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                        <p>
                            Matched to your job{' '}
                            <Link href={`/jobs/${o.job.id}`} className="font-medium underline underline-offset-2">
                                {o.job.title}
                            </Link>{' '}
                            by its emails, or its dates and client or venue. Is it that job?
                        </p>
                        <span className="flex gap-2">
                            <Button size="sm" onClick={() => router.post(`/opportunities/${o.id}/link`, {}, { preserveScroll: true })}>
                                Yes, it’s that job
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(`/opportunities/${o.id}/reopen`, {}, { preserveScroll: true })}
                            >
                                Not this job
                            </Button>
                        </span>
                    </div>
                )}
                {o.may_be_off && (
                    <p className="flex items-start gap-2 rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm dark:border-rose-900 dark:bg-rose-950/30">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-rose-600" aria-hidden />
                        <span>
                            They may have called this off: {o.may_be_off.quote ? `“${o.may_be_off.quote}”` : 'a later email says so'}
                            {o.may_be_off.verified ? '' : ' (not found word for word)'}. Check the email, then mark it as went elsewhere (Edit
                            details) or leave it open.
                        </span>
                    </p>
                )}
                {closed && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-slate-50 p-3 text-sm dark:bg-slate-900/40">
                        <p>
                            {o.status_label}
                            {o.decided_at ? ` on ${day(o.decided_at.slice(0, 10))}` : ''}
                            {o.decided_by ? ` by ${ACTORS[o.decided_by] ?? o.decided_by}` : ''}
                            {o.note ? `: ${o.note}` : '.'}
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.post(`/opportunities/${o.id}/reopen`, {}, { preserveScroll: true })}
                        >
                            <RotateCcw /> Reopen
                        </Button>
                    </div>
                )}
                {o.open && o.reply_by && (o.reply_in ?? 99) <= 3 && (
                    <p className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm dark:border-rose-900 dark:bg-rose-950/30">
                        {(o.reply_in ?? 0) < 0 ? `They asked for an answer by ${day(o.reply_by)}.` : `They’d like an answer by ${day(o.reply_by)}.`}{' '}
                        Reply to them yourself{o.contact_email ? ` (${o.contact_email})` : ''}, then record what you said here.
                    </p>
                )}
                {o.unverified.length > 0 && (
                    <p className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/30">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                        <span>
                            Not found word for word in the email or document: {o.unverified.join(', ')}. Check these against it before relying on
                            them.
                        </span>
                    </p>
                )}

                <section className="space-y-2">
                    <h2 className="font-semibold">Details and where each came from</h2>
                    <dl className="divide-y rounded-xl border text-sm">
                        {FIELDS.map(([field, label]) => {
                            const v = value(field);
                            const ev = o.evidence.filter((e) => e.field === field);
                            if (!v && ev.length === 0) return null;
                            // Checked only when every part was found (each day of its dates, the rate's amount, currency and unit).
                            const found = !o.unverified.includes(field);
                            const edited = o.edited.includes(field);
                            return (
                                <div key={field} className="grid gap-1 p-3 sm:grid-cols-[10rem_1fr]">
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="min-w-0 space-y-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{v ?? '—'}</span>
                                            {edited ? (
                                                <span className="text-xs text-sky-700 dark:text-sky-400">Changed by you</span>
                                            ) : ev.length === 0 ? (
                                                o.found_by === 'you' ? (
                                                    <span className="text-muted-foreground text-xs">From you</span>
                                                ) : null
                                            ) : found ? (
                                                <span className="text-xs text-emerald-700 dark:text-emerald-400">✓ Found in the email</span>
                                            ) : (
                                                <span className="text-xs text-amber-700 dark:text-amber-400">⚠ Not found word for word</span>
                                            )}
                                        </span>
                                        {ev.map((e, i) => (
                                            <span key={i} className="text-muted-foreground block text-xs">
                                                <span className="italic">{e.quote ? `“${e.quote}”` : '(no words quoted)'}</span>{' '}
                                                {e.verified ? '✓' : '⚠'}{' '}
                                                {e.href ? (
                                                    <Link href={e.href} className="underline underline-offset-2">
                                                        {e.source}
                                                    </Link>
                                                ) : (
                                                    e.source
                                                )}
                                                {e.received ? `, ${day(e.received)}` : ''}
                                            </span>
                                        ))}
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                    {o.note && !closed && <p className="text-muted-foreground text-sm">Note: {o.note}</p>}
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">Emails and documents ({documents.length})</h2>
                    {documents.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{o.found_by === 'you' ? 'You added this yourself.' : 'None any more.'}</p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {documents.map((d) => (
                                <li key={d.id} className="flex items-start gap-2 p-3">
                                    <FileText className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                                    <span className="min-w-0">
                                        <Link href={`/documents/${d.id}`} className="font-medium hover:underline">
                                            {d.subject || d.name}
                                        </Link>
                                        <span className="text-muted-foreground block text-xs">
                                            {d.from ? `${d.from} · ` : ''}
                                            {d.received ? day(d.received.slice(0, 10)) : ''}
                                            {d.job ? ' · on a job' : ''}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">History</h2>
                    <ol className="text-muted-foreground space-y-1 text-sm">
                        {history.map((h) => (
                            <li key={h.id}>
                                <span className="text-foreground">{ACTIONS[h.action] ?? h.action}</span>
                                {h.at ? ` · ${day(h.at.slice(0, 10))} ${h.at.slice(11, 16)}` : ''} · by {ACTORS[h.actor] ?? h.actor}
                                {h.reason ? ` · ${h.reason}` : ''}
                            </li>
                        ))}
                        {history.length === 0 && <li>Nothing recorded yet.</li>}
                    </ol>
                </section>
            </div>

            <Dialog open={accepting} onOpenChange={setAccepting}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Make it a job</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">
                        Filled in from the emails: check it before adding. Its emails and documents are linked to the new job. Nothing is sent to
                        anyone.
                    </p>
                    {accepting && (
                        <AcceptForm
                            id={o.id}
                            prefill={prefill}
                            clients={clients}
                            dayKinds={day_kinds}
                            rateUnits={rate_units}
                            onDone={() => setAccepting(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit details</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">What you change is kept as yours, with the words it was read from.</p>
                    {editing && (
                        <OpportunityForm
                            initial={toForm(o, edit_statuses)}
                            statuses={edit_statuses}
                            dayKinds={opportunity_day_kinds}
                            rateUnits={rate_units}
                            url={`/opportunities/${o.id}`}
                            method="patch"
                            submitLabel="Save"
                            onDone={() => setEditing(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function toForm(o: OpportunityRow, statuses: Record<string, string>): OpportunityFormData {
    const digits = currencyDigits(o.rate?.currency);
    return {
        title: o.title,
        status: o.status in statuses ? o.status : 'enquiry',
        hold_rank: o.hold_rank ? String(o.hold_rank) : '',
        client_name: o.client_name ?? '',
        contact_name: o.contact_name ?? '',
        contact_email: o.contact_email ?? '',
        role: o.role ?? '',
        venue: o.venue ?? '',
        postcode: o.postcode ?? '',
        days: o.days.map((d) => ({ date: d.date, kind: d.kind })),
        reply_by: o.reply_by ?? '',
        rate_amount: o.rate ? (o.rate.minor / 10 ** digits).toFixed(digits) : '',
        rate_currency: o.rate?.currency ?? 'GBP',
        rate_unit: o.rate?.unit ?? 'day',
        po_number: o.po_number ?? '',
        note: o.note ?? '',
    };
}

/** The new job's details, from the opportunity, for you to check. */
function AcceptForm({
    id,
    prefill,
    clients,
    dayKinds,
    rateUnits,
    onDone,
}: {
    id: number;
    prefill: Prefill;
    clients: { id: number; name: string }[];
    dayKinds: Record<string, string>;
    rateUnits: Record<string, string>;
    onDone: () => void;
}) {
    const known = prefill.client_id ?? clients.find((c) => c.name.toLowerCase() === (prefill.client_name ?? '').toLowerCase())?.id ?? null;
    const form = useForm({
        title: prefill.title,
        client_id: known ? String(known) : '',
        client_name: known ? '' : (prefill.client_name ?? ''),
        days: prefill.days,
        start_time: '',
        end_time: '',
        venue: prefill.venue ?? '',
        postcode: prefill.postcode ?? '',
        po_number: prefill.po_number ?? '',
        rate_amount: prefill.rate_amount,
        rate_currency: prefill.rate_currency,
        rate_unit: prefill.rate_unit,
        note: prefill.note,
        status: prefill.status,
    });

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, client_id: d.client_id ? Number(d.client_id) : null }));
                form.post(`/opportunities/${id}/accept`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="acc-title">Job</Label>
                <Input id="acc-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-client">Client</Label>
                <NativeSelect id="acc-client" value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)}>
                    <option value="">No client / new client</option>
                    {clients.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            {!form.data.client_id && (
                <div className="space-y-1.5">
                    <Label htmlFor="acc-new-client">New client name</Label>
                    <Input id="acc-new-client" value={form.data.client_name} onChange={(e) => form.setData('client_name', e.target.value)} />
                </div>
            )}
            <div className="space-y-1.5 sm:col-span-2">
                <Label>Days</Label>
                <DaysEditor days={form.data.days} kinds={dayKinds} onChange={(days) => form.setData('days', days)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-start">Start time (call time)</Label>
                <Input id="acc-start" type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-end">Finish time</Label>
                <Input id="acc-end" type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-venue">Venue</Label>
                <Input id="acc-venue" value={form.data.venue} onChange={(e) => form.setData('venue', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-postcode">Venue postcode</Label>
                <Input id="acc-postcode" value={form.data.postcode} onChange={(e) => form.setData('postcode', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-rate">Rate</Label>
                <div className="flex gap-2">
                    <Input
                        id="acc-rate"
                        inputMode="decimal"
                        className="min-w-0 flex-1"
                        value={form.data.rate_amount}
                        onChange={(e) => form.setData('rate_amount', e.target.value)}
                    />
                    <Input
                        aria-label="Currency"
                        className="w-20"
                        maxLength={3}
                        value={form.data.rate_currency}
                        onChange={(e) => form.setData('rate_currency', e.target.value.toUpperCase())}
                    />
                    <NativeSelect
                        aria-label="Rate per"
                        className="w-36"
                        value={form.data.rate_unit}
                        onChange={(e) => form.setData('rate_unit', e.target.value)}
                    >
                        {Object.entries(rateUnits).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <p className="text-muted-foreground text-xs">
                    A fee for the whole job in pounds becomes the job’s fee; a day or hour rate goes in its notes.
                </p>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-po">PO number</Label>
                <Input id="acc-po" value={form.data.po_number} onChange={(e) => form.setData('po_number', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="acc-status">Status</Label>
                <NativeSelect id="acc-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'booked' | 'done')}>
                    <option value="booked">Booked</option>
                    <option value="done">Done</option>
                </NativeSelect>
            </div>
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="acc-note">Notes</Label>
                <Textarea id="acc-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </div>
            {Object.entries(form.errors).map(([k, e]) => (
                <p key={k} className={cn('text-sm text-rose-600 sm:col-span-2')}>
                    {e}
                </p>
            ))}
            <div className="sm:col-span-2">
                <Button disabled={form.processing || form.data.days.length === 0}>Add the job</Button>
            </div>
        </form>
    );
}
