import { ItemDrawer } from '@/components/item-drawer';
import { JobForm, type JobData } from '@/components/job-form';
import { Money } from '@/components/money';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day, kindLabels, money } from '@/lib/format';
import { type Item, type Lookups } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    job: JobData & { id: number; client: string | null };
    lines: {
        id: number;
        date: string;
        description: string;
        account: string;
        kind: string;
        category: string | null;
        amount_pence: number;
        confirmed: boolean;
        source: 'bank' | 'manual';
        source_id: number;
    }[];
    mileage: { id: number; trip_on: string; from_place: string; to_place: string; miles: string; claim_pence: number; note: string }[];
    totals: { income_pence: number; costs_pence: number; mileage_pence: number; profit_pence: number };
    near: Item[];
    lookups: Lookups;
    clients: { id: number; name: string }[];
}

export default function JobShow({ job, lines, mileage, totals, near, lookups, clients }: Props) {
    const [editing, setEditing] = useState(false);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Jobs', href: '/jobs' },
                { title: job.title, href: `/jobs/${job.id}` },
            ]}
        >
            <Head title={job.title} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{job.title}</h1>
                        <p className="text-muted-foreground text-sm">
                            {day(job.starts_on)}
                            {job.ends_on !== job.starts_on ? ` – ${day(job.ends_on)}` : ''}
                            {job.start_time ? ` · from ${job.start_time}` : ''}
                            {job.end_time ? ` to ${job.end_time}` : ''}
                            {job.client ? ` · ${job.client}` : ''}
                            {job.location ? ` · ${job.location}` : ''} {job.postcode ?? ''}
                        </p>
                    </div>
                    <Button variant="outline" onClick={() => setEditing(true)}>
                        Edit job
                    </Button>
                </div>

                <div className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Income', totals.income_pence],
                        ['Costs', totals.costs_pence],
                        ['Mileage', totals.mileage_pence],
                        ['Profit', totals.profit_pence],
                    ].map(([label, value]) => (
                        <div key={label as string} className="rounded-xl border p-4">
                            <p className="text-muted-foreground text-sm">{label}</p>
                            <p className="text-xl font-semibold tabular-nums">{money(value as number)}</p>
                        </div>
                    ))}
                </div>
                {job.fee_pence ? (
                    <p className="text-muted-foreground text-sm">
                        Agreed fee {money(job.fee_pence)}. Income counts payments you've confirmed as this job's.
                    </p>
                ) : null}

                <section className="space-y-2">
                    <h2 className="font-semibold">Linked to this job</h2>
                    <ul className="divide-y rounded-xl border text-sm">
                        {lines.map((l) => (
                            <li
                                key={l.id}
                                className="hover:bg-muted/40 flex cursor-pointer items-center justify-between gap-3 p-3"
                                onClick={() => setDrawer({ source: l.source, id: l.source_id })}
                            >
                                <span className="min-w-0">
                                    <span className="block truncate">{l.description}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {day(l.date)} · {l.account} · {kindLabels[l.kind]}
                                        {l.category ? ` · ${l.category}` : ''}
                                        {!l.confirmed ? ' · not confirmed' : ''}
                                    </span>
                                </span>
                                <Money pence={l.amount_pence} />
                            </li>
                        ))}
                        {mileage.map((m) => (
                            <li key={'m' + m.id} className="flex items-center justify-between gap-3 p-3">
                                <span>
                                    <span className="block">
                                        Mileage: {m.from_place} → {m.to_place}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {day(m.trip_on)} · {m.miles} miles · {m.note} · travel expense
                                    </span>
                                </span>
                                <Money pence={-m.claim_pence} />
                            </li>
                        ))}
                        {lines.length + mileage.length === 0 && <li className="text-muted-foreground p-4">Nothing linked yet.</li>}
                    </ul>
                </section>

                {near.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Undecided around these dates</h2>
                        <p className="text-muted-foreground text-sm">Link something here, or open it to choose the category and split it.</p>
                        <ul className="divide-y rounded-xl border text-sm">
                            {near.map((t) => (
                                <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <button className="min-w-0 text-left" onClick={() => setDrawer({ source: 'bank', id: t.id })}>
                                        <span className="block truncate hover:underline">{t.description}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {day(t.date)} · {t.account?.name}
                                        </span>
                                    </button>
                                    <span className="flex items-center gap-3">
                                        <Money pence={t.amount_pence} />
                                        <StatusPill status={t.review_status} />
                                        <Button size="sm" variant="outline" onClick={() => setDrawer({ source: 'bank', id: t.id })}>
                                            Link
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit job</DialogTitle>
                    </DialogHeader>
                    <JobForm job={job} clients={clients} onDone={() => setEditing(false)} />
                </DialogContent>
            </Dialog>
            <ItemDrawer
                target={drawer}
                lookups={lookups}
                onClose={() => {
                    setDrawer(null);
                    router.reload({ only: ['lines', 'near', 'totals', 'mileage'] });
                }}
            />
        </AppLayout>
    );
}
