import { NativeSelect } from '@/components/native-select';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Ban, ExternalLink, HandCoins, Lightbulb, Scale } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Legality = 'legal_planning_with_conditions' | 'relief_intended_by_parliament' | 'avoid_targeted_by_hmrc' | 'illegal' | null;

interface Warning {
    code: string;
    title: string;
    text: string;
    url: string | null;
}
interface Source {
    id: string;
    title: string;
    url: string;
}
interface PartnerFigures {
    share_pence: number;
    income_tax_pence: number;
    class4_pence: number;
    take_home_pence: number;
}
interface SolePension {
    gross_pence: number;
    you_pay_pence: number;
    added_by_provider_pence: number;
    extra_relief_pence: number;
    capped: boolean;
    total_tax_pence: number;
    take_home_pence: number;
    cost_to_you_pence: number;
}
interface CompanyFigures {
    salary_option: string;
    salary_pence: number;
    salary_capped: boolean;
    running_costs: {
        accountant_pence: number;
        software_pence: number;
        confirmation_statement_pence: number;
        total_pence: number;
        defaults: string[];
    };
    employer_nic_pence: number;
    employee_nic_pence: number;
    corporation_tax_pence: number;
    corporation_tax_band: 'none' | 'small' | 'marginal' | 'main';
    dividends_pence: number;
    retained_pence: number;
    income_tax_on_salary_pence: number;
    dividend_tax_pence: number;
    income_tax_pence: number;
    total_tax_pence: number;
    take_home_pence: number;
    shortfall_pence: number;
    difference_pence: number;
}
interface Comparison {
    tax_year: string;
    profit_pence: number;
    law: { announced: boolean; label: string; announced_items: string[]; law_note: string };
    sole_trader: {
        income_tax_pence: number;
        class4_pence: number;
        class2_pence: number;
        class2_note: string | null;
        total_tax_pence: number;
        take_home_pence: number;
        pension: SolePension | null;
    };
    company: CompanyFigures & {
        salaries_tried: { option: string; salary_pence: number; total_tax_pence: number; take_home_pence: number }[];
        pension: (CompanyFigures & { gross_pence: number; capped: boolean; cost_to_you_pence: number }) | null;
    };
    partnership: {
        partner_share_pct: string;
        you: PartnerFigures;
        partner: PartnerFigures & { other_income_pence: number };
        total_tax_pence: number;
        take_home_pence: number;
        difference_pence: number;
        pension: {
            gross_pence: number;
            you_pay_pence: number;
            extra_relief_pence: number;
            capped: boolean;
            total_tax_pence: number;
            take_home_pence: number;
            cost_to_you_pence: number;
            difference_pence: number;
        } | null;
    };
    break_even: {
        profit_pence: number | null;
        ahead_until_pence: number | null;
        windows: { from_pence: number; until_pence: number }[];
        note: string;
    } | null;
    assumptions: string[];
    warnings: Warning[];
    sources: Source[];
    researched_on: string | null;
}
interface Item {
    id: string;
    section: string;
    name: string;
    url: string | null;
    legality: Legality;
    status: string | null;
    figures: { label: string; text: string }[];
    figures_disputed: boolean;
    disputes: string[];
    relevance: string | null;
}
interface Idea extends Item {
    summary: string;
    conditions: string | null;
    risks: string | null;
    fit: 'likely' | 'company' | 'later' | 'some';
}
interface Funding extends Item {
    type: 'grant' | 'loan' | 'bursary' | 'support' | null;
    who: string | null;
    amount: string | null;
    how_taxed: string | null;
    conditions: string | null;
    risks: string | null;
    sector: boolean;
}
interface Avoid extends Item {
    why: string;
    never_recommend: boolean;
}
interface Inputs {
    profit: number | null;
    salary: string;
    salary_amount: number | null;
    retain: number | null;
    accountant: number | null;
    software: number | null;
    statement: number | null;
    share: string | null;
    partner_other: number | null;
    partner_se: boolean;
    pension: number | null;
    class2: boolean;
}
interface ProfitBasis {
    profit_pence: number;
    records_pence: number;
    basis: 'records' | 'projected' | 'later_year';
    label: string;
}
interface Props {
    years: string[];
    year: string;
    researched_on: string | null;
    inputs: Inputs;
    profit_basis: ProfitBasis;
    comparison: Comparison;
    ideas: Idea[];
    funding: Funding[];
    avoid: Avoid[];
}

/** The text fields, in pounds (blank means the default shown as a hint). */
type Form = Record<
    'profit' | 'salary_amount' | 'retain' | 'accountant' | 'software' | 'statement' | 'partner_other' | 'pension' | 'share',
    string
> & {
    salary: string;
    class2: boolean;
    partner_se: boolean;
};

// Negative amounts (a company whose running costs are more than its profit) keep their minus sign.
const pounds = (p: number) => money(p, 'GBP');
const signed = (p: number) => money(p, 'GBP', { plus: true });
const toPounds = (p: number | null | undefined) => (p === null || p === undefined ? '' : String(p / 100));

const LEGALITY: Record<string, { label: string; className: string }> = {
    legal_planning_with_conditions: {
        label: 'Legal, with conditions',
        className: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    },
    relief_intended_by_parliament: { label: 'A relief Parliament intends', className: 'bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-200' },
    avoid_targeted_by_hmrc: { label: 'HMRC targets this: avoid', className: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200' },
    illegal: { label: 'Illegal', className: 'bg-red-100 text-red-900 dark:bg-red-900 dark:text-red-100' },
};
const FUNDING_TYPE: Record<string, string> = { grant: 'Grant', loan: 'Loan', bursary: 'Bursary', support: 'Support' };
const SALARY: Record<string, string> = {
    best: 'Whichever leaves the least tax',
    none: 'No salary',
    secondary_threshold: 'Up to the employer NIC threshold',
    personal_allowance: 'Up to the personal allowance',
    custom: 'An amount I choose',
};
/** The salary used, as it reads inside a figure's label: "Salary (up to the personal allowance)". */
const SALARY_ROW: Record<string, string> = {
    none: 'no salary',
    secondary_threshold: 'up to the employer NIC threshold',
    personal_allowance: 'up to the personal allowance',
    custom: 'your figure',
};
const CT_BAND: Record<string, string> = {
    none: 'no profit to tax',
    small: 'small profits rate',
    marginal: 'main rate less marginal relief',
    main: 'main rate',
};

function Badge({ legality }: { legality: Legality }) {
    const l = legality ? LEGALITY[legality] : null;
    if (!l) return null;
    return <span className={cn('inline-block rounded px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap', l.className)}>{l.label}</span>;
}

function SourceLink({ url, label = 'Source' }: { url: string | null; label?: string }) {
    if (!url) return null;
    return (
        <a
            href={url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 text-xs [overflow-wrap:anywhere] underline underline-offset-2"
        >
            {label} <ExternalLink className="size-3 shrink-0" aria-hidden />
        </a>
    );
}

function Row({ label, value, strong, muted }: { label: React.ReactNode; value: React.ReactNode; strong?: boolean; muted?: boolean }) {
    return (
        <div className={cn('flex items-baseline justify-between gap-3 py-0.5 text-sm', muted && 'text-muted-foreground', strong && 'font-semibold')}>
            <span className="min-w-0">{label}</span>
            <span className="shrink-0 tabular-nums">{value}</span>
        </div>
    );
}

function Field({ label, hint, children, htmlFor }: { label: string; hint?: React.ReactNode; children: React.ReactNode; htmlFor: string }) {
    return (
        <div className="min-w-0 space-y-1">
            <label htmlFor={htmlFor} className="text-sm font-medium">
                {label}
            </label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

const inputClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

/** The common details of a researched item: status, figures for the year, disputes and the source. */
function ItemDetail({ item }: { item: Item }) {
    return (
        <div className="space-y-1.5">
            {item.figures.length > 0 && (
                <div className="text-muted-foreground space-y-0.5 text-xs">
                    {item.figures.map((f, i) => (
                        <p key={i}>
                            <span className="font-medium">{f.label}:</span> {f.text}
                        </p>
                    ))}
                </div>
            )}
            {item.figures_disputed && (
                <p className="text-xs text-amber-800 dark:text-amber-200">
                    Some figures here are disputed between sources{item.disputes.length > 0 ? `: ${item.disputes.join(' ')}` : '.'}
                </p>
            )}
            {item.status && (
                <p className="text-muted-foreground text-xs">
                    <span className="font-medium">Status:</span> {item.status}
                </p>
            )}
            <SourceLink url={item.url} />
        </div>
    );
}

/**
 * Structures and planning: sole trader, company and partnership side by side for a year and a profit, then legal ideas
 * that fit, grants, loans and support, and things to avoid, each from research checked against the law and GOV.UK.
 */
export default function Planning({ years, year, researched_on, inputs, profit_basis, comparison, ideas, funding, avoid }: Props) {
    const c = comparison;
    const initial = (): Form => ({
        profit: toPounds(inputs.profit),
        salary: inputs.salary,
        salary_amount: toPounds(inputs.salary_amount),
        retain: toPounds(inputs.retain),
        accountant: toPounds(inputs.accountant),
        software: toPounds(inputs.software),
        statement: toPounds(inputs.statement),
        partner_other: toPounds(inputs.partner_other),
        pension: toPounds(inputs.pension),
        share: inputs.share ?? '',
        class2: inputs.class2,
        partner_se: inputs.partner_se,
    });
    const [form, setForm] = useState<Form>(initial);
    const [filter, setFilter] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, []);

    const query = (f: Form, y: string) => {
        const q: Record<string, string> = { year: y };
        (['profit', 'salary_amount', 'retain', 'accountant', 'software', 'statement', 'partner_other', 'pension', 'share'] as const).forEach((k) => {
            if (f[k].trim() !== '') q[k] = f[k].trim();
        });
        if (f.salary !== 'best') q.salary = f.salary;
        if (f.class2) q.class2 = '1';
        if (f.partner_se) q.partner_se = '1';
        return q;
    };
    // The last change wins: a figure typed just before a change of year mustn't reload the old year after it.
    const reload = (f: Form, y: string, all = false) => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = null;
        router.get('/planning', query(f, y), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(all ? {} : { only: ['comparison', 'inputs', 'profit_basis'] }),
        });
    };
    const set = (patch: Partial<Form>) => {
        const next = { ...form, ...patch };
        setForm(next);
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => reload(next, year), 500);
    };

    const costs = c.company.running_costs;
    const match = (i: { name: string; summary?: string; why?: string; who?: string | null; relevance?: string | null }) =>
        !filter || `${i.name} ${i.summary ?? ''} ${i.why ?? ''} ${i.who ?? ''} ${i.relevance ?? ''}`.toLowerCase().includes(filter.toLowerCase());
    const likely = ideas.filter((i) => i.fit === 'likely' && match(i));
    const some = ideas.filter((i) => i.fit === 'some' && match(i));
    const companyIdeas = ideas.filter((i) => i.fit === 'company' && match(i));
    const later = ideas.filter((i) => i.fit === 'later' && match(i));
    const sectorFunding = funding.filter((f) => f.sector && match(f));
    const otherFunding = funding.filter((f) => !f.sector && match(f));
    const shownAvoid = avoid.filter(match);

    const ideaCard = (i: Idea) => (
        <details key={i.id} className="rounded-xl border p-3 text-sm">
            <summary className="cursor-pointer">
                <span className="font-medium">{i.name}</span> <Badge legality={i.legality} />
            </summary>
            <div className="mt-2 space-y-2 [overflow-wrap:anywhere]">
                <p>{i.summary}</p>
                {i.relevance && (
                    <p>
                        <span className="font-medium">For you:</span> {i.relevance}
                    </p>
                )}
                {i.conditions && (
                    <p>
                        <span className="font-medium">Conditions:</span> {i.conditions}
                    </p>
                )}
                {i.risks && (
                    <p>
                        <span className="font-medium">Risks:</span> {i.risks}
                    </p>
                )}
                <ItemDetail item={i} />
            </div>
        </details>
    );
    const fundingCard = (f: Funding) => (
        <details key={f.id} className="rounded-xl border p-3 text-sm">
            <summary className="cursor-pointer">
                <span className="font-medium">{f.name}</span>{' '}
                {f.type && <span className="bg-muted rounded px-1.5 py-0.5 text-[11px] font-medium">{FUNDING_TYPE[f.type] ?? f.type}</span>}
            </summary>
            <div className="mt-2 space-y-2 [overflow-wrap:anywhere]">
                {f.who && (
                    <p className="whitespace-pre-line">
                        <span className="font-medium">Who can get it:</span> {f.who}
                    </p>
                )}
                {f.amount && (
                    <p className="whitespace-pre-line">
                        <span className="font-medium">How much:</span> {f.amount}
                    </p>
                )}
                {f.how_taxed && (
                    <p className="whitespace-pre-line">
                        <span className="font-medium">How it’s taxed:</span> {f.how_taxed}
                    </p>
                )}
                {f.relevance && (
                    <p>
                        <span className="font-medium">For you:</span> {f.relevance}
                    </p>
                )}
                {f.conditions && (
                    <p className="whitespace-pre-line">
                        <span className="font-medium">Conditions:</span> {f.conditions}
                    </p>
                )}
                {f.risks && (
                    <p className="whitespace-pre-line">
                        <span className="font-medium">Risks:</span> {f.risks}
                    </p>
                )}
                <ItemDetail item={f} />
            </div>
        </details>
    );

    const pension = c.sole_trader.pension;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tax years', href: '/years' },
                { title: 'Planning', href: '/planning' },
            ]}
        >
            <Head title="Structures and planning" />
            <div className="mx-auto w-full max-w-5xl min-w-0 space-y-6 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                        <Scale className="size-5" /> Structures and planning
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Sole trader, limited company or partnership: what you would pay and take home in a year, side by side, then legal ways to
                        plan, grants and loans, and things to stay away from. Every figure is an estimate from research checked against the law and
                        GOV.UK
                        {researched_on ? ` (checked ${researched_on})` : ''}. It isn’t advice: ask an accountant before you set up a company.
                    </p>
                </div>

                {c.law.announced && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        <p className="font-medium">{c.tax_year}: some figures are announced, not yet law</p>
                        <ul className="mt-1 list-disc space-y-0.5 pl-5">
                            {c.law.announced_items.map((a) => (
                                <li key={a}>{a}</li>
                            ))}
                        </ul>
                        <p className="mt-1 text-xs">{c.law.law_note}</p>
                    </div>
                )}

                <section className="space-y-3 rounded-xl border p-4" aria-labelledby="planning-inputs">
                    <h2 id="planning-inputs" className="font-semibold">
                        Your figures
                    </h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label="Tax year" htmlFor="pl-year">
                            <NativeSelect id="pl-year" value={year} onChange={(e) => reload(form, e.target.value, true)}>
                                {years.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                        {y === '2027-28' ? ' (partly announced)' : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Business profit for the year (£)"
                            htmlFor="pl-profit"
                            hint={
                                form.profit === '' ? (
                                    <>
                                        Using {pounds(profit_basis.profit_pence)}: {profit_basis.label}
                                    </>
                                ) : (
                                    <button type="button" className="underline underline-offset-2" onClick={() => set({ profit: '' })}>
                                        Use my own figure ({pounds(profit_basis.profit_pence)})
                                    </button>
                                )
                            }
                        >
                            <input
                                id="pl-profit"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.profit}
                                placeholder={String(profit_basis.profit_pence / 100)}
                                onChange={(e) => set({ profit: e.target.value })}
                            />
                        </Field>
                        <Field label="Your salary from the company" htmlFor="pl-salary">
                            <NativeSelect id="pl-salary" value={form.salary} onChange={(e) => set({ salary: e.target.value })}>
                                {Object.entries(SALARY).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        {form.salary === 'custom' && (
                            <Field label="Salary (£ a year)" htmlFor="pl-salary-amount">
                                <input
                                    id="pl-salary-amount"
                                    inputMode="decimal"
                                    className={inputClass}
                                    value={form.salary_amount}
                                    onChange={(e) => set({ salary_amount: e.target.value })}
                                />
                            </Field>
                        )}
                        <Field label="Leave in the company (£)" htmlFor="pl-retain" hint="Not paid out this year: taxed when you take it later.">
                            <input
                                id="pl-retain"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.retain}
                                placeholder="0"
                                onChange={(e) => set({ retain: e.target.value })}
                            />
                        </Field>
                        <Field
                            label="Accountant (£ a year)"
                            htmlFor="pl-accountant"
                            hint={
                                costs.defaults.includes('accountant') ? 'Expend’s placeholder: no official figure exists. Put in a quote.' : undefined
                            }
                        >
                            <input
                                id="pl-accountant"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.accountant}
                                placeholder={String(costs.accountant_pence / 100)}
                                onChange={(e) => set({ accountant: e.target.value })}
                            />
                        </Field>
                        <Field
                            label="Software (£ a year)"
                            htmlFor="pl-software"
                            hint={costs.defaults.includes('software') ? 'Expend’s placeholder for accounts and tax return software.' : undefined}
                        >
                            <input
                                id="pl-software"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.software}
                                placeholder={String(costs.software_pence / 100)}
                                onChange={(e) => set({ software: e.target.value })}
                            />
                        </Field>
                        <Field
                            label="Confirmation statement (£ a year)"
                            htmlFor="pl-statement"
                            hint={
                                costs.defaults.includes('confirmation_statement')
                                    ? 'The Companies House online fee for most of this year.'
                                    : undefined
                            }
                        >
                            <input
                                id="pl-statement"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.statement}
                                placeholder={String(costs.confirmation_statement_pence / 100)}
                                onChange={(e) => set({ statement: e.target.value })}
                            />
                        </Field>
                        <Field label="Partner’s share of the profit (%)" htmlFor="pl-share" hint="Your spouse or partner in a partnership.">
                            <input
                                id="pl-share"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.share}
                                placeholder={c.partnership.partner_share_pct}
                                onChange={(e) => set({ share: e.target.value })}
                            />
                        </Field>
                        <Field
                            label="Partner’s other income (£ a year)"
                            htmlFor="pl-partner-other"
                            hint={
                                <label className="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={form.partner_se}
                                        onChange={(e) => set({ partner_se: e.target.checked })}
                                    />
                                    <span>It’s from self-employment (otherwise it’s taken as wages or a pension)</span>
                                </label>
                            }
                        >
                            <input
                                id="pl-partner-other"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.partner_other}
                                placeholder="0"
                                onChange={(e) => set({ partner_other: e.target.value })}
                            />
                        </Field>
                        <Field label="Into a pension (£ a year, gross)" htmlFor="pl-pension" hint="Compared the same way in each structure.">
                            <input
                                id="pl-pension"
                                inputMode="decimal"
                                className={inputClass}
                                value={form.pension}
                                placeholder="0"
                                onChange={(e) => set({ pension: e.target.value })}
                            />
                        </Field>
                        <label className="flex items-start gap-2 self-end pb-1 text-sm">
                            <input type="checkbox" className="mt-0.5" checked={form.class2} onChange={(e) => set({ class2: e.target.checked })} />
                            <span>Pay voluntary Class 2 as a sole trader (only if profit is under the small profits threshold)</span>
                        </label>
                    </div>
                </section>

                <section className="space-y-3" aria-labelledby="planning-compare">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="planning-compare" className="font-semibold">
                            Side by side for {c.tax_year}, on {pounds(c.profit_pence)} profit
                        </h2>
                        <span className="text-muted-foreground text-xs">Estimates · {c.law.label}</span>
                    </div>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div className="min-w-0 rounded-xl border p-4">
                            <h3 className="font-medium">Sole trader</h3>
                            <p className="text-muted-foreground text-xs">What you do now</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{pounds(c.sole_trader.take_home_pence)}</p>
                            <p className="text-muted-foreground mb-2 text-xs">take-home (estimate)</p>
                            <Row label="Income tax" value={pounds(c.sole_trader.income_tax_pence)} />
                            <Row label="Class 4 NIC" value={pounds(c.sole_trader.class4_pence)} />
                            {c.sole_trader.class2_pence > 0 && <Row label="Voluntary Class 2" value={pounds(c.sole_trader.class2_pence)} />}
                            <Row label="Total tax" value={pounds(c.sole_trader.total_tax_pence)} strong />
                            {c.sole_trader.class2_note && <p className="text-muted-foreground mt-1 text-xs">{c.sole_trader.class2_note}</p>}
                        </div>

                        <div className="min-w-0 rounded-xl border p-4">
                            <h3 className="font-medium">Limited company</h3>
                            <p className="text-muted-foreground text-xs">You as the only director</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{pounds(c.company.take_home_pence)}</p>
                            <p
                                className={cn(
                                    'mb-2 text-xs',
                                    c.company.difference_pence > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground',
                                )}
                            >
                                take-home (estimate), {signed(c.company.difference_pence)} against sole trader
                            </p>
                            <Row
                                label={`Salary (${SALARY_ROW[c.company.salary_option] ?? c.company.salary_option}${c.company.salary_capped ? ', cut' : ''})`}
                                value={pounds(c.company.salary_pence)}
                                muted
                            />
                            <Row label="Employer NIC" value={pounds(c.company.employer_nic_pence)} />
                            <Row label="Employee NIC" value={pounds(c.company.employee_nic_pence)} />
                            <Row
                                label={`Corporation tax (${CT_BAND[c.company.corporation_tax_band]})`}
                                value={pounds(c.company.corporation_tax_pence)}
                            />
                            <Row label="Dividends" value={pounds(c.company.dividends_pence)} muted />
                            {c.company.income_tax_on_salary_pence > 0 && (
                                <Row label="Income tax on salary" value={pounds(c.company.income_tax_on_salary_pence)} />
                            )}
                            <Row label="Dividend tax" value={pounds(c.company.dividend_tax_pence)} />
                            <Row label="Total tax" value={pounds(c.company.total_tax_pence)} strong />
                            <Row label="Running costs" value={pounds(costs.total_pence)} muted />
                            {c.company.retained_pence > 0 && <Row label="Left in the company" value={pounds(c.company.retained_pence)} muted />}
                        </div>

                        <div className="min-w-0 rounded-xl border p-4">
                            <h3 className="font-medium">Partnership</h3>
                            <p className="text-muted-foreground text-xs">With your spouse or partner, {c.partnership.partner_share_pct}% to them</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{pounds(c.partnership.take_home_pence)}</p>
                            <p
                                className={cn(
                                    'mb-2 text-xs',
                                    c.partnership.difference_pence > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground',
                                )}
                            >
                                take-home for you both (estimate), {signed(c.partnership.difference_pence)} against sole trader
                            </p>
                            <Row label="Your share" value={pounds(c.partnership.you.share_pence)} muted />
                            <Row label="Your income tax" value={pounds(c.partnership.you.income_tax_pence)} />
                            <Row label="Your Class 4" value={pounds(c.partnership.you.class4_pence)} />
                            <Row label="Their share" value={pounds(c.partnership.partner.share_pence)} muted />
                            <Row label="Their extra income tax" value={pounds(c.partnership.partner.income_tax_pence)} />
                            <Row label="Their Class 4" value={pounds(c.partnership.partner.class4_pence)} />
                            <Row label="Total tax" value={pounds(c.partnership.total_tax_pence)} strong />
                            <p className="text-muted-foreground mt-1 text-xs">Partners can’t use the trading allowance. See the warnings below.</p>
                        </div>
                    </div>

                    {pension && c.company.pension && c.partnership.pension && (
                        <div className="rounded-xl border p-4">
                            <h3 className="font-medium">With {pounds(pension.gross_pence)} a year into a pension</h3>
                            <p className="text-muted-foreground text-xs">
                                Sole trader and partnership: your own contribution (you pay 80%, the provider adds 20%, higher-rate relief on your
                                return). Company: paid by the company before corporation tax, with no National Insurance. Estimates.
                            </p>
                            <div className="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">Sole trader</p>
                                    <Row label="Take-home" value={pounds(pension.take_home_pence)} strong />
                                    <Row label="Into the pension" value={pounds(pension.gross_pence)} muted />
                                    <Row label="Extra relief on your return" value={pounds(pension.extra_relief_pence)} />
                                    <Row label="Cost to you" value={pounds(pension.cost_to_you_pence)} />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">Limited company</p>
                                    <Row label="Take-home" value={pounds(c.company.pension.take_home_pence)} strong />
                                    <Row label="Into the pension" value={pounds(c.company.pension.gross_pence)} muted />
                                    <Row label="Cost to you" value={pounds(c.company.pension.cost_to_you_pence)} />
                                    <Row label="Against sole trader" value={signed(c.company.pension.difference_pence)} />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">Partnership</p>
                                    <Row label="Take-home" value={pounds(c.partnership.pension.take_home_pence)} strong />
                                    <Row label="Into the pension" value={pounds(c.partnership.pension.gross_pence)} muted />
                                    <Row label="Cost to you" value={pounds(c.partnership.pension.cost_to_you_pence)} />
                                    <Row label="Against sole trader" value={signed(c.partnership.pension.difference_pence)} />
                                </div>
                            </div>
                        </div>
                    )}

                    {c.break_even && (
                        <div className="rounded-xl border p-4 text-sm">
                            <h3 className="font-medium">When would a company pay?</h3>
                            <p className="mt-1">{c.break_even.note}</p>
                        </div>
                    )}

                    <div className="space-y-2">
                        {c.warnings.map((w) => (
                            <div
                                key={w.code}
                                className={cn(
                                    'rounded-xl border p-3 text-sm [overflow-wrap:anywhere]',
                                    w.code === 'not_advice' &&
                                        'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100',
                                )}
                            >
                                <p className="flex items-center gap-2 font-medium">
                                    <AlertTriangle className="size-4 shrink-0 text-amber-600" aria-hidden /> {w.title}
                                </p>
                                <p className="mt-1">{w.text}</p>
                                {w.url && (
                                    <div className="mt-1">
                                        <SourceLink url={w.url} />
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    <details className="rounded-xl border p-3 text-sm">
                        <summary className="cursor-pointer font-medium">How this is worked out, and the sources</summary>
                        <ul className="mt-2 list-disc space-y-1 pl-5 [overflow-wrap:anywhere]">
                            {c.assumptions.map((a) => (
                                <li key={a}>{a}</li>
                            ))}
                        </ul>
                        {c.company.salaries_tried.length > 1 && (
                            <div className="mt-2">
                                <p className="font-medium">Salaries tried</p>
                                {c.company.salaries_tried.map((s) => (
                                    <Row
                                        key={s.option}
                                        label={`${pounds(s.salary_pence)} (${SALARY_ROW[s.option] ?? s.option})`}
                                        value={`tax ${pounds(s.total_tax_pence)}`}
                                        muted
                                    />
                                ))}
                            </div>
                        )}
                        <ul className="mt-2 space-y-1">
                            {c.sources.map((s) => (
                                <li key={s.id}>
                                    <SourceLink url={s.url} label={s.title} />
                                </li>
                            ))}
                        </ul>
                    </details>
                </section>

                <div className="space-y-1">
                    <input
                        type="search"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Filter the lists below, e.g. pension, spouse, grant"
                        aria-label="Filter ideas, grants and things to avoid"
                        className={cn(inputClass, 'max-w-sm')}
                    />
                </div>

                <section className="space-y-2" aria-labelledby="planning-ideas">
                    <h2 id="planning-ideas" className="flex items-center gap-2 font-semibold">
                        <Lightbulb className="size-4 text-amber-500" aria-hidden /> Ideas that fit you
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Only legal planning and reliefs Parliament intends, each with its conditions and risks. Figures shown are for {year}.
                    </p>
                    {likely.length > 0 && <h3 className="pt-1 text-sm font-medium">Most likely to matter now</h3>}
                    {likely.map(ideaCard)}
                    {companyIdeas.length > 0 && <h3 className="pt-2 text-sm font-medium">If you set up a company or LLP</h3>}
                    {companyIdeas.map(ideaCard)}
                    {later.length > 0 && (
                        <>
                            <h3 className="pt-2 text-sm font-medium">Family savings, trusts and inheritance</h3>
                            <p className="text-muted-foreground text-xs">
                                No trust can lower the tax on your day-rate earnings. These matter for savings, children and what you pass on.
                            </p>
                        </>
                    )}
                    {later.map(ideaCard)}
                    {some.length > 0 && <h3 className="pt-2 text-sm font-medium">Only in some situations</h3>}
                    {some.map(ideaCard)}
                    {likely.length + companyIdeas.length + later.length + some.length === 0 && (
                        <p className="text-sm">Nothing matches that filter.</p>
                    )}
                </section>

                <section className="space-y-2" aria-labelledby="planning-funding">
                    <h2 id="planning-funding" className="flex items-center gap-2 font-semibold">
                        <HandCoins className="size-4 text-emerald-600" aria-hidden /> Grants, loans and support
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        For events, film, TV, theatre and music workers first, then general schemes, each with how it’s taxed.
                    </p>
                    {sectorFunding.length > 0 && <h3 className="pt-1 text-sm font-medium">For your industry</h3>}
                    {sectorFunding.map(fundingCard)}
                    {otherFunding.length > 0 && <h3 className="pt-2 text-sm font-medium">For any business</h3>}
                    {otherFunding.map(fundingCard)}
                    {sectorFunding.length + otherFunding.length === 0 && <p className="text-sm">Nothing matches that filter.</p>}
                </section>

                <section className="space-y-2" aria-labelledby="planning-avoid">
                    <h2 id="planning-avoid" className="flex items-center gap-2 font-semibold">
                        <Ban className="size-4 text-red-600" aria-hidden /> Things to avoid
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Schemes HMRC targets, things that are illegal, and pitfalls of lawful planning. Never use the first two; know the third before
                        you act.
                    </p>
                    {shownAvoid.map((a) => (
                        <div key={a.id} className="rounded-xl border p-3 text-sm">
                            <p className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{a.name}</span>
                                {a.never_recommend ? (
                                    <Badge legality={a.legality} />
                                ) : (
                                    <span className="bg-muted rounded px-1.5 py-0.5 text-[11px] font-medium">A pitfall to know</span>
                                )}
                            </p>
                            <p className="mt-1 line-clamp-3 [overflow-wrap:anywhere]">{a.why}</p>
                            <details className="mt-1">
                                <summary className="cursor-pointer text-xs">Why, in full, with the source</summary>
                                <div className="mt-2 space-y-2 [overflow-wrap:anywhere]">
                                    <p className="whitespace-pre-line">{a.why}</p>
                                    {a.relevance && (
                                        <p>
                                            <span className="font-medium">For you:</span> {a.relevance}
                                        </p>
                                    )}
                                    <ItemDetail item={a} />
                                </div>
                            </details>
                        </div>
                    ))}
                    {shownAvoid.length === 0 && <p className="text-sm">Nothing matches that filter.</p>}
                </section>
            </div>
        </AppLayout>
    );
}
