import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileUp, Undo2 } from 'lucide-react';
import { useRef, useState } from 'react';

interface Profile {
    preset: string;
    dateColumn: string | null;
    transactionDateColumn: string | null;
    descriptionColumns: string[];
    counterpartyColumn: string | null;
    referenceColumn: string | null;
    amountColumn: string | null;
    debitColumn: string | null;
    creditColumn: string | null;
    invertSign: boolean;
    balanceColumn: string | null;
    categoryColumn: string | null;
    currencyColumn: string | null;
    originalAmountColumn: string | null;
    dateFormat: string;
}

interface Preview {
    ok: boolean;
    message?: string;
    filename: string;
    account: { id: number; name: string; kind: string };
    headers: string[];
    profile: Profile;
    new?: number;
    duplicates?: number;
    rows?: number;
    errors?: string[];
    error_count?: number;
    balance?: { status: string; notes: string | null };
    first?: string | null;
    last?: string | null;
    pockets?: {
        label: string;
        currency: string;
        name: string;
        exists: boolean;
        new: number;
        duplicates: number;
        balance: string;
        first: string | null;
        last: string | null;
    }[];
    skipped?: Record<string, number>;
    exchanges?: number;
    sample?: {
        date: string;
        description: string;
        amount_pence: number;
        balance_pence: number | null;
        currency: string | null;
        original_minor: number | null;
        duplicate: boolean;
    }[];
}

interface Props {
    accounts: { id: number; name: string; kind: string; provider: string | null }[];
    batches: {
        id: number;
        account: string;
        file: string;
        imported: number;
        duplicates: number;
        first: string | null;
        last: string | null;
        balance_check: string;
        balance_notes: string | null;
        status: string;
        review_note: string | null;
        held_rows: { date: string; description: string; amount_pence: number; balance_pence: number | null }[];
        at: string;
    }[];
    preview: Preview | null;
    dateFormats: string[];
}

const presetNames: Record<string, string> = {
    starling: 'Starling',
    amex: 'American Express',
    capital_one: 'Capital One',
    revolut_personal: 'Revolut (personal)',
    revolut_business: 'Revolut Business',
    custom: 'Your column choices',
};

function ColumnSelect({
    label,
    headers,
    value,
    onChange,
    optional = true,
}: {
    label: string;
    headers: string[];
    value: string | null;
    onChange: (v: string | null) => void;
    optional?: boolean;
}) {
    return (
        <div className="space-y-1">
            <Label className="text-xs">{label}</Label>
            <NativeSelect value={value ?? ''} onChange={(e) => onChange(e.target.value || null)}>
                <option value="">{optional ? 'Not in this file' : 'Choose…'}</option>
                {headers.map((h) => (
                    <option key={h}>{h}</option>
                ))}
            </NativeSelect>
        </div>
    );
}

export default function Imports({ accounts, batches, preview, dateFormats }: Props) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [accountId, setAccountId] = useState<number | ''>(preview?.account.id ?? accounts[0]?.id ?? '');
    const [profile, setProfile] = useState<Profile | null>(preview?.profile ?? null);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState(false);

    const upload = (file: File) => {
        if (!accountId) return;
        setBusy(true);
        router.post(
            '/imports/preview',
            { file, account_id: accountId },
            {
                forceFormData: true,
                onSuccess: (p) => setProfile(((p.props as unknown as Props).preview?.profile as Profile) ?? null),
                onFinish: () => setBusy(false),
            },
        );
    };

    const remap = (patch: Partial<Profile>) => {
        if (!profile || !preview) return;
        const next = { ...profile, ...patch, preset: 'custom' };
        setProfile(next);
        router.post('/imports/preview', { account_id: preview.account.id, profile: next } as never, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Accounts', href: '/accounts' },
                { title: 'Import', href: '/imports' },
            ]}
        >
            <Head title="Import" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Import a statement</h1>
                    <p className="text-muted-foreground text-sm">
                        CSV exports from Starling, Amex and Capital One are recognised. Any other bank: map its columns once and it's remembered.
                        Overlapping statements are safe; nothing is imported twice.
                    </p>
                </div>

                {accounts.length === 0 ? (
                    <p className="text-sm">
                        First{' '}
                        <Link href="/accounts" className="underline">
                            add an account
                        </Link>
                        .
                    </p>
                ) : !preview ? (
                    <div className="space-y-4">
                        <div className="max-w-xs space-y-1.5">
                            <Label htmlFor="account">Account</Label>
                            <NativeSelect id="account" value={accountId} onChange={(e) => setAccountId(Number(e.target.value))}>
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            onDragOver={(e) => (e.preventDefault(), setDragging(true))}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setDragging(false);
                                if (e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]);
                            }}
                            className={cn(
                                'flex w-full flex-col items-center gap-3 rounded-2xl border-2 border-dashed p-10 text-center transition-colors',
                                dragging ? 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' : 'hover:bg-muted/40',
                            )}
                        >
                            <FileUp className="text-muted-foreground size-8" />
                            <span className="font-medium">{busy ? 'Reading…' : 'Drop a CSV here, or click to choose'}</span>
                            <span className="text-muted-foreground text-sm">You'll see exactly what will be imported before anything changes.</span>
                        </button>
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".csv,text/csv,.xlsx"
                            className="hidden"
                            onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])}
                        />
                    </div>
                ) : (
                    <div className="space-y-5 rounded-2xl border p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-semibold">{preview.filename}</p>
                                <p className="text-muted-foreground text-sm">
                                    Into {preview.account.name} · read as {presetNames[preview.profile.preset] ?? preview.profile.preset}
                                </p>
                            </div>
                            <Button variant="ghost" onClick={() => router.post('/imports/cancel')}>
                                Cancel
                            </Button>
                        </div>

                        {preview.ok ? (
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div className="rounded-lg border p-3">
                                    <p className="text-2xl font-semibold">{preview.new}</p>
                                    <p className="text-muted-foreground text-sm">new transactions</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-2xl font-semibold">{preview.duplicates}</p>
                                    <p className="text-muted-foreground text-sm">already imported, skipped</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="font-semibold">
                                        {day(preview.first)} – {day(preview.last)}
                                    </p>
                                    <p className="text-muted-foreground text-sm">dates covered</p>
                                </div>
                                <div
                                    className={cn(
                                        'rounded-lg border p-3',
                                        preview.balance?.status === 'failed' && 'border-rose-300 bg-rose-50 dark:bg-rose-950/30',
                                    )}
                                >
                                    <p className="flex items-center gap-1.5 font-semibold">
                                        {preview.balance?.status === 'passed' ? (
                                            <>
                                                <CheckCircle2 className="size-4 text-emerald-600" /> Balances add up
                                            </>
                                        ) : preview.balance?.status === 'failed' ? (
                                            <>
                                                <AlertTriangle className="size-4 text-rose-600" /> Balance mismatch
                                            </>
                                        ) : (
                                            'No running balance'
                                        )}
                                    </p>
                                    <p className="text-muted-foreground text-sm">{preview.balance?.notes ?? 'running-balance check'}</p>
                                </div>
                            </div>
                        ) : (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                {preview.message}
                            </p>
                        )}

                        {preview.pockets && preview.pockets.length > 0 && (
                            <div className="space-y-2 rounded-lg border p-3 text-sm">
                                <p className="font-medium">Each currency goes into its own account, checked against its own balance</p>
                                <ul className="divide-y">
                                    {preview.pockets.map((p) => (
                                        <li key={p.label} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5">
                                            <span className="w-14 font-mono text-xs">{p.currency}</span>
                                            <span className="min-w-0 flex-1">
                                                {p.name}
                                                {!p.exists && <span className="text-muted-foreground"> (new)</span>}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {p.new} new{p.duplicates ? `, ${p.duplicates} already in` : ''}
                                                {p.first ? ` · ${day(p.first)} – ${day(p.last)}` : ''}
                                            </span>
                                            <span
                                                className={cn(
                                                    'text-xs',
                                                    p.balance === 'passed' && 'text-emerald-700 dark:text-emerald-300',
                                                    p.balance === 'failed' && 'text-rose-700 dark:text-rose-300',
                                                )}
                                            >
                                                {p.balance === 'passed'
                                                    ? 'balance adds up'
                                                    : p.balance === 'failed'
                                                      ? 'balance doesn’t add up'
                                                      : 'no balance to check'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground text-xs">
                                    Lines in other currencies count in pounds at the pounds they were bought with, the bank’s sterling figure, or
                                    HMRC’s monthly rate.
                                    {preview.exchanges
                                        ? ` ${preview.exchanges} currency ${preview.exchanges === 1 ? 'exchange is' : 'exchanges are'} paired as transfers.`
                                        : ''}
                                    {preview.skipped && Object.keys(preview.skipped).length > 0
                                        ? ` Skipped (not completed): ${Object.entries(preview.skipped)
                                              .map(([k, v]) => `${v} ${k.toLowerCase()}`)
                                              .join(', ')}.`
                                        : ''}
                                </p>
                            </div>
                        )}

                        {preview.errors && preview.errors.length > 0 && (
                            <div className="rounded-lg bg-rose-50 p-3 text-sm text-rose-900 dark:bg-rose-950/30 dark:text-rose-200">
                                <p className="font-medium">{preview.error_count} rows can't be read yet. Adjust the columns below.</p>
                                <ul className="mt-1 list-disc pl-5">
                                    {preview.errors.slice(0, 5).map((e) => (
                                        <li key={e}>{e}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {profile && !String(preview.profile.preset).startsWith('revolut') && (
                            <details
                                open={!preview.ok || (preview.errors?.length ?? 0) > 0 || preview.profile.preset === 'custom'}
                                className="rounded-lg border p-4"
                            >
                                <summary className="cursor-pointer text-sm font-medium">How this file is read</summary>
                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    <ColumnSelect
                                        label="Date"
                                        headers={preview.headers}
                                        value={profile.dateColumn}
                                        onChange={(v) => remap({ dateColumn: v })}
                                        optional={false}
                                    />
                                    <ColumnSelect
                                        label="Card transaction date (if separate)"
                                        headers={preview.headers}
                                        value={profile.transactionDateColumn}
                                        onChange={(v) => remap({ transactionDateColumn: v })}
                                    />
                                    <div className="space-y-1">
                                        <Label className="text-xs">Date format</Label>
                                        <NativeSelect value={profile.dateFormat} onChange={(e) => remap({ dateFormat: e.target.value })}>
                                            {dateFormats.map((f) => (
                                                <option key={f}>{f}</option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                    <ColumnSelect
                                        label="Description"
                                        headers={preview.headers}
                                        value={profile.descriptionColumns[0] ?? null}
                                        onChange={(v) => remap({ descriptionColumns: v ? [v] : [] })}
                                    />
                                    <ColumnSelect
                                        label="Payee / counter party"
                                        headers={preview.headers}
                                        value={profile.counterpartyColumn}
                                        onChange={(v) => remap({ counterpartyColumn: v })}
                                    />
                                    <ColumnSelect
                                        label="Reference"
                                        headers={preview.headers}
                                        value={profile.referenceColumn}
                                        onChange={(v) => remap({ referenceColumn: v })}
                                    />
                                    <ColumnSelect
                                        label="Amount (one column)"
                                        headers={preview.headers}
                                        value={profile.amountColumn}
                                        onChange={(v) => remap({ amountColumn: v, debitColumn: null, creditColumn: null })}
                                    />
                                    <ColumnSelect
                                        label="Money out (if separate)"
                                        headers={preview.headers}
                                        value={profile.debitColumn}
                                        onChange={(v) => remap({ debitColumn: v, amountColumn: null })}
                                    />
                                    <ColumnSelect
                                        label="Money in (if separate)"
                                        headers={preview.headers}
                                        value={profile.creditColumn}
                                        onChange={(v) => remap({ creditColumn: v, amountColumn: null })}
                                    />
                                    <ColumnSelect
                                        label="Running balance"
                                        headers={preview.headers}
                                        value={profile.balanceColumn}
                                        onChange={(v) => remap({ balanceColumn: v })}
                                    />
                                    <ColumnSelect
                                        label="Original currency"
                                        headers={preview.headers}
                                        value={profile.currencyColumn}
                                        onChange={(v) => remap({ currencyColumn: v })}
                                    />
                                    <ColumnSelect
                                        label="Original amount"
                                        headers={preview.headers}
                                        value={profile.originalAmountColumn}
                                        onChange={(v) => remap({ originalAmountColumn: v })}
                                    />
                                    <label className="flex items-center gap-2 text-sm sm:col-span-3">
                                        <input
                                            type="checkbox"
                                            checked={profile.invertSign}
                                            onChange={(e) => remap({ invertSign: e.target.checked })}
                                        />
                                        Purchases show as positive numbers in this file (common for credit cards), so flip the sign
                                    </label>
                                </div>
                            </details>
                        )}

                        {preview.sample && preview.sample.length > 0 && (
                            <div className="overflow-x-auto">
                                <p className="mb-2 text-sm font-medium">First rows, as they'll be imported</p>
                                <table className="w-full text-sm">
                                    <tbody className="divide-y">
                                        {preview.sample.map((r, i) => (
                                            <tr key={i} className={r.duplicate ? 'text-muted-foreground line-through' : ''}>
                                                <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{day(r.date)}</td>
                                                <td className="max-w-96 truncate py-1.5 pr-3">{r.description}</td>
                                                <td className="py-1.5 pr-3 text-right">
                                                    <Money pence={r.amount_pence} />
                                                </td>
                                                <td className="text-muted-foreground py-1.5 text-right text-xs tabular-nums">
                                                    {r.balance_pence !== null ? money(r.balance_pence) : ''}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    Spending shows as negative. If it's the other way round, tick the sign option above.
                                </p>
                            </div>
                        )}

                        <div className="flex gap-2">
                            <Button
                                disabled={!preview.ok || (preview.error_count ?? 0) > 0 || preview.new === 0 || busy}
                                onClick={() => (setBusy(true), router.post('/imports/commit', {}, { onFinish: () => setBusy(false) }))}
                            >
                                Import {preview.new} transactions
                            </Button>
                        </div>
                    </div>
                )}

                <section className="space-y-2">
                    <h2 className="font-semibold">Previous imports</h2>
                    {batches.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet.</p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {batches.map((b) => {
                                const ask = (action: string, question: string) => {
                                    const reason = window.prompt(question);
                                    if (reason) router.post(`/imports/${b.id}/${action}`, { reason }, { preserveScroll: true });
                                };
                                const off = b.status === 'reverted' || b.status === 'discarded';
                                return (
                                    <li
                                        key={b.id}
                                        className={cn(
                                            'space-y-2 p-3',
                                            off && 'opacity-60',
                                            b.status === 'quarantined' && 'border-l-4 border-l-amber-400 bg-amber-50/60 dark:bg-amber-950/20',
                                        )}
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">{b.file}</span>
                                                <span className="text-muted-foreground text-xs">
                                                    {b.account} · {day(b.first)} – {day(b.last)} · {b.imported}{' '}
                                                    {b.status === 'quarantined' ? 'held' : 'imported'}, {b.duplicates} skipped · {b.at}
                                                    {b.status === 'reverted' ? ' · undone (kept for the record)' : ''}
                                                    {b.status === 'discarded' ? ' · discarded (kept for the record)' : ''}
                                                </span>
                                            </span>
                                            <span className="flex flex-wrap items-center gap-2">
                                                {b.balance_check === 'passed' && (
                                                    <CheckCircle2 className="size-4 text-emerald-600" aria-label="Balances added up" />
                                                )}
                                                {b.balance_check === 'failed' && (
                                                    <AlertTriangle
                                                        className="size-4 text-rose-600"
                                                        aria-label={b.balance_notes ?? 'Balance mismatch'}
                                                    />
                                                )}
                                                {b.status === 'committed' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            ask(
                                                                'revert',
                                                                `Undo this import? Its ${b.imported} transactions will stop counting but are kept, and can be restored. Say why (kept in the audit log).`,
                                                            )
                                                        }
                                                    >
                                                        <Undo2 /> Undo import
                                                    </Button>
                                                )}
                                                {b.status === 'reverted' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => ask('restore', 'Restore this import? Say why (kept in the audit log).')}
                                                    >
                                                        Restore
                                                    </Button>
                                                )}
                                            </span>
                                        </div>
                                        {b.status === 'quarantined' && (
                                            <div className="space-y-2 text-sm">
                                                <p className="text-amber-800 dark:text-amber-300">
                                                    Held for review, nothing counted yet: {b.balance_notes} A row may be missing, or the file may have
                                                    been edited. Usually it's best to download the statement again and import that instead.
                                                </p>
                                                <table className="w-full text-xs">
                                                    <tbody>
                                                        {b.held_rows.map((r, i) => (
                                                            <tr key={i} className="border-t">
                                                                <td className="py-1 pr-2 whitespace-nowrap">{r.date}</td>
                                                                <td className="py-1 pr-2">{r.description}</td>
                                                                <td className="py-1 pr-2 text-right tabular-nums">{money(r.amount_pence)}</td>
                                                                <td className="text-muted-foreground py-1 text-right tabular-nums">
                                                                    {r.balance_pence !== null ? money(r.balance_pence) : ''}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            ask(
                                                                'accept',
                                                                'Accept this statement even though its balance doesn’t add up? Say why (kept in the audit log).',
                                                            )
                                                        }
                                                    >
                                                        Accept anyway
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => ask('discard', 'Discard this statement? Say why (kept in the audit log).')}
                                                    >
                                                        Discard
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                        {b.review_note && b.status !== 'quarantined' && (
                                            <p className="text-muted-foreground text-xs">{b.review_note}</p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
