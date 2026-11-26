import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError, getJson, postJson, problemText } from '@/lib/api';
import { day, londonToday, money } from '@/lib/format';
import { type GmailPreview, type InvoiceDetail, type IssuedView } from '@/types/invoices';
import { router } from '@inertiajs/react';
import { AlertTriangle, Paperclip } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * "Send it now": the Gmail draft as it is in Gmail now (From, To, subject, what's attached, its first lines), then Send
 * now, tied to exactly that version. Edited in Gmail since: nothing is sent, and the draft is shown again as it is.
 */
export function SendNowSheet({
    open,
    onClose,
    invoice,
    kind,
    onDone,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    kind: 'invoice' | 'reminder';
    onDone: (message: string) => void;
}) {
    const [preview, setPreview] = useState<GmailPreview | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [changed, setChanged] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setPreview(null);
        setProblem(null);
        setChanged(null);
        let live = true;
        getJson<GmailPreview>(`/invoices/${invoice.id}/gmail/preview`, { kind })
            .then((p) => live && setPreview(p))
            .catch((e) => live && setProblem(problemText(e)));
        return () => {
            live = false;
        };
    }, [open, invoice.id, kind]);

    const send = async () => {
        if (!preview) return;
        setBusy(true);
        setProblem(null);
        try {
            const r = await postJson<{ message: string }>(`/invoices/${invoice.id}/gmail/send`, { version: preview.version, kind });
            onDone(r.message);
        } catch (e) {
            if (e instanceof ApiError && e.problem === 'changed') {
                const sheet = (e.data as { sheet?: GmailPreview | null } | null)?.sheet ?? null;
                setChanged(e.message);
                if (sheet) setPreview(sheet);
            } else setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={kind === 'reminder' ? 'Send the reminder now' : `Send ${invoice.number ?? 'the invoice'} now`}
            description="This is the draft as it is in your Gmail now."
        >
            <SheetBody className="space-y-3">
                {changed && (
                    <p className="flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        {changed} Nothing was sent. Below is the draft as it is now.
                    </p>
                )}
                {!preview && !problem && <p className="text-muted-foreground text-sm">Looking at the draft in Gmail…</p>}
                {preview && (
                    <dl className="space-y-2 text-sm">
                        <Row label="From" value={preview.from} />
                        <Row label="To" value={preview.to || 'No address yet: add it in Gmail'} />
                        <Row label="Subject" value={preview.subject} />
                        {preview.files.length > 0 && (
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground w-16 shrink-0">Attached</dt>
                                <dd className="min-w-0 space-y-0.5">
                                    {preview.files.map((f) => (
                                        <span key={f} className="flex items-center gap-1 break-all">
                                            <Paperclip className="size-3.5 shrink-0" />
                                            {f}
                                        </span>
                                    ))}
                                </dd>
                            </div>
                        )}
                        <div className="bg-muted/50 rounded-lg p-3 whitespace-pre-line">{preview.text}</div>
                        {preview.edited && <p className="text-muted-foreground text-xs">You’ve changed it in Gmail: this is your version.</p>}
                    </dl>
                )}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button className="h-11 flex-1" disabled={!preview || !preview.to || busy} onClick={send}>
                    {busy ? 'Sending…' : 'Send now'}
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Not now
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex gap-2">
            <dt className="text-muted-foreground w-16 shrink-0">{label}</dt>
            <dd className="min-w-0 break-words">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Email it from Expend (when Gmail can't be used), or a reminder that way: the address it goes to, filled in from the
 * invoice, then Send. Nothing goes until you press it.
 */
export function EmailSheet({
    open,
    onClose,
    invoice,
    reminder,
    issued,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    reminder: boolean;
    /** What has come in and what's waiting: a reminder says what its email will claim, and warns before it's wrong. */
    issued?: Pick<IssuedView, 'paid_minor' | 'outstanding_minor' | 'waiting_pence' | 'currency'>;
}) {
    const [to, setTo] = useState('');
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setTo(invoice.sent_to ?? invoice.bill_to_email ?? '');
        setProblem(null);
    }, [open, invoice.sent_to, invoice.bill_to_email]);

    const send = () => {
        if (to.trim() === '') return setProblem('Type the address it goes to.');
        setBusy(true);
        router.post(
            `/invoices/${invoice.id}/send`,
            { to: to.trim(), reminder },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errors) => setProblem(errors.to ?? Object.values(errors)[0] ?? 'That didn’t send. Check the address and try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={reminder ? 'Send a reminder' : `Email ${invoice.number ?? 'the invoice'}`}
            description={reminder ? 'A reminder with the invoice attached again, from Expend.' : 'The invoice as a PDF, from Expend.'}
        >
            <SheetBody className="space-y-3">
                <div className="space-y-1.5">
                    <Label htmlFor="email-to">To</Label>
                    <Input
                        id="email-to"
                        className="h-11"
                        type="email"
                        inputMode="email"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        placeholder="accounts@client.co.uk"
                    />
                </div>
                {reminder && issued && (
                    <p className="text-sm">
                        {issued.paid_minor > 0
                            ? `It thanks them for the ${money(issued.paid_minor, issued.currency)} that came in, and asks for the ${money(issued.outstanding_minor, issued.currency)} still to come.`
                            : `It says ${money(issued.outstanding_minor, issued.currency)} is due and you can’t see a payment yet.`}
                    </p>
                )}
                {reminder && issued && issued.waiting_pence > 0 && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        {money(issued.waiting_pence)} that may be for it is waiting in the Inbox. Decide that payment first, so the email doesn’t ask
                        for money that has come in.
                    </p>
                )}
                {problem && (
                    <p role="alert" className="text-sm text-rose-700 dark:text-rose-400">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button className="h-11 flex-1" disabled={busy} onClick={send}>
                    {busy ? 'Sending…' : 'Send'}
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/**
 * "I sent it myself": when did it go? Today unless you pick another day, never before it was issued or after today.
 * Its terms (and so when it's overdue) count from that day.
 */
export function SentSelfSheet({
    open,
    onClose,
    invoice,
    from,
    anotherWay,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    /** The day it was issued: the first day it can have gone. */
    from: string | null;
    /** "I sent it another way" (its Gmail draft was deleted unsent). */
    anotherWay: boolean;
}) {
    const today = londonToday();
    const [on, setOn] = useState(today);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setOn(londonToday());
        setProblem(null);
    }, [open]);

    const save = () => {
        if (!on) return setProblem('Pick the day it went.');
        setBusy(true);
        router.post(
            `/invoices/${invoice.id}/sent-self`,
            { on },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errors) => setProblem(errors.on ?? Object.values(errors)[0] ?? 'That wasn’t saved. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title={anotherWay ? 'I sent it another way' : 'I sent it myself'}
            description={`When did ${invoice.number ?? 'it'} go? Its payment terms count from that day.`}
        >
            <SheetBody className="space-y-3">
                <div className="space-y-1.5">
                    <Label htmlFor="sent-on">When did it go?</Label>
                    <Input
                        id="sent-on"
                        type="date"
                        className="h-11"
                        value={on}
                        min={from ?? undefined}
                        max={today}
                        onChange={(e) => setOn(e.target.value)}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant={on === today ? 'default' : 'outline'} className="h-11" onClick={() => setOn(today)}>
                            Today
                        </Button>
                        {from && from !== today && (
                            <Button variant={on === from ? 'default' : 'outline'} className="h-11" onClick={() => setOn(from)}>
                                The day it was issued ({day(from)})
                            </Button>
                        )}
                    </div>
                </div>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex gap-2">
                <Button className="h-11 flex-1" disabled={busy} onClick={save}>
                    Mark it as sent
                </Button>
                <Button variant="ghost" className="h-11" onClick={onClose}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
