import {
    isButton,
    isHere,
    isLink,
    localPath,
    noAnswerWords,
    openLink,
    openWords,
    postQuietly,
    saysItsOwnProblems,
    todoUrl,
    withoutLinks,
} from '@/lib/todo';
import { type Flash, type FollowUpButton, type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** A button on a toast: a press (Undo, a choice) or a link to a page. */
type ToastAction = { label: string; run?: () => void; href?: string };

type Toast = {
    id: number;
    tone: 'success' | 'error';
    text: string;
    actions: ToastAction[];
    /** It asks something (Cancelled: do they owe you anything?): it stays until you answer or close it. */
    asks?: boolean;
};

let push: (t: Omit<Toast, 'id'>) => void = () => {};

// Inertia has shown its first page (it starts after the page's own effects have run, so it can't be told anything before).
let ready = false;
router.on('navigate', () => {
    ready = true;
});

/**
 * No answer at all (no signal at a venue, the connection dropped): said in words, whatever was pressed, instead of the
 * press doing nothing. The sheet it came from stays open with what you typed, so it can be pressed again. A press that
 * says its own words (postQuietly) is left to say them.
 */
let lastMethod = 'get';
router.on('before', (e) => {
    lastMethod = e.detail.visit.method;
});
router.on('exception', (e) => {
    // Only a request that got no answer: an error in the page's own code still shows as one.
    const failed = e.detail.exception as { isAxiosError?: boolean; response?: unknown } | undefined;
    if (saysItsOwnProblems() || !failed?.isAxiosError || failed.response) return;
    e.preventDefault();
    toast(lastMethod === 'get' ? 'Can’t reach Expend. Try again when you have signal.' : noAnswerWords(0), 'error');
});

/**
 * A message the server sent with a page belongs to that one response, but Inertia keeps the page, message included,
 * in the browser's history and shows it again on Back without asking the server. So once it's shown, take it off
 * this page's history entry: Back then shows no toast, and no "Always do this" or Undo for something already done.
 */
function forgetFlash() {
    const clear = () =>
        router.replace({
            props: (props) => ({
                ...props,
                flash: { ...(props.flash as object), success: null, error: null, rule_offer: null, rule_undo: null, undo: null, follow_up: null },
            }),
            preserveScroll: true,
            preserveState: true,
            // The page has already asked for what it loads once it shows (the to-do list's "more_below"): taking the
            // message off mustn't ask for it a second time. Inertia copies this onto the page, and a page without it
            // loads nothing more.
            deferredProps: undefined,
        } as Parameters<typeof router.replace>[0]);
    if (ready) {
        clear();
    } else {
        const stop = router.on('navigate', () => {
            stop();
            clear();
        });
    }
}

/** Toasts for the page a visit is about to show (the page now goes, and its toasts with it). */
let forNextPage: string[] = [];
const showForNextPage = () => {
    const due = forNextPage;
    forNextPage = [];
    due.forEach((text) => push({ text, tone: 'success', actions: [] }));
};

/** Say something on the page you're about to go to ("Merged…", then the kept client's page). */
export function toastOnNextPage(text: string) {
    forNextPage.push(text);
}

/** Show a toast from anywhere, optionally with an action such as Undo. */
export function toast(text: string, tone: 'success' | 'error' = 'success', action?: { label: string; run: () => void }) {
    push({ text, tone, actions: action ? [action] : [] });
}

/**
 * Press a to-do button (posted to /todo/{code}/{id} with the fingerprint of what it was shown on). The answer comes
 * back as the page's message: what was done, with its Undo and what to do next, or why it wasn't ("This changed
 * since: open it in Expend. Nothing was done"). No answer at all is said in words too.
 */
export function pressTodo(
    b: { code: string; id: string; fp?: string | null },
    on: { start?: () => void; finish?: () => void; success?: () => void } = {},
) {
    postQuietly(todoUrl(b), b.fp ? { fp: b.fp } : {}, { problem: (words) => toast(words, 'error'), ...on });
}

/**
 * A page that asks a press's follow-up itself (the to-do list asks Cancelled's "Do they owe you anything?" in a sheet)
 * says so by returning true for that message, and the toaster leaves it to the page.
 */
type Claim = (flash: Flash) => boolean;
const claims = new Set<{ current: Claim }>();

export function useFlashClaim(claim: Claim) {
    const entry = useRef(claim);
    entry.current = claim;
    useEffect(() => {
        const mine = entry;
        claims.add(mine);
        return () => {
            claims.delete(mine);
        };
    }, []);
}

/** How long a toast stays: long enough to read, and to reach Undo with a thumb. One that asks stays until answered. */
const lifeOf = (t: Toast) => (t.asks ? null : t.tone === 'error' ? 9000 : t.actions.length ? 10000 : 4500);

/**
 * A to-do press's answer as toasts: its message with what to do next (links such as "Check the invoice", choices it
 * asks for, and Undo last, where the thumb is), and a refusal in the server's words.
 */
function fromFlash(flash: Flash) {
    const next = flash.follow_up ?? [];
    const press = (b: FollowUpButton) => () => pressTodo(b);
    if (flash.success) {
        const buttons = next.filter(isButton);
        const undo = flash.undo ?? buttons.find((b) => b.label === 'Undo') ?? null;
        const choices = buttons.filter((b) => b.label !== 'Undo' && !(undo && b.code === undo.code && b.id === undo.id));
        const actions: ToastAction[] = [
            ...choices.map((b) => ({ label: b.label, run: press(b) })),
            ...next.filter(isLink).map((l) => ({ label: l.label, href: l.href })),
        ];
        const rule = flash.rule_undo;
        if (undo) actions.push({ label: undo.label || 'Undo', run: press(undo) });
        // After a rule is learned, the confirmation carries an Undo.
        else if (rule) actions.push({ label: 'Undo', run: () => router.post(`/rules/${rule.id}/undo`, {}, { preserveScroll: true }) });
        push({ text: flash.success, tone: 'success', actions, asks: choices.length > 0 });
    }
    if (flash.error) push({ text: flash.error, tone: 'error', actions: [] });
    // You've decided this merchant the same way before: offer to make it automatic.
    const offer = flash.rule_offer;
    if (offer) {
        push({
            text: offer.text,
            tone: 'success',
            actions: [
                { label: 'Always do this', run: () => router.post('/rules/learn', { merchant_key: offer.merchant_key }, { preserveScroll: true }) },
            ],
        });
    }
}

export function Toaster() {
    const { flash } = usePage<SharedData>().props;
    const [toasts, setToasts] = useState<Toast[]>([]);
    const showing = useRef<Toast[]>([]);
    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    const show = (list: Toast[]) => {
        showing.current = list;
        setToasts(list);
    };
    const remove = (id: number) => {
        clearTimeout(timers.current.get(id));
        timers.current.delete(id);
        show(showing.current.filter((x) => x.id !== id));
    };
    const hold = (id: number) => {
        clearTimeout(timers.current.get(id));
        timers.current.delete(id);
    };
    const expireLater = (t: Toast) => {
        hold(t.id);
        const life = lifeOf(t);
        if (life !== null)
            timers.current.set(
                t.id,
                setTimeout(() => remove(t.id), life),
            );
    };

    useEffect(() => {
        push = (raw) => {
            // An address in the words (Telegram needs them written out) becomes a button, unless one already goes there.
            const { text, hrefs } = withoutLinks(raw.text);
            const extra = hrefs
                .filter((h) => !raw.actions.some((a) => a.href && localPath(a.href) === localPath(h)))
                .map((h) => ({ label: openWords(h), href: h }));
            // A link back to this very page leads nowhere new.
            const actions = [...raw.actions.filter((a) => !a.href || !isHere(a.href)), ...extra.filter((a) => !isHere(a.href))];
            const t = { ...raw, text, actions };
            // The same message twice at once (the server's confirmation and the page's own, which carries an Undo) is
            // shown once, keeping the buttons. Two with buttons of their own (Undo for two different things) both stay.
            const same = showing.current.find((x) => x.text === t.text && x.tone === t.tone && !(x.actions.length && t.actions.length));
            if (same) {
                const merged = t.actions.length ? { ...same, actions: t.actions, asks: t.asks } : same;
                show(showing.current.map((x) => (x.id === same.id ? merged : x)));
                expireLater(merged);
                return;
            }
            const made = { ...t, id: Date.now() + Math.random() };
            // At most three at once, so they never cover the page.
            showing.current.slice(0, -2).forEach((gone) => hold(gone.id));
            show([...showing.current.slice(-2), made]);
            expireLater(made);
        };
        // A toast meant for this page, from the one before it: shown once this page is here.
        showForNextPage();
        const stop = router.on('navigate', () => setTimeout(showForNextPage, 0));
        const pending = timers.current;
        return () => {
            stop();
            pending.forEach(clearTimeout);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- show/remove only touch refs and state setters
    }, []);

    useEffect(() => {
        if (!flash?.success && !flash?.error && !flash?.rule_offer && !flash?.undo && !flash?.follow_up?.length) return;
        const said = flash;
        // A tick later, once the page's own effects have run: a page that asks the follow-up itself has said so by then.
        setTimeout(() => {
            if (![...claims].some((c) => c.current(said))) fromFlash(said);
        }, 0);
        forgetFlash();
    }, [flash]);

    return (
        // Above the round + button and a page's sticky bottom bar on a phone, so neither is covered while it shows.
        <div
            className="pointer-events-none fixed inset-x-0 bottom-[calc(5.5rem_+_env(safe-area-inset-bottom))] z-[100] flex flex-col items-center gap-2 px-4 md:bottom-4"
            aria-live="polite"
        >
            {toasts.map((t) => (
                <div
                    key={t.id}
                    role={t.tone === 'error' ? 'alert' : 'status'}
                    // Held while your finger or pointer is on it, so it doesn't go while you read it or reach for Undo.
                    onPointerEnter={() => hold(t.id)}
                    onPointerLeave={() => expireLater(t)}
                    onFocus={() => hold(t.id)}
                    onBlur={() => expireLater(t)}
                    className="pointer-events-auto w-full max-w-md rounded-lg border bg-white text-sm shadow-lg dark:bg-neutral-900"
                >
                    <div className="flex items-start gap-3 py-1 pr-1 pl-4">
                        {t.tone === 'success' ? (
                            <CheckCircle2 className="mt-3 size-4 shrink-0 text-emerald-600" aria-hidden />
                        ) : (
                            <XCircle className="mt-3 size-4 shrink-0 text-rose-600" aria-hidden />
                        )}
                        <p className="min-w-0 flex-1 py-2.5 break-words">{t.text}</p>
                        <button
                            type="button"
                            aria-label="Dismiss"
                            className="hover:bg-muted flex size-10 shrink-0 items-center justify-center rounded-full"
                            onClick={() => remove(t.id)}
                        >
                            <X className="text-muted-foreground size-4" />
                        </button>
                    </div>
                    {t.actions.length > 0 && (
                        <div className="flex flex-wrap justify-end gap-1 border-t px-2 py-1">
                            {t.actions.map((a) =>
                                a.href ? (
                                    <a
                                        key={a.label + a.href}
                                        href={localPath(a.href) ?? a.href}
                                        className="hover:bg-muted inline-flex h-10 items-center rounded-md px-3 font-medium text-sky-700 dark:text-sky-400"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            remove(t.id);
                                            openLink(a.href!);
                                        }}
                                    >
                                        {a.label}
                                    </a>
                                ) : (
                                    <button
                                        key={a.label}
                                        type="button"
                                        className="hover:bg-muted inline-flex h-10 items-center rounded-md px-3 font-medium text-sky-700 dark:text-sky-400"
                                        onClick={() => {
                                            remove(t.id);
                                            a.run?.();
                                        }}
                                    >
                                        {a.label}
                                    </button>
                                ),
                            )}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
