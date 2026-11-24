import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Toast = { id: number; tone: 'success' | 'error'; text: string; action?: { label: string; run: () => void } };

let push: (t: Omit<Toast, 'id'>) => void = () => {};

// Inertia has shown its first page (it starts after the page's own effects have run, so it can't be told anything before).
let ready = false;
router.on('navigate', () => {
    ready = true;
});

/**
 * A message the server sent with a page belongs to that one response, but Inertia keeps the page, message included,
 * in the browser's history and shows it again on Back without asking the server. So once it's shown, take it off
 * this page's history entry: Back then shows no toast, and no "Always do this" or Undo for something already done.
 */
function forgetFlash() {
    const clear = () =>
        router.replace({
            props: (props) => ({ ...props, flash: { ...(props.flash as object), success: null, error: null, rule_offer: null, rule_undo: null } }),
            preserveScroll: true,
            preserveState: true,
        });
    if (ready) {
        clear();
    } else {
        const stop = router.on('navigate', () => {
            stop();
            clear();
        });
    }
}

/** Show a toast from anywhere, optionally with an action such as Undo. */
export function toast(text: string, tone: 'success' | 'error' = 'success', action?: Toast['action']) {
    push({ text, tone, action });
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
    const expireLater = (id: number, withAction: boolean) => {
        clearTimeout(timers.current.get(id));
        timers.current.set(
            id,
            setTimeout(() => remove(id), withAction ? 8000 : 4500),
        );
    };

    useEffect(() => {
        push = (t) => {
            // The same message twice at once (the server's confirmation and the page's own, which carries an Undo) is
            // shown once, keeping the action. Two with actions of their own (Undo for two different things) both stay.
            const same = showing.current.find((x) => x.text === t.text && x.tone === t.tone && !(x.action && t.action));
            if (same) {
                if (t.action) show(showing.current.map((x) => (x.id === same.id ? { ...x, action: t.action } : x)));
                expireLater(same.id, !!(same.action || t.action));
                return;
            }
            const id = Date.now() + Math.random();
            expireLater(id, !!t.action);
            show([...showing.current.slice(-3), { ...t, id }]);
        };
        const pending = timers.current;
        return () => pending.forEach(clearTimeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- show/remove only touch refs and state setters
    }, []);

    useEffect(() => {
        if (!flash?.success && !flash?.error && !flash?.rule_offer) return;
        if (flash.success) {
            // After a rule is learned, the confirmation carries an Undo.
            const undo = flash.rule_undo;
            toast(
                flash.success,
                'success',
                undo ? { label: 'Undo', run: () => router.post(`/rules/${undo.id}/undo`, {}, { preserveScroll: true }) } : undefined,
            );
        }
        if (flash.error) toast(flash.error, 'error');
        // You've decided this merchant the same way before: offer to make it automatic.
        const offer = flash.rule_offer;
        if (offer) {
            toast(offer.text, 'success', {
                label: 'Always do this',
                run: () => router.post('/rules/learn', { merchant_key: offer.merchant_key }, { preserveScroll: true }),
            });
        }
        forgetFlash();
    }, [flash?.success, flash?.error, flash?.rule_offer, flash?.rule_undo]);

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-4 z-[100] flex flex-col items-center gap-2 px-4" aria-live="polite">
            {toasts.map((t) => (
                <div
                    key={t.id}
                    className="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border bg-white px-4 py-3 text-sm shadow-lg dark:bg-neutral-900"
                >
                    {t.tone === 'success' ? (
                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                    ) : (
                        <XCircle className="mt-0.5 size-4 shrink-0 text-rose-600" />
                    )}
                    <span className="flex-1">{t.text}</span>
                    {t.action && (
                        <button
                            className="font-medium text-sky-700 hover:underline dark:text-sky-400"
                            onClick={() => {
                                t.action!.run();
                                remove(t.id);
                            }}
                        >
                            {t.action.label}
                        </button>
                    )}
                    <button aria-label="Dismiss" onClick={() => remove(t.id)}>
                        <X className="text-muted-foreground size-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
