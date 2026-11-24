import { JobsTabs } from '@/components/jobs-tabs';
import { emptyOpportunity, OpportunityForm } from '@/components/opportunity-form';
import { daysText, OpportunityStatus, ordinal, type OpportunityRow } from '@/components/opportunity-status';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { day } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Clock, Plus } from 'lucide-react';
import { useState } from 'react';

type Filter = 'open' | 'reply' | 'needs_job' | 'enquiry' | 'held' | 'offered' | 'confirmed' | 'jobs' | 'closed' | 'all';

const FILTERS: [Filter, string][] = [
    ['open', 'Open'],
    ['reply', 'To answer'],
    ['needs_job', 'Booked, no job'],
    ['enquiry', 'Asked'],
    ['held', 'On hold'],
    ['offered', 'Offered'],
    ['jobs', 'Made jobs'],
    ['closed', 'Closed'],
    ['all', 'All'],
];

const EMPTY: Record<Filter, string> = {
    open: 'Nothing waiting. Enquiries, holds and offers read from your emails and documents appear here.',
    reply: 'Nothing to answer in the next few days.',
    needs_job: 'No bookings without a job.',
    enquiry: 'No availability checks waiting.',
    held: 'Nothing on hold.',
    offered: 'No offers waiting.',
    confirmed: 'Nothing confirmed.',
    jobs: 'None made into jobs yet.',
    closed: 'Nothing closed.',
    all: 'Nothing found yet.',
};

/** Work you might get, found in your emails and documents, before there's a job: answer it, then record what you decided. */
export default function Opportunities({
    opportunities,
    counts,
    filters,
    new_statuses,
    day_kinds,
    rate_units,
    lastRun,
}: {
    opportunities: OpportunityRow[];
    counts: Record<Filter, number>;
    filters: { show: Filter };
    statuses: Record<string, string>;
    new_statuses: Record<string, string>;
    day_kinds: Record<string, string>;
    rate_units: Record<string, string>;
    lastRun: { read: number; linked: number; expired: number; needs_job: number; open: number; at: string } | null;
}) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Jobs', href: '/jobs' },
                { title: 'Enquiries & offers', href: '/opportunities' },
            ]}
        >
            <Head title="Enquiries & offers" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <JobsTabs />
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="max-w-2xl">
                        <h1 className="text-2xl font-semibold tracking-tight">Enquiries & offers</h1>
                        <p className="text-muted-foreground text-sm">
                            Availability checks, holds, offers and bookings read from your emails and documents, before there’s a job. Every detail
                            shows the words it came from. Expend never replies for you: answer the client yourself, then record what you decided here.
                            Only you make one a job.
                        </p>
                    </div>
                    <Button onClick={() => setAdding(true)}>
                        <Plus /> Add one yourself
                    </Button>
                </div>
                <p className="text-muted-foreground text-xs">
                    {lastRun
                        ? `Last checked ${new Date(lastRun.at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}: ${lastRun.read} read, ${lastRun.linked} matched to your jobs, ${lastRun.expired} passed with no answer.`
                        : 'Checked every night, and as soon as an email or document has been read.'}
                </p>

                <div className="flex flex-wrap gap-1.5 text-sm" role="tablist" aria-label="Show">
                    {FILTERS.map(([key, label]) => (
                        <button
                            key={key}
                            role="tab"
                            aria-selected={filters.show === key}
                            onClick={() => router.get('/opportunities', key === 'open' ? {} : { show: key }, { preserveState: true })}
                            className={cn(
                                'rounded-full border px-3 py-1',
                                filters.show === key && 'bg-foreground text-background',
                                key === 'reply' && counts.reply > 0 && filters.show !== key && 'border-rose-300 text-rose-700 dark:text-rose-300',
                            )}
                        >
                            {label} ({counts[key] ?? 0})
                        </button>
                    ))}
                </div>

                <ul className="divide-y rounded-xl border">
                    {opportunities.map((o) => (
                        <li key={o.id}>
                            <Link href={`/opportunities/${o.id}`} className="hover:bg-muted/40 flex flex-wrap items-start justify-between gap-3 p-4">
                                <span className="min-w-0 space-y-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-medium">{o.title}</span>
                                        <OpportunityStatus status={o.status} label={o.status_label} />
                                        {o.hold_rank && ['held', 'offered', 'confirmed'].includes(o.status) && (
                                            <span className="text-muted-foreground text-xs">{ordinal(o.hold_rank)} option</span>
                                        )}
                                        {o.job && (
                                            <span className="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
                                                <Briefcase className="size-3.5" aria-hidden /> {o.job.title}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        {daysText(o.days) || 'No dates yet'}
                                        {o.client_name ? ` · ${o.client_name}` : ''}
                                        {o.venue ? ` · ${o.venue}` : ''}
                                        {o.rate ? ` · ${o.rate.text}` : ''}
                                    </span>
                                    <span className="text-muted-foreground flex flex-wrap gap-x-3 text-xs">
                                        {o.contact_name || o.contact_email ? <span>From {o.contact_name ?? o.contact_email}</span> : null}
                                        <span>
                                            {o.found_by === 'you'
                                                ? 'Added by you'
                                                : `Read from ${o.documents} ${o.documents === 1 ? 'email or document' : 'emails and documents'}`}
                                        </span>
                                        {o.unverified.length > 0 && (
                                            <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                                <AlertTriangle className="size-3" aria-hidden /> Not found word for word: {o.unverified.join(', ')}
                                            </span>
                                        )}
                                    </span>
                                </span>
                                {o.reply_by && o.open && (
                                    <span
                                        className={cn(
                                            'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                            (o.reply_in ?? 99) <= 3
                                                ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                                : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                        )}
                                    >
                                        <Clock className="size-3" aria-hidden />
                                        {(o.reply_in ?? 0) < 0 ? `Reply was due ${day(o.reply_by)}` : `Reply by ${day(o.reply_by)}`}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                    {opportunities.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">{EMPTY[filters.show]}</li>}
                </ul>
            </div>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add work you’ve been asked about</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">For work that came by phone or message. It isn’t a job until you make it one.</p>
                    <OpportunityForm
                        initial={emptyOpportunity}
                        statuses={new_statuses}
                        dayKinds={day_kinds}
                        rateUnits={rate_units}
                        url="/opportunities"
                        method="post"
                        submitLabel="Add"
                        onDone={() => setAdding(false)}
                    />
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
