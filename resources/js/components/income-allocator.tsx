import { ClientSelect } from '@/components/client-select';
import { CategorySelect } from '@/components/decision-form';
import { JobCombobox } from '@/components/job-combobox';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { getJson, problemText, wasCalledOff } from '@/lib/api';
import { day, money, toPence } from '@/lib/format';
import { feeCategoryId, linesProblem, shortMoney, turnoverId } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type ClientRef, type DecisionLine, type IncomeInfo, type IncomeTarget, type Item, type Lookups, type OpenInvoice, type Owed } from '@/types';
import { Check, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

/** Something the payment can be for: an open invoice or a job, ticked with how much of the payment goes on it. */
interface Row {
    key: string;
    type: 'invoice' | 'job';
    id: number;
    title: string;
    sub: string;
    /** What's still to come on it, in pence (a pound invoice); null when that isn't known (a job, another currency). */
    open: number | null;
    currency: string;
    ticked: boolean;
    amount: string;
    /** Ticked because a page asked (an invoice's "Link a payment"): what's still to come is filled in once it's read. */
    asked?: boolean;
}

/** A row's key without the mark of one added by a search ("+i12" is "i12"). */
const bare = (key: string) => key.replace(/^\+/, '');

/** What the drawer can ask to have ticked from the start ("Link a payment" on an invoice, "Link" on a job). */
export interface AllocatorTick {
    invoice_id?: number;
    job_id?: number;
    label?: string;
    /** What's still to come on the invoice, in pence (a pound invoice): the row starts at that, not the whole payment. */
    outstanding_pence?: number | null;
}

const pounds = (pence: number) => (Math.max(0, pence) / 100).toFixed(2);

function invoiceRow(i: OpenInvoice): Row {
    const pound = i.currency === 'GBP';
    return {
        key: `i${i.id}`,
        type: 'invoice',
        id: i.id,
        title: i.number ?? 'An invoice',
        sub: [i.job, i.client, `${shortMoney(i.outstanding_minor, i.currency)} to come`].filter(Boolean).join(' · '),
        open: pound ? i.outstanding_minor : null,
        currency: i.currency,
        ticked: false,
        amount: '',
    };
}

function ticks(rows: Row[], key: string, payment: number, on: boolean): Row[] {
    const others = rows.filter((r) => r.ticked && r.key !== key).reduce((s, r) => s + (toPence(r.amount) ?? 0), 0);
    const left = payment - others;
    return rows.map((r) =>
        r.key !== key
            ? r
            : // Ticking fills what's still to come, or what's left of the payment if that's less; you can change it.
              { ...r, ticked: on, amount: on ? pounds(r.open === null ? left : Math.min(r.open, left)) : r.amount },
    );
}

/**
 * "What is this payment for?" (money in, in the drawer): the client (from the payment's lines, else who the payer
 * usually pays for), what they still owe you as rows to tick, and a footer that only saves when it adds up. When the
 * ticked invoices come to more than the payment there's no plain Save: either a fee or commission was taken off, or
 * they paid less. Anything left over goes to the client with no job, or onto one of the invoices as overpaid.
 *
 * Nothing is saved, accepted or ticked for you: the rows start as the payment's own lines you made or confirmed, and
 * only your press saves.
 */
export function IncomeAllocator({
    item,
    income,
    targets,
    lookups,
    busy,
    disabled,
    tick,
    onSave,
}: {
    item: Item;
    income: IncomeInfo;
    targets: IncomeTarget[];
    lookups: Lookups;
    busy?: boolean;
    disabled?: boolean;
    tick?: AllocatorTick | null;
    onSave: (lines: DecisionLine[], done: string) => void;
}) {
    const payment = item.amount_pence;
    const [client, setClient] = useState<ClientRef | null>(income.client_id ? { id: income.client_id, name: income.client ?? 'Client' } : null);
    const [owed, setOwed] = useState<Owed | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [rows, setRows] = useState<Row[]>(() => ownRows(item, tick ?? null));
    const [chip, setChip] = useState<IncomeTarget | null>(null);
    const [rest, setRest] = useState<string>('client');
    const [feeCategory, setFeeCategory] = useState<number | null>(feeCategoryId(lookups));
    const [adding, setAdding] = useState<'invoice' | 'job' | null>(null);
    // A row a page asked to have ticked is ticked on the first look only: untick it and it stays unticked.
    const firstLook = useRef(true);

    // What the client still owes, read again whenever the client changes: their rows join the ones already ticked.
    useEffect(() => {
        if (!client) {
            // No client: only what's ticked (or added by a search) stays.
            setOwed(null);
            setRows((current) => current.filter((r) => r.ticked || r.key.startsWith('+')));
            return;
        }
        const stop = new AbortController();
        setProblem(null);
        getJson<Owed>('/lookups/owed', { client: client.id }, stop.signal)
            .then((o) => {
                setOwed(o);
                setRows((current) => {
                    const fresh: Row[] = [
                        ...o.invoices.map(invoiceRow),
                        ...o.jobs.map(
                            (j): Row => ({
                                key: `j${j.id}`,
                                type: 'job',
                                id: j.id,
                                title: j.title,
                                sub: `${day(j.ends_on, false)} · nothing billed${j.fee_pence ? ` · fee ${shortMoney(j.fee_pence)}` : ''}`,
                                open: j.fee_pence,
                                currency: 'GBP',
                                ticked: false,
                                amount: '',
                            }),
                        ),
                    ];
                    const byKey = new Map(fresh.map((r) => [r.key, r]));
                    // Ticked rows and ones you added stay; a row asked for takes what's still to come from the list.
                    const kept = current
                        .filter((r) => r.ticked || r.key.startsWith('+'))
                        .map((r) => {
                            const known = r.asked ? byKey.get(bare(r.key)) : undefined;
                            if (!known) return r;
                            // The row asked for starts at what's still to come on it, as ticking it by hand does: what
                            // is left of the payment is then offered as left over, never put on the invoice as overpaid.
                            const others = current.filter((o) => o.ticked && o.key !== r.key).reduce((t, o) => t + (toPence(o.amount) ?? 0), 0);
                            const amount = known.open === null ? r.amount : pounds(Math.min(known.open, payment - others));
                            return { ...r, open: known.open, sub: known.sub, currency: known.currency, amount, asked: false };
                        });
                    const have = new Set(kept.map((r) => bare(r.key)));
                    let next = [...kept, ...fresh.filter((r) => !have.has(r.key))];
                    // Asked to start with one ticked (from an invoice's or a job's page): ticked, not saved.
                    const wanted = tick?.invoice_id ? `i${tick.invoice_id}` : tick?.job_id ? `j${tick.job_id}` : null;
                    if (firstLook.current && wanted && next.some((r) => r.key === wanted && !r.ticked)) {
                        next = ticks(next, wanted, payment, true);
                    }
                    firstLook.current = false;
                    return next;
                });
            })
            .catch((e) => !wasCalledOff(e) && setProblem(problemText(e)));
        return () => stop.abort();
        // Read again only when the client changes (the payment, the tick and the rows are this drawer's own).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [client?.id]);

    const ticked = rows.filter((r) => r.ticked);
    const amounts = ticked.map((r) => toPence(r.amount));
    const typed = amounts.reduce<number>((s, a) => s + (a ?? 0), 0);
    // What the ticked rows come to: an invoice at what's still to come on it, anything else at the amount typed.
    const due = ticked.reduce((s, r, i) => s + (r.type === 'invoice' && r.open !== null ? r.open : (amounts[i] ?? 0)), 0);
    const over = due - payment;
    const left = payment - typed;
    const pound = ticked.every((r) => r.currency === 'GBP');
    const knownDue = ticked.every((r) => r.type !== 'invoice' || r.open !== null);
    // A fee or commission is a small part of what was owed (at most a quarter, as the to-do list's offer): a bigger
    // gap is a part payment, not a fee.
    const canFee = over > 0 && over * 4 <= due && pound && knownDue && !income.payslip_like;
    const turnover = turnoverId(lookups);

    const line = (r: Row, pence: number): DecisionLine => ({
        kind: 'business_income',
        category_id: turnover,
        work_job_id: r.type === 'job' ? r.id : null,
        invoice_id: r.type === 'invoice' ? r.id : null,
        client_id: client?.id ?? null,
        amount_pence: pence,
        business_use_pct: 100,
        note: null,
    });
    const clientOnly = (pence: number): DecisionLine => ({
        kind: 'business_income',
        category_id: turnover,
        work_job_id: null,
        invoice_id: null,
        client_id: client?.id ?? null,
        amount_pence: pence,
        business_use_pct: 100,
        note: null,
    });

    // Where what's left over goes: onto a ticked pound invoice you chose, else to the client with no job.
    const overpayable = ticked.filter((r) => r.type === 'invoice' && r.currency === 'GBP');
    const restOnto = overpayable.some((r) => `i${r.id}` === rest) ? rest : 'client';

    /** Saved as they are, plus what's left over (to the client with no job, or onto an invoice as overpaid). */
    const asTyped = (): DecisionLine[] => {
        const lines = ticked.map((r, i) => line(r, amounts[i] ?? 0));
        if (left > 0) {
            const onto = restOnto !== 'client' ? lines.find((l) => l.invoice_id === Number(restOnto.slice(1))) : undefined;
            if (onto) onto.amount_pence += left;
            else lines.push(clientOnly(left));
        }
        return lines;
    };

    /** A fee or commission was taken off: each invoice at what was still to come, and the difference as the fee. */
    const withFee = (): DecisionLine[] => [
        ...ticked.map((r, i) => line(r, r.type === 'invoice' && r.open !== null ? r.open : (amounts[i] ?? 0))),
        {
            kind: 'business_expense',
            category_id: feeCategory,
            work_job_id: null,
            invoice_id: null,
            client_id: client?.id ?? null,
            amount_pence: -over,
            business_use_pct: 100,
            note: null,
        },
    ];

    /** They paid less: what came in goes on the ticked rows in order, each up to what's still to come. */
    const partPaid = (): DecisionLine[] => {
        if (typed === payment && amounts.every((a) => a !== null && a > 0)) return ticked.map((r, i) => line(r, amounts[i] as number));
        let remaining = payment;
        const lines: DecisionLine[] = [];
        for (const [i, r] of ticked.entries()) {
            const want = r.type === 'invoice' && r.open !== null ? r.open : (amounts[i] ?? 0);
            const take = Math.min(want, remaining);
            if (take > 0) lines.push(line(r, take));
            remaining -= take;
        }
        return lines;
    };

    const save = (lines: DecisionLine[], done: string) => {
        const wrong = linesProblem(lines, payment);
        if (wrong) return setProblem(wrong);
        setProblem(null);
        onSave(lines, done);
    };

    const setRow = (key: string, patch: Partial<Row>) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));
    const add = (r: Row) =>
        setRows((rs) => {
            const without = rs.filter((x) => bare(x.key) !== r.key);
            return ticks([...without, { ...r, key: `+${r.key}` }], `+${r.key}`, payment, true);
        });

    const invalid = ticked.some((_, i) => amounts[i] === null || (amounts[i] as number) <= 0);

    return (
        <section aria-labelledby="allocator-title" className="rounded-xl border">
            <div className="space-y-3 p-3">
                <h3 id="allocator-title" className="font-semibold">
                    What is this payment for?
                </h3>
                {income.payer && <p className="text-muted-foreground text-sm">{income.payer}</p>}

                {targets.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-muted-foreground text-xs">Could be (tap to pick, then Save):</p>
                        <div className="flex flex-wrap gap-2">
                            {targets.map((t) => (
                                <button
                                    key={t.label}
                                    type="button"
                                    disabled={disabled}
                                    aria-pressed={chip?.label === t.label}
                                    onClick={() => setChip(chip?.label === t.label ? null : t)}
                                    className={cn(
                                        'min-h-11 rounded-full border px-3 py-2 text-left text-sm disabled:opacity-50',
                                        chip?.label === t.label
                                            ? 'border-sky-600 bg-sky-50 text-sky-900 dark:bg-sky-950 dark:text-sky-100'
                                            : 'hover:bg-muted',
                                    )}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {chip ? (
                    <div className="rounded-lg bg-sky-50 p-3 text-sm dark:bg-sky-950/40">
                        <p className="font-medium">{chip.label}</p>
                        <p className="text-muted-foreground mt-1">
                            Saved as you see it. To pick the lines yourself, tap{' '}
                            <button type="button" className="underline underline-offset-2" onClick={() => setChip(null)}>
                                choose myself
                            </button>
                            .
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-1.5">
                            <p className="text-sm font-medium">Client</p>
                            <ClientSelect
                                clients={lookups.clients}
                                value={client?.id ?? null}
                                name={client?.name}
                                allowNone
                                disabled={disabled}
                                onChange={(c) => setClient(c)}
                            />
                        </div>

                        {(rows.length > 0 || owed) && (
                            <div className="space-y-1.5">
                                <p className="text-sm font-medium">{owed?.label ?? 'Ticked'}</p>
                                {rows.length === 0 && <p className="text-muted-foreground text-sm">Nothing owed that Expend knows of.</p>}
                                <ul className="divide-y rounded-lg border">
                                    {rows.map((r) => (
                                        <li key={r.key} className="flex items-center gap-2 px-2">
                                            <label className="flex min-h-11 min-w-0 flex-1 items-center gap-3 py-1.5">
                                                <Checkbox
                                                    checked={r.ticked}
                                                    disabled={disabled}
                                                    onCheckedChange={(v) => setRows((rs) => ticks(rs, r.key, payment, !!v))}
                                                />
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-medium">{r.title}</span>
                                                    <span className="text-muted-foreground block truncate text-xs">{r.sub}</span>
                                                </span>
                                            </label>
                                            {r.ticked && (
                                                <Input
                                                    inputMode="decimal"
                                                    aria-label={`How much for ${r.title}`}
                                                    className="h-11 w-24 shrink-0 text-right tabular-nums"
                                                    disabled={disabled}
                                                    value={r.amount}
                                                    onChange={(e) => setRow(r.key, { amount: e.target.value })}
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                className="h-11"
                                disabled={disabled}
                                onClick={() => setAdding(adding === 'invoice' ? null : 'invoice')}
                            >
                                <Plus /> Another invoice
                            </Button>
                            <Button variant="outline" className="h-11" disabled={disabled} onClick={() => setAdding(adding === 'job' ? null : 'job')}>
                                <Plus /> Another job
                            </Button>
                        </div>
                        {adding === 'invoice' && (
                            <InvoiceSearch
                                onPick={(i) => {
                                    add(invoiceRow(i));
                                    setAdding(null);
                                }}
                            />
                        )}
                        {adding === 'job' && (
                            <JobCombobox
                                value={undefined}
                                placeholder="Search for a job"
                                client={client?.id ?? null}
                                on={item.date}
                                forIncome
                                onChange={(j) => {
                                    if (j)
                                        add({
                                            key: `j${j.id}`,
                                            type: 'job',
                                            id: j.id,
                                            title: j.title,
                                            sub: [day(j.starts_on, false), j.client].filter(Boolean).join(' · '),
                                            open: null,
                                            currency: 'GBP',
                                            ticked: false,
                                            amount: '',
                                        });
                                    setAdding(null);
                                }}
                            />
                        )}
                    </>
                )}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </div>

            {/* The footer stays in reach while the rows scroll, and says what pressing it will save. */}
            <div className="bg-background sticky bottom-0 space-y-2 rounded-b-xl border-t p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                {chip ? (
                    <Button
                        className="h-auto min-h-11 w-full py-2 whitespace-normal"
                        disabled={disabled || busy}
                        onClick={() => save(chip.lines, 'Saved.')}
                    >
                        <Check /> {chip.save}
                    </Button>
                ) : ticked.length === 0 ? (
                    client ? (
                        <>
                            <p className="text-sm">
                                {money(payment)} for {client.name}, no job
                            </p>
                            <Button className="h-11 w-full" disabled={disabled || busy} onClick={() => save([clientOnly(payment)], 'Saved.')}>
                                <Check /> Save
                            </Button>
                        </>
                    ) : (
                        <p className="text-muted-foreground text-sm">Pick the client, or tick what it’s for.</p>
                    )
                ) : over > 0 ? (
                    <>
                        <p className="text-sm">
                            Ticked: {money(due)}. That’s {money(over)} more than came in.
                        </p>
                        {canFee && (
                            <div className="space-y-1">
                                <Button
                                    className="h-auto min-h-11 w-full whitespace-normal"
                                    disabled={disabled || busy || !feeCategory}
                                    onClick={() => save(withFee(), `Saved, with ${shortMoney(over)} taken off.`)}
                                >
                                    {shortMoney(over)} was taken off (fee or commission)
                                </Button>
                                <p className="text-muted-foreground text-xs">{income.deduction_note}</p>
                                <details className="text-xs">
                                    <summary className="text-muted-foreground cursor-pointer py-3">Where the fee is counted</summary>
                                    <CategorySelect
                                        lookups={lookups}
                                        kind="business_expense"
                                        value={feeCategory}
                                        onChange={setFeeCategory}
                                        className="h-11"
                                    />
                                </details>
                            </div>
                        )}
                        <Button
                            variant={canFee ? 'outline' : 'default'}
                            className="h-auto min-h-11 w-full whitespace-normal"
                            disabled={disabled || busy}
                            onClick={() => save(partPaid(), 'Saved as part paid.')}
                        >
                            They paid less (save as part paid)
                        </Button>
                    </>
                ) : invalid ? (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        Type how much is for each ticked row.
                    </p>
                ) : left < 0 ? (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {money(-left)} more than the payment: lower an amount.
                    </p>
                ) : (
                    <>
                        {left > 0 && (
                            <fieldset className="space-y-1 text-sm">
                                <legend className="mb-1">{money(left)} is left over:</legend>
                                <label className="flex min-h-11 items-center gap-3">
                                    <input
                                        type="radio"
                                        name="rest"
                                        className="size-5"
                                        checked={restOnto === 'client'}
                                        onChange={() => setRest('client')}
                                    />
                                    {client ? `${client.name}, no job (pays no invoice)` : 'Income with no client yet'}
                                </label>
                                {overpayable.map((r) => (
                                    <label key={r.key} className="flex min-h-11 items-center gap-3">
                                        <input
                                            type="radio"
                                            name="rest"
                                            className="size-5"
                                            checked={restOnto === `i${r.id}`}
                                            onChange={() => setRest(`i${r.id}`)}
                                        />
                                        Add it to {r.title} (shows as overpaid)
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        <div className="flex items-center gap-3">
                            <p className="min-w-0 flex-1 text-sm tabular-nums">
                                {money(typed + left)} of {money(payment)} <Check className="inline size-4 text-emerald-600" />
                            </p>
                            <Button className="h-11 shrink-0" disabled={disabled || busy} onClick={() => save(asTyped(), 'Saved.')}>
                                Save
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}

/** The payment's own lines you made or confirmed, as ticked rows; and the row a page asked to have ticked, named as it said. */
function ownRows(item: Item, tick: AllocatorTick | null): Row[] {
    const yours = item.allocations.filter((a) => a.is_manual || a.confirmed);
    const rows: Row[] = yours
        .filter((a) => a.kind === 'business_income' && a.amount_pence > 0 && (a.invoice_id || a.work_job_id))
        .map((a) => ({
            key: a.invoice_id ? `i${a.invoice_id}` : `j${a.work_job_id}`,
            type: a.invoice_id ? 'invoice' : 'job',
            id: (a.invoice_id ?? a.work_job_id) as number,
            title: a.invoice_id ? (a.invoice_number ?? 'An invoice') : (a.job ?? 'A job'),
            sub: 'On this payment now',
            open: null,
            currency: 'GBP',
            ticked: true,
            amount: pounds(a.amount_pence),
        }));
    const wanted = tick?.invoice_id ? `i${tick.invoice_id}` : tick?.job_id ? `j${tick.job_id}` : null;
    if (tick && wanted && tick.label && !rows.some((r) => r.key === wanted)) {
        const taken = rows.reduce((s, r) => s + (toPence(r.amount) ?? 0), 0);
        // An invoice's page knows what's still to come on it: the row starts at that (or what's left of the payment).
        const open = tick.invoice_id && typeof tick.outstanding_pence === 'number' ? tick.outstanding_pence : null;
        rows.push({
            key: wanted,
            type: tick.invoice_id ? 'invoice' : 'job',
            id: (tick.invoice_id ?? tick.job_id) as number,
            title: tick.label,
            sub: 'From the page you came from',
            open,
            currency: 'GBP',
            ticked: true,
            amount: pounds(open === null ? item.amount_pence - taken : Math.min(open, item.amount_pence - taken)),
            asked: true,
        });
    }
    return rows;
}

/** "+ Another invoice": any open invoice, by number or amount. */
function InvoiceSearch({ onPick }: { onPick: (invoice: OpenInvoice) => void }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<OpenInvoice[] | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const query = useMemo(() => q.trim(), [q]);

    useEffect(() => {
        if (query === '') {
            setFound(null);
            return;
        }
        const stop = new AbortController();
        const timer = window.setTimeout(() => {
            setProblem(null);
            getJson<{ invoices: OpenInvoice[] }>('/lookups/invoices', { q: query }, stop.signal)
                .then((r) => setFound(r.invoices))
                .catch((e) => !wasCalledOff(e) && setProblem(problemText(e)));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            stop.abort();
        };
    }, [query]);

    return (
        <div className="space-y-2 rounded-lg border p-2">
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-3.5 left-3 size-4" />
                <Input autoFocus className="h-11 pl-9" placeholder="Invoice number or amount" value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            {found && found.length === 0 && <p className="text-muted-foreground px-1 text-sm">No open invoice matches that.</p>}
            {found && found.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {found.map((i) => (
                        <li key={i.id}>
                            <button type="button" className="hover:bg-muted min-h-11 w-full px-3 py-2 text-left text-sm" onClick={() => onPick(i)}>
                                <span className="font-medium">{i.number ?? 'An invoice'}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {[i.job, i.client, `${shortMoney(i.outstanding_minor, i.currency)} to come`].filter(Boolean).join(' · ')}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {problem && (
                <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                    {problem}
                </p>
            )}
        </div>
    );
}
