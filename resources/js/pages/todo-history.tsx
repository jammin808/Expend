import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Row {
    key: string;
    group: string;
    title: string;
    detail: string | null;
    href: string | null;
    amount_pence: number | null;
    first_seen: string;
    resolved: string | null;
    resolution: 'resolved' | 'snoozed' | 'dismissed' | null;
    days_open: number;
    reopened: number;
}

const resolutionLabel = { resolved: 'Dealt with', snoozed: 'Snoozed', dismissed: 'Marked not relevant' };

/** What the to-do list has found over the last year, what's been dealt with, and the money involved. */
export default function TodoHistory({
    open,
    resolved,
    money_open,
    money_resolved,
    by_group,
    recent,
}: {
    open: number;
    resolved: number;
    money_open: number;
    money_resolved: number;
    by_group: { group: string; label: string; found: number; dealt_with: number }[];
    recent: Row[];
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'To do', href: '/todo' },
                { title: 'History', href: '/todo/history' },
            ]}
        >
            <Head title="Findings history" />
            <div className="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Found and dealt with</h1>
                    <p className="text-muted-foreground text-sm">
                        Everything the to-do list has found in the last year (money you may be owed, gaps with the bank, missing POs, duplicate
                        charges, failed checks, unpaid invoices) with when it was found and when it was dealt with. Updated each night and when you
                        open this page.
                    </p>
                </div>
                <div className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Open', String(open)],
                        ['Dealt with', String(resolved)],
                        ['Money open', money(money_open, 'GBP', { signed: false })],
                        ['Money dealt with', money(money_resolved, 'GBP', { signed: false })],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border p-4">
                            <p className="text-muted-foreground text-sm">{label}</p>
                            <p className="text-xl font-semibold tabular-nums">{value}</p>
                        </div>
                    ))}
                </div>
                {by_group.length > 0 && (
                    <ul className="flex flex-wrap gap-2 text-xs">
                        {by_group.map((g) => (
                            <li key={g.group} className="rounded-full border px-3 py-1">
                                {g.label}: {g.dealt_with} of {g.found} dealt with
                            </li>
                        ))}
                    </ul>
                )}
                <ul className="divide-y rounded-xl border text-sm">
                    {recent.map((r) => (
                        <li key={r.key} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-3">
                            <span className="min-w-0 flex-1">
                                {r.href ? (
                                    <Link href={r.href} className="font-medium underline-offset-2 hover:underline">
                                        {r.title}
                                    </Link>
                                ) : (
                                    <span className="font-medium">{r.title}</span>
                                )}
                                <span className="text-muted-foreground block text-xs">
                                    {r.group} · found {day(r.first_seen)}
                                    {r.resolved
                                        ? ` · ${resolutionLabel[r.resolution ?? 'resolved'].toLowerCase()} ${day(r.resolved)} (${r.days_open} days)`
                                        : ` · open ${r.days_open} days`}
                                    {r.reopened ? ` · came back ${r.reopened}×` : ''}
                                </span>
                            </span>
                            {r.amount_pence !== null && (
                                <span className="tabular-nums">{money(Math.abs(r.amount_pence), 'GBP', { signed: false })}</span>
                            )}
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-xs',
                                    !r.resolved && 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
                                    r.resolution === 'resolved' && 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
                                    (r.resolution === 'snoozed' || r.resolution === 'dismissed') && 'bg-muted text-muted-foreground',
                                )}
                            >
                                {r.resolved ? resolutionLabel[r.resolution ?? 'resolved'] : 'Open'}
                            </span>
                        </li>
                    ))}
                    {recent.length === 0 && <li className="text-muted-foreground p-6 text-center">Nothing found yet.</li>}
                </ul>
            </div>
        </AppLayout>
    );
}
