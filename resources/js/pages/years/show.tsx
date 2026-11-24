import { DecisionForm, decisionProblem } from '@/components/decision-form';
import { Money } from '@/components/money';
import { PenaltyPanel, type PenaltyYear } from '@/components/penalty-panel';
import { SavingsPanel, type SavingsReview } from '@/components/savings-panel';
import { StatusPill } from '@/components/status-pill';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { YearLockPanel, type LockCheck, type LockSnapshot } from '@/components/year-lock-panel';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Decision, type Lookups } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, Lock, Unlock, XCircle } from 'lucide-react';
import { useState } from 'react';

interface ExpenseLine {
    code: string;
    name: string;
    box: string;
    allowable_pence: number;
    disallowable_pence: number;
    mileage_pence?: number;
}

interface Props {
    year: {
        label: string;
        status: string;
        locked: boolean;
        starts_on: string;
        ends_on: string;
        /** From HMRC's notice to file; null when they sent none (tell them by notify_by instead). */
        deadline: string | null;
        notify_by: string;
        overdue: boolean;
        payments_on_account_paid_pence: number;
    };
    progress: { total_count: number; confirmed_count: number; suggested_count: number; unreviewed_count: number; value_pct: number };
    coverage: {
        months: string[];
        accounts: { id: number; name: string; kind: string; months: Record<string, { count: number; continuity: string }> }[];
    };
    groups: {
        merchant_key: string;
        count: number;
        value_pence: number;
        money_in: number;
        sample: string;
        suggested: number;
        suggestion: string | null;
    }[];
    summary: {
        turnover_pence: number;
        other_income_pence: number;
        expense_lines: ExpenseLine[];
        allowable_expenses_pence: number;
        disallowable_expenses_pence: number;
        mileage_pence: number;
        net_profit_pence: number;
        trading_allowance: { allowance_pence: number; profit_with_allowance_pence: number; better: boolean };
        short_form_allowed: boolean;
        pending: { unreviewed_count: number; unreviewed_pence: number; suggested_count: number; manual_unreviewed_count: number };
    };
    estimate: null | {
        personal_allowance_pence: number;
        taxable_pence: number;
        income_tax: { basic_pence: number; higher_pence: number; additional_pence: number; total_pence: number };
        class4_pence: number;
        class2_pence: number;
        total_pence: number;
        notes: string[];
    };
    bill: null | {
        liability_pence: number;
        already_paid_pence: number;
        balancing_pence: number;
        payment_on_account_pence: number;
        due: { date: string; label: string; pence: number }[];
    };
    checks: { key: string; label: string; status: 'ok' | 'warn' | 'fail'; detail: string }[];
    lookups: Lookups;
    penalties: PenaltyYear | null;
    savings: SavingsReview | null;
    /** What was kept when the year was last locked, if it was locked with a snapshot. */
    lock?: LockSnapshot | null;
    /** Whether it still holds, once you ask ("Check it"). */
    lock_check?: LockCheck | null;
}

function Step({ n, title, children, aside }: { n: number; title: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <section className="rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
                <h2 className="flex items-center gap-3 font-semibold">
                    <span className="bg-foreground text-background flex size-6 items-center justify-center rounded-full text-xs">{n}</span>
                    {title}
                </h2>
                {aside}
            </div>
            <div className="p-5">{children}</div>
        </section>
    );
}

export default function YearShow({
    year,
    progress,
    coverage,
    groups,
    summary,
    estimate,
    bill,
    checks,
    lookups,
    penalties,
    savings,
    lock,
    lock_check,
}: Props) {
    const [group, setGroup] = useState<Props['groups'][number] | null>(null);
    const [decision, setDecision] = useState<Decision>({ kind: 'business_expense', business_pct: 100 });
    const [poa, setPoa] = useState((year.payments_on_account_paid_pence / 100).toFixed(2));
    const left = progress.unreviewed_count + progress.suggested_count;

    const openGroup = (g: Props['groups'][number]) => {
        setGroup(g);
        setDecision({
            kind: g.money_in === g.count ? 'business_income' : g.money_in === 0 ? 'business_expense' : 'personal',
            business_pct: 100,
            category_id: null,
            work_job_id: null,
        });
    };

    const saveGroup = () => {
        if (!group) return;
        const problem = decisionProblem(decision);
        if (problem) return toast(problem, 'error');
        router.post('/decisions/bulk', { ...decision, merchant_key: group.merchant_key, tax_year: year.label } as never, {
            preserveScroll: true,
            onSuccess: () => setGroup(null),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tax years', href: '/years' },
                { title: year.label, href: `/years/${year.label}` },
            ]}
        >
            <Head title={`${year.label} catch-up`} />
            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">{year.label}</h1>
                            <StatusPill status={year.status} />
                            {year.locked && (
                                <span className="text-muted-foreground flex items-center gap-1 text-sm">
                                    <Lock className="size-3.5" /> Locked
                                </span>
                            )}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {day(year.starts_on)} to {day(year.ends_on)} ·{' '}
                            <span className={year.overdue ? 'font-medium text-rose-600' : ''}>
                                {year.deadline === null
                                    ? `no notice to file from HMRC (tell them by ${day(year.notify_by)} if you need to file)`
                                    : year.overdue
                                      ? `return overdue since ${day(year.deadline)}`
                                      : `return due ${day(year.deadline)}`}
                            </span>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/years/${year.label}/export`}>
                                <Download /> Spreadsheet
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                const failed = (errors: Record<string, string>) =>
                                    toast(Object.values(errors)[0] ?? 'That didn’t work. Try again.', 'error');
                                if (year.locked) {
                                    const reason = window.prompt('Why are you unlocking this year? (kept in the audit log)');
                                    if (reason) router.post(`/years/${year.label}/lock`, { reason }, { preserveScroll: true, onError: failed });
                                } else if (checks.some((c) => c.status === 'fail')) {
                                    // A failing check needs a reason to lock anyway: it's kept with the lock.
                                    const reason = window.prompt(
                                        `Some checks before filing are failing (${checks
                                            .filter((c) => c.status === 'fail')
                                            .map((c) => c.label.toLowerCase())
                                            .join('; ')}). Why are you locking ${year.label} anyway? (kept with the lock)`,
                                    );
                                    if (reason) router.post(`/years/${year.label}/lock`, { reason }, { preserveScroll: true, onError: failed });
                                } else if (
                                    window.confirm(
                                        `Lock ${year.label}? Nothing in it can change until you unlock it with a reason. Its figures and a copy of the spreadsheet are kept with the lock.`,
                                    )
                                ) {
                                    router.post(`/years/${year.label}/lock`, {}, { preserveScroll: true, onError: failed });
                                }
                            }}
                        >
                            {year.locked ? <Unlock /> : <Lock />} {year.locked ? 'Unlock' : 'Lock year'}
                        </Button>
                    </div>
                </div>

                {lock && <YearLockPanel label={year.label} lock={lock} check={lock_check} />}

                {penalties &&
                    (penalties.penalties.length > 0 ||
                        penalties.upcoming.length > 0 ||
                        penalties.unpaid_pence > 0 ||
                        penalties.at_risk.length > 0 ||
                        penalties.plan !== null ||
                        penalties.amendment !== null ||
                        penalties.submissions.length > 0 ||
                        penalties.return_needed ||
                        penalties.chargeable) && <PenaltyPanel p={penalties} locked={year.locked} />}

                <div className="rounded-xl border p-5">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="font-medium">{progress.value_pct}% of this year's money is decided</p>
                        <p className="text-muted-foreground text-sm">
                            {progress.confirmed_count.toLocaleString()} confirmed · {progress.suggested_count.toLocaleString()} suggested ·{' '}
                            {progress.unreviewed_count.toLocaleString()} to decide
                        </p>
                    </div>
                    <Progress value={progress.value_pct} className="mt-3 h-3" />
                </div>

                <Step
                    n={1}
                    title="Import every statement"
                    aside={
                        <Button size="sm" variant="outline" asChild>
                            <Link href="/imports">Import</Link>
                        </Button>
                    }
                >
                    {coverage.accounts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Add your accounts, then import a CSV for each.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr>
                                        <th className="py-1 pr-3 text-left font-medium">Account</th>
                                        {coverage.months.map((m) => (
                                            <th key={m} className="text-muted-foreground px-0.5 text-center text-xs font-normal">
                                                {new Date(m + '-01T12:00').toLocaleDateString('en-GB', { month: 'short' })}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {coverage.accounts.map((a) => (
                                        <tr key={a.id}>
                                            <td className="py-1 pr-3 whitespace-nowrap">{a.name}</td>
                                            {coverage.months.map((m) => {
                                                const cell = a.months[m];
                                                const future = m > new Date().toISOString().slice(0, 7);
                                                return (
                                                    <td key={m} className="px-0.5 py-1">
                                                        <div
                                                            title={`${cell.count} transactions${cell.continuity === 'gap' ? ' · balance jumps here' : ''}`}
                                                            className={cn(
                                                                'mx-auto h-6 min-w-6 rounded text-center text-[10px] leading-6 tabular-nums',
                                                                future
                                                                    ? 'bg-muted/40'
                                                                    : cell.continuity === 'gap'
                                                                      ? 'bg-rose-500 text-white'
                                                                      : cell.count === 0
                                                                        ? 'bg-amber-200 dark:bg-amber-900'
                                                                        : 'bg-emerald-500/80 text-white',
                                                            )}
                                                        >
                                                            {future ? '' : cell.count}
                                                        </div>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="text-muted-foreground mt-3 text-xs">
                                Green: data. Amber: no transactions that month (fine if quiet, a problem if a statement is missing). Red: the balance
                                jumps, so rows are missing.
                            </p>
                        </div>
                    )}
                </Step>

                <Step
                    n={2}
                    title="Biggest merchants first"
                    aside={<span className="text-muted-foreground text-sm">One decision covers every undecided item from a merchant</span>}
                >
                    {groups.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing left to group. Nice.</p>
                    ) : (
                        <ul className="divide-y">
                            {groups.map((g) => (
                                <li key={g.merchant_key} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{g.merchant_key}</p>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {g.count} items · e.g. {g.sample}
                                            {g.suggestion && <span className="text-amber-700"> · suggested: {g.suggestion.toLowerCase()}</span>}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm tabular-nums">{money(g.value_pence)}</span>
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={`/transactions?year=${year.label}&q=${encodeURIComponent(g.merchant_key)}`}>One by one</Link>
                                        </Button>
                                        {g.suggestion && g.suggestion !== 'Mixed suggestions' && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                disabled={year.locked}
                                                onClick={() =>
                                                    router.post(
                                                        '/decisions/confirm',
                                                        { merchant_key: g.merchant_key, tax_year: year.label },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Accept all
                                            </Button>
                                        )}
                                        <Button size="sm" onClick={() => openGroup(g)} disabled={year.locked}>
                                            Decide all
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Step>

                <Step
                    n={3}
                    title="The rest, one by one"
                    aside={
                        <Button size="sm" asChild>
                            <Link href={`/inbox?year=${year.label}`}>Open Inbox for {year.label}</Link>
                        </Button>
                    }
                >
                    <p className="text-sm">
                        {left > 0
                            ? `${left.toLocaleString()} items still need a decision or a confirmation.`
                            : 'Everything in this year has a confirmed decision.'}{' '}
                        Each item shows that day's calendar entries and any jobs around the date.
                    </p>
                    {progress.unreviewed_count > 0 && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="mt-3"
                            onClick={() => router.post('/ai/suggest', { tax_year: year.label }, { preserveScroll: true })}
                        >
                            Ask AI to suggest the next 40
                        </Button>
                    )}
                    <p className="text-muted-foreground mt-2 text-xs">
                        AI suggestions arrive unconfirmed and labelled; you accept or change each one.
                    </p>
                </Step>

                <Step n={4} title="Checks before filing">
                    <ul className="space-y-3">
                        {checks.map((c) => (
                            <li key={c.key} className="flex gap-3 text-sm">
                                {c.status === 'ok' ? (
                                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                ) : c.status === 'warn' ? (
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                ) : (
                                    <XCircle className="mt-0.5 size-4 shrink-0 text-rose-600" />
                                )}
                                <span>
                                    <span className="font-medium">{c.label}</span>
                                    <span className="text-muted-foreground block">{c.detail}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Step>

                <Step n={5} title="Figures for your return">
                    {(summary.pending.unreviewed_count > 0 || summary.pending.suggested_count > 0) && (
                        <p className="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            {summary.pending.unreviewed_count + summary.pending.suggested_count} items aren't confirmed yet, so they're not counted
                            below.
                        </p>
                    )}
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div>
                            <h3 className="mb-2 text-sm font-medium">Self-employment pages</h3>
                            <table className="w-full text-sm">
                                <tbody className="divide-y">
                                    <tr>
                                        <td className="py-1.5">Turnover</td>
                                        <td className="text-right tabular-nums">{money(summary.turnover_pence)}</td>
                                    </tr>
                                    <tr>
                                        <td className="py-1.5">Other business income</td>
                                        <td className="text-right tabular-nums">{money(summary.other_income_pence)}</td>
                                    </tr>
                                    {summary.expense_lines.map((l) => (
                                        <tr key={l.code}>
                                            <td className="py-1.5">
                                                <span className="text-muted-foreground mr-1 text-xs">Box {l.box}</span> {l.name}
                                                {l.mileage_pence ? (
                                                    <span className="text-muted-foreground block text-xs">
                                                        includes {money(l.mileage_pence)} mileage
                                                    </span>
                                                ) : null}
                                                {l.disallowable_pence ? (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {money(l.disallowable_pence)} not allowable, so not counted
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="text-right align-top tabular-nums">{money(l.allowable_pence)}</td>
                                        </tr>
                                    ))}
                                    <tr className="font-medium">
                                        <td className="py-1.5">Total allowable expenses</td>
                                        <td className="text-right tabular-nums">{money(summary.allowable_expenses_pence)}</td>
                                    </tr>
                                    <tr className="text-base font-semibold">
                                        <td className="py-2">Net profit</td>
                                        <td className="text-right tabular-nums">{money(summary.net_profit_pence)}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <ul className="text-muted-foreground mt-3 space-y-1 text-xs">
                                {summary.short_form_allowed && (
                                    <li>Turnover is under the VAT threshold, so you can use the short pages and enter one total for expenses.</li>
                                )}
                                {summary.trading_allowance.better && (
                                    <li className="text-amber-700">
                                        Your expenses are under £1,000: the trading allowance would give a profit of{' '}
                                        {money(summary.trading_allowance.profit_with_allowance_pence)} instead.
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className="space-y-6">
                            <div>
                                <h3 className="mb-2 text-sm font-medium">Tax estimate (a cross-check, not the bill)</h3>
                                {!estimate ? (
                                    <p className="text-muted-foreground text-sm">No estimate for this year. See the checks above.</p>
                                ) : (
                                    <table className="w-full text-sm">
                                        <tbody className="divide-y">
                                            <tr>
                                                <td className="py-1.5">Personal allowance</td>
                                                <td className="text-right tabular-nums">{money(estimate.personal_allowance_pence)}</td>
                                            </tr>
                                            <tr>
                                                <td className="py-1.5">Income tax</td>
                                                <td className="text-right tabular-nums">{money(estimate.income_tax.total_pence)}</td>
                                            </tr>
                                            <tr>
                                                <td className="py-1.5">Class 4 National Insurance</td>
                                                <td className="text-right tabular-nums">{money(estimate.class4_pence)}</td>
                                            </tr>
                                            {estimate.class2_pence > 0 && (
                                                <tr>
                                                    <td className="py-1.5">Class 2 National Insurance</td>
                                                    <td className="text-right tabular-nums">{money(estimate.class2_pence)}</td>
                                                </tr>
                                            )}
                                            <tr className="font-semibold">
                                                <td className="py-1.5">Total for the year</td>
                                                <td className="text-right tabular-nums">{money(estimate.total_pence)}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                )}
                                {estimate?.notes.map((n) => (
                                    <p key={n} className="text-muted-foreground mt-2 text-xs">
                                        {n}
                                    </p>
                                ))}
                            </div>
                            {bill && (
                                <div>
                                    <h3 className="mb-2 text-sm font-medium">What HMRC is likely to ask for</h3>
                                    <div className="mb-3 flex items-center gap-2 text-sm">
                                        <label htmlFor="poa" className="text-muted-foreground">
                                            Payments on account already paid for {year.label}
                                        </label>
                                        <Input
                                            id="poa"
                                            inputMode="decimal"
                                            className="h-8 w-28"
                                            value={poa}
                                            onChange={(e) => setPoa(e.target.value)}
                                        />
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.patch(`/years/${year.label}`, { payments_on_account_paid: poa }, { preserveScroll: true })
                                            }
                                        >
                                            Save
                                        </Button>
                                    </div>
                                    <ul className="divide-y text-sm">
                                        {bill.due.map((d) => (
                                            <li key={d.date} className="flex justify-between gap-3 py-1.5">
                                                <span>
                                                    <span className="font-medium">{day(d.date)}</span> · {d.label}
                                                </span>
                                                <Money pence={d.pence} plus={false} className="font-medium" />
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="text-muted-foreground mt-2 text-xs">
                                        Your HMRC online account shows the real balance, including anything already paid or charged.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </Step>

                {savings && <SavingsPanel s={savings} />}
            </div>

            <Dialog open={!!group} onOpenChange={(open) => !open && setGroup(null)}>
                <DialogContent className="sm:max-w-xl">
                    {group && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Decide all {group.count} from {group.merchant_key}
                                </DialogTitle>
                                <DialogDescription>
                                    {money(group.value_pence)} in {year.label}. Anything you've already decided stays as it is. You can still change
                                    any single item afterwards.
                                </DialogDescription>
                            </DialogHeader>
                            <DecisionForm
                                amount={group.money_in === group.count ? 1 : -1}
                                lookups={lookups}
                                value={decision}
                                onChange={setDecision}
                                merchant={group.merchant_key}
                            />
                            <div className="flex gap-2">
                                <Button onClick={saveGroup}>Save all {group.count}</Button>
                                <Button variant="ghost" onClick={() => setGroup(null)}>
                                    Cancel
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
