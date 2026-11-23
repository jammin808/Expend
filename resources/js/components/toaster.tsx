import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

type Toast = { id: number; tone: 'success' | 'error'; text: string; action?: { label: string; run: () => void } };

let push: (t: Omit<Toast, 'id'>) => void = () => {};

/** Show a toast from anywhere, optionally with an action such as Undo. */
export function toast(text: string, tone: 'success' | 'error' = 'success', action?: Toast['action']) {
    push({ text, tone, action });
}

export function Toaster() {
    const { flash } = usePage<SharedData>().props;
    const [toasts, setToasts] = useState<Toast[]>([]);

    useEffect(() => {
        push = (t) => {
            const id = Date.now() + Math.random();
            setToasts((list) => [...list.slice(-3), { ...t, id }]);
            setTimeout(() => setToasts((list) => list.filter((x) => x.id !== id)), t.action ? 8000 : 4500);
        };
    }, []);

    useEffect(() => {
        if (flash?.success) {
            // After a rule is learned, the confirmation carries an Undo.
            const undo = flash.rule_undo;
            toast(
                flash.success,
                'success',
                undo ? { label: 'Undo', run: () => router.post(`/rules/${undo.id}/undo`, {}, { preserveScroll: true }) } : undefined,
            );
        }
        if (flash?.error) toast(flash.error, 'error');
        // You've decided this merchant the same way before: offer to make it automatic.
        const offer = flash?.rule_offer;
        if (offer) {
            toast(offer.text, 'success', {
                label: 'Always do this',
                run: () => router.post('/rules/learn', { merchant_key: offer.merchant_key }, { preserveScroll: true }),
            });
        }
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
                                setToasts((list) => list.filter((x) => x.id !== t.id));
                            }}
                        >
                            {t.action.label}
                        </button>
                    )}
                    <button aria-label="Dismiss" onClick={() => setToasts((list) => list.filter((x) => x.id !== t.id))}>
                        <X className="text-muted-foreground size-4" />
                    </button>
                </div>
            ))}
        </div>
    );
}
