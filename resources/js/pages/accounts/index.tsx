import { NativeSelect } from '@/components/native-select';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { balanceText, day } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface AccountRow {
    id: number;
    name: string;
    kind: 'bank' | 'credit_card';
    provider: string | null;
    last4: string | null;
    is_active: boolean;
    transactions: number;
    preset: string | null;
    currency: string | null;
    parent: string | null;
    reconcile: {
        method: 'running_balance' | 'statement_balances' | 'none';
        checked_to: string | null;
        balance_pence: number | null;
        gaps: { from: string; to: string; missing_pence: number; text: string; kind?: 'missing' | 'extra' | 'misdated' }[];
        last_line: string | null;
        last_import?: string | null;
        stale: boolean;
        text: string;
    };
    balances: { id: number; as_of: string; balance_pence: number; note: string | null }[];
}

/**
 * Show what the server refused, in plain words, so a button never seems to do nothing. A name kept as the bank
 * gave it can be longer than an account's details save with.
 */
const failed = (errors: Record<string, string>) =>
    toast(
        errors.name
            ? 'This account’s name is longer than 255 characters, so the change can’t be saved.'
            : (Object.values(errors)[0] ?? 'That didn’t save. Try again.'),
        'error',
    );

/** Whether the account matches the bank's own figures, the gaps if not, and the statement balances you've entered. */
function Reconcile({ a }: { a: AccountRow }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ as_of: '', balance: '', note: '' });
    const r = a.reconcile;
    const ok = r.method !== 'none' && r.gaps.length === 0;
    // A currency pocket's balances are in its own currency, as its statement shows them, and kept in that currency's
    // own minor units (whole yen for a yen pocket): the running balance from its statement and the ones typed in.
    const currency = a.currency ?? 'GBP';

    return (
        <div className="w-full space-y-2 text-sm">
            <p
                className={cn(
                    'flex flex-wrap items-center gap-2',
                    r.gaps.length ? 'text-rose-700 dark:text-rose-300' : ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground',
                )}
            >
                {r.gaps.length ? <TriangleAlert className="size-4" /> : ok ? <CircleCheck className="size-4" /> : null}
                {r.text}
                {ok && r.balance_pence !== null && (
                    <span className="text-muted-foreground">
                        {balanceText(a.kind, r.balance_pence, currency, r.method === 'running_balance' ? 'bank' : 'owed')}
                    </span>
                )}
                {r.stale && (r.last_import ?? r.last_line) && (
                    <span className="text-amber-700 dark:text-amber-300">Nothing imported since {day(r.last_import ?? r.last_line)}.</span>
                )}
                <button type="button" className="text-muted-foreground underline underline-offset-2" onClick={() => setOpen((o) => !o)}>
                    {open ? 'Hide' : 'Statement balances'}
                </button>
            </p>
            {r.gaps.length > 0 && (
                <ul className="list-disc space-y-0.5 pl-5 text-rose-700 dark:text-rose-300">
                    {r.gaps.map((g, i) => (
                        <li key={i}>{g.text}</li>
                    ))}
                </ul>
            )}
            {open && (
                <div className="space-y-2 rounded-lg border p-3">
                    <p className="text-muted-foreground text-xs">
                        {r.method === 'running_balance'
                            ? 'This account’s statements carry a running balance, so it’s checked line by line. You can still add a statement’s closing balance.'
                            : a.kind === 'credit_card'
                              ? 'Copy “new balance” (what you owe) and the statement date from two or more card statements. The change between them must match the purchases and payments imported.'
                              : 'Copy the closing balance and date from two or more statements.'}
                    </p>
                    <form
                        className="grid gap-2 sm:grid-cols-[10rem_9rem_1fr_auto]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/accounts/${a.id}/balances`, { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <Input
                            type="date"
                            aria-label="Statement date"
                            value={form.data.as_of}
                            onChange={(e) => form.setData('as_of', e.target.value)}
                        />
                        <Input
                            inputMode="decimal"
                            placeholder={a.kind === 'credit_card' ? 'Owed, e.g. 412.50' : 'Balance, e.g. 1,204.10'}
                            aria-label="Balance"
                            value={form.data.balance}
                            onChange={(e) => form.setData('balance', e.target.value)}
                        />
                        <Input placeholder="Note (optional)" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                        <Button size="sm" disabled={form.processing || !form.data.as_of || !form.data.balance}>
                            Add
                        </Button>
                        {Object.values(form.errors).map((e) => (
                            <p key={e} className="text-xs text-rose-600 sm:col-span-4">
                                {e}
                            </p>
                        ))}
                    </form>
                    {a.balances.length > 0 && (
                        <ul className="divide-y text-xs">
                            {a.balances.map((b) => (
                                <li key={b.id} className="flex items-center justify-between gap-2 py-1">
                                    <span>
                                        {day(b.as_of)} · {balanceText(a.kind, b.balance_pence, currency)}
                                        {b.note ? ` · ${b.note}` : ''}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.delete(`/accounts/${a.id}/balances/${b.id}`, { preserveScroll: true, onError: failed })}
                                    >
                                        Remove
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Accounts({ accounts }: { accounts: AccountRow[] }) {
    const form = useForm({ name: '', kind: 'bank', provider: 'other', last4: '' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Accounts', href: '/accounts' }]}>
            <Head title="Accounts" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Accounts</h1>
                    <p className="text-muted-foreground text-sm">Banks and cards change: add new ones any time. Old ones keep their history.</p>
                    <Link href="/imports" className="hover:bg-muted mt-2 inline-flex rounded-md border px-3 py-1.5 text-sm font-medium">
                        Import a statement
                    </Link>
                </div>
                <ul className="divide-y rounded-xl border">
                    {accounts.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <span>
                                <span className="font-medium">{a.name}</span>
                                <span className="text-muted-foreground block text-sm">
                                    {a.parent ? `${a.currency} pocket of ${a.parent}` : a.kind === 'bank' ? 'Bank account' : 'Credit card'}
                                    {a.last4 ? ` ending ${a.last4}` : ''} · {a.transactions.toLocaleString()} transactions
                                    {a.preset ? ` · import format saved (${a.preset})` : ''}
                                </span>
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="order-2"
                                onClick={() =>
                                    router.patch(
                                        `/accounts/${a.id}`,
                                        { ...a, last4: a.last4 ?? '', is_active: !a.is_active },
                                        { preserveScroll: true, onError: failed },
                                    )
                                }
                            >
                                {a.is_active ? 'Hide from checks' : 'Show in checks'}
                            </Button>
                            <div className="order-3 w-full">
                                <Reconcile a={a} />
                            </div>
                        </li>
                    ))}
                </ul>
                <form
                    className="grid gap-2 rounded-xl border p-4 sm:grid-cols-[1fr_10rem_10rem_6rem_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/accounts', { onSuccess: () => form.reset() });
                    }}
                >
                    <Input placeholder="Account name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <NativeSelect value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                        <option value="bank">Bank account</option>
                        <option value="credit_card">Credit card</option>
                    </NativeSelect>
                    <NativeSelect value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                        <option value="starling">Starling</option>
                        <option value="amex">American Express</option>
                        <option value="capital_one">Capital One</option>
                        <option value="revolut">Revolut</option>
                        <option value="monzo">Monzo</option>
                        <option value="wise">Wise</option>
                        <option value="paypal">PayPal</option>
                        <option value="other">Other</option>
                    </NativeSelect>
                    <Input
                        placeholder="Last 4"
                        inputMode="numeric"
                        maxLength={4}
                        value={form.data.last4}
                        onChange={(e) => form.setData('last4', e.target.value)}
                    />
                    <Button disabled={form.processing}>Add</Button>
                    {Object.values(form.errors).map((e) => (
                        <p key={e} className="text-sm text-rose-600 sm:col-span-5">
                            {e}
                        </p>
                    ))}
                </form>
            </div>
        </AppLayout>
    );
}
