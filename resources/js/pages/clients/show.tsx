import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { type InvoiceRow } from '@/pages/invoices/index';
import { Head, Link } from '@inertiajs/react';

// A plain list of the props for now: the client page (summary card, owed, jobs, money, costs, terms editor, details)
// is built in stage 4 (F3).
type JobRow = { id: number; title: string; starts_on: string; ends_on: string; status: string; client: string | null; end_client: string | null };

interface Props {
    client: {
        id: number;
        name: string;
        invoice_name: string | null;
        billing_email: string | null;
        billing_address: string | null;
        payment_terms_days: number | null;
        invoice_route: string;
        notes: string | null;
        merged: { id: number; name: string }[];
        payers: { key: string; payments: number; text: string }[];
        work_for: string | null;
        booked_through: string | null;
        locked_years: string[];
    };
    owed: {
        invoices: InvoiceRow[];
        jobs: JobRow[];
        costs_to_charge: { pence: number; other: Record<string, number>; count: number };
        to_claim: { count: number; pence: number };
        last_paid: { number: string | null; issue_date: string | null } | null;
    };
    jobs: { paying: JobRow[]; paying_count: number; end_client: JobRow[]; end_client_count: number };
    figures: { made_pence: number; spent_pence: number; profit_pence: number; footnote: string; cash_basis_note: string | null; tax_year: string };
    costs: {
        ref: string;
        id: string;
        description: string;
        date: string | null;
        status: string;
        chip: string;
        currency: string;
        amount_minor: number | null;
    }[];
}

export default function ClientShow({ client, owed, jobs, figures, costs }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: client.name, href: `/clients/${client.id}` },
            ]}
        >
            <Head title={client.name} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">{client.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        {figures.tax_year}: made {money(figures.made_pence)} · spent {money(figures.spent_pence)} · profit{' '}
                        {money(figures.profit_pence)}
                    </p>
                    {client.payers.map((p) => (
                        <p key={p.key} className="text-muted-foreground text-xs">
                            {p.text}
                        </p>
                    ))}
                    {figures.cash_basis_note && <p className="text-muted-foreground text-xs">{figures.cash_basis_note}</p>}
                </div>
                <section className="space-y-1">
                    <h2 className="font-medium">Owed</h2>
                    {owed.invoices.map((i) => (
                        <Link key={i.id} href={`/invoices/${i.id}`} className="block text-sm underline">
                            {i.number} · {money(i.outstanding_minor ?? 0, i.currency)} still to come
                        </Link>
                    ))}
                    {owed.jobs.map((j) => (
                        <Link key={j.id} href={`/jobs/${j.id}`} className="block text-sm underline">
                            {j.title} · not invoiced yet
                        </Link>
                    ))}
                    {owed.invoices.length === 0 && owed.jobs.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            Nothing owed.{owed.last_paid?.issue_date ? ` Last paid ${day(owed.last_paid.issue_date)}.` : ''}
                        </p>
                    )}
                </section>
                <section className="space-y-1">
                    <h2 className="font-medium">Jobs ({jobs.paying_count})</h2>
                    {jobs.paying.map((j) => (
                        <Link key={j.id} href={`/jobs/${j.id}`} className="block text-sm underline">
                            {j.title} · {day(j.starts_on)}
                        </Link>
                    ))}
                </section>
                <section className="space-y-1">
                    <h2 className="font-medium">Costs</h2>
                    {costs.map((c) => (
                        <p key={c.ref} className="text-sm">
                            {c.description} · {c.amount_minor !== null ? money(c.amount_minor, c.currency) : ''} · {c.status}
                        </p>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
