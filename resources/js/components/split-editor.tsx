import { CategorySelect, JobSelect } from '@/components/decision-form';
import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { money, toPence } from '@/lib/format';
import { type Allocation, type JobRef, type Kind, type Lookups } from '@/types';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export interface Line {
    kind: Kind;
    category_id: number | null;
    work_job_id: number | null;
    amount: string;
    note?: string | null;
}

function fromAllocations(amount: number, allocations: Allocation[]): Line[] {
    if (allocations.length === 0)
        return [
            { kind: amount < 0 ? 'business_expense' : 'business_income', category_id: null, work_job_id: null, amount: (amount / 100).toFixed(2) },
        ];
    return allocations.map((a) => ({
        kind: a.kind,
        category_id: a.category_id,
        work_job_id: a.work_job_id,
        amount: (a.amount_pence / 100).toFixed(2),
        note: a.note,
    }));
}

/** Split one transaction across jobs, categories or business and personal. Lines must add up exactly. */
export function SplitEditor({
    amount,
    allocations,
    lookups,
    near,
    busy,
    onSave,
}: {
    amount: number;
    allocations: Allocation[];
    lookups: Lookups;
    near?: JobRef[];
    busy?: boolean;
    onSave: (lines: { kind: Kind; category_id: number | null; work_job_id: number | null; amount_pence: number; note?: string | null }[]) => void;
}) {
    const [lines, setLines] = useState<Line[]>(() => fromAllocations(amount, allocations));
    const parsed = lines.map((l) => toPence(l.amount));
    const total = parsed.reduce<number>((s, p) => s + (p ?? 0), 0);
    const remaining = amount - total;
    const invalid =
        parsed.some((p) => p === null || p === 0) ||
        lines.some((l) => (l.kind === 'business_expense' || l.kind === 'business_income') && !l.category_id);

    const update = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    return (
        <div className="space-y-3">
            {lines.map((line, i) => (
                <div key={i} className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1fr_7rem_auto]">
                    <NativeSelect
                        value={line.kind}
                        onChange={(e) => update(i, { kind: e.target.value as Kind, category_id: null })}
                        aria-label="Type"
                    >
                        {lookups.kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </NativeSelect>
                    {line.kind === 'business_expense' || line.kind === 'business_income' ? (
                        <>
                            <CategorySelect
                                lookups={lookups}
                                kind={line.kind}
                                value={line.category_id}
                                onChange={(v) => update(i, { category_id: v })}
                            />
                            <JobSelect lookups={lookups} near={near} value={line.work_job_id} onChange={(v) => update(i, { work_job_id: v })} />
                        </>
                    ) : (
                        <div className="sm:col-span-2" />
                    )}
                    <Input
                        inputMode="decimal"
                        value={line.amount}
                        onChange={(e) => update(i, { amount: e.target.value })}
                        aria-label="Amount"
                        className="tabular-nums"
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Remove line"
                        disabled={lines.length === 1}
                        onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        setLines((ls) => [...ls, { kind: 'personal', category_id: null, work_job_id: null, amount: (remaining / 100).toFixed(2) }])
                    }
                >
                    <Plus /> Add line
                </Button>
                <span className={remaining === 0 ? 'text-emerald-700' : 'text-rose-600'}>
                    {remaining === 0 ? 'Adds up exactly' : `${money(remaining, 'GBP')} still to allocate`}
                </span>
                <span className="text-muted-foreground ml-auto">
                    Total <Money pence={amount} />
                </span>
            </div>
            <Button
                disabled={busy || remaining !== 0 || invalid}
                onClick={() =>
                    onSave(
                        lines.map((l, i) => ({
                            kind: l.kind,
                            category_id: l.category_id,
                            work_job_id: l.work_job_id,
                            amount_pence: parsed[i] as number,
                            note: l.note,
                        })),
                    )
                }
            >
                Save split
            </Button>
        </div>
    );
}
