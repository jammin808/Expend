import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { ClientMergeSheet } from '@/components/client-merge-sheet';
import { ClientSelect } from '@/components/client-select';
import { CostChip, CostSheet, costAmount } from '@/components/cost-sheet';
import { DoneButton } from '@/components/done-prompt';
import { ItemDrawer } from '@/components/item-drawer';
import { StageChip } from '@/components/job-stage';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { TermsEditor } from '@/components/terms-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { day, londonToday, money } from '@/lib/format';
import { shortMoney } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type ClientRef, type Lookups } from '@/types';
import { type ByCurrency, type ClientJobRow, type ClientShowProps, type CostChipKey, type CostRow, type InvoiceRoute } from '@/types/clients';
import { type InvoiceRow } from '@/types/invoices';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Inbox, Lock } from 'lucide-react';
import { type ReactNode, useEffect, useId, useMemo, useRef, useState } from 'react';

type Props = ClientShowProps & { lookups?: Lookups };

const SECTIONS = [
    ['owed', 'Owed'],
    ['jobs', 'Jobs'],
    ['money', 'Money'],
    ['costs', 'Costs'],
    ['travel', 'Travel'],
    ['details', 'Details'],
] as const;

/** How their invoices go, in words. */
const ROUTE_WORDS: Record<InvoiceRoute, { label: string; help: string }> = {
    thread: { label: 'In the booking conversation', help: 'A reply in the Gmail conversation the job was booked in.' },
    new: { label: 'As a new email to their accounts address', help: 'Its own email, to the billing email above.' },
    none: { label: 'They pay without an invoice from me', help: 'Self-billing or timesheets: no draft is made when a job is done.' },
};

/** The UK tax year today is in, like "2026-27". */
function currentTaxYear(): string {
    const today = londonToday();
    const y = Number(today.slice(0, 4));
    const start = today >= `${y}-04-06` ? y : y - 1;
    return `${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

/** Amounts by currency, pounds first: "£450 + €120". Empty when there are none. */
function amounts(m: ByCurrency, short = true): string {
    const entries = Object.entries(m).filter(([, v]) => v !== 0);
    entries.sort(([a], [b]) => (a === 'GBP' ? -1 : b === 'GBP' ? 1 : a.localeCompare(b)));
    return entries.map(([cur, v]) => (short ? shortMoney(v, cur) : money(v, cur))).join(' + ');
}

/** What's still to come on their open invoices, by currency. */
function owedBy(invoices: InvoiceRow[]): Record<string, number> {
    const out: Record<string, number> = {};
    for (const i of invoices) out[i.currency] = (out[i.currency] ?? 0) + i.outstanding_minor;
    return out;
}

function jobDates(j: { starts_on: string; ends_on: string }): string {
    return j.starts_on === j.ends_on ? day(j.starts_on) : `${day(j.starts_on, false)} – ${day(j.ends_on)}`;
}

function Section({ id, title, action, children }: { id: string; title: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section id={id} aria-labelledby={`${id}-title`} className="scroll-mt-16 space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id={`${id}-title`} className="text-lg font-semibold">
                    {title}
                </h2>
                {action}
            </div>
            {children}
        </section>
    );
}

/**
 * One client, on one scrolling page: a card that answers "what do they owe, and what are they worth this year", then
 * Owed, Jobs, Money (by tax year), Costs (each with its choices), Travel and expenses (their usual arrangement) and
 * Details (how invoices go, merged names, merging).
 */
export default function ClientShow(props: Props) {
    const { client, owed, figures, costs, terms } = props;
    const [costFilter, setCostFilter] = useState<CostChipKey | 'all'>('all');
    const [cost, setCost] = useState<CostRow | null>(null);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const [mergeWith, setMergeWith] = useState<ClientRef | null>(null);
    const [wantsTerms, setWantsTerms] = useState(false);
    useEffect(() => {
        setWantsTerms(window.location.hash === '#terms');
    }, []);

    const owes = owedBy(owed.invoices);
    const overdue = owed.invoices.filter((i) => i.overdue).length;
    const thisYear = figures.tax_year === currentTaxYear() ? 'This tax year' : figures.tax_year;
    const chargedBack = amounts(figures.charged_back);
    const seeCosts = (chip: CostChipKey) => {
        setCostFilter(chip);
        document.getElementById('costs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    // A cost's payment opens in the side panel, which needs the decision pickers: asked for the first time it's opened.
    const openPayment = (target: { source: 'bank' | 'manual'; id: number }) => {
        if (props.lookups) return setDrawer(target);
        router.reload({ only: ['lookups'], onSuccess: () => setDrawer(target) });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: client.name, href: `/clients/${client.id}` },
            ]}
        >
            <Head title={client.name} />
            <div className="mx-auto w-full max-w-4xl space-y-6 px-4 pt-4 pb-6 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight break-words">{client.name}</h1>
                    <p className="text-muted-foreground text-sm break-words">
                        {client.invoice_route === 'none'
                            ? 'They pay without an invoice from you.'
                            : `Invoices go to ${client.invoice_name ?? client.name}${client.billing_email ? ` · ${client.billing_email}` : ''}`}
                    </p>
                    {client.payers.slice(0, 3).map((p) => (
                        <p key={p.key} className="text-muted-foreground text-sm break-words">
                            {p.text}
                        </p>
                    ))}
                    {client.work_for && <p className="text-muted-foreground text-sm break-words">{client.work_for}</p>}
                    {client.booked_through && <p className="text-muted-foreground text-sm break-words">{client.booked_through}</p>}
                </header>

                <div className="bg-muted/40 space-y-1 rounded-xl border p-4">
                    <p className={cn('font-semibold', overdue > 0 && 'text-rose-700 dark:text-rose-400')}>
                        {Object.keys(owes).length > 0
                            ? `Owes you ${amounts(owes, false)}${overdue > 0 ? ` · ${overdue} overdue` : ''}`
                            : 'Owes you nothing right now'}
                    </p>
                    <p className="text-sm">
                        {thisYear}: made {shortMoney(figures.made_pence)} · spent {shortMoney(figures.spent_pence)}
                        {chargedBack ? ` (${chargedBack} charged back)` : ''} ·{' '}
                        {figures.profit_pence < 0 ? `a loss of ${shortMoney(figures.profit_pence)}` : `profit ${shortMoney(figures.profit_pence)}`}
                    </p>
                    {figures.waiting_pence > 0 && (
                        <Link
                            href="/inbox"
                            className="inline-flex min-h-10 items-center gap-1.5 text-sm text-sky-700 underline-offset-2 hover:underline dark:text-sky-400"
                        >
                            <Inbox className="size-4" aria-hidden /> {shortMoney(figures.waiting_pence)} more waiting in the Inbox
                        </Link>
                    )}
                </div>

                <nav
                    aria-label="On this page"
                    className="bg-background/95 supports-[backdrop-filter]:bg-background/85 sticky top-0 z-10 -mx-4 flex gap-2 overflow-x-auto border-b px-4 py-2 backdrop-blur md:-mx-6 md:px-6"
                >
                    {SECTIONS.map(([id, label]) => (
                        <a key={id} href={`#${id}`} className="hover:bg-muted flex min-h-10 shrink-0 items-center rounded-full border px-3 text-sm">
                            {label}
                        </a>
                    ))}
                </nav>

                <Owed {...props} onSeeCosts={seeCosts} />
                <Jobs {...props} />
                <Money {...props} />

                <Section
                    id="costs"
                    title="Costs"
                    action={
                        owed.costs_to_charge.count > 0 && client.invoice_route !== 'none' ? (
                            <Button variant="outline" className="h-11" onClick={() => router.post(`/clients/${client.id}/invoice`)}>
                                Draft an invoice for them
                            </Button>
                        ) : undefined
                    }
                >
                    <Costs costs={costs} chips={props.chips} filter={costFilter} onFilter={setCostFilter} onOpen={setCost} />
                </Section>

                <Section id="travel" title="Travel and expenses">
                    <div id="terms" className="scroll-mt-16" />
                    <TermsEditor key={String(wantsTerms)} sheet={terms} url={`/clients/${client.id}/terms`} owner="client" startOpen={wantsTerms} />
                </Section>

                <Details {...props} onMergeWith={setMergeWith} />
            </div>

            <CostSheet cost={cost} clientName={client.name} clientId={client.id} onClose={() => setCost(null)} onOpenPayment={openPayment} />
            {props.lookups && (
                <ItemDrawer
                    target={drawer}
                    lookups={props.lookups}
                    onClose={() => {
                        setDrawer(null);
                        router.reload({ only: ['costs', 'chips', 'figures', 'owed'] });
                    }}
                />
            )}
            {mergeWith && (
                <ClientMergeSheet a={{ id: client.id, name: client.name }} b={mergeWith} keepFirst="b" open onClose={() => setMergeWith(null)} />
            )}
        </AppLayout>
    );
}

/* ---------------------------------------------------------------- Owed */

function Owed({ client, owed, jobs, onSeeCosts }: Props & { onSeeCosts: (chip: CostChipKey) => void }) {
    const titles = new Map<number, string>([...jobs.paying, ...owed.jobs].map((j) => [j.id, j.title]));
    // A job still to invoice that has its draft shows once, with "Check the draft".
    const listed = new Set(owed.jobs.map((j) => j.id));
    const drafts = owed.drafts.filter((d) => d.work_job_id === null || !listed.has(d.work_job_id));
    const nothing =
        owed.invoices.length === 0 &&
        owed.drafts.length === 0 &&
        owed.jobs.length === 0 &&
        owed.costs_to_charge.count === 0 &&
        owed.to_claim.count === 0;

    return (
        <Section id="owed" title="Owed">
            {nothing ? (
                <p className="text-muted-foreground text-sm">
                    Nothing owed.
                    {owed.last_paid?.number
                        ? ` ${owed.last_paid.number}${owed.last_paid.issue_date ? ` (${day(owed.last_paid.issue_date)})` : ''} is paid.`
                        : ''}
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {owed.invoices.map((i) => (
                        <li key={i.id}>
                            <Link href={`/invoices/${i.id}`} className="hover:bg-muted/40 flex min-h-14 items-center gap-3 px-3 py-2">
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{i.number}</span>
                                        <StatusPill status={i.overdue && i.state === 'unpaid' ? 'overdue' : i.state} />
                                    </span>
                                    <span className="text-muted-foreground block text-xs break-words">
                                        {[
                                            i.job?.title,
                                            i.status === 'issued' && !i.sent
                                                ? 'Not sent yet'
                                                : i.overdue && i.overdue_days
                                                  ? `${i.overdue_days} ${i.overdue_days === 1 ? 'day' : 'days'} overdue`
                                                  : i.due_date
                                                    ? `Due ${day(i.due_date)}`
                                                    : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="shrink-0 text-right text-sm tabular-nums">
                                    {money(i.outstanding_minor, i.currency)}
                                    <span className="text-muted-foreground block text-xs">to come</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                    {drafts.map((d) => (
                        <li key={`d${d.id}`} className="flex min-h-14 flex-wrap items-center gap-3 px-3 py-2">
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium break-words">
                                    Draft
                                    {d.work_job_id && titles.get(d.work_job_id) ? ` for ${titles.get(d.work_job_id)}` : ` for ${client.name}’s costs`}
                                </span>
                                <span className="text-muted-foreground text-xs">Not issued yet · {money(d.total_minor, d.currency)}</span>
                            </span>
                            <Button asChild className="h-11">
                                <Link href={`/invoices/${d.id}`}>Check the draft</Link>
                            </Button>
                        </li>
                    ))}
                    {owed.jobs.map((j) => (
                        <li key={`j${j.id}`} className="flex min-h-14 flex-wrap items-center gap-3 px-3 py-2">
                            <Link href={`/jobs/${j.id}`} className="min-w-0 flex-1">
                                <span className="block font-medium break-words">{j.title}</span>
                                <span className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                    Not invoiced yet · {jobDates(j)}
                                    <StageChip stage={j.stage} />
                                </span>
                            </Link>
                            {j.stage?.done_due ? (
                                <DoneButton job={j} />
                            ) : j.stage?.draft_id ? (
                                <Button asChild variant="outline" className="h-11">
                                    <Link href={`/invoices/${j.stage.draft_id}`}>Check the draft</Link>
                                </Button>
                            ) : j.stage?.stage === 'done_no_draft' ? (
                                <Button variant="outline" className="h-11" onClick={() => router.post(`/jobs/${j.id}/draft`)}>
                                    Draft its invoice
                                </Button>
                            ) : (
                                <Button asChild variant="outline" className="h-11">
                                    <Link href={`/jobs/${j.id}`}>Open</Link>
                                </Button>
                            )}
                        </li>
                    ))}
                    {owed.costs_to_charge.count > 0 && (
                        <li className="flex min-h-14 flex-wrap items-center gap-3 px-3 py-2">
                            <span className="min-w-0 flex-1 text-sm">
                                Costs to charge:{' '}
                                {[
                                    owed.costs_to_charge.pence ? money(owed.costs_to_charge.pence) : null,
                                    amounts(owed.costs_to_charge.other, false) || null,
                                ]
                                    .filter(Boolean)
                                    .join(' + ')}{' '}
                                ({owed.costs_to_charge.count})
                            </span>
                            <Button variant="outline" className="h-11" onClick={() => onSeeCosts('to_charge')}>
                                See them
                            </Button>
                        </li>
                    )}
                    {owed.to_claim.count > 0 && (
                        <li className="flex min-h-14 flex-wrap items-center gap-3 px-3 py-2">
                            <span className="min-w-0 flex-1 text-sm">
                                To claim on {client.name}’s expense form: {money(owed.to_claim.pence)} ({owed.to_claim.count})
                            </span>
                            <Button variant="outline" className="h-11" onClick={() => onSeeCosts('to_claim')}>
                                See them
                            </Button>
                        </li>
                    )}
                </ul>
            )}
        </Section>
    );
}

/* ---------------------------------------------------------------- Jobs */

function JobList({ rows }: { rows: ClientJobRow[] }) {
    return (
        <ul className="divide-y rounded-xl border">
            {rows.map((j) => (
                <li key={j.id}>
                    <Link href={`/jobs/${j.id}`} className="hover:bg-muted/40 flex min-h-14 items-center gap-3 px-3 py-2">
                        <span className="min-w-0 flex-1">
                            <span className="block font-medium break-words">{j.title}</span>
                            <span className="text-muted-foreground block text-xs break-words">
                                {[jobDates(j), j.end_client ? `for ${j.end_client}` : null].filter(Boolean).join(' · ')}
                            </span>
                        </span>
                        <StageChip stage={j.stage} className="shrink-0" />
                        <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function Jobs({ client, jobs }: Props) {
    const [tab, setTab] = useState<'paying' | 'end'>(jobs.paying_count === 0 && jobs.end_client_count > 0 ? 'end' : 'paying');
    const rows = tab === 'paying' ? jobs.paying : jobs.end_client;
    const total = tab === 'paying' ? jobs.paying_count : jobs.end_client_count;

    return (
        <Section id="jobs" title="Jobs">
            {jobs.end_client_count > 0 && (
                <div className="flex flex-wrap gap-2" role="tablist" aria-label="Which jobs">
                    {(
                        [
                            ['paying', `They pay (${jobs.paying_count})`],
                            ['end', `They’re the end client (${jobs.end_client_count})`],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={cn(
                                'min-h-11 rounded-full border px-4 text-sm',
                                tab === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}
            {rows.length === 0 ? <p className="text-muted-foreground text-sm">No jobs yet.</p> : <JobList rows={rows} />}
            {total > rows.length && (
                <Link
                    href={`/jobs?client=${client.id}`}
                    className="inline-flex min-h-11 items-center text-sm font-medium underline underline-offset-2"
                >
                    All {total}
                </Link>
            )}
        </Section>
    );
}

/* ---------------------------------------------------------------- Money */

function Row({ label, value, strong = false, note }: { label: string; value: string; strong?: boolean; note?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3 py-2">
            <dt className={cn('min-w-0 text-sm', strong && 'font-semibold')}>
                {label}
                {note && <span className="text-muted-foreground block text-xs">{note}</span>}
            </dt>
            <dd className={cn('shrink-0 text-right text-sm tabular-nums', strong && 'font-semibold')}>{value}</dd>
        </div>
    );
}

function Money({ client, figures, years }: Props) {
    const invoiced = amounts(figures.invoiced, false);
    const outstanding = amounts(figures.outstanding, false);
    const closed = amounts(figures.closed, false);
    const still = [figures.still_to_charge.pence ? money(figures.still_to_charge.pence) : null, amounts(figures.still_to_charge.other, false) || null]
        .filter(Boolean)
        .join(' + ');
    const ends = figures.by_end_client;

    return (
        <Section
            id="money"
            title="Money"
            action={
                <NativeSelect
                    aria-label="Tax year"
                    className="h-11 w-32"
                    value={figures.tax_year}
                    onChange={(e) =>
                        router.get(
                            `/clients/${client.id}`,
                            { year: e.target.value },
                            { preserveScroll: true, preserveState: true, only: ['figures'] },
                        )
                    }
                >
                    {years.map((y) => (
                        <option key={y} value={y}>
                            {y}
                        </option>
                    ))}
                </NativeSelect>
            }
        >
            {figures.locked && (
                <p className="flex items-center gap-2 text-sm">
                    <Lock className="size-4 shrink-0" aria-hidden /> {figures.tax_year} is locked: these are the figures as filed.
                </p>
            )}
            <dl className="divide-y rounded-xl border px-3">
                <Row label="Made from them" value={money(figures.made_pence)} />
                <Row label="Spent for them" value={money(figures.spent_pence)} />
                {figures.fees_taken_pence > 0 && <Row label="Fees they took off" value={money(figures.fees_taken_pence)} />}
                <Row label="Mileage" value={money(figures.mileage_pence)} />
                <Row label="Profit" value={money(figures.profit_pence)} strong />
                {figures.disallowable_pence > 0 && (
                    <Row label="Also spent, not allowed for tax" value={money(figures.disallowable_pence)} note="Not taken off the profit above." />
                )}
            </dl>
            <dl className="divide-y rounded-xl border px-3">
                <Row
                    label="Charged back on invoices"
                    value={amounts(figures.charged_back, false) || money(0)}
                    note="Counted as income when it’s paid."
                />
                <Row label="Still to charge" value={still || money(0)} />
                <Row
                    label="Invoiced"
                    value={invoiced || money(0)}
                    note={
                        [outstanding ? `${outstanding} still to come` : null, closed ? `${closed} closed short` : null].filter(Boolean).join(' · ') ||
                        undefined
                    }
                />
            </dl>
            {ends.length > 1 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">By end client</h3>
                    <ul className="divide-y rounded-xl border">
                        {ends.map((e) => (
                            <li key={e.key} className="space-y-0.5 px-3 py-2 text-sm">
                                <span className="flex items-baseline justify-between gap-3">
                                    <span className="min-w-0 font-medium break-words">{e.name}</span>
                                    <span className="shrink-0 tabular-nums">profit {money(e.profit_pence)}</span>
                                </span>
                                <span className="text-muted-foreground block text-xs">
                                    made {money(e.made_pence)} · spent {money(e.spent_pence)}
                                    {e.mileage_pence ? ` · mileage ${money(e.mileage_pence)}` : ''}
                                    {e.jobs ? ` · ${e.jobs} ${e.jobs === 1 ? 'job' : 'jobs'}` : ''}
                                </span>
                                {e.note && <span className="text-muted-foreground block text-xs">{e.note}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <p className="text-muted-foreground text-xs">{figures.footnote}</p>
            {figures.cash_basis_note && <p className="text-muted-foreground text-xs">{figures.cash_basis_note}</p>}
        </Section>
    );
}

/* ---------------------------------------------------------------- Costs */

function Costs({
    costs,
    chips,
    filter,
    onFilter,
    onOpen,
}: {
    costs: CostRow[];
    chips: Props['chips'];
    filter: CostChipKey | 'all';
    onFilter: (f: CostChipKey | 'all') => void;
    onOpen: (c: CostRow) => void;
}) {
    const shown = filter === 'all' ? costs : costs.filter((c) => c.chip === filter);
    if (costs.length === 0)
        return <p className="text-muted-foreground text-sm">No costs for them yet: costs on their jobs, and ones you set as theirs, show here.</p>;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Show costs">
                {[{ key: 'all' as const, label: 'All', count: costs.length }, ...chips.filter((c) => c.count > 0)].map((c) => (
                    <button
                        key={c.key}
                        type="button"
                        role="radio"
                        aria-checked={filter === c.key}
                        onClick={() => onFilter(c.key)}
                        className={cn(
                            'min-h-11 rounded-full border px-3 text-sm',
                            filter === c.key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                        )}
                    >
                        {c.label} ({c.count})
                    </button>
                ))}
            </div>
            <ul className="divide-y rounded-xl border">
                {shown.map((c) => (
                    <li key={c.ref}>
                        <button
                            type="button"
                            onClick={() => onOpen(c)}
                            className="hover:bg-muted/40 flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium break-words">{c.description}</span>
                                <span className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                    {/* The cost's own words may carry its date already ("Train: Trainline, 11 Apr 2024"). */}
                                    {c.date && !c.description.includes(day(c.date)) ? day(c.date) : null}
                                    <CostChip chip={c.chip} />
                                    {c.note && <span className="break-words">{c.note}</span>}
                                </span>
                            </span>
                            <span className="shrink-0 text-sm tabular-nums">{costAmount(c)}</span>
                        </button>
                    </li>
                ))}
                {shown.length === 0 && <li className="text-muted-foreground p-4 text-center text-sm">None here.</li>}
            </ul>
        </div>
    );
}

/* ---------------------------------------------------------------- Details */

const TERMS = [14, 30, 60];

function Details({ client, invoice_routes, lookups, onMergeWith }: Props & { onMergeWith: (c: ClientRef) => void }) {
    const id = useId();
    const form = useForm({
        name: client.name,
        invoice_name: client.invoice_name ?? '',
        billing_email: client.billing_email ?? '',
        billing_address: client.billing_address ?? '',
        payment_terms_days: client.payment_terms_days === null ? '' : String(client.payment_terms_days),
        invoice_route: client.invoice_route,
        notes: client.notes ?? '',
    });
    const [other, setOther] = useState(client.payment_terms_days !== null && !TERMS.includes(client.payment_terms_days));
    const [unmerging, setUnmerging] = useState<{ id: number; name: string } | null>(null);
    // Unmerging: both buttons wait for the answer, so a double tap sends it once.
    const [unmergeBusy, setUnmergeBusy] = useState(false);
    const unmergeSent = useRef(false);
    const unmerge = () => {
        if (!unmerging || unmergeSent.current) return;
        unmergeSent.current = true;
        router.post(
            `/clients/${unmerging.id}/unmerge`,
            {},
            {
                preserveScroll: true,
                onStart: () => setUnmergeBusy(true),
                // Answered (done, or refused in words): the sheet closes. No answer: it stays, to try again.
                onSuccess: () => setUnmerging(null),
                onFinish: () => {
                    unmergeSent.current = false;
                    setUnmergeBusy(false);
                },
            },
        );
    };
    const [picking, setPicking] = useState(false);
    const days = Number(form.data.payment_terms_days);
    const pickable = useMemo(() => (lookups?.clients ?? []).filter((c) => c.id !== client.id), [lookups, client.id]);
    const field = (name: string) => `${id}-${name}`;
    const error = (name: keyof typeof form.data) =>
        form.errors[name] ? (
            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                {form.errors[name]}
            </p>
        ) : null;

    return (
        <Section id="details" title="Details">
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(`/clients/${client.id}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
                }}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor={field('name')}>Name</Label>
                        <Input
                            id={field('name')}
                            className="h-11"
                            maxLength={120}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                        {error('name')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={field('invoice_name')}>Name on invoices</Label>
                        <Input
                            id={field('invoice_name')}
                            className="h-11"
                            maxLength={200}
                            placeholder={client.name}
                            value={form.data.invoice_name}
                            onChange={(e) => form.setData('invoice_name', e.target.value)}
                        />
                        {error('invoice_name')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={field('billing_email')}>Billing email</Label>
                        <Input
                            id={field('billing_email')}
                            type="email"
                            inputMode="email"
                            className="h-11"
                            placeholder="accounts@client.co.uk"
                            value={form.data.billing_email}
                            onChange={(e) => form.setData('billing_email', e.target.value)}
                        />
                        {error('billing_email')}
                    </div>
                    <div className="space-y-1.5 sm:col-span-2">
                        <Label htmlFor={field('billing_address')}>Address on invoices</Label>
                        <Textarea
                            id={field('billing_address')}
                            rows={3}
                            value={form.data.billing_address}
                            onChange={(e) => form.setData('billing_address', e.target.value)}
                        />
                        {error('billing_address')}
                    </div>
                </div>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Payment terms</legend>
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Payment terms">
                        {[{ key: '', label: 'Your usual' }, ...TERMS.map((t) => ({ key: String(t), label: `${t} days` }))].map((t) => (
                            <button
                                key={t.key || 'usual'}
                                type="button"
                                role="radio"
                                aria-checked={!other && form.data.payment_terms_days === t.key}
                                onClick={() => {
                                    setOther(false);
                                    form.setData('payment_terms_days', t.key);
                                }}
                                className={cn(
                                    'min-h-11 rounded-full border px-4 text-sm',
                                    !other && form.data.payment_terms_days === t.key
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-muted',
                                )}
                            >
                                {t.label}
                            </button>
                        ))}
                        <button
                            type="button"
                            role="radio"
                            aria-checked={other}
                            onClick={() => setOther(true)}
                            className={cn(
                                'min-h-11 rounded-full border px-4 text-sm',
                                other ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                            )}
                        >
                            Other
                        </button>
                    </div>
                    {other && (
                        <div className="flex items-center gap-2">
                            <Input
                                aria-label="Days to pay"
                                inputMode="numeric"
                                className="h-11 w-24"
                                value={form.data.payment_terms_days}
                                onChange={(e) => form.setData('payment_terms_days', e.target.value.replace(/\D/g, '').slice(0, 3))}
                            />
                            <span className="text-sm">days</span>
                        </div>
                    )}
                    {days > 60 && <p className="text-sm text-amber-800 dark:text-amber-300">Over 60 days: check it’s in your agreement.</p>}
                    {error('payment_terms_days')}
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Send invoices</legend>
                    <div className="grid gap-2" role="radiogroup" aria-label="Send invoices">
                        {invoice_routes.map((r) => (
                            <button
                                key={r}
                                type="button"
                                role="radio"
                                aria-checked={form.data.invoice_route === r}
                                onClick={() => form.setData('invoice_route', r)}
                                className={cn(
                                    'min-h-11 rounded-lg border px-3 py-2 text-left text-sm',
                                    form.data.invoice_route === r ? 'border-primary ring-primary ring-1' : 'hover:bg-muted/50',
                                )}
                            >
                                <span className="block font-medium">{ROUTE_WORDS[r]?.label ?? r}</span>
                                <span className="text-muted-foreground block text-xs">{ROUTE_WORDS[r]?.help}</span>
                            </button>
                        ))}
                    </div>
                    {error('invoice_route')}
                </fieldset>

                <div className="space-y-1.5">
                    <Label htmlFor={field('notes')}>Notes</Label>
                    <Textarea id={field('notes')} rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    {error('notes')}
                </div>
                <Button className="h-11" disabled={form.processing || !form.isDirty}>
                    Save details
                </Button>
            </form>

            <div className="space-y-2 border-t pt-4">
                <h3 className="text-sm font-medium">The same client under another name</h3>
                {client.merged.length > 0 ? (
                    <ul className="divide-y rounded-xl border">
                        {client.merged.map((m) => (
                            <li key={m.id} className="flex min-h-14 flex-wrap items-center gap-3 px-3 py-2">
                                <span className="min-w-0 flex-1 text-sm break-words">
                                    {m.name} is merged into {client.name}
                                </span>
                                <Button variant="outline" className="h-11" onClick={() => setUnmerging(m)}>
                                    Unmerge
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">None merged into {client.name}.</p>
                )}
                {client.locked_years.length > 0 && (
                    <p className="text-muted-foreground flex items-center gap-2 text-xs">
                        <Lock className="size-3.5 shrink-0" aria-hidden /> Records in {client.locked_years.join(', ')} are locked: merging{' '}
                        {client.name} away needs unlocking first.
                    </p>
                )}
                {picking ? (
                    lookups ? (
                        <ClientSelect
                            clients={pickable}
                            value={null}
                            placeholder="Pick the client to merge into"
                            onChange={(c) => {
                                setPicking(false);
                                if (c && c.id !== client.id) onMergeWith(c);
                            }}
                        />
                    ) : (
                        <p className="text-muted-foreground text-sm">Getting your clients…</p>
                    )
                ) : (
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() => {
                            setPicking(true);
                            if (!lookups) router.reload({ only: ['lookups'] });
                        }}
                    >
                        Merge into another client
                    </Button>
                )}
            </div>

            <ResponsiveSheet
                open={unmerging !== null}
                onOpenChange={(o) => !o && !unmergeBusy && setUnmerging(null)}
                title={unmerging ? `Unmerge ${unmerging.name}?` : 'Unmerge'}
            >
                <SheetBody>
                    <p className="text-sm">
                        {unmerging?.name}’s jobs, invoices and payments count as its own again. Nothing in your records is rewritten.
                    </p>
                </SheetBody>
                <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                    <Button className="h-11" disabled={unmergeBusy} onClick={unmerge}>
                        {unmergeBusy ? 'Unmerging…' : 'Unmerge'}
                    </Button>
                    <Button variant="outline" className="h-11" disabled={unmergeBusy} onClick={() => setUnmerging(null)}>
                        Keep them merged
                    </Button>
                </SheetFooter>
            </ResponsiveSheet>
        </Section>
    );
}
