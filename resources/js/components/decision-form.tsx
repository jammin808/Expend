import { ClientSelect } from '@/components/client-select';
import { Explain } from '@/components/explain';
import { JobCombobox } from '@/components/job-combobox';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { turnoverId } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type Allocation, type Decision, type JobRef, type Kind, type Lookups } from '@/types';
import { X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const quickKinds: { value: Kind; label: string; key: string }[] = [
    { value: 'business_expense', label: 'Business expense', key: 'B' },
    { value: 'business_income', label: 'Business income', key: 'I' },
    { value: 'personal', label: 'Personal', key: 'P' },
    { value: 'transfer', label: 'Transfer', key: 'T' },
];

/**
 * The decision a payment's lines show now, to start from: its business line (kind, category, job, business share and
 * note), with the invoice and client that line names. Money in with nothing yet starts as turnover.
 */
export function initialDecision(amount: number, allocations: Allocation[], lookups?: Lookups): Decision {
    const business = allocations.find((a) => a.kind === 'business_expense' || a.kind === 'business_income');
    const first = business ?? allocations[0];
    if (!first)
        return {
            kind: amount < 0 ? 'business_expense' : 'business_income',
            category_id: amount > 0 && lookups ? turnoverId(lookups) : null,
            work_job_id: null,
            business_pct: 100,
        };
    return {
        kind: first.kind,
        category_id: first.category_id,
        work_job_id: first.work_job_id,
        business_pct: business?.business_use_pct ?? 100,
        note: first.note,
        ...(first.invoice_id ? { invoice_id: first.invoice_id } : {}),
        ...(first.client_id ? { client_id: first.client_id } : {}),
    };
}

export function CategorySelect({
    lookups,
    kind,
    value,
    onChange,
    id,
    className,
    both = false,
    placeholder = 'Choose a category…',
    'aria-label': ariaLabel,
}: {
    lookups: Lookups;
    kind: Kind;
    value: number | null | undefined;
    onChange: (v: number | null) => void;
    id?: string;
    className?: string;
    /** Income and expense categories together (a filter), income first. */
    both?: boolean;
    placeholder?: string;
    'aria-label'?: string;
}) {
    const wanted = kind === 'business_income' ? 'income' : 'expense';
    const groups = useMemo(() => {
        const map = new Map<string, typeof lookups.categories>();
        lookups.categories
            .filter((c) => both || c.kind === wanted)
            .sort((a, b) => (both ? Number(a.kind !== 'income') - Number(b.kind !== 'income') : 0))
            .forEach((c) =>
                map.set(c.kind === 'income' && both ? 'Income' : c.group, [...(map.get(c.kind === 'income' && both ? 'Income' : c.group) ?? []), c]),
            );
        return [...map.entries()];
    }, [lookups.categories, wanted, both]);

    return (
        <NativeSelect
            id={id}
            aria-label={ariaLabel}
            className={className}
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
        >
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
    className,
}: {
    lookups: Lookups;
    near?: JobRef[];
    value: number | null | undefined;
    onChange: (v: number | null) => void;
    id?: string;
    className?: string;
}) {
    const nearIds = new Set(near.map((j) => j.id));
    return (
        <NativeSelect id={id} className={className} value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
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
    /** The payment's date: the job search starts nearest it. */
    on?: string | null;
    /** What the payment's lines say now, by name: the job and the client it counts for. */
    jobName?: string | null;
    clientName?: string | null;
    /** The invoice the decision pays, by number, when it names one. */
    invoiceName?: string | null;
    /** Money in: pick a client and search every job (true unless a page decides many payments at once without a client). */
    incomePickers?: boolean;
    disabled?: boolean;
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
    on,
    jobName,
    clientName,
    invoiceName,
    incomePickers = true,
    disabled,
}: Props) {
    const [moreKinds, setMoreKinds] = useState(!quickKinds.some((k) => k.value === value.kind));
    // The picked job's client, by name: income on a job counts for the job's client.
    const [jobClient, setJobClient] = useState<string | null>(value.work_job_id ? (clientName ?? null) : null);
    const [jobTitle, setJobTitle] = useState<string | null>(jobName ?? null);
    const needsCategory = value.kind === 'business_expense' || value.kind === 'business_income';
    const income = amount > 0 && value.kind === 'business_income' && incomePickers;

    useEffect(() => {
        if (!quickKinds.some((k) => k.value === value.kind)) setMoreKinds(true);
    }, [value.kind]);

    const set = (patch: Partial<Decision>) => onChange({ ...value, ...patch });

    /** Another kind: its category goes (money in's income starts as turnover), and so does an invoice it carried. */
    const setKind = (kind: Kind) => {
        if (kind === value.kind) return;
        set({
            kind,
            category_id: kind === 'business_income' && amount > 0 ? turnoverId(lookups) : null,
            ...(value.invoice_id !== undefined ? { invoice_id: null } : {}),
        });
    };

    // An invoice or several lines can't be given to every payment from there: "Apply to all" is for one plain decision.
    const canApplyToAll = merchantCount > 1 && !!onApplyToMerchant && !value.invoice_id;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="What is it?">
                {quickKinds.map((k) => (
                    <button
                        key={k.value}
                        type="button"
                        role="radio"
                        aria-checked={value.kind === k.value}
                        disabled={disabled}
                        onClick={() => setKind(k.value)}
                        className={cn(
                            'min-h-10 rounded-full border px-3 py-1.5 text-sm transition-colors disabled:opacity-50',
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
                    disabled={disabled}
                    onClick={() => setMoreKinds(!moreKinds)}
                    className="text-muted-foreground hover:bg-muted min-h-10 rounded-full border px-3 py-1.5 text-sm"
                >
                    More…
                </button>
            </div>

            {moreKinds && (
                <NativeSelect
                    className="h-11"
                    value={value.kind}
                    disabled={disabled}
                    onChange={(e) => setKind(e.target.value as Kind)}
                    aria-label="Other types"
                >
                    {lookups.kinds.map((k) => (
                        <option key={k.value} value={k.value}>
                            {k.label}
                        </option>
                    ))}
                </NativeSelect>
            )}

            {value.kind === 'business_income' && value.invoice_id ? (
                // What Save saves, said before you press it: this payment pays that invoice.
                <p className="flex items-center gap-2 text-sm">
                    <span className="rounded-full border border-sky-600 bg-sky-50 px-3 py-1.5 text-sky-900 dark:bg-sky-950 dark:text-sky-100">
                        Pays {invoiceName ?? 'an invoice'}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        disabled={disabled}
                        aria-label={`Not for ${invoiceName ?? 'that invoice'}`}
                        onClick={() => set({ invoice_id: null })}
                    >
                        <X />
                    </Button>
                </p>
            ) : null}

            {needsCategory && (
                <div className="grid gap-3 sm:grid-cols-2">
                    {income && (
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="decision-client">Who is it from?</Label>
                            <ClientSelect
                                id="decision-client"
                                clients={lookups.clients}
                                value={value.client_id}
                                name={clientName}
                                allowNone
                                disabled={disabled}
                                placeholder={value.work_job_id && jobClient ? `${jobClient} (from the job)` : 'Pick the client'}
                                onChange={(c) => set({ client_id: c?.id ?? null })}
                            />
                        </div>
                    )}
                    <div className="space-y-1.5">
                        <Label htmlFor="decision-category" className="flex items-center gap-1.5">
                            HMRC category
                            {value.category_id ? (
                                <Explain
                                    key={value.category_id}
                                    url={`/explain/category/${value.category_id}`}
                                    label="What counts in this category?"
                                />
                            ) : null}
                        </Label>
                        <CategorySelect
                            id="decision-category"
                            className="h-11"
                            lookups={lookups}
                            kind={value.kind}
                            value={value.category_id}
                            onChange={(v) => set({ category_id: v })}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="decision-job">Job</Label>
                        {income ? (
                            <JobCombobox
                                id="decision-job"
                                value={value.work_job_id}
                                label={jobTitle}
                                known={lookups.jobs}
                                client={value.client_id ?? null}
                                on={on}
                                forIncome
                                disabled={disabled}
                                noneLabel="No job"
                                onChange={(j) => {
                                    setJobTitle(j?.title ?? null);
                                    setJobClient(j?.client ?? null);
                                    // Another job: an invoice the decision carried goes with the old one.
                                    set({
                                        work_job_id: j?.id ?? null,
                                        ...(value.invoice_id !== undefined ? { invoice_id: null } : {}),
                                        ...(j?.client_id ? { client_id: j.client_id } : {}),
                                    });
                                }}
                            />
                        ) : (
                            <JobSelect
                                id="decision-job"
                                className="h-11"
                                lookups={lookups}
                                near={near}
                                value={value.work_job_id}
                                onChange={(v) => set({ work_job_id: v, ...(value.invoice_id !== undefined ? { invoice_id: null } : {}) })}
                            />
                        )}
                        {income && value.client_id && !value.work_job_id && (
                            <p className="text-muted-foreground text-xs">A job is optional: with the client set, this is done.</p>
                        )}
                    </div>
                    {value.kind === 'business_expense' && (
                        <div className="space-y-1.5">
                            <Label htmlFor="decision-pct">Business use</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="decision-pct"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={100}
                                    className="h-11 w-24"
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
                    className="h-11"
                    value={value.note ?? ''}
                    onChange={(e) => set({ note: e.target.value })}
                    placeholder="For example: client lunch, kit for the Leeds show"
                />
            </div>

            <div className="flex flex-col gap-1 text-sm">
                {canApplyToAll && (
                    <label className="flex min-h-10 items-center gap-3">
                        <Checkbox checked={!!applyToMerchant} onCheckedChange={(v) => onApplyToMerchant?.(!!v)} />
                        Apply to all {merchantCount} undecided from {merchant ?? 'this merchant'}
                    </label>
                )}
                {showRule && (
                    <label className="flex min-h-10 items-center gap-3">
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
