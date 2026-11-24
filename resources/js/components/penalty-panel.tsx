import { Button } from '@/components/ui/button';
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
    status: 'due' | 'accruing' | 'upcoming';
    why: string;
    source: string;
}
export interface PenaltyYear {
    tax_year: string;
    regime: string;
    regime_text: string;
    deadline: string;
    filed: boolean;
    filed_on: string | null;
    return_needed: boolean;
    liability_pence: number;
    basis: string;
    estimable: boolean;
    payment_plan_from: string | null;
    obligations: {
        kind: string;
        label: string;
        due: string;
        amount_pence: number;
        paid_pence: number;
        outstanding_pence: number;
        interest_pence: number;
    }[];
    penalties: PenaltyItem[];
    upcoming: PenaltyItem[];
    interest_pence: number;
    per_day_pence: number;
    penalties_pence: number;
    upcoming_pence: number;
    unpaid_pence: number;
    actions: string[];
    rate_pct: string;
    assumptions: string[];
}

const pounds = (p: number) => money(p, 'GBP', { signed: false });

/**
 * Fines and interest for one tax year: what's already due, what's coming and when, what's still owed, and the
 * cheapest way to stop it growing. Worked out from the rules (with sources) and your records.
 */
export function PenaltyPanel({ p, locked }: { p: PenaltyYear; locked?: boolean }) {
    const [plan, setPlan] = useState(p.payment_plan_from ?? '');
    const owing = p.penalties_pence + p.interest_pence;
    const clear = owing === 0 && p.upcoming.length === 0 && p.unpaid_pence === 0;

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
                        {p.regime_text}. Return due {day(p.deadline)}
                        {p.filed ? (p.filed_on ? `, filed ${day(p.filed_on)}` : ', filed') : ', not filed yet'}. Tax for the year about{' '}
                        {pounds(p.liability_pence)} (from {p.basis}).
                    </p>
                </div>
                {!clear && (
                    <div className="text-right">
                        <p className="text-xl font-semibold tabular-nums">{pounds(owing)}</p>
                        <p className="text-muted-foreground text-xs">
                            penalties {pounds(p.penalties_pence)} + interest {pounds(p.interest_pence)} so far
                        </p>
                    </div>
                )}
            </div>

            {clear && <p className="text-sm">Nothing to pay in fines or interest for this year, as far as your records show.</p>}

            {p.penalties.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium">Already due</h3>
                    <ul className="divide-y text-sm">
                        {p.penalties.map((x) => (
                            <li key={x.code} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                <span className="text-muted-foreground w-24 shrink-0 text-xs">{day(x.date)}</span>
                                <span className="min-w-0 flex-1">
                                    {x.label}
                                    {x.status === 'accruing' && <span className="ml-2 text-xs text-rose-700 dark:text-rose-300">still growing</span>}
                                    <span className="text-muted-foreground block text-xs">
                                        {x.why}{' '}
                                        <a href={x.source} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                            the rule
                                        </a>
                                    </span>
                                </span>
                                <span className="tabular-nums">{pounds(x.amount_pence)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {p.upcoming.length > 0 && (
                <div>
                    <h3 className="flex items-center gap-1.5 text-sm font-medium">
                        <CalendarClock className="size-4" /> Coming if nothing changes
                    </h3>
                    <ul className="divide-y text-sm">
                        {p.upcoming.map((x) => (
                            <li key={x.code} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                <span className="text-muted-foreground w-24 shrink-0 text-xs">{day(x.date)}</span>
                                <span className="min-w-0 flex-1">
                                    {x.label}
                                    <span className="text-muted-foreground block text-xs">{x.why}</span>
                                </span>
                                <span className="tabular-nums">{pounds(x.amount_pence)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

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
                                </span>
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

            {!locked && p.unpaid_pence > 0 && (
                <form
                    className="flex flex-wrap items-center gap-2 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.patch(`/years/${p.tax_year}`, { payment_plan_from: plan || null }, { preserveScroll: true });
                    }}
                >
                    <label htmlFor={`plan-${p.tax_year}`} className="text-muted-foreground">
                        HMRC agreed a payment plan from
                    </label>
                    <Input id={`plan-${p.tax_year}`} type="date" className="h-8 w-40" value={plan} onChange={(e) => setPlan(e.target.value)} />
                    <Button size="sm" variant="outline">
                        Save
                    </Button>
                </form>
            )}

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
