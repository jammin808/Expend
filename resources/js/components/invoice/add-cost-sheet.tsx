import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError, getJson, problemText, wasCalledOff } from '@/lib/api';
import { day, money, toPence } from '@/lib/format';
import { changedSheet, lineAmount, linesAsInput, quantityHundredths, saveDraft } from '@/lib/invoice';
import { type ChangedSheet, type InvoiceDetail } from '@/types/invoices';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CostPayment {
    source: 'bank';
    id: number;
    date: string;
    description: string;
    amount_pence: number;
    fp: string;
    on_job: string | null;
    on_job_id: number | null;
    category: string | null;
    decided: boolean;
}

interface CostBooking {
    id: number;
    kind: string;
    provider: string | null;
    starts_on: string;
    amount_minor: number | null;
    currency: string | null;
    on_job_id: number | null;
}

interface CostTrip {
    id: number;
    date: string;
    miles: string;
    purpose: string | null;
    on_job_id: number | null;
}

interface Costs {
    job?: { id: number; title: string };
    payments: CostPayment[];
    bookings: CostBooking[];
    trips: CostTrip[];
    message?: string;
}

/**
 * "+ Add a cost or a line": costs around the job's days that aren't linked to it (money out from 60 days before to 7
 * days after, bookings by travel date, mileage trips), with a search; or a line you type. A payment opens in its
 * drawer, where you put it on this job (or split it with this job when it's on another), as you. A booking is linked
 * to the job with one tap. Nothing is moved off another job here.
 */
export function AddCostSheet({
    open,
    onClose,
    invoice,
    onOpenPayment,
    onDone,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    onOpenPayment: (target: { source: 'bank' | 'manual'; id: number }) => void;
    onDone: (message: string) => void;
    onChanged: (message: string, sheet: ChangedSheet | null) => void;
}) {
    const [mode, setMode] = useState<'choose' | 'costs' | 'type'>('choose');
    const [q, setQ] = useState('');
    const [costs, setCosts] = useState<Costs | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [description, setDescription] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [price, setPrice] = useState('');

    useEffect(() => {
        if (!open) {
            setMode('choose');
            setQ('');
            setCosts(null);
            setProblem(null);
            setDescription('');
            setQuantity('1');
            setPrice('');
        }
    }, [open]);

    useEffect(() => {
        if (!open || mode !== 'costs') return;
        const abort = new AbortController();
        const t = setTimeout(() => {
            getJson<Costs>(`/invoices/${invoice.id}/costs`, { q: q.trim() }, abort.signal)
                .then((c) => {
                    setCosts(c);
                    setProblem(null);
                })
                .catch((e) => !wasCalledOff(e) && setProblem(problemText(e)));
        }, 250);
        return () => {
            clearTimeout(t);
            abort.abort();
        };
    }, [open, mode, q, invoice.id]);

    const typeLine = async () => {
        if (description.trim() === '') return setProblem('Give it a description.');
        if (quantityHundredths(quantity) === null) return setProblem('The quantity must be a number more than 0, like 1 or 0.5.');
        if (toPence(price) === null) return setProblem('Give the price as an amount, like 25.00.');
        setBusy(true);
        setProblem(null);
        try {
            const sort = invoice.lines.reduce((m, l) => Math.max(m, l.sort), 0) + 1;
            await saveDraft(invoice, {
                lines: [
                    ...linesAsInput(invoice.lines),
                    {
                        id: null,
                        version: null,
                        description: description.trim(),
                        quantity: quantity.trim(),
                        unit_price: price.trim(),
                        included: true,
                        sort,
                    },
                ],
            });
            onDone('Line added.');
            onClose();
        } catch (e) {
            if (e instanceof ApiError && e.problem === 'changed') {
                onChanged(e.message, changedSheet(e.data));
                onClose();
            } else setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    const linkBooking = (b: CostBooking) => {
        if (!invoice.work_job_id) return;
        setBusy(true);
        router.post(
            `/jobs/${invoice.work_job_id}/links`,
            { type: 'travel', action: 'link', id: b.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onDone(`${b.provider ?? b.kind} is linked to the job.`);
                    onClose();
                },
                onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That wasn’t linked. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    const amount = lineAmount(quantity, price);

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={mode === 'type' ? 'Type a line' : mode === 'costs' ? 'Costs for this job, not linked to it' : 'Add a cost or a line'}
            description={mode === 'costs' && costs?.job ? `Around ${costs.job.title}’s days` : undefined}
        >
            {mode === 'choose' && (
                <SheetBody className="space-y-2">
                    {invoice.work_job_id ? (
                        <Button
                            variant="outline"
                            className="h-auto min-h-14 w-full flex-col items-start gap-0.5 py-2 text-left whitespace-normal"
                            onClick={() => setMode('costs')}
                        >
                            <span className="font-medium">Costs for this job, not linked to it</span>
                            <span className="text-muted-foreground text-xs font-normal">Payments, bookings and trips around the job’s days</span>
                        </Button>
                    ) : null}
                    <Button
                        variant="outline"
                        className="h-auto min-h-14 w-full flex-col items-start gap-0.5 py-2 text-left whitespace-normal"
                        onClick={() => setMode('type')}
                    >
                        <span className="font-medium">Type a line</span>
                        <span className="text-muted-foreground text-xs font-normal">Something that isn’t a cost in Expend</span>
                    </Button>
                </SheetBody>
            )}
            {mode === 'costs' && (
                <>
                    <SheetBody className="space-y-4">
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                className="h-11 pl-9"
                                placeholder="Search payments"
                                aria-label="Search payments"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        {problem && (
                            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                                {problem}
                            </p>
                        )}
                        {!costs && !problem && <p className="text-muted-foreground text-sm">Looking…</p>}
                        {costs?.message && <p className="text-muted-foreground text-sm">{costs.message}</p>}
                        {costs && costs.payments.length > 0 && (
                            <section className="space-y-1.5">
                                <h3 className="text-sm font-medium">Payments</h3>
                                <ul className="divide-y rounded-lg border">
                                    {costs.payments.map((p) => (
                                        <li key={p.id} className="flex items-center gap-2 p-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">{p.description}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {day(p.date)}
                                                    {p.on_job ? ` · on ${p.on_job}` : p.category ? ` · ${p.category}` : ' · not decided yet'}
                                                </p>
                                            </div>
                                            <span className="shrink-0 text-sm tabular-nums">{money(Math.abs(p.amount_pence))}</span>
                                            <Button
                                                variant="outline"
                                                className="h-11 shrink-0"
                                                onClick={() => {
                                                    onOpenPayment({ source: p.source, id: p.id });
                                                    onClose();
                                                }}
                                            >
                                                {p.on_job ? 'Share…' : 'Add…'}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground text-xs">
                                    Add… opens the payment: put it on this job there. One on another job is split with this one, not moved.
                                </p>
                            </section>
                        )}
                        {costs && costs.bookings.length > 0 && (
                            <section className="space-y-1.5">
                                <h3 className="text-sm font-medium">Bookings</h3>
                                <ul className="divide-y rounded-lg border">
                                    {costs.bookings.map((b) => (
                                        <li key={b.id} className="flex items-center gap-2 p-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">{b.provider ?? b.kind}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {b.kind} · {day(b.starts_on)}
                                                    {b.on_job_id ? ' · on another job' : ''}
                                                </p>
                                            </div>
                                            {b.amount_minor !== null && (
                                                <span className="shrink-0 text-sm tabular-nums">{money(b.amount_minor, b.currency ?? 'GBP')}</span>
                                            )}
                                            {!b.on_job_id && (
                                                <Button variant="outline" className="h-11 shrink-0" disabled={busy} onClick={() => linkBooking(b)}>
                                                    Link
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {costs && costs.trips.length > 0 && (
                            <section className="space-y-1.5">
                                <h3 className="text-sm font-medium">Mileage trips</h3>
                                <ul className="divide-y rounded-lg border">
                                    {costs.trips.map((t) => (
                                        <li key={t.id} className="flex items-center gap-2 p-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">{t.purpose || 'A trip'}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {day(t.date)} · {t.miles} miles{t.on_job_id ? ' · on another job' : ''}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                <Button variant="outline" className="h-11" asChild>
                                    <a href="/mileage">Link trips on the Mileage page</a>
                                </Button>
                            </section>
                        )}
                        {costs && !costs.message && costs.payments.length + costs.bookings.length + costs.trips.length === 0 && (
                            <p className="text-muted-foreground text-sm">Nothing around the job’s days that isn’t linked to it already.</p>
                        )}
                    </SheetBody>
                    <SheetFooter>
                        <Button variant="ghost" className="h-11" onClick={() => setMode('choose')}>
                            Back
                        </Button>
                    </SheetFooter>
                </>
            )}
            {mode === 'type' && (
                <>
                    <SheetBody className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="new-line-description">Description</Label>
                            <Input
                                id="new-line-description"
                                className="h-11"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="Like Get-out, or Extra hour"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="new-line-quantity">Quantity</Label>
                                <Input
                                    id="new-line-quantity"
                                    className="h-11"
                                    inputMode="decimal"
                                    value={quantity}
                                    onChange={(e) => setQuantity(e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="new-line-price">Price each ({invoice.currency})</Label>
                                <Input
                                    id="new-line-price"
                                    aria-label="Price each, amount"
                                    className="h-11"
                                    inputMode="decimal"
                                    value={price}
                                    onChange={(e) => setPrice(e.target.value)}
                                    placeholder="0.00"
                                />
                            </div>
                        </div>
                        <p className="text-sm">
                            Amount: <span className="font-semibold tabular-nums">{amount === null ? '—' : money(amount, invoice.currency)}</span>
                        </p>
                        {problem && (
                            <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                                {problem}
                            </p>
                        )}
                    </SheetBody>
                    <SheetFooter className="flex gap-2">
                        <Button className="h-11 flex-1" disabled={busy} onClick={typeLine}>
                            Add the line
                        </Button>
                        <Button variant="ghost" className="h-11" onClick={() => setMode('choose')}>
                            Back
                        </Button>
                    </SheetFooter>
                </>
            )}
        </ResponsiveSheet>
    );
}
