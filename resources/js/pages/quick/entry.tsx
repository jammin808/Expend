import QuickLayout from '@/layouts/quick-layout';
import { currencyDigits, money, moneyMinor } from '@/lib/format';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * One entry from the phone (or Telegram): its photo, what happened to it, what the photo's reading said, the bank
 * lines it could be the receipt for, and what you can do. Every button here is one of the entry's own actions (the
 * same ones To do and Telegram offer, fingerprinted so a press after a change is refused).
 */

interface Line {
    id: number;
    words: string;
    account: string | null;
    date: string;
    amount_pence: number;
    description: string | null;
    original: string | null;
    href: string;
    fits?: boolean;
    near?: boolean;
    attached_by?: string | null;
    active?: boolean;
}

interface Action {
    code: string;
    id: string;
    label: string;
    style?: string;
    fp?: string | null;
}

interface Entry {
    id: number;
    client_key: string;
    state: string;
    state_words: string;
    hold_reason: string | null;
    message: string;
    words: string;
    amount_minor: number | null;
    currency: string;
    sterling_pence: number | null;
    what: string | null;
    what_source: string | null;
    category: { id: number; name: string } | null;
    job: { id: number; title: string } | null;
    job_source: string | null;
    paid_with: string | null;
    paid_with_words: string | null;
    account: { id: number; name: string } | null;
    spent_on: string | null;
    spent_at: string | null;
    captured_at: string | null;
    tax_year: string | null;
    business_pct: number | null;
    note: string | null;
    channel: string;
    photo: {
        document_id: number;
        href: string;
        file: string;
        status: string | null;
        name: string | null;
        mime: string | null;
        read: string | null;
    } | null;
    photo_status: string | null;
    read_check: {
        status: string | null;
        note: string | null;
        agrees: boolean;
        questions: { field: string; text: string; use: string; keep: string }[];
    } | null;
    line: Line | null;
    expense: { id: number; href: string; amount_pence: number } | null;
    duplicate_of: { id: number; words: string; href: string } | null;
    locked_note: string | null;
    locked: boolean;
}

interface Props {
    entry: Entry;
    lines: Line[];
    actions: Action[];
    categories: { id: number; name: string; code: string }[];
    jobs: { id: number; title: string; starts_on: string }[];
    can_edit: boolean;
    can_detach: boolean;
    [key: string]: unknown;
}

const CHANNEL: Record<string, string> = { phone: 'Added on your phone', share: 'Shared to Expend on your phone', telegram: 'Sent in Telegram' };

function day(date: string | null): string {
    if (!date) return '';
    return new Date(date + 'T12:00:00Z').toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex min-h-10 items-baseline gap-3 py-1">
            <dt className="text-muted-foreground w-28 shrink-0 text-sm">{label}</dt>
            <dd className="min-w-0 flex-1 text-sm">{children}</dd>
        </div>
    );
}

const button = 'min-h-12 rounded-xl border px-4 text-sm font-medium';
const primary = 'bg-primary text-primary-foreground min-h-12 rounded-xl px-4 text-sm font-medium';

export default function QuickEntryPage({ entry, lines, actions, categories, jobs, can_edit, can_detach }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        amount:
            entry.amount_minor !== null ? (entry.amount_minor / 10 ** currencyDigits(entry.currency)).toFixed(currencyDigits(entry.currency)) : '',
        what: entry.what ?? '',
        category_id: entry.category?.id ?? '',
        work_job_id: entry.job?.id ?? '',
        spent_on: entry.spent_on ?? '',
        paid_with: entry.paid_with ?? '',
        business_pct: entry.business_pct ?? 100,
        note: entry.note ?? '',
    });
    const isImage = entry.photo?.mime?.startsWith('image/') ?? false;
    const amount = entry.amount_minor !== null ? moneyMinor(entry.amount_minor, entry.currency, { signed: false }) : 'No amount typed';

    const done = { preserveScroll: true, onFinish: () => setBusy(false) };
    const act = (a: Action) => {
        setBusy(true);
        router.post(`/todo/${a.code}/${encodeURIComponent(a.id)}`, a.fp ? { fp: a.fp } : {}, done);
    };
    const attach = (line: Line) => {
        setBusy(true);
        router.post(`/quick/entries/${entry.id}/attach`, { line_id: line.id }, done);
    };
    const detach = () => {
        setBusy(true);
        router.post(`/quick/entries/${entry.id}/detach`, {}, done);
    };
    const readAgain = () => {
        setBusy(true);
        router.post(`/quick/entries/${entry.id}/read`, {}, done);
    };
    const save = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.patch(
            `/quick/entries/${entry.id}`,
            {
                amount: form.amount || null,
                what: form.what || null,
                category_id: form.category_id === '' ? null : Number(form.category_id),
                work_job_id: form.work_job_id === '' ? null : Number(form.work_job_id),
                spent_on: form.spent_on || null,
                paid_with: form.paid_with || null,
                business_pct: Number(form.business_pct) || 100,
                note: form.note || null,
            },
            { ...done, onSuccess: () => setEditing(false) },
        );
    };
    const readFailed = entry.photo && (entry.photo.read === 'failed' || entry.photo.read === 'waiting_budget');

    return (
        <QuickLayout title="Your entry">
            <main className="mx-auto max-w-lg px-4 pt-3" style={{ paddingBottom: 'calc(24px + env(safe-area-inset-bottom))' }}>
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {flash?.error ? (
                    <p
                        role="alert"
                        className="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-100"
                    >
                        {flash.error}
                    </p>
                ) : null}

                <section className="flex gap-3">
                    {entry.photo ? (
                        <a href={entry.photo.href} className="shrink-0" aria-label="Open the photo">
                            {isImage ? (
                                <img src={entry.photo.file} alt="Receipt photo" className="size-24 rounded-xl border object-cover" />
                            ) : (
                                <span className="bg-muted flex size-24 items-center justify-center rounded-xl border">
                                    <FileText className="size-8" />
                                </span>
                            )}
                        </a>
                    ) : (
                        <span className="bg-muted text-muted-foreground flex size-24 shrink-0 items-center justify-center rounded-xl border text-center text-xs">
                            {entry.photo_status === 'expected' ? 'Photo still on your phone' : 'No photo'}
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <h1 className="text-xl font-semibold">{amount}</h1>
                        <p className="truncate text-base">{entry.what ?? entry.category?.name ?? ''}</p>
                        <span className="bg-muted mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-medium">{entry.state_words}</span>
                    </div>
                </section>
                <p className="mt-3 text-sm">{entry.message}</p>
                {entry.locked_note ? <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">{entry.locked_note}</p> : null}
                {entry.photo_status === 'expected' ? (
                    <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">
                        The photo is still on your phone: open Expend there with signal to send it.
                    </p>
                ) : null}
                {entry.photo_status === 'unreadable' ? (
                    <p className="text-muted-foreground mt-2 text-sm">
                        Expend can’t read this kind of photo. It’s kept; take the next one with Expend’s camera button.
                    </p>
                ) : null}

                {/* What the photo's reading said against what you typed. */}
                {entry.read_check ? (
                    <section className="mt-4 rounded-xl border p-3">
                        <h2 className="text-sm font-semibold">The photo</h2>
                        {entry.read_check.agrees ? <p className="mt-1 text-sm">The photo agrees.</p> : null}
                        {entry.read_check.note ? <p className="mt-1 text-sm">{entry.read_check.note}</p> : null}
                        {entry.read_check.questions.map((q) => (
                            <p key={q.field} className="mt-1 text-sm">
                                {q.text}
                            </p>
                        ))}
                    </section>
                ) : entry.photo && entry.photo.read !== 'extracted' ? (
                    <p className="text-muted-foreground mt-3 text-sm">Not read yet.</p>
                ) : null}

                {actions.length > 0 ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {actions.map((a) => (
                            <button
                                key={`${a.code}:${a.id}`}
                                type="button"
                                disabled={busy}
                                onClick={() => act(a)}
                                className={a.style === 'primary' ? primary : button}
                            >
                                {a.label}
                            </button>
                        ))}
                        {readFailed ? (
                            <button type="button" disabled={busy} onClick={readAgain} className={button}>
                                Read again
                            </button>
                        ) : null}
                    </div>
                ) : readFailed ? (
                    <button type="button" disabled={busy} onClick={readAgain} className={`${button} mt-4`}>
                        Read again
                    </button>
                ) : null}

                {/* Its bank line, or the lines it could be the receipt for. */}
                {entry.line ? (
                    <section className="mt-4 rounded-xl border p-3">
                        <h2 className="text-sm font-semibold">Its bank line</h2>
                        <a href={entry.line.href} className="mt-1 flex min-h-12 items-center text-sm underline">
                            {entry.line.words}
                        </a>
                        <p className="text-muted-foreground text-xs">
                            {entry.line.attached_by === 'you'
                                ? 'You put the receipt on this line.'
                                : 'Matched by amount and date: confirm it in the Inbox.'}
                            {entry.line.active === false ? ' That import was undone: it waits for the line to come in again.' : ''}
                        </p>
                        {can_detach ? (
                            <button type="button" disabled={busy} onClick={detach} className={`${button} mt-2`}>
                                Not this payment’s receipt
                            </button>
                        ) : null}
                    </section>
                ) : null}
                {lines.length > 0 ? (
                    <section className="mt-4">
                        <h2 className="mb-2 text-sm font-semibold">Is it one of these payments?</h2>
                        <ul className="flex flex-col divide-y rounded-xl border">
                            {lines.map((l) => (
                                <li key={l.id} className="flex items-center gap-3 p-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {money(Math.abs(l.amount_pence))} · {l.account ?? ''} · {day(l.date)}
                                        </p>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {l.description}
                                            {l.original ? ` (${l.original})` : ''}
                                            {l.fits ? ' · fits' : l.near ? ' · close (a tip or a pre-authorisation?)' : ''}
                                        </p>
                                    </div>
                                    <button type="button" disabled={busy} onClick={() => attach(l)} className={l.fits ? primary : button}>
                                        It’s this one
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <dl className="mt-4 divide-y rounded-xl border px-3">
                    <Row label="Paid with">
                        {entry.paid_with_words ?? 'Not said'}
                        {entry.account ? ` · ${entry.account.name}` : ''}
                    </Row>
                    <Row label="When">
                        {day(entry.spent_on)}
                        {entry.spent_at ? ` at ${entry.spent_at}` : ''}
                        {entry.tax_year ? ` · ${entry.tax_year}${entry.locked ? ' (locked)' : ''}` : ''}
                    </Row>
                    <Row label="What">{entry.category?.name ?? 'No category yet'}</Row>
                    <Row label="Job">{entry.job ? entry.job.title : 'No job'}</Row>
                    {entry.business_pct !== null && entry.business_pct !== 100 ? <Row label="Business share">{entry.business_pct}%</Row> : null}
                    {entry.sterling_pence ? <Row label="Sterling on it">{money(entry.sterling_pence)}</Row> : null}
                    {entry.note ? <Row label="Note">{entry.note}</Row> : null}
                    <Row label="From">{CHANNEL[entry.channel] ?? entry.channel}</Row>
                    {entry.expense ? (
                        <Row label="Expense">
                            <a href={entry.expense.href} className="inline-flex min-h-12 items-center underline">
                                {money(Math.abs(entry.expense.amount_pence))} in Transactions
                            </a>
                        </Row>
                    ) : null}
                    {entry.duplicate_of ? (
                        <Row label="Same as">
                            <a href={entry.duplicate_of.href} className="inline-flex min-h-12 items-center underline">
                                {entry.duplicate_of.words}
                            </a>
                        </Row>
                    ) : null}
                </dl>

                {can_edit ? (
                    editing ? (
                        <form onSubmit={save} className="mt-4 flex flex-col gap-3 rounded-xl border p-3">
                            <h2 className="text-sm font-semibold">Change it</h2>
                            <label className="flex flex-col gap-1 text-sm">
                                Amount ({entry.currency})
                                <input
                                    inputMode="decimal"
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.amount}
                                    onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/[^0-9.,]/g, '') })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                What it was
                                <input
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.what}
                                    onChange={(e) => setForm({ ...form, what: e.target.value })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Category
                                <select
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.category_id}
                                    onChange={(e) => setForm({ ...form, category_id: e.target.value === '' ? '' : Number(e.target.value) })}
                                >
                                    <option value="">Choose…</option>
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Job
                                <select
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.work_job_id}
                                    onChange={(e) => setForm({ ...form, work_job_id: e.target.value === '' ? '' : Number(e.target.value) })}
                                >
                                    <option value="">No job</option>
                                    {jobs.map((j) => (
                                        <option key={j.id} value={j.id}>
                                            {j.title} ({day(j.starts_on)})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Date
                                <input
                                    type="date"
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.spent_on}
                                    onChange={(e) => setForm({ ...form, spent_on: e.target.value })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Paid with
                                <select
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.paid_with}
                                    onChange={(e) => setForm({ ...form, paid_with: e.target.value })}
                                >
                                    <option value="">Choose…</option>
                                    <option value="business">Business card or account</option>
                                    <option value="personal_card">Personal card</option>
                                    <option value="cash">Cash</option>
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Business share (%)
                                <input
                                    type="number"
                                    min={1}
                                    max={100}
                                    className="h-12 rounded-lg border px-3 text-base"
                                    value={form.business_pct}
                                    onChange={(e) => setForm({ ...form, business_pct: Number(e.target.value) })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Note
                                <textarea
                                    className="min-h-20 rounded-lg border p-3 text-base"
                                    value={form.note}
                                    onChange={(e) => setForm({ ...form, note: e.target.value })}
                                />
                            </label>
                            <div className="flex gap-2">
                                <button type="submit" disabled={busy} className={`${primary} flex-1`}>
                                    Save
                                </button>
                                <button type="button" onClick={() => setEditing(false)} className={`${button} flex-1`}>
                                    Cancel
                                </button>
                            </div>
                        </form>
                    ) : (
                        <button type="button" onClick={() => setEditing(true)} className={`${button} mt-4 w-full`}>
                            Change it
                        </button>
                    )
                ) : null}

                <p className="mt-6">
                    <a href="/quick" className="flex min-h-12 items-center justify-center rounded-xl border text-sm font-medium">
                        Add another expense
                    </a>
                </p>
            </main>
        </QuickLayout>
    );
}
