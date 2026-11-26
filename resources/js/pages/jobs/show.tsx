import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { CheckBadge, type Check } from '@/components/check-badge';
import { costAmount, CostChip, CostSheet } from '@/components/cost-sheet';
import { DocLine, InvoicedTick, type DocRow, type Invoiced } from '@/components/doc-line';
import { CancelSheet, cancelWords, DonePrompt, EarlySheet, markDone } from '@/components/done-prompt';
import { Explain } from '@/components/explain';
import { type AllocatorTick } from '@/components/income-allocator';
import { ItemDrawer } from '@/components/item-drawer';
import { JobDays, type JobDayRow } from '@/components/job-days';
import { JobFinds, type Find, type GatherState } from '@/components/job-finds';
import { JobForm, type JobData } from '@/components/job-form';
import { JobSheet, type SheetFact } from '@/components/job-sheet';
import { StageChip } from '@/components/job-stage';
import { Money } from '@/components/money';
import { PoChasePanel } from '@/components/po-chase';
import { StatusPill } from '@/components/status-pill';
import { TermsEditor } from '@/components/terms-editor';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { day, kindLabels, money, paidIn, paidWith, rateText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Item, type Lookups } from '@/types';
import { type CostRow, type JobStageInfo, type TermsSheet } from '@/types/clients';
import { type InvoiceRow } from '@/types/invoices';
import { Head, Link, router } from '@inertiajs/react';
import { Camera, ChevronDown, FileWarning } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

interface Props {
    job: JobData & {
        id: number;
        client: string | null;
        po: { number: string | null; document_id: number | null; source: string } | null;
        missing: boolean;
        invoice: Invoiced | null;
    };
    /** Where it is (JobStage): its chip, "Done?" once its last day has finished, its draft and its invoice. */
    stage: JobStageInfo | null;
    /** Done with no invoice: "Draft its invoice?". */
    offers_draft: boolean;
    /** Its days not finished yet: Done first asks "Did it finish early?". */
    days_to_come: string[];
    /** "Not yet" on "All done?", tied to the job as shown (null once it isn't booked). */
    not_yet_fp?: string | null;
    /** Its costs and work, each with where it stands for charging and what its sheet offers. */
    charges: CostRow[];
    /** Its arrangement for travel and expenses: its own rows, else its client's usual. */
    terms: TermsSheet;
    lines: {
        id: number;
        date: string;
        description: string;
        account: { name: string } | null;
        time: string | null;
        place: string | null;
        card_last4: string | null;
        payment_type: string | null;
        original_amount_minor: number | null;
        original_currency: string | null;
        merchant_amount_minor?: number | null;
        merchant_currency?: string | null;
        fx: { units_per_gbp: string | null; rate_date: string | null; source: string | null } | null;
        /** The whole payment: the line's amount is the job's part of it. */
        payment_pence: number;
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
    facts: SheetFact[];
    documents: (DocRow & { linked_by: string | null; check: Check | null })[];
    invoices: InvoiceRow[];
    kinds: Record<string, string>;
    calendar: {
        key: string;
        date: string;
        summary: string | null;
        location: string | null;
        time: string | null;
        linked: boolean;
        other_job: number | null;
        by: string | null;
        not_job: boolean;
        check: Check | null;
    }[];
    travel: {
        id: number;
        kind: string;
        provider: string;
        place: string | null;
        starts_on: string;
        ends_on: string | null;
        amount_minor: number | null;
        currency: string;
        linked: boolean;
        by: string | null;
        decision: string | null;
        source: string;
        check: Check | null;
    }[];
    finds: Find[];
    gather: GatherState;
    days: JobDayRow[];
    day_kinds: Record<string, string>;
    /** Business-card receipts from your phone for this job whose bank line hasn't come in yet; words is "£12.50 parking". */
    quick_waiting?: {
        id: number;
        words: string;
        spent_on: string | null;
        amount_minor: number | null;
        currency: string;
        document_id: number | null;
        href: string;
    }[];
}

const byLabel = (by: string | null) => (by === 'you' ? 'linked by you' : by === 'ai' ? 'linked by AI (checked)' : by ? 'linked automatically' : '');

/** "£650 for the job · £250 a day · 3 days agreed". */
function priceWords(job: Props['job']): string | null {
    const parts = [
        job.fee_pence ? `${money(job.fee_pence)} for the job` : null,
        job.rate_pence ? `${money(job.rate_pence)} ${job.rate_unit === 'hour' ? 'an hour' : 'a day'}` : null,
        job.days_agreed ? `${job.days_agreed} ${job.days_agreed === 1 ? 'day' : 'days'} agreed` : null,
    ].filter(Boolean);
    return parts.length ? parts.join(' · ') : null;
}

function Card({ id, title, children, className }: { id?: string; title: string; children: ReactNode; className?: string }) {
    return (
        <section id={id} aria-label={title} className={cn('scroll-mt-16 space-y-3 rounded-xl border p-4', className)}>
            <h2 className="font-semibold">{title}</h2>
            {children}
        </section>
    );
}

export default function JobShow({
    job,
    stage,
    offers_draft,
    days_to_come = [],
    not_yet_fp = null,
    charges = [],
    terms,
    lines,
    mileage,
    totals,
    near,
    lookups,
    clients,
    facts,
    documents,
    invoices,
    kinds,
    calendar,
    travel,
    finds,
    gather,
    days,
    day_kinds,
    quick_waiting = [],
}: Props) {
    const link = (data: Record<string, string | number>) => router.post(`/jobs/${job.id}/links`, data, { preserveScroll: true });
    const [editing, setEditing] = useState(false);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const [tick, setTick] = useState<AllocatorTick | null>(null);
    const [cost, setCost] = useState<CostRow | null>(null);
    const [sheet, setSheet] = useState<'status' | 'cancel' | 'early' | null>(null);
    // "Give the cancellation fee on the job's page" (the to-do list and Telegram link to #cancel).
    useEffect(() => {
        const open = () => {
            if (window.location.hash === '#cancel') setSheet('cancel');
        };
        open();
        window.addEventListener('hashchange', open);
        return () => window.removeEventListener('hashchange', open);
    }, []);
    const doneJob = {
        id: job.id,
        title: job.title,
        ends_on: job.ends_on,
        status: job.status,
        cancel_charge: job.cancel_charge,
        cancel_fee_pence: job.cancel_fee_pence,
    };
    const price = priceWords(job);
    // "Link": money in opens with this job ticked (not saved), as income for it; a cost opens as it is.
    const openNear = (t: Item) => {
        setTick(t.amount_pence > 0 ? { job_id: job.id, label: job.title } : null);
        setDrawer({ source: 'bank', id: t.id });
    };
    const workingDays = days.filter((d) => d.kind !== 'travel').length || undefined;
    // The one thing to do next, kept in reach at the bottom (not while "All done?" asks it at the top).
    const draftId = stage?.draft_id ?? null;
    const issued = stage?.invoice_id ?? null;
    const main: { label: string; href?: string; run?: () => void } | null = stage?.done_due
        ? null
        : draftId
          ? { label: 'Check the invoice', href: `/invoices/${draftId}` }
          : offers_draft
            ? { label: 'Draft its invoice', run: () => router.post(`/jobs/${job.id}/draft`) }
            : stage?.stage === 'not_sent' && issued
              ? { label: 'Send the invoice', href: `/invoices/${issued}` }
              : null;

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
                    <div className="min-w-0 space-y-1">
                        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight break-words">
                            {job.title}
                            {job.invoice && <InvoicedTick invoiced={job.invoice} className="text-sm" />}
                        </h1>
                        {stage && (
                            <button
                                type="button"
                                onClick={() => setSheet('status')}
                                className="hover:bg-muted -ml-1 inline-flex min-h-10 items-center gap-1 rounded-full px-1"
                                aria-label={`${stage.chip}: change where it’s up to`}
                            >
                                <StageChip stage={stage} />
                                <ChevronDown className="text-muted-foreground size-4" aria-hidden />
                            </button>
                        )}
                        <p className="text-muted-foreground text-sm break-words">
                            {day(job.starts_on)}
                            {job.ends_on !== job.starts_on ? ` – ${day(job.ends_on)}` : ''}
                            {job.start_time ? ` · from ${job.start_time}` : ''}
                            {job.end_time ? ` to ${job.end_time}` : ''}
                            {job.client && job.client_id ? (
                                <>
                                    {' · '}
                                    <Link href={`/clients/${job.client_id}`} className="underline underline-offset-2">
                                        {job.client}
                                    </Link>
                                </>
                            ) : job.client ? (
                                ` · ${job.client}`
                            ) : (
                                ''
                            )}
                            {job.end_client && job.end_client_id ? (
                                <>
                                    {' for '}
                                    <Link href={`/clients/${job.end_client_id}`} className="underline underline-offset-2">
                                        {job.end_client}
                                    </Link>
                                </>
                            ) : (
                                ''
                            )}
                            {job.location ? ` · ${job.location}` : ''}
                            {/* The postcode once: a venue copied from a calendar entry often has it already. */}
                            {job.postcode &&
                            !(job.location ?? '').toUpperCase().replace(/\s+/g, '').includes(job.postcode.toUpperCase().replace(/\s+/g, ''))
                                ? ` ${job.postcode}`
                                : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* A plain link: the Add expense screen opens from the phone's cache, with this job picked. */}
                        <Button variant="outline" className="h-11" asChild>
                            <a href={`/quick?job=${job.id}`}>
                                <Camera /> Add expense for this job
                            </a>
                        </Button>
                        <Button variant="ghost" className="h-11" asChild>
                            <Link href={`/connections?job=${job.id}`}>Connections</Link>
                        </Button>
                        <Button variant="outline" className="h-11" onClick={() => setEditing(true)}>
                            Edit job
                        </Button>
                    </div>
                </div>

                {stage?.done_due && job.status === 'booked' && <DonePrompt job={doneJob} daysToCome={days_to_come} notYetFp={not_yet_fp} />}

                <InvoiceCard job={job} stage={stage} offersDraft={offers_draft} invoices={invoices} onCancel={() => setSheet('cancel')} />

                {job.missing ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/30">
                        <p className="flex items-center gap-2">
                            <FileWarning className="size-4 text-amber-600" />
                            No PO for this job yet. Ask the client for one before you invoice, or link the PO email under Documents.
                        </p>
                        <Button size="sm" variant="outline" className="h-11" onClick={() => setEditing(true)}>
                            Add PO number, or mark it not needed
                        </Button>
                        <PoChasePanel
                            // Asked again when the job's dates change (days added on this page), so its "Starts in …" is right.
                            key={`${job.id}-${job.starts_on}-${job.ends_on}`}
                            jobId={job.id}
                            className="w-full space-y-2 border-t border-amber-200 pt-3 dark:border-amber-900"
                        />
                    </div>
                ) : (
                    job.po && (
                        <p className="text-muted-foreground text-sm">
                            PO {job.po.number ?? '(number not read)'}
                            {job.po.document_id && (
                                <>
                                    {' · '}
                                    <Link className="underline underline-offset-2" href={`/documents/${job.po.document_id}`}>
                                        view the PO
                                    </Link>
                                </>
                            )}
                        </p>
                    )
                )}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        ['Income', totals.income_pence],
                        ['Costs', totals.costs_pence],
                        ['Mileage', totals.mileage_pence],
                        ['Profit', totals.profit_pence],
                    ].map(([label, value]) => (
                        <div key={label as string} className="rounded-xl border p-3 sm:p-4">
                            <p className="text-muted-foreground text-sm">{label}</p>
                            <p className="text-lg font-semibold tabular-nums sm:text-xl">{money(value as number)}</p>
                        </div>
                    ))}
                </div>
                <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-sm">
                    <span>{price ? `Agreed: ${price}.` : 'No fee or rate yet.'} Income counts payments you’ve confirmed as this job’s.</span>
                    <button type="button" className="min-h-10 underline underline-offset-2" onClick={() => setEditing(true)}>
                        {price ? 'Change' : 'Add the fee or rate'}
                    </button>
                </p>

                <Card id="costs" title="Costs and what’s charged">
                    <JobCharges charges={charges} onOpen={setCost} />
                </Card>

                <Card id="travel" title="Travel and expenses">
                    <TermsEditor sheet={terms} url={`/jobs/${job.id}/terms`} owner="job" clientName={job.client} />
                    {job.client_id && (
                        <Link
                            href={`/clients/${job.client_id}#terms`}
                            className="text-muted-foreground inline-flex min-h-10 items-center text-sm underline underline-offset-2"
                        >
                            Change {job.client}’s usual
                        </Link>
                    )}
                </Card>

                <JobDays
                    jobId={job.id}
                    days={days}
                    kinds={day_kinds}
                    job={{ title: job.title, starts_on: job.starts_on, ends_on: job.ends_on, status: job.status }}
                />

                <JobFinds jobId={job.id} finds={finds} gather={gather} onOpenPayment={(id) => setDrawer({ source: 'bank', id })} />

                <section className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-semibold">Job sheet</h2>
                        <div className="flex gap-2">
                            <label className="hover:bg-muted flex min-h-11 cursor-pointer items-center rounded-md border px-3 text-sm">
                                Add documents
                                <input
                                    type="file"
                                    multiple
                                    className="hidden"
                                    onChange={(e) =>
                                        e.target.files?.length &&
                                        router.post(
                                            '/documents',
                                            { files: Array.from(e.target.files), work_job_id: job.id },
                                            { forceFormData: true, preserveScroll: true },
                                        )
                                    }
                                />
                            </label>
                        </div>
                    </div>
                    <JobSheet job={job} facts={facts} documents={documents} />
                </section>

                {documents.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Documents</h2>
                        <ul className="divide-y rounded-xl border">
                            {documents.map((d) => (
                                <li key={d.id}>
                                    <DocLine d={d} kinds={kinds} />
                                    <div className="flex flex-wrap items-center gap-2 px-3 pb-2 text-xs">
                                        {d.linked_by && <span className="text-muted-foreground">{byLabel(d.linked_by)}</span>}
                                        <CheckBadge check={d.check} subject={{ type: 'document', id: d.id }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {calendar.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Calendar</h2>
                        <p className="text-muted-foreground text-sm">Entries on and around this job’s days. Link the ones that are part of it.</p>
                        <ul className="divide-y rounded-xl border text-sm">
                            {calendar.map((e) => (
                                <li key={e.key} className="flex flex-wrap items-center justify-between gap-2 p-3">
                                    <span className="min-w-0">
                                        <span className="block truncate">{e.summary || '(no title)'}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {day(e.date)}
                                            {e.time ? ` · ${e.time}` : ''}
                                            {e.location ? ` · ${e.location}` : ''}
                                            {e.linked
                                                ? ` · ${byLabel(e.by)}`
                                                : e.other_job
                                                  ? ' · linked to another job'
                                                  : e.not_job
                                                    ? ' · marked not a job'
                                                    : ''}
                                        </span>
                                        {e.linked && e.by !== 'you' && <CheckBadge check={e.check} className="mt-1" />}
                                    </span>
                                    {e.linked ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => link({ type: 'calendar', key: e.key, date: e.date, action: 'unlink' })}
                                        >
                                            Unlink
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => link({ type: 'calendar', key: e.key, date: e.date, action: 'link' })}
                                        >
                                            Link to this job
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {travel.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Stays and travel</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {travel.map((t) => (
                                <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {t.kind}: {t.provider}
                                            {t.place ? `, ${t.place}` : ''}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {t.ends_on && t.ends_on !== t.starts_on
                                                ? `${day(t.starts_on, false)} – ${day(t.ends_on)}`
                                                : day(t.starts_on)}
                                            {t.amount_minor !== null ? ` · ${money(t.amount_minor, t.currency)}` : ''}
                                            {t.decision ? ` · ${t.decision}` : ''}
                                            {t.linked ? ` · ${byLabel(t.by)}` : ' · not linked to a job'}
                                        </span>
                                        {t.source !== 'manual' && (
                                            <div className="mt-1">
                                                <CheckBadge check={t.check} subject={{ type: 'travel_item', id: t.id }} />
                                            </div>
                                        )}
                                    </span>
                                    <span className="flex gap-2">
                                        <Link href="/travel" className="text-muted-foreground self-center text-xs underline-offset-2 hover:underline">
                                            Decide in Stays & travel
                                        </Link>
                                        {t.linked ? (
                                            <Button size="sm" variant="ghost" onClick={() => link({ type: 'travel', id: t.id, action: 'unlink' })}>
                                                Unlink
                                            </Button>
                                        ) : (
                                            <Button size="sm" variant="outline" onClick={() => link({ type: 'travel', id: t.id, action: 'link' })}>
                                                Link to this job
                                            </Button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

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
                                    <span className="flex items-center gap-1.5">
                                        <span className="truncate">{l.description}</span>
                                        <Explain url={`/explain/${l.source}/${l.source_id}`} />
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {day(l.date)}
                                        {l.time ? ` ${l.time}` : ''} · {paidWith(l)}
                                        {l.place ? ` · ${l.place}` : ''}
                                        {paidIn(l) && (
                                            <span title={rateText(l.fx, l.original_currency)}>
                                                {' · paid '}
                                                {paidIn(l)}
                                                {l.payment_pence !== l.amount_pence ? ' in all' : ''}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {kindLabels[l.kind]}
                                        {l.category ? ` · ${l.category}` : ''}
                                        {l.payment_pence !== l.amount_pence ? ` · part of ${money(Math.abs(l.payment_pence))}` : ''}
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

                {quick_waiting.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Receipts waiting for their bank line</h2>
                        <p className="text-muted-foreground text-sm">
                            Paid by the business card and kept from your phone. Each goes on its payment when the bank line comes in, so it isn’t
                            counted until then.
                        </p>
                        <ul className="divide-y rounded-xl border text-sm">
                            {quick_waiting.map((q) => (
                                <li key={q.id}>
                                    <Link href={q.href} className="hover:bg-muted/40 flex min-h-12 items-center justify-between gap-3 p-3">
                                        <span className="min-w-0">
                                            <span className="block truncate">{q.words}</span>
                                            {q.spent_on && <span className="text-muted-foreground text-xs">{day(q.spent_on)}</span>}
                                        </span>
                                        <span className="text-muted-foreground shrink-0 text-xs">Waiting</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {near.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Undecided around these dates</h2>
                        <p className="text-muted-foreground text-sm">Link something here, or open it to choose the category and split it.</p>
                        <ul className="divide-y rounded-xl border text-sm">
                            {near.map((t) => (
                                <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <button className="min-h-11 min-w-0 text-left" onClick={() => openNear(t)}>
                                        <span className="block truncate hover:underline">{t.description}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {day(t.date)}
                                            {t.time ? ` ${t.time}` : ''} · {paidWith(t)}
                                            {t.place ? ` · ${t.place}` : ''}
                                            {paidIn(t) ? ` · paid ${paidIn(t)}` : ''}
                                        </span>
                                    </button>
                                    <span className="flex items-center gap-3">
                                        <Money pence={t.amount_pence} />
                                        <StatusPill status={t.review_status} />
                                        <Button size="sm" variant="outline" className="h-11" onClick={() => openNear(t)}>
                                            Link
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {main && (
                    // The next step, in reach: clear of the round Add expense button on a phone.
                    <div className="bg-background/95 supports-[backdrop-filter]:bg-background/85 sticky bottom-0 z-20 -mx-4 flex items-center gap-3 border-t py-3 pr-[88px] pb-[max(0.75rem,env(safe-area-inset-bottom))] pl-4 backdrop-blur md:-mx-6 md:px-6">
                        <p className="min-w-0 flex-1 text-sm">
                            <span className="block font-medium break-words">{job.title}</span>
                            {stage && <span className="text-muted-foreground">{stage.chip}</span>}
                        </p>
                        {main.href ? (
                            <Button asChild className="h-11 shrink-0 px-5">
                                <Link href={main.href}>{main.label}</Link>
                            </Button>
                        ) : (
                            <Button className="h-11 shrink-0 px-5" onClick={main.run}>
                                {main.label}
                            </Button>
                        )}
                    </div>
                )}
            </div>
            <ResponsiveSheet open={editing} onOpenChange={setEditing} title="Edit job">
                <SheetBody>
                    <JobForm job={job} clients={clients} workingDays={workingDays} onDone={() => setEditing(false)} />
                </SheetBody>
            </ResponsiveSheet>
            <StatusSheet
                job={job}
                stage={stage}
                open={sheet === 'status'}
                onClose={() => setSheet(null)}
                onDone={() => {
                    if (days_to_come.length > 0) return setSheet('early');
                    setSheet(null);
                    markDone(job);
                }}
                onCancel={() => setSheet('cancel')}
            />
            <EarlySheet job={doneJob} days={days_to_come} open={sheet === 'early'} onClose={() => setSheet(null)} />
            <CancelSheet job={doneJob} open={sheet === 'cancel'} onClose={() => setSheet(null)} />
            <CostSheet
                cost={cost}
                clientName={job.client}
                clientId={job.client_id}
                onClose={() => setCost(null)}
                onOpenPayment={(target) => {
                    setTick(null);
                    setDrawer(target);
                }}
            />
            <ItemDrawer
                target={drawer}
                tick={tick}
                lookups={lookups}
                onClose={() => {
                    setDrawer(null);
                    setTick(null);
                    router.reload({ only: ['lines', 'near', 'totals', 'mileage', 'finds', 'quick_waiting', 'charges', 'stage', 'invoices'] });
                }}
            />
        </AppLayout>
    );
}

/**
 * The job's invoice, by where it's up to: its draft to check, "Draft its invoice?" once it's done, the issued one (to
 * send, or what's still to come), or what to do first ("Mark it done when it's finished"). Every invoice of the job is
 * listed under it.
 */
function InvoiceCard({
    job,
    stage,
    offersDraft,
    invoices,
    onCancel,
}: {
    job: Props['job'];
    stage: JobStageInfo | null;
    offersDraft: boolean;
    invoices: InvoiceRow[];
    onCancel: () => void;
}) {
    const draft = stage?.draft_id ?? invoices.find((i) => i.status === 'draft')?.id ?? null;
    const issued = stage?.invoice_id ? invoices.find((i) => i.id === stage.invoice_id) : null;
    const booked = job.status === 'booked';
    let words: ReactNode;
    let action: ReactNode = null;
    if (job.status === 'cancelled' && !draft && !issued) {
        words = `Cancelled. They owe you: ${cancelWords(job).toLowerCase()}.`;
        action = (
            <Button variant="outline" className="h-11" onClick={onCancel}>
                Change what they owe
            </Button>
        );
    } else if (draft) {
        words = job.status === 'cancelled' ? 'Its cancellation invoice is ready to check.' : 'Its invoice is ready to check.';
        action = (
            <Button asChild className="h-11">
                <Link href={`/invoices/${draft}`}>Check the invoice</Link>
            </Button>
        );
    } else if (offersDraft) {
        words = 'It’s done. Draft its invoice?';
        action = (
            <Button className="h-11" onClick={() => router.post(`/jobs/${job.id}/draft`)}>
                Draft its invoice
            </Button>
        );
    } else if (issued) {
        words =
            stage?.stage === 'not_sent'
                ? `${issued.number} is issued but not sent yet.`
                : stage?.stage === 'paid'
                  ? `${issued.number} is paid.`
                  : `${issued.number}: ${stage?.chip ?? ''}.`;
        action = (
            <Button asChild variant={stage?.stage === 'not_sent' ? 'default' : 'outline'} className="h-11">
                <Link href={`/invoices/${issued.id}`}>{stage?.stage === 'not_sent' ? 'Send it' : `Open ${issued.number}`}</Link>
            </Button>
        );
    } else if (stage?.stage === 'waiting_payment') {
        words = `${job.client ?? 'The client'} pays without an invoice from you: waiting for their payment.`;
    } else if (stage?.stage === 'paid') {
        words = 'Paid: money for it has come in.';
    } else if (booked && stage?.done_due && invoices.length === 0) {
        // "All done?" above asks it.
        return null;
    } else if (booked) {
        words = 'Mark it done when it’s finished: that gets its invoice ready to check.';
        action = (
            <Button variant="ghost" className="h-11" onClick={() => router.post(`/jobs/${job.id}/invoice`)}>
                Draft it now instead
            </Button>
        );
    } else {
        words = 'No invoice yet.';
    }

    return (
        <Card id="invoice" title="Invoice">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <p className="min-w-0 flex-1 text-sm">{words}</p>
                {action && <div className="[&>*]:w-full sm:[&>*]:w-auto">{action}</div>}
            </div>
            {invoices.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                    {invoices.map((i) => (
                        <li key={i.id}>
                            <Link href={`/invoices/${i.id}`} className="hover:bg-muted/40 flex min-h-11 items-center justify-between gap-3 px-3 py-2">
                                <span className="flex min-w-0 flex-wrap items-center gap-2">
                                    {/* A draft has no number yet: its pill says Draft, once. */}
                                    <span className="font-medium">{i.number ?? (i.state === 'draft' ? 'Invoice' : 'Draft')}</span>
                                    <StatusPill status={i.overdue && i.state === 'unpaid' ? 'overdue' : i.state} />
                                    {i.status === 'issued' && !i.sent && <span className="text-muted-foreground text-xs">not sent yet</span>}
                                </span>
                                <span className="shrink-0 tabular-nums">{money(i.total_minor, i.currency)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** The job's costs and work, each with where it stands for charging; a cost opens its choices. */
function JobCharges({ charges, onOpen }: { charges: CostRow[]; onOpen: (c: CostRow) => void }) {
    if (charges.length === 0) return <p className="text-muted-foreground text-sm">Nothing to charge yet: costs you link to the job show here.</p>;
    return (
        <ul className="divide-y rounded-lg border">
            {charges.map((c) => {
                const body = (
                    <>
                        <span className="min-w-0 flex-1">
                            <span className="block text-sm break-words">{c.description}</span>
                            <span className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                {c.date ? day(c.date) : null}
                                <CostChip chip={c.chip} />
                                {c.note && <span className="break-words">{c.note}</span>}
                            </span>
                        </span>
                        <span className="shrink-0 text-sm tabular-nums">{costAmount(c)}</span>
                    </>
                );
                return (
                    <li key={c.ref}>
                        {c.actions.length > 0 ? (
                            <button
                                type="button"
                                onClick={() => onOpen(c)}
                                className="hover:bg-muted/40 flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left"
                            >
                                {body}
                            </button>
                        ) : (
                            <div className="flex min-h-14 items-center gap-3 px-3 py-2">{body}</div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/** Where the job's up to, and changing it: Done, back to booked, Cancelled (and what they owe). */
function StatusSheet({
    job,
    stage,
    open,
    onClose,
    onDone,
    onCancel,
}: {
    job: Props['job'];
    stage: JobStageInfo | null;
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    onCancel: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const post = (url: string, body: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, body, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                onClose();
            },
        });
    };

    return (
        <ResponsiveSheet open={open} onOpenChange={(o) => !o && onClose()} title="Where it’s up to" description={stage?.chip}>
            <SheetBody className="space-y-2">
                {job.status === 'booked' && <p className="text-muted-foreground text-sm">Marking it done gets its invoice ready to check.</p>}
                {job.status === 'done' && (
                    <p className="text-muted-foreground text-sm">Back to booked takes away the draft Done made, unless you’ve changed it.</p>
                )}
                {job.status === 'cancelled' && <p className="text-muted-foreground text-sm">They owe you: {cancelWords(job).toLowerCase()}.</p>}
            </SheetBody>
            <SheetFooter className="flex flex-col gap-2">
                {job.status === 'booked' && (
                    <Button className="h-11" disabled={busy} onClick={onDone}>
                        Done
                    </Button>
                )}
                {job.status !== 'booked' && (
                    <Button
                        variant="outline"
                        className="h-11"
                        disabled={busy}
                        onClick={() => (job.status === 'cancelled' ? post(`/jobs/${job.id}/uncancel`) : post(`/jobs/${job.id}/reopen`))}
                    >
                        {job.status === 'cancelled' ? 'It’s not cancelled after all' : 'Back to booked'}
                    </Button>
                )}
                <Button variant="outline" className="h-11" disabled={busy} onClick={onCancel}>
                    {job.status === 'cancelled' ? 'Change what they owe…' : 'Cancelled…'}
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
