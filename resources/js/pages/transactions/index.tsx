import { CategorySelect, DecisionForm, decisionProblem } from '@/components/decision-form';
import { ItemDrawer } from '@/components/item-drawer';
import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { StatusPill } from '@/components/status-pill';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { day, decidedByLabels, kindLabels, money } from '@/lib/format';
import { type Decision, type Item, type Lookups, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    page: { data: Item[]; current_page: number; last_page: number; total: number; links: { prev: string | null; next: string | null } };
    totals: { count: number; out_pence: number; in_pence: number };
    manual: Item[];
    filters: Record<string, string | undefined>;
    lookups: Lookups;
}

function summary(item: Item): string {
    if (item.allocations.length === 0) return '';
    if (item.allocations.length > 1) return `Split into ${item.allocations.length}`;
    const a = item.allocations[0];
    return [kindLabels[a.kind], a.category, a.job ?? (a.kind === 'business_expense' ? 'General business' : null)].filter(Boolean).join(' · ');
}

function ManualExpenseDialog({ open, onClose, lookups }: { open: boolean; onClose: () => void; lookups: Lookups }) {
    const form = useForm({
        spent_on: new Date().toISOString().slice(0, 10),
        description: '',
        direction: 'out',
        amount: '',
        currency: 'GBP',
        sterling_from_document: '',
        manual_rate: '',
        paid_with: 'cash',
        kind: 'business_expense',
        category_id: null as number | null,
        work_job_id: null as number | null,
        business_pct: 100,
        note: '',
    });
    const [rate, setRate] = useState<{ text: string; ok: boolean } | null>(null);
    const d = form.data;

    useEffect(() => {
        if (d.currency === 'GBP' || !d.spent_on || d.sterling_from_document) {
            setRate(null);
            return;
        }
        const ctrl = new AbortController();
        const q = new URLSearchParams({ currency: d.currency, date: d.spent_on, amount: d.amount || '0' });
        fetch(`/fx/rate?${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: ctrl.signal })
            .then((r) => r.json())
            .then((j) =>
                setRate(
                    j.ok
                        ? {
                              ok: true,
                              text: `HMRC rate for ${j.rate.period_start.slice(0, 7)}: ${j.rate.units_per_gbp} ${d.currency} per £1${j.pence ? ` → ${money(Math.abs(j.pence))}` : ''}`,
                          }
                        : { ok: false, text: j.message },
                ),
            )
            .catch(() => {});
        return () => ctrl.abort();
    }, [d.currency, d.spent_on, d.amount, d.sterling_from_document]);

    const decision: Decision = {
        kind: d.kind as Decision['kind'],
        category_id: d.category_id,
        work_job_id: d.work_job_id,
        business_pct: d.business_pct,
        note: d.note,
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add cash or foreign-currency item</DialogTitle>
                    <DialogDescription>
                        For anything that didn't go through an imported account. Other currencies use the document's sterling figure if it has one,
                        otherwise HMRC's monthly rate.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const problem = decisionProblem(decision);
                        if (problem) return toast(problem, 'error');
                        form.post('/expenses', { preserveScroll: true, onSuccess: () => (form.reset(), onClose()) });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Date</Label>
                            <Input type="date" value={d.spent_on} onChange={(e) => form.setData('spent_on', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Paid with</Label>
                            <NativeSelect value={d.paid_with} onChange={(e) => form.setData('paid_with', e.target.value)}>
                                <option value="cash">Cash</option>
                                <option value="personal_card">A personal card not imported here</option>
                                <option value="other">Other</option>
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Description</Label>
                            <Input
                                value={d.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="For example: Taxi in Berlin, Hilton Lyon"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Amount</Label>
                            <div className="flex gap-2">
                                <NativeSelect className="w-24" value={d.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                                    <option value="out">Spent</option>
                                    <option value="in">Received</option>
                                </NativeSelect>
                                <Input inputMode="decimal" value={d.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Currency</Label>
                            <NativeSelect value={d.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                {['GBP', 'USD', 'EUR', 'CHF', 'CAD', 'AUD', 'NOK', 'SEK', 'DKK', 'JPY', 'AED'].map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </NativeSelect>
                        </div>
                        {d.currency !== 'GBP' && (
                            <>
                                <div className="space-y-1.5">
                                    <Label>Sterling amount on the document (if shown)</Label>
                                    <Input
                                        inputMode="decimal"
                                        value={d.sterling_from_document}
                                        onChange={(e) => form.setData('sterling_from_document', e.target.value)}
                                        placeholder="£"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Or your own rate ({d.currency} per £1)</Label>
                                    <Input
                                        inputMode="decimal"
                                        value={d.manual_rate}
                                        onChange={(e) => form.setData('manual_rate', e.target.value)}
                                        placeholder="Leave empty for HMRC's rate"
                                    />
                                </div>
                                {rate && (
                                    <p className={`text-sm sm:col-span-2 ${rate.ok ? 'text-muted-foreground' : 'text-amber-700'}`}>{rate.text}</p>
                                )}
                            </>
                        )}
                    </div>
                    <DecisionForm
                        amount={d.direction === 'out' ? -1 : 1}
                        lookups={lookups}
                        value={decision}
                        showRule={false}
                        onChange={(x) =>
                            form.setData({
                                ...d,
                                kind: x.kind,
                                category_id: x.category_id ?? null,
                                work_job_id: x.work_job_id ?? null,
                                business_pct: x.business_pct ?? 100,
                                note: x.note ?? '',
                            })
                        }
                    />
                    <Button disabled={form.processing}>Add</Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Transactions({ page, totals, manual, filters, lookups }: Props) {
    const { nav } = usePage<SharedData>().props;
    const [selected, setSelected] = useState<number[]>([]);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const [bulk, setBulk] = useState(false);
    const [bulkDecision, setBulkDecision] = useState<Decision>({ kind: 'business_expense', business_pct: 100 });
    const [adding, setAdding] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (patch: Record<string, string | undefined>) => {
        const next: Record<string, string | undefined> = { ...filters, ...patch };
        delete next.page;
        Object.keys(next).forEach((k) => (next[k] === '' || next[k] === undefined) && delete next[k]);
        router.get('/transactions', next as never, { preserveState: true, preserveScroll: true, replace: true });
        setSelected([]);
    };

    const all = page.data.length > 0 && selected.length === page.data.length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Transactions', href: '/transactions' }]}>
            <Head title="Transactions" />
            <div className="mx-auto w-full max-w-7xl space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Transactions</h1>
                        <p className="text-muted-foreground text-sm">
                            {totals.count.toLocaleString()} matching · out {money(totals.out_pence)} · in {money(totals.in_pence)}
                        </p>
                    </div>
                    <Button variant="outline" onClick={() => setAdding(true)}>
                        <Plus /> Cash or foreign item
                    </Button>
                </div>

                <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-7">
                    <form
                        className="sm:col-span-3 lg:col-span-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q });
                        }}
                    >
                        <Input placeholder="Search description or merchant" value={q} onChange={(e) => setQ(e.target.value)} />
                    </form>
                    <NativeSelect value={filters.year ?? ''} onChange={(e) => apply({ year: e.target.value })} aria-label="Tax year">
                        <option value="">All years</option>
                        {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                    </NativeSelect>
                    <NativeSelect value={filters.account ?? ''} onChange={(e) => apply({ account: e.target.value })} aria-label="Account">
                        <option value="">All accounts</option>
                        {lookups.accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value })} aria-label="Status">
                        <option value="">Any status</option>
                        <option value="unreviewed">To decide</option>
                        <option value="suggested">Suggested</option>
                        <option value="confirmed">Confirmed</option>
                    </NativeSelect>
                    <NativeSelect value={filters.kind ?? ''} onChange={(e) => apply({ kind: e.target.value })} aria-label="Type">
                        <option value="">Any type</option>
                        {lookups.kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.decided_by ?? ''} onChange={(e) => apply({ decided_by: e.target.value })} aria-label="Decided by">
                        <option value="">Decided by anyone</option>
                        <option value="you">You</option>
                        <option value="rule">Your rules</option>
                        <option value="matcher">Matching</option>
                        <option value="ai">AI</option>
                    </NativeSelect>
                    <CategorySelect
                        placeholder="Any category"
                        lookups={lookups}
                        kind="business_expense"
                        value={filters.category ? Number(filters.category) : null}
                        onChange={(v) => apply({ category: v ? String(v) : '' })}
                    />
                    <NativeSelect value={filters.job ?? ''} onChange={(e) => apply({ job: e.target.value })} aria-label="Job">
                        <option value="">Any job</option>
                        <option value="none">Business, no job</option>
                        {lookups.jobs.map((j) => (
                            <option key={j.id} value={j.id}>
                                {j.title}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.direction ?? ''} onChange={(e) => apply({ direction: e.target.value })} aria-label="Direction">
                        <option value="">Money in and out</option>
                        <option value="out">Money out</option>
                        <option value="in">Money in</option>
                    </NativeSelect>
                    <NativeSelect value={filters.sort ?? 'date'} onChange={(e) => apply({ sort: e.target.value })} aria-label="Sort">
                        <option value="date">Newest first</option>
                        <option value="amount">Largest first</option>
                    </NativeSelect>
                </div>

                {selected.length > 0 && (
                    <div className="bg-muted sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg p-2 text-sm shadow-sm">
                        <span className="px-2 font-medium">{selected.length} selected</span>
                        <Button size="sm" onClick={() => setBulk(true)}>
                            Decide selected
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post('/decisions/confirm', { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) })
                            }
                        >
                            Accept suggestions
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                            Clear selection
                        </Button>
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="w-8 p-2">
                                    <Checkbox
                                        checked={all}
                                        onCheckedChange={(v) => setSelected(v ? page.data.map((t) => t.id) : [])}
                                        aria-label="Select all"
                                    />
                                </th>
                                <th className="p-2">Date</th>
                                <th className="p-2">Description</th>
                                <th className="hidden p-2 md:table-cell">Account</th>
                                <th className="p-2 text-right">Amount</th>
                                <th className="hidden p-2 lg:table-cell">Decision</th>
                                <th className="p-2">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {page.data.map((t) => (
                                <tr key={t.id} className="hover:bg-muted/40 cursor-pointer" onClick={() => setDrawer({ source: 'bank', id: t.id })}>
                                    <td className="p-2" onClick={(e) => e.stopPropagation()}>
                                        <Checkbox
                                            checked={selected.includes(t.id)}
                                            onCheckedChange={(v) => setSelected((s) => (v ? [...s, t.id] : s.filter((x) => x !== t.id)))}
                                            aria-label="Select"
                                        />
                                    </td>
                                    <td className="p-2 whitespace-nowrap tabular-nums">{day(t.date)}</td>
                                    <td className="max-w-80 p-2">
                                        <p className="truncate">{t.description}</p>
                                        {t.original_currency && (
                                            <p className="text-muted-foreground text-xs">{money(t.original_amount_minor, t.original_currency)}</p>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground hidden p-2 whitespace-nowrap md:table-cell">{t.account?.name}</td>
                                    <td className="p-2 text-right">
                                        <Money pence={t.amount_pence} />
                                    </td>
                                    <td className="text-muted-foreground hidden max-w-72 p-2 lg:table-cell">
                                        <p className="truncate">{summary(t)}</p>
                                        {t.allocations[0] && <p className="text-xs">by {decidedByLabels[t.allocations[0].decided_by]}</p>}
                                    </td>
                                    <td className="p-2">
                                        <StatusPill status={t.review_status} />
                                    </td>
                                </tr>
                            ))}
                            {page.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-8 text-center">
                                        Nothing matches these filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        Page {page.current_page} of {page.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!page.links.prev}
                            onClick={() => page.links.prev && router.get(page.links.prev, {}, { preserveScroll: true })}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!page.links.next}
                            onClick={() => page.links.next && router.get(page.links.next, {}, { preserveScroll: true })}
                        >
                            Next
                        </Button>
                    </div>
                </div>

                {manual.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Cash and foreign-currency items</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {manual.map((m) => (
                                <li
                                    key={m.id}
                                    className="hover:bg-muted/40 flex cursor-pointer items-center justify-between gap-3 p-3"
                                    onClick={() => setDrawer({ source: 'manual', id: m.id })}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate">{m.description}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {day(m.date)} · {summary(m)}
                                            {m.original_currency ? ` · ${money(m.original_amount_minor, m.original_currency)}` : ''}
                                        </span>
                                    </span>
                                    <Money pence={m.amount_pence} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <ItemDrawer
                target={drawer}
                lookups={lookups}
                onClose={() => {
                    setDrawer(null);
                    router.reload({ only: ['page', 'totals', 'manual', 'nav'] });
                }}
            />
            <ManualExpenseDialog open={adding} onClose={() => setAdding(false)} lookups={lookups} />

            <Dialog open={bulk} onOpenChange={setBulk}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Decide {selected.length} items</DialogTitle>
                        <DialogDescription>
                            This replaces any current decision on the selected items. Each change is logged and can be changed again later.
                        </DialogDescription>
                    </DialogHeader>
                    <DecisionForm amount={-1} lookups={lookups} value={bulkDecision} onChange={setBulkDecision} showRule={false} />
                    <Button
                        onClick={() => {
                            const problem = decisionProblem(bulkDecision);
                            if (problem) return toast(problem, 'error');
                            router.post('/decisions/bulk', { ...bulkDecision, ids: selected } as never, {
                                preserveScroll: true,
                                onSuccess: () => (setBulk(false), setSelected([])),
                            });
                        }}
                    >
                        Save for {selected.length}
                    </Button>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
