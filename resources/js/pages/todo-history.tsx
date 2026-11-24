import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/** A record a finding points at, in plain words. */
interface Chip {
    ref: string;
    label: string;
    href: string | null;
    state: 'ok' | 'source_changed' | 'source_missing';
}

interface FindingEvent {
    event: 'detected' | 'changed' | 'snoozed' | 'snooze_ended' | 'dismissed' | 'reopened' | 'resolved' | 'unseen' | 'detector_failed' | 'rekeyed';
    at: string | null;
    amount_pence: number | null;
    currency: string;
    actor: string;
    note: string | null;
    cause: Chip | null;
}

interface Row {
    id?: number;
    key: string;
    group: string;
    title: string;
    detail: string | null;
    href: string | null;
    amount_pence: number | null;
    currency: string;
    first_seen: string;
    resolved: string | null;
    resolution: 'resolved' | 'snoozed' | 'dismissed' | 'superseded' | null;
    days_open: number;
    reopened: number;
    state?: 'open' | 'snoozed' | 'dismissed' | 'resolved' | 'unseen';
    direction?: string | null;
    grade?: string | null;
    severity?: string | null;
    /** Your reason for snoozing it or marking it not relevant. */
    note?: string | null;
    /** What dealt with it. */
    cause?: Chip | null;
    scope?: Chip | null;
    /** The latest of its history; events_total says how many there are in all. */
    events?: FindingEvent[];
    events_total?: number;
}

interface DirectionTotal {
    direction: string;
    label: string;
    open: Record<string, number>;
    resolved: Record<string, number>;
}

interface Scope extends Chip {
    count: number;
}

const resolutionLabel = { resolved: 'Dealt with', snoozed: 'Snoozed', dismissed: 'Marked not relevant', superseded: 'Followed by another' };

/**
 * How it ended, in plain words: a gap with the bank that's joined another says so, as does a job's "less than its PO"
 * or "recharge" item that its close-out now says.
 */
function endedAs(r: Row): string {
    if (r.resolution === 'superseded' && r.key.startsWith('gap:')) return 'Joined to another gap';
    if (r.resolution === 'superseded' && (r.key.startsWith('underbilled:') || r.key.startsWith('recharge:'))) return 'Part of the job’s close-out';
    return resolutionLabel[r.resolution ?? 'resolved'];
}

const EVENT_WORDS: Record<FindingEvent['event'], string> = {
    detected: 'Found',
    changed: 'Money or dates changed',
    snoozed: 'Snoozed',
    snooze_ended: 'Snooze ended',
    dismissed: 'Marked not relevant',
    reopened: 'Back on the list',
    resolved: 'Dealt with',
    unseen: 'Not looked for in full, so left open',
    detector_failed: 'Its check went wrong, so left open',
    rekeyed: 'Followed under a new key',
};

const ACTOR_WORDS: Record<string, string> = {
    you: 'you',
    system: 'Expend',
    matcher: 'matching',
    rule: 'your rule',
    ai: 'AI',
    policy: 'an automatic decision',
    checker: 'the double-check',
};

/** Amounts in each currency, kept apart: "£40.00 and €1,000.00". */
function amounts(byCurrency: Record<string, number>): string | null {
    const parts = Object.entries(byCurrency)
        .filter(([, minor]) => minor !== 0)
        .map(([currency, minor]) => money(minor, currency, { signed: false }));

    return parts.length ? parts.join(' and ') : null;
}

function ChipLink({ chip }: { chip: Chip }) {
    const text = chip.label + (chip.state === 'source_missing' ? '' : chip.state === 'source_changed' ? ' (changed since)' : '');
    return chip.href ? (
        <Link href={chip.href} className="underline underline-offset-2">
            {text}
        </Link>
    ) : (
        <span>{text}</span>
    );
}

/** How a finding ended, in plain words, with what dealt with it. */
function ending(r: Row): string {
    if (!r.resolved) return r.state === 'unseen' ? 'Not looked for in full last time' : 'Open';
    return endedAs(r);
}

/** One finding: what it was, when it was found and how it ended, with its money and its history. */
function FindingRow({ r, all }: { r: Row; all: FindingEvent[] | null }) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    // The latest of its history came with the page; all of it when asked for.
    const events = all ?? r.events ?? [];
    const total = Math.max(r.events_total ?? 0, events.length);
    const showAll = () =>
        router.reload({
            only: ['more_events'],
            data: { events: r.id },
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    return (
        <li className="space-y-1 p-3">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
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
                        {r.resolved ? ` · ${endedAs(r).toLowerCase()} ${day(r.resolved)} (${r.days_open} days)` : ` · open ${r.days_open} days`}
                        {r.reopened ? ` · came back ${r.reopened}×` : ''}
                        {r.scope ? ` · ${r.scope.label}` : ''}
                    </span>
                    {r.resolution === 'resolved' && (
                        <span className="text-muted-foreground block text-xs">
                            Why it closed: {r.cause ? <ChipLink chip={r.cause} /> : 'your records changed'}
                        </span>
                    )}
                    {r.note && (r.resolution === 'snoozed' || r.resolution === 'dismissed') && (
                        <span className="text-muted-foreground block text-xs">Your reason: {r.note}</span>
                    )}
                </span>
                {r.amount_pence !== null && (
                    <span className="tabular-nums">{money(Math.abs(r.amount_pence), r.currency ?? 'GBP', { signed: false })}</span>
                )}
                <span
                    className={cn(
                        'rounded-full px-2 py-0.5 text-xs',
                        !r.resolved && r.state !== 'unseen' && 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
                        !r.resolved && r.state === 'unseen' && 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100',
                        r.resolution === 'resolved' && 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
                        (r.resolution === 'snoozed' || r.resolution === 'dismissed' || r.resolution === 'superseded') &&
                            'bg-muted text-muted-foreground',
                    )}
                >
                    {ending(r)}
                </span>
            </div>
            {events.length > 0 && (
                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Hide history' : `History (${total})`}
                </button>
            )}
            {open && events.length < total && (
                <p className="text-muted-foreground text-xs">
                    The latest {events.length} of {total}.{' '}
                    <button
                        type="button"
                        className="hover:text-foreground underline underline-offset-2"
                        disabled={loading || r.id === undefined}
                        onClick={showAll}
                    >
                        {loading ? 'Loading…' : 'Show all'}
                    </button>
                </p>
            )}
            {open && (
                <ol className="border-muted ml-1 space-y-1 border-l pl-3 text-xs">
                    {events.map((e, n) => (
                        <li key={n}>
                            <span className="text-muted-foreground">{e.at ? day(e.at) : ''}</span> {EVENT_WORDS[e.event] ?? e.event}
                            {e.actor && e.actor !== 'system' ? ` by ${ACTOR_WORDS[e.actor] ?? e.actor}` : ''}
                            {e.amount_pence !== null && ` · ${money(Math.abs(e.amount_pence), e.currency ?? 'GBP', { signed: false })}`}
                            {e.note && ` · ${e.note}`}
                            {e.cause && (
                                <>
                                    {' · '}
                                    <ChipLink chip={e.cause} />
                                </>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </li>
    );
}

/** What the to-do list has found over the last year, what's been dealt with, and the money involved. */
export default function TodoHistory({
    open,
    resolved,
    unseen = 0,
    by_direction = [],
    by_group,
    scopes = [],
    scope = null,
    recent,
    more_events = null,
    updating = false,
}: {
    open: number;
    resolved: number;
    unseen?: number;
    by_direction?: DirectionTotal[];
    by_group: { group: string; label: string; found: number; dealt_with: number }[];
    scopes?: Scope[];
    scope?: Chip | null;
    recent: Row[];
    /** All of one finding's history, when "Show all" asked for it. */
    more_events?: { id: number; events: FindingEvent[] } | null;
    /** What's found now is being recorded in the background: this shows what's recorded so far. */
    updating?: boolean;
}) {
    const directions = by_direction.filter((d) => amounts(d.open) || amounts(d.resolved));
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
                        charges, failed checks, unpaid invoices) with when it was found, what changed, and why it closed. Updated each night and when
                        you open this page.
                    </p>
                    {updating && (
                        <p className="text-muted-foreground mt-2 text-sm" role="status">
                            Bringing this up to date in the background: reload in a minute or two to see the latest.
                        </p>
                    )}
                </div>
                {(scopes.length > 0 || scope) && (
                    <div className="flex flex-wrap items-center gap-1.5 text-xs">
                        <span className="text-muted-foreground">Show:</span>
                        <Link
                            href="/todo/history"
                            preserveScroll
                            className={cn('rounded-full border px-2.5 py-1', !scope ? 'bg-foreground text-background' : 'hover:bg-muted')}
                        >
                            Everything
                        </Link>
                        {scope && !scopes.some((s) => s.ref === scope.ref) && (
                            <span className="bg-foreground text-background rounded-full border px-2.5 py-1">{scope.label}</span>
                        )}
                        {scopes.map((s) => (
                            <Link
                                key={s.ref}
                                href={`/todo/history?scope=${encodeURIComponent(s.ref)}`}
                                preserveScroll
                                className={cn(
                                    'rounded-full border px-2.5 py-1',
                                    scope?.ref === s.ref ? 'bg-foreground text-background' : 'hover:bg-muted',
                                )}
                            >
                                {s.label} ({s.count})
                            </Link>
                        ))}
                    </div>
                )}
                {/* Counts only: money is never one total, only by which way it goes and by currency (below). */}
                <div className="grid grid-cols-2 gap-3">
                    {(
                        [
                            ['Open', String(open), unseen ? `${unseen} not looked for in full last time` : null],
                            ['Dealt with', String(resolved), null],
                        ] as const
                    ).map(([label, value, other]) => (
                        <div key={label} className="rounded-xl border p-4">
                            <p className="text-muted-foreground text-sm">{label}</p>
                            <p className="text-xl font-semibold tabular-nums">{value}</p>
                            {other && <p className="text-muted-foreground text-xs tabular-nums">{other}</p>}
                        </div>
                    ))}
                </div>
                {directions.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="text-sm font-medium">Money, by which way it goes</h2>
                        <p className="text-muted-foreground text-xs">Never added together: each direction and currency is its own total.</p>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground text-left text-xs">
                                    <th className="py-1 font-normal"></th>
                                    <th className="py-1 font-normal">Open</th>
                                    <th className="py-1 font-normal">Dealt with</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {directions.map((d) => (
                                    <tr key={d.direction}>
                                        <td className="py-1">{d.label}</td>
                                        <td className="py-1 tabular-nums">{amounts(d.open) ?? '—'}</td>
                                        <td className="py-1 tabular-nums">{amounts(d.resolved) ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
                {by_group.length > 0 && (
                    <ul className="flex flex-wrap gap-2 text-xs">
                        {by_group.map((g) => (
                            <li key={g.group} className="rounded-full border px-3 py-1">
                                {g.label}: {g.dealt_with} of {g.found} dealt with
                            </li>
                        ))}
                    </ul>
                )}
                {recent.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Nothing found yet.</p>
                ) : (
                    (
                        [
                            ['Still open', recent.filter((r) => !r.resolved)],
                            ['Dealt with, snoozed or not relevant', recent.filter((r) => r.resolved)],
                        ] as const
                    )
                        .filter(([, rows]) => rows.length > 0)
                        .map(([heading, rows]) => (
                            <section key={heading} className="space-y-2">
                                <h2 className="text-sm font-medium">{heading}</h2>
                                <ul className="divide-y rounded-xl border text-sm">
                                    {rows.map((r) => (
                                        <FindingRow key={r.key} r={r} all={more_events && more_events.id === r.id ? more_events.events : null} />
                                    ))}
                                </ul>
                            </section>
                        ))
                )}
            </div>
        </AppLayout>
    );
}
