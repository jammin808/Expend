import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Money } from '@/components/money';
import { pressTodo, toast, useFlashClaim } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { isButton, isLink, localPath, postQuietly, withoutLinks } from '@/lib/todo';
import { cn } from '@/lib/utils';
import { type FollowUpButton, type FollowUpLink, type TodoAction } from '@/types';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ChevronRight } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';

interface Item {
    key: string;
    group: string;
    priority: 1 | 2 | 3;
    title: string;
    detail: string | null;
    href: string | null;
    actions: TodoAction[];
    amount?: number | null;
    currency?: string;
    /** Comes back under the same key whenever it applies again, so it can be snoozed but not marked not relevant. */
    snooze_only?: boolean;
    /** What it's about in the shared vocabulary (findings only; routine items have none). */
    code?: string;
    direction?: string;
    grade?: string;
    grade_basis?: string | null;
    severity?: 'critical' | 'important' | 'info';
    /** What it rests on, in plain words. */
    why_refs?: WhyRef[];
    /** The reasons offered for marking it not relevant. */
    reasons?: Record<string, string>;
    /** The reasons offered for snoozing it ("Not finished yet" only for "Is it done?"). */
    snooze_reasons?: Record<string, string>;
    /** Shown below the fold: a new check's finding, or one demoted by its false positives. */
    below_fold?: boolean;
}

/** A kind of finding that can be shown below the fold or with the rest (Intel\DetectorLevels). */
interface DetectorLevel {
    code: string;
    name: string;
    level: 'normal' | 'info' | 'raised' | 'demoted';
    reason: string | null;
    /** Fingerprints of showing it in full or below the fold, as shown. */
    fp_raise?: string | null;
    fp_lower?: string | null;
}

interface WhyRef {
    ref: string;
    label: string;
    href: string | null;
    state: 'ok' | 'source_changed' | 'source_missing';
    check: string | null;
}

/** Snoozing or marking not relevant, waiting for its reason. */
interface Asking {
    item: Item;
    days?: number;
}

/** A press that asks something before it does anything (Cancelled…: do they owe you anything?), with its answers. */
interface Question {
    text: string;
    choices: FollowUpButton[];
    links: FollowUpLink[];
}

const CHECK_WORDS: Record<string, string> = {
    passed: 'checked against its source',
    partial: 'partly checked',
    failed: 'didn’t match its source',
    not_checkable: 'can’t be checked word for word',
    unchecked: 'not checked yet',
};

const GRADE_STYLE: Record<string, string> = {
    certain: 'border-emerald-300 text-emerald-800 dark:border-emerald-800 dark:text-emerald-300',
    strong: 'border-emerald-200 text-emerald-700 dark:border-emerald-900 dark:text-emerald-400',
    likely: 'border-amber-200 text-amber-800 dark:border-amber-900 dark:text-amber-300',
    possible: 'border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200',
    weak: 'border-rose-300 text-rose-800 dark:border-rose-900 dark:text-rose-300',
    unmeasured: 'text-muted-foreground',
};

/** What the money checks looked at this time, so it shows they ran even when they found nothing (null: it went wrong). */
interface Checked {
    hmrc: number | null;
    jobs: number | null;
    years: number | null;
    closeout?: number | null;
}

const counted = (n: number | null, one: string, many: string, what = '') =>
    n === null
        ? `${many}${what && ` ${what}`}: couldn’t check this time`
        : `${n.toLocaleString('en-GB')} ${n === 1 ? one : many}${what && ` ${what}`}`;

const PRIORITY = {
    1: { label: 'Do first', dot: 'bg-rose-500' },
    2: { label: 'This week', dot: 'bg-amber-500' },
    3: { label: 'When you can', dot: 'bg-slate-400' },
};

/** A small text button that's still big enough to hit with a thumb. */
const quiet = 'text-muted-foreground hover:text-foreground inline-flex min-h-10 items-center rounded-md px-2 text-xs';

/** A link to a page of this app as an Inertia link; anything else as a plain one. */
function PageLink({ href, className, onClick, children }: { href: string; className?: string; onClick?: () => void; children: React.ReactNode }) {
    const path = localPath(href);
    return path !== null ? (
        <Link href={path} className={className} onClick={onClick}>
            {children}
        </Link>
    ) : (
        <a href={href} className={className} onClick={onClick}>
            {children}
        </a>
    );
}

/** Everything that needs you, in one list, most urgent first, with one-tap actions. */
export default function Todo({
    items,
    counts,
    total,
    groups,
    all = [],
    checked,
    directions = {},
    snooze_reasons = {},
    below = [],
    more_below,
    detector_levels = [],
}: {
    items: Item[];
    counts: Record<string, number>;
    total: number;
    groups: Record<string, string>;
    /** The kinds sent in full because you asked for all of them; of the rest, only the first few are sent. */
    all?: string[];
    checked?: Checked;
    /** How each direction of money reads beside an amount: "you may be owed". */
    directions?: Record<string, string>;
    /** The reasons offered for a snooze (each item also carries its own). */
    snooze_reasons?: Record<string, string>;
    /** Found below the fold by what the list works out itself (a close-out of its own, a kind demoted). */
    below?: Item[];
    /** What the new checks found, below the fold: loaded once the list is on the page. */
    more_below?: { items: Item[]; counts: Record<string, number> };
    /** The kinds of finding that can be raised or lowered, with how each is shown now. */
    detector_levels?: DetectorLevel[];
}) {
    const formId = useId();
    const [busy, setBusy] = useState<string | null>(null);
    const [why, setWhy] = useState<Record<string, boolean>>({});
    // What a sheet is about stays while it slides away; whether it's open is kept apart.
    const [asking, setAsking] = useState<Asking | null>(null);
    const [askOpen, setAskOpen] = useState(false);
    const [question, setQuestion] = useState<Question | null>(null);
    const [questionOpen, setQuestionOpen] = useState(false);
    const [other, setOther] = useState('');
    const [filter, setFilter] = useState<string | null>(null);
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const [loading, setLoading] = useState<string | null>(null);

    // Cancelled… only asks (do they owe you anything?): its answers come back with the page, and are asked here in a
    // sheet rather than in a toast that could go before you've answered.
    useFlashClaim((flash) => {
        const next = flash.follow_up ?? [];
        const choices = next.filter(isButton).filter((b) => b.code === 'job_cancel');
        if (choices.length === 0) return false;
        setQuestion({ text: withoutLinks(flash.success ?? '').text, choices, links: next.filter(isLink) });
        setQuestionOpen(true);
        return true;
    });

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
    // A press: what it did comes back as the page's message, with Undo and what to do next (the toaster shows them),
    // or why it wasn't done, in the server's words.
    const act = (b: { code: string; id: string; fp?: string | null }, key: string, after?: () => void) =>
        pressTodo(b, {
            start: () => setBusy(key),
            finish: () => {
                setBusy(null);
                after?.();
            },
        });
    // Snooze (days) or not relevant (no days), with your reason (kept with it). The server confirms it; this gives
    // that confirmation an Undo (the toaster shows the same message once, keeping the one with the Undo), so its words
    // must match the server's.
    const dismiss = (i: Item, days: number | undefined, reason: string, note?: string) =>
        postQuietly(
            '/todo/dismiss',
            { key: i.key, reason, ...(days ? { days } : {}), ...(note ? { note } : {}) },
            {
                start: () => setBusy(i.key + ':dismiss'),
                finish: () => setBusy(null),
                problem: (words) => toast(words, 'error'),
                success: () => {
                    setAskOpen(false);
                    setOther('');
                    toast(days ? `Snoozed for ${days} days.` : 'Marked not relevant.', 'success', {
                        label: 'Undo',
                        run: () => postQuietly('/todo/dismiss', { key: i.key, undo: true }, { problem: (words) => toast(words, 'error') }),
                    });
                },
            },
        );
    const reasonsFor = (a: Asking) => (a.days ? (a.item.snooze_reasons ?? snooze_reasons) : (a.item.reasons ?? { other: 'Other' }));
    const submitOther = (e: FormEvent) => {
        e.preventDefault();
        if (asking) dismiss(asking.item, asking.days, 'other', other.trim() || undefined);
    };
    const ask = (item: Item, days?: number) => {
        setOther('');
        setAsking({ item, days });
        setAskOpen(true);
    };
    // Below the fold: what the list worked out, then what the new checks found once it's loaded, ranked as found.
    const belowAll = [...below, ...(more_below?.items ?? [])];
    const belowKinds = detector_levels.filter((d) => belowAll.some((i) => i.code === d.code));
    const raised = detector_levels.filter((d) => d.level === 'raised');
    // A detector's code as a button's id holds it: no full stop in a to-do button's id.
    const level = (code: string, up: boolean) => {
        const d = detector_levels.find((x) => x.code === code);
        act({ code: up ? 'det_raise' : 'det_lower', id: code.replace(/\./g, ':'), fp: up ? d?.fp_raise : d?.fp_lower }, `level:${code}`);
    };

    // The item's main press is the first that isn't a quieter one: filled, where the thumb goes first.
    const button = (i: Item, a: TodoAction, main: boolean) => {
        const key = `${i.key}|${a.code}|${a.id}`;
        // Every other button has an outline: on a phone a bare word doesn't look like something to tap.
        const variant = main ? 'default' : 'outline';
        if (a.code === 'link' && a.href)
            return (
                <Button key={key} asChild variant={variant} className="h-10">
                    <PageLink href={a.href}>{a.label}</PageLink>
                </Button>
            );
        return (
            <Button key={key} variant={variant} className="h-10" disabled={busy !== null} onClick={() => act(a, key)}>
                {busy === key ? '…' : a.label}
            </Button>
        );
    };

    const row = (i: Item) => {
        const main = i.actions.find((a) => a.style !== 'ghost' && a.code !== 'link');
        const amount = i.amount !== undefined && i.amount !== null;
        return (
            <li key={i.key} className="space-y-2 p-3">
                <div className="flex items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="text-muted-foreground text-[11px] tracking-wide uppercase">{groups[i.group]}</p>
                        {i.href ? (
                            <PageLink href={i.href} className="flex min-h-10 items-center gap-1 font-medium break-words hover:underline">
                                <span className="min-w-0">{i.title}</span>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
                            </PageLink>
                        ) : (
                            <p className="font-medium break-words">{i.title}</p>
                        )}
                        {i.detail && <p className="text-muted-foreground text-sm break-words">{i.detail}</p>}
                    </div>
                    {amount && <Money pence={i.amount!} currency={i.currency ?? 'GBP'} className="shrink-0 pt-5 text-sm" />}
                </div>
                {(i.grade || (amount && i.direction && directions[i.direction]) || (i.why_refs?.length ?? 0) > 0) && (
                    <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                        {i.grade && (
                            <span className={cn('rounded-full border px-1.5 py-px', GRADE_STYLE[i.grade])} title={i.grade_basis ?? undefined}>
                                {i.grade[0].toUpperCase() + i.grade.slice(1)}
                            </span>
                        )}
                        {amount && i.direction && directions[i.direction] && <span className="text-muted-foreground">{directions[i.direction]}</span>}
                        {(i.why_refs?.length ?? 0) > 0 && (
                            <button
                                type="button"
                                className={cn(quiet, 'underline underline-offset-2')}
                                aria-expanded={!!why[i.key]}
                                onClick={() => setWhy({ ...why, [i.key]: !why[i.key] })}
                            >
                                {why[i.key] ? 'Hide why' : 'Why'}
                            </button>
                        )}
                    </div>
                )}
                {why[i.key] && (
                    <div className="bg-muted/40 space-y-1 rounded-md p-2 text-xs">
                        {i.grade && i.grade_basis && (
                            <p>
                                {i.grade[0].toUpperCase() + i.grade.slice(1)}: {i.grade_basis}.
                            </p>
                        )}
                        <ul className="space-y-0.5">
                            {i.why_refs?.map((r) => (
                                <li key={r.ref} className="break-words">
                                    {r.state === 'source_missing' ? (
                                        // Its label says so: "An invoice that’s no longer there".
                                        <span className="text-rose-700 dark:text-rose-400">{r.label}</span>
                                    ) : r.href ? (
                                        <Link href={r.href} className="inline-flex min-h-10 items-center underline underline-offset-2">
                                            {r.label}
                                        </Link>
                                    ) : (
                                        r.label
                                    )}
                                    {r.state === 'source_changed' && <span className="text-amber-700 dark:text-amber-400"> · changed since</span>}
                                    {r.check && CHECK_WORDS[r.check] && <span className="text-muted-foreground"> · {CHECK_WORDS[r.check]}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    {i.actions.map((a) => button(i, a, a === main))}
                    <span className="ml-auto flex flex-wrap justify-end">
                        <button type="button" className={quiet} disabled={busy !== null} onClick={() => ask(i, 7)}>
                            Snooze a week
                        </button>
                        {!i.snooze_only && (
                            <button type="button" className={quiet} disabled={busy !== null} onClick={() => ask(i)}>
                                Not relevant
                            </button>
                        )}
                    </span>
                </div>
            </li>
        );
    };

    const reasons = asking ? Object.entries(reasonsFor(asking)).filter(([code]) => code !== 'other') : [];
    // The server's question: "Do they owe you anything for Arena load-out?", then what else it said.
    const [questionTitle, ...questionRest] = (question?.text ?? '').split(/(?<=\?)\s+/);

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
                        The same list comes to Telegram each Monday, with buttons.
                    </p>
                    <Link
                        href="/todo/history"
                        className="text-muted-foreground inline-flex min-h-10 items-center text-sm underline underline-offset-2"
                    >
                        What’s been found and dealt with
                    </Link>
                </div>

                {Object.keys(counts).length > 1 && (
                    // One row that scrolls sideways on a phone, so the list starts near the top.
                    <div className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 text-xs md:mx-0 md:flex-wrap md:overflow-visible md:px-0">
                        <button
                            type="button"
                            onClick={() => setFilter(null)}
                            className={cn('min-h-10 shrink-0 rounded-full border px-3', !filter ? 'bg-foreground text-background' : 'hover:bg-muted')}
                        >
                            All ({total})
                        </button>
                        {Object.entries(counts).map(([g, n]) => (
                            <button
                                key={g}
                                type="button"
                                onClick={() => setFilter(filter === g ? null : g)}
                                className={cn(
                                    'min-h-10 shrink-0 rounded-full border px-3 whitespace-nowrap',
                                    filter === g ? 'bg-foreground text-background' : 'hover:bg-muted',
                                )}
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
                            <ul className="divide-y rounded-xl border">{list.map(row)}</ul>
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
                                            className={cn(quiet, 'mr-3 px-0 underline underline-offset-2')}
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
                                        <Link href="/inbox" className="inline-flex min-h-10 items-center underline underline-offset-2">
                                            Go through them in the Inbox
                                        </Link>
                                    </p>
                                )}
                        </section>
                    );
                })}

                <section className="space-y-2 border-t pt-4">
                    <h2 className="text-sm font-semibold">Found by the newer checks</h2>
                    <p className="text-muted-foreground text-xs">
                        These checks are new (or were often wrong), so what they find is shown here, kept off Telegram, until you show a kind with the
                        rest. When more than one in ten of a kind is marked not relevant because it was wrong, it comes back here on its own.
                    </p>
                    <Deferred data="more_below" fallback={<p className="text-muted-foreground text-xs">Looking…</p>}>
                        <>
                            {belowAll.length === 0 && <p className="text-muted-foreground text-xs">Nothing found by them right now.</p>}
                            {belowKinds.length > 0 && (
                                <ul className="space-y-1 text-xs">
                                    {belowKinds.map((d) => (
                                        <li key={d.code} className="flex flex-wrap items-center gap-x-2">
                                            <span className="font-medium">{d.name}</span>
                                            <span className="text-muted-foreground">
                                                {belowAll.filter((i) => i.code === d.code).length} found
                                                {d.reason ? ` · ${d.reason}` : ''}
                                            </span>
                                            <button
                                                type="button"
                                                className={cn(quiet, 'px-0 underline underline-offset-2')}
                                                disabled={busy !== null}
                                                onClick={() => level(d.code, true)}
                                            >
                                                Show with the rest
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {belowAll.length > 0 && <ul className="divide-y rounded-xl border">{belowAll.map(row)}</ul>}
                        </>
                    </Deferred>
                    {raised.length > 0 && (
                        <p className="text-muted-foreground text-xs">
                            Shown with the rest because you raised {raised.length === 1 ? 'it' : 'them'}:{' '}
                            {raised.map((d, n) => (
                                <span key={d.code}>
                                    {n > 0 && '; '}
                                    {d.name}{' '}
                                    <button
                                        type="button"
                                        className={cn(quiet, 'px-0 underline underline-offset-2')}
                                        disabled={busy !== null}
                                        onClick={() => level(d.code, false)}
                                    >
                                        move back here
                                    </button>
                                </span>
                            ))}
                            .
                        </p>
                    )}
                </section>

                {checked && (
                    <p className="text-muted-foreground border-t pt-3 text-xs">
                        Checked:{' '}
                        {[
                            counted(checked.hmrc, 'payment to HMRC', 'payments to HMRC'),
                            counted(checked.jobs, 'finished job', 'finished jobs', 'for mileage'),
                            counted(checked.years, 'filed year', 'filed years', 'against your records'),
                            ...(checked.closeout !== undefined
                                ? [counted(checked.closeout, 'finished job', 'finished jobs', 'against their PO and invoices')]
                                : []),
                        ].join(', ')}
                        .
                    </p>
                )}
            </div>

            {/* Snooze a week, or Not relevant: why (kept with it, and with the finding). */}
            <ResponsiveSheet
                open={askOpen}
                onOpenChange={setAskOpen}
                title={asking?.days ? 'Snooze it for a week' : 'Not relevant'}
                description={asking?.item.title}
                dirty={other.trim() !== ''}
                onDiscard={() => setOther('')}
                discardNote="Nothing was snoozed. What you typed is lost."
            >
                <SheetBody className="space-y-3">
                    <p className="text-sm font-medium">{asking?.days ? 'Why snooze it?' : 'Why isn’t it relevant?'}</p>
                    {reasons.length > 0 && (
                        <div className="grid gap-2">
                            {reasons.map(([code, label]) => (
                                <Button
                                    key={code}
                                    variant="outline"
                                    className="h-12 justify-start text-left"
                                    disabled={busy !== null}
                                    onClick={() => asking && dismiss(asking.item, asking.days, code)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    )}
                    <form id={formId} onSubmit={submitOther} className="space-y-1.5">
                        <Label htmlFor={`${formId}-why`}>Or say why</Label>
                        <Input id={`${formId}-why`} className="h-11" value={other} onChange={(e) => setOther(e.target.value)} maxLength={200} />
                    </form>
                </SheetBody>
                <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                    <Button type="submit" form={formId} className="h-11" disabled={busy !== null}>
                        {asking?.days ? 'Snooze' : 'Not relevant'}
                    </Button>
                    <Button type="button" variant="outline" className="h-11" onClick={() => setAskOpen(false)}>
                        Cancel
                    </Button>
                </SheetFooter>
            </ResponsiveSheet>

            {/* Cancelled…: do they owe you anything? (the server's question and answers, each tied to the job as it is). */}
            <ResponsiveSheet
                open={questionOpen}
                onOpenChange={setQuestionOpen}
                title={questionTitle || 'Cancelled'}
                description={questionRest.join(' ') || undefined}
            >
                <SheetBody className="space-y-3">
                    <div className="grid gap-2">
                        {question?.choices.map((c) => (
                            <Button
                                key={c.id}
                                variant="outline"
                                className="h-12 justify-start text-left"
                                disabled={busy !== null}
                                onClick={() => act(c, `ask|${c.id}`, () => setQuestionOpen(false))}
                            >
                                {busy === `ask|${c.id}` ? '…' : c.label}
                            </Button>
                        ))}
                        {question?.links.map((l) => (
                            <Button key={l.href} asChild variant="outline" className="h-12 justify-start text-left">
                                <PageLink href={l.href} onClick={() => setQuestionOpen(false)}>
                                    {l.label}
                                </PageLink>
                            </Button>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-sm">With a fee or your costs, its invoice gets ready to check.</p>
                </SheetBody>
            </ResponsiveSheet>
        </AppLayout>
    );
}
