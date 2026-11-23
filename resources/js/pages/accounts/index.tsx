import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface AccountRow {
    id: number;
    name: string;
    kind: 'bank' | 'credit_card';
    provider: string | null;
    last4: string | null;
    is_active: boolean;
    transactions: number;
    preset: string | null;
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
                                    {a.kind === 'bank' ? 'Bank account' : 'Credit card'}
                                    {a.last4 ? ` ending ${a.last4}` : ''} · {a.transactions.toLocaleString()} transactions
                                    {a.preset ? ` · import format saved (${a.preset})` : ''}
                                </span>
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.patch(
                                        `/accounts/${a.id}`,
                                        { ...a, last4: a.last4 ?? '', is_active: !a.is_active },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {a.is_active ? 'Hide from checks' : 'Show in checks'}
                            </Button>
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
