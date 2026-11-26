import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type CostChipKey, type CostRow } from '@/types/clients';
import { Link, router } from '@inertiajs/react';
import { useId, useState } from 'react';

/** What each chip a cost is listed under says. */
export const CHIP_WORDS: Record<CostChipKey, string> = {
    to_charge: 'To charge',
    on_draft: 'On a draft',
    charged: 'Charged',
    to_claim: 'To claim',
    not_charged: 'Not charged',
    elsewhere: 'Charged another way',
};

const CHIP_TONES: Record<CostChipKey, string> = {
    to_charge: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    on_draft: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    charged: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    to_claim: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    not_charged: 'bg-muted text-neutral-700 dark:text-neutral-300',
    elsewhere: 'bg-muted text-neutral-700 dark:text-neutral-300',
};

/** A cost's chip: where it stands for charging. */
export function CostChip({ chip, className }: { chip: CostChipKey; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                CHIP_TONES[chip] ?? CHIP_TONES.not_charged,
                className,
            )}
        >
            {CHIP_WORDS[chip] ?? chip}
        </span>
    );
}

/** A cost's amount, or its quantity for the job's own work (days, hours). */
export function costAmount(c: CostRow): string {
    if (c.amount_minor !== null) return money(Math.abs(c.amount_minor), c.currency);
    // A trip is in miles; the job's own work has no price here (the job's fee or rate gives it).
    if (c.quantity && c.kind === 'trip') return `${c.quantity.replace(/\.00$/, '')} miles`;
    return '';
}

/** The payment (or hand-entered expense) behind a cost, for its drawer; null for a booking, a trip or the job's work. */
export function paymentOfCost(c: CostRow): { source: 'bank' | 'manual'; id: number } | null {
    const m = /^(tx|exp):(\d+)/.exec(c.ref);
    return m ? { source: m[1] === 'tx' ? 'bank' : 'manual', id: parseInt(m[2], 10) } : null;
}

/**
 * One cost's choices: Charge to Acme (it goes on their next draft), Not charged, Charged another way… (with a note),
 * Claimed (on their expense form), back to the usual arrangement, and Open the payment. Only what fits where it stands
 * is offered (the server's list). "Charge to" is only ever the job's own paying client.
 */
export function CostSheet({
    cost,
    clientName,
    clientId,
    onClose,
    onOpenPayment,
}: {
    cost: CostRow | null;
    /** Who pays: the job's client, or the client whose page this is. */
    clientName: string | null;
    clientId: number | null;
    onClose: () => void;
    onOpenPayment?: (target: { source: 'bank' | 'manual'; id: number }) => void;
}) {
    const uid = useId();
    const [note, setNote] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const close = () => {
        setNote(null);
        setProblem(null);
        onClose();
    };
    const send = (path: string, body: Record<string, string | number | null> = {}) => {
        if (!cost) return;
        setBusy(true);
        setProblem(null);
        router.post(`/costs/${cost.id}/${path}`, body, {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as unknown as SharedData).flash?.error;
                if (error) setProblem(error);
                else close();
            },
            onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That didn’t save. Try again.'),
            onFinish: () => setBusy(false),
        });
    };
    const payment = cost ? paymentOfCost(cost) : null;
    const actions = cost?.actions ?? [];
    const who = clientName ?? 'the client';

    return (
        <ResponsiveSheet
            open={cost !== null}
            onOpenChange={(o) => !o && close()}
            title={cost?.description ?? 'Cost'}
            description={
                cost
                    ? [cost.date && !cost.description.includes(day(cost.date)) ? day(cost.date) : null, costAmount(cost)].filter(Boolean).join(' · ')
                    : undefined
            }
        >
            {cost && (
                <>
                    <SheetBody className="space-y-3">
                        <p className="flex flex-wrap items-center gap-2 text-sm">
                            <CostChip chip={cost.chip} />
                            {cost.note && <span className="text-muted-foreground">{cost.note}</span>}
                        </p>
                        {note === null ? (
                            <div className="grid gap-2">
                                {actions.includes('recharge') && (
                                    <Button
                                        className="h-auto min-h-11 justify-start py-2 text-left whitespace-normal"
                                        disabled={busy}
                                        onClick={() => send('charge', { charge: 'recharge', client_id: clientId })}
                                    >
                                        Charge to {who}
                                    </Button>
                                )}
                                {actions.includes('claimed') && (
                                    <Button variant="outline" className="h-11 justify-start" disabled={busy} onClick={() => send('claimed')}>
                                        Claimed on their expense form
                                    </Button>
                                )}
                                {actions.includes('no_charge') && (
                                    <Button
                                        variant="outline"
                                        className="h-11 justify-start"
                                        disabled={busy}
                                        onClick={() => send('charge', { charge: 'no_charge' })}
                                    >
                                        Not charged
                                    </Button>
                                )}
                                {actions.includes('elsewhere') && (
                                    <Button variant="outline" className="h-11 justify-start" disabled={busy} onClick={() => setNote('')}>
                                        Charged another way…
                                    </Button>
                                )}
                                {actions.includes('clear') && (
                                    <Button variant="ghost" className="h-11 justify-start" disabled={busy} onClick={() => send('clear')}>
                                        Back to the usual arrangement
                                    </Button>
                                )}
                                {actions.length === 0 && (
                                    <p className="text-muted-foreground text-sm">Nothing to decide: it’s on an invoice already.</p>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-1.5">
                                <label className="text-sm font-medium" htmlFor={`${uid}-note`}>
                                    How was it charged? (optional)
                                </label>
                                <Input
                                    id={`${uid}-note`}
                                    autoFocus
                                    className="h-11"
                                    maxLength={250}
                                    placeholder="For example: on their crew sheet"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">It won’t be offered on an invoice.</p>
                            </div>
                        )}
                        {problem && (
                            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                                {problem}
                            </p>
                        )}
                    </SheetBody>
                    <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                        {note !== null ? (
                            <>
                                <Button className="h-11" disabled={busy} onClick={() => send('charge', { charge: 'elsewhere', note })}>
                                    Save
                                </Button>
                                <Button variant="outline" className="h-11" onClick={() => setNote(null)}>
                                    Back
                                </Button>
                            </>
                        ) : payment && onOpenPayment ? (
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => {
                                    close();
                                    onOpenPayment(payment);
                                }}
                            >
                                Open the payment
                            </Button>
                        ) : cost.ref.startsWith('travel:') ? (
                            <Button variant="outline" className="h-11" asChild>
                                <Link href="/travel">Open Stays and travel</Link>
                            </Button>
                        ) : cost.ref.startsWith('trip:') ? (
                            <Button variant="outline" className="h-11" asChild>
                                <Link href="/mileage">Open Mileage</Link>
                            </Button>
                        ) : (
                            <Button variant="outline" className="h-11" onClick={close}>
                                Close
                            </Button>
                        )}
                    </SheetFooter>
                </>
            )}
        </ResponsiveSheet>
    );
}
