import { Money } from '@/components/money';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ChevronRight } from 'lucide-react';
import { useState } from 'react';

interface Action {
    code: string;
    id: string;
    label: string;
    style: 'default' | 'ghost';
}
interface Item {
    key: string;
    group: string;
    priority: 1 | 2 | 3;
    title: string;
    detail: string | null;
    href: string | null;
    actions: Action[];
    amount?: number | null;
    currency?: string;
    /** Comes back under the same key whenever it applies again, so it can be snoozed but not marked not relevant. */
    snooze_only?: boolean;
}

const PRIORITY = {
    1: { label: 'Do first', dot: 'bg-rose-500' },
    2: { label: 'This week', dot: 'bg-amber-500' },
    3: { label: 'When you can', dot: 'bg-slate-400' },
};

/** Everything that needs you, in one list, most urgent first, with one-tap actions. */
export default function Todo({
    items,
    counts,
    total,
    groups,
    all = [],
}: {
    items: Item[];
    counts: Record<string, number>;
    total: number;
    groups: Record<string, string>;
    /** The kinds sent in full because you asked for all of them; of the rest, only the first few are sent. */
    all?: string[];
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [filter, setFilter] = useState<string | null>(null);
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const [loading, setLoading] = useState<string | null>(null);
    // Five of each kind unless you ask for more, so one busy kind doesn't bury the rest.
    const seen: Record<string, number> = {};
    const shown = (filter ? items.filter((i) => i.group === filter) : items).filter((i) => {
        seen[i.group] = (seen[i.group] ?? 0) + 1;
        return filter || open[i.group] || seen[i.group] <= 5;
    });
    const sent = (g: string) => items.filter((i) => i.group === g).length;
    const showing = (g: string) => (filter === g || open[g] ? sent(g) : Math.min(sent(g), 5));
    // Counted on the server, so it includes the ones not sent yet. Payments are gone through in the Inbox (see below).
    const hidden = (g: string) => (g === 'payments' ? sent(g) : Math.max(sent(g), counts[g] ?? 0)) - showing(g);
    const showMore = (g: string) => {
        setOpen({ ...open, [g]: true });
        if (g === 'payments' || all.includes(g) || sent(g) >= (counts[g] ?? 0)) return;
        // Only the first few of each kind came with the page: ask for the rest of this one. Kept in the address, so
        // it stays listed after you act on an item.
        router.get(
            '/todo',
            { all: [...all, g].join(',') },
            {
                only: ['items', 'counts', 'total', 'all'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(g),
                onFinish: () => setLoading(null),
            },
        );
    };
    const act = (a: Action, key: string) =>
        router.post(
            `/todo/${a.code}/${encodeURIComponent(a.id)}`,
            {},
            { preserveScroll: true, onStart: () => setBusy(key + a.code), onFinish: () => setBusy(null) },
        );
    // Snooze (days) or not relevant (no days). The server confirms it; this gives that confirmation an Undo (the
    // toaster shows the same message once, keeping the one with the Undo), so its words must match the server's.
    const dismiss = (i: Item, days?: number) =>
        router.post('/todo/dismiss', days ? { key: i.key, days } : { key: i.key }, {
            preserveScroll: true,
            onSuccess: () =>
                toast(days ? `Snoozed for ${days} days.` : 'Marked not relevant.', 'success', {
                    label: 'Undo',
                    run: () => router.post('/todo/dismiss', { key: i.key, undo: true }, { preserveScroll: true }),
                }),
            onError: (errors) => toast(Object.values(errors)[0] ?? 'That didn’t work. Try again.', 'error'),
        });

    return (
        <AppLayout breadcrumbs={[{ title: 'To do', href: '/todo' }]}>
            <Head title="To do" />
            <div className="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">To do</h1>
                    <p className="text-muted-foreground text-sm">
                        {total === 0
                            ? 'Nothing needs you right now.'
                            : `${total} ${total === 1 ? 'thing needs' : 'things need'} you, most urgent first.`}{' '}
                        The same list comes to Telegram each Monday, with buttons.{' '}
                        <Link href="/todo/history" className="underline underline-offset-2">
                            What’s been found and dealt with
                        </Link>
                    </p>
                </div>

                {Object.keys(counts).length > 1 && (
                    <div className="flex flex-wrap gap-1.5 text-xs">
                        <button
                            type="button"
                            onClick={() => setFilter(null)}
                            className={cn('rounded-full border px-2.5 py-1', !filter ? 'bg-foreground text-background' : 'hover:bg-muted')}
                        >
                            All ({total})
                        </button>
                        {Object.entries(counts).map(([g, n]) => (
                            <button
                                key={g}
                                type="button"
                                onClick={() => setFilter(filter === g ? null : g)}
                                className={cn('rounded-full border px-2.5 py-1', filter === g ? 'bg-foreground text-background' : 'hover:bg-muted')}
                            >
                                {groups[g]} ({n})
                            </button>
                        ))}
                    </div>
                )}

                {total === 0 && (
                    <div className="flex flex-col items-center gap-2 rounded-xl border p-10 text-center">
                        <CheckCircle2 className="size-10 text-emerald-500" />
                        <p className="font-medium">All clear</p>
                        <p className="text-muted-foreground text-sm">New payments, documents and jobs will appear here as they arrive.</p>
                    </div>
                )}

                {([1, 2, 3] as const).map((p) => {
                    const list = shown.filter((i) => i.priority === p);
                    if (!list.length) return null;
                    return (
                        <section key={p} className="space-y-2">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <span className={cn('size-2 rounded-full', PRIORITY[p].dot)} /> {PRIORITY[p].label}
                            </h2>
                            <ul className="divide-y rounded-xl border">
                                {list.map((i) => (
                                    <li key={i.key} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-muted-foreground text-[11px] tracking-wide uppercase">{groups[i.group]}</p>
                                            {i.href ? (
                                                <Link href={i.href} className="flex items-center gap-1 font-medium hover:underline">
                                                    <span className="truncate">{i.title}</span>
                                                    <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                                </Link>
                                            ) : (
                                                <p className="font-medium">{i.title}</p>
                                            )}
                                            {i.detail && <p className="text-muted-foreground text-sm">{i.detail}</p>}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {i.amount !== undefined && i.amount !== null && (
                                                <Money pence={i.amount} currency={i.currency ?? 'GBP'} className="text-sm" />
                                            )}
                                            <span className="flex flex-col items-end text-[11px]">
                                                <button
                                                    type="button"
                                                    className="text-muted-foreground hover:text-foreground"
                                                    onClick={() => dismiss(i, 7)}
                                                >
                                                    Snooze a week
                                                </button>
                                                {!i.snooze_only && (
                                                    <button
                                                        type="button"
                                                        className="text-muted-foreground hover:text-foreground"
                                                        onClick={() => dismiss(i)}
                                                    >
                                                        Not relevant
                                                    </button>
                                                )}
                                            </span>
                                            {i.actions.map((a) => (
                                                <Button
                                                    key={a.code}
                                                    size="sm"
                                                    variant={a.style === 'ghost' ? 'ghost' : 'outline'}
                                                    disabled={busy !== null}
                                                    onClick={() => act(a, i.key)}
                                                >
                                                    {busy === i.key + a.code ? '…' : a.label}
                                                </Button>
                                            ))}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {[...new Set(list.map((i) => i.group))]
                                .filter((g) => hidden(g) > 0)
                                .map((g) =>
                                    all.includes(g) && showing(g) === sent(g) ? (
                                        <p key={g} className="text-muted-foreground text-xs">
                                            Showing the first {sent(g)} of {counts[g]}: {groups[g].toLowerCase()}.
                                        </p>
                                    ) : (
                                        <button
                                            key={g}
                                            type="button"
                                            disabled={loading !== null}
                                            className="text-muted-foreground mr-3 text-xs underline underline-offset-2"
                                            onClick={() => showMore(g)}
                                        >
                                            {loading === g ? 'Loading…' : `Show ${hidden(g)} more: ${groups[g].toLowerCase()}`}
                                        </button>
                                    ),
                                )}
                            {p === 2 &&
                                (counts.payments ?? 0) > list.filter((i) => i.group === 'payments').length &&
                                (!filter || filter === 'payments') && (
                                    <p className="text-muted-foreground text-xs">
                                        Showing the first payments; there are {counts.payments} to decide.{' '}
                                        <Link href="/inbox" className="underline underline-offset-2">
                                            Go through them in the Inbox
                                        </Link>
                                        .
                                    </p>
                                )}
                        </section>
                    );
                })}
            </div>
        </AppLayout>
    );
}
