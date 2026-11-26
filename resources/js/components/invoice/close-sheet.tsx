import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { day, money } from '@/lib/format';
import { shortMoney } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type CloseReason, type InvoiceDetail, type IssuedView } from '@/types/invoices';
import { router } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';

const REASONS: Record<CloseReason, string> = {
    short_paid: 'They paid less than invoiced',
    charges: 'Bank or exchange charges',
    wont_chase: 'I won’t chase it',
    paid_other_currency: 'It’s paid, in another currency',
    other: 'Other',
};

/** A sheet that posts one thing through a page visit (back to this page), saying why when the server refuses. */
function useVisit(onClose: () => void) {
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const post = (url: string, data: Record<string, string | number | null>) => {
        setBusy(true);
        setProblem(null);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That wasn’t saved. Try again.'),
            onFinish: () => setBusy(false),
        });
    };
    return { busy, problem, setProblem, post };
}

function Choice({ checked, onPick, children }: { checked: boolean; onPick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={checked}
            onClick={onPick}
            className={cn(
                'flex min-h-11 w-full items-center rounded-lg border px-3 py-2 text-left text-sm',
                checked ? 'border-primary bg-primary/5 font-medium' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

/**
 * "Close it: £30 isn't coming": why (they paid less, charges, you won't chase it, paid in another currency, other) and a
 * note. You're only taxed on what came in, so there's nothing to claim. Reopen undoes it.
 */
export function CloseSheet({ open, onClose, invoice, issued }: { open: boolean; onClose: () => void; invoice: InvoiceDetail; issued: IssuedView }) {
    const [reason, setReason] = useState<CloseReason | null>(null);
    const [note, setNote] = useState('');
    const { busy, problem, setProblem, post } = useVisit(onClose);
    useEffect(() => {
        if (!open) return;
        setReason(null);
        setNote('');
        setProblem(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const left = shortMoney(issued.outstanding_minor, issued.currency);

    return (
        <ResponsiveSheet open={open} onOpenChange={(o) => !o && onClose()} title={`Close it: ${left} isn’t coming`}>
            <SheetBody className="space-y-3">
                <div className="space-y-2" role="radiogroup" aria-label="Why">
                    {issued.close_reasons.map((r) => (
                        <Choice key={r} checked={reason === r} onPick={() => setReason(r)}>
                            {REASONS[r] ?? r}
                        </Choice>
                    ))}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="close-note">A note (if it helps later)</Label>
                    <Textarea id="close-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                </div>
                <p className="text-muted-foreground text-sm">
                    You’re only taxed on what came in, so there’s nothing to claim for the {left}. Agreed a lower price after issuing? That’s a credit
                    note, not built yet: choose Other and say so.
                </p>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button
                    className="h-11 flex-1"
                    disabled={busy || !reason}
                    onClick={() => reason && post(`/invoices/${invoice.id}/close`, { reason, note: note.trim() || null })}
                >
                    Close it
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/** Reopen a closed invoice: what was written off is owed again. */
export function ReopenSheet({ open, onClose, invoice, issued }: { open: boolean; onClose: () => void; invoice: InvoiceDetail; issued: IssuedView }) {
    const [note, setNote] = useState('');
    const { busy, problem, post } = useVisit(onClose);
    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={`Reopen ${invoice.number ?? 'it'}?`}
            description={`${money(issued.closed_minor, issued.currency)} that you closed is owed again.`}
        >
            <SheetBody className="space-y-3">
                <div className="space-y-1.5">
                    <Label htmlFor="reopen-note">A note (if it helps later)</Label>
                    <Textarea id="reopen-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                </div>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button className="h-11 flex-1" disabled={busy} onClick={() => post(`/invoices/${invoice.id}/reopen`, { note: note.trim() || null })}>
                    Reopen it
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/** "It was replaced by…": a void invoice's payments count for the invoice that replaced it (or nothing replaced it). */
export function ReplacedBySheet({
    open,
    onClose,
    invoice,
    issued,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    issued: IssuedView;
}) {
    const [pick, setPick] = useState<number | 'none' | null>(null);
    const { busy, problem, post } = useVisit(onClose);
    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title="It was replaced by…"
            description={`What was paid against ${invoice.number ?? 'it'} counts for the invoice that replaced it.`}
        >
            <SheetBody className="space-y-2">
                <div className="space-y-2" role="radiogroup" aria-label="Which invoice">
                    {issued.replace_with.map((r) => (
                        <Choice key={r.id} checked={pick === r.id} onPick={() => setPick(r.id)}>
                            <span className="min-w-0 flex-1">
                                {r.number} · {day(r.issue_date)}
                            </span>
                            <span className="shrink-0 tabular-nums">
                                {money(r.total_minor, invoice.currency)}
                                {r.outstanding_minor > 0 ? ` · ${shortMoney(r.outstanding_minor, invoice.currency)} to come` : ''}
                            </span>
                        </Choice>
                    ))}
                    <Choice checked={pick === 'none'} onPick={() => setPick('none')}>
                        Nothing replaced it
                    </Choice>
                </div>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button
                    className="h-11 flex-1"
                    disabled={busy || pick === null}
                    onClick={() => post(`/invoices/${invoice.id}/replaced-by`, { invoice_id: pick === 'none' ? null : pick })}
                >
                    Save
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/** Void an issued invoice, with why (kept with it and in the audit log). What was paid against it is said first. */
export function VoidSheet({ open, onClose, invoice, issued }: { open: boolean; onClose: () => void; invoice: InvoiceDetail; issued: IssuedView }) {
    const [reason, setReason] = useState('');
    const { busy, problem, setProblem, post } = useVisit(onClose);
    useEffect(() => {
        if (!open) return;
        setReason('');
        setProblem(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const paid = issued.payments.reduce((s, p) => s + p.amount_pence, 0);

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={`Void ${invoice.number ?? 'it'}?`}
            description="A void invoice stays on record with its number. Issue a new one if the client still owes you."
        >
            <SheetBody className="space-y-3">
                {issued.payments.length > 0 && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        Paid against it: {money(paid)} ({issued.payments.length} {issued.payments.length === 1 ? 'payment' : 'payments'}). Say which
                        invoice replaced it afterwards, so that money counts there.
                    </p>
                )}
                <div className="space-y-1.5">
                    <Label htmlFor="void-reason">Why are you voiding it?</Label>
                    <Textarea
                        id="void-reason"
                        rows={2}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Like: wrong PO number"
                    />
                </div>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button
                    variant="destructive"
                    className="h-11 flex-1"
                    disabled={busy}
                    onClick={() => post(`/invoices/${invoice.id}/void`, { reason })}
                >
                    Void it
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
