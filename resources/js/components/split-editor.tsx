import { ClientSelect } from '@/components/client-select';
import { CategorySelect, JobSelect } from '@/components/decision-form';
import { JobCombobox } from '@/components/job-combobox';
import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { money, toPence } from '@/lib/format';
import { feeCategoryId, linesProblem, turnoverId } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type Allocation, type DecisionLine, type JobRef, type Kind, type Lookups } from '@/types';
import { Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

/**
 * A line as the editor holds it. The amount is typed without a sign (a phone's number pad has no minus key): money
 * out is all money out, and on money in only a line marked as a fee taken off comes off.
 */
export interface Line {
    kind: Kind;
    category_id: number | null;
    work_job_id: number | null;
    job?: string | null;
    invoice_id: number | null;
    invoice_number?: string | null;
    client_id: number | null;
    client?: string | null;
    business_use_pct: number | null;
    amount: string;
    /** Money in: a fee or commission the payer took off (a negative business expense). */
    deduction: boolean;
    note?: string | null;
}

const pounds = (pence: number) => (Math.abs(pence) / 100).toFixed(2);

/** The lines a payment has now, everything they say kept (their invoice, client, business share), so saving keeps it. */
function fromAllocations(amount: number, allocations: Allocation[], lookups: Lookups): Line[] {
    if (allocations.length === 0)
        return [
            {
                kind: amount < 0 ? 'business_expense' : 'business_income',
                category_id: amount > 0 ? turnoverId(lookups) : null,
                work_job_id: null,
                invoice_id: null,
                client_id: null,
                business_use_pct: null,
                amount: pounds(amount),
                deduction: false,
            },
        ];
    return allocations.map((a) => ({
        kind: a.kind,
        category_id: a.category_id,
        work_job_id: a.work_job_id,
        job: a.job,
        invoice_id: a.invoice_id ?? null,
        invoice_number: a.invoice_number ?? null,
        client_id: a.client_id ?? null,
        client: a.client ?? null,
        business_use_pct: a.business_use_pct,
        amount: pounds(a.amount_pence),
        deduction: amount > 0 && a.amount_pence < 0,
        note: a.note,
    }));
}

/** A line's pence, signed as the ledger keeps it; null when the amount can't be read. */
function signed(line: Line, amount: number): number | null {
    const p = toPence(line.amount);
    if (p === null) return null;
    return Math.abs(p) * (amount < 0 || line.deduction ? -1 : 1);
}

/**
 * Split one payment across jobs, invoices, clients, categories or business and personal. Lines must add up to the
 * payment exactly, in pence, before Save works. Income lines show the invoice they pay and who they're for.
 */
export function SplitEditor({
    amount,
    allocations,
    lookups,
    near,
    on,
    busy,
    disabled,
    deductionNote,
    onSave,
}: {
    amount: number;
    allocations: Allocation[];
    lookups: Lookups;
    near?: JobRef[];
    /** The payment's date, for the job search. */
    on?: string | null;
    busy?: boolean;
    disabled?: boolean;
    deductionNote?: string | null;
    onSave: (lines: DecisionLine[]) => void;
}) {
    const [lines, setLines] = useState<Line[]>(() => fromAllocations(amount, allocations, lookups));
    const moneyIn = amount > 0;
    const pence = lines.map((l) => signed(l, amount));
    const out: DecisionLine[] = lines.map((l, i) => ({
        kind: l.kind,
        category_id: l.category_id,
        work_job_id: l.work_job_id,
        invoice_id: l.invoice_id,
        client_id: l.client_id,
        amount_pence: pence[i] ?? 0,
        business_use_pct: l.business_use_pct,
        note: l.note ?? null,
    }));
    const total = pence.reduce<number>((s, p) => s + (p ?? 0), 0);
    // What's still to put on a line, in the payment's direction (positive: some left; negative: too much).
    const left = (amount - total) * (amount < 0 ? -1 : 1);
    const problem = pence.some((p) => p === null) ? 'Type an amount on every line.' : linesProblem(out, amount);

    const update = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    const addLine = () =>
        setLines((ls) => {
            const first = ls[0];
            if (moneyIn && left < 0) {
                // The income is more than the payment: what's over is the fee they took off.
                return [
                    ...ls,
                    {
                        kind: 'business_expense',
                        category_id: feeCategoryId(lookups),
                        work_job_id: null,
                        invoice_id: null,
                        client_id: first?.client_id ?? null,
                        business_use_pct: null,
                        amount: pounds(left),
                        deduction: true,
                    },
                ];
            }
            return [
                ...ls,
                moneyIn
                    ? {
                          kind: 'business_income',
                          category_id: turnoverId(lookups),
                          work_job_id: null,
                          invoice_id: null,
                          client_id: first?.client_id ?? null,
                          client: first?.client_id ? first.client : null,
                          business_use_pct: null,
                          amount: pounds(left),
                          deduction: false,
                      }
                    : {
                          kind: 'personal',
                          category_id: null,
                          work_job_id: null,
                          invoice_id: null,
                          client_id: null,
                          business_use_pct: null,
                          amount: pounds(left),
                          deduction: false,
                      },
            ];
        });

    return (
        <div className="space-y-3">
            {lines.map((line, i) => {
                const income = line.kind === 'business_income';
                const business = income || line.kind === 'business_expense';
                return (
                    <div key={i} className="space-y-2 rounded-lg border p-3">
                        <div className="flex gap-2">
                            <NativeSelect
                                className="h-11 min-w-0 flex-1"
                                value={line.kind}
                                disabled={disabled}
                                onChange={(e) => {
                                    const kind = e.target.value as Kind;
                                    // Another kind: its category and any invoice it carried go.
                                    update(i, {
                                        kind,
                                        category_id: kind === 'business_income' && moneyIn ? turnoverId(lookups) : null,
                                        invoice_id: null,
                                        invoice_number: null,
                                        deduction: false,
                                    });
                                }}
                                aria-label={`Line ${i + 1}: type`}
                            >
                                {lookups.kinds.map((k) => (
                                    <option key={k.value} value={k.value}>
                                        {k.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                inputMode="decimal"
                                value={line.amount}
                                disabled={disabled}
                                onChange={(e) => update(i, { amount: e.target.value })}
                                aria-label={`Line ${i + 1}: amount`}
                                className="h-11 w-24 shrink-0 text-right tabular-nums"
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-11 shrink-0"
                                aria-label={`Remove line ${i + 1}`}
                                disabled={disabled || lines.length === 1}
                                onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        {business && (
                            <CategorySelect
                                lookups={lookups}
                                kind={line.kind}
                                value={line.category_id}
                                onChange={(v) => update(i, { category_id: v })}
                                className="h-11"
                            />
                        )}
                        {income && line.invoice_id && (
                            <p className="flex items-center gap-2 text-sm">
                                <span className="rounded-full border border-sky-600 bg-sky-50 px-2.5 py-1 text-sky-800 dark:bg-sky-950 dark:text-sky-200">
                                    Pays {line.invoice_number ?? 'an invoice'}
                                    {line.client ? ` · ${line.client}` : ''}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-11"
                                    disabled={disabled}
                                    aria-label={`Line ${i + 1}: not for ${line.invoice_number ?? 'that invoice'}`}
                                    onClick={() => update(i, { invoice_id: null, invoice_number: null })}
                                >
                                    <X />
                                </Button>
                            </p>
                        )}
                        {income && moneyIn && !line.invoice_id && (
                            <ClientSelect
                                clients={lookups.clients}
                                value={line.client_id}
                                name={line.client}
                                allowNone
                                disabled={disabled}
                                placeholder={line.work_job_id && line.client ? `${line.client} (from the job)` : 'Who is it from? (optional)'}
                                onChange={(c) => update(i, { client_id: c?.id ?? null, client: c?.name ?? null })}
                            />
                        )}
                        {business &&
                            !line.deduction &&
                            (income && moneyIn ? (
                                <JobCombobox
                                    value={line.work_job_id}
                                    label={line.job}
                                    known={lookups.jobs}
                                    client={line.client_id}
                                    on={on}
                                    forIncome
                                    disabled={disabled}
                                    noneLabel="No job"
                                    onChange={(j) =>
                                        // Another job: an invoice the line carried goes with the old one.
                                        update(i, {
                                            work_job_id: j?.id ?? null,
                                            job: j?.title ?? null,
                                            invoice_id: null,
                                            invoice_number: null,
                                            ...(j?.client_id ? { client: j.client } : {}),
                                        })
                                    }
                                />
                            ) : (
                                <JobSelect
                                    lookups={lookups}
                                    near={near}
                                    value={line.work_job_id}
                                    className="h-11"
                                    onChange={(v) => update(i, { work_job_id: v, invoice_id: null, invoice_number: null })}
                                />
                            ))}
                        {moneyIn && line.kind === 'business_expense' && (
                            <div className="space-y-1">
                                <label className="flex min-h-11 items-center gap-3 text-sm">
                                    <Checkbox
                                        checked={line.deduction}
                                        disabled={disabled}
                                        onCheckedChange={(v) =>
                                            update(i, {
                                                deduction: !!v,
                                                ...(v ? { work_job_id: null, category_id: line.category_id ?? feeCategoryId(lookups) } : {}),
                                            })
                                        }
                                    />
                                    Deduction: a fee or commission they took off the payment
                                </label>
                                {line.deduction && deductionNote && <p className="text-muted-foreground text-xs">{deductionNote}</p>}
                            </div>
                        )}
                    </div>
                );
            })}
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Button variant="outline" className="h-11" disabled={disabled || left === 0 || (!moneyIn && left < 0)} onClick={addLine}>
                    <Plus /> {moneyIn && left < 0 ? 'Add the fee taken off' : 'Add line'}
                </Button>
                <span className={cn(left === 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400')}>
                    {left === 0 ? 'Adds up exactly' : left > 0 ? `${money(left)} still to put on a line` : `${money(-left)} too much`}
                </span>
                <span className="text-muted-foreground ml-auto">
                    Total <Money pence={amount} />
                </span>
            </div>
            {problem && left === 0 && (
                <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                    {problem}
                </p>
            )}
            <Button className="h-11 w-full sm:w-auto" disabled={disabled || busy || problem !== null} onClick={() => onSave(out)}>
                Save split
            </Button>
        </div>
    );
}
