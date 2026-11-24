import { NativeSelect } from '@/components/native-select';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

export interface PenaltyItem {
    code: string;
    label: string;
    date: string;
    amount_pence: number;
    /** at_risk: held off by a payment plan that was broken; HMRC can charge it by notice (not in the totals). */
    status: 'due' | 'accruing' | 'upcoming' | 'at_risk';
    why: string;
    source: string;
    /** Rests on an assumption (stated in `assumption`) or on rules that are announced but not law. */
    estimate: boolean;
    assumption: string | null;
    /** Added when the return is filed (HMRC charge £300 first while it's missing). */
    on_filing_pence: number;
    /** For a penalty HMRC set within a range (failure to notify). */
    range: { min_pence: number; max_pence: number } | null;
    /** Set when the penalty only stands if HMRC charged it in time. */
    time_limit: string | null;
    date_a: string | null;
    /** When HMRC are taken to charge it, if not on its date; interest runs from 30 days after. */
    charged_on: string | null;
    /** A late submission point: the last day of its 24 months (it stays longer while you're at the threshold). */
    expires_on?: string;
    /** A late payment penalty that can't be charged until the return is in (or HMRC assess the tax): no interest yet. */
    awaits_return?: boolean;
    /** The tax it's a percentage of isn't worked out (Scottish income tax, or no rates loaded): amount_pence isn't the figure. */
    amount_unknown?: boolean;
}
/** A submission late submission points are counted on: the year's return, or a Making Tax Digital quarterly update. */
export interface PenaltySubmission {
    /** The row to record against; null until the year page has made it. */
    id: number | null;
    kind: 'annual' | 'quarterly';
    quarter: number | null;
    period_start: string;
    period_end: string;
    due_on: string;
    submitted_on: string | null;
    /** Sent (a return marked filed with no date is taken as on time). */
    made: boolean;
    excused: boolean;
    note: string | null;
    /** The return: its dates come from the year (filed date), so they aren't entered here. */
    derived: boolean;
    /** An accepted volunteer's quarterly update: HMRC give no points for these. */
    volunteer: boolean;
    status: 'on_time' | 'late' | 'not_sent' | 'not_due' | 'excused';
    /** What being late did, or would do if nothing changes. */
    effect: string | null;
    point: boolean;
    penalty_pence: number;
    /** Worked out under rules that are announced but not law yet. */
    announced: boolean;
    what: string;
}
/** Your late submission points as they stand today (counted across every year under the new rules). */
export interface PenaltyPoints {
    announced: boolean;
    group: '1A' | '1B';
    threshold: number;
    held: number;
    at_threshold: boolean;
    points: { what: string; failure_on: string; expires_on: string; stays: boolean; goes_on: string | null; added: boolean }[];
    /** When they'd all go if everything is sent on time from now (and anything listed in reset_needs is sent now). */
    reset_on: string | null;
    reset_needs: string[];
    penalties: { on: string; what: string; pence: number; projected: boolean }[];
    penalties_pence: number;
    next: { what: string; due_on: string; if_late: string } | null;
    text: string;
    estimate: boolean;
    assumptions: string[];
}
export interface PenaltyPlan {
    requested_on: string;
    /** The day the plan counts from: the request, or the filing day if the return was overdue when you asked. */
    counts_from: string | null;
    agreed: boolean | null;
    ends_on: string | null;
    broken_on: string | null;
    /** HMRC's notice charging what a broken plan held off. */
    notice_on: string | null;
    amount_pence: number | null;
    state: 'protected' | 'pending' | 'at_risk' | 'charged' | null;
    what_if: boolean;
    protected_pence: number;
    pending_pence: number;
    /** Held off by a broken plan, arisen so far: HMRC can charge it now by notice. */
    at_risk_pence: number;
    /** Would be held off (and at risk) when it arises later. */
    at_risk_later_pence: number;
    /** Held off, then charged by HMRC's notice after the breach. */
    charged_pence: number;
    notes: string[];
}
export interface PenaltyPlanFacts {
    plan_requested_on: string | null;
    plan_agreed: boolean | null;
    plan_ends_on: string | null;
    plan_broken_on: string | null;
    plan_notice_on: string | null;
    plan_amount_pence: number | null;
}
export interface PenaltyAmendment {
    amended_on: string | null;
    original_liability_pence: number | null;
    increase_pence: number | null;
    increase_due: string | null;
    assumed_sent_today: boolean;
}
export interface PenaltyNotice {
    notice_to_file: 'given' | 'not_given' | null;
    notice_date: string | null;
    filed_method: 'online' | 'paper' | null;
    filing_date_override: string | null;
    deferred_to: string | null;
    told_hmrc_by_5_october: boolean | null;
    assumed: boolean;
}
export interface PenaltyYear {
    tax_year: string;
    regime: 'sch55_56' | 'sch24_26_law' | 'sch24_26_announced';
    regime_text: string;
    new_rules: boolean;
    deadline: string | null;
    deadline_basis: 'law' | 'estimate' | 'notice' | 'deferred' | null;
    paper_deadline: string | null;
    balancing_due: string;
    /** law; assumed (late notice, not said whether you told HMRC by 5 October); estimate (told HMRC, no notice yet). */
    balancing_basis: 'law' | 'assumed' | 'estimate';
    notify_by: string;
    own_return_last_date: string;
    determination_last_date: string | null;
    filed: boolean;
    filed_on: string | null;
    return_needed: boolean;
    chargeable: boolean;
    notify_hmrc: boolean;
    too_late_to_file: boolean;
    notice: PenaltyNotice;
    status: string;
    liability_pence: number;
    basis: string;
    estimable: boolean;
    /** Why the tax isn't worked out (so it's unknown, not nil). */
    unestimable: 'scottish' | 'no_rates' | null;
    /** No notice to file yet, but you told HMRC by 5 October. */
    told_no_notice: boolean;
    plan_facts: PenaltyPlanFacts;
    plan: PenaltyPlan | null;
    amendment: PenaltyAmendment | null;
    determination_on: string | null;
    obligations: {
        kind: string;
        label: string;
        due: string;
        /** Interest starts here: the due date, or HMRC's later date for tax due under s59B(3) (an estimate). */
        interest_from: string;
        amount_pence: number;
        paid_pence: number;
        outstanding_pence: number;
        interest_pence: number;
        /** Each run of days at one balance, rate and year length, rounded down to the penny. */
        interest_segments: InterestSegment[];
    }[];
    penalties: PenaltyItem[];
    upcoming: PenaltyItem[];
    interest_pence: number;
    per_day_pence: number;
    penalties_pence: number;
    upcoming_pence: number;
    on_filing_pence: number;
    at_risk: PenaltyItem[];
    at_risk_pence: number;
    unpaid_pence: number;
    announced: {
        regime_text: string;
        penalties: PenaltyItem[];
        upcoming: PenaltyItem[];
        at_risk: PenaltyItem[];
        penalties_pence: number;
        upcoming_pence: number;
        points: PenaltyPoints | null;
    } | null;
    submissions: PenaltySubmission[];
    /** Under the rules that are law for this year (for a year where they're only announced, see announced.points). */
    points: PenaltyPoints | null;
    actions: string[];
    rate_pct: string;
    assumptions: string[];
}

interface InterestSegment {
    from: string;
    to: string;
    days: number;
    balance_pence: number;
    pct: string;
    divisor: number;
    pence: number;
}

const pounds = (p: number) => money(p, 'GBP', { signed: false });
/** Pence as a plain amount for a form field (no £ or commas): 125050 → "1250.50". */
const plain = (p: number | null) => (p === null ? '' : (p / 100).toFixed(2));
const saveError = (errors: Record<string, string>) => toast(Object.values(errors)[0] ?? 'Not saved.', 'error');

function Items({ items, showSource = true }: { items: PenaltyItem[]; showSource?: boolean }) {
    return (
        <ul className="divide-y text-sm">
            {items.map((x) => (
                <li key={x.code} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                    <span className="text-muted-foreground w-24 shrink-0 text-xs">{day(x.date)}</span>
                    <span className="min-w-0 flex-1">
                        {x.label}
                        {x.status === 'accruing' && <span className="ml-2 text-xs text-rose-700 dark:text-rose-300">still growing</span>}
                        {x.estimate && (
                            <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                estimate
                            </span>
                        )}
                        <span className="text-muted-foreground block text-xs">
                            {x.why}
                            {showSource && (
                                <>
                                    {' '}
                                    <a href={x.source} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                        the rule
                                    </a>
                                </>
                            )}
                        </span>
                        {x.assumption && <span className="block text-xs text-amber-800 dark:text-amber-300">{x.assumption}</span>}
                        {x.time_limit && <span className="block text-xs text-amber-800 dark:text-amber-300">{x.time_limit}</span>}
                    </span>
                    <span className="text-right tabular-nums">
                        {x.amount_unknown
                            ? 'not worked out'
                            : x.range
                              ? `${pounds(x.range.min_pence)} to ${pounds(x.range.max_pence)}`
                              : pounds(x.amount_pence)}
                        {x.awaits_return && <span className="text-muted-foreground block text-xs">charged once the return is in</span>}
                        {x.on_filing_pence > 0 && (
                            <span className="text-muted-foreground block text-xs">+{pounds(x.on_filing_pence)} when filed</span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** What HMRC's letters say. They decide the filing date and which penalties can apply; empty means "don't know". */
function NoticeFacts({ p }: { p: PenaltyYear }) {
    const startYear = Number(p.tax_year.slice(0, 4));
    const [f, setF] = useState({
        notice_to_file: p.notice.notice_to_file ?? '',
        notice_date: p.notice.notice_date ?? '',
        filed_method: p.notice.filed_method ?? '',
        filing_date_override: p.notice.filing_date_override ?? '',
        deferred_to: p.notice.deferred_to ?? '',
        told_hmrc_by_5_october: p.notice.told_hmrc_by_5_october === null ? '' : p.notice.told_hmrc_by_5_october ? '1' : '0',
        determination_on: p.determination_on ?? '',
    });
    const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
    // Telling HMRC by 5 October matters where there's no notice, or it came after then (TMA s7, and s59B(3) after 31 October).
    const lateNotice = f.notice_to_file !== 'not_given' && !!f.notice_date && f.notice_date > `${startYear + 1}-10-05`;
    const askTold = lateNotice || f.notice_to_file === 'not_given';
    const id = (k: string) => `${k}-${p.tax_year}`;

    return (
        <details className="text-sm" open={p.notice.assumed && (p.penalties.length > 0 || p.notify_hmrc)}>
            <summary className="cursor-pointer font-medium">Your notice to file and how you filed</summary>
            <form
                className="mt-2 grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.patch(
                        `/years/${p.tax_year}`,
                        {
                            notice_to_file: f.notice_to_file || null,
                            notice_date: f.notice_to_file === 'not_given' ? null : f.notice_date || null,
                            filed_method: f.filed_method || null,
                            filing_date_override: f.filing_date_override || null,
                            deferred_to: f.deferred_to || null,
                            told_hmrc_by_5_october: askTold && f.told_hmrc_by_5_october !== '' ? f.told_hmrc_by_5_october === '1' : null,
                            determination_on: f.notice_to_file === 'not_given' ? null : f.determination_on || null,
                        },
                        { preserveScroll: true, onError: saveError },
                    );
                }}
            >
                <label className="space-y-1" htmlFor={id('notice')}>
                    <span className="text-muted-foreground text-xs">Did HMRC send a notice to file a return for {p.tax_year}?</span>
                    <NativeSelect id={id('notice')} value={f.notice_to_file} onChange={(e) => set('notice_to_file', e.target.value)}>
                        <option value="">Don’t know (assume they did, by 31 July {startYear + 1})</option>
                        <option value="given">Yes, HMRC sent one (letter, email or online)</option>
                        <option value="not_given">No, they haven’t</option>
                    </NativeSelect>
                </label>
                {f.notice_to_file !== 'not_given' && (
                    <label className="space-y-1" htmlFor={id('notice_date')}>
                        <span className="text-muted-foreground text-xs">Date of the notice (if you know it)</span>
                        <Input
                            id={id('notice_date')}
                            type="date"
                            className="h-9"
                            value={f.notice_date}
                            onChange={(e) => set('notice_date', e.target.value)}
                        />
                    </label>
                )}
                {askTold && (
                    <label className="space-y-1" htmlFor={id('told')}>
                        <span className="text-muted-foreground text-xs">
                            Had you told HMRC (registered for Self Assessment) by 5 October {startYear + 1} that you needed to file?
                        </span>
                        <NativeSelect
                            id={id('told')}
                            value={f.told_hmrc_by_5_october}
                            onChange={(e) => set('told_hmrc_by_5_october', e.target.value)}
                        >
                            <option value="">Don’t know (assume not)</option>
                            <option value="1">Yes, by 5 October</option>
                            <option value="0">No, later</option>
                        </NativeSelect>
                    </label>
                )}
                {f.notice_to_file !== 'not_given' && (
                    <>
                        <label className="space-y-1" htmlFor={id('method')}>
                            <span className="text-muted-foreground text-xs">How the return was filed</span>
                            <NativeSelect id={id('method')} value={f.filed_method} onChange={(e) => set('filed_method', e.target.value)}>
                                <option value="">Not filed yet, or online (assumed)</option>
                                <option value="online">Online</option>
                                <option value="paper">On paper</option>
                            </NativeSelect>
                        </label>
                        <label className="space-y-1" htmlFor={id('override')}>
                            <span className="text-muted-foreground text-xs">Deadline printed on HMRC’s notice, if it’s different</span>
                            <Input
                                id={id('override')}
                                type="date"
                                className="h-9"
                                value={f.filing_date_override}
                                onChange={(e) => set('filing_date_override', e.target.value)}
                            />
                        </label>
                        <label className="space-y-1" htmlFor={id('deferred')}>
                            <span className="text-muted-foreground text-xs">HMRC deferred the deadline to</span>
                            <Input
                                id={id('deferred')}
                                type="date"
                                className="h-9"
                                value={f.deferred_to}
                                onChange={(e) => set('deferred_to', e.target.value)}
                            />
                        </label>
                        <label className="space-y-1" htmlFor={id('determination')}>
                            <span className="text-muted-foreground text-xs">
                                Date of HMRC’s determination, if they sent one (their own estimate of the tax because no return arrived)
                            </span>
                            <Input
                                id={id('determination')}
                                type="date"
                                className="h-9"
                                value={f.determination_on}
                                onChange={(e) => set('determination_on', e.target.value)}
                            />
                        </label>
                    </>
                )}
                <div className="flex items-end sm:col-span-2">
                    <Button size="sm" variant="outline">
                        Save
                    </Button>
                </div>
            </form>
        </details>
    );
}

/** Late submission points: how many, when each goes, when they'd all go, any £200, and the next submission. */
function Points({ pts }: { pts: PenaltyPoints }) {
    const due = pts.penalties.filter((f) => !f.projected);
    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            <h3 className="flex flex-wrap items-baseline gap-2 font-medium">
                Late submission points: {pts.held} of {pts.threshold}
                {pts.at_threshold && (
                    <span className="text-xs text-rose-700 dark:text-rose-300">at the threshold: £200 for each late submission</span>
                )}
                <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-normal text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    estimate
                </span>
            </h3>
            <p className="text-muted-foreground text-xs">{pts.text}</p>
            {pts.points.length > 0 && (
                <ul className="divide-y text-xs">
                    {pts.points.map((x, i) => (
                        <li key={`${x.what}-${i}`} className="flex flex-wrap items-baseline gap-x-3 py-1">
                            <span className="text-muted-foreground w-24 shrink-0">{day(x.failure_on)}</span>
                            <span className="min-w-0 flex-1">
                                {x.added ? 'Added on starting quarterly updates, with ' : 'For '}
                                {x.what}
                            </span>
                            <span className="text-right">
                                {x.stays
                                    ? `stays until all your points go${x.goes_on ? ` (${day(x.goes_on)} at the earliest)` : ''}`
                                    : `expires at the end of ${day(x.expires_on)}`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {due.length > 0 && <p className="text-xs">£200 penalties so far: {due.map((f) => `${day(f.on)} (${f.what})`).join('; ')}.</p>}
            {pts.next && (
                <p className="text-xs">
                    Next: {pts.next.what}, due {day(pts.next.due_on)}. Late, it costs {pts.next.if_late}.
                </p>
            )}
            <details className="text-muted-foreground text-xs">
                <summary className="cursor-pointer">What this rests on</summary>
                <ul className="mt-1 list-disc space-y-0.5 pl-5">
                    {pts.assumptions.map((a) => (
                        <li key={a}>{a}</li>
                    ))}
                </ul>
            </details>
        </div>
    );
}

const SUBMISSION_STATUS: Record<PenaltySubmission['status'], string> = {
    on_time: 'on time',
    late: 'sent late',
    not_sent: 'not sent',
    not_due: 'not due yet',
    excused: 'late, excused',
};

/** One submission: due date, the day it was sent (a quarterly update's is entered here), and any excuse HMRC accepted. */
function SubmissionRow({ p, s }: { p: PenaltyYear; s: PenaltySubmission }) {
    const [sent, setSent] = useState(s.submitted_on ?? '');
    const [excused, setExcused] = useState(s.excused);
    const [note, setNote] = useState(s.note ?? '');
    const id = `sub-${p.tax_year}-${s.kind}-${s.quarter ?? 0}`;
    const late = s.status === 'late' || s.status === 'not_sent' || s.status === 'excused';
    const changed = sent !== (s.submitted_on ?? '') || excused !== s.excused || note !== (s.note ?? '');

    return (
        <li className="space-y-1 py-2">
            <form
                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (s.id === null) return;
                    router.patch(
                        `/years/${p.tax_year}/submissions/${s.id}`,
                        { ...(s.derived ? {} : { submitted_on: sent || null }), excused, note: excused ? note || null : null },
                        { preserveScroll: true, onError: saveError },
                    );
                }}
            >
                <span className="min-w-0 flex-1 basis-48">
                    {s.kind === 'annual'
                        ? `Tax return ${p.tax_year}`
                        : `Quarterly update ${day(s.period_start, s.period_start.slice(0, 4) !== s.period_end.slice(0, 4))} to ${day(s.period_end)}`}
                    <span className="text-muted-foreground block text-xs">
                        Due {day(s.due_on)} · {SUBMISSION_STATUS[s.status]}
                    </span>
                </span>
                {s.derived ? (
                    <span className="text-muted-foreground text-xs">
                        {s.submitted_on ? `Filed ${day(s.submitted_on)}` : s.made ? 'Filed (date not recorded)' : 'Not filed'} · set with the year’s
                        status
                    </span>
                ) : (
                    <label className="flex items-center gap-2 text-xs" htmlFor={`${id}-sent`}>
                        <span className="text-muted-foreground">Sent on</span>
                        <Input
                            id={`${id}-sent`}
                            type="date"
                            className="h-8 w-40"
                            value={sent}
                            disabled={s.id === null}
                            onChange={(e) => setSent(e.target.value)}
                        />
                    </label>
                )}
                {(late || s.excused) && (
                    <label className="flex items-center gap-2 text-xs" htmlFor={`${id}-excused`}>
                        <Checkbox id={`${id}-excused`} checked={excused} disabled={s.id === null} onCheckedChange={(v) => setExcused(!!v)} />
                        HMRC accepted an excuse
                    </label>
                )}
                {excused && (
                    <Input
                        aria-label="What HMRC accepted"
                        placeholder="What HMRC accepted (optional)"
                        className="h-8 w-56"
                        value={note}
                        maxLength={500}
                        onChange={(e) => setNote(e.target.value)}
                    />
                )}
                {s.id !== null && changed && (
                    <Button size="sm" variant="outline" className="h-8">
                        Save
                    </Button>
                )}
            </form>
            {s.effect && (
                <p className={cn('text-xs', late && !excused ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground')}>{s.effect}</p>
            )}
        </li>
    );
}

/** The return and quarterly updates late submission points are counted on, with the day each was sent. */
function Submissions({ p }: { p: PenaltyYear }) {
    // Open while something is past its due date with no "sent on" date.
    const open = p.submissions.some((s) => s.status === 'not_sent');
    return (
        <details className="text-sm" open={open}>
            <summary className="cursor-pointer font-medium">
                Submissions for {p.tax_year}: {p.submissions.filter((s) => s.made).length} of {p.submissions.length} sent
            </summary>
            <ul className="mt-1 divide-y">
                {p.submissions.map((s) => (
                    <SubmissionRow key={`${s.kind}-${s.quarter ?? 0}-${s.submitted_on ?? ''}-${s.excused}`} p={p} s={s} />
                ))}
            </ul>
            {p.submissions.some((s) => s.kind === 'quarterly' && !s.volunteer) && (
                <p className="text-muted-foreground text-xs">
                    A quarterly update with no “sent on” date is taken as not sent. Each late one makes you liable to a point (at most one a month for
                    each kind of submission); HMRC give it by letter.
                </p>
            )}
        </details>
    );
}

const PLAN_STATE: Record<NonNullable<PenaltyPlan['state']>, string> = {
    protected: 'Holding off late payment penalties while you keep to it',
    pending: 'Not agreed yet: penalties are shown in full',
    at_risk: 'Broken: HMRC can charge what it holds off',
    charged: 'Broken: HMRC charged what it held off',
};

/**
 * A payment plan (Time to Pay). What protects you is the day you asked HMRC, if they then agree it and you keep to
 * it; it covers only the amount in the plan, and only penalties arising before it ends.
 */
function PaymentPlan({ p }: { p: PenaltyYear }) {
    const facts = p.plan_facts;
    const [f, setF] = useState({
        plan_requested_on: facts.plan_requested_on ?? '',
        plan_agreed: facts.plan_agreed === null ? '' : facts.plan_agreed ? '1' : '0',
        plan_ends_on: facts.plan_ends_on ?? '',
        plan_broken_on: facts.plan_broken_on ?? '',
        plan_notice_on: facts.plan_notice_on ?? '',
        plan_amount: plain(facts.plan_amount_pence),
    });
    const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
    const id = (k: string) => `${k}-${p.tax_year}`;
    const plan = p.plan;

    return (
        <details className="text-sm" open={!!plan && plan.state !== 'protected'}>
            <summary className="cursor-pointer font-medium">
                Payment plan{plan && !plan.what_if ? `: asked for ${day(plan.requested_on)}` : ''}
                {plan?.state ? ` · ${PLAN_STATE[plan.state]}` : ''}
            </summary>
            {plan && !plan.what_if && (
                <div className="mt-2 space-y-1 text-xs">
                    {plan.state === 'protected' && plan.protected_pence > 0 && (
                        <p>
                            Held off so far: <span className="font-medium tabular-nums">{pounds(plan.protected_pence)}</span>
                            {plan.counts_from && plan.counts_from !== plan.requested_on ? ` (counted from ${day(plan.counts_from)})` : ''}.
                        </p>
                    )}
                    {plan.state === 'pending' && plan.pending_pence > 0 && (
                        <p>If HMRC agree it and you keep to it, {pounds(plan.pending_pence)} of the penalties above are cancelled.</p>
                    )}
                    {plan.notes.map((n) => (
                        <p key={n} className="text-amber-800 dark:text-amber-300">
                            {n}
                        </p>
                    ))}
                </div>
            )}
            <form
                className="mt-2 grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.patch(
                        `/years/${p.tax_year}`,
                        {
                            plan_requested_on: f.plan_requested_on || null,
                            plan_agreed: f.plan_requested_on && f.plan_agreed !== '' ? f.plan_agreed === '1' : null,
                            plan_ends_on: f.plan_requested_on ? f.plan_ends_on || null : null,
                            plan_broken_on: f.plan_requested_on ? f.plan_broken_on || null : null,
                            plan_notice_on: f.plan_requested_on && f.plan_broken_on ? f.plan_notice_on || null : null,
                            plan_amount: f.plan_requested_on ? f.plan_amount || null : null,
                        },
                        { preserveScroll: true, onError: saveError },
                    );
                }}
            >
                <label className="space-y-1" htmlFor={id('plan_requested_on')}>
                    <span className="text-muted-foreground text-xs">Date you asked HMRC for a payment plan</span>
                    <Input
                        id={id('plan_requested_on')}
                        type="date"
                        className="h-9"
                        value={f.plan_requested_on}
                        onChange={(e) => set('plan_requested_on', e.target.value)}
                    />
                </label>
                {f.plan_requested_on && (
                    <>
                        <label className="space-y-1" htmlFor={id('plan_agreed')}>
                            <span className="text-muted-foreground text-xs">Did HMRC agree it?</span>
                            <NativeSelect id={id('plan_agreed')} value={f.plan_agreed} onChange={(e) => set('plan_agreed', e.target.value)}>
                                <option value="">Not yet, or don’t know (assume not)</option>
                                <option value="1">Yes, HMRC agreed it</option>
                                <option value="0">No, HMRC refused it</option>
                            </NativeSelect>
                        </label>
                        <label className="space-y-1" htmlFor={id('plan_ends_on')}>
                            <span className="text-muted-foreground text-xs">Date of the last payment in the plan (if you know it)</span>
                            <Input
                                id={id('plan_ends_on')}
                                type="date"
                                className="h-9"
                                value={f.plan_ends_on}
                                onChange={(e) => set('plan_ends_on', e.target.value)}
                            />
                        </label>
                        <label className="space-y-1" htmlFor={id('plan_amount')}>
                            <span className="text-muted-foreground text-xs">Amount in the plan, £ (leave empty if it covers everything owed)</span>
                            <Input
                                id={id('plan_amount')}
                                inputMode="decimal"
                                className="h-9"
                                value={f.plan_amount}
                                onChange={(e) => set('plan_amount', e.target.value)}
                            />
                        </label>
                        <label className="space-y-1" htmlFor={id('plan_broken_on')}>
                            <span className="text-muted-foreground text-xs">Missed a payment? The date the plan was broken</span>
                            <Input
                                id={id('plan_broken_on')}
                                type="date"
                                className="h-9"
                                value={f.plan_broken_on}
                                onChange={(e) => set('plan_broken_on', e.target.value)}
                            />
                        </label>
                        {f.plan_broken_on && (
                            <label className="space-y-1" htmlFor={id('plan_notice_on')}>
                                <span className="text-muted-foreground text-xs">
                                    Date of HMRC’s notice charging the penalties it held off (leave empty if none has come)
                                </span>
                                <Input
                                    id={id('plan_notice_on')}
                                    type="date"
                                    className="h-9"
                                    value={f.plan_notice_on}
                                    onChange={(e) => set('plan_notice_on', e.target.value)}
                                />
                            </label>
                        )}
                    </>
                )}
                <p className="text-muted-foreground text-xs sm:col-span-2">
                    What counts is the day you asked, if HMRC then agree the plan. If it’s broken, the penalties it held off are only charged if HMRC
                    send a notice. HMRC don’t normally agree a plan while the return is overdue. Interest still runs.
                </p>
                <div className="flex items-end sm:col-span-2">
                    <Button size="sm" variant="outline">
                        Save
                    </Button>
                </div>
            </form>
        </details>
    );
}

/** An amended return: extra tax from it is penalised from its own later date; interest still runs from 31 January. */
function Amendment({ p, a }: { p: PenaltyYear; a: PenaltyAmendment }) {
    const [f, setF] = useState({ amended_on: a.amended_on ?? '', original_liability: plain(a.original_liability_pence) });
    const id = (k: string) => `${k}-${p.tax_year}`;

    return (
        <details className="text-sm" open={a.original_liability_pence === null}>
            <summary className="cursor-pointer font-medium">
                Your amended return
                {a.increase_pence ? `: ${pounds(a.increase_pence)} more tax, due ${a.increase_due ? day(a.increase_due) : ''}` : ''}
                {a.assumed_sent_today ? ' (if you send it today)' : ''}
            </summary>
            <form
                className="mt-2 grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.patch(
                        `/years/${p.tax_year}`,
                        { amended_on: f.amended_on || null, original_liability: f.original_liability || null },
                        { preserveScroll: true, onError: saveError },
                    );
                }}
            >
                <label className="space-y-1" htmlFor={id('amended_on')}>
                    <span className="text-muted-foreground text-xs">Date HMRC got the amendment (leave empty if not sent yet)</span>
                    <Input
                        id={id('amended_on')}
                        type="date"
                        className="h-9"
                        value={f.amended_on}
                        onChange={(e) => setF((x) => ({ ...x, amended_on: e.target.value }))}
                    />
                </label>
                <label className="space-y-1" htmlFor={id('original_liability')}>
                    <span className="text-muted-foreground text-xs">Tax on the return as first filed, £</span>
                    <Input
                        id={id('original_liability')}
                        inputMode="decimal"
                        className="h-9"
                        value={f.original_liability}
                        onChange={(e) => setF((x) => ({ ...x, original_liability: e.target.value }))}
                    />
                </label>
                <p className="text-muted-foreground text-xs sm:col-span-2">
                    Extra tax from an amendment is due 30 days after HMRC get it (or on the normal date, if that’s later), and late payment penalties
                    on it run from then. Interest on it runs from the normal date, as if it had been on the original return.
                </p>
                <div className="flex items-end sm:col-span-2">
                    <Button size="sm" variant="outline">
                        Save
                    </Button>
                </div>
            </form>
        </details>
    );
}

/**
 * Fines and interest for one tax year: what's already due, what's coming and when, what's still owed, and the
 * cheapest way to stop it growing. Worked out from the rules (with sources) and your records; anything that rests on
 * an assumption, or on rules that are only announced, is marked as an estimate.
 */
// A locked year can still record HMRC's side (a plan, an amendment's date): those aren't the return's figures.
export function PenaltyPanel({ p }: { p: PenaltyYear; locked?: boolean }) {
    const owing = p.penalties_pence + p.interest_pence;
    const clear =
        owing === 0 && p.upcoming.length === 0 && p.unpaid_pence === 0 && p.penalties.length === 0 && p.at_risk.length === 0 && !p.notify_hmrc;

    return (
        <section
            className={cn(
                'space-y-3 rounded-xl border p-4',
                !clear && owing > 0 && 'border-rose-300 bg-rose-50/50 dark:border-rose-900 dark:bg-rose-950/20',
                !clear && owing === 0 && 'border-amber-300 dark:border-amber-800',
            )}
            aria-labelledby={`penalties-${p.tax_year}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id={`penalties-${p.tax_year}`} className="flex items-center gap-2 font-semibold">
                        {clear ? <CheckCircle2 className="size-4 text-emerald-600" /> : <AlertTriangle className="size-4 text-rose-600" />}
                        Fines and interest for {p.tax_year}
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        {p.regime_text}.{' '}
                        {p.deadline ? (
                            <>
                                Return due {day(p.deadline)}
                                {p.deadline_basis === 'estimate' ? ' (estimated from the date of HMRC’s notice)' : ''}
                                {p.filed ? (p.filed_on ? `, filed ${day(p.filed_on)}` : ', filed') : ', not filed yet'}.
                            </>
                        ) : p.notice.notice_to_file === 'not_given' ? (
                            <>
                                No notice to file from HMRC
                                {p.told_no_notice
                                    ? ' yet: you told them by 5 October that you need to file'
                                    : p.chargeable
                                      ? `: tell them you need to file (due by ${day(p.notify_by)})`
                                      : ''}
                                .
                            </>
                        ) : null}{' '}
                        {p.estimable ? (
                            <>
                                Tax for the year about {pounds(p.liability_pence)} (from {p.basis}), due {day(p.balancing_due)}
                                {p.balancing_basis !== 'law' ? ' (an estimate: see how this is worked out)' : ''}.
                            </>
                        ) : (
                            <>
                                Tax for the year: not worked out (
                                {p.unestimable === 'scottish'
                                    ? 'Expend doesn’t work out Scottish income tax'
                                    : `no tax rates loaded for ${p.tax_year}`}
                                ), due {day(p.balancing_due)}.
                            </>
                        )}
                    </p>
                    {p.notice.assumed && p.return_needed && (
                        <p className="text-xs text-amber-800 dark:text-amber-300">
                            Assumes HMRC sent a notice to file by 31 July {Number(p.tax_year.slice(0, 4)) + 1}. Not sure? Say below.
                        </p>
                    )}
                </div>
                {!clear && (
                    <div className="text-right">
                        <p className="text-xl font-semibold tabular-nums">{pounds(owing)}</p>
                        <p className="text-muted-foreground text-xs">
                            penalties {pounds(p.penalties_pence)} + interest {pounds(p.interest_pence)} so far
                            {p.on_filing_pence > 0 ? `; ${pounds(p.on_filing_pence)} more when filed` : ''}
                        </p>
                    </div>
                )}
            </div>

            {clear && <p className="text-sm">Nothing to pay in fines or interest for this year, as far as your records show.</p>}

            {p.penalties.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium">Already due</h3>
                    <Items items={p.penalties} />
                </div>
            )}

            {p.at_risk.length > 0 && (
                <div className="rounded-lg border border-amber-300 p-3 dark:border-amber-800">
                    <h3 className="text-sm font-medium">At risk: {pounds(p.at_risk_pence)} your broken payment plan holds off</h3>
                    <p className="text-muted-foreground text-xs">
                        Not charged, and not in the totals: the plan still holds these off unless HMRC send a notice charging them (record its date
                        under the payment plan if they do). If they agree to change the plan, they stay held off.
                    </p>
                    <Items items={p.at_risk} />
                </div>
            )}

            {p.upcoming.length > 0 && (
                <div>
                    <h3 className="flex items-center gap-1.5 text-sm font-medium">
                        <CalendarClock className="size-4" /> Coming if nothing changes
                    </h3>
                    <Items items={p.upcoming} showSource={false} />
                </div>
            )}

            {p.points && <Points pts={p.points} />}

            {p.announced && (p.announced.penalties.length > 0 || p.announced.upcoming.length > 0) && (
                <details className="rounded-lg border border-dashed p-3 text-sm">
                    <summary className="cursor-pointer font-medium">
                        {p.announced.regime_text}: {pounds(p.announced.penalties_pence)} due, {pounds(p.announced.upcoming_pence)} coming
                    </summary>
                    <p className="text-muted-foreground mt-1 text-xs">
                        These are an estimate only, and not in the totals. The figures above use the rules that are law today; these show what the
                        announced rules would cost if they are made law for {p.tax_year} as announced.
                    </p>
                    <Items items={[...p.announced.penalties, ...p.announced.upcoming, ...p.announced.at_risk]} />
                    {p.announced.points && (
                        <div className="mt-2">
                            <Points pts={p.announced.points} />
                        </div>
                    )}
                </details>
            )}

            {p.submissions.length > 0 && <Submissions p={p} />}

            {p.obligations.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer font-medium">
                        Tax due for {p.tax_year}: {pounds(p.unpaid_pence)} unpaid
                        {p.per_day_pence > 0 ? ` · interest about ${pounds(p.per_day_pence)} a day at ${p.rate_pct}%` : ''}
                    </summary>
                    <ul className="mt-1 divide-y">
                        {p.obligations.map((o) => (
                            <li key={o.kind + o.due} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                <span className="text-muted-foreground w-24 shrink-0 text-xs">{day(o.due)}</span>
                                <span className="min-w-0 flex-1">{o.label}</span>
                                <span className="text-muted-foreground text-xs">
                                    {pounds(o.amount_pence)} · paid {pounds(o.paid_pence)}
                                    {o.interest_pence ? ` · interest ${pounds(o.interest_pence)}` : ''}
                                    {o.interest_from !== o.due ? ` from ${day(o.interest_from)}` : ''}
                                </span>
                                {o.interest_segments.length > 0 && (
                                    <details className="text-muted-foreground w-full pl-[6.75rem] text-xs">
                                        <summary className="cursor-pointer">How the interest is worked out</summary>
                                        <ul className="mt-1 space-y-0.5">
                                            {o.interest_segments.map((s) => (
                                                <li key={s.from} className="tabular-nums">
                                                    {day(s.from)} to {day(s.to)}: {pounds(s.balance_pence)} × {s.pct}% × {s.days}{' '}
                                                    {s.days === 1 ? 'day' : 'days'} ÷ {s.divisor} = {pounds(s.pence)}
                                                </li>
                                            ))}
                                        </ul>
                                        <p className="mt-1">
                                            Each run is rounded down to the penny, as in HMRC’s published example. HMRC’s statement is the final word.
                                        </p>
                                    </details>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {p.actions.length > 0 && (
                <ul className="list-disc space-y-1 pl-5 text-sm">
                    {p.actions.map((a) => (
                        <li key={a}>{a}</li>
                    ))}
                </ul>
            )}

            <NoticeFacts p={p} />

            {p.amendment && <Amendment p={p} a={p.amendment} />}

            {(p.unpaid_pence > 0 || (p.plan !== null && !p.plan.what_if)) && <PaymentPlan p={p} />}

            <details className="text-muted-foreground text-xs">
                <summary className="cursor-pointer">How this is worked out</summary>
                <ul className="mt-1 list-disc space-y-0.5 pl-5">
                    {p.assumptions.map((a) => (
                        <li key={a}>{a}</li>
                    ))}
                </ul>
            </details>
        </section>
    );
}
