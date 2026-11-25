/**
 * The page's side of quick add: types for window.ExpendQuick (public/quick-store.js, which keeps entries on the phone
 * and sends them), the day's data ("pocket") as the screen uses it, the waiting and needs-you counts for the pill and
 * the + button, and small phone checks (iPhone, home-screen app).
 */
import { useEffect, useState } from 'react';

export type PaidWith = 'cash' | 'personal_card' | 'business';

export type PhoneState =
    | 'waiting'
    | 'sending'
    | 'photo_pending'
    | 'sent'
    | 'receiving'
    | 'needs_you'
    | 'signed_out'
    | 'withdraw_pending'
    | 'withdrawn';

/** What is sent as POST /quick/entries (with client_key and payload_sha). */
export interface EntryFields {
    channel: 'phone' | 'share';
    paid_with: PaidWith;
    account_id: number | null;
    spent_on: string;
    spent_at: string | null;
    captured_at: string;
    amount: string | null;
    currency: string;
    sterling: string | null;
    what: string | null;
    what_source: 'typed' | 'chip' | null;
    category_id: number | null;
    work_job_id: number | null;
    job_source: 'today' | 'you' | 'words' | null;
    business_pct: number;
    note: string | null;
    photo: { sha256: string; size: number; mime: string; name: string; original: boolean } | null;
}

/** An entry as the phone keeps it (IndexedDB 'entries'). */
export interface LocalEntry {
    client_key: string;
    created_at: number;
    saved_at: number;
    state: PhoneState;
    fields: EntryFields;
    payload_sha: string;
    photo: { bytes: ArrayBuffer | null; type: string; sha256: string; size: number; name: string; original: boolean } | null;
    thumb: string | null;
    /** For the lists, without working anything out again. */
    summary: { amount_minor: number | null; currency: string; what: string; paid: string; job: string | null; date: string; category: string | null };
    attempts?: number;
    tries?: number;
    next_at?: number;
    last_error?: string | null;
    /** "£12.50 parking", as Expend says it. */
    words?: string | null;
    /** What happened, in Expend's words: "Added as a cash expense", "Kept, not added: 2024-25 is locked". */
    result?: string | null;
    href?: string | null;
    server_id?: number | null;
    server_state?: string | null;
    warnings?: string[];
    changed?: boolean;
    kept?: boolean;
    sent_at?: number;
    sending_kind?: string | null;
    mismatches?: number;
}

export interface SharedItem {
    id: number;
    at: number;
    title: string;
    text: string;
    url: string;
    file: { name: string; type: string; size: number; bytes: ArrayBuffer } | null;
}

export interface Counts {
    waiting: number;
    needs_you: number;
    shared: number;
    /** Signed out with something waiting to send. */
    signed_out: boolean;
    /** Expend last said you're signed out, whether or not anything waits. */
    signed_out_now?: boolean;
    total: number;
}

export interface FlushResult {
    sent: number;
    left: number;
    signedOut: boolean;
    offline: boolean;
    busy: boolean;
}

export interface Parsed {
    amount: string;
    minor: number;
    amount_minor: number;
    currency: string;
    what: string | null;
    paid_with: PaidWith | null;
    account_id: number | null;
    /** Always a date (today when the text names none). */
    date: string;
    /** The words that named the day ("yesterday", "23 Nov"), or null. */
    date_word: string | null;
    job_id: number | null;
}

export interface JobChoice {
    job_id: number | null;
    source: 'you' | 'today' | 'words' | null;
    date: string;
    on: number[];
    near: number[];
    carried: boolean;
}

export interface Suggestion {
    category_id: number | null;
    code?: string | null;
    count: number;
    source: 'recent' | 'hint' | 'server';
    what?: string;
}

export interface ExpendQuickApi {
    backoff(attempts: number): number;
    keypad(text: string, key: string, digits: number): string;
    keypadMinor(text: string, digits: number): number | null;
    keypadAmount(text: string): string | null;
    keypadText(minor: number | null, digits: number): string;
    withdrawRule(entry: LocalEntry | null): 'gone' | 'delete' | 'withdraw';
    stateWords(entry: LocalEntry): string;
    parseText(
        text: string,
        today: string,
        ctx?: { digits?: (c: string) => number; accounts?: { id: number; name: string }[]; jobs?: PocketJob[] },
    ): Parsed | null;
    defaultJob(
        pocket: { jobs: PocketJob[] } | null,
        o: {
            date: string;
            today: string;
            time: string;
            now: number;
            launchJobId?: number | null;
            carry?: { job_id: number | null; job_source: string | null; date: string; day: string; at: number } | null;
            jobCost?: boolean | null;
        },
    ): JobChoice;
    suggestOffline(pocket: { recent_whats: Pocket['recent_whats']; hints: Pocket['hints'] } | null, what: string): Suggestion | null;
    sameReceipt<T extends { amount_minor: number | null; currency: string; date: string }>(
        candidate: { amount_minor: number; currency: string; date: string; client_key?: string },
        records: T[],
    ): T | null;
    canonical(value: unknown): string;
    addDays(date: string, n: number): string;
    daysBetween(a: string, b: string): number;
    london(ms: number): { date: string; time: string };
    taxYear(date: string): string;
    uuid(): string;
    sha256(data: string | ArrayBuffer): Promise<string>;
    list(): Promise<LocalEntry[]>;
    get(key: string): Promise<LocalEntry | null>;
    put(entry: LocalEntry): Promise<LocalEntry>;
    update(key: string, fn: (e: LocalEntry) => LocalEntry | null): Promise<LocalEntry | null>;
    remove(key: string): Promise<boolean>;
    getMeta<T = unknown>(key: string): Promise<T | null>;
    setMeta(key: string, value: unknown): Promise<unknown>;
    getPocket(): Promise<{ data: unknown; etag: string | null; fetched_at: number } | null>;
    setPocket(value: unknown): Promise<unknown>;
    addShared(item: Omit<SharedItem, 'id'>): Promise<number>;
    listShared(): Promise<SharedItem[]>;
    removeShared(id: number): Promise<boolean>;
    counts(): Promise<Counts>;
    notify(): void;
    flush(opts?: { reset?: boolean; only?: string; from?: string; pocket?: boolean }): Promise<FlushResult>;
    checkSignIn(): Promise<'signed_in' | 'signed_out' | 'offline'>;
    refreshPocket(o?: { force?: boolean }): Promise<{ data: unknown; etag: string | null; fetched_at: number } | null>;
    withdraw(key: string, opts?: { wait?: boolean }): Promise<'deleted' | 'pending' | 'gone' | 'withdrawn' | 'kept'>;
    keepWithoutPhoto(key: string): Promise<boolean>;
    registerSync(): Promise<boolean>;
    tidy(): Promise<{ remove: string[]; askShared: number[] }>;
}

declare global {
    interface Window {
        ExpendQuick?: ExpendQuickApi;
        /** The address the page was opened with, copied before Inertia rewrites it (app.blade.php). */
        __quickLaunch?: { path: string; search: string; hash: string };
    }
}

/** The store, once quick-store.js has loaded (it's a deferred script that runs before the app). */
export function store(): ExpendQuickApi | null {
    return typeof window !== 'undefined' && window.ExpendQuick ? window.ExpendQuick : null;
}

export function whenStore(timeoutMs = 5000): Promise<ExpendQuickApi | null> {
    const now = store();
    if (now) return Promise.resolve(now);
    return new Promise((resolve) => {
        const started = Date.now();
        const tick = () => {
            const s = store();
            if (s || Date.now() - started > timeoutMs) resolve(s);
            else setTimeout(tick, 50);
        };
        tick();
    });
}

// ------------------------------------------------------------------ the pocket

export interface PocketJob {
    id: number;
    title: string;
    client?: string | null;
    venue?: string | null;
    days: string[];
}

export interface PocketCategory {
    id: number;
    code: string;
    name: string;
    job_cost: boolean;
    group?: string | null;
}

export interface PocketChip {
    label: string;
    category_id: number | null;
    code?: string | null;
}

export interface PocketAccount {
    id: number;
    name: string;
    kind?: string | null;
    last_line_on?: string | null;
}

/** A record the same-receipt check compares with: the server's entries, Telegram drafts, hand-added expenses. */
export interface PocketRecord {
    client_key?: string | null;
    amount_minor: number | null;
    currency: string;
    date: string;
    at?: string | number | null;
    what?: string | null;
    state?: string | null;
    source?: string | null;
}

/** One of the last entries Expend has, for Recent. */
export interface PocketEntry {
    id: number;
    client_key: string | null;
    status: string;
    words: string;
    amount_minor: number | null;
    currency: string;
    what: string | null;
    paid_with: string | null;
    date: string | null;
    at: string | null;
    job: string | null;
    /** What happened, in Expend's words. */
    result: string | null;
    href: string | null;
}

export interface Pocket {
    today: string | null;
    locked_years: string[];
    filed_years: string[];
    open_from: string | null;
    accounts: PocketAccount[];
    categories: PocketCategory[];
    chips: PocketChip[];
    recent_whats: { what: string; category_id: number | null; count: number }[];
    hints: { words: string[]; category_id: number | null; code: string }[];
    jobs: PocketJob[];
    /** HMRC monthly rates: currency → {'YYYY-MM': units per £1}. */
    rates: Record<string, Record<string, number>>;
    recent: PocketRecord[];
    entries: PocketEntry[];
    simplified_mileage: boolean;
    max_upload_bytes: number | null;
    telegram_bot: string | null;
}

export const EMPTY_POCKET: Pocket = {
    today: null,
    locked_years: [],
    filed_years: [],
    open_from: null,
    accounts: [],
    categories: [],
    chips: [],
    recent_whats: [],
    hints: [],
    jobs: [],
    rates: {},
    recent: [],
    entries: [],
    simplified_mileage: false,
    max_upload_bytes: null,
    telegram_bot: null,
};

/** The six chips before there's any history (QuickHints::DEFAULT_CHIPS). */
export const DEFAULT_CHIPS: { label: string; code: string }[] = [
    { label: 'Parking', code: 'travel_parking' },
    { label: 'Fuel', code: 'travel_fuel' },
    { label: 'Food', code: 'travel_subsistence' },
    { label: 'Taxi', code: 'travel_taxi' },
    { label: 'Train', code: 'travel_rail' },
    { label: 'Kit', code: 'materials' },
];

type Loose = Record<string, unknown>;

const arr = (v: unknown): Loose[] => (Array.isArray(v) ? (v as Loose[]) : []);
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : typeof v === 'number' ? String(v) : null);
const num = (v: unknown): number | null =>
    typeof v === 'number' && isFinite(v) ? v : typeof v === 'string' && v !== '' && !isNaN(Number(v)) ? Number(v) : null;
const labels = (v: unknown): string[] =>
    arr(v)
        .map((y) => (typeof y === 'string' ? y : (str(y.label) ?? str(y.year) ?? '')))
        .filter((s) => s !== '');

/** Take the pocket as the server sends it (GET /quick/pocket), filling anything missing, so the screen never breaks. */
export function normalisePocket(raw: unknown): Pocket {
    if (!raw || typeof raw !== 'object') return EMPTY_POCKET;
    const p = raw as Loose;
    // A category with children (a box on the return, "Car, van and travel expenses") heads its group; the rest are listed under it.
    const rawCategories = arr(p.categories);
    const names = new Map(rawCategories.map((c) => [Number(c.id), str(c.name) ?? '']));
    const categories: PocketCategory[] = rawCategories
        .filter((c) => !c.has_children)
        .map((c) => ({
            id: Number(c.id),
            code: str(c.code) ?? '',
            name: str(c.name) ?? '',
            job_cost: Boolean(c.job_cost ?? c.is_job_cost ?? false),
            group: str(c.group) ?? (c.parent_id ? (names.get(Number(c.parent_id)) ?? null) : null),
        }));
    const byCode = new Map(categories.map((c) => [c.code, c]));
    const rates: Pocket['rates'] = {};
    const rawRates = p.rates;
    if (Array.isArray(rawRates)) {
        for (const r of rawRates as Loose[]) {
            const cur = str(r.currency);
            const month = str(r.month) ?? str(r.period);
            const rate = num(r.units_per_gbp ?? r.rate);
            if (cur && month && rate) (rates[cur] ??= {})[month.slice(0, 7)] = rate;
        }
    } else if (rawRates && typeof rawRates === 'object') {
        // Either month → currency → rate (as the server sends it) or currency → month → rate.
        for (const [outer, inner] of Object.entries(rawRates as Loose)) {
            if (!inner || typeof inner !== 'object') continue;
            const byMonth = /^\d{4}-\d{2}/.test(outer);
            for (const [key, rate] of Object.entries(inner as Loose)) {
                const n = num(rate);
                if (!n) continue;
                const [cur, month] = byMonth ? [key, outer] : [outer, key];
                (rates[cur] ??= {})[month.slice(0, 7)] = n;
            }
        }
    }
    const chips: PocketChip[] = arr(p.chips)
        .map((c) => {
            const code = str(c.code);
            const id = num(c.category_id ?? c.id) ?? (code ? (byCode.get(code)?.id ?? null) : null);
            return { label: str(c.label) ?? str(c.name) ?? '', category_id: id, code };
        })
        .filter((c) => c.label !== '');
    return {
        today: str(p.today),
        locked_years: labels(p.locked_years),
        filed_years: labels(p.filed_years),
        open_from: str(p.open_from) ?? str(p.earliest_open),
        accounts: arr(p.accounts).map((a) => ({
            id: Number(a.id),
            name: str(a.name) ?? '',
            kind: str(a.kind),
            last_line_on: str(a.latest_line_on ?? a.last_line_on),
        })),
        categories,
        chips: chips.length ? chips : DEFAULT_CHIPS.map((c) => ({ label: c.label, code: c.code, category_id: byCode.get(c.code)?.id ?? null })),
        recent_whats: arr(p.recent_whats).map((r) => ({ what: str(r.what) ?? '', category_id: num(r.category_id), count: num(r.count ?? r.n) ?? 1 })),
        hints: arr(p.hints).map((h) => ({
            words: Array.isArray(h.words) ? (h.words as string[]) : h.word ? [String(h.word)] : [],
            category_id: num(h.category_id),
            code: str(h.code) ?? '',
        })),
        jobs: arr(p.jobs).map((j) => ({
            id: Number(j.id),
            title: str(j.title) ?? str(j.name) ?? 'Job',
            client: str(j.client),
            venue: str(j.venue) ?? str(j.location),
            days: Array.isArray(j.days) ? (j.days as string[]) : Array.isArray(j.dates) ? (j.dates as string[]) : [],
        })),
        rates,
        recent: arr(p.recent).map((r) => ({
            client_key: str(r.client_key),
            amount_minor: num(r.amount_minor),
            currency: str(r.currency) ?? 'GBP',
            date: str(r.date) ?? str(r.spent_on) ?? '',
            at: str(r.at) ?? str(r.captured_at) ?? str(r.created_at),
            what: str(r.what) ?? str(r.words),
            state: str(r.state) ?? str(r.status),
            source: str(r.source) ?? str(r.kind) ?? str(r.channel),
        })),
        entries: arr(p.entries).map((e) => ({
            id: Number(e.id),
            client_key: str(e.client_key),
            status: str(e.status) ?? str(e.state) ?? '',
            words: str(e.words) ?? '',
            amount_minor: num(e.amount_minor),
            currency: str(e.currency) ?? 'GBP',
            what: str(e.what),
            paid_with: str(e.paid_with),
            date: str(e.date) ?? str(e.spent_on),
            at: str(e.at) ?? str(e.captured_at) ?? str(e.created_at) ?? (str(e.spent_on) ? `${str(e.spent_on)}T12:00:00Z` : null),
            job: str(e.job),
            result: str(e.message) ?? str(e.result) ?? str(e.state_words),
            href: str(e.href),
        })),
        simplified_mileage: Boolean(p.simplified_mileage ?? p.mileage_simplified ?? false),
        max_upload_bytes: num(p.max_upload_bytes),
        telegram_bot: str(p.telegram_bot) ?? str(p.telegram_bot_username),
    };
}

/** Load the kept pocket. */
export async function readPocket(): Promise<{ pocket: Pocket; fetched_at: number } | null> {
    const s = store();
    if (!s) return null;
    try {
        const kept = await s.getPocket();
        return kept ? { pocket: normalisePocket(kept.data), fetched_at: kept.fetched_at } : null;
    } catch {
        return null;
    }
}

// ------------------------------------------------------------------ counts

const EMPTY_COUNTS: Counts = { waiting: 0, needs_you: 0, shared: 0, signed_out: false, total: 0 };

/** The waiting and needs-you counts, kept up to date across pages, tabs and the worker. */
export function useQuickCounts(): Counts {
    const [counts, setCounts] = useState<Counts>(EMPTY_COUNTS);
    useEffect(() => {
        let alive = true;
        let channel: BroadcastChannel | null = null;
        const read = () => {
            whenStore().then((s) => {
                if (!s) return;
                s.counts()
                    .then((c) => {
                        if (alive) setCounts(c);
                    })
                    .catch(() => {});
            });
        };
        read();
        window.addEventListener('expend-quick', read);
        document.addEventListener('visibilitychange', read);
        try {
            channel = new BroadcastChannel('expend-quick');
            channel.onmessage = read;
        } catch {
            channel = null;
        }
        return () => {
            alive = false;
            window.removeEventListener('expend-quick', read);
            document.removeEventListener('visibilitychange', read);
            channel?.close();
        };
    }, []);
    return counts;
}

/** "2 waiting · 1 needs you", or null when nothing waits. */
export function countsWords(c: Counts): { waiting: string | null; needs: string | null } | null {
    const waiting = c.waiting + c.shared;
    if (waiting === 0 && c.needs_you === 0) return null;
    return {
        waiting: waiting > 0 ? (c.signed_out ? `Sign in to send ${waiting}` : `${waiting} waiting`) : null,
        needs: c.needs_you > 0 ? `${c.needs_you} needs you` : null,
    };
}

// ------------------------------------------------------------------ the phone

export function isIos(): boolean {
    if (typeof navigator === 'undefined') return false;
    const ua = navigator.userAgent;
    return /iPhone|iPad|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1);
}

export function isAndroid(): boolean {
    return typeof navigator !== 'undefined' && /Android/.test(navigator.userAgent);
}

/** Opened from the home screen (the standalone app), not a browser tab. */
export function isStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    const nav = navigator as Navigator & { standalone?: boolean };
    return nav.standalone === true || window.matchMedia?.('(display-mode: standalone)').matches === true;
}

/**
 * The launch address's parameters (?job=14, ?source=home, ?share_failed=1): the page load's (read once before Inertia
 * rewrites it), and the current address's. A launch that had to sign in first loaded /login; the login then came back
 * to the launch address by Inertia, with no fresh page load, so only the current address still has them.
 */
export function launchParams(): URLSearchParams {
    const launch = typeof window !== 'undefined' ? window.__quickLaunch : undefined;
    const out = new URLSearchParams(launch?.search ?? '');
    if (typeof location !== 'undefined') {
        new URLSearchParams(location.search).forEach((value, key) => {
            if (!out.has(key)) out.set(key, value);
        });
    }
    return out;
}

/** Today's date and the time now in London. */
export function londonNow(now = Date.now()): { date: string; time: string } {
    const s = store();
    if (s) return s.london(now);
    const parts: Record<string, string> = {};
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/London',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    })
        .formatToParts(new Date(now))
        .forEach((p) => {
            parts[p.type] = p.value;
        });
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

/** The UK tax year of a date: '2025-26'. */
export function taxYearOf(date: string): string {
    const y = parseInt(date.slice(0, 4), 10);
    const start = date.slice(5) >= '04-06' ? y : y - 1;
    return `${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

/**
 * Before signing out (user-menu-content.tsx): how many entries still wait on this phone, so the menu can ask
 * "Send them first, Delete them, or Keep them".
 */
export async function waitingOnPhone(): Promise<number> {
    const s = await whenStore(1000);
    if (!s) return 0;
    const c = await s.counts().catch(() => EMPTY_COUNTS);
    return c.waiting + c.needs_you + c.shared;
}

/**
 * Sign out's clean-up: forget the day's data and the cached screen. With deleteWaiting ("Delete them"), entries that
 * never left the phone and shared files are deleted; anything that may already be in Expend is marked to be withdrawn
 * there at the next sign-in, never just forgotten. Otherwise ("Keep them") they stay and send after the next sign-in.
 */
export async function clearOnSignOut(deleteWaiting = false): Promise<void> {
    const s = await whenStore(1000);
    if (s) {
        await s.setPocket(null).catch(() => {});
        await s.setMeta('current', null).catch(() => {});
        await s.setMeta('current_photo', null).catch(() => {});
        if (deleteWaiting) {
            for (const e of await s.list().catch(() => [] as LocalEntry[])) {
                if (e.state !== 'sent') await s.withdraw(e.client_key).catch(() => 'gone');
            }
            for (const x of await s.listShared().catch(() => [] as SharedItem[])) await s.removeShared(x.id).catch(() => {});
        }
    }
    try {
        navigator.serviceWorker?.controller?.postMessage({ type: 'clear' });
    } catch {
        // No worker.
    }
}
