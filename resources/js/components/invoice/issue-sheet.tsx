import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError, postJson, problemText } from '@/lib/api';
import { day, kindLabels, londonToday, money } from '@/lib/format';
import { addDays, changedSheet } from '@/lib/invoice';
import { cn } from '@/lib/utils';
import { type Kind, type Lookups } from '@/types';
import { type ChangedSheet, type DraftSheet, type InvoiceDetail, type MailThread, type SplitPart, type ToConfirm } from '@/types/invoices';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useState } from 'react';

const THREAD_WORDS: Record<MailThread['how'], string> = {
    po: 'The PO email',
    enquiry: 'The enquiry',
    job: 'An email about the job',
    new: 'A new email',
};

/** An email address the server takes: one address with an ending, like accounts@client.co.uk. */
const looksLikeEmail = (a: string) => /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]{2,}$/.test(a.trim());

/** One line of a payment's split, in words: "Business expense · Travel (box 20) · Arena load-in · £23.40 · 50% business". */
function partWords(p: SplitPart, lookups: Lookups, currency: string): string {
    const [kind, category, job, , amount, pct] = p;
    const c = lookups.categories.find((x) => x.id === category);
    const j = lookups.jobs.find((x) => x.id === job);
    return [
        kindLabels[kind as Kind] ?? kind,
        c ? `${c.name}${c.box ? ` (box ${c.box})` : ''}` : null,
        j ? j.title : null,
        money(Math.abs(amount), currency),
        pct !== null && pct < 100 ? `${pct}% business` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

type How = 'send' | 'gmail' | 'issue';

/**
 * "Confirm and issue": what issuing does, shown first, since it can't be undone. The next number, who it's to, the
 * total and how many lines; the date and due date; each payment it confirms with its whole split; where the email goes
 * (the address and the conversation); then Issue and send to <address>, Issue and put in Gmail drafts, or Issue only.
 * Every press carries the lines and the last change this sheet showed, and each payment's fingerprint: when anything
 * changed since, nothing is issued and the sheet comes back with what changed.
 */
export function IssueSheet({
    open,
    onClose,
    invoice,
    draft,
    lookups,
    onIssued,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    invoice: InvoiceDetail;
    draft: DraftSheet;
    lookups: Lookups;
    onIssued: (message: string) => void;
    /** The draft changed since: load it again behind the sheet. */
    onChanged: () => void;
}) {
    const [fresh, setFresh] = useState<ChangedSheet | null>(null);
    const [changedWords, setChangedWords] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState<How | null>(null);
    const [issueDate, setIssueDate] = useState(londonToday());
    const [to, setTo] = useState('');
    const [typed, setTyped] = useState('');
    const [thread, setThread] = useState<string | null>(null);
    const [picking, setPicking] = useState(false);
    const [second, setSecond] = useState(false);
    const [usual, setUsual] = useState(false);

    useEffect(() => {
        if (!open) return;
        setFresh(null);
        setChangedWords(null);
        setProblem(null);
        setIssueDate(londonToday());
        setTo(draft.addresses[0] ?? '');
        setTyped('');
        setThread(draft.threads[0]?.thread_id ?? null);
        setPicking(false);
        setSecond(false);
        setUsual(false);
        // Only when it opens: a reload behind it keeps what you chose.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // What the sheet is tied to: the draft as this page loaded it, or as a refusal sent it back.
    const binding = {
        lines_version: fresh?.lines_version ?? invoice.lines_version,
        seen_up_to: fresh ? fresh.seen_up_to : invoice.seen_up_to,
        to_confirm: (fresh?.to_confirm ?? draft.to_confirm) as ToConfirm[],
        total: fresh?.total_minor ?? invoice.total_minor,
        lines: fresh?.lines ?? invoice.lines,
    };
    const ticked = binding.lines.filter((l) => l.included).length;
    const locked = binding.to_confirm.filter((c) => c.locked);
    const address = (typed.trim() !== '' ? typed.trim() : to).trim();
    const addressOk = address !== '' && looksLikeEmail(address);
    const due = issueDate ? addDays(issueDate, draft.terms_days) : null;
    const threads = draft.threads.filter((t) => t.thread_id !== 'new');
    const chosen = draft.threads.find((t) => t.thread_id === thread) ?? null;
    const needsSecond = !!draft.second_invoice && !second;
    const blocked = locked.length > 0 || needsSecond || binding.total <= 0;
    const toWhom = invoice.bill_to_name ?? 'the client';

    const go = async (how: How) => {
        setBusy(how);
        setProblem(null);
        const body = {
            lines_version: binding.lines_version,
            seen_up_to: binding.seen_up_to,
            confirm: binding.to_confirm.map((c) => ({ source: c.source, fp: c.fp })),
            second_invoice: !!draft.second_invoice && second,
            save_usual: usual,
            issue_date: issueDate || null,
            ...(how === 'issue' ? {} : { to: how === 'gmail' && !addressOk ? '' : address, thread }),
            ...(how === 'send' ? { total: binding.total } : {}),
        };
        const url =
            how === 'send'
                ? `/invoices/${invoice.id}/issue-and-send`
                : how === 'gmail'
                  ? `/invoices/${invoice.id}/gmail`
                  : `/invoices/${invoice.id}/issue`;
        try {
            const r = await postJson<{ message: string }>(url, body);
            onIssued(r.message);
        } catch (e) {
            if (e instanceof ApiError && e.problem === 'changed') {
                const sheet = changedSheet(e.data);
                setFresh(sheet);
                setChangedWords(e.message);
                onChanged();
            } else setProblem(problemText(e));
        } finally {
            setBusy(null);
        }
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && onClose()}
            title="Confirm and issue"
            description="After issuing it can’t be changed: you’d void it and issue a new one."
        >
            <SheetBody className="space-y-4">
                {changedWords && (
                    <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/60">
                        <p className="flex gap-2 font-medium text-amber-900 dark:text-amber-200">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {changedWords} Nothing was issued.
                        </p>
                        {fresh && fresh.changes.length > 0 && (
                            <ul className="list-disc space-y-1 pl-6 text-amber-950 dark:text-amber-100">
                                {fresh.changes.map((c) => (
                                    <li key={c.id}>{c.words}</li>
                                ))}
                            </ul>
                        )}
                        <p className="text-amber-900 dark:text-amber-200">This is the invoice as it is now: check it, then issue again.</p>
                    </div>
                )}
                <div className="space-y-1">
                    <p className="font-medium">Issue it as {draft.next_number ?? 'the next number'}?</p>
                    <p className="text-sm">
                        To {toWhom} · <span className="font-semibold tabular-nums">{money(binding.total, invoice.currency)}</span> · {ticked}{' '}
                        {ticked === 1 ? 'line' : 'lines'}
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="issue-date">Dated</Label>
                        <Input id="issue-date" type="date" className="h-11" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <span className="text-sm font-medium">Due</span>
                        <p className="flex h-11 items-center text-sm">
                            {due ? day(due) : '—'} ({draft.terms_days} days)
                        </p>
                    </div>
                </div>
                {binding.to_confirm.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {binding.to_confirm.length === 1
                                ? 'Issuing confirms this payment'
                                : `Issuing confirms these ${binding.to_confirm.length} payments`}
                        </h3>
                        <ul className="space-y-2">
                            {binding.to_confirm.map((c) => (
                                <li
                                    key={c.source}
                                    className={cn('rounded-lg border p-2 text-sm', c.locked && 'border-rose-300 dark:border-rose-900')}
                                >
                                    <p className="text-muted-foreground text-xs">Its whole split:</p>
                                    <ul className="mt-1 space-y-0.5">
                                        {(c.state?.lines ?? []).map((p, i) => (
                                            <li key={i} className="break-words">
                                                {partWords(p, lookups, 'GBP')}
                                            </li>
                                        ))}
                                    </ul>
                                    {c.locked && (
                                        <p className="mt-1 text-rose-700 dark:text-rose-400">
                                            {c.tax_year} is locked: untick its line, or unlock the year first.
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {draft.second_invoice && (
                    <label className="flex min-h-11 items-start gap-3 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            className="accent-primary mt-0.5 size-5 shrink-0"
                            checked={second}
                            onChange={(e) => setSecond(e.target.checked)}
                        />
                        <span>It’s a second invoice for this job: {draft.second_invoice.number} has its work already.</span>
                    </label>
                )}
                {draft.client && !draft.client.has_terms && (
                    <label className="flex min-h-11 items-start gap-3 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            className="accent-primary mt-0.5 size-5 shrink-0"
                            checked={usual}
                            onChange={(e) => setUsual(e.target.checked)}
                        />
                        <span>Save these ticks as {draft.client.name}’s usual</span>
                    </label>
                )}
                {draft.gmail ? (
                    <section className="space-y-3">
                        <div className="space-y-1.5">
                            <p className="text-sm font-medium">To</p>
                            {draft.addresses.length > 0 && (
                                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Who it goes to">
                                    {draft.addresses.map((a) => (
                                        <button
                                            key={a}
                                            type="button"
                                            role="radio"
                                            aria-checked={typed.trim() === '' && to === a}
                                            onClick={() => {
                                                setTo(a);
                                                setTyped('');
                                            }}
                                            className={cn(
                                                'min-h-11 max-w-full truncate rounded-full border px-3 text-sm',
                                                typed.trim() === '' && to === a
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'hover:bg-muted',
                                            )}
                                        >
                                            {a}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <Input
                                className="h-11"
                                type="email"
                                inputMode="email"
                                aria-label="Another address"
                                placeholder={draft.addresses.length > 0 ? 'Or type another address' : 'Their email for invoices'}
                                value={typed}
                                onChange={(e) => setTyped(e.target.value)}
                            />
                            {address !== '' && !addressOk && (
                                <p className="text-sm text-rose-700 dark:text-rose-400">Use one address, like accounts@client.co.uk.</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <p className="text-sm font-medium">Goes in</p>
                            <p className="text-sm">
                                {chosen
                                    ? chosen.thread_id === 'new'
                                        ? 'A new email'
                                        : `${THREAD_WORDS[chosen.how]}${chosen.subject ? `: ${chosen.subject}` : ''}`
                                    : 'A new email'}
                            </p>
                            {threads.length > 0 && (
                                <Button variant="ghost" className="h-11 px-2" onClick={() => setPicking((p) => !p)}>
                                    {picking ? 'Keep this one' : 'Use another conversation'}
                                </Button>
                            )}
                            {picking && (
                                <div className="space-y-1" role="radiogroup" aria-label="Which conversation">
                                    {draft.threads.map((t) => (
                                        <button
                                            key={t.thread_id}
                                            type="button"
                                            role="radio"
                                            aria-checked={thread === t.thread_id}
                                            onClick={() => {
                                                setThread(t.thread_id);
                                                setPicking(false);
                                            }}
                                            className={cn(
                                                'flex min-h-11 w-full flex-col items-start rounded-lg border px-3 py-2 text-left text-sm',
                                                thread === t.thread_id ? 'border-primary' : 'hover:bg-muted',
                                            )}
                                        >
                                            <span className="font-medium">{THREAD_WORDS[t.how]}</span>
                                            {t.subject && <span className="text-muted-foreground max-w-full truncate text-xs">{t.subject}</span>}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <p className="text-muted-foreground text-xs">Issue and put in Gmail drafts sends nothing: you press Send in Gmail.</p>
                        </div>
                    </section>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Gmail isn’t connected: after issuing, email it from Expend, or download the PDF and send it yourself.
                    </p>
                )}
                {needsSecond && <p className="text-sm text-amber-800 dark:text-amber-300">Tick that it’s a second invoice to issue it.</p>}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="space-y-2">
                {draft.gmail ? (
                    <>
                        {addressOk && (
                            <Button
                                className="h-auto min-h-11 w-full py-2 whitespace-normal"
                                disabled={blocked || busy !== null}
                                onClick={() => go('send')}
                            >
                                {busy === 'send' ? 'Issuing and sending…' : `Issue and send to ${address}`}
                            </Button>
                        )}
                        <Button
                            variant={addressOk ? 'outline' : 'default'}
                            className="h-auto min-h-11 w-full py-2 whitespace-normal"
                            disabled={blocked || busy !== null}
                            onClick={() => go('gmail')}
                        >
                            {busy === 'gmail'
                                ? 'Issuing…'
                                : addressOk
                                  ? 'Issue and put in Gmail drafts'
                                  : 'Issue and put in Gmail drafts (add the address there)'}
                        </Button>
                        <Button variant="ghost" className="h-11 w-full" disabled={blocked || busy !== null} onClick={() => go('issue')}>
                            {busy === 'issue' ? 'Issuing…' : 'Issue only'}
                        </Button>
                    </>
                ) : (
                    <Button className="h-11 w-full" disabled={blocked || busy !== null} onClick={() => go('issue')}>
                        {busy === 'issue' ? 'Issuing…' : 'Issue'}
                    </Button>
                )}
            </SheetFooter>
        </ResponsiveSheet>
    );
}
