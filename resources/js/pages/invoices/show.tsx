import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { ClientSelect } from '@/components/client-select';
import { type AllocatorTick } from '@/components/income-allocator';
import { AddCostSheet } from '@/components/invoice/add-cost-sheet';
import { ChangesBanner } from '@/components/invoice/changes-banner';
import { CloseSheet, ReopenSheet, ReplacedBySheet, VoidSheet } from '@/components/invoice/close-sheet';
import { EmailTimeline } from '@/components/invoice/email-timeline';
import { IssueSheet } from '@/components/invoice/issue-sheet';
import { LineRow } from '@/components/invoice/line-row';
import { LineSheet } from '@/components/invoice/line-sheet';
import { blockingCount, NeedsYou } from '@/components/invoice/needs-you';
import { EmailSheet, SendNowSheet, SentSelfSheet } from '@/components/invoice/send-sheet';
import { ItemDrawer } from '@/components/item-drawer';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { ApiError, postJson, problemText } from '@/lib/api';
import { day, money } from '@/lib/format';
import { shortMoney } from '@/lib/income';
import {
    changedSheet,
    costId,
    draftFields,
    type DraftFields,
    GROUP_TITLES,
    groupOf,
    lineAction,
    type LineGroup,
    markSeen,
    saveDraft,
} from '@/lib/invoice';
import { cn } from '@/lib/utils';
import { type ClientRef, type Lookups } from '@/types';
import { type ChangedSheet, type DraftSheet, type InvoiceDetail, type InvoiceLine, type IssuedView, type PaymentToLink } from '@/types/invoices';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download, ExternalLink, MoreHorizontal, Plus } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

interface Props {
    invoice: InvoiceDetail;
    /** A draft's check screen extras; null once it's issued. */
    draft: DraftSheet | null;
    /** An issued or void invoice's view; null while it's a draft. */
    issued: IssuedView | null;
    jobs: { id: number; title: string; starts_on: string }[];
    clients: ClientRef[];
    lookups: Lookups;
}

/** The page's props again, as they are now (after a press saved something, or a refusal said it changed). */
function reload() {
    router.reload({ only: ['invoice', 'draft', 'issued'] });
}

/**
 * An invoice. As a draft it's the check screen: its lines in groups (each a tick that saves at once and a sheet),
 * what changed since you last looked, what needs you, and Issue… with what issuing does shown first. Once issued it's
 * where its money stands, what to do next (send it, a reminder only when you press for one, they've paid), its
 * payments, what was left off or linked late, and what happened. An issued invoice never changes: a late cost goes on
 * the client's next invoice.
 */
export default function InvoiceShow(props: Props) {
    const { invoice } = props;
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const [tick, setTick] = useState<AllocatorTick | null>(null);
    const openPayment = (target: { source: 'bank' | 'manual'; id: number }, withTick: AllocatorTick | null = null) => {
        setTick(withTick);
        setDrawer(target);
    };
    const title = invoice.number ?? (invoice.job ? `Invoice for ${invoice.job.title}` : 'Draft invoice');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Invoices', href: '/invoices' },
                { title: invoice.number ?? 'Draft', href: `/invoices/${invoice.id}` },
            ]}
        >
            <Head title={title} />
            {props.draft ? (
                <CheckScreen {...props} draft={props.draft} title={title} onOpenPayment={openPayment} />
            ) : props.issued ? (
                <IssuedScreen {...props} issued={props.issued} onOpenPayment={openPayment} />
            ) : null}
            <ItemDrawer
                target={drawer}
                tick={tick}
                lookups={props.lookups}
                onClose={() => {
                    setDrawer(null);
                    setTick(null);
                    reload();
                }}
            />
        </AppLayout>
    );
}

/* ---------------------------------------------------------------- the check screen (a draft) */

function CheckScreen({
    invoice,
    draft,
    clients,
    lookups,
    title,
    onOpenPayment,
}: Props & { draft: DraftSheet; title: string; onOpenPayment: (t: { source: 'bank' | 'manual'; id: number }) => void }) {
    const [openLine, setOpenLine] = useState<number | null>(null);
    const [stale, setStale] = useState<Set<number>>(new Set());
    const [busyLine, setBusyLine] = useState<number | null>(null);
    const [seenBusy, setSeenBusy] = useState(false);
    const [sheet, setSheet] = useState<'issue' | 'add' | 'details' | 'menu' | 'delete' | 'another' | null>(null);
    const [shown, setShown] = useState<Record<string, boolean>>({});
    const changedLines = useMemo(() => new Set(draft.changes.map((c) => c.line).filter((l): l is number => l !== null)), [draft.changes]);
    const line = invoice.lines.find((l) => l.id === openLine) ?? null;
    const ticked = invoice.lines.filter((l) => l.included);
    const blocking = blockingCount(draft.checks, invoice);
    const client = draft.client?.name ?? invoice.client ?? invoice.bill_to_name;

    const changed = (message: string, fresh: ChangedSheet | null) => {
        toast(fresh ? `${message} The draft is shown as it is now.` : message, 'error');
        reload();
    };

    const toggle = async (l: InvoiceLine) => {
        setBusyLine(l.id);
        try {
            await lineAction(invoice.id, l, l.included ? 'untick' : 'tick');
            setStale((s) => {
                const n = new Set(s);
                n.delete(l.id);
                return n;
            });
            reload();
        } catch (e) {
            if (e instanceof ApiError && e.problem === 'changed') {
                setStale((s) => new Set(s).add(l.id));
                reload();
            } else toast(problemText(e), 'error');
        } finally {
            setBusyLine(null);
        }
    };

    const untick = (id: number) => {
        const l = invoice.lines.find((x) => x.id === id);
        if (l && l.included) void toggle(l);
    };

    const gotIt = async () => {
        if (invoice.seen_up_to === null) return;
        setSeenBusy(true);
        try {
            await markSeen(invoice.id, invoice.seen_up_to);
            reload();
        } catch (e) {
            toast(problemText(e), 'error');
        } finally {
            setSeenBusy(false);
        }
    };

    const groups = (['work', 'travel', 'earlier', 'yours'] as LineGroup[])
        .map((g) => ({ g, lines: invoice.lines.filter((l) => groupOf(l, invoice.work_job_id) === g) }))
        .filter((x) => x.lines.length > 0);

    return (
        <div className="mx-auto w-full max-w-3xl space-y-5 px-4 pt-4 md:px-6 md:pt-6">
            <div className="flex items-start gap-2">
                {invoice.job && (
                    <Button variant="ghost" size="icon" className="size-11 shrink-0" asChild>
                        <Link href={`/jobs/${invoice.job.id}`} aria-label={`Back to ${invoice.job.title}`}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                )}
                <div className="min-w-0 flex-1 pt-1.5">
                    <h1 className="text-xl leading-tight font-semibold tracking-tight break-words">{title}</h1>
                    {draft.stage && <p className="text-muted-foreground text-sm">{draft.stage.chip}</p>}
                </div>
                <Button variant="ghost" size="icon" className="size-11 shrink-0" aria-label="More" onClick={() => setSheet('menu')}>
                    <MoreHorizontal />
                </Button>
            </div>

            <section className="space-y-1 rounded-xl border p-4">
                <p className="font-medium break-words">{invoice.bill_to_name || 'Who it’s for isn’t set yet'}</p>
                {invoice.end_client && <p className="text-muted-foreground text-sm">for {invoice.end_client}</p>}
                <p className="text-muted-foreground text-sm">
                    {[invoice.job?.title, invoice.po_number ? `PO ${invoice.po_number}` : null].filter(Boolean).join(' · ')}
                </p>
                <p className="text-sm">Due {draft.terms_days} days after it’s issued</p>
                {invoice.document && (
                    <p className="text-muted-foreground text-sm">
                        From{' '}
                        <Link className="underline" href={`/documents/${invoice.document.id}`}>
                            {invoice.document.name}
                        </Link>
                    </p>
                )}
                <Button variant="outline" className="mt-2 h-11" onClick={() => setSheet('details')}>
                    Who it’s for, PO and notes
                </Button>
            </section>

            <ChangesBanner changes={draft.changes} since={draft.changes_since} busy={seenBusy} onGotIt={gotIt} />
            <NeedsYou
                invoice={invoice}
                draft={draft}
                onOpenLine={setOpenLine}
                onUntick={untick}
                onDetails={() => setSheet('details')}
                onIssue={() => setSheet('issue')}
                onDone={() => reload()}
            />

            {groups.map(({ g, lines }) => {
                const on = lines.filter((l) => l.included);
                const off = lines.filter((l) => !l.included);
                const fold = off.length > 3 && !shown[g];
                const sum = on.reduce((s, l) => s + l.amount_minor, 0);
                return (
                    <section key={g} aria-label={GROUP_TITLES[g]} className="space-y-1">
                        <div className="flex items-baseline justify-between gap-2 border-b pb-1">
                            <h2 className="text-base font-semibold">{GROUP_TITLES[g]}</h2>
                            <span className="text-muted-foreground text-sm tabular-nums">
                                {money(sum, invoice.currency)}
                                {off.length > 0 ? ` · ${off.length} left off` : ''}
                            </span>
                        </div>
                        <ul className="divide-y">
                            {[...on, ...(fold ? [] : off)].map((l) => (
                                <LineRow
                                    key={l.id}
                                    line={l}
                                    currency={invoice.currency}
                                    client={client}
                                    hasTerms={!!draft.client?.has_terms}
                                    changed={changedLines.has(l.id)}
                                    stale={stale.has(l.id)}
                                    busy={busyLine === l.id}
                                    onTick={() => toggle(l)}
                                    onOpen={() => setOpenLine(l.id)}
                                />
                            ))}
                        </ul>
                        {off.length > 3 && (
                            <Button variant="ghost" className="h-11" onClick={() => setShown((s) => ({ ...s, [g]: !s[g] }))}>
                                {fold ? `${off.length} more not charged: show them` : 'Hide the ones not charged'}
                            </Button>
                        )}
                    </section>
                );
            })}

            <Button variant="outline" className="h-11 w-full" onClick={() => setSheet('add')}>
                <Plus /> Add a cost or a line
            </Button>

            {invoice.po_total_minor !== null && (
                <p
                    className={cn(
                        'text-sm',
                        invoice.total_minor === invoice.po_total_minor
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : 'text-amber-800 dark:text-amber-300',
                    )}
                >
                    {invoice.total_minor === invoice.po_total_minor
                        ? 'It matches the PO total.'
                        : `The PO total is ${money(invoice.po_total_minor, invoice.currency)}.`}
                </p>
            )}

            {/* Total and Issue…, kept in reach: clear of the round Add expense button on a phone. */}
            <div className="bg-background/95 supports-[backdrop-filter]:bg-background/85 sticky bottom-0 z-20 -mx-4 flex items-center gap-3 border-t py-3 pr-[88px] pb-[max(0.75rem,env(safe-area-inset-bottom))] pl-4 backdrop-blur md:-mx-6 md:px-6">
                <p className="min-w-0 flex-1 text-sm">
                    <span className="block font-semibold tabular-nums">Total {money(invoice.total_minor, invoice.currency)}</span>
                    <span className="text-muted-foreground">
                        {ticked.length} of {invoice.lines.length} {invoice.lines.length === 1 ? 'line' : 'lines'}
                    </span>
                </p>
                {blocking > 0 ? (
                    <Button
                        variant="outline"
                        className="h-11 shrink-0 border-rose-300 text-rose-800 dark:border-rose-800 dark:text-rose-300"
                        onClick={() => document.getElementById('needs-you')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                    >
                        {blocking === 1 ? '1 thing to fix' : `${blocking} things to fix`}
                    </Button>
                ) : (
                    <Button className="h-11 shrink-0 px-5" disabled={ticked.length === 0} onClick={() => setSheet('issue')}>
                        Issue…
                    </Button>
                )}
            </div>

            <LineSheet
                invoice={invoice}
                line={line}
                onClose={() => setOpenLine(null)}
                onDone={(m) => {
                    if (m) toast(m);
                    reload();
                }}
                onChanged={changed}
                onOpenPayment={(t) => {
                    setOpenLine(null);
                    onOpenPayment(t);
                }}
            />
            <AddCostSheet
                open={sheet === 'add'}
                onClose={() => setSheet(null)}
                invoice={invoice}
                onOpenPayment={onOpenPayment}
                onDone={(m) => {
                    toast(m);
                    reload();
                }}
                onChanged={changed}
            />
            <IssueSheet
                open={sheet === 'issue'}
                onClose={() => setSheet(null)}
                invoice={invoice}
                draft={draft}
                lookups={lookups}
                onIssued={(m) => {
                    setSheet(null);
                    toast(m);
                    router.reload();
                }}
                onChanged={reload}
            />
            <DetailsSheet open={sheet === 'details'} onClose={() => setSheet(null)} invoice={invoice} clients={clients} onChanged={changed} />
            <ResponsiveSheet open={sheet === 'menu'} onOpenChange={(o) => !o && setSheet(null)} title="This draft">
                <SheetBody className="space-y-2">
                    <Button variant="outline" className="h-11 w-full justify-start" asChild>
                        <a href={`/invoices/${invoice.id}/pdf`} target="_blank" rel="noreferrer">
                            <ExternalLink /> Preview the PDF
                        </a>
                    </Button>
                    {invoice.work_job_id && (
                        <Button variant="outline" className="h-11 w-full justify-start" onClick={() => setSheet('another')}>
                            I invoiced this another way
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        className="h-11 w-full justify-start text-rose-700 dark:text-rose-400"
                        onClick={() => setSheet('delete')}
                    >
                        Delete the draft
                    </Button>
                </SheetBody>
            </ResponsiveSheet>
            <DeleteSheet
                open={sheet === 'delete' || sheet === 'another'}
                anotherWay={sheet === 'another'}
                onClose={() => setSheet(null)}
                invoice={invoice}
            />
        </div>
    );
}

/** Who it's for, their address and email, the PO number, notes and currency: saved with the lines as you saw them. */
function DetailsSheet({
    open,
    onClose,
    invoice,
    clients,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    clients: ClientRef[];
    onChanged: (message: string, sheet: ChangedSheet | null) => void;
}) {
    const [f, setF] = useState<DraftFields>(draftFields(invoice));
    const [clientName, setClientName] = useState<string | null>(invoice.client);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (!open) return;
        setF(draftFields(invoice));
        setClientName(invoice.client);
        setErrors({});
        // Only when it opens: a reload behind it keeps what you typed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const set = (k: keyof DraftFields, v: string | number | null) => setF((x) => ({ ...x, [k]: v }));
    const dirty = JSON.stringify(f) !== JSON.stringify(draftFields(invoice));

    const save = async () => {
        setBusy(true);
        setErrors({});
        try {
            await saveDraft(invoice, { fields: f });
            toast('Saved.');
            onClose();
            reload();
        } catch (e) {
            if (e instanceof ApiError && e.problem === 'changed') {
                onChanged(e.message, changedSheet(e.data));
                onClose();
            } else if (e instanceof ApiError && Object.keys(e.errors).length > 0) setErrors(e.errors);
            else setErrors({ form: problemText(e) });
        } finally {
            setBusy(false);
        }
    };

    const field = (k: keyof DraftFields, label: string, input: ReactNode) => (
        <div className="space-y-1.5">
            <Label htmlFor={`d-${k}`}>{label}</Label>
            {input}
            {errors[k] && <p className="text-sm text-rose-700 dark:text-rose-400">{errors[k]}</p>}
        </div>
    );

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title="Who it’s for, PO and notes"
            dirty={dirty}
            discardNote="What you changed here is lost. The invoice’s lines are kept."
        >
            <SheetBody className="space-y-4">
                <div className="space-y-1.5">
                    <Label htmlFor="d-client">Client</Label>
                    <ClientSelect
                        id="d-client"
                        clients={clients}
                        value={f.client_id}
                        name={clientName}
                        allowNone
                        noneLabel="No client"
                        onChange={(c) => {
                            set('client_id', c?.id ?? null);
                            setClientName(c?.name ?? null);
                            if (c && f.bill_to_name.trim() === '') set('bill_to_name', c.name);
                        }}
                    />
                    {errors.client_id && <p className="text-sm text-rose-700 dark:text-rose-400">{errors.client_id}</p>}
                </div>
                {field(
                    'bill_to_name',
                    'Bill to',
                    <Input id="d-bill_to_name" className="h-11" value={f.bill_to_name} onChange={(e) => set('bill_to_name', e.target.value)} />,
                )}
                {field(
                    'bill_to_address',
                    'Their address',
                    <Textarea id="d-bill_to_address" rows={3} value={f.bill_to_address} onChange={(e) => set('bill_to_address', e.target.value)} />,
                )}
                {field(
                    'bill_to_email',
                    'Their email for invoices',
                    <Input
                        id="d-bill_to_email"
                        className="h-11"
                        type="email"
                        inputMode="email"
                        value={f.bill_to_email}
                        onChange={(e) => set('bill_to_email', e.target.value)}
                        aria-invalid={!!errors.bill_to_email}
                    />,
                )}
                {field(
                    'po_number',
                    'PO number',
                    <Input
                        id="d-po_number"
                        className="h-11"
                        value={f.po_number}
                        onChange={(e) => set('po_number', e.target.value)}
                        aria-invalid={!!errors.po_number}
                    />,
                )}
                {field(
                    'notes',
                    'Notes on the invoice',
                    <Textarea id="d-notes" rows={3} value={f.notes} onChange={(e) => set('notes', e.target.value)} />,
                )}
                {field(
                    'currency',
                    'Currency',
                    <NativeSelect id="d-currency" className="h-11" value={f.currency} onChange={(e) => set('currency', e.target.value)}>
                        {[...new Set(['GBP', 'EUR', 'USD', f.currency])].map((c) => (
                            <option key={c}>{c}</option>
                        ))}
                    </NativeSelect>,
                )}
                {errors.form && <p className="text-sm text-rose-700 dark:text-rose-400">{errors.form}</p>}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button className="h-11 flex-1" disabled={busy || !dirty} onClick={save}>
                    {dirty ? 'Save' : 'Saved'}
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Close
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/** Delete the draft (every line with it), or "I invoiced this another way" (the job then counts as invoiced). */
function DeleteSheet({ open, anotherWay, onClose, invoice }: { open: boolean; anotherWay: boolean; onClose: () => void; invoice: InvoiceDetail }) {
    const [busy, setBusy] = useState(false);
    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={anotherWay ? 'I invoiced this another way' : 'Delete this draft?'}
            description={
                anotherWay
                    ? 'The draft is deleted, and the job counts as invoiced: Expend won’t ask you to invoice it.'
                    : 'The draft and every line on it are deleted. What’s linked to the job stays.'
            }
        >
            <SheetFooter className="flex gap-2">
                <Button
                    variant="destructive"
                    className="h-11 flex-1"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.delete(`/invoices/${invoice.id}`, {
                            data: anotherWay ? { another_way: true } : {},
                            onError: (errors) => toast(Object.values(errors)[0] ?? 'The draft wasn’t deleted. Try again.', 'error'),
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    {anotherWay ? 'Delete the draft' : 'Delete it'}
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Keep it
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/* ---------------------------------------------------------------- an issued (or void) invoice */

type IssuedSheet = 'send' | 'send-reminder' | 'email' | 'email-reminder' | 'sent' | 'close' | 'reopen' | 'replaced' | 'void' | null;

function IssuedScreen({
    invoice,
    issued,
    onOpenPayment,
}: Props & { issued: IssuedView; onOpenPayment: (t: { source: 'bank' | 'manual'; id: number }, tick?: AllocatorTick | null) => void }) {
    const [sheet, setSheet] = useState<IssuedSheet>(null);
    const [linking, setLinking] = useState(false);
    const [toLink, setToLink] = useState<PaymentToLink[]>(issued.to_link);
    const [busy, setBusy] = useState<string | null>(null);
    const email = issued.email;
    const reminder = issued.reminder;
    const isVoid = invoice.status === 'void';
    const payable = !isVoid;
    const sent = !!invoice.sent_at;
    // The server's words (Paid, Part paid £500 of £950, Closed · £30 not paid, Overpaid £100, Overdue, Unpaid…).
    const pillStatus =
        issued.state === 'unpaid' && invoice.overdue ? 'overdue' : issued.state === 'paid' && issued.over_minor > 0 ? 'overpaid' : issued.state;
    const leftOff = invoice.lines.filter((l) => !l.included);
    const onInvoice = invoice.lines.filter((l) => l.included);
    const tick: AllocatorTick = {
        invoice_id: invoice.id,
        label: invoice.number ?? 'this invoice',
        // What's still to come (a pound invoice): a payment linked from here starts at that, the rest is left over.
        outstanding_pence: invoice.currency === 'GBP' ? invoice.outstanding_minor : null,
    };

    useEffect(() => {
        setToLink(issued.to_link);
    }, [issued.to_link]);

    // Links from the to-do list and Telegram: #payments ("Link a payment") and #sent ("Pick a date").
    useEffect(() => {
        const hash = window.location.hash;
        if (hash === '#payments') {
            setLinking(true);
            window.setTimeout(() => document.getElementById('payments')?.scrollIntoView({ block: 'start' }), 100);
        }
        if (hash === '#sent' && !invoice.sent_at && !isVoid) setSheet('sent');
        // Only on arrival.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const post = (key: string, url: string, data: Record<string, string | number | boolean | null> = {}) => {
        setBusy(key);
        router.post(url, data, {
            preserveScroll: true,
            onError: (errors) => toast(Object.values(errors)[0] ?? 'That didn’t work. Try again.', 'error'),
            onFinish: () => setBusy(null),
        });
    };

    const openInGmail = () => {
        // Expend looks again in 2, 10 and 30 minutes, to see it sent.
        postJson(`/invoices/${invoice.id}/gmail/check`, {}).catch(() => {});
    };

    const theyPaid = async () => {
        setBusy('paid');
        try {
            const r = await postJson<{ message: string; payments: PaymentToLink[]; selected: number | null }>(
                `/invoices/${invoice.id}/paid-wait`,
                {},
            );
            toast(r.message);
            setToLink(r.payments);
            setLinking(true);
            document.getElementById('payments')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (r.selected) onOpenPayment({ source: 'bank', id: r.selected }, tick);
        } catch (e) {
            toast(problemText(e), 'error');
        } finally {
            setBusy(null);
        }
    };

    const gmailLink = 'https://mail.google.com/mail/u/0/#drafts';

    return (
        <div className="mx-auto w-full max-w-3xl space-y-5 px-4 pt-4 pb-24 md:px-6 md:pt-6 md:pb-8">
            <div className="flex items-start gap-2">
                {invoice.job && (
                    <Button variant="ghost" size="icon" className="size-11 shrink-0" asChild>
                        <Link href={`/jobs/${invoice.job.id}`} aria-label={`Back to ${invoice.job.title}`}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                )}
                <div className="min-w-0 flex-1 pt-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">{invoice.number}</h1>
                        <StatusPill status={pillStatus} label={issued.state_words} />
                    </div>
                    <p className="text-muted-foreground text-sm break-words">
                        {invoice.bill_to_name ?? 'No client'} · {money(invoice.total_minor, invoice.currency)}
                        {invoice.job ? ` · ${invoice.job.title}` : ''}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        Issued {day(invoice.issue_date)} · due {day(invoice.due_date)}
                        {invoice.po_number ? ` · PO ${invoice.po_number}` : ''}
                    </p>
                </div>
            </div>

            {isVoid && (
                <section className="space-y-2 rounded-xl border p-4">
                    <p className="text-sm">Void{invoice.void_reason ? `: ${invoice.void_reason}` : ''}.</p>
                    {issued.replaced_by && <p className="text-sm">Replaced by {issued.replaced_by}: what was paid against it counts there.</p>}
                    {!issued.replaced_by && issued.payments.length > 0 && issued.replace_with.length > 0 && (
                        <Button variant="outline" className="h-11" onClick={() => setSheet('replaced')}>
                            It was replaced by…
                        </Button>
                    )}
                </section>
            )}
            {issued.stale_date && <Note tone="warn">{issued.stale_date}</Note>}
            {issued.blind && !sent && payable && (
                <Note tone="warn">
                    Expend can’t see Gmail just now, so it can’t tell whether {invoice.number} went.{' '}
                    <a className="underline" href="/settings/integrations#gmail">
                        Reconnect Gmail
                    </a>
                </Note>
            )}
            {invoice.email_failed && (
                <Note tone="bad">
                    {invoice.email_failed.reminder ? 'The reminder' : 'The email'}
                    {invoice.email_failed.to ? ` to ${invoice.email_failed.to}` : ''} on {day(invoice.email_failed.at.slice(0, 10))} didn’t send
                    {invoice.email_failed.error ? `: ${invoice.email_failed.error}` : '.'} Check Settings, then send it again.
                </Note>
            )}

            {payable && (
                <section aria-label="What next" className="space-y-3 rounded-xl border p-4">
                    {!sent ? (
                        <>
                            <p className="text-sm font-medium">
                                {email?.route === 'gmail' && email.status === 'drafted'
                                    ? `${invoice.number} is in your Gmail drafts, ready to send.`
                                    : email?.route === 'gmail' && email.status === 'pending'
                                      ? 'Putting it in Gmail…'
                                      : email?.route === 'gmail' && email.status === 'deleted'
                                        ? `The Gmail draft for ${invoice.number} was deleted without being sent.`
                                        : email?.route === 'gmail' && email.status === 'failed'
                                          ? `${invoice.number} isn’t in your Gmail drafts yet${email.error ? `: ${email.error}` : ''}.`
                                          : `${invoice.number} hasn’t been sent yet.`}
                            </p>
                            {email?.route === 'gmail' && email.status === 'drafted' && (
                                <p className="text-muted-foreground text-xs">
                                    It’s at the top of Drafts in Gmail. Nothing goes until you press Send.
                                </p>
                            )}
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                {issued.gmail && email?.status === 'drafted' && (
                                    <>
                                        <Button className="h-11" onClick={() => setSheet('send')}>
                                            Send it now
                                        </Button>
                                        <Button variant="outline" className="h-11" asChild>
                                            <a href={gmailLink} target="_blank" rel="noreferrer" onClick={openInGmail}>
                                                <ExternalLink /> Open in Gmail
                                            </a>
                                        </Button>
                                    </>
                                )}
                                {issued.gmail && (!email || email.route !== 'gmail' || ['failed', 'deleted', 'replaced'].includes(email.status)) && (
                                    <Button
                                        className="h-11"
                                        disabled={busy === 'gmail'}
                                        onClick={() => post('gmail', `/invoices/${invoice.id}/gmail`)}
                                    >
                                        {email?.route === 'gmail' && email.status === 'failed'
                                            ? 'Try again'
                                            : email?.route === 'gmail' && email.status === 'deleted'
                                              ? 'Put it back in Gmail'
                                              : 'Put it in Gmail'}
                                    </Button>
                                )}
                                {(!issued.gmail || email?.status === 'failed') && (
                                    <Button variant={issued.gmail ? 'outline' : 'default'} className="h-11" onClick={() => setSheet('email')}>
                                        Email it from Expend
                                    </Button>
                                )}
                                <Button variant="outline" className="h-11" onClick={() => setSheet('sent')}>
                                    {email?.status === 'deleted' ? 'I sent it another way' : 'I sent it myself'}
                                </Button>
                            </div>
                            {!issued.gmail && (
                                <p className="text-muted-foreground text-xs">
                                    <a className="underline" href="/settings/integrations#gmail">
                                        Connect Gmail
                                    </a>{' '}
                                    to put invoices in the booking conversation.
                                </p>
                            )}
                        </>
                    ) : (
                        <>
                            <p className="text-sm">
                                Sent{invoice.sent_to ? ` to ${invoice.sent_to}` : ''} on {day(invoice.sent_at?.slice(0, 10))}.
                                {issued.overdue && issued.outstanding_minor > 0
                                    ? ` ${issued.overdue.days} ${issued.overdue.days === 1 ? 'day' : 'days'} overdue.`
                                    : ''}
                            </p>
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                {reminder?.route === 'gmail' && reminder.status === 'drafted' ? (
                                    <>
                                        <Button className="h-11" onClick={() => setSheet('send-reminder')}>
                                            Send the reminder
                                        </Button>
                                        <Button variant="outline" className="h-11" asChild>
                                            <a href={gmailLink} target="_blank" rel="noreferrer" onClick={openInGmail}>
                                                <ExternalLink /> Open in Gmail
                                            </a>
                                        </Button>
                                    </>
                                ) : (
                                    issued.can_remind &&
                                    (issued.gmail ? (
                                        <Button
                                            className="h-11"
                                            disabled={busy === 'remind'}
                                            onClick={() => post('remind', `/invoices/${invoice.id}/reminder`)}
                                        >
                                            Make a reminder
                                        </Button>
                                    ) : (
                                        <Button className="h-11" onClick={() => setSheet('email-reminder')}>
                                            Send a reminder
                                        </Button>
                                    ))
                                )}
                                {issued.overdue && issued.outstanding_minor > 0 && (
                                    <Button variant="outline" className="h-11" disabled={busy === 'paid'} onClick={theyPaid}>
                                        They’ve paid
                                    </Button>
                                )}
                            </div>
                            {issued.can_remind && issued.gmail && !(reminder?.status === 'drafted') && (
                                <p className="text-muted-foreground text-xs">
                                    A reminder is written in your Gmail drafts for you to send. Nothing goes until you do.
                                </p>
                            )}
                        </>
                    )}
                    <Button variant="ghost" className="h-11 px-2" asChild>
                        <a href={`/invoices/${invoice.id}/pdf`}>
                            <Download /> Download the PDF
                        </a>
                    </Button>
                </section>
            )}

            <section id="payments" aria-label="Payments" className="scroll-mt-24 space-y-2">
                <div className="flex items-baseline justify-between gap-2 border-b pb-1">
                    <h2 className="text-base font-semibold">Payments</h2>
                    <span className="text-muted-foreground text-sm tabular-nums">
                        {money(issued.paid_minor, issued.currency)} of {money(invoice.total_minor, invoice.currency)}
                    </span>
                </div>
                {issued.payments.length === 0 && issued.paid_by_job_minor === 0 && (
                    <p className="text-muted-foreground text-sm">Nothing has come in for it yet.</p>
                )}
                <ul className="divide-y">
                    {issued.payments.map((p) => (
                        <li key={`${p.source}${p.id}`}>
                            <button
                                type="button"
                                onClick={() => onOpenPayment({ source: p.source, id: p.id })}
                                className="hover:bg-muted/50 flex min-h-11 w-full items-center gap-2 py-2 text-left"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm">{p.payer ?? 'A payment'}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {day(p.date)} · {p.linked ? 'linked' : 'waiting in the Inbox'}
                                    </span>
                                </span>
                                <span className="shrink-0 text-sm tabular-nums">{money(p.amount_pence)}</span>
                            </button>
                        </li>
                    ))}
                </ul>
                {issued.paid_by_job_minor > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {money(issued.paid_by_job_minor, issued.currency)} paid on {invoice.job?.title ?? 'the job'} without naming this invoice.
                    </p>
                )}
                {payable && issued.outstanding_minor > 0 && (
                    <p className="text-sm">
                        Still to come: <span className="font-semibold tabular-nums">{money(issued.outstanding_minor, issued.currency)}</span>
                    </p>
                )}
                {payable && issued.outstanding_minor > 0 && (
                    <>
                        <Button variant="outline" className="h-11" onClick={() => setLinking((l) => !l)}>
                            {linking ? 'Hide' : 'Link a payment'}
                        </Button>
                        {linking && (
                            <div className="space-y-1">
                                {toLink.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        No money in from this client is waiting. Link it when it comes in.
                                    </p>
                                ) : (
                                    <ul className="divide-y rounded-lg border">
                                        {toLink.map((p) => (
                                            <li key={p.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => onOpenPayment({ source: 'bank', id: p.id }, tick)}
                                                    className="hover:bg-muted/50 flex min-h-11 w-full items-center gap-2 px-3 py-2 text-left"
                                                >
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm">{p.payer}</span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {day(p.date)}
                                                            {p.suggested ? ' · Expend suggests it’s for this one' : ''}
                                                        </span>
                                                    </span>
                                                    <span className="shrink-0 text-sm tabular-nums">{money(p.amount_pence)}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="text-muted-foreground text-xs">
                                    A payment opens with this invoice ticked. Nothing is linked until you save it.
                                </p>
                            </div>
                        )}
                    </>
                )}
                <div className="flex flex-wrap gap-2">
                    {payable && issued.state !== 'closed' && issued.outstanding_minor > 0 && (
                        <Button variant="ghost" className="h-11 px-2" onClick={() => setSheet('close')}>
                            Close it: {shortMoney(issued.outstanding_minor, issued.currency)} isn’t coming
                        </Button>
                    )}
                    {payable && issued.state === 'closed' && (
                        <Button variant="ghost" className="h-11 px-2" onClick={() => setSheet('reopen')}>
                            Reopen it
                        </Button>
                    )}
                </div>
            </section>

            {issued.late_costs.length > 0 && (
                <section aria-label="Linked after it was sent" className="space-y-2">
                    <h2 className="text-base font-semibold">Linked to {invoice.job?.title ?? 'the job'} after it was sent</h2>
                    <p className="text-muted-foreground text-sm">
                        A sent invoice never changes: these go on {invoice.client ?? 'the client'}’s next invoice.
                    </p>
                    <ul className="space-y-2">
                        {issued.late_costs.map((c) => (
                            <li key={c.id} className="space-y-2 rounded-lg border p-3">
                                <p className="flex justify-between gap-2 text-sm">
                                    <span className="min-w-0 break-words">{c.what}</span>
                                    {c.amount_minor !== null && <span className="shrink-0 tabular-nums">{money(c.amount_minor, c.currency)}</span>}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {c.actions.map((a) => (
                                        <Button
                                            key={a.code}
                                            variant={a.code === 'cost_invoice_now' ? 'default' : 'outline'}
                                            className="h-11"
                                            disabled={busy === a.code + a.id}
                                            onClick={() => post(a.code + a.id, `/todo/${a.code}/${a.id}`, { fp: a.fp })}
                                        >
                                            {a.label}
                                        </Button>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {leftOff.length > 0 && (
                <section aria-label="Left off this invoice" className="space-y-2">
                    <h2 className="text-base font-semibold">Left off this invoice ({leftOff.length})</h2>
                    <ul className="divide-y">
                        {leftOff.map((l) => {
                            const id = costId(l);
                            return (
                                <li key={l.id} className="flex items-center gap-2 py-2">
                                    <span className="text-muted-foreground min-w-0 flex-1 text-sm break-words">{l.description}</span>
                                    <span className="text-muted-foreground shrink-0 text-sm tabular-nums">
                                        {money(l.amount_minor, invoice.currency)}
                                    </span>
                                    {id && payable && (
                                        <Button
                                            variant="outline"
                                            className="h-11 shrink-0"
                                            disabled={busy === `next${l.id}`}
                                            onClick={() => post(`next${l.id}`, `/costs/${id}/charge`, { charge: 'recharge' })}
                                        >
                                            Charge it next time
                                        </Button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            <details className="group rounded-xl border">
                <summary className="flex min-h-11 cursor-pointer items-center justify-between gap-2 px-4 py-2 text-sm font-medium">
                    <span>
                        On the invoice ({onInvoice.length} {onInvoice.length === 1 ? 'line' : 'lines'})
                    </span>
                    <span className="tabular-nums">{money(invoice.total_minor, invoice.currency)}</span>
                </summary>
                <ul className="divide-y border-t px-4">
                    {onInvoice.map((l) => (
                        <li key={l.id} className="flex justify-between gap-3 py-2 text-sm">
                            <span className="min-w-0 break-words">
                                {l.description}
                                {l.quantity !== '1.00' && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {l.quantity} × {money(Math.round(parseFloat(l.unit_price) * 100), invoice.currency)}
                                    </span>
                                )}
                            </span>
                            <span className="shrink-0 tabular-nums">{money(l.amount_minor, invoice.currency)}</span>
                        </li>
                    ))}
                </ul>
            </details>

            <EmailTimeline invoice={invoice} issued={issued} />

            {invoice.status === 'issued' && (
                <Button variant="ghost" className="h-11 px-2 text-rose-700 dark:text-rose-400" onClick={() => setSheet('void')}>
                    Void it…
                </Button>
            )}

            <SendNowSheet
                open={sheet === 'send' || sheet === 'send-reminder'}
                onClose={() => setSheet(null)}
                invoice={invoice}
                kind={sheet === 'send-reminder' ? 'reminder' : 'invoice'}
                onDone={(m) => {
                    setSheet(null);
                    toast(m);
                    reload();
                }}
            />
            <EmailSheet
                open={sheet === 'email' || sheet === 'email-reminder'}
                onClose={() => setSheet(null)}
                invoice={invoice}
                reminder={sheet === 'email-reminder'}
                issued={issued}
            />
            <SentSelfSheet
                open={sheet === 'sent'}
                onClose={() => setSheet(null)}
                invoice={invoice}
                from={issued.sent_from}
                anotherWay={email?.status === 'deleted'}
            />
            <CloseSheet open={sheet === 'close'} onClose={() => setSheet(null)} invoice={invoice} issued={issued} />
            <ReopenSheet open={sheet === 'reopen'} onClose={() => setSheet(null)} invoice={invoice} issued={issued} />
            <ReplacedBySheet open={sheet === 'replaced'} onClose={() => setSheet(null)} invoice={invoice} issued={issued} />
            <VoidSheet open={sheet === 'void'} onClose={() => setSheet(null)} invoice={invoice} issued={issued} />
        </div>
    );
}

function Note({ tone, children }: { tone: 'warn' | 'bad'; children: ReactNode }) {
    return (
        <p
            className={cn(
                'rounded-xl p-3 text-sm',
                tone === 'warn'
                    ? 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                    : 'bg-rose-50 text-rose-900 dark:bg-rose-950 dark:text-rose-200',
            )}
        >
            {children}
        </p>
    );
}
