import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { toastOnNextPage } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { ApiError, getJson, postJson, problemText } from '@/lib/api';
import { cn } from '@/lib/utils';
import { type CompareSheet } from '@/types/clients';
import { Link, router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useEffect, useState } from 'react';

type Side = CompareSheet['from'];

/** "3 jobs · 2 invoices · 5 payments". */
function counts(c: Side): string {
    const n = (k: number, one: string, many: string) => `${k} ${k === 1 ? one : many}`;
    return [n(c.jobs, 'job', 'jobs'), n(c.invoices, 'invoice', 'invoices'), n(c.payments, 'payment', 'payments')].join(' · ');
}

function Card({ c, keep, onPick }: { c: Side; keep: boolean; onPick: () => void }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={keep}
            onClick={onPick}
            className={cn(
                'min-h-11 w-full min-w-0 space-y-1 rounded-lg border p-3 text-left text-sm',
                keep ? 'border-primary ring-primary ring-1' : 'hover:bg-muted/50',
            )}
        >
            <span className="flex items-center justify-between gap-2">
                <span className="font-medium break-words">{c.name}</span>
                <span className={cn('shrink-0 text-xs', keep ? 'font-medium' : 'text-muted-foreground')}>
                    {keep ? 'Keep this one' : 'Tap to keep'}
                </span>
            </span>
            {c.invoice_name && c.invoice_name !== c.name && (
                <span className="text-muted-foreground block break-words">On invoices: {c.invoice_name}</span>
            )}
            {c.billing_email && <span className="text-muted-foreground block break-all">{c.billing_email}</span>}
            <span className="text-muted-foreground block">{counts(c)}</span>
        </button>
    );
}

/**
 * Two clients side by side: which one to keep, what merging does ("Its 3 jobs, 2 invoices and payments then count as
 * Acme Events. Nothing in your records is rewritten, and you can unmerge"), and why it can't when a locked year holds
 * records. The merge is tied to both as shown: if either changed since, it's refused and shown again.
 */
export function ClientMergeSheet({
    a,
    b,
    keepFirst = 'b',
    open,
    onClose,
    onNotSame,
}: {
    a: { id: number; name: string };
    b: { id: number; name: string };
    /** Which one starts as the one kept. */
    keepFirst?: 'a' | 'b';
    open: boolean;
    onClose: () => void;
    /** "Not the same" (from the list's possible duplicates). */
    onNotSame?: () => void;
}) {
    const [keep, setKeep] = useState<'a' | 'b'>(keepFirst);
    // Both ways round: merging a into b (keep b), and b into a (keep a). Each is tied to its own pair as shown.
    const [sheets, setSheets] = useState<{ a: CompareSheet | null; b: CompareSheet | null }>({ a: null, b: null });
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        let live = true;
        setProblem(null);
        setKeep(keepFirst);
        Promise.all([getJson<CompareSheet>(`/clients/${a.id}/compare/${b.id}`), getJson<CompareSheet>(`/clients/${b.id}/compare/${a.id}`)])
            .then(([intoB, intoA]) => live && setSheets({ b: intoB, a: intoA }))
            .catch((e) => live && setProblem(problemText(e)));
        return () => {
            live = false;
        };
    }, [open, a.id, b.id, keepFirst]);

    const sheet = sheets[keep];
    const merge = async () => {
        if (!sheet) return;
        setBusy(true);
        setProblem(null);
        try {
            const done = await postJson<{ message: string; id: number }>(`/clients/${sheet.from.id}/merge`, { into_id: sheet.into.id, fp: sheet.fp });
            onClose();
            // Said on the kept client's page (this page, and anything shown on it, goes).
            toastOnNextPage(done.message);
            router.visit(`/clients/${done.id}`);
        } catch (e) {
            const now = e instanceof ApiError ? (e.data as { sheet?: CompareSheet } | null)?.sheet : null;
            if (now) setSheets((s) => ({ ...s, [keep]: now }));
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };
    const kept = keep === 'a' ? a : b;
    const gone = keep === 'a' ? b : a;

    return (
        <ResponsiveSheet open={open} onOpenChange={(o) => !o && onClose()} title="Are they the same client?" description="Pick the one to keep.">
            <SheetBody className="space-y-3">
                {sheets.a && sheets.b ? (
                    <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="The client to keep">
                        <Card c={sheets.b.from} keep={keep === 'a'} onPick={() => setKeep('a')} />
                        <Card c={sheets.a.from} keep={keep === 'b'} onPick={() => setKeep('b')} />
                    </div>
                ) : (
                    !problem && <p className="text-muted-foreground text-sm">Looking them up…</p>
                )}
                {sheet &&
                    (sheet.can_merge ? (
                        <p className="text-sm">
                            Merge {gone.name} into {kept.name}. {sheet.what_follows}
                        </p>
                    ) : (
                        <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/40">
                            <p className="flex gap-2">
                                <Lock className="mt-0.5 size-4 shrink-0" aria-hidden />
                                {sheet.why_not ?? 'These two can’t be merged.'}
                            </p>
                            {sheet.locked_years.map((y) => (
                                <Link key={y} href={`/years/${y}`} className="inline-flex min-h-11 items-center underline underline-offset-2">
                                    Open {y} to unlock it
                                </Link>
                            ))}
                        </div>
                    ))}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                <Button className="h-auto min-h-11 py-2 whitespace-normal" disabled={busy || !sheet?.can_merge} onClick={() => void merge()}>
                    Merge into {kept.name}
                </Button>
                {onNotSame && (
                    <Button variant="outline" className="h-11" disabled={busy} onClick={onNotSame}>
                        Not the same
                    </Button>
                )}
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
