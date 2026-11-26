import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { ClientMergeSheet } from '@/components/client-merge-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson, problemText } from '@/lib/api';
import { shortMoney } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type ClientAdded, type ClientRow, type ClientsIndexProps, type DuplicatePair } from '@/types/clients';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Plus, Search, Users } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

// Other pages list clients in this shape too.
export type { ClientRow } from '@/types/clients';

const FILTERS: { key: ClientsIndexProps['filters']['show']; label: string; count: (c: ClientsIndexProps['counts']) => number }[] = [
    { key: 'owe', label: 'Owe you', count: (c) => c.owe },
    { key: 'to_invoice', label: 'To invoice', count: (c) => c.to_invoice },
    { key: 'all', label: 'All', count: (c) => c.all },
];

/** The first thing to know about a client: what they owe (red when overdue), else jobs to invoice, else recent work. */
function rowLine(c: ClientRow): { text: string; tone: 'owed' | 'late' | 'invoice' | 'quiet' } | null {
    if (c.owed_words) return { text: c.owed_words, tone: c.overdue > 0 ? 'late' : 'owed' };
    if (c.to_invoice > 0) return { text: `${c.to_invoice} ${c.to_invoice === 1 ? 'job' : 'jobs'} to invoice`, tone: 'invoice' };
    if (c.recent_words) return { text: c.recent_words, tone: 'quiet' };
    return null;
}

const TONE = {
    late: 'font-medium text-rose-700 dark:text-rose-400',
    owed: 'text-foreground',
    invoice: 'font-medium text-amber-800 dark:text-amber-300',
    quiet: 'text-muted-foreground',
};

/**
 * Your clients: who owes you (overdue first), who has work to invoice, and the rest by their latest job. Search finds
 * a client by any name they go by (on invoices, merged into them, or the name they pay you under). Two that may be the
 * same are offered to compare and merge.
 */
export default function Clients({ clients, counts, filters, figures, duplicates }: ClientsIndexProps) {
    const [q, setQ] = useState(filters.q ?? '');
    const [adding, setAdding] = useState(false);
    const [comparing, setComparing] = useState<DuplicatePair | null>(null);
    const first = useRef(true);
    const made = new Map(figures.map((f) => [f.client_id, f.made_pence]));

    // Search as you type, a moment after the last key.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(
            () =>
                router.get(
                    '/clients',
                    { ...(q.trim() ? { q: q.trim() } : {}), ...(filters.show !== 'all' ? { show: filters.show } : {}) },
                    { preserveState: true, replace: true },
                ),
            300,
        );
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only the typed words start a search
    }, [q]);

    const show = (key: string) =>
        router.get(
            '/clients',
            { ...(q.trim() ? { q: q.trim() } : {}), ...(key !== 'all' ? { show: key } : {}) },
            { preserveState: true, preserveScroll: true },
        );
    const notSame = (d: DuplicatePair) =>
        router.post(`/clients/${d.a.id}/not-same`, { other_id: d.b.id }, { preserveScroll: true, onSuccess: () => setComparing(null) });

    return (
        <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }]}>
            <Head title="Clients" />
            <div className="mx-auto w-full max-w-4xl space-y-4 px-4 pt-4 pb-24 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">Clients</h1>
                        <p className="text-muted-foreground text-sm">Who owes you, what’s to invoice, and what each client is worth.</p>
                    </div>
                    <Button className="h-11" onClick={() => setAdding(true)}>
                        <Plus /> New client
                    </Button>
                </div>

                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                    <Input
                        type="search"
                        aria-label="Search clients"
                        className="h-11 pl-9"
                        placeholder="Search by any name they go by"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                </div>

                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Show">
                    {FILTERS.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            role="radio"
                            aria-checked={filters.show === f.key}
                            onClick={() => show(f.key)}
                            className={cn(
                                'min-h-11 rounded-full border px-4 text-sm',
                                filters.show === f.key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                            )}
                        >
                            {f.label} ({f.count(counts)})
                        </button>
                    ))}
                </div>

                {duplicates.length > 0 && (
                    <section
                        aria-label="Possible duplicates"
                        className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40"
                    >
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <Users className="size-4" aria-hidden />{' '}
                            {duplicates.length === 1 ? 'These may be the same client' : 'These may be the same clients'}
                        </p>
                        <ul className="space-y-3">
                            {duplicates.map((d) => (
                                <li key={`${d.a.id}:${d.b.id}`} className="space-y-2">
                                    <p className="text-sm break-words">
                                        {d.text}
                                        <span className="text-muted-foreground">
                                            {d.why === 'email' ? ' (the same billing email)' : ' (the same name)'}
                                        </span>
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant="outline" className="h-11" onClick={() => setComparing(d)}>
                                            Compare
                                        </Button>
                                        <Button variant="ghost" className="h-11" onClick={() => notSame(d)}>
                                            Not the same
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <ul className="divide-y rounded-xl border">
                    {clients.map((c) => {
                        const line = rowLine(c);
                        const madeThisYear = made.get(c.id) ?? 0;
                        return (
                            <li key={c.id}>
                                <Link href={`/clients/${c.id}`} className="hover:bg-muted/40 flex min-h-14 items-center gap-3 px-3 py-2.5">
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium break-words">{c.name}</span>
                                        {line && <span className={cn('block text-sm break-words', TONE[line.tone])}>{line.text}</span>}
                                        {c.for && <span className="text-muted-foreground block text-xs break-words">{c.for}</span>}
                                        {q.trim() && c.merged_names.length > 0 && (
                                            <span className="text-muted-foreground block text-xs break-words">Also: {c.merged_names.join(', ')}</span>
                                        )}
                                    </span>
                                    {madeThisYear > 0 && (
                                        <span className="text-muted-foreground shrink-0 text-right text-xs tabular-nums">
                                            {shortMoney(madeThisYear)}
                                            <span className="block">this year</span>
                                        </span>
                                    )}
                                    <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                </Link>
                            </li>
                        );
                    })}
                    {clients.length === 0 && (
                        <li className="text-muted-foreground p-6 text-center text-sm">
                            {q.trim() ? `No client goes by “${q.trim()}”.` : filters.show !== 'all' ? 'Nobody here right now.' : 'No clients yet.'}
                        </li>
                    )}
                </ul>
            </div>

            <NewClientSheet open={adding} onClose={() => setAdding(false)} start={q.trim()} />
            {comparing && (
                <ClientMergeSheet
                    a={comparing.a}
                    b={comparing.b}
                    keepFirst="a"
                    open
                    onClose={() => setComparing(null)}
                    onNotSame={() => notSame(comparing)}
                />
            )}
        </AppLayout>
    );
}

/** New client: its name, the name on invoices and the billing email. A name you have already asks first. */
function NewClientSheet({ open, onClose, start }: { open: boolean; onClose: () => void; start: string }) {
    const id = useId();
    const [name, setName] = useState('');
    const [invoiceName, setInvoiceName] = useState('');
    const [email, setEmail] = useState('');
    const [same, setSame] = useState<ClientAdded | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setName(start);
        setInvoiceName('');
        setEmail('');
        setSame(null);
        setProblem(null);
    }, [open, start]);

    const add = async (different = false) => {
        if (!name.trim()) return setProblem('Give the client a name.');
        setBusy(true);
        setProblem(null);
        try {
            const answer = await postJson<ClientAdded>('/clients', {
                name: name.trim(),
                invoice_name: invoiceName.trim() || null,
                billing_email: email.trim() || null,
                different,
            });
            if (answer.existing && !different) return setSame(answer);
            onClose();
            router.visit(`/clients/${answer.id}`);
        } catch (e) {
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title="New client"
            dirty={!same && (name.trim() !== start || invoiceName !== '' || email !== '')}
            discardNote="What you typed here is lost."
        >
            <SheetBody className="space-y-3">
                {same ? (
                    <div
                        className="space-y-2 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/40"
                        role="group"
                        aria-label="A client with that name"
                    >
                        <p>You already have {same.name}.</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${id}-name`}>Name</Label>
                            <Input
                                id={`${id}-name`}
                                autoFocus
                                className="h-11"
                                maxLength={120}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${id}-invoice`}>Name on invoices (if different)</Label>
                            <Input
                                id={`${id}-invoice`}
                                className="h-11"
                                maxLength={200}
                                placeholder="For example: Acme Events Ltd"
                                value={invoiceName}
                                onChange={(e) => setInvoiceName(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${id}-email`}>Billing email</Label>
                            <Input
                                id={`${id}-email`}
                                type="email"
                                inputMode="email"
                                className="h-11"
                                placeholder="accounts@client.co.uk"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                        </div>
                    </>
                )}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                {same ? (
                    <>
                        <Button
                            className="h-11"
                            onClick={() => {
                                onClose();
                                router.visit(`/clients/${same.id}`);
                            }}
                        >
                            Use {same.name}
                        </Button>
                        <Button variant="outline" className="h-11" disabled={busy} onClick={() => void add(true)}>
                            It’s a different client
                        </Button>
                    </>
                ) : (
                    <Button className="h-11" disabled={busy} onClick={() => void add()}>
                        Add client
                    </Button>
                )}
            </SheetFooter>
        </ResponsiveSheet>
    );
}
