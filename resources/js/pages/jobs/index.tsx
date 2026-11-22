import { JobForm, type JobData } from '@/components/job-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface Row extends JobData {
    id: number;
    client: string | null;
    income_pence: number;
    costs_pence: number;
}

export default function Jobs({ jobs, clients, filters }: { jobs: Row[]; clients: { id: number; name: string }[]; filters: { year: string | null } }) {
    const { nav } = usePage<SharedData>().props;
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={[{ title: 'Jobs', href: '/jobs' }]}>
            <Head title="Jobs" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
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
                            onChange={(e) => router.get('/jobs', e.target.value ? { year: e.target.value } : {}, { preserveState: true })}
                            aria-label="Tax year"
                        >
                            <option value="">All years</option>
                            {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                        </select>
                        <Button onClick={() => setAdding(true)}>
                            <Plus /> Add job
                        </Button>
                    </div>
                </div>
                <ul className="divide-y rounded-xl border">
                    {jobs.map((j) => (
                        <li key={j.id}>
                            <Link href={`/jobs/${j.id}`} className="hover:bg-muted/40 flex flex-wrap items-center justify-between gap-3 p-4">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{j.title}</span>
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
                    {jobs.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">No jobs yet.</li>}
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
