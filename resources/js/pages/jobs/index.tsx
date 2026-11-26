import { ResponsiveSheet, SheetBody } from '@/components/calendar-pick/responsive-sheet';
import { InvoicedTick, type Invoiced } from '@/components/doc-line';
import { DoneButton } from '@/components/done-prompt';
import { JobForm, type JobData } from '@/components/job-form';
import { StageChip } from '@/components/job-stage';
import { JobsTabs } from '@/components/jobs-tabs';
import { PoChasePanel } from '@/components/po-chase';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type JobStageInfo } from '@/types/clients';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileWarning, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';

interface Row extends JobData {
    id: number;
    client: string | null;
    income_pence: number;
    costs_pence: number;
    po: { number: string | null; document_id: number | null; source: string } | null;
    missing: boolean;
    invoice: Invoiced | null;
    /** Where it is (JobStage): its chip, and "Done?" once its last day has finished. */
    stage: JobStageInfo | null;
    done_due: boolean;
}

export default function Jobs({
    jobs,
    clients,
    filters,
    counts,
    client = null,
}: {
    jobs: Row[];
    clients: { id: number; name: string }[];
    filters: { year: string | null; show: string | null; client?: number | null };
    counts: { no_po: number; not_invoiced: number; possible: number; done_due?: number };
    /** One client's jobs ("All 12" from their page): the ones they pay for or were the end client of. */
    client?: { id: number; name: string } | null;
}) {
    const go = (patch: Record<string, string | number | null>) =>
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
                    <div className="flex flex-wrap gap-2">
                        <select
                            className="border-input h-11 rounded-md border bg-transparent px-2 text-sm dark:bg-neutral-900"
                            value={filters.year ?? ''}
                            onChange={(e) => go({ year: e.target.value || null })}
                            aria-label="Tax year"
                        >
                            <option value="">All years</option>
                            {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                        </select>
                        <Button variant="outline" className="h-11" asChild>
                            <Link href="/jobs/finder">
                                <Search /> Find jobs{counts.possible ? ` (${counts.possible})` : ''}
                            </Link>
                        </Button>
                        <Button className="h-11" onClick={() => setAdding(true)}>
                            <Plus /> Add job
                        </Button>
                    </div>
                </div>
                {filters.show === 'no_po' ? <PoChasePanel /> : !filters.show && <PoChasePanel limit={5} allHref="/jobs?show=no_po" />}
                <div className="flex flex-wrap gap-2 text-sm" role="radiogroup" aria-label="Show">
                    {(
                        [
                            [null, 'All jobs'],
                            ...((counts.done_due ?? 0) > 0 || filters.show === 'done_due' ? [['done_due', `Done? (${counts.done_due ?? 0})`]] : []),
                            ['no_po', `No PO (${counts.no_po})`],
                            ['not_invoiced', `Not invoiced (${counts.not_invoiced})`],
                        ] as [string | null, string][]
                    ).map(([key, label]) => (
                        <button
                            key={label}
                            type="button"
                            role="radio"
                            aria-checked={(filters.show ?? null) === key}
                            onClick={() => go({ show: key })}
                            className={cn(
                                'min-h-11 rounded-full border px-4',
                                (filters.show ?? null) === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                                key === 'done_due' && (filters.show ?? null) !== key && 'border-amber-400 dark:border-amber-700',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                    {client && (
                        <button
                            type="button"
                            onClick={() => go({ client: null })}
                            className="bg-muted flex min-h-11 items-center gap-1.5 rounded-full border px-4"
                            aria-label={`Show every client’s jobs, not only ${client.name}’s`}
                        >
                            {client.name} <X className="size-4" aria-hidden />
                        </button>
                    )}
                </div>
                <ul className="divide-y rounded-xl border">
                    {jobs.map((j) => (
                        <li
                            key={j.id}
                            className={cn(
                                'flex items-center gap-2 pr-3',
                                j.missing && 'border-l-4 border-l-amber-400 bg-amber-50/60 dark:bg-amber-950/20',
                            )}
                        >
                            <Link
                                href={`/jobs/${j.id}`}
                                className="hover:bg-muted/40 flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-3 gap-y-1 p-4 pr-1"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium break-words">{j.title}</span>
                                        {j.invoice && <InvoicedTick invoiced={j.invoice} />}
                                        {j.missing && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                <FileWarning className="size-3.5" aria-hidden /> No PO
                                            </span>
                                        )}
                                        {j.po?.number && <span className="text-muted-foreground text-xs">PO {j.po.number}</span>}
                                    </span>
                                    <span className="text-muted-foreground block text-sm break-words">
                                        {day(j.starts_on)}
                                        {j.ends_on !== j.starts_on ? ` – ${day(j.ends_on)}` : ''}
                                        {j.client ? ` · ${j.client}` : ''}
                                        {j.end_client ? ` for ${j.end_client}` : ''}
                                        {j.location ? ` · ${j.location}` : ''}
                                    </span>
                                    {j.stage && <StageChip stage={j.stage} className="mt-1" />}
                                </span>
                                <span className="text-right text-sm tabular-nums">
                                    <span className="block">{money(j.income_pence)} in</span>
                                    <span className="text-muted-foreground">{money(j.costs_pence)} costs</span>
                                </span>
                            </Link>
                            {j.done_due && <DoneButton job={j} />}
                        </li>
                    ))}
                    {jobs.length === 0 && (
                        <li className="text-muted-foreground p-6 text-center text-sm">{filters.show || client ? 'Nothing here.' : 'No jobs yet.'}</li>
                    )}
                </ul>
            </div>
            <ResponsiveSheet open={adding} onOpenChange={setAdding} title="Add a job">
                <SheetBody>
                    <JobForm clients={clients} onDone={() => setAdding(false)} />
                </SheetBody>
            </ResponsiveSheet>
        </AppLayout>
    );
}
