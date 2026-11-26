import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { JobCombobox } from '@/components/job-combobox';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError, postJson, problemText } from '@/lib/api';
import { money, toPence } from '@/lib/format';
import { changedSheet, costId, FLAG_WORDS, lineAction, lineAmount, linesAsInput, paymentOf, quantityHundredths, saveDraft } from '@/lib/invoice';
import { type JobHit } from '@/types';
import { type ChangedSheet, type InvoiceDetail, type InvoiceLine, type LinePayment, WORK_SOURCES } from '@/types/invoices';
import { useEffect, useRef, useState } from 'react';

type Step = 'edit' | 'share' | 'same';

/**
 * A line's sheet: its words, quantity and price (the amount worked out as the server does), whether it's on the
 * invoice, where it's from, and what else can be said about it: Use Expend's figures again, Not this job's cost (its
 * payment's drawer, with its whole split), Shared with another job…, Charged another way, Remove (your own lines),
 * Keep it as my own line (one gone from the job) and It's the same (one that may be on your own line). Every save is
 * tied to the line as you saw it: changed since, nothing is saved and it says so.
 */
export function LineSheet({
    invoice,
    line,
    onClose,
    onDone,
    onChanged,
    onOpenPayment,
}: {
    invoice: InvoiceDetail;
    line: InvoiceLine | null;
    onClose: () => void;
    /** Saved: say so and load the draft again. */
    onDone: (message: string) => void;
    /** Refused because the draft changed since: show what it is now. */
    onChanged: (message: string, sheet: ChangedSheet | null) => void;
    onOpenPayment: (target: { source: 'bank' | 'manual'; id: number }) => void;
}) {
    const [description, setDescription] = useState('');
    const [quantity, setQuantity] = useState('');
    const [price, setPrice] = useState('');
    const [included, setIncluded] = useState(true);
    const [step, setStep] = useState<Step>('edit');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [shareJob, setShareJob] = useState<JobHit | null>(null);
    const [shareAmount, setShareAmount] = useState('');
    // The payment as the share step showed it: refused when it changed since, and then shown as it is now.
    const [sharePayment, setSharePayment] = useState<LinePayment | null>(null);
    // The line as the boxes were filled from it: what you typed is compared with this, not with the line as it is now.
    const [base, setBase] = useState<InvoiceLine | null>(null);
    const typed =
        base !== null && (description !== base.description || quantity !== base.quantity || price !== base.unit_price || included !== base.included);
    const typedNow = useRef(false);
    typedNow.current = typed;
    const baseId = base?.id ?? null;

    // The draft is loaded again after every change (yours, or a sync), so the same line comes back as a new object. The
    // boxes start again only for another line, or when you haven't typed anything: what you typed is never thrown away.
    useEffect(() => {
        if (!line) {
            setBase(null);
            return;
        }
        const other = baseId !== line.id;
        if (!other && typedNow.current) return;
        setBase(line);
        setDescription(line.description);
        setQuantity(line.quantity);
        setPrice(line.unit_price);
        setIncluded(line.included);
        if (other) {
            setStep('edit');
            setProblem(null);
            setShareJob(null);
            setShareAmount((Math.abs(line.amount_minor) / 200).toFixed(2));
            setSharePayment(line.payment ?? null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only a new line (or a fresh copy of it) starts the boxes again
    }, [line]);

    if (!line) return null;

    const own = line.source === null;
    const work = line.source !== null && WORK_SOURCES.includes(line.source);
    const amount = lineAmount(quantity, price);
    const dirty = typed;
    // Changed since you opened it (a sync, or another tab) while you were typing: say so; Save keeps what you typed.
    const changedUnder = typed && base !== null && base.version !== line.version;
    const payment = paymentOf(line);
    const cost = costId(line);
    const yourLines = invoice.lines.filter((l) => l.source === null && l.included && l.id !== line.id);
    const quantityChanged = work && line.source !== 'po' && quantity !== line.quantity;

    const fail = (e: unknown) => {
        if (e instanceof ApiError && e.problem === 'changed') {
            onChanged(e.message, changedSheet(e.data));
            onClose();
            return;
        }
        setProblem(problemText(e));
    };

    const save = async () => {
        if (description.trim() === '') return setProblem('Give it a description.');
        if (quantityHundredths(quantity) === null) return setProblem('The quantity must be a number more than 0, like 1 or 0.5.');
        if (toPence(price) === null) return setProblem('Give the price as an amount, like 450.00.');
        setBusy(true);
        setProblem(null);
        try {
            const lines = linesAsInput(invoice.lines).map((l) =>
                l.id === line.id ? { ...l, description: description.trim(), quantity: quantity.trim(), unit_price: price.trim(), included } : l,
            );
            await saveDraft(invoice, { lines });
            onDone('Saved.');
            onClose();
        } catch (e) {
            fail(e);
        } finally {
            setBusy(false);
        }
    };

    const act = async (action: 'reset' | 'remove' | 'keep' | 'same', otherId?: number) => {
        setBusy(true);
        setProblem(null);
        try {
            const r = await lineAction(invoice.id, line, action, otherId);
            onDone(r.message);
            onClose();
        } catch (e) {
            fail(e);
        } finally {
            setBusy(false);
        }
    };

    // Shared with another job: tied to the payment as the step shows it. Changed since: nothing is moved, and the step
    // shows the payment as it is now, for another look.
    const share = async () => {
        if (!shareJob) return;
        setBusy(true);
        setProblem(null);
        try {
            const r = await postJson<{ message: string }>(`/invoices/${invoice.id}/share`, {
                line: line.id,
                job_id: shareJob.id,
                amount: shareAmount.trim(),
                fp: sharePayment?.fp ?? null,
            });
            onDone(r.message);
            onClose();
        } catch (e) {
            const now =
                e instanceof ApiError && e.problem === 'changed'
                    ? (e.data as { sheet?: { payment?: LinePayment | null } } | null)?.sheet?.payment
                    : null;
            if (now) setSharePayment(now);
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    // Charged another way: the server's own words when it's done, its reason when it isn't (never "done" for a refusal).
    const chargedElsewhere = async (id: string) => {
        setBusy(true);
        setProblem(null);
        try {
            const r = await postJson<{ message: string }>(`/costs/${id}/charge`, { charge: 'elsewhere' });
            onDone(r.message);
            onClose();
        } catch (e) {
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ResponsiveSheet
            open={!!line}
            onOpenChange={(o) => !o && onClose()}
            title={
                step === 'share'
                    ? 'Shared with another job'
                    : step === 'same'
                      ? 'Which of your lines is it?'
                      : own
                        ? 'Your line'
                        : 'A line from the job'
            }
            description={step === 'edit' ? line.description : undefined}
            dirty={step === 'edit' && dirty}
            discardNote="What you changed on this line is lost."
        >
            {step === 'edit' && (
                <>
                    <SheetBody className="space-y-4">
                        {line.flag && FLAG_WORDS[line.flag] && (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                {FLAG_WORDS[line.flag]}
                            </p>
                        )}
                        <div className="space-y-1.5">
                            <Label htmlFor="line-description">Description</Label>
                            <Input id="line-description" className="h-11" value={description} onChange={(e) => setDescription(e.target.value)} />
                            {line.own_text && <p className="text-muted-foreground text-xs">Your wording: it stays when the job changes.</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="line-quantity">Quantity</Label>
                                <Input
                                    id="line-quantity"
                                    className="h-11"
                                    inputMode="decimal"
                                    value={quantity}
                                    onChange={(e) => setQuantity(e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="line-price">Price each ({invoice.currency})</Label>
                                <Input
                                    id="line-price"
                                    aria-label="Price each, amount"
                                    className="h-11"
                                    inputMode="decimal"
                                    value={price}
                                    onChange={(e) => setPrice(e.target.value)}
                                />
                            </div>
                        </div>
                        <p className="text-sm">
                            Amount: <span className="font-semibold tabular-nums">{amount === null ? '—' : money(amount, invoice.currency)}</span>
                        </p>
                        {quantityChanged && <p className="text-muted-foreground text-xs">You set this: it won’t follow the job’s days any more.</p>}
                        {line.source === 'mileage' && (
                            <p className="text-muted-foreground text-xs">
                                Charged to the client. Your mileage claim on the tax return is separate, at HMRC’s rate.
                            </p>
                        )}
                        <label className="flex min-h-11 items-center justify-between gap-3 rounded-lg border px-3">
                            <span className="text-sm font-medium">On the invoice</span>
                            <input
                                type="checkbox"
                                className="accent-primary size-6"
                                checked={included}
                                onChange={(e) => setIncluded(e.target.checked)}
                            />
                        </label>
                        {(line.source_quote || line.source_job || payment) && (
                            <div className="space-y-2 rounded-lg border p-3 text-sm">
                                <p className="font-medium">Where it’s from</p>
                                {line.source_quote && <p className="text-muted-foreground italic">From the PO: “{line.source_quote}”</p>}
                                {line.source_job && line.source_job_id !== invoice.work_job_id && (
                                    <p className="text-muted-foreground">From the job {line.source_job}</p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {payment && (
                                        <Button variant="outline" className="h-11" onClick={() => onOpenPayment(payment)}>
                                            Open the payment
                                        </Button>
                                    )}
                                    {line.source_job_id && (
                                        <Button variant="outline" className="h-11" asChild>
                                            <a href={`/jobs/${line.source_job_id}`}>Open the job</a>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                        {changedUnder && (
                            <p role="status" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                This line changed since you opened it. Save keeps what you typed; Close leaves it as it is now.
                            </p>
                        )}
                        {problem && (
                            <p role="alert" className="text-sm text-rose-700 dark:text-rose-400">
                                {problem}
                            </p>
                        )}
                        <div className="space-y-2 border-t pt-3">
                            {!own && (line.own_text || line.own_amount || line.ticked_by === 'you') && (
                                <Button variant="outline" className="h-11 w-full justify-start" disabled={busy} onClick={() => act('reset')}>
                                    Use Expend’s figures again
                                </Button>
                            )}
                            {payment && (
                                <Button
                                    variant="outline"
                                    className="h-11 w-full justify-start"
                                    disabled={busy}
                                    onClick={() => onOpenPayment(payment)}
                                >
                                    Not this job’s cost…
                                </Button>
                            )}
                            {payment && line.source_ref?.includes('#j') && (
                                <Button variant="outline" className="h-11 w-full justify-start" disabled={busy} onClick={() => setStep('share')}>
                                    Shared with another job…
                                </Button>
                            )}
                            {cost && (
                                <Button
                                    variant="outline"
                                    className="h-11 w-full justify-start"
                                    disabled={busy}
                                    onClick={() => void chargedElsewhere(cost)}
                                >
                                    Charged another way
                                </Button>
                            )}
                            {line.flag === 'maybe_same' && yourLines.length > 0 && (
                                <Button variant="outline" className="h-11 w-full justify-start" disabled={busy} onClick={() => setStep('same')}>
                                    It’s the same as one of my lines…
                                </Button>
                            )}
                            {line.flag === 'gone' && (
                                <Button variant="outline" className="h-11 w-full justify-start" disabled={busy} onClick={() => act('keep')}>
                                    Keep it as my own line
                                </Button>
                            )}
                            {own || line.flag === 'gone' ? (
                                <Button
                                    variant="outline"
                                    className="h-11 w-full justify-start text-rose-700 dark:text-rose-400"
                                    disabled={busy}
                                    onClick={() => act('remove')}
                                >
                                    Remove it
                                </Button>
                            ) : (
                                <p className="text-muted-foreground text-xs">It comes from the job: untick it to leave it off.</p>
                            )}
                        </div>
                    </SheetBody>
                    <SheetFooter className="flex gap-2">
                        <Button className="h-11 flex-1" disabled={busy || !dirty} onClick={save}>
                            Save
                        </Button>
                        <Button variant="ghost" className="h-11" onClick={onClose}>
                            Close
                        </Button>
                    </SheetFooter>
                </>
            )}
            {step === 'share' && (
                <>
                    <SheetBody className="space-y-4">
                        <p className="text-muted-foreground text-sm">
                            The payment is split between this job and the other, as you. Half each to start with: change the other job’s share if it
                            isn’t.
                        </p>
                        {sharePayment && sharePayment.split.length > 0 && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p className="font-medium">The payment now</p>
                                <ul className="mt-1 space-y-1">
                                    {sharePayment.split.map((part, i) => (
                                        <li key={i} className="flex justify-between gap-3">
                                            <span className="min-w-0 break-words">{part.words}</span>
                                            <span className="shrink-0 tabular-nums">{money(part.amount_minor, 'GBP')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label htmlFor="share-job">The other job</Label>
                            <JobCombobox
                                id="share-job"
                                value={shareJob?.id ?? null}
                                label={shareJob?.title ?? null}
                                onChange={setShareJob}
                                placeholder="Find the job"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="share-amount">The other job’s share ({invoice.currency})</Label>
                            <Input
                                id="share-amount"
                                aria-label="The other job’s share, amount"
                                className="h-11"
                                inputMode="decimal"
                                value={shareAmount}
                                onChange={(e) => setShareAmount(e.target.value)}
                            />
                        </div>
                        {problem && (
                            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                                {problem}
                            </p>
                        )}
                    </SheetBody>
                    <SheetFooter className="flex gap-2">
                        <Button className="h-11 flex-1" disabled={busy || !shareJob} onClick={share}>
                            Share it
                        </Button>
                        <Button variant="ghost" className="h-11" onClick={() => setStep('edit')}>
                            Back
                        </Button>
                    </SheetFooter>
                </>
            )}
            {step === 'same' && (
                <>
                    <SheetBody className="space-y-2">
                        <p className="text-muted-foreground text-sm">Pick your line it’s already on. Your wording and price stay.</p>
                        {yourLines.map((l) => (
                            <Button
                                key={l.id}
                                variant="outline"
                                className="h-auto min-h-11 w-full justify-between gap-3 py-2 text-left whitespace-normal"
                                disabled={busy}
                                onClick={() => act('same', l.id)}
                            >
                                <span className="min-w-0">{l.description}</span>
                                <span className="shrink-0 tabular-nums">{money(l.amount_minor, invoice.currency)}</span>
                            </Button>
                        ))}
                        {problem && (
                            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                                {problem}
                            </p>
                        )}
                    </SheetBody>
                    <SheetFooter>
                        <Button variant="ghost" className="h-11" onClick={() => setStep('edit')}>
                            Back
                        </Button>
                    </SheetFooter>
                </>
            )}
        </ResponsiveSheet>
    );
}
