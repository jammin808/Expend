import { CheckBadge } from '@/components/check-badge';
import { DecisionForm, decisionProblem, initialDecision } from '@/components/decision-form';
import { Explain } from '@/components/explain';
import { ItemDrawer } from '@/components/item-drawer';
import { Money } from '@/components/money';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { day, decidedByLabels, kindLabels, paidIn, paidWith } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Decision, type Item, type Lookups, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Camera, Check, ChevronLeft, ChevronRight, Keyboard, PartyPopper, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** An Inbox item, with what its suggestion rests on and what you've said no to for it. */
type InboxItem = Item & {
    suggestion_why?: {
        /** How the method works, in plain words */
        method: string | null;
        /** What else it weighed ("Also possible"), best first, with how far behind in plain words */
        alternatives: { ref: string; label: string; href: string | null; score: number | null; note: string | null }[];
    } | null;
    /** What you said no to for this payment: not suggested again */
    said_no?: { id: number; what: string }[];
};

interface Props {
    items: InboxItem[];
    remaining: number;
    filters: { year: string | null; status: string | null };
    lookups: Lookups;
    /** "Not this one" asks why, with these */
    not_this_reasons: Record<string, string>;
}

/** The lines a card shows, by id: what a decision or "Not this" there is about. */
const shownIds = (item: Item) => item.allocations.map((a) => a.id);

const RECENT_KEY = 'ledger.recentCategories';

function recentCategories(): number[] {
    try {
        return JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]');
    } catch {
        return [];
    }
}

function rememberCategory(id: number | null | undefined) {
    if (!id) return;
    try {
        const next = [id, ...recentCategories().filter((c) => c !== id)].slice(0, 9);
        localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        /* storage unavailable: shortcuts just won't remember */
    }
}

/** One item at a time, best suggestion pre-filled, instant feedback, keyboard and swipe. */
export default function InboxPage({ items, remaining, filters, lookups, not_this_reasons }: Props) {
    const { nav } = usePage<SharedData>().props;
    const [queue, setQueue] = useState<InboxItem[]>(items);
    const [askingWhy, setAskingWhy] = useState(false);
    const [index, setIndex] = useState(0);
    const [decision, setDecision] = useState<Decision | null>(null);
    const [applyAll, setApplyAll] = useState(false);
    const [done, setDone] = useState(0);
    const [drawer, setDrawer] = useState<{ source: 'bank' | 'manual'; id: number } | null>(null);
    const [showKeys, setShowKeys] = useState(false);
    const [recent, setRecent] = useState<number[]>([]);
    const touch = useRef<{ x: number; y: number } | null>(null);

    useEffect(() => {
        setQueue(items);
        setIndex(0);
    }, [items]);

    useEffect(() => {
        setRecent(recentCategories());
    }, []);

    const item = queue[index];
    const suggestion = item?.allocations.length && !item.allocations[0].confirmed ? item.allocations[0] : null;

    useEffect(() => {
        if (item) {
            setDecision(initialDecision(item.amount_pence, item.allocations));
            setApplyAll(false);
            setAskingWhy(false);
        }
        // Only when a different payment is shown: a reloaded queue mustn't wipe a decision you're part-way through.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item?.id]);

    const recentCats = useMemo(
        () =>
            recent
                .map((id) => lookups.categories.find((c) => c.id === id))
                .filter(Boolean)
                .slice(0, 9) as Lookups['categories'],
        [recent, lookups.categories],
    );

    const advance = useCallback(
        (removedId?: number) => {
            setQueue((q) => {
                const next = removedId ? q.filter((x) => x.id !== removedId) : q;
                if (next.length === 0) {
                    router.reload({ only: ['items', 'remaining', 'nav'] });
                }
                return next;
            });
            if (!removedId) setIndex((i) => Math.min(i + 1, Math.max(0, queue.length - 1)));
            else setIndex((i) => Math.min(i, Math.max(0, queue.length - 2)));
        },
        [queue.length],
    );

    const submit = useCallback(
        (d: Decision, mode: 'decide' | 'accept') => {
            if (!item) return;
            if (mode === 'decide') {
                const problem = decisionProblem(d);
                if (problem) return toast(problem, 'error');
            }
            const current = item;
            const all = applyAll && (current.merchant_count ?? 1) > 1;
            const url = mode === 'accept' ? `/items/bank/${current.id}/confirm` : all ? '/decisions/bulk' : `/items/bank/${current.id}/decide`;
            const payload = {
                // A single decision says which lines it was shown, so deciding differently is remembered only for those.
                ...(mode === 'accept'
                    ? {}
                    : all
                      ? { ...d, merchant_key: current.merchant_key, tax_year: current.tax_year }
                      : { ...d, shown: shownIds(current) }),
                quiet: true,
            };

            rememberCategory(d.category_id);
            setRecent(recentCategories());
            setDone((n) => n + (all ? (current.merchant_count ?? 1) : 1));
            if (all) {
                setQueue((q) => q.filter((x) => x.merchant_key !== current.merchant_key || x.id === current.id));
            }
            advance(current.id);

            router.post(url, payload as never, {
                preserveScroll: true,
                preserveState: true,
                only: ['nav', 'flash'],
                onSuccess: (page) => {
                    const flash = (page.props as unknown as SharedData).flash;
                    if (flash?.error) {
                        setQueue((q) => [current, ...q]);
                        setIndex(0);
                        return;
                    }
                    if (all) {
                        toast(`Saved every undecided item from ${current.merchant_key}.`);
                    } else {
                        toast(`${mode === 'accept' ? 'Accepted' : 'Saved'}: ${current.description.slice(0, 40)}`, 'success', {
                            label: 'Undo',
                            run: () =>
                                router.post(
                                    `/items/bank/${current.id}/clear`,
                                    // Undo only puts back what you just did: it says no to nothing.
                                    { reason: 'Undo from Inbox', undo: true },
                                    { preserveState: true, onSuccess: () => router.reload({ only: ['items', 'remaining', 'nav'] }) },
                                ),
                        });
                    }
                },
                onError: () => {
                    toast("That didn't save. It's back in your queue.", 'error');
                    setQueue((q) => [current, ...q]);
                },
            });
        },
        [item, applyAll, advance],
    );

    // "Not this one": the suggestion goes, with your reason, and isn't made for this payment again. The payment stays
    // here for you to decide.
    const notThis = useCallback(
        (reason: string) => {
            if (!item) return;
            const current = item;
            router.post(
                `/items/bank/${current.id}/clear`,
                // Bound to the suggestion on the card: if another has replaced it since, nothing is cleared.
                { reason_code: reason, quiet: true, shown: shownIds(current) },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['nav', 'flash'],
                    onSuccess: (page) => {
                        const flash = (page.props as unknown as SharedData).flash;
                        if (flash?.error) {
                            // Refused (the message is shown): it changed since it was shown, so show it as it is now.
                            return router.reload({ only: ['items', 'remaining', 'nav'] });
                        }
                        setQueue((q) =>
                            q.map((x) =>
                                x.id === current.id
                                    ? {
                                          ...x,
                                          allocations: [],
                                          suggestion_why: null,
                                          said_no: [...(x.said_no ?? []), { id: 0, what: 'Not what was suggested' }],
                                      }
                                    : x,
                            ),
                        );
                        setDecision(initialDecision(current.amount_pence, []));
                        setAskingWhy(false);
                        toast('OK: that won’t be suggested for this payment again. Decide it yourself.');
                    },
                    onError: () => toast('That didn’t work. Try again.', 'error'),
                },
            );
        },
        [item],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
            if (drawer || !item || !decision) return;
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                return submit(decision, 'decide');
            }
            // Browser and system shortcuts (Ctrl+P to print, Ctrl+D to bookmark, Alt+B…) aren't the Inbox's keys.
            if (typing || e.metaKey || e.ctrlKey || e.altKey) return;
            const k = e.key.toLowerCase();
            if (k === 'enter') {
                e.preventDefault();
                submit(decision, suggestion ? 'accept' : 'decide');
            } else if (k === 'j' || e.key === 'ArrowRight') setIndex((i) => Math.min(i + 1, queue.length - 1));
            else if (k === 'k' || e.key === 'ArrowLeft') setIndex((i) => Math.max(i - 1, 0));
            else if (k === 'b') setDecision({ ...decision, kind: 'business_expense', category_id: null });
            else if (k === 'i') setDecision({ ...decision, kind: 'business_income', category_id: null });
            else if (k === 'p') submit({ ...decision, kind: 'personal', category_id: null }, 'decide');
            else if (k === 't') setDecision({ ...decision, kind: 'transfer', category_id: null });
            else if (k === 'a') setApplyAll((v) => !v);
            else if (k === 'd') setDrawer({ source: 'bank', id: item.id });
            else if (k === '?') setShowKeys((v) => !v);
            else if (/^[1-9]$/.test(k) && recentCats[Number(k) - 1]) {
                const cat = recentCats[Number(k) - 1];
                setDecision({ ...decision, kind: cat.kind === 'income' ? 'business_income' : 'business_expense', category_id: cat.id });
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [item, decision, suggestion, submit, queue.length, drawer, recentCats]);

    const years = nav?.years ?? [];
    const left = Math.max(0, remaining - done);

    return (
        <AppLayout breadcrumbs={[{ title: 'Inbox', href: '/inbox' }]}>
            <Head title="Inbox" />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Inbox</h1>
                        <p className="text-muted-foreground text-sm">
                            {left.toLocaleString()} to go{done > 0 ? ` · ${done} done this session` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        <Link href="/inbox" className={cn('rounded-full border px-3 py-1 text-sm', !filters.year && 'bg-foreground text-background')}>
                            All years
                        </Link>
                        {years.map((y) => (
                            <Link
                                key={y.label}
                                href={`/inbox?year=${y.label}`}
                                className={cn('rounded-full border px-3 py-1 text-sm', filters.year === y.label && 'bg-foreground text-background')}
                            >
                                {y.label}
                            </Link>
                        ))}
                    </div>
                </div>

                {!item ? (
                    <div className="rounded-2xl border p-10 text-center">
                        <PartyPopper className="mx-auto size-10 text-emerald-600" />
                        <p className="mt-4 text-lg font-semibold">Inbox zero</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Everything {filters.year ? `in ${filters.year} ` : ''}is decided. Check the tax year page for anything still to confirm.
                        </p>
                        <Button className="mt-6" asChild>
                            <Link href={filters.year ? `/years/${filters.year}` : '/years'}>Go to tax years</Link>
                        </Button>
                    </div>
                ) : (
                    decision && (
                        <div
                            className="rounded-2xl border shadow-sm"
                            onTouchStart={(e) => (touch.current = { x: e.touches[0].clientX, y: e.touches[0].clientY })}
                            onTouchEnd={(e) => {
                                if (!touch.current) return;
                                const dx = e.changedTouches[0].clientX - touch.current.x;
                                const dy = Math.abs(e.changedTouches[0].clientY - touch.current.y);
                                touch.current = null;
                                if (Math.abs(dx) < 90 || dy > 60) return;
                                if (dx > 0) submit(decision, suggestion ? 'accept' : 'decide');
                                else submit({ ...decision, kind: 'personal', category_id: null }, 'decide');
                            }}
                        >
                            <div className="space-y-3 p-5 md:p-6">
                                <div className="text-muted-foreground flex items-center justify-between text-sm">
                                    <span>
                                        {day(item.date)}
                                        {item.time ? ` ${item.time}` : ''} · {paidWith(item)}
                                        {item.place ? ` · ${item.place}` : ''} · {item.tax_year}
                                        {paidIn(item) ? ` · paid ${paidIn(item)}` : ''}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <button
                                            aria-label="Previous"
                                            className="hover:bg-muted rounded p-1"
                                            onClick={() => setIndex((i) => Math.max(i - 1, 0))}
                                        >
                                            <ChevronLeft className="size-4" />
                                        </button>
                                        {index + 1}/{queue.length}
                                        <button
                                            aria-label="Next"
                                            className="hover:bg-muted rounded p-1"
                                            onClick={() => setIndex((i) => Math.min(i + 1, queue.length - 1))}
                                        >
                                            <ChevronRight className="size-4" />
                                        </button>
                                    </span>
                                </div>
                                <div className="flex items-start justify-between gap-4">
                                    <span className="flex items-start gap-2">
                                        <button
                                            className="text-left text-lg leading-snug font-semibold hover:underline md:text-xl"
                                            onClick={() => setDrawer({ source: 'bank', id: item.id })}
                                        >
                                            {item.description}
                                        </button>
                                        <Explain key={item.id} url={`/explain/bank/${item.id}`} className="mt-1.5" />
                                    </span>
                                    <Money pence={item.amount_pence} className="text-xl font-semibold md:text-2xl" />
                                </div>
                                <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                    {item.bank_category && <span>Bank says: {item.bank_category.replace(/_/g, ' ').toLowerCase()}</span>}
                                    {paidIn(item) && <span>Paid {paidIn(item)}</span>}
                                    {(item.merchant_count ?? 1) > 1 && (
                                        <span>
                                            {item.merchant_count} undecided from {item.merchant_key}
                                        </span>
                                    )}
                                    {item.receipt && (
                                        // The receipt you kept on your phone for this payment (paid by the business card).
                                        <span className="flex items-center gap-1">
                                            <Camera className="size-3.5" />
                                            <Link href={item.receipt.href} className="underline underline-offset-2">
                                                Your receipt photo{item.receipt.what ? `: ${item.receipt.what}` : ''}
                                            </Link>
                                        </span>
                                    )}
                                </div>

                                {item.events && item.events.length > 0 && (
                                    <div className="bg-muted/50 flex gap-2 rounded-lg p-3 text-sm">
                                        <CalendarDays className="mt-0.5 size-4 shrink-0" />
                                        <div>
                                            {item.events.map((e, i) => (
                                                <p key={i}>
                                                    {e.time ? <span className="tabular-nums">{e.time} </span> : null}
                                                    {e.summary}
                                                    {e.location ? <span className="text-muted-foreground"> · {e.location}</span> : null}
                                                </p>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {item.jobs_near && item.jobs_near.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">Jobs around then:</span>
                                        {item.jobs_near.map((j) => (
                                            <button
                                                key={j.id}
                                                className={cn(
                                                    'rounded-full border px-2.5 py-0.5',
                                                    decision.work_job_id === j.id &&
                                                        'border-sky-600 bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
                                                )}
                                                onClick={() => setDecision({ ...decision, work_job_id: decision.work_job_id === j.id ? null : j.id })}
                                            >
                                                {j.title}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {suggestion && (
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/40">
                                        <span className="flex items-start gap-2">
                                            <Sparkles className="mt-0.5 size-4 shrink-0 text-amber-600" />
                                            <span>
                                                <span className="font-medium">
                                                    {decidedByLabels[suggestion.decided_by]} suggests {kindLabels[suggestion.kind].toLowerCase()}
                                                    {suggestion.category ? ` · ${suggestion.category}` : ''}
                                                </span>
                                                {suggestion.reason && <span className="text-muted-foreground block">{suggestion.reason}</span>}
                                                {item.suggestion_why?.method && (
                                                    <span className="text-muted-foreground block text-xs">How: {item.suggestion_why.method}</span>
                                                )}
                                                {item.suggestion_why && item.suggestion_why.alternatives.length > 0 && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        Also possible:{' '}
                                                        {item.suggestion_why.alternatives.map((a, i) => (
                                                            <span key={a.ref}>
                                                                {i > 0 ? '; ' : ''}
                                                                {a.label}
                                                                {a.note ? ` (${a.note})` : ''}
                                                            </span>
                                                        ))}
                                                    </span>
                                                )}
                                                {(suggestion.decided_by === 'ai' || suggestion.decided_by === 'matcher') && (
                                                    <CheckBadge
                                                        check={item.check ?? null}
                                                        subject={{ type: 'transaction', id: item.id }}
                                                        className="mt-1"
                                                    />
                                                )}
                                            </span>
                                        </span>
                                        <span className="flex flex-wrap items-center gap-2">
                                            <Button size="sm" variant="outline" onClick={() => setAskingWhy((v) => !v)} aria-expanded={askingWhy}>
                                                <X /> Not this
                                            </Button>
                                            <Button size="sm" onClick={() => submit(decision, 'accept')}>
                                                <Check /> Accept <kbd className="ml-1 hidden opacity-60 md:inline">↵</kbd>
                                            </Button>
                                        </span>
                                        {askingWhy && (
                                            <div className="flex w-full flex-wrap items-center gap-1.5">
                                                <span className="text-muted-foreground text-xs">Why not?</span>
                                                {Object.entries(not_this_reasons).map(([code, label]) => (
                                                    <button
                                                        key={code}
                                                        className="hover:bg-muted rounded-full border bg-white px-2.5 py-0.5 text-xs dark:bg-transparent"
                                                        onClick={() => notThis(code)}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                                {item.said_no && item.said_no.length > 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        You said no before: {item.said_no.map((v) => v.what).join('; ')}. It isn’t suggested again (lift it from Why?
                                        in the details).
                                    </p>
                                )}
                            </div>

                            <div className="space-y-4 border-t p-5 md:p-6">
                                {recentCats.length > 0 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {recentCats.map((c, i) => (
                                            <button
                                                key={c.id}
                                                className={cn(
                                                    'hover:bg-muted rounded-md border px-2 py-1 text-xs',
                                                    decision.category_id === c.id && 'bg-muted font-medium',
                                                )}
                                                onClick={() =>
                                                    setDecision({
                                                        ...decision,
                                                        kind: c.kind === 'income' ? 'business_income' : 'business_expense',
                                                        category_id: c.id,
                                                    })
                                                }
                                            >
                                                <kbd className="text-muted-foreground mr-1">{i + 1}</kbd>
                                                {c.name}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <DecisionForm
                                    amount={item.amount_pence}
                                    lookups={lookups}
                                    value={decision}
                                    onChange={setDecision}
                                    near={item.jobs_near}
                                    merchantCount={item.merchant_count}
                                    merchant={item.merchant_key}
                                    applyToMerchant={applyAll}
                                    onApplyToMerchant={setApplyAll}
                                />
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button onClick={() => submit(decision, 'decide')}>
                                        Save{applyAll && (item.merchant_count ?? 1) > 1 ? ` all ${item.merchant_count}` : ''}{' '}
                                        <kbd className="ml-1 hidden opacity-60 md:inline">⌘↵</kbd>
                                    </Button>
                                    <Button variant="outline" onClick={() => submit({ ...decision, kind: 'personal', category_id: null }, 'decide')}>
                                        Personal <kbd className="ml-1 hidden opacity-60 md:inline">P</kbd>
                                    </Button>
                                    <Button variant="ghost" onClick={() => setIndex((i) => Math.min(i + 1, queue.length - 1))}>
                                        Skip <kbd className="ml-1 hidden opacity-60 md:inline">J</kbd>
                                    </Button>
                                    <Button variant="ghost" onClick={() => setDrawer({ source: 'bank', id: item.id })}>
                                        Details or split <kbd className="ml-1 hidden opacity-60 md:inline">D</kbd>
                                    </Button>
                                    <button
                                        className="text-muted-foreground ml-auto hidden items-center gap-1 text-xs md:flex"
                                        onClick={() => setShowKeys((v) => !v)}
                                    >
                                        <Keyboard className="size-3.5" /> Shortcuts
                                    </button>
                                </div>
                                <p className="text-muted-foreground text-xs md:hidden">Swipe right to save or accept, left for personal.</p>
                                {showKeys && (
                                    <div className="text-muted-foreground grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg border p-3 text-xs sm:grid-cols-3">
                                        <span>↵ accept suggestion / save</span>
                                        <span>⌘↵ save</span>
                                        <span>P personal (saves)</span>
                                        <span>B business expense</span>
                                        <span>I business income</span>
                                        <span>T transfer</span>
                                        <span>1–9 recent categories</span>
                                        <span>A apply to whole merchant</span>
                                        <span>J / K next / previous</span>
                                        <span>D details, split, history</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    )
                )}
            </div>
            <ItemDrawer
                target={drawer}
                lookups={lookups}
                onClose={() => {
                    setDrawer(null);
                    router.reload({ only: ['items', 'remaining', 'nav'] });
                }}
            />
        </AppLayout>
    );
}
