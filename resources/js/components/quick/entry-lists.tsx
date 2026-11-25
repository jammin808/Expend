import { BottomSheet } from '@/components/quick/bottom-sheet';
import { moneyMinor } from '@/lib/format';
import { type LocalEntry, type PocketEntry, type SharedItem } from '@/lib/quick';
import { FileText, ImageIcon } from 'lucide-react';
import { useState } from 'react';

const SERVER_STATUS: Record<string, string> = {
    added: 'Added',
    waiting: 'Waiting for its bank line',
    attached: 'On its bank line',
    held: 'Kept, not added',
    duplicate: 'Not added: same photo',
    set_aside: 'Not a business cost',
    withdrawn: 'Removed',
    dismissed: 'Not an expense',
    draft: 'Not added yet',
    receiving: 'Arriving',
};

type Tone = 'wait' | 'ok' | 'bad' | 'muted';

const TONES: Record<Tone, string> = {
    wait: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100',
    ok: 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
    bad: 'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-100',
    muted: 'bg-muted text-muted-foreground',
};

function clock(ms: number): string {
    return new Date(ms).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London' });
}

/** The pill for an entry still on the phone, with its tries and next try. */
export function localPill(e: LocalEntry, signedOut: boolean): { text: string; tone: Tone } {
    switch (e.state) {
        case 'sending':
            return { text: 'Sending…', tone: 'wait' };
        case 'photo_pending':
            return { text: signedOut ? 'Sign in to send the photo' : 'Photo still to send', tone: 'wait' };
        case 'needs_you':
            return { text: 'Needs you', tone: 'bad' };
        case 'signed_out':
            return { text: 'Sign in to send', tone: 'bad' };
        case 'withdraw_pending':
            return { text: 'Removing…', tone: 'wait' };
        case 'sent':
            return { text: 'Sent ✓', tone: 'ok' };
        case 'withdrawn':
            return { text: 'Removed', tone: 'muted' };
        default: {
            if (signedOut) return { text: 'Sign in to send', tone: 'bad' };
            const tries = e.attempts ?? 0;
            const next = e.next_at && e.next_at > Date.now() ? ` · next try ${clock(e.next_at)}` : '';
            return { text: `Waiting to send${tries > 0 ? ` (${tries} ${tries === 1 ? 'try' : 'tries'})` : ''}${next}`, tone: 'wait' };
        }
    }
}

function Thumb({ src, pdf }: { src: string | null; pdf?: boolean }) {
    if (src) return <img src={src} alt="" className="size-12 shrink-0 rounded-lg border object-cover" />;
    return (
        <span className="bg-muted text-muted-foreground flex size-12 shrink-0 items-center justify-center rounded-lg border">
            {pdf ? <FileText className="size-5" /> : <ImageIcon className="size-5" />}
        </span>
    );
}

function Pill({ text, tone }: { text: string; tone: Tone }) {
    return <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${TONES[tone]}`}>{text}</span>;
}

function amountOf(e: { amount_minor: number | null; currency: string }): string {
    return e.amount_minor === null ? 'No amount' : moneyMinor(e.amount_minor, e.currency, { signed: false });
}

function dayOf(date: string | null): string {
    if (!date) return '';
    return new Date(date + 'T12:00:00Z').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
}

/**
 * Waiting to send: entries Expend hasn't got yet (or whose photo it hasn't), with Send now, Edit and Delete, and
 * Android shares not made into entries yet.
 */
export function WaitingList({
    entries,
    shared,
    signedOut,
    onSendNow,
    onEdit,
    onDelete,
    onKeepWithoutPhoto,
    onAddShared,
    onDeleteShared,
    note,
}: {
    entries: LocalEntry[];
    shared: SharedItem[];
    signedOut: boolean;
    onSendNow: (key: string) => void;
    onEdit: (entry: LocalEntry) => void;
    onDelete: (entry: LocalEntry) => void;
    /** An entry Expend has whose photo can't be sent: keep the entry, drop the photo from the phone. */
    onKeepWithoutPhoto?: (entry: LocalEntry) => void;
    onAddShared: () => void;
    onDeleteShared: () => void;
    note: string | null;
}) {
    const [confirm, setConfirm] = useState<LocalEntry | null>(null);
    const [confirmShared, setConfirmShared] = useState(false);
    if (entries.length === 0 && shared.length === 0) return null;
    const files = shared.filter((s) => s.file).length;
    return (
        <section id="waiting" className="scroll-mt-16 px-4 pt-4">
            <h2 className="mb-2 text-sm font-semibold">Waiting to send</h2>
            {note ? <p className="text-muted-foreground mb-2 text-sm">{note}</p> : null}
            <ul className="flex flex-col divide-y rounded-xl border">
                {shared.length > 0 ? (
                    <li className="flex flex-wrap items-center gap-2 p-3">
                        <span className="min-w-0 flex-1 text-sm font-medium">
                            {files > 0
                                ? `${files} shared ${files === 1 ? 'photo or PDF' : 'photos or PDFs'} not added yet`
                                : `${shared.length} shared ${shared.length === 1 ? 'message' : 'messages'} not added yet`}
                        </span>
                        <button
                            type="button"
                            onClick={onAddShared}
                            className="bg-primary text-primary-foreground min-h-12 rounded-lg px-4 text-sm font-medium"
                        >
                            Add
                        </button>
                        <button type="button" onClick={() => setConfirmShared(true)} className="min-h-12 rounded-lg border px-4 text-sm font-medium">
                            Delete
                        </button>
                    </li>
                ) : null}
                {entries.map((e) => {
                    const pill = localPill(e, signedOut);
                    const editable = (e.state === 'waiting' || e.state === 'signed_out' || e.state === 'needs_you') && !e.server_id;
                    return (
                        <li key={e.client_key} className="flex flex-col gap-2 p-3">
                            <div className="flex items-center gap-3">
                                <Thumb src={e.thumb} pdf={e.photo?.type === 'application/pdf'} />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {amountOf(e.summary)} {e.summary.what}
                                    </p>
                                    <p className="text-muted-foreground truncate text-xs">
                                        {[e.summary.paid, e.summary.job, dayOf(e.summary.date)].filter(Boolean).join(' · ')}
                                    </p>
                                    <Pill text={pill.text} tone={pill.tone} />
                                </div>
                            </div>
                            {e.last_error && e.state !== 'sending' ? <p className="text-muted-foreground text-xs">{e.last_error}</p> : null}
                            {e.state !== 'sending' && e.state !== 'withdraw_pending' ? (
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => onSendNow(e.client_key)}
                                        className="min-h-12 rounded-lg border px-4 text-sm font-medium"
                                    >
                                        Send now
                                    </button>
                                    {editable ? (
                                        <button
                                            type="button"
                                            onClick={() => onEdit(e)}
                                            className="min-h-12 rounded-lg border px-4 text-sm font-medium"
                                        >
                                            Edit
                                        </button>
                                    ) : null}
                                    {e.state === 'needs_you' && e.server_id && e.photo?.bytes && onKeepWithoutPhoto ? (
                                        <button
                                            type="button"
                                            onClick={() => onKeepWithoutPhoto(e)}
                                            className="min-h-12 rounded-lg border px-4 text-sm font-medium"
                                        >
                                            Keep it without the photo
                                        </button>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => setConfirm(e)}
                                        className="min-h-12 rounded-lg border px-4 text-sm font-medium text-red-700 dark:text-red-400"
                                    >
                                        Delete
                                    </button>
                                </div>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
            <BottomSheet
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={confirm?.server_id ? 'Remove this expense from Expend?' : 'Delete this entry?'}
                description={
                    confirm
                        ? confirm.server_id
                            ? `${amountOf(confirm.summary)} ${confirm.summary.what}. It’s in Expend already: this takes it out of Expend too.`
                            : (confirm.tries ?? 0) > 0
                              ? `${amountOf(confirm.summary)} ${confirm.summary.what}. It may already have reached Expend, so it’s removed there too once there’s signal.`
                              : `${amountOf(confirm.summary)} ${confirm.summary.what}. It hasn’t been sent, so it’s gone for good.`
                        : undefined
                }
            >
                <div className="flex gap-2">
                    <button
                        type="button"
                        className="min-h-12 flex-1 rounded-lg bg-red-600 px-4 text-sm font-medium text-white"
                        onClick={() => {
                            if (confirm) onDelete(confirm);
                            setConfirm(null);
                        }}
                    >
                        {confirm?.server_id ? 'Remove from Expend' : 'Delete'}
                    </button>
                    <button type="button" className="min-h-12 flex-1 rounded-lg border px-4 text-sm font-medium" onClick={() => setConfirm(null)}>
                        Keep it
                    </button>
                </div>
            </BottomSheet>
            <BottomSheet
                open={confirmShared}
                onOpenChange={setConfirmShared}
                title="Delete what you shared?"
                description="The shared photos, PDFs and messages not added yet are deleted from this phone. Nothing reaches Expend."
            >
                <div className="flex gap-2">
                    <button
                        type="button"
                        className="min-h-12 flex-1 rounded-lg bg-red-600 px-4 text-sm font-medium text-white"
                        onClick={() => {
                            onDeleteShared();
                            setConfirmShared(false);
                        }}
                    >
                        Delete {shared.length}
                    </button>
                    <button
                        type="button"
                        className="min-h-12 flex-1 rounded-lg border px-4 text-sm font-medium"
                        onClick={() => setConfirmShared(false)}
                    >
                        Keep them
                    </button>
                </div>
            </BottomSheet>
        </section>
    );
}

interface RecentRow {
    key: string;
    thumb: string | null;
    pdf: boolean;
    amount: string;
    what: string;
    line: string;
    pill: { text: string; tone: Tone };
    detail: string | null;
    warnings: string[];
    href: string | null;
    at: number;
}

/**
 * Recent: what this phone sent in the last 14 days and the last entries Expend has (from the pocket), newest first.
 * A row is a plain link to the entry's page; with no signal it opens a sheet with what the phone knows instead.
 */
export function RecentList({ entries, server }: { entries: LocalEntry[]; server: PocketEntry[] }) {
    const [open, setOpen] = useState<RecentRow | null>(null);
    const rows: RecentRow[] = [];
    const seen = new Set<string>();
    for (const e of entries) {
        if (e.state !== 'sent') continue;
        seen.add(e.client_key);
        const fromServer = server.find((s) => s.client_key === e.client_key);
        rows.push({
            key: e.client_key,
            thumb: e.thumb,
            pdf: e.photo?.type === 'application/pdf',
            amount: amountOf(e.summary),
            what: e.summary.what,
            line: [e.summary.paid, e.summary.job, dayOf(e.summary.date)].filter(Boolean).join(' · '),
            // Sent, but not added (the same photo as another entry, or kept for you): amber, never the green of a success.
            pill:
                e.server_state === 'duplicate'
                    ? { text: 'Not added: same photo', tone: 'wait' }
                    : e.server_state === 'held'
                      ? { text: 'Kept, not added', tone: 'wait' }
                      : { text: 'Sent ✓', tone: 'ok' },
            detail: fromServer?.result ?? e.result ?? null,
            warnings: e.warnings ?? [],
            href: e.server_id ? `/quick/entries/${e.server_id}` : null,
            at: e.sent_at ?? e.saved_at,
        });
    }
    for (const s of server) {
        if (s.client_key && seen.has(s.client_key)) continue;
        rows.push({
            key: `server:${s.id}`,
            thumb: null,
            pdf: false,
            // Expend's words already say the amount ("£40.00 fuel").
            amount: s.what ? amountOf(s) : '',
            what: s.what ?? s.words,
            line: [
                s.paid_with
                    ? ({ cash: 'Cash', personal_card: 'Personal card', business: 'Business card' } as Record<string, string>)[s.paid_with]
                    : null,
                s.job,
                dayOf(s.date),
            ]
                .filter(Boolean)
                .join(' · '),
            pill: {
                text: SERVER_STATUS[s.status] ?? s.status,
                tone:
                    s.status === 'held'
                        ? 'bad'
                        : s.status === 'duplicate'
                          ? 'wait'
                          : s.status === 'withdrawn' || s.status === 'dismissed'
                            ? 'muted'
                            : 'ok',
            },
            detail: s.result,
            warnings: [],
            href: `/quick/entries/${s.id}`,
            at: s.at ? Date.parse(s.at) : 0,
        });
    }
    rows.sort((a, b) => b.at - a.at);
    if (rows.length === 0) return null;
    return (
        <section className="px-4 pt-4">
            <h2 className="mb-2 text-sm font-semibold">Recent</h2>
            <ul className="flex flex-col divide-y rounded-xl border">
                {rows.slice(0, 20).map((r) => (
                    <li key={r.key}>
                        <a
                            href={r.href ?? '#'}
                            className="flex min-h-16 items-center gap-3 p-3"
                            onClick={(ev) => {
                                if (!r.href || !navigator.onLine) {
                                    ev.preventDefault();
                                    setOpen(r);
                                }
                            }}
                        >
                            <Thumb src={r.thumb} pdf={r.pdf} />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">{[r.amount, r.what].filter(Boolean).join(' ')}</p>
                                <p className="text-muted-foreground truncate text-xs">{r.detail ?? r.line}</p>
                                <Pill text={r.pill.text} tone={r.pill.tone} />
                            </div>
                        </a>
                    </li>
                ))}
            </ul>
            <BottomSheet
                open={open !== null}
                onOpenChange={(o) => !o && setOpen(null)}
                title={open ? [open.amount, open.what].filter(Boolean).join(' ') : ''}
            >
                {open ? (
                    <div className="flex flex-col gap-2 text-sm">
                        {open.thumb ? <img src={open.thumb} alt="Receipt" className="max-h-48 self-start rounded-lg border" /> : null}
                        <p>{open.line}</p>
                        {open.detail ? <p>{open.detail}</p> : null}
                        {open.warnings.map((w) => (
                            <p key={w} className="text-amber-800 dark:text-amber-200">
                                {w}
                            </p>
                        ))}
                        <p className="text-muted-foreground">
                            {open.href
                                ? 'This is what the phone knows. Open it with signal to see the rest.'
                                : 'Expend has this; its page opens once the phone knows its number.'}
                        </p>
                    </div>
                ) : null}
            </BottomSheet>
        </section>
    );
}
