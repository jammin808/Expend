import AppLayout from '@/layouts/app-layout';
import { day } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';

// A plain list of the props for now: the client list (filters, duplicates and compare sheet) is built in stage 4 (F3).
export interface ClientRow {
    id: number;
    name: string;
    invoice_name: string | null;
    billing_email: string | null;
    merged_names: string[];
    owed: { currency: string; minor: number }[];
    owed_words: string | null;
    overdue: number;
    to_invoice: number;
    last_job_on: string | null;
    jobs_this_year: number;
    end_client_ids: number[];
    recent_words: string | null;
    for: string | null;
}

interface Props {
    clients: ClientRow[];
    counts: { owe: number; to_invoice: number; all: number };
    filters: { q: string | null; show: string; year: string };
    figures: { client_id: number; made_pence: number; profit_pence: number }[];
    duplicates: { a: { id: number; name: string }; b: { id: number; name: string }; why: string; text: string }[];
}

export default function Clients({ clients, counts, duplicates }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }]}>
            <Head title="Clients" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">Clients</h1>
                <p className="text-muted-foreground text-sm">
                    {counts.all} clients · {counts.owe} owe you · {counts.to_invoice} to invoice
                </p>
                {duplicates.length > 0 && (
                    <ul className="space-y-1 rounded-xl border p-3 text-sm">
                        {duplicates.map((d) => (
                            <li key={`${d.a.id}:${d.b.id}`}>{d.text}</li>
                        ))}
                    </ul>
                )}
                <ul className="divide-y rounded-xl border">
                    {clients.map((c) => (
                        <li key={c.id}>
                            <Link href={`/clients/${c.id}`} className="hover:bg-muted/40 block min-h-14 p-3">
                                <span className="font-medium">{c.name}</span>
                                {c.for && <span className="text-muted-foreground"> · {c.for}</span>}
                                <span className="text-muted-foreground block text-xs">
                                    {c.owed_words ??
                                        (c.to_invoice > 0
                                            ? `${c.to_invoice} ${c.to_invoice === 1 ? 'job' : 'jobs'} to invoice`
                                            : (c.recent_words ?? (c.last_job_on ? `Last job ${day(c.last_job_on)}` : '')))}
                                </span>
                            </Link>
                        </li>
                    ))}
                    {clients.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">No clients yet.</li>}
                </ul>
            </div>
        </AppLayout>
    );
}
