import { InvoicedTick, type Invoiced } from '@/components/doc-line';
import { JobForm, type JobData } from '@/components/job-form';
import { JobsTabs } from '@/components/jobs-tabs';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileWarning, Plus, Search } from 'lucide-react';
import { useState } from 'react';

interface Row extends JobData {
    id: number;
    client: string | null;
    income_pence: number;
    costs_pence: number;
    po: { number: string | null; document_id: number | null; source: string } | null;
    missing: boolean;
    invoice: Invoiced | null;
}

export default function Jobs({
    jobs,
    clients,
    filters,
    counts,
}: {
    jobs: Row[];
    clients: { id: number; name: string }[];
    filters: { year: string | null; show: string | null };
    counts: { no_po: number; not_invoiced: number; possible: number };
}) {
    const go = (patch: Record<string, string | null>) =>
        router.get('/jobs', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v)), { preserveState: true });
    const { nav } = usePage<SharedData>().props;
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={[{ title: 'Jobs', href: '/jobs' }]}>
            <Head title="Jobs" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <JobsTabs />
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Jobs</h1>
                        <p className="text-muted-foreground text-sm">
                            Costs and mileage you link to a job show its real profit. Purchases like equipment don't need a job.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <select
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={filters.year ?? ''}
                            onChange={(e) => go({ year: e.target.value || null })}
                            aria-label="Tax year"
                        >
                            <option value="">All years</option>
                            {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                        </select>
                        <Button variant="outline" asChild>
                            <Link href="/jobs/finder">
                                <Search /> Find jobs{counts.possible ? ` (${counts.possible})` : ''}
                            </Link>
                        </Button>
                        <Button onClick={() => setAdding(true)}>
                            <Plus /> Add job
                        </Button>
                    </div>
                </div>
                <div className="flex flex-wrap gap-1.5 text-sm">
                    {[
                        [null, 'All jobs'],
                        ['no_po', `No PO (${counts.no_po})`],
                        ['not_invoiced', `Not invoiced (${counts.not_invoiced})`],
                    ].map(([key, label]) => (
                        <button
                            key={label}
                            onClick={() => go({ show: key })}
                            className={cn('rounded-full border px-3 py-1', (filters.show ?? null) === key && 'bg-foreground text-background')}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <ul className="divide-y rounded-xl border">
                    {jobs.map((j) => (
                        <li key={j.id} className={cn(j.missing && 'border-l-4 border-l-amber-400 bg-amber-50/60 dark:bg-amber-950/20')}>
                            <Link href={`/jobs/${j.id}`} className="hover:bg-muted/40 flex flex-wrap items-center justify-between gap-3 p-4">
                                <span className="min-w-0">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-medium">{j.title}</span>
                                        {j.invoice && <InvoicedTick invoiced={j.invoice} />}
                                        {j.missing && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                <FileWarning className="size-3.5" aria-hidden /> No PO
                                            </span>
                                        )}
                                        {j.po?.number && <span className="text-muted-foreground text-xs">PO {j.po.number}</span>}
                                    </span>
                                    <span className="text-muted-foreground text-sm">
                                        {day(j.starts_on)}
                                        {j.ends_on !== j.starts_on ? ` – ${day(j.ends_on)}` : ''}
                                        {j.client ? ` · ${j.client}` : ''}
                                        {j.location ? ` · ${j.location}` : ''}
                                    </span>
                                </span>
                                <span className="text-right text-sm tabular-nums">
                                    <span className="block">{money(j.income_pence)} in</span>
                                    <span className="text-muted-foreground">{money(j.costs_pence)} costs</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                    {jobs.length === 0 && (
                        <li className="text-muted-foreground p-6 text-center text-sm">{filters.show ? 'Nothing here.' : 'No jobs yet.'}</li>
                    )}
                </ul>
            </div>
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add a job</DialogTitle>
                    </DialogHeader>
                    <JobForm clients={clients} onDone={() => setAdding(false)} />
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
