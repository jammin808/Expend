import {
    count,
    datesFrom,
    drop,
    emptySelection,
    fill,
    fillOffer,
    setOverride as overrideIn,
    pickEntry as pickEntryIn,
    pickedDates,
    plural,
    restore,
    serialise,
    toggle,
    unpickEntry as unpickEntryIn,
    type FillOffer,
    type PickEntry,
    type Selection,
} from '@/lib/day-pick';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** Where the calendar page keeps its pick, and where a job's page keeps one for that job. */
export const CALENDAR_KEY = 'expend.day-pick.v1';
export const jobKey = (id: number) => `expend.day-pick.job.${id}.v1`;

function read<D>(key: string) {
    try {
        return restore<D>(window.localStorage.getItem(key), Date.now());
    } catch {
        return null; // a private window or blocked storage: start empty
    }
}

function write(key: string, value: string | null) {
    try {
        if (value === null) window.localStorage.removeItem(key);
        else window.localStorage.setItem(key, value);
    } catch {
        // Storage full or blocked: the pick still works, it just isn't kept past a reload.
    }
}

export interface DayPick<D> {
    sel: Selection;
    dates: string[];
    draft: D | null;
    offer: FillOffer | null;
    /** Kept from earlier (a reload, or the app reopened): asked about until the next tap. */
    restored: boolean;
    /** What the last Clear or Fill can put back, until the next tap. */
    undo: { label: string } | null;
    /** A one-line message for the bar: news (an entry's days picked), or something refused (120 days at most). */
    notice: { text: string; refused: boolean } | null;
    tap: (date: string) => void;
    /** Adding to a job: one of its own days, so a small-hours entry after it can go on that evening. */
    addDay: (date: string) => void;
    range: (from: string, to: string) => void;
    drag: (from: string, to: string, mode: 'pick' | 'drop') => void;
    doFill: () => void;
    pickEntry: (entry: PickEntry & { summary?: string | null }) => void;
    /** Start again with just this entry's days (Clear can still be undone). */
    startWith: (entry: PickEntry & { summary?: string | null }) => void;
    unpickEntry: (key: string) => void;
    setOverride: (key: string, value: 'in' | 'out' | null) => void;
    clear: () => void;
    undoLast: () => void;
    keep: () => void;
    setToken: (token: string | null) => void;
    setDraft: (draft: D | null | ((d: D | null) => D | null)) => void;
    /** The job was saved: forget the pick and its draft. */
    finished: () => void;
}

/**
 * The days you've picked, the entries you ticked or unticked, the pick's token and the review's draft, kept in this
 * browser for a day (so a reload, an app switch or iOS reopening the app at a venue keeps them) until the job is saved.
 */
const NO_DAYS: ReadonlySet<string> = new Set();

/**
 * $own: adding to a job, its own days. They're never picked by a fill, a range, a drag or an entry's circle (they're
 * its days already), and a gap they fill isn't offered as one.
 */
export function useDayPick<D = unknown>(storageKey: string, own: ReadonlySet<string> = NO_DAYS): DayPick<D> {
    const first = useRef(typeof window === 'undefined' ? null : read<D>(storageKey));
    const [sel, setSel] = useState<Selection>(() => first.current?.sel ?? emptySelection());
    const [draft, setDraftState] = useState<D | null>(() => first.current?.draft ?? null);
    const [restored, setRestored] = useState(() => !!first.current && count(first.current.sel) > 0);
    const [undo, setUndo] = useState<{ label: string; sel: Selection; draft: D | null } | null>(null);
    const [notice, setNoticeState] = useState<{ text: string; refused: boolean } | null>(null);
    const setNotice = (text: string | null, refused = true) => setNoticeState(text === null ? null : { text, refused });

    useEffect(() => {
        const empty = count(sel) === 0 && !draft;
        write(storageKey, empty ? null : serialise(sel, draft, Date.now()));
    }, [storageKey, sel, draft]);

    /** A new tap: whatever the bar was saying about the last one goes. */
    const fresh = () => {
        setUndo(null);
        setRestored(false);
        setNotice(null);
    };

    const tap = useCallback(
        (date: string) => {
            fresh();
            const r = toggle(sel, date);
            if (r.did === 'refused') setNotice('120 days is the most at once.');
            setSel(r.sel);
        },
        [sel],
    );

    const addDay = useCallback((date: string) => {
        setSel((s) => (s.dates[date] ? s : fill(s, date, date, 'fold')));
    }, []);

    const range = useCallback(
        (from: string, to: string) => {
            fresh();
            const wanted = datesFrom(from < to ? from : to, from < to ? to : from).filter((d) => !sel.dates[d] && !own.has(d)).length;
            const next = fill(sel, from, to, 'fill', own);
            if (count(next) - count(sel) < wanted) setNotice('120 days is the most at once.');
            setSel({ ...next, prev: sel.last, last: to });
        },
        [sel, own],
    );

    const drag = useCallback(
        (from: string, to: string, mode: 'pick' | 'drop') => {
            fresh();
            setSel((s) => (mode === 'pick' ? fill(s, from, to, 'tap', own) : drop(s, from, to)));
        },
        [own],
    );

    const offer = useMemo(() => fillOffer(sel, own), [sel, own]);

    const doFill = useCallback(() => {
        if (!offer) return;
        setUndo({ label: `Filled ${plural(offer.count, 'day')}`, sel, draft });
        setRestored(false);
        setNotice(null);
        setSel((s) => ({ ...fill(s, offer.from, offer.to, 'fill', own), prev: s.last, last: null }));
    }, [offer, sel, draft, own]);

    const pickEntry = useCallback(
        (entry: PickEntry & { summary?: string | null }) => {
            fresh();
            const r = pickEntryIn(sel, entry, own);
            const name = entry.summary || 'the entry';
            if (r.total > 1) {
                if (r.capped) setNotice(`${r.picked} of the ${r.total} days of ${name} picked: 120 days is the most at once.`);
                else setNotice(`All ${r.total} days of ${name} picked.`, false);
            }
            setSel(r.sel);
        },
        [sel, own],
    );

    const startWith = useCallback(
        (entry: PickEntry & { summary?: string | null }) => {
            fresh();
            const n = count(sel);
            if (n) setUndo({ label: `Cleared ${plural(n, 'day')}`, sel, draft });
            const r = pickEntryIn({ ...emptySelection(), token: sel.token }, entry, own);
            if (r.total > 1) {
                if (r.capped)
                    setNotice(`${r.picked} of the ${r.total} days of ${entry.summary || 'the entry'} picked: 120 days is the most at once.`);
                else setNotice(`All ${r.total} days of ${entry.summary || 'the entry'} picked.`, false);
            }
            setSel(r.sel);
            setDraftState(null);
        },
        [sel, draft, own],
    );

    const unpickEntry = useCallback((key: string) => {
        fresh();
        setSel((s) => unpickEntryIn(s, key));
    }, []);

    const setOverride = useCallback((key: string, value: 'in' | 'out' | null) => {
        setSel((s) => overrideIn(s, key, value));
    }, []);

    const clear = useCallback(() => {
        const n = count(sel);
        if (n === 0) return;
        setUndo({ label: `Cleared ${plural(n, 'day')}`, sel, draft });
        setRestored(false);
        setNotice(null);
        setSel((s) => ({ ...emptySelection(), token: s.token }));
        setDraftState(null);
    }, [sel, draft]);

    const undoLast = useCallback(() => {
        if (!undo) return;
        setSel(undo.sel);
        setDraftState(undo.draft);
        setUndo(null);
    }, [undo]);

    const keep = useCallback(() => setRestored(false), []);

    const setToken = useCallback((token: string | null) => {
        setSel((s) => (s.token === token ? s : { ...s, token }));
    }, []);

    const setDraft = useCallback((d: D | null | ((d: D | null) => D | null)) => {
        setDraftState((old) => (typeof d === 'function' ? (d as (d: D | null) => D | null)(old) : d));
    }, []);

    const finished = useCallback(() => {
        write(storageKey, null);
        setSel(emptySelection());
        setDraftState(null);
        setUndo(null);
        setRestored(false);
        setNotice(null);
    }, [storageKey]);

    return {
        sel,
        dates: useMemo(() => pickedDates(sel), [sel]),
        draft,
        offer,
        restored,
        undo: undo ? { label: undo.label } : null,
        notice,
        tap,
        addDay,
        range,
        drag,
        doFill,
        pickEntry,
        startWith,
        unpickEntry,
        setOverride,
        clear,
        undoLast,
        keep,
        setToken,
        setDraft,
        finished,
    };
}
