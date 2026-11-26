import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { ClientSelect } from '@/components/client-select';
import { CategorySelect, DecisionForm, decisionProblem } from '@/components/decision-form';
import { Explain } from '@/components/explain';
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
import { day, decidedByLabels, kindLabels, londonToday, money, moneyMinor, movedBetweenAccounts, paidIn, paidWith, rateText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type ClientRef, type Decision, type Item, type Lookups, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Camera, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    page: { data: Item[]; current_page: number; last_page: number; total: number; links: { prev: string | null; next: string | null } };
    totals: { count: number; out_pence: number; in_pence: number; transfer_count: number; transfer_out_pence: number; transfer_in_pence: number };
    manual: Item[];
    filters: Record<string, string | undefined>;
    lookups: Lookups;
}

function summary(item: Item): string {
    if (item.allocations.length === 0) return '';
    if (item.allocations.length > 1) {
        // Income across invoices or jobs names them, and who it's for when that's one client.
        const invoices = item.allocations.map((a) => a.invoice_number).filter(Boolean);
        const clients = [...new Set(item.allocations.map((a) => a.client).filter(Boolean))];
        return [`Split into ${item.allocations.length}`, invoices.length ? invoices.join(' + ') : null, clients.length === 1 ? clients[0] : null]
            .filter(Boolean)
            .join(' · ');
    }
    const a = item.allocations[0];
    return [
        kindLabels[a.kind],
        a.category,
        a.invoice_number,
        a.job ?? (a.kind === 'business_expense' ? 'General business' : null),
        a.kind === 'business_income' ? a.client : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function ManualExpenseDialog({ open, onClose, lookups }: { open: boolean; onClose: () => void; lookups: Lookups }) {
    const form = useForm({
        spent_on: londonToday(),
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
                        incomePickers={false}
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
    const [settingClient, setSettingClient] = useState(false);
    // On a phone, the filters used less often wait behind "More filters", so the payments come first.
    const [moreFilters, setMoreFilters] = useState(false);
    const more = moreFilters ? '' : 'max-md:hidden';
    const hidden =
        ['account', 'status', 'kind', 'decided_by', 'category', 'job', 'unassigned'].filter((k) => filters[k]).length +
        (filters.sort === 'amount' ? 1 : 0);
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (patch: Record<string, string | undefined>) => {
        const next: Record<string, string | undefined> = { ...filters, ...patch };
        delete next.page;
        Object.keys(next).forEach((k) => (next[k] === '' || next[k] === undefined) && delete next[k]);
        router.get('/transactions', next as never, { preserveState: true, preserveScroll: true, replace: true });
        setSelected([]);
    };

    const all = page.data.length > 0 && selected.length === page.data.length;
    const chosen = page.data.filter((t) => selected.includes(t.id));
    // "Decide selected" for money in only: who it's from and any job can be given to them all.
    const allIn = chosen.length > 0 && chosen.every((t) => t.amount_pence > 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Transactions', href: '/transactions' }]}>
            <Head title="Transactions" />
            <div className="mx-auto w-full max-w-7xl space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Transactions</h1>
                        <p className="text-muted-foreground text-sm">
                            {totals.count.toLocaleString()} matching · spent {money(totals.out_pence)} · received {money(totals.in_pence)}
                            {totals.transfer_count > 0 && (
                                <span title="Card bills, top-ups, savings and money moved between your own accounts. Not spending or income, so not in the totals.">
                                    {' '}
                                    · moved between your accounts {movedBetweenAccounts(totals.transfer_out_pence, totals.transfer_in_pence)} (
                                    {totals.transfer_count.toLocaleString()}, not counted)
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* A plain link, not an Inertia visit: the Add expense screen opens from the phone's cache with no signal. */}
                        <Button variant="outline" asChild>
                            <a href="/quick">
                                <Camera /> Quick add
                            </a>
                        </Button>
                        <Button variant="outline" onClick={() => setAdding(true)}>
                            <Plus /> Cash or foreign item
                        </Button>
                    </div>
                </div>

                <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-7">
                    <form
                        className="sm:col-span-3 lg:col-span-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q });
                        }}
                    >
                        <Input placeholder="Search description or merchant" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" />
                    </form>
                    <NativeSelect className="h-10" value={filters.year ?? ''} onChange={(e) => apply({ year: e.target.value })} aria-label="Tax year">
                        <option value="">All years</option>
                        {nav?.years.map((y) => <option key={y.label}>{y.label}</option>)}
                    </NativeSelect>
                    <NativeSelect
                        className="h-10"
                        value={filters.direction ?? ''}
                        onChange={(e) => apply({ direction: e.target.value })}
                        aria-label="Direction"
                    >
                        <option value="">Money in and out</option>
                        <option value="out">Money out</option>
                        <option value="in">Money in</option>
                    </NativeSelect>
                    <NativeSelect
                        className="h-10"
                        value={filters.client ?? ''}
                        onChange={(e) => apply({ client: e.target.value })}
                        aria-label="Client"
                    >
                        <option value="">Any client</option>
                        {lookups.clients.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" className="h-10 md:hidden" aria-expanded={moreFilters} onClick={() => setMoreFilters((v) => !v)}>
                        {moreFilters ? 'Fewer filters' : `More filters${hidden > 0 ? ` (${hidden} set)` : ''}`}
                    </Button>
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.account ?? ''}
                        onChange={(e) => apply({ account: e.target.value })}
                        aria-label="Account"
                    >
                        <option value="">All accounts</option>
                        {lookups.accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.status ?? ''}
                        onChange={(e) => apply({ status: e.target.value })}
                        aria-label="Status"
                    >
                        <option value="">Any status</option>
                        <option value="unreviewed">To decide</option>
                        <option value="suggested">Suggested</option>
                        <option value="confirmed">Confirmed</option>
                    </NativeSelect>
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.kind ?? ''}
                        onChange={(e) => apply({ kind: e.target.value })}
                        aria-label="Type"
                    >
                        <option value="">Any type</option>
                        {lookups.kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.decided_by ?? ''}
                        onChange={(e) => apply({ decided_by: e.target.value })}
                        aria-label="Decided by"
                    >
                        <option value="">Decided by anyone</option>
                        <option value="you">You</option>
                        <option value="rule">Your rules</option>
                        <option value="matcher">Matching</option>
                        <option value="ai">AI</option>
                    </NativeSelect>
                    <CategorySelect
                        placeholder="Any category"
                        aria-label="Category"
                        className={cn('h-10', more)}
                        both
                        lookups={lookups}
                        kind="business_expense"
                        value={filters.category ? Number(filters.category) : null}
                        onChange={(v) => apply({ category: v ? String(v) : '' })}
                    />
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.job ?? ''}
                        onChange={(e) => apply({ job: e.target.value })}
                        aria-label="Job"
                    >
                        <option value="">Any job</option>
                        <option value="none">Business, no job</option>
                        {lookups.jobs.map((j) => (
                            <option key={j.id} value={j.id}>
                                {j.title}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className={cn('h-10', more)}
                        value={filters.sort ?? 'date'}
                        onChange={(e) => apply({ sort: e.target.value })}
                        aria-label="Sort"
                    >
                        <option value="date">Newest first</option>
                        <option value="amount">Largest first</option>
                    </NativeSelect>
                    <label className={cn('flex min-h-10 items-center gap-3 rounded-md border px-3 text-sm sm:col-span-2', more)}>
                        <Checkbox
                            checked={filters.unassigned === '1' || filters.unassigned === 'true'}
                            onCheckedChange={(v) => apply({ unassigned: v ? '1' : '' })}
                        />
                        Income not given a client, job or invoice
                    </label>
                </div>

                {selected.length > 0 && (
                    <div className="bg-muted sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg p-2 text-sm shadow-sm">
                        <span className="px-2 font-medium">{selected.length} selected</span>
                        <Button className="h-10" onClick={() => setBulk(true)}>
                            Decide selected
                        </Button>
                        {chosen.some((t) => t.amount_pence > 0) && (
                            <Button className="h-10" variant="outline" onClick={() => setSettingClient(true)}>
                                Set client
                            </Button>
                        )}
                        <Button
                            className="h-10"
                            variant="outline"
                            onClick={() =>
                                router.post('/decisions/confirm', { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) })
                            }
                        >
                            Accept suggestions
                        </Button>
                        <Button className="h-10" variant="ghost" onClick={() => setSelected([])}>
                            Clear selection
                        </Button>
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="w-10 p-0">
                                    <label className="flex size-10 items-center justify-center">
                                        <Checkbox
                                            checked={all}
                                            onCheckedChange={(v) => setSelected(v ? page.data.map((t) => t.id) : [])}
                                            aria-label="Select all"
                                        />
                                    </label>
                                </th>
                                <th className="hidden p-2 sm:table-cell">Date</th>
                                <th className="p-2">Description</th>
                                <th className="hidden p-2 md:table-cell">Account</th>
                                <th className="p-2 text-right">Amount</th>
                                <th className="hidden p-2 lg:table-cell">Decision</th>
                                <th className="hidden p-2 sm:table-cell">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {page.data.map((t) => (
                                <tr key={t.id} className="hover:bg-muted/40 cursor-pointer" onClick={() => setDrawer({ source: 'bank', id: t.id })}>
                                    <td className="p-0" onClick={(e) => e.stopPropagation()}>
                                        <label className="flex size-10 items-center justify-center">
                                            <Checkbox
                                                checked={selected.includes(t.id)}
                                                onCheckedChange={(v) => setSelected((s) => (v ? [...s, t.id] : s.filter((x) => x !== t.id)))}
                                                aria-label="Select"
                                            />
                                        </label>
                                    </td>
                                    <td className="hidden p-2 whitespace-nowrap tabular-nums sm:table-cell">
                                        {day(t.date)}
                                        {t.time && <p className="text-muted-foreground text-xs">{t.time}</p>}
                                    </td>
                                    {/* On a phone the date and status go under the description and the amount, so the amount fits. */}
                                    <td className="max-w-[11rem] p-2 sm:max-w-80">
                                        <p className="text-muted-foreground text-xs tabular-nums sm:hidden">
                                            {day(t.date)}
                                            {t.time ? ` ${t.time}` : ''}
                                        </p>
                                        <p className="flex items-center gap-1.5">
                                            <span className="truncate">{t.description}</span>
                                            <Explain url={`/explain/${t.source ?? 'bank'}/${t.id}`} />
                                        </p>
                                        {t.place && <p className="text-muted-foreground truncate text-xs">{t.place}</p>}
                                        <p className="text-muted-foreground text-xs md:hidden">{paidWith(t)}</p>
                                        {summary(t) && <p className="text-muted-foreground truncate text-xs lg:hidden">{summary(t)}</p>}
                                    </td>
                                    <td className="text-muted-foreground hidden p-2 whitespace-nowrap md:table-cell">
                                        {t.account?.name}
                                        {(t.card_last4 || t.payment_type) && (
                                            <p className="text-xs">
                                                {[t.card_last4 ? `card •••• ${t.card_last4}` : null, t.payment_type].filter(Boolean).join(' · ')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        <Money pence={t.amount_pence} />
                                        <StatusPill status={t.review_status} className="mt-1 ml-auto flex w-fit sm:hidden" />
                                        {paidIn(t) && (
                                            <p className="text-muted-foreground text-xs tabular-nums" title={rateText(t.fx, t.original_currency)}>
                                                {paidIn(t)}
                                            </p>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground hidden max-w-72 p-2 lg:table-cell">
                                        <p className="truncate">{summary(t)}</p>
                                        {t.allocations[0] && <p className="text-xs">by {decidedByLabels[t.allocations[0].decided_by]}</p>}
                                    </td>
                                    <td className="hidden p-2 sm:table-cell">
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
                            className="h-10"
                            disabled={!page.links.prev}
                            onClick={() => page.links.prev && router.get(page.links.prev, {}, { preserveScroll: true })}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            className="h-10"
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
                                            {m.original_currency ? ` · ${moneyMinor(m.original_amount_minor, m.original_currency)}` : ''}
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

            <ResponsiveSheet
                open={bulk}
                onOpenChange={setBulk}
                title={`Decide ${selected.length} ${selected.length === 1 ? 'item' : 'items'}`}
                description="This replaces any current decision on the selected items. Each change is logged and can be changed again later."
            >
                <SheetBody>
                    <DecisionForm amount={allIn ? 1 : -1} lookups={lookups} value={bulkDecision} onChange={setBulkDecision} showRule={false} />
                </SheetBody>
                <SheetFooter>
                    <Button
                        className="h-11 w-full sm:w-auto"
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
                </SheetFooter>
            </ResponsiveSheet>
            <SetClientSheet
                open={settingClient}
                onClose={() => setSettingClient(false)}
                rows={chosen}
                year={filters.year}
                lookups={lookups}
                onDone={() => setSelected([])}
            />
        </AppLayout>
    );
}

/**
 * "Set client" for the selected money in: these payments, or every payment from their payer (in the year shown, or
 * all years), set as one client's, decided as you. Money out, locked years and payments decided as something else
 * are left as they are, and the message says so.
 */
function SetClientSheet({
    open,
    onClose,
    rows,
    year,
    lookups,
    onDone,
}: {
    open: boolean;
    onClose: () => void;
    rows: Item[];
    year?: string;
    lookups: Lookups;
    onDone: () => void;
}) {
    const [client, setClient] = useState<ClientRef | null>(null);
    const [scope, setScope] = useState<'these' | 'payer'>('these');
    const [busy, setBusy] = useState(false);
    const moneyIn = rows.filter((t) => t.amount_pence > 0);
    const payers = [...new Set(moneyIn.map((t) => t.merchant_key))];
    const payer = payers.length === 1 ? payers[0] : null;
    const everyFrom = payer && scope === 'payer';

    useEffect(() => {
        if (!open) return;
        setScope('these');
        // Who the lines say they're for already, when they all agree.
        const named = [...new Set(moneyIn.flatMap((t) => t.allocations.map((a) => a.client_id).filter(Boolean)))];
        const id = named.length === 1 ? (named[0] as number) : null;
        setClient(id ? (lookups.clients.find((c) => c.id === id) ?? null) : null);
        // Only when the sheet opens: the rows don't change while it's open.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const save = () => {
        if (!client) return toast('Pick the client first.', 'error');
        setBusy(true);
        router.post(
            '/decisions/bulk-client',
            (everyFrom
                ? { client_id: client.id, merchant_key: payer, ...(year ? { tax_year: year } : {}) }
                : { client_id: client.id, ids: moneyIn.map((t) => t.id) }) as never,
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    onDone();
                },
                onError: (errors) => toast(Object.values(errors)[0] ?? 'That didn’t save. Try again.', 'error'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title="Who is this money from?"
            description="Each payment is saved as income from that client, with no job. You can still change any one of them later."
        >
            <SheetBody className="space-y-4">
                <ClientSelect clients={lookups.clients} value={client?.id ?? null} name={client?.name} onChange={setClient} />
                {payer && (
                    <fieldset className="space-y-1 text-sm">
                        <legend className="mb-1 font-medium">Which payments?</legend>
                        <label className="flex min-h-11 items-center gap-3">
                            <input type="radio" name="scope" className="size-5" checked={scope === 'these'} onChange={() => setScope('these')} />
                            Only the {moneyIn.length} selected
                        </label>
                        <label className="flex min-h-11 items-center gap-3">
                            <input type="radio" name="scope" className="size-5" checked={scope === 'payer'} onChange={() => setScope('payer')} />
                            Every payment from {payer}
                            {year ? ` in ${year}` : ''}
                        </label>
                    </fieldset>
                )}
                {moneyIn.length < rows.length && (
                    <p className="text-muted-foreground text-sm">
                        {rows.length - moneyIn.length} selected {rows.length - moneyIn.length === 1 ? 'is' : 'are'} money out: left as{' '}
                        {rows.length - moneyIn.length === 1 ? 'it is' : 'they are'}.
                    </p>
                )}
            </SheetBody>
            <SheetFooter>
                <Button className="h-auto min-h-11 w-full py-2 whitespace-normal sm:w-auto" disabled={busy || !client} onClick={save}>
                    {!client
                        ? 'Pick the client first'
                        : everyFrom
                          ? `All from ${payer} are ${client.name}`
                          : `Set ${moneyIn.length} as ${client.name}’s`}
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
