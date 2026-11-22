import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type Allocation, type Decision, type JobRef, type Kind, type Lookups } from '@/types';
import { useEffect, useMemo, useState } from 'react';

const quickKinds: { value: Kind; label: string; key: string }[] = [
    { value: 'business_expense', label: 'Business expense', key: 'B' },
    { value: 'business_income', label: 'Business income', key: 'I' },
    { value: 'personal', label: 'Personal', key: 'P' },
    { value: 'transfer', label: 'Transfer', key: 'T' },
];

export function initialDecision(amount: number, allocations: Allocation[]): Decision {
    const business = allocations.find((a) => a.kind === 'business_expense' || a.kind === 'business_income');
    const first = business ?? allocations[0];
    if (!first) return { kind: amount < 0 ? 'business_expense' : 'business_income', category_id: null, work_job_id: null, business_pct: 100 };
    return {
        kind: first.kind,
        category_id: first.category_id,
        work_job_id: first.work_job_id,
        business_pct: business?.business_use_pct ?? 100,
        note: first.note,
    };
}

export function CategorySelect({
    lookups,
    kind,
    value,
    onChange,
    id,
    placeholder = 'Choose a category…',
}: {
    lookups: Lookups;
    kind: Kind;
    value: number | null | undefined;
    onChange: (v: number | null) => void;
    id?: string;
    placeholder?: string;
}) {
    const wanted = kind === 'business_income' ? 'income' : 'expense';
    const groups = useMemo(() => {
        const map = new Map<string, typeof lookups.categories>();
        lookups.categories.filter((c) => c.kind === wanted).forEach((c) => map.set(c.group, [...(map.get(c.group) ?? []), c]));
        return [...map.entries()];
    }, [lookups.categories, wanted]);

    return (
        <NativeSelect id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            <option value="">{placeholder}</option>
            {groups.map(([group, cats]) => (
                <optgroup key={group} label={group}>
                    {cats.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.is_top ? `${c.name} (general)` : c.name}
                            {!c.allowable ? ' · not allowable' : ''}
                            {c.capital ? ' · capital item' : ''}
                        </option>
                    ))}
                </optgroup>
            ))}
        </NativeSelect>
    );
}

export function JobSelect({
    lookups,
    near = [],
    value,
    onChange,
    id,
}: {
    lookups: Lookups;
    near?: JobRef[];
    value: number | null | undefined;
    onChange: (v: number | null) => void;
    id?: string;
}) {
    const nearIds = new Set(near.map((j) => j.id));
    return (
        <NativeSelect id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            <option value="">General business (no job)</option>
            {near.length > 0 && (
                <optgroup label="Around this date">
                    {near.map((j) => (
                        <option key={j.id} value={j.id}>
                            {j.title}
                            {j.client ? ` · ${j.client}` : ''}
                        </option>
                    ))}
                </optgroup>
            )}
            <optgroup label="All jobs">
                {lookups.jobs
                    .filter((j) => !nearIds.has(j.id))
                    .map((j) => (
                        <option key={j.id} value={j.id}>
                            {j.title}
                            {j.client ? ` · ${j.client}` : ''} · {j.starts_on}
                        </option>
                    ))}
            </optgroup>
        </NativeSelect>
    );
}

interface Props {
    amount: number;
    lookups: Lookups;
    value: Decision;
    onChange: (d: Decision) => void;
    near?: JobRef[];
    merchantCount?: number;
    merchant?: string;
    applyToMerchant?: boolean;
    onApplyToMerchant?: (v: boolean) => void;
    showRule?: boolean;
    autoFocusCategory?: boolean;
}

/** Choose what an item is: the core of every review screen. */
export function DecisionForm({
    amount,
    lookups,
    value,
    onChange,
    near = [],
    merchantCount = 1,
    merchant,
    applyToMerchant,
    onApplyToMerchant,
    showRule = true,
}: Props) {
    const [moreKinds, setMoreKinds] = useState(!quickKinds.some((k) => k.value === value.kind));
    const needsCategory = value.kind === 'business_expense' || value.kind === 'business_income';

    useEffect(() => {
        if (!quickKinds.some((k) => k.value === value.kind)) setMoreKinds(true);
    }, [value.kind]);

    const set = (patch: Partial<Decision>) => onChange({ ...value, ...patch });

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="What is it?">
                {quickKinds.map((k) => (
                    <button
                        key={k.value}
                        type="button"
                        role="radio"
                        aria-checked={value.kind === k.value}
                        onClick={() => set({ kind: k.value, category_id: value.kind === k.value ? value.category_id : null })}
                        className={cn(
                            'rounded-full border px-3 py-1.5 text-sm transition-colors',
                            value.kind === k.value
                                ? 'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900'
                                : 'hover:bg-muted',
                        )}
                    >
                        {k.label}
                        <kbd className="ml-1.5 hidden text-xs opacity-50 md:inline">{k.key}</kbd>
                    </button>
                ))}
                <button
                    type="button"
                    onClick={() => setMoreKinds(!moreKinds)}
                    className="text-muted-foreground hover:bg-muted rounded-full border px-3 py-1.5 text-sm"
                >
                    More…
                </button>
            </div>

            {moreKinds && (
                <NativeSelect value={value.kind} onChange={(e) => set({ kind: e.target.value as Kind, category_id: null })} aria-label="Other types">
                    {lookups.kinds.map((k) => (
                        <option key={k.value} value={k.value}>
                            {k.label}
                        </option>
                    ))}
                </NativeSelect>
            )}

            {needsCategory && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="decision-category">HMRC category</Label>
                        <CategorySelect
                            id="decision-category"
                            lookups={lookups}
                            kind={value.kind}
                            value={value.category_id}
                            onChange={(v) => set({ category_id: v })}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="decision-job">Job</Label>
                        <JobSelect
                            id="decision-job"
                            lookups={lookups}
                            near={near}
                            value={value.work_job_id}
                            onChange={(v) => set({ work_job_id: v })}
                        />
                    </div>
                    {value.kind === 'business_expense' && (
                        <div className="space-y-1.5">
                            <Label htmlFor="decision-pct">Business use</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="decision-pct"
                                    type="number"
                                    min={1}
                                    max={100}
                                    className="w-24"
                                    value={value.business_pct ?? 100}
                                    onChange={(e) => set({ business_pct: Math.max(1, Math.min(100, Number(e.target.value) || 100)) })}
                                />
                                <span className="text-muted-foreground text-sm">% (the rest is personal)</span>
                            </div>
                        </div>
                    )}
                </div>
            )}

            <div className="space-y-1.5">
                <Label htmlFor="decision-note">Note (optional)</Label>
                <Input
                    id="decision-note"
                    value={value.note ?? ''}
                    onChange={(e) => set({ note: e.target.value })}
                    placeholder="For example: client lunch, kit for the Leeds show"
                />
            </div>

            <div className="flex flex-col gap-2 text-sm">
                {merchantCount > 1 && onApplyToMerchant && (
                    <label className="flex items-center gap-2">
                        <Checkbox checked={!!applyToMerchant} onCheckedChange={(v) => onApplyToMerchant(!!v)} />
                        Apply to all {merchantCount} undecided from {merchant ?? 'this merchant'}
                    </label>
                )}
                {showRule && (
                    <label className="flex items-center gap-2">
                        <Checkbox checked={!!value.make_rule} onCheckedChange={(v) => set({ make_rule: !!v })} />
                        Suggest this next time for {merchant ?? 'this merchant'}
                    </label>
                )}
            </div>
            {amount === 0 && <p className="text-sm text-rose-600">This item has no amount.</p>}
        </div>
    );
}

export function decisionProblem(d: Decision): string | null {
    if ((d.kind === 'business_expense' || d.kind === 'business_income') && !d.category_id) return 'Choose an HMRC category.';
    return null;
}

export function SubmitRow({ busy, onSave, onCancel, label = 'Save' }: { busy?: boolean; onSave: () => void; onCancel?: () => void; label?: string }) {
    return (
        <div className="flex gap-2">
            <Button onClick={onSave} disabled={busy}>
                {label}
            </Button>
            {onCancel && (
                <Button variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
            )}
        </div>
    );
}
