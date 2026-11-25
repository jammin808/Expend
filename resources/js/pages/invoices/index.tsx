import { StatusPill } from '@/components/status-pill';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

export interface InvoiceRow {
    id: number;
    number: string | null;
    status: string;
    client: string | null;
    job: { id: number; title: string } | null;
    po_number: string | null;
    currency: string;
    total_minor: number;
    paid_minor: number;
    issue_date: string | null;
    due_date: string | null;
    overdue: boolean;
    // Income design v3: where its money stands, what's still to come, and how many days it's overdue.
    state?: string;
    outstanding_minor?: number;
    overdue_days?: number | null;
}

export default function Invoices({ invoices }: { invoices: InvoiceRow[] }) {
    const outstanding = invoices.filter((i) => i.status === 'issued').reduce((s, i) => s + (i.total_minor - i.paid_minor), 0);
    return (
        <AppLayout breadcrumbs={[{ title: 'Invoices', href: '/invoices' }]}>
            <Head title="Invoices" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Invoices</h1>
                    <p className="text-muted-foreground text-sm">
                        {money(outstanding)} outstanding (sterling invoices). Draft one from a PO on its document page, or from a job.
                    </p>
                </div>
                <ul className="divide-y rounded-xl border">
                    {invoices.map((i) => (
                        <li key={i.id}>
                            <Link href={`/invoices/${i.id}`} className="hover:bg-muted/40 flex flex-wrap items-center justify-between gap-3 p-3">
                                <span className="min-w-0">
                                    <span className="font-medium">{i.number ?? 'Draft'}</span>
                                    <span className="text-muted-foreground"> · {i.client ?? 'No client yet'}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {i.job?.title}
                                        {i.po_number ? ` · PO ${i.po_number}` : ''}
                                        {i.due_date ? ` · due ${day(i.due_date)}` : ''}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3">
                                    <span className="font-medium tabular-nums">{money(i.total_minor, i.currency)}</span>
                                    {i.overdue ? (
                                        <span className="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800">Overdue</span>
                                    ) : (
                                        <StatusPill status={i.status === 'paid' ? 'confirmed' : i.status === 'issued' ? 'suggested' : 'unreviewed'} />
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                    {invoices.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">No invoices yet.</li>}
                </ul>
            </div>
        </AppLayout>
    );
}
