import { NativeSelect } from '@/components/native-select';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { day, money, toPence } from '@/lib/format';
import { type InvoiceRow } from '@/pages/invoices/index';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Mail, Plus, Trash2 } from 'lucide-react';

type Line = {
    description: string;
    quantity: string;
    unit_price: string;
    source_quote: string | null;
};

interface Props {
    invoice: InvoiceRow & {
        sent_at: string | null;
        sent_to: string | null;
        reminded_at: string | null;
        reminder_count: number;
        email_failed: { to: string | null; error: string | null; at: string; reminder: boolean } | null;
        bill_to_name: string | null;
        bill_to_address: string | null;
        bill_to_email: string | null;
        notes: string | null;
        po_total_minor: number | null;
        work_job_id: number | null;
        client_id: number | null;
        document: { id: number; name: string } | null;
        void_reason: string | null;
        lines: (Line & { amount_minor: number })[];
        lines_version: string | null;
        seen_up_to: number | null;
    };
    jobs: { id: number; title: string; starts_on: string }[];
    clients: { id: number; name: string }[];
}

// The form starts again from the draft's lines whenever they change (a cost the job's sync added, a save from another
// tab): a page left open never saves or issues lines it didn't show.
export default function InvoiceShow(props: Props) {
    return <InvoicePage key={props.invoice.lines_version ?? props.invoice.status} {...props} />;
}

function InvoicePage({ invoice, jobs, clients }: Props) {
    const draft = invoice.status === 'draft';
    const form = useForm({
        lines_version: invoice.lines_version,
        bill_to_name: invoice.bill_to_name ?? '',
        bill_to_address: invoice.bill_to_address ?? '',
        bill_to_email: invoice.bill_to_email ?? '',
        po_number: invoice.po_number ?? '',
        currency: invoice.currency,
        notes: invoice.notes ?? '',
        work_job_id: invoice.work_job_id ? String(invoice.work_job_id) : '',
        client_id: invoice.client_id ? String(invoice.client_id) : '',
        lines: invoice.lines.map((l) => ({
            description: l.description,
            quantity: l.quantity,
            unit_price: l.unit_price,
            source_quote: l.source_quote,
        })) as Line[],
    });
    const total = form.data.lines.reduce((s, l) => s + Math.round((parseFloat(l.quantity) || 0) * (toPence(l.unit_price) ?? 0)), 0);
    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Invoices', href: '/invoices' },
                { title: invoice.number ?? 'Draft', href: `/invoices/${invoice.id}` },
            ]}
        >
            <Head title={invoice.number ?? 'Draft invoice'} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{invoice.number ?? 'Draft invoice'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {invoice.status === 'void'
                                ? `Void: ${invoice.void_reason}`
                                : draft
                                  ? 'Edit freely until you issue it. After that it can’t be changed.'
                                  : `Issued ${day(invoice.issue_date)} · due ${day(invoice.due_date)} · paid ${money(invoice.paid_minor, invoice.currency)}` +
                                    (invoice.sent_at
                                        ? invoice.sent_to
                                            ? ` · emailed to ${invoice.sent_to} ${day(invoice.sent_at.slice(0, 10))}`
                                            : ' · sent outside Expend'
                                        : ' · not emailed yet') +
                                    (invoice.reminder_count
                                        ? ` · ${invoice.reminder_count} reminder${invoice.reminder_count === 1 ? '' : 's'} sent`
                                        : '')}
                            {invoice.document && (
                                <>
                                    {' · from '}
                                    <Link className="underline" href={`/documents/${invoice.document.id}`}>
                                        {invoice.document.name}
                                    </Link>
                                </>
                            )}
                        </p>
                        {invoice.email_failed && (
                            <p className="mt-1 text-sm text-rose-600">
                                {invoice.email_failed.reminder ? 'The reminder' : 'The email'}
                                {invoice.email_failed.to ? ` to ${invoice.email_failed.to}` : ''} on {day(invoice.email_failed.at.slice(0, 10))}{' '}
                                didn’t send{invoice.email_failed.error ? `: ${invoice.email_failed.error}` : '.'} Check Settings › Integrations, then
                                send it again.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/invoices/${invoice.id}/pdf`} target="_blank" rel="noreferrer">
                                <Download /> {draft ? 'Preview PDF' : 'PDF'}
                            </a>
                        </Button>
                        {draft && (
                            <Button
                                onClick={() => {
                                    if (form.isDirty) return window.alert('Save your changes first.');
                                    if (window.confirm('Issue this invoice? It gets the next number and can’t be edited afterwards.'))
                                        router.post(
                                            `/invoices/${invoice.id}/issue`,
                                            { lines_version: invoice.lines_version, seen_up_to: invoice.seen_up_to },
                                            {
                                                onError: (errors) =>
                                                    toast(Object.values(errors)[0] ?? 'The invoice wasn’t issued. Check it and try again.', 'error'),
                                            },
                                        );
                                }}
                            >
                                Issue invoice
                            </Button>
                        )}
                        {(invoice.status === 'issued' || invoice.status === 'paid') && (
                            <Button
                                variant={invoice.sent_at ? 'outline' : 'default'}
                                onClick={() => {
                                    const reminder = invoice.overdue && !!invoice.sent_at;
                                    const to = window.prompt(
                                        reminder ? 'Send a reminder with the invoice attached to:' : 'Email the invoice (PDF attached) to:',
                                        invoice.bill_to_email ?? '',
                                    );
                                    if (to)
                                        router.post(
                                            `/invoices/${invoice.id}/send`,
                                            { to, reminder },
                                            {
                                                preserveScroll: true,
                                                // A rejected address comes back as a form error, which this page has no field to show.
                                                onError: (errors) =>
                                                    toast(
                                                        errors.to ?? Object.values(errors)[0] ?? 'That didn’t send. Check the address and try again.',
                                                        'error',
                                                    ),
                                            },
                                        );
                                }}
                            >
                                <Mail /> {invoice.overdue && invoice.sent_at ? 'Send a reminder' : invoice.sent_at ? 'Send again' : 'Email to client'}
                            </Button>
                        )}
                        {invoice.status === 'issued' && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    const reason = window.prompt('Why are you voiding this invoice?');
                                    // Cancel does nothing; OK with no reason gets the server's "Say why" message rather than nothing.
                                    if (reason !== null)
                                        router.post(
                                            `/invoices/${invoice.id}/void`,
                                            { reason },
                                            {
                                                preserveScroll: true,
                                                // A reason that's too short comes back as a form error, which this page has no field to show.
                                                onError: (errors) =>
                                                    toast(
                                                        errors.reason ?? Object.values(errors)[0] ?? 'The invoice wasn’t voided. Try again.',
                                                        'error',
                                                    ),
                                            },
                                        );
                                }}
                            >
                                Void
                            </Button>
                        )}
                        {draft && (
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Delete draft"
                                onClick={() =>
                                    window.confirm('Delete this draft?') &&
                                    router.delete(`/invoices/${invoice.id}`, {
                                        onError: (errors) => toast(Object.values(errors)[0] ?? 'The draft wasn’t deleted. Try again.', 'error'),
                                    })
                                }
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                </div>

                <form
                    className="space-y-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(`/invoices/${invoice.id}`, {
                            preserveScroll: true,
                            // Not saved: say why, as the field may be out of view.
                            onError: (errors) => toast(Object.values(errors)[0] ?? 'The draft wasn’t saved. Check it and try again.', 'error'),
                        });
                    }}
                >
                    <fieldset disabled={!draft} className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Bill to</Label>
                            <Input value={form.data.bill_to_name} onChange={(e) => form.setData('bill_to_name', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Their email for invoices</Label>
                            <Input
                                value={form.data.bill_to_email}
                                onChange={(e) => form.setData('bill_to_email', e.target.value)}
                                aria-invalid={!!form.errors.bill_to_email}
                            />
                            {form.errors.bill_to_email && <p className="text-sm text-rose-600">{form.errors.bill_to_email}</p>}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Address</Label>
                            <Textarea value={form.data.bill_to_address} onChange={(e) => form.setData('bill_to_address', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>PO number</Label>
                            <Input
                                value={form.data.po_number}
                                onChange={(e) => form.setData('po_number', e.target.value)}
                                aria-invalid={!!form.errors.po_number}
                            />
                            {form.errors.po_number && <p className="text-sm text-rose-600">{form.errors.po_number}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Currency</Label>
                            <NativeSelect value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                {['GBP', 'EUR', 'USD'].map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Job</Label>
                            <NativeSelect value={form.data.work_job_id} onChange={(e) => form.setData('work_job_id', e.target.value)}>
                                <option value="">None</option>
                                {jobs.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {j.title} · {j.starts_on}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Client</Label>
                            <NativeSelect value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)}>
                                <option value="">None</option>
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </fieldset>

                    <fieldset disabled={!draft} className="space-y-2">
                        <Label>Lines</Label>
                        {form.data.lines.map((l, i) => (
                            <div key={i} className="grid gap-2 rounded-lg border p-2 sm:grid-cols-[1fr_6rem_8rem_8rem_auto]">
                                <div>
                                    <Input
                                        value={l.description}
                                        onChange={(e) => setLine(i, { description: e.target.value })}
                                        aria-label="Description"
                                    />
                                    {l.source_quote && <p className="text-muted-foreground mt-1 text-xs italic">From the PO: “{l.source_quote}”</p>}
                                </div>
                                <Input
                                    inputMode="decimal"
                                    value={l.quantity}
                                    onChange={(e) => setLine(i, { quantity: e.target.value })}
                                    aria-label="Quantity"
                                />
                                <Input
                                    inputMode="decimal"
                                    value={l.unit_price}
                                    onChange={(e) => setLine(i, { unit_price: e.target.value })}
                                    aria-label="Unit price"
                                />
                                <span className="self-center text-right font-medium tabular-nums">
                                    {money(Math.round((parseFloat(l.quantity) || 0) * (toPence(l.unit_price) ?? 0)), form.data.currency)}
                                </span>
                                {draft && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove line"
                                        onClick={() =>
                                            form.setData(
                                                'lines',
                                                form.data.lines.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {draft && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('lines', [
                                        ...form.data.lines,
                                        { description: '', quantity: '1', unit_price: '', source_quote: null },
                                    ])
                                }
                            >
                                <Plus /> Add line
                            </Button>
                        )}
                    </fieldset>

                    <div className="flex flex-wrap items-baseline justify-between gap-3 border-t pt-3">
                        <span className="text-lg font-semibold">Total {money(draft ? total : invoice.total_minor, form.data.currency)}</span>
                        {invoice.po_total_minor !== null && (
                            <span
                                className={
                                    (draft ? total : invoice.total_minor) === invoice.po_total_minor
                                        ? 'text-sm text-emerald-700'
                                        : 'text-sm text-rose-600'
                                }
                            >
                                {(draft ? total : invoice.total_minor) === invoice.po_total_minor
                                    ? 'Matches the PO total'
                                    : `PO total is ${money(invoice.po_total_minor, form.data.currency)}`}
                            </span>
                        )}
                    </div>

                    <fieldset disabled={!draft} className="space-y-1.5">
                        <Label>Notes on the invoice</Label>
                        <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </fieldset>
                    {draft && <Button disabled={form.processing || !form.isDirty}>{form.isDirty ? 'Save draft' : 'Saved'}</Button>}
                </form>
            </div>
        </AppLayout>
    );
}
