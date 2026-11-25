/**
 * Picking a job's days on a calendar: single days, runs of days and whole calendar entries, across months, kept on
 * the phone until the job is saved. Pure functions over dates written YYYY-MM-DD and worked out at UTC noon, so a
 * clock change never moves a day. No imports and only types that can be stripped, so node can run it as it is
 * (tests/Feature/CalendarPickCodeTest.php).
 */

/** The most days one pick can hold (the server refuses more). */
export const CAP = 120;
/** The longest gap the bar offers to fill. */
export const FILL_MAX = 62;
/** A job's first to last day can't be longer than this (JobDays::MAX_DAYS). */
export const MAX_SPAN = 400;
/** A saved pick is forgotten after a day. */
export const KEEP_MS = 24 * 60 * 60 * 1000;
/** A timed entry starting before this belongs to the evening before (a load-out at 00:30), as JobDays::OVERNIGHT_UNTIL. */
export const OVERNIGHT_UNTIL = '06:00';

/** No days: the default for the job's own days, when not adding to a job. */
const NONE: ReadonlySet<string> = new Set();

/** How a day got into the pick: tapped, filled in, or from an entry ("entry:<key>"). A day can have several. */
export type Origin = string;

export interface Selection {
    /** Each picked date and how it was picked. */
    dates: Record<string, Origin[]>;
    /** Entries you ticked ('in') or unticked ('out') yourself; the rest follow defaultInclude. */
    overrides: Record<string, 'in' | 'out'>;
    /** The last day you tapped, and the one before: the bar offers to fill the gap between picks from here. */
    last: string | null;
    prev: string | null;
    /** The pick's token from the server, kept until the job is saved so a retry can't make a second job. */
    token: string | null;
    savedAt: number;
}

/** What the calendar knows about an entry, as far as picking goes. */
export interface PickEntry {
    key: string;
    /** Its first and last UK day (an evening gig to 01:30 is one day). */
    date: string;
    last_day: string;
    summary?: string | null;
    all_day?: boolean;
    start_time?: string | null;
    end_time?: string | null;
    /** free: on no job and not marked; job: on a job; not_job: you said it isn't work. Preview states are finer. */
    state?: string | null;
    /** The job it's on, and who linked it there (you, or automatically): the calendar month sends these. */
    job?: { id: number } | null;
    by?: string | null;
    recurring?: boolean;
    /** Its title without day words ("rig: winter expo" is "winter expo"), lower case. */
    base?: string | null;
    /** The server's own work score (2 or more looks like work), when it sends one. */
    score?: number | null;
    /** The server's verdict, when it sends no score. */
    default_include?: boolean;
    looks_like_work?: boolean;
}

export function emptySelection(): Selection {
    return { dates: {}, overrides: {}, last: null, prev: null, token: null, savedAt: 0 };
}

// ------------------------------------------------------------------ dates

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAYS_LONG = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const at = (iso: string) => new Date(iso.slice(0, 10) + 'T12:00:00Z');
const iso = (d: Date) => d.toISOString().slice(0, 10);

export function isDate(value: unknown): value is string {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && iso(at(value)) === value;
}

export function addDays(date: string, n: number): string {
    const d = at(date);
    d.setUTCDate(d.getUTCDate() + n);
    return iso(d);
}

/** Whole days from a to b (b later is positive). */
export function daysBetween(a: string, b: string): number {
    return Math.round((at(b).getTime() - at(a).getTime()) / 86_400_000);
}

/** 0 for Monday to 6 for Sunday. */
export function weekday(date: string): number {
    return (at(date).getUTCDay() + 6) % 7;
}

/** Every date from first to last, both included (none if last is before first). */
export function datesFrom(first: string, last: string, max = MAX_SPAN): string[] {
    const out: string[] = [];
    for (let d = first; d <= last && out.length < max; d = addDays(d, 1)) out.push(d);
    return out;
}

export function monthOf(date: string): string {
    return date.slice(0, 7);
}

/** The month n months after (or before) a YYYY-MM month. */
export function shiftMonth(month: string, n: number): string {
    const [y, m] = month.split('-').map(Number);
    const total = y * 12 + (m - 1) + n;
    return `${Math.floor(total / 12)}-${String((total % 12) + 1).padStart(2, '0')}`;
}

export function monthName(month: string, withYear = true): string {
    const [y, m] = month.split('-').map(Number);
    return MONTHS_LONG[m - 1] + (withYear ? ` ${y}` : '');
}

/** "Mon 3 Nov". */
export function dayLabel(date: string): string {
    const d = at(date);
    return `${DAYS[weekday(date)]} ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`;
}

/** "Monday 3 November", for screen readers. */
export function dayLabelLong(date: string): string {
    const d = at(date);
    return `${DAYS_LONG[weekday(date)]} ${d.getUTCDate()} ${MONTHS_LONG[d.getUTCMonth()]}`;
}

export interface Cell {
    date: string;
    day: number;
    inMonth: boolean;
}

/** A month as whole weeks, Monday first: the days before the 1st and after the last belong to the months either side. */
export function monthCells(month: string): Cell[] {
    const first = `${month}-01`;
    const last = addDays(`${shiftMonth(month, 1)}-01`, -1);
    const start = addDays(first, -weekday(first));
    const end = addDays(last, 6 - weekday(last));
    return datesFrom(start, end, 42).map((date) => ({ date, day: at(date).getUTCDate(), inMonth: monthOf(date) === month }));
}

// ------------------------------------------------------------------ the pick

export function pickedDates(sel: Selection): string[] {
    return Object.keys(sel.dates).sort();
}

export function count(sel: Selection): number {
    return Object.keys(sel.dates).length;
}

/** From the first picked day to the last, counting the days between. */
export function span(dates: string[]): number {
    if (dates.length === 0) return 0;
    const sorted = [...dates].sort();
    return daysBetween(sorted[0], sorted[sorted.length - 1]) + 1;
}

function copy(sel: Selection): Selection {
    const dates: Record<string, Origin[]> = {};
    for (const [d, o] of Object.entries(sel.dates)) dates[d] = [...o];
    return { ...sel, dates, overrides: { ...sel.overrides } };
}

export interface ToggleResult {
    sel: Selection;
    /** picked, dropped, or refused: the pick already has CAP days. */
    did: 'picked' | 'dropped' | 'refused';
}

/** A tap on a day: picks it, or drops it if it's picked (however it got there). */
export function toggle(sel: Selection, date: string): ToggleResult {
    const next = copy(sel);
    if (next.dates[date]) {
        delete next.dates[date];
        next.prev = sel.last;
        next.last = date;
        return { sel: next, did: 'dropped' };
    }
    if (count(sel) >= CAP) return { sel, did: 'refused' };
    next.dates[date] = ['tap'];
    next.prev = sel.last;
    next.last = date;
    return { sel: next, did: 'picked' };
}

export interface FillOffer {
    /** The first and last date the fill adds, and how many. */
    from: string;
    to: string;
    count: number;
}

/**
 * After a tap that leaves a gap of 1 to 62 days to the nearest picked day, the days in between, offered as one
 * "Fill". The gap on the side of the tap before is chosen, else the smaller one. Nothing is offered when the fill
 * wouldn't fit in CAP days. Adding to a job, its own days count as there already: a gap they fill isn't one.
 */
export function fillOffer(sel: Selection, have: ReadonlySet<string> = NONE): FillOffer | null {
    const last = sel.last;
    if (!last || !sel.dates[last]) return null;
    const dates = [...new Set([...pickedDates(sel), ...have])].sort();
    const before = dates.filter((d) => d < last).pop();
    const after = dates.find((d) => d > last);
    const gap = (a: string, b: string): FillOffer | null => {
        const n = daysBetween(a, b) - 1;
        return n >= 1 && n <= FILL_MAX ? { from: addDays(a, 1), to: addDays(b, -1), count: n } : null;
    };
    const below = before ? gap(before, last) : null;
    const above = after ? gap(last, after) : null;
    let offer: FillOffer | null;
    if (sel.prev && sel.prev < last && below) offer = below;
    else if (sel.prev && sel.prev > last && above) offer = above;
    else if (below && above) offer = above.count < below.count ? above : below;
    else offer = below ?? above;
    return offer && count(sel) + offer.count <= CAP ? offer : null;
}

/** The days a fill adds. */
export function fillDates(offer: FillOffer | null): string[] {
    return offer ? datesFrom(offer.from, offer.to) : [];
}

/** Adds a run of days (a fill, a shift-click or a drag), as far as CAP allows; never the job's own days ($skip). */
export function fill(sel: Selection, from: string, to: string, origin: Origin = 'fill', skip: ReadonlySet<string> = NONE): Selection {
    const next = copy(sel);
    const [a, b] = from <= to ? [from, to] : [to, from];
    for (const d of datesFrom(a, b)) {
        if (next.dates[d] || skip.has(d)) continue;
        if (count(next) >= CAP) break;
        next.dates[d] = [origin];
    }
    return next;
}

/** Drops a run of days (a drag across picked days). */
export function drop(sel: Selection, from: string, to: string): Selection {
    const next = copy(sel);
    const [a, b] = from <= to ? [from, to] : [to, from];
    for (const d of datesFrom(a, b)) delete next.dates[d];
    return next;
}

export interface EntryPickResult {
    sel: Selection;
    /** Of the entry's days, how many are now picked, out of how many it has. */
    picked: number;
    total: number;
    /** Some of its days didn't fit in CAP days. */
    capped: boolean;
}

/**
 * The entry's circle: includes the entry and picks every day it covers, as far as CAP allows (never the job's own
 * days, $skip: they're its days already). An entry longer than MAX_SPAN days says how long it really is.
 */
export function pickEntry(sel: Selection, entry: PickEntry, skip: ReadonlySet<string> = NONE): EntryPickResult {
    const next = copy(sel);
    const days = datesFrom(entry.date, entry.last_day, MAX_SPAN).filter((d) => !skip.has(d));
    const total = daysBetween(entry.date, entry.last_day) + 1;
    const origin = 'entry:' + entry.key;
    let capped = days.length < total - [...skip].filter((d) => covers(entry, d)).length;
    for (const d of days) {
        if (next.dates[d]) {
            if (!next.dates[d].includes(origin)) next.dates[d].push(origin);
            continue;
        }
        if (count(next) >= CAP) {
            capped = true;
            continue;
        }
        next.dates[d] = [origin];
    }
    next.overrides[entry.key] = 'in';
    next.prev = sel.last;
    next.last = entry.date;
    return { sel: next, picked: days.filter((d) => next.dates[d]).length, total, capped };
}

/** The circle tapped again: leaves the entry out and drops only the days picked through it, not ones you tapped or filled. */
export function unpickEntry(sel: Selection, key: string): Selection {
    const next = copy(sel);
    const origin = 'entry:' + key;
    for (const [d, origins] of Object.entries(next.dates)) {
        if (!origins.includes(origin)) continue;
        const left = origins.filter((o) => o !== origin);
        if (left.length) next.dates[d] = left;
        else delete next.dates[d];
    }
    next.overrides[key] = 'out';
    return next;
}

/** Whether the entry's circle is filled: picked through its circle, and not left out since. */
export function entryPicked(sel: Selection, key: string): boolean {
    return sel.overrides[key] !== 'out' && Object.values(sel.dates).some((o) => o.includes('entry:' + key));
}

/** Ticks or unticks an entry yourself; null goes back to what Expend would tick. */
export function setOverride(sel: Selection, key: string, value: 'in' | 'out' | null): Selection {
    const next = copy(sel);
    if (value === null) delete next.overrides[key];
    else next.overrides[key] = value;
    return next;
}

// ------------------------------------------------------------------ words

/**
 * The picked days in a few words: "Mon 3–Wed 5, Fri 7 Nov (+2 more days)". Runs of days are joined with a dash, the
 * month follows the last run in it, and the year is said only when it isn't this year.
 */
export function summary(dates: string[], opts: { max?: number; year?: number } = {}): string {
    const max = opts.max ?? 3;
    const sorted = [...new Set(dates)].sort();
    if (sorted.length === 0) return '';
    const runs: [string, string][] = [];
    for (const d of sorted) {
        const run = runs[runs.length - 1];
        if (run && addDays(run[1], 1) === d) run[1] = d;
        else runs.push([d, d]);
    }
    const shown = runs.slice(0, max);
    const month = (d: string) => {
        const date = at(d);
        const y = date.getUTCFullYear();
        return MONTHS[date.getUTCMonth()] + (opts.year !== undefined && y !== opts.year ? ` ${y}` : '');
    };
    const day = (d: string) => `${DAYS[weekday(d)]} ${at(d).getUTCDate()}`;
    const parts = shown.map(([a, b], i) => {
        const next = shown[i + 1];
        const tail = !next || month(next[0]) !== month(b) ? ` ${month(b)}` : '';
        if (a === b) return day(a) + tail;
        const head = month(a) !== month(b) ? `${day(a)} ${month(a)}` : day(a);
        return `${head}–${day(b)}${tail}`;
    });
    const hidden = runs.slice(max).reduce((n, [a, b]) => n + daysBetween(a, b) + 1, 0);
    return parts.join(', ') + (hidden ? ` (+${hidden} more ${hidden === 1 ? 'day' : 'days'})` : '');
}

/** "3 days", "1 entry". */
export function plural(n: number, one: string, many = one + 's'): string {
    return `${n} ${n === 1 ? one : many}`;
}

// ------------------------------------------------------------------ entries

/** The entries that cover a date: from their first day to their last. */
export function covers(entry: PickEntry, date: string): boolean {
    return entry.date <= date && date <= entry.last_day;
}

/**
 * A short timed entry that starts in the small hours of the day after a picked day (a load-out at 00:30 to 04:00 after
 * a Friday show) belongs to that evening: the picked day before it (DayPick::foldOnto). Null when it doesn't, or the
 * day before isn't picked.
 */
export function foldTarget(entry: PickEntry, picked: Record<string, unknown> | string[]): string | null {
    if (entry.all_day || !entry.start_time || entry.start_time >= OVERNIGHT_UNTIL || entry.last_day !== entry.date) return null;
    const before = addDays(entry.date, -1);
    const has = Array.isArray(picked) ? picked.includes(before) : before in picked;
    return has ? before : null;
}

/** Whether a base title is long enough to match others by (as the server's 4-character rule). */
const usable = (base: string | null | undefined): base is string => !!base && base.length >= 4;

/**
 * Whether Expend ticks an entry for you. Only an entry on no job and not marked as not work: one that looks like
 * work (a work score of 2 or more, 3 for an occurrence of a repeating entry), or one with the same title as an entry
 * already included or linked to the job ("De-rig: Winter Expo" beside "Rig: Winter Expo").
 */
export function defaultInclude(entry: PickEntry, bases: string[] = [], ownJobId?: number): boolean {
    if (entry.state === 'job' || (entry.job && entry.state !== 'not_job')) {
        // Already on the job you're adding to; or linked automatically to another, which the review moves here for
        // you (Move ticked), so the phone ticks it too. One you linked elsewhere needs your say.
        return !!entry.job && (entry.job.id === ownJobId || (!!entry.by && entry.by !== 'you'));
    }
    if ((entry.state ?? 'free') !== 'free') return false;
    if (usable(entry.base) && bases.includes(entry.base)) return true;
    if (typeof entry.score === 'number') return entry.score >= (entry.recurring ? 3 : 2);
    if (typeof entry.default_include === 'boolean') return entry.default_include;
    return !!entry.looks_like_work && !entry.recurring;
}

/**
 * Whether an entry is ticked now: your own tick or untick first, else Expend's. An entry that only falls in the small
 * hours after a picked day (a load-out at 00:30) is offered, not ticked for you, as the server does.
 */
export function isIncluded(entry: PickEntry, sel: Selection, bases: string[] = [], ownJobId?: number): boolean {
    const own = sel.overrides[entry.key];
    if (own) return own === 'in';
    return Object.keys(sel.dates).some((d) => covers(entry, d)) && defaultInclude(entry, bases, ownJobId);
}

/**
 * The entries a pick includes, from those it touches: covering a picked day, or folding onto one. Bases are the
 * titles of what's included (and, adding to a job, of the job's own entries), so a same-title entry is ticked too.
 */
export function includedEntries<T extends PickEntry>(entries: T[], sel: Selection, jobBases: string[] = [], ownJobId?: number): T[] {
    const touched = touchedEntries(entries, sel);
    const first = touched.filter((e) => isIncluded(e, sel, jobBases, ownJobId));
    const bases = [...new Set([...jobBases, ...first.map((e) => e.base).filter(usable)])];
    return touched.filter((e) => isIncluded(e, sel, bases, ownJobId));
}

/** The entries covering a picked day or folding onto one, each once, earliest first. */
export function touchedEntries<T extends PickEntry>(entries: T[], sel: Selection): T[] {
    const dates = pickedDates(sel);
    const seen = new Set<string>();
    const out: T[] = [];
    for (const e of [...entries].sort(byStart)) {
        if (seen.has(e.key)) continue;
        if (dates.some((d) => covers(e, d)) || foldTarget(e, sel.dates)) {
            seen.add(e.key);
            out.push(e);
        }
    }
    return out;
}

/** Earliest first: by first day, all-day entries before timed ones, then by start time. */
export function byStart(a: PickEntry, b: PickEntry): number {
    const key = (e: PickEntry) => `${e.date} ${e.all_day || !e.start_time ? '00:00' : e.start_time}`;
    return key(a) < key(b) ? -1 : key(a) > key(b) ? 1 : 0;
}

/**
 * The picked day an entry is linked for: the evening it folds onto (a load-out at 00:30 after the show is that
 * evening's, even when its own day is picked too), else the first picked day it covers.
 */
export function entryOn(entry: PickEntry, sel: Selection | Record<string, unknown>): string | null {
    const dates = 'dates' in sel && typeof sel.dates === 'object' && !Array.isArray(sel.dates) ? (sel as Selection).dates : sel;
    const first = Object.keys(dates)
        .sort()
        .find((d) => covers(entry, d));
    return foldTarget(entry, dates as Record<string, unknown>) ?? first ?? null;
}

/** What kind of day a calendar title suggests, as JobDays::kindFrom. */
export function kindFrom(text: string): string {
    if (/\b(de-?rig|load[- ]?out|get[- ]?out|strike|break[- ]?down|pack[- ]?down)\b/i.test(text)) return 'derig';
    if (/\b(rig|load[- ]?in|get[- ]?in|build|set[- ]?up|install|prep)\b/i.test(text)) return 'rig';
    if (/\b(show|gig|event|performance|concert|conference|awards|festival|live)\b/i.test(text)) return 'show';
    if (/\b(travel|drive|flight|train)\b/i.test(text)) return 'travel';
    return 'work';
}

/** The later of two finish times, where a time before 06:00 is the next morning (JobDays::latest). */
export function latest(a: string | null, b: string | null): string | null {
    if (!a) return b;
    if (!b) return a;
    const key = (t: string) => (t < OVERNIGHT_UNTIL ? '1' : '0') + t;
    return key(a) >= key(b) ? a : b;
}

export interface DayDefault {
    kind: string;
    start_time: string | null;
    end_time: string | null;
}

/**
 * A picked day's kind and times from the entries included for it: the kind from the earliest entry on the day that says
 * one, the start from the earliest timed entry starting that day, and the finish from the latest one ending that day or
 * folding onto it (DayPick::dayDefaults). An entry running on past the day (22:00 to 07:00 the next morning, with the morning not picked)
 * gives no finish: 07:00 would read as before the 22:00 start.
 */
export function dayDefaults(date: string, included: PickEntry[], picked: Record<string, unknown> | string[]): DayDefault {
    // An entry folded onto the evening before (a get-in at 00:30) is that evening's only, not its own day's too.
    const on = included.filter((e) => covers(e, date) && foldTarget(e, picked) === null).sort(byStart);
    const folded = included.filter((e) => !covers(e, date) && foldTarget(e, picked) === date);
    // The first that says which day it is (a rig, a show), earliest first, as the server does (DayPick::dayDefaults).
    let kind = 'work';
    for (const e of on) {
        kind = kindFrom(e.summary ?? '');
        if (kind !== 'work') break;
    }
    const starts = on
        .filter((e) => !e.all_day && e.start_time && e.date === date)
        .map((e) => e.start_time as string)
        .sort();
    let end: string | null = null;
    for (const e of [...on, ...folded]) {
        if (e.all_day || !e.end_time) continue;
        if (e.last_day === date || folded.includes(e)) end = latest(end, e.end_time);
    }
    return { kind, start_time: starts[0] ?? null, end_time: end };
}

// ------------------------------------------------------------------ keeping it

export interface Saved<D = unknown> {
    sel: Selection;
    draft: D | null;
}

/** The pick (and the review's draft) as kept on the phone. */
export function serialise(sel: Selection, draft: unknown, now: number): string {
    return JSON.stringify({ v: 1, sel: { ...sel, savedAt: now }, draft: draft ?? null, savedAt: now });
}

/** A kept pick read back, or null when there's none, it can't be read, or it's more than a day old. */
export function restore<D = unknown>(raw: string | null | undefined, now: number): Saved<D> | null {
    if (!raw) return null;
    let data: unknown;
    try {
        data = JSON.parse(raw);
    } catch {
        return null;
    }
    if (!data || typeof data !== 'object') return null;
    const box = data as { v?: unknown; sel?: unknown; draft?: unknown; savedAt?: unknown };
    if (box.v !== 1 || typeof box.savedAt !== 'number' || now - box.savedAt > KEEP_MS || box.savedAt - now > KEEP_MS) return null;
    const s = box.sel as Partial<Selection> | undefined;
    if (!s || typeof s !== 'object' || !s.dates || typeof s.dates !== 'object') return null;
    const sel = emptySelection();
    for (const [d, origins] of Object.entries(s.dates)) {
        if (!isDate(d) || !Array.isArray(origins)) continue;
        const kept = origins.filter((o): o is string => typeof o === 'string');
        if (kept.length && count(sel) < CAP) sel.dates[d] = kept;
    }
    for (const [k, v] of Object.entries(s.overrides ?? {})) if (v === 'in' || v === 'out') sel.overrides[k] = v;
    sel.last = isDate(s.last) && sel.dates[s.last] ? s.last : null;
    sel.prev = isDate(s.prev) ? s.prev : null;
    sel.token = typeof s.token === 'string' && /^[0-9A-Za-z]{26}$/.test(s.token) ? s.token : null;
    sel.savedAt = box.savedAt;
    if (count(sel) === 0) return null;
    return { sel, draft: (box.draft ?? null) as D | null };
}
