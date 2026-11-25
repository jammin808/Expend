import { BottomSheet, Chip, SheetChoice } from '@/components/quick/bottom-sheet';
import { type PaidWith, type Pocket, type PocketCategory, type PocketJob } from '@/lib/quick';
import { useEffect, useRef, useState, type ReactNode } from 'react';

export const PAID_WORDS: Record<PaidWith, string> = { cash: 'Cash', personal_card: 'Personal card', business: 'Business card' };

/** "Cash", "Personal card", "Business card · Starling" (or just "Business card" for any of them). */
export function paidLabel(paid: PaidWith | null, accountId: number | null, pocket: Pocket): string {
    if (!paid) return 'Paid with?';
    if (paid !== 'business') return PAID_WORDS[paid];
    const account = pocket.accounts.find((a) => a.id === accountId);
    return account ? `Business card · ${account.name}` : 'Business card';
}

/**
 * How you paid. It decides what's recorded: a business card or account keeps only the photo until the bank line comes
 * in (nothing counted twice); a personal card Expend doesn't import, or cash, adds the expense now.
 */
export function PaidWithSheet({
    open,
    onOpenChange,
    pocket,
    paid,
    accountId,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pocket: Pocket;
    paid: PaidWith | null;
    accountId: number | null;
    /** done: the choice is complete (the sheet closes); false while the business card's account is still to pick. */
    onPick: (paid: PaidWith, accountId: number | null, done: boolean) => void;
}) {
    return (
        <BottomSheet open={open} onOpenChange={onOpenChange} title="How did you pay?">
            <div className="flex flex-col gap-2">
                <SheetChoice
                    selected={paid === 'business'}
                    onClick={() => onPick('business', paid === 'business' ? accountId : null, pocket.accounts.length === 0 || paid === 'business')}
                    title="Business card or account"
                    detail="Photo kept for the card payment. Nothing is added until the bank line comes in."
                >
                    {pocket.accounts.length > 0 ? (
                        <div className="flex flex-wrap gap-2 px-4 pb-3">
                            <Chip selected={paid === 'business' && accountId === null} onClick={() => onPick('business', null, true)}>
                                Any
                            </Chip>
                            {pocket.accounts.map((a) => (
                                <Chip key={a.id} selected={paid === 'business' && accountId === a.id} onClick={() => onPick('business', a.id, true)}>
                                    {a.name}
                                </Chip>
                            ))}
                        </div>
                    ) : null}
                </SheetChoice>
                <SheetChoice
                    selected={paid === 'personal_card'}
                    onClick={() => onPick('personal_card', null, true)}
                    title="Personal card (one Expend doesn’t import)"
                    detail="Added as an expense now."
                />
                <SheetChoice selected={paid === 'cash'} onClick={() => onPick('cash', null, true)} title="Cash" detail="Added as an expense now." />
            </div>
        </BottomSheet>
    );
}

function dayWords(date: string, today: string, yesterday: string): string {
    if (date === today) return 'Today';
    if (date === yesterday) return 'Yesterday';
    return new Date(date + 'T12:00:00Z').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
}

export { dayWords };

/** The job and the day: today's job first, other jobs near the date, No job; Today, Yesterday or a date you pick. */
export function JobWhenSheet({
    open,
    onOpenChange,
    jobs,
    jobId,
    onJob,
    date,
    today,
    yesterday,
    openFrom,
    onDate,
    yearNote,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobs: { job: PocketJob; note: string | null }[];
    jobId: number | null;
    onJob: (id: number | null) => void;
    date: string;
    today: string;
    yesterday: string;
    openFrom: string | null;
    onDate: (date: string) => void;
    yearNote: { tone: 'error' | 'warn'; text: string } | null;
}) {
    const picked = date !== today && date !== yesterday;
    return (
        <BottomSheet open={open} onOpenChange={onOpenChange} title="Job and day">
            <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">Job</h3>
            <div className="flex flex-col gap-2">
                {jobs.map(({ job, note }) => (
                    <SheetChoice
                        key={job.id}
                        selected={jobId === job.id}
                        onClick={() => onJob(job.id)}
                        title={job.title}
                        detail={note ?? job.venue ?? job.client ?? undefined}
                    />
                ))}
                <SheetChoice selected={jobId === null} onClick={() => onJob(null)} title="No job" detail="An overhead, or not for one job." />
            </div>
            <h3 className="text-muted-foreground mt-4 mb-2 text-xs font-semibold tracking-wide uppercase">When</h3>
            <div className="flex flex-wrap gap-2">
                <Chip selected={date === today} onClick={() => onDate(today)}>
                    Today
                </Chip>
                <Chip selected={date === yesterday} onClick={() => onDate(yesterday)}>
                    Yesterday
                </Chip>
                <label
                    className={`relative inline-flex min-h-12 items-center rounded-full border px-4 text-sm font-medium ${picked ? 'bg-primary text-primary-foreground border-primary' : ''}`}
                >
                    {picked ? dayWords(date, today, yesterday) : 'Pick a date'}
                    <input
                        type="date"
                        aria-label="Pick a date"
                        className="absolute inset-0 opacity-0"
                        value={date}
                        min={openFrom ?? undefined}
                        max={today}
                        onChange={(e) => {
                            if (e.target.value) onDate(e.target.value);
                        }}
                    />
                </label>
            </div>
            {yearNote ? (
                <p className={`mt-3 text-sm ${yearNote.tone === 'error' ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-300'}`}>
                    {yearNote.text}
                </p>
            ) : null}
        </BottomSheet>
    );
}

/** The business share, a note, and the sterling figure printed on a foreign receipt. */
export function MoreSheet({
    open,
    onOpenChange,
    pct,
    onPct,
    note,
    onNote,
    foreign,
    sterling,
    onSterling,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pct: number;
    onPct: (pct: number) => void;
    note: string;
    onNote: (note: string) => void;
    foreign: boolean;
    sterling: string;
    onSterling: (value: string) => void;
    /** Room for more choices (the clients and invoices build adds "Recharge to the client" here). */
    children?: ReactNode;
}) {
    const [custom, setCustom] = useState(pct !== 100 && pct !== 50);
    return (
        <BottomSheet open={open} onOpenChange={onOpenChange} title="More">
            <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">Business share</h3>
            <div className="flex flex-wrap items-center gap-2">
                <Chip
                    selected={pct === 100 && !custom}
                    onClick={() => {
                        setCustom(false);
                        onPct(100);
                    }}
                >
                    All business
                </Chip>
                <Chip
                    selected={pct === 50 && !custom}
                    onClick={() => {
                        setCustom(false);
                        onPct(50);
                    }}
                >
                    Half
                </Chip>
                <Chip selected={custom} onClick={() => setCustom(true)}>
                    Other
                </Chip>
                {custom ? (
                    <label className="flex min-h-12 items-center gap-2 text-sm">
                        <input
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={100}
                            className="h-12 w-20 rounded-lg border px-3 text-base"
                            value={pct}
                            onChange={(e) => onPct(Math.max(1, Math.min(100, parseInt(e.target.value || '100', 10) || 100)))}
                        />
                        % business
                    </label>
                ) : null}
            </div>
            {foreign ? (
                <>
                    <h3 className="text-muted-foreground mt-4 mb-2 text-xs font-semibold tracking-wide uppercase">Sterling on the receipt</h3>
                    <label className="flex items-center gap-2 text-sm">
                        <span className="text-lg">£</span>
                        <input
                            inputMode="decimal"
                            className="h-12 w-32 rounded-lg border px-3 text-base"
                            placeholder="Optional"
                            value={sterling}
                            onChange={(e) => onSterling(e.target.value.replace(/[^0-9.]/g, ''))}
                        />
                    </label>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Only if the receipt prints the pounds charged. Otherwise HMRC’s monthly rate is used.
                    </p>
                </>
            ) : null}
            <h3 className="text-muted-foreground mt-4 mb-2 text-xs font-semibold tracking-wide uppercase">Note</h3>
            <textarea
                className="min-h-20 w-full rounded-lg border p-3 text-base"
                value={note}
                onChange={(e) => onNote(e.target.value)}
                placeholder="Anything to remember about it"
            />
            {children}
        </BottomSheet>
    );
}

/** Every business category, and a text field for what it was ("NCP Leeds"). */
export function WhatSheet({
    open,
    onOpenChange,
    categories,
    categoryId,
    onCategory,
    what,
    onWhat,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: PocketCategory[];
    categoryId: number | null;
    onCategory: (id: number) => void;
    what: string;
    onWhat: (text: string) => void;
}) {
    const [draft, setDraft] = useState(what);
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (open) setDraft(what);
    }, [open, what]);
    const groups = new Map<string, PocketCategory[]>();
    for (const c of categories) {
        const g = c.group ?? '';
        groups.set(g, [...(groups.get(g) ?? []), c]);
    }
    // However the sheet closes (✕, Escape, Back, outside), what you typed is kept.
    const close = (next: boolean) => {
        if (!next && draft.trim() !== what) onWhat(draft.trim());
        onOpenChange(next);
    };
    return (
        <BottomSheet open={open} onOpenChange={close} title="What was it?">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onWhat(draft.trim());
                    onOpenChange(false);
                }}
                className="flex gap-2"
            >
                <input
                    ref={input}
                    className="h-12 min-w-0 flex-1 rounded-lg border px-3 text-base"
                    placeholder="What was it? (for example NCP Leeds)"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={() => onWhat(draft.trim())}
                    enterKeyHint="done"
                    autoComplete="off"
                />
                <button type="submit" className="bg-primary text-primary-foreground h-12 rounded-lg px-4 text-sm font-medium">
                    Done
                </button>
            </form>
            <div className="mt-3 flex flex-col gap-3">
                {[...groups.entries()].map(([group, list]) => (
                    <div key={group}>
                        {group ? <h3 className="text-muted-foreground mb-1 text-xs font-semibold tracking-wide uppercase">{group}</h3> : null}
                        <div className="flex flex-col">
                            {list.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => {
                                        if (draft.trim() !== what) onWhat(draft.trim());
                                        onCategory(c.id);
                                        onOpenChange(false);
                                    }}
                                    className={`flex min-h-12 items-center rounded-lg px-3 text-left text-base ${categoryId === c.id ? 'bg-primary text-primary-foreground' : ''}`}
                                    aria-pressed={categoryId === c.id}
                                >
                                    {c.name}
                                </button>
                            ))}
                        </div>
                    </div>
                ))}
                {categories.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Open Expend once with signal to load your categories.</p>
                ) : null}
            </div>
        </BottomSheet>
    );
}

/** £ by default, then €, $ and any other currency; the last foreign one you used comes first. */
export function CurrencySheet({
    open,
    onOpenChange,
    currency,
    lastForeign,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    currency: string;
    lastForeign: string | null;
    onPick: (code: string) => void;
}) {
    const [other, setOther] = useState('');
    const list = ['GBP', ...(lastForeign && !['GBP', 'EUR', 'USD'].includes(lastForeign) ? [lastForeign] : []), 'EUR', 'USD'];
    const names: Record<string, string> = { GBP: '£ Pounds', EUR: '€ Euros', USD: '$ US dollars' };
    return (
        <BottomSheet open={open} onOpenChange={onOpenChange} title="Currency">
            <div className="flex flex-wrap gap-2">
                {list.map((code) => (
                    <Chip key={code} selected={currency === code} onClick={() => onPick(code)}>
                        {names[code] ?? code}
                    </Chip>
                ))}
            </div>
            <form
                className="mt-3 flex gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (/^[A-Za-z]{3}$/.test(other)) onPick(other.toUpperCase());
                }}
            >
                <input
                    className="h-12 w-28 rounded-lg border px-3 text-base uppercase"
                    placeholder="Other…"
                    maxLength={3}
                    value={other}
                    onChange={(e) => setOther(e.target.value.replace(/[^A-Za-z]/g, ''))}
                    autoCapitalize="characters"
                    aria-label="Other currency code, for example CHF"
                />
                <button
                    type="submit"
                    className="bg-primary text-primary-foreground h-12 rounded-lg px-4 text-sm font-medium"
                    disabled={!/^[A-Za-z]{3}$/.test(other)}
                >
                    Use it
                </button>
            </form>
        </BottomSheet>
    );
}
