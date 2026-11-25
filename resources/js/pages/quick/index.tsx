import { BottomSheet, Chip } from '@/components/quick/bottom-sheet';
import { RecentList, WaitingList } from '@/components/quick/entry-lists';
import { Keypad } from '@/components/quick/keypad';
import { recordHomeLaunch } from '@/components/quick/quick-pill';
import { CurrencySheet, dayWords, JobWhenSheet, MoreSheet, paidLabel, PaidWithSheet, WhatSheet } from '@/components/quick/sheets';
import QuickLayout from '@/layouts/quick-layout';
import { currencyDigits, moneyMinor } from '@/lib/format';
import {
    EMPTY_POCKET,
    isAndroid,
    isIos,
    isStandalone,
    launchParams,
    londonNow,
    normalisePocket,
    readPocket,
    store,
    taxYearOf,
    useQuickCounts,
    whenStore,
    type EntryFields,
    type LocalEntry,
    type PaidWith,
    type Pocket,
    type PocketRecord,
    type SharedItem,
} from '@/lib/quick';
import { isPdf, PDF_LIMIT, prepareFile, type PreparedFile } from '@/lib/quick-image';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Camera, ChevronDown, FileUp, RotateCcw, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/**
 * Add expense, on the phone: camera first, the amount on a keypad, what it was from a row of chips, how you paid,
 * today's job, Save. Everything is kept on the phone first (IndexedDB, through public/quick-store.js) and sent from
 * there, so the screen never waits for signal: entries wait, marked 'Waiting to send', and go by themselves.
 *
 * The day's data (jobs, categories, chips, rates) comes from the phone's copy of GET /quick/pocket, refreshed when
 * it's over 10 minutes old. The launch address (?job=14, ?source=home, ?share_failed=1) is read from
 * window.__quickLaunch, copied before Inertia rewrites the address bar.
 */

interface Props {
    /** True when this page is the cached screen (GET /quick/shell): it carries no data of its own. */
    shell?: boolean;
    /** The day's data, when the server sends it with the page. */
    pocket?: unknown;
    /** The server's upload limit (less 64 KB for the rest of the request) and the Telegram bot's name. */
    max_upload_bytes?: number;
    telegram_bot?: string | null;
    [key: string]: unknown;
}

interface Form {
    amountText: string;
    currency: string;
    categoryId: number | null;
    /** Your choice (a chip, More…, or Change): replies from the suggester no longer move it. */
    pinned: boolean;
    what: string;
    whatSource: 'typed' | 'chip' | null;
    paidWith: PaidWith | null;
    accountId: number | null;
    /** You picked the date (otherwise today, or the last save's date within 30 minutes). */
    datePick: string | null;
    /** You picked the job, or "No job" (id null); otherwise today's job is worked out. */
    jobPick: { id: number | null } | null;
    businessPct: number;
    note: string;
    sterling: string;
    photo: PreparedFile | null;
    photoAt: number | null;
    share: { id: number; index: number; total: number } | null;
    editing: string | null;
}

const EMPTY_FORM: Form = {
    amountText: '',
    currency: 'GBP',
    categoryId: null,
    pinned: false,
    what: '',
    whatSource: null,
    paidWith: null,
    accountId: null,
    datePick: null,
    jobPick: null,
    businessPct: 100,
    note: '',
    sterling: '',
    photo: null,
    photoAt: null,
    share: null,
    editing: null,
};

interface PaidMemory {
    paid_with: PaidWith;
    account_id: number | null;
    at: number;
}

interface LastSave {
    job_id: number | null;
    job_source: string | null;
    date: string;
    day: string;
    at: number;
}

interface Suggestion {
    categoryId: number | null;
    text: string;
    personal: boolean;
    forWhat: string;
}

interface Toast {
    id: number;
    text: string;
    detail?: string | null;
    tone: 'ok' | 'wait' | 'bad';
    undo?: string;
}

const SYMBOL: Record<string, string> = { GBP: '£', EUR: '€', USD: '$' };
const TWELVE_HOURS = 12 * 60 * 60 * 1000;
const PAID_LOWER: Record<PaidWith, string> = { cash: 'cash', personal_card: 'personal card', business: 'business card' };

/** "£1,250.5" as typed, with the thousands shown. */
function shownAmount(text: string, currency: string): string {
    const symbol = SYMBOL[currency] ?? currency + ' ';
    if (!text) return `${symbol}0`;
    const [whole, frac] = text.split('.');
    const grouped = Number(whole || '0').toLocaleString('en-GB');
    return symbol + grouped + (frac !== undefined ? '.' + frac : '');
}

function lowerFirst(s: string): string {
    return s ? s.charAt(0).toLowerCase() + s.slice(1) : s;
}

function useClock() {
    const [clock, setClock] = useState(() => ({ now: Date.now(), ...londonNow() }));
    const tick = useCallback(() => setClock({ now: Date.now(), ...londonNow() }), []);
    return [clock, tick] as const;
}

export default function QuickAdd(props: Props) {
    const { auth } = usePage<SharedData>().props;
    const counts = useQuickCounts();
    const [clock, tick] = useClock();
    const [pocket, setPocket] = useState<Pocket>(() => (props.pocket ? normalisePocket(props.pocket) : EMPTY_POCKET));
    const [form, setForm] = useState<Form>(EMPTY_FORM);
    const [sheet, setSheet] = useState<null | 'paid' | 'job' | 'more' | 'what' | 'currency' | 'same'>(null);
    const [entries, setEntries] = useState<LocalEntry[]>([]);
    const [shared, setShared] = useState<SharedItem[]>([]);
    const [paidMemory, setPaidMemory] = useState<PaidMemory | null>(null);
    const [paidFresh, setPaidFresh] = useState(false);
    const [lastSave, setLastSave] = useState<LastSave | null>(null);
    const [lastForeign, setLastForeign] = useState<string | null>(null);
    const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
    const [toast, setToast] = useState<Toast | null>(null);
    const [busy, setBusy] = useState(false);
    const [preparing, setPreparing] = useState(false);
    const [same, setSame] = useState<PocketRecord | null>(null);
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));
    const [ready, setReady] = useState(false);
    const [noStore, setNoStore] = useState(false);
    const [banner, setBanner] = useState<{ tone: 'warn' | 'bad'; text: string } | null>(null);
    const [installPrompt, setInstallPrompt] = useState<(Event & { prompt: () => Promise<void> }) | null>(null);
    const [tgHint, setTgHint] = useState(false);
    const [storageWarn, setStorageWarn] = useState<string | null>(null);
    const [maxUpload, setMaxUpload] = useState<number | null>(null);
    const chips = useRef<Pocket['chips'] | null>(null);
    const camera = useRef<HTMLInputElement>(null);
    const picker = useRef<HTMLInputElement>(null);
    const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const launch = useRef(launchParams());
    const restored = useRef(false);

    const today = clock.date;
    const yesterday = useMemo(() => store()?.addDays(today, -1) ?? today, [today]);
    const digits = currencyDigits(form.currency);
    const amountMinor = store()?.keypadMinor(form.amountText, digits) ?? null;
    const category = pocket.categories.find((c) => c.id === form.categoryId) ?? null;
    // The server's limit already leaves 64 KB for the rest of the request (QuickWorker::maxUploadBytes).
    const serverLimit = maxUpload ?? pocket.max_upload_bytes ?? (typeof props.max_upload_bytes === 'number' ? props.max_upload_bytes : null);
    const pdfLimit = Math.min(PDF_LIMIT, serverLimit && serverLimit > 0 ? serverLimit : PDF_LIMIT);
    // Signed out with entries waiting, or Expend said so when the screen opened (it opens from the phone's copy).
    const signedOut = counts.signed_out || !!counts.signed_out_now;

    // ------------------------------------------------------------------ loading

    const reload = useCallback(async () => {
        const s = await whenStore();
        if (!s) return;
        const [list, sharedList, kept] = await Promise.all([s.list().catch(() => []), s.listShared().catch(() => []), readPocket()]);
        setEntries(list);
        setShared(sharedList);
        if (kept) setPocket(kept.pocket);
    }, []);

    const showToast = useCallback((t: Omit<Toast, 'id'>, ms = 10000) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast({ ...t, id: Date.now() });
        toastTimer.current = setTimeout(() => setToast(null), ms);
    }, []);

    /** Take a shared file or message into the form ("1 of 3"). */
    const takeShare = useCallback(
        async (item: SharedItem, index: number, total: number, base: Form) => {
            const s = store();
            if (!s) return;
            let photo: PreparedFile | null = null;
            if (item.file) {
                setPreparing(true);
                try {
                    const blob = new File([item.file.bytes], item.file.name, { type: item.file.type });
                    photo = await prepareFile(blob, new Date(item.at), pdfLimit);
                } catch (error) {
                    setBanner({ tone: 'bad', text: error instanceof Error ? error.message : 'That file couldn’t be read.' });
                } finally {
                    setPreparing(false);
                }
            }
            // A bank's notification reads "You spent £12.50 at NCP": the amount comes after "spent".
            const text = [item.title, item.text]
                .filter(Boolean)
                .join(' ')
                .replace(/^(you|i)\s+(?=(spent|paid)\b)/i, '');
            const parsed = text
                ? s.parseText(text, londonNow().date, { digits: currencyDigits, accounts: pocket.accounts, jobs: pocket.jobs })
                : null;
            setForm({
                ...EMPTY_FORM,
                paidWith: parsed?.paid_with ?? base.paidWith,
                accountId: parsed?.paid_with ? parsed.account_id : base.accountId,
                photo,
                photoAt: photo ? item.at : null,
                amountText: parsed ? s.keypadText(parsed.amount_minor, currencyDigits(parsed.currency)) : '',
                currency: parsed?.currency ?? 'GBP',
                what: parsed?.what ?? '',
                whatSource: parsed?.what ? 'typed' : null,
                datePick: parsed?.date_word ? parsed.date : null,
                jobPick: parsed?.job_id ? { id: parsed.job_id } : null,
                note: item.url || '',
                share: { id: item.id, index, total },
            });
            if (parsed?.paid_with) setPaidFresh(true);
        },
        [pdfLimit, pocket.accounts, pocket.jobs],
    );

    /** Bring back the entry that was being filled in (kept on every change). */
    const restoreCurrent = (base: Form, current: Partial<Form> & { at?: number }, photo: (PreparedFile & { at?: number }) | null) => {
        const { at: _at, ...rest } = current;
        void _at;
        setForm({ ...base, ...rest, photo: photo && photo.bytes ? photo : null, share: null, editing: null });
        if (rest.paidWith) setPaidFresh(true);
    };

    /** After the last share is added or deleted: the unfinished entry from before it, if there is one. */
    const afterShares = async (base: Form) => {
        const s = store();
        if (!s) return;
        const [current, photo] = await Promise.all([
            s.getMeta<Partial<Form> & { at?: number }>('current').catch(() => null),
            s.getMeta<PreparedFile & { at?: number }>('current_photo').catch(() => null),
        ]);
        if (current && current.at && Date.now() - current.at < 24 * 60 * 60 * 1000) restoreCurrent(base, current, photo);
    };

    useEffect(() => {
        let alive = true;
        (async () => {
            const s = await whenStore();
            if (!alive) return;
            if (!s) {
                setNoStore(true);
                setReady(true);
                return;
            }
            const params = launch.current;
            if (params.get('source') === 'home') recordHomeLaunch();
            if (params.get('share_failed')) setBanner({ tone: 'bad', text: 'Sharing didn’t reach Expend: open Expend once, then share again.' });

            const [kept, paid, last, foreign, current, currentPhoto, token, list, sharedList, pendingShare] = await Promise.all([
                readPocket(),
                s.getMeta<PaidMemory>('paid').catch(() => null),
                s.getMeta<LastSave>('last_save').catch(() => null),
                s.getMeta<string>('last_foreign').catch(() => null),
                s.getMeta<Partial<Form> & { at?: number }>('current').catch(() => null),
                s.getMeta<PreparedFile & { at?: number }>('current_photo').catch(() => null),
                s.getMeta<{ max_upload_bytes: number | null }>('token_info').catch(() => null),
                s.list().catch(() => [] as LocalEntry[]),
                s.listShared().catch(() => [] as SharedItem[]),
                s.getMeta<number>('pending_share').catch(() => null),
            ]);
            if (!alive) return;
            if (kept) setPocket(kept.pocket);
            else if (props.pocket) await s.setPocket({ data: props.pocket, etag: null, fetched_at: 0 }).catch(() => {});
            setPaidMemory(paid);
            setLastSave(last);
            setLastForeign(foreign);
            setMaxUpload(token?.max_upload_bytes ?? null);
            setEntries(list);
            setShared(sharedList);

            const jobParam = Number(params.get('job'));
            const base: Form = { ...EMPTY_FORM, paidWith: paid?.paid_with ?? null, accountId: paid?.account_id ?? null };
            if (jobParam > 0) base.jobPick = { id: jobParam };
            const fresh = !!(current && current.at && Date.now() - current.at < 24 * 60 * 60 * 1000);
            // Something just shared comes first; an unfinished entry from before it waits and comes back after.
            const shareFirst = sharedList.length > 0 && (!fresh || (pendingShare ?? 0) > (current?.at ?? 0));
            if (shareFirst) {
                await takeShare(sharedList[0], 1, sharedList.length, base);
            } else if (fresh && current) {
                // The phone closed the page while the camera was open: bring the unfinished entry back.
                restoreCurrent(base, current, currentPhoto);
            } else {
                setForm(base);
            }
            restored.current = true;
            setReady(true);

            // Opened with signal (or back here after signing in: Inertia follows the login without a fresh page load):
            // ask Expend whether you're signed in, so "Sign in to send" is right from the start, and send what waits.
            if (navigator.onLine !== false) {
                s.checkSignIn()
                    .then((state) => (state === 'signed_in' ? s.flush({ reset: true, from: 'open' }) : null))
                    .catch(() => null);
            }

            // Tell the worker to keep this screen when it's a live, signed-in load.
            if (!props.shell && auth?.user) navigator.serviceWorker?.controller?.postMessage({ type: 'refresh-shell' });
            const refreshed = await s.refreshPocket().catch(() => null);
            if (alive && refreshed) setPocket(normalisePocket(refreshed.data));
            s.tidy().catch(() => null);
        })();
        return () => {
            alive = false;
        };
        // Once, on open.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const changed = () => void reload();
        window.addEventListener('expend-quick', changed);
        let channel: BroadcastChannel | null = null;
        try {
            channel = new BroadcastChannel('expend-quick');
            channel.onmessage = changed;
        } catch {
            channel = null;
        }
        const goOnline = () => setOnline(true);
        const goOffline = () => setOnline(false);
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
        // Back in the app: work out today and today's job again.
        const visible = () => {
            if (document.visibilityState === 'visible') {
                tick();
                void reload();
            }
        };
        document.addEventListener('visibilitychange', visible);
        const install = (e: Event) => {
            e.preventDefault();
            setInstallPrompt(e as Event & { prompt: () => Promise<void> });
        };
        window.addEventListener('beforeinstallprompt', install);
        return () => {
            window.removeEventListener('expend-quick', changed);
            channel?.close();
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
            document.removeEventListener('visibilitychange', visible);
            window.removeEventListener('beforeinstallprompt', install);
        };
    }, [reload, tick]);

    // The chips keep their order while the screen is open.
    if (chips.current === null && pocket.chips.length > 0 && pocket.categories.length > 0) {
        chips.current = pocket.chips.filter((c) => c.category_id !== null).slice(0, 6);
    }

    // On iPhone, once: share photos to the Telegram bot.
    useEffect(() => {
        if (!isIos() || !(pocket.telegram_bot ?? props.telegram_bot)) return;
        try {
            if (!localStorage.getItem('expend.tg_hint')) {
                setTgHint(true);
                localStorage.setItem('expend.tg_hint', new Date().toISOString());
            }
        } catch {
            // No storage: no hint.
        }
    }, [pocket.telegram_bot, props.telegram_bot]);

    // A soft warning when a lot waits on the phone.
    useEffect(() => {
        const waiting = entries.filter((e) => e.state !== 'sent').length;
        if (waiting >= 30) {
            setStorageWarn(`${waiting} entries are waiting on this phone. Open Expend with signal to send them.`);
            return;
        }
        navigator.storage
            ?.estimate?.()
            .then((est) => {
                setStorageWarn(
                    est.usage && est.usage > 50 * 1024 * 1024
                        ? 'Expend is keeping a lot on this phone. Open it with signal to send what’s waiting.'
                        : null,
                );
            })
            .catch(() => {});
    }, [entries]);

    // ------------------------------------------------------------------ autosave

    useEffect(() => {
        if (!restored.current) return;
        const s = store();
        if (!s) return;
        // A share in the form isn't kept here: the shared store holds it, and an unfinished entry from before waits.
        if (form.share) return;
        const t = setTimeout(() => {
            const { photo: _photo, ...rest } = form;
            void _photo;
            const empty = !form.amountText && !form.what && !form.photo && !form.categoryId && !form.note && !form.editing;
            s.setMeta('current', empty ? null : { ...rest, at: Date.now() }).catch(() => {});
        }, 300);
        return () => clearTimeout(t);
    }, [form]);

    useEffect(() => {
        if (!restored.current) return;
        const s = store();
        if (!s || form.share) return;
        s.setMeta('current_photo', form.photo ? { ...form.photo, at: Date.now() } : null).catch(() => {});
    }, [form.photo, form.share]);

    // ------------------------------------------------------------------ derived

    const carry = lastSave && lastSave.day === today && clock.now - lastSave.at < 30 * 60 * 1000 ? lastSave : null;
    const date = form.datePick ?? carry?.date ?? today;
    const choice = useMemo(() => {
        const s = store();
        if (!s) return null;
        return s.defaultJob(pocket, {
            date,
            today,
            time: clock.time,
            now: clock.now,
            carry: carry && (!form.datePick || form.datePick === carry.date) ? carry : null,
            jobCost: category ? category.job_cost : null,
        });
    }, [pocket, date, today, clock, carry, form.datePick, category]);
    const jobId = form.jobPick ? form.jobPick.id : (choice?.job_id ?? null);
    const jobSource: EntryFields['job_source'] = form.jobPick
        ? form.jobPick.id
            ? 'you'
            : null
        : ((choice?.source as EntryFields['job_source']) ?? null);
    const job = pocket.jobs.find((j) => j.id === jobId) ?? null;
    const unsureJob = !form.jobPick && !jobId && (choice?.on.length ?? 0) >= 2;

    const year = taxYearOf(date);
    const yearNote = pocket.locked_years.includes(year)
        ? { tone: 'error' as const, text: `${year} is locked: unlock it in Expend first` }
        : pocket.filed_years.includes(year)
          ? { tone: 'warn' as const, text: `${year} was filed: adding this means amending it` }
          : null;

    const paidStale = !paidFresh && form.paidWith !== null && paidMemory !== null && clock.now - paidMemory.at > TWELVE_HOURS;
    const foreign = form.currency !== 'GBP';

    const missing: { text: string; act: () => void } | null = !amountMinor
        ? { text: 'Enter the amount', act: () => {} }
        : !form.paidWith
          ? { text: 'Choose how you paid', act: () => setSheet('paid') }
          : form.paidWith !== 'business' && !form.categoryId
            ? { text: 'Choose what it was', act: () => setSheet('what') }
            : yearNote?.tone === 'error'
              ? { text: yearNote.text, act: () => setSheet('job') }
              : null;

    const whatWords =
        form.what ||
        (form.whatSource === 'chip' ? (chips.current?.find((c) => c.category_id === form.categoryId)?.label ?? '') : '') ||
        category?.name ||
        '';

    const estimate = useMemo(() => {
        if (!foreign || !amountMinor) return null;
        const rates = pocket.rates[form.currency];
        if (!rates) return null;
        const month = date.slice(0, 7);
        const rate = rates[month] ?? Object.entries(rates).sort((a, b) => b[0].localeCompare(a[0]))[0]?.[1];
        if (!rate) return null;
        const pence = Math.round((amountMinor / 10 ** digits / rate) * 100);
        return `about ${moneyMinor(pence, 'GBP', { signed: false })} (an estimate at HMRC’s rate)`;
    }, [foreign, amountMinor, pocket.rates, form.currency, date, digits]);

    const pounds = foreign ? null : amountMinor;
    const saveLabel = missing
        ? missing.text
        : [
              `${form.editing ? 'Save changes' : 'Save'} ${moneyMinor(amountMinor, form.currency, { signed: false })}`,
              form.paidWith
                  ? form.paidWith === 'business'
                      ? paidLabel('business', form.accountId, pocket)
                      : PAID_LOWER[form.paidWith].replace(/^./, (c) => c.toUpperCase())
                  : null,
              job?.title ?? null,
              date !== today ? dayWords(date, today, yesterday) : null,
          ]
              .filter(Boolean)
              .join(' · ');

    // ------------------------------------------------------------------ suggestions

    useEffect(() => {
        const text = form.whatSource === 'typed' ? form.what.trim() : '';
        if (!text || form.pinned) {
            if (!form.pinned) setSuggestion(null);
            return;
        }
        let stale = false;
        const offline = () => {
            const s = store();
            const hit = s?.suggestOffline(pocket, text);
            if (!hit || !hit.category_id) return null;
            const name = pocket.categories.find((c) => c.id === hit.category_id)?.name ?? '';
            return {
                categoryId: hit.category_id,
                text:
                    hit.source === 'recent' && hit.count > 1
                        ? `${name} · suggested: you chose this for ${hit.what} ${hit.count} times`
                        : `${name} · suggested`,
                personal: false,
                forWhat: text,
            };
        };
        const apply = (s: Suggestion | null) => {
            if (stale) return;
            setSuggestion(s);
            setForm((f) => (f.pinned || f.what.trim() !== text ? f : { ...f, categoryId: s && !s.personal ? s.categoryId : null }));
        };
        const t = setTimeout(async () => {
            if (!navigator.onLine) {
                apply(offline());
                return;
            }
            const ctl = new AbortController();
            const stop = setTimeout(() => ctl.abort(), 5000);
            try {
                const q = new URLSearchParams({ what: text, amount: form.amountText || '0', currency: form.currency, date });
                const res = await fetch(`/quick/suggest?${q}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: ctl.signal,
                });
                if (!res.ok) {
                    apply(offline());
                    return;
                }
                // {category_id, category_name, reason, source, job_id, job_title, personal_note}
                const sug = await res.json();
                if (!sug || (!sug.category_id && !sug.personal_note)) {
                    apply(offline());
                    return;
                }
                const name = pocket.categories.find((c) => c.id === Number(sug.category_id))?.name ?? sug.category_name ?? '';
                apply(
                    sug.personal_note
                        ? { categoryId: null, text: String(sug.personal_note), personal: true, forWhat: text }
                        : {
                              categoryId: Number(sug.category_id),
                              text: `${name} · suggested${sug.reason ? `: ${lowerFirst(String(sug.reason))}` : ''}`,
                              personal: false,
                              forWhat: text,
                          },
                );
            } catch {
                apply(offline());
            } finally {
                clearTimeout(stop);
            }
        }, 400);
        return () => {
            stale = true;
            clearTimeout(t);
        };
        // A reply is for the words it was asked about.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.what, form.whatSource, form.pinned]);

    // ------------------------------------------------------------------ actions

    const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }));

    const onPhoto = async (file: File | undefined) => {
        if (!file) return;
        setPreparing(true);
        setBanner(null);
        try {
            const at = Date.now();
            const photo = await prepareFile(file, new Date(at), pdfLimit);
            set({ photo, photoAt: at });
        } catch (error) {
            setBanner({ tone: 'bad', text: error instanceof Error ? error.message : 'That file couldn’t be read.' });
        } finally {
            setPreparing(false);
        }
    };

    const pickChip = (chip: { label: string; category_id: number | null }) => {
        if (form.pinned && form.categoryId === chip.category_id) {
            set({
                categoryId: null,
                pinned: false,
                whatSource: form.whatSource === 'chip' ? null : form.whatSource,
                what: form.whatSource === 'chip' ? '' : form.what,
            });
            return;
        }
        set({
            categoryId: chip.category_id,
            pinned: true,
            ...(form.whatSource === 'typed' && form.what ? {} : { what: chip.label, whatSource: 'chip' as const }),
        });
    };

    const nextShared = async (after: Form) => {
        const s = store();
        if (!s) return;
        const rest = await s.listShared().catch(() => [] as SharedItem[]);
        setShared(rest);
        if (rest.length > 0) await takeShare(rest[0], (form.share?.index ?? 0) + 1, (form.share?.index ?? 0) + rest.length, after);
        else await afterShares(after);
    };

    const save = async (force = false) => {
        if (busy) return;
        if (missing) {
            missing.act();
            return;
        }
        const s = store();
        if (!s) {
            setBanner({ tone: 'bad', text: 'This browser can’t keep entries on the phone. Open Expend from your home screen.' });
            return;
        }
        const nowMs = Date.now();
        const ldn = londonNow(nowMs);
        const existing = form.editing ? await s.get(form.editing).catch(() => null) : null;
        if (!force) {
            const records = [
                ...entries
                    .filter((e) => e.client_key !== form.editing)
                    .map((e) => ({
                        client_key: e.client_key,
                        amount_minor: e.summary.amount_minor,
                        currency: e.summary.currency,
                        date: e.summary.date,
                        at: e.saved_at,
                        what: e.summary.what,
                        state: e.state,
                    })),
                ...pocket.recent,
            ];
            const hit = s.sameReceipt(
                { amount_minor: amountMinor as number, currency: form.currency, date, client_key: form.editing ?? undefined },
                records,
            );
            if (hit) {
                setSame(hit);
                setSheet('same');
                return;
            }
        }
        setBusy(true);
        const key = form.editing ?? s.uuid();
        const fields: EntryFields = {
            channel: form.share ? 'share' : ((existing?.fields.channel ?? 'phone') as EntryFields['channel']),
            paid_with: form.paidWith as PaidWith,
            account_id: form.paidWith === 'business' ? form.accountId : null,
            spent_on: date,
            spent_at: date === ldn.date ? (form.photoAt ? londonNow(form.photoAt).time : ldn.time) : null,
            captured_at: new Date(form.photoAt ?? existing?.created_at ?? nowMs).toISOString(),
            amount: s.keypadAmount(form.amountText),
            currency: form.currency,
            sterling: foreign && form.sterling ? form.sterling : null,
            what: whatWords || null,
            what_source: form.what || form.whatSource === 'chip' ? form.whatSource : null,
            category_id: form.categoryId,
            work_job_id: jobId,
            job_source: jobId ? jobSource : null,
            business_pct: form.businessPct,
            note: form.note.trim() || null,
            photo: form.photo
                ? { sha256: form.photo.sha256, size: form.photo.size, mime: form.photo.type, name: form.photo.name, original: form.photo.original }
                : null,
        };
        const entry: LocalEntry = {
            client_key: key,
            created_at: existing?.created_at ?? nowMs,
            saved_at: nowMs,
            state: 'waiting',
            fields,
            payload_sha: await s.sha256(s.canonical(fields)),
            photo: form.photo
                ? {
                      bytes: form.photo.bytes,
                      type: form.photo.type,
                      sha256: form.photo.sha256,
                      size: form.photo.size,
                      name: form.photo.name,
                      original: form.photo.original,
                  }
                : null,
            thumb: form.photo?.thumb ?? null,
            summary: {
                amount_minor: amountMinor,
                currency: form.currency,
                what: whatWords,
                paid: paidLabel(form.paidWith, form.accountId, pocket),
                job: job?.title ?? null,
                date,
                category: category?.name ?? null,
            },
            attempts: 0,
            tries: existing?.tries ?? 0,
            next_at: 0,
            last_error: null,
        };
        try {
            await s.put(entry);
        } catch (error) {
            setBusy(false);
            const full = error instanceof DOMException && (error.name === 'QuotaExceededError' || error.name === 'UnknownError');
            setBanner({
                tone: 'bad',
                text: full ? 'Your phone’s storage is full: this wasn’t saved.' : 'This wasn’t saved on the phone. Try again.',
            });
            return;
        }

        // Remember how you paid, and the job and day for the next entry (kept for 30 minutes on the same day).
        const paid = { paid_with: form.paidWith as PaidWith, account_id: form.accountId, at: nowMs };
        const last = { job_id: jobId, job_source: jobSource, date, day: ldn.date, at: nowMs };
        setPaidMemory(paid);
        setLastSave(last);
        void s.setMeta('paid', paid).catch(() => {});
        void s.setMeta('last_save', last).catch(() => {});
        if (foreign) {
            setLastForeign(form.currency);
            void s.setMeta('last_foreign', form.currency).catch(() => {});
        }
        if (!form.share) {
            void s.setMeta('current', null).catch(() => {});
            void s.setMeta('current_photo', null).catch(() => {});
        }
        if (!(await s.getMeta('persist_asked').catch(() => true))) {
            navigator.storage?.persist?.().catch(() => false);
            void s.setMeta('persist_asked', true).catch(() => {});
        }
        const wasShare = form.share;
        if (wasShare) await s.removeShared(wasShare.id).catch(() => {});
        const after: Form = { ...EMPTY_FORM, paidWith: form.paidWith, accountId: form.accountId };
        setForm(after);
        setSuggestion(null);
        setPaidFresh(true);
        setBusy(false);
        tick();
        showToast({ text: 'Saved. Sending…', tone: 'wait', undo: key });
        if (wasShare) void nextShared(after);

        void s.registerSync();
        const result = await s.flush({ reset: true, from: 'save' }).catch(() => null);
        const now = await s.get(key).catch(() => null);
        await reload();
        if (!now || now.state === 'withdraw_pending' || now.state === 'withdrawn') return;
        if (now.state === 'sent' || now.state === 'photo_pending') {
            const added = now.server_state === 'added';
            // Not added (the same photo as another entry, or kept for you to check): said so, in amber.
            const notAdded = now.server_state === 'duplicate' || now.server_state === 'held';
            showToast({
                text: added
                    ? `Sent: added ${moneyMinor(now.summary.amount_minor, now.summary.currency, { signed: false })} ${now.fields.what_source === 'typed' ? now.summary.what : lowerFirst(now.summary.what)} (${PAID_LOWER[now.fields.paid_with]})${now.summary.job ? ` to ${now.summary.job}` : ''}`
                    : notAdded
                      ? (now.result ?? 'Not added: check it in To do')
                      : `Sent: ${lowerFirst(now.result ?? 'Expend has it')}`,
                detail: [now.state === 'photo_pending' ? 'The photo is still sending.' : null, ...(now.warnings ?? [])].filter(Boolean).join(' '),
                tone: notAdded || (now.warnings && now.warnings.length) ? 'wait' : 'ok',
                undo: key,
            });
            return;
        }
        if (now.state === 'needs_you') {
            showToast({ text: 'Saved on this phone: it needs you', detail: now.last_error, tone: 'bad' });
            return;
        }
        const safariTab = isIos() && !isStandalone();
        showToast({
            text: result?.signedOut || now.state === 'signed_out' ? 'Saved on this phone. Sign in to send.' : 'Saved on this phone: waiting to send',
            detail: safariTab && !navigator.onLine ? 'Saved in this Safari tab only: open Expend from your home screen to keep it safe.' : null,
            tone: 'wait',
            undo: key,
        });
    };

    const undo = async (key: string) => {
        const s = store();
        if (!s) return;
        showToast({ text: 'Removing…', tone: 'wait' }, 15000);
        // Waits for Expend's answer when there's signal; never just forgets something Expend may have.
        const r = await s.withdraw(key, { wait: true }).catch(() => 'pending' as const);
        await reload();
        if (r === 'pending')
            showToast(
                { text: 'Removing… (waiting for signal)', detail: 'It’s removed from Expend as soon as the phone can reach it.', tone: 'wait' },
                6000,
            );
        else if (r === 'kept') {
            const e = await s.get(key).catch(() => null);
            showToast({ text: e?.result ?? 'Kept: that year is locked', tone: 'bad' }, 8000);
        } else showToast({ text: 'Removed', tone: 'ok' }, 4000);
    };

    const sendNow = async (key?: string) => {
        const s = store();
        if (!s) return;
        void s.registerSync();
        const r = await s.flush({ reset: true, only: key, from: 'send-now' }).catch(() => null);
        await reload();
        if (r?.signedOut) showToast({ text: 'Sign in to send', tone: 'bad' }, 5000);
        else if (r?.offline) showToast({ text: 'No signal: it’ll go by itself once there is', tone: 'wait' }, 5000);
        else if (r && r.left === 0) showToast({ text: 'All sent', tone: 'ok' }, 4000);
    };

    const edit = (e: LocalEntry) => {
        const f = e.fields;
        const chip = chips.current?.find((c) => c.category_id === f.category_id && c.label === f.what);
        setForm({
            ...EMPTY_FORM,
            amountText: f.amount ?? '',
            currency: f.currency,
            categoryId: f.category_id,
            pinned: f.category_id !== null,
            what: f.what_source === 'chip' && chip ? chip.label : (f.what ?? ''),
            whatSource: f.what_source,
            paidWith: f.paid_with,
            accountId: f.account_id,
            datePick: f.spent_on,
            jobPick: { id: f.work_job_id },
            businessPct: f.business_pct,
            note: f.note ?? '',
            sterling: f.sterling ?? '',
            photo:
                e.photo && e.photo.bytes
                    ? {
                          bytes: e.photo.bytes,
                          type: e.photo.type,
                          size: e.photo.size,
                          sha256: e.photo.sha256,
                          name: e.photo.name,
                          original: e.photo.original,
                          thumb: e.thumb,
                          pdf: isPdf({ type: e.photo.type }),
                      }
                    : null,
            photoAt: Date.parse(f.captured_at) || null,
            editing: e.client_key,
        });
        setPaidFresh(true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const deleteShared = async () => {
        const s = store();
        if (!s) return;
        for (const item of shared) await s.removeShared(item.id).catch(() => {});
        if (form.share) {
            const base = { ...EMPTY_FORM, paidWith: form.paidWith, accountId: form.accountId };
            setForm(base);
            await afterShares(base);
        }
        await reload();
    };

    // ------------------------------------------------------------------ the screen

    const waiting = entries.filter((e) => e.state !== 'sent' && e.state !== 'withdrawn');
    const oldShared = shared.filter((x) => Date.now() - x.at > 14 * 24 * 60 * 60 * 1000);
    const syncNote = isIos()
        ? 'On iPhone, waiting entries send when Expend is open.'
        : isAndroid()
          ? 'Will send in the background when Android allows; opening Expend sends straight away.'
          : null;
    const jobChoices = useMemo(() => {
        const on = choice?.on ?? [];
        const near = choice?.near ?? [];
        const ids = [...on, ...near];
        if (jobId && !ids.includes(jobId)) ids.unshift(jobId);
        const distance = (days: string[]) => Math.min(...days.map((d) => Math.abs(store()?.daysBetween(d, date) ?? 99)), 99);
        const others = pocket.jobs
            .filter((j) => !ids.includes(j.id))
            .map((j) => ({ j, d: distance(j.days) }))
            .filter((x) => x.d <= 7)
            .sort((a, b) => a.d - b.d)
            .slice(0, 6)
            .map((x) => x.j.id);
        return [...ids, ...others]
            .map((id) => pocket.jobs.find((j) => j.id === id))
            .filter((j): j is NonNullable<typeof j> => !!j)
            .map((j) => ({
                job: j,
                note: on.includes(j.id) ? `On ${lowerFirst(dayWords(date, today, yesterday))}` : near.includes(j.id) ? 'A day either side' : null,
            }));
    }, [choice, jobId, pocket.jobs, date, today, yesterday]);

    const jobButton = job
        ? `${job.title}${jobSource === 'today' && !form.jobPick ? (date === today ? ' (on today)' : ' (on that day)') : ''}`
        : unsureJob
          ? 'Which job?'
          : 'No job';
    const fuelNote =
        category?.code === 'travel_fuel' && pocket.simplified_mileage ? 'Fuel for your own car is covered by mileage; hire cars only' : null;
    const bigAmount = pounds !== null && pounds > 100000;

    return (
        <QuickLayout>
            <main className="mx-auto max-w-lg" style={{ paddingBottom: 'calc(72px + env(safe-area-inset-bottom))' }}>
                {noStore ? (
                    <p className="m-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-100">
                        This browser can’t keep entries on the phone (private browsing?). Open Expend from your home screen.
                    </p>
                ) : null}
                {signedOut ? (
                    <a
                        href="/quick?signin=1"
                        className="mx-4 mt-2 flex min-h-12 items-center rounded-lg border border-red-300 bg-red-50 px-3 text-sm font-medium text-red-800 dark:bg-red-950 dark:text-red-100"
                    >
                        {counts.waiting + counts.needs_you > 0
                            ? `Sign in to send ${counts.waiting + counts.needs_you} waiting`
                            : 'You’re signed out: sign in'}{' '}
                        · tick Remember me
                    </a>
                ) : null}
                {banner ? (
                    <div
                        className={`mx-4 mt-2 flex items-start gap-2 rounded-lg border px-3 py-2 text-sm ${banner.tone === 'bad' ? 'border-red-300 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-100' : 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'}`}
                    >
                        <p className="flex-1 py-2">{banner.text}</p>
                        <button type="button" className="min-h-12 px-2 font-medium" onClick={() => setBanner(null)}>
                            OK
                        </button>
                    </div>
                ) : null}

                {/* Photo strip: the camera first. */}
                <section className="flex h-16 items-center gap-2 px-4 pt-2" aria-label="Receipt photo">
                    <input
                        ref={camera}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="hidden"
                        onChange={(e) => {
                            void onPhoto(e.target.files?.[0]);
                            e.target.value = '';
                        }}
                    />
                    <input
                        ref={picker}
                        type="file"
                        accept="image/*,application/pdf,.pdf"
                        className="hidden"
                        onChange={(e) => {
                            void onPhoto(e.target.files?.[0]);
                            e.target.value = '';
                        }}
                    />
                    {preparing ? (
                        <p className="text-muted-foreground flex-1 text-sm">Getting the photo ready…</p>
                    ) : form.photo ? (
                        <>
                            {form.photo.thumb ? (
                                <img src={form.photo.thumb} alt="Receipt photo" className="size-14 rounded-lg border object-cover" />
                            ) : (
                                <span className="bg-muted flex size-14 items-center justify-center rounded-lg border text-xs font-medium">
                                    {form.photo.pdf ? 'PDF' : 'Photo'}
                                </span>
                            )}
                            <div className="min-w-0 flex-1 text-xs">
                                {form.share ? (
                                    <p className="font-medium">Shared {form.share.total > 1 ? `${form.share.index} of ${form.share.total}` : ''}</p>
                                ) : null}
                                <p className="text-muted-foreground truncate">
                                    {form.photo.original && !form.photo.pdf
                                        ? 'Kept as it is: take it in Expend if it won’t read'
                                        : `${Math.round(form.photo.size / 1024)} KB`}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => camera.current?.click()}
                                className="flex min-h-12 items-center gap-1 rounded-lg border px-3 text-sm font-medium"
                            >
                                <RotateCcw className="size-4" /> Retake
                            </button>
                            <button
                                type="button"
                                onClick={() => set({ photo: null, photoAt: null })}
                                className="flex min-h-12 min-w-12 items-center justify-center rounded-lg border"
                                aria-label="Remove the photo"
                            >
                                <Trash2 className="size-4" />
                            </button>
                        </>
                    ) : (
                        <>
                            <button
                                type="button"
                                onClick={() => camera.current?.click()}
                                className="bg-primary text-primary-foreground flex min-h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-xl px-3 text-sm leading-tight font-semibold"
                            >
                                <Camera className="size-5 shrink-0" /> Take a photo of the receipt
                            </button>
                            <button
                                type="button"
                                onClick={() => picker.current?.click()}
                                className="flex min-h-12 w-20 shrink-0 flex-col items-center justify-center rounded-xl border px-1 text-xs leading-tight font-medium"
                            >
                                <FileUp className="size-4" /> Photo or PDF
                            </button>
                        </>
                    )}
                </section>

                {/* What it was: the suggestion line, then the chips. */}
                <p className="text-muted-foreground flex min-h-5 items-center gap-1 px-4 pt-1 text-xs leading-5" aria-live="polite">
                    {/* What you typed stays in view (up to about half the line); the reason after it gives way. */}
                    {form.what && form.whatSource === 'typed' ? (
                        <span className="text-foreground max-w-[45%] shrink-0 truncate font-medium">“{form.what}”</span>
                    ) : null}
                    {fuelNote ? (
                        <span className="min-w-0 truncate text-amber-700 dark:text-amber-300">{fuelNote}</span>
                    ) : suggestion && !form.pinned && suggestion.forWhat === form.what.trim() ? (
                        <span className={`min-w-0 truncate ${suggestion.personal ? 'text-amber-700 dark:text-amber-300' : ''}`}>
                            {suggestion.text}
                        </span>
                    ) : form.whatSource === 'typed' && category ? (
                        <span className="truncate">{category.name}</span>
                    ) : null}
                    {(form.what && form.whatSource === 'typed') || suggestion ? (
                        <button
                            type="button"
                            onClick={() => setSheet('what')}
                            className="text-foreground relative ml-auto shrink-0 font-medium underline after:absolute after:-inset-x-2 after:-inset-y-3.5 after:content-['']"
                        >
                            Change
                        </button>
                    ) : null}
                </p>
                <div
                    className="flex gap-2 overflow-x-auto px-4 py-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    role="group"
                    aria-label="What was it?"
                >
                    {(chips.current ?? []).map((c) => (
                        <Chip
                            key={`${c.label}-${c.category_id}`}
                            selected={form.pinned && form.categoryId === c.category_id}
                            onClick={() => pickChip(c)}
                        >
                            {c.label}
                        </Chip>
                    ))}
                    <Chip
                        selected={form.pinned && !!form.categoryId && !(chips.current ?? []).some((c) => c.category_id === form.categoryId)}
                        onClick={() => setSheet('what')}
                    >
                        {form.pinned && category && !(chips.current ?? []).some((c) => c.category_id === form.categoryId)
                            ? category.name.split(/[ ,(]/)[0]
                            : 'More…'}
                    </Chip>
                </div>

                {/* Paid with · Job and when · More */}
                <div className="grid grid-cols-[1fr_1fr_auto] gap-2 px-4 pt-2">
                    <button
                        type="button"
                        onClick={() => setSheet('paid')}
                        className={`flex min-h-12 min-w-0 flex-col items-start justify-center rounded-xl border px-3 text-left ${paidStale ? 'border-amber-500 ring-1 ring-amber-500' : ''} ${!form.paidWith ? 'border-dashed' : ''}`}
                    >
                        <span className="flex w-full items-center gap-1 text-sm font-medium">
                            <span className="truncate">{paidLabel(form.paidWith, form.accountId, pocket)}</span>
                            <ChevronDown className="size-4 shrink-0" />
                        </span>
                        {paidStale ? <span className="text-xs text-amber-700 dark:text-amber-300">Check how you paid</span> : null}
                    </button>
                    <button
                        type="button"
                        onClick={() => setSheet('job')}
                        className={`flex min-h-12 min-w-0 flex-col items-start justify-center rounded-xl border px-3 text-left ${unsureJob || yearNote ? 'border-amber-500' : ''}`}
                    >
                        <span className="flex w-full items-center gap-1 text-sm font-medium">
                            <span className="truncate">{jobButton}</span>
                            <ChevronDown className="size-4 shrink-0" />
                        </span>
                        <span className={`truncate text-xs ${yearNote?.tone === 'error' ? 'text-red-600' : 'text-muted-foreground'}`}>
                            {yearNote?.tone === 'error' ? `${year} is locked` : dayWords(date, today, yesterday)}
                        </span>
                    </button>
                    <button type="button" onClick={() => setSheet('more')} className="min-h-12 rounded-xl border px-3 text-sm font-medium">
                        More{form.businessPct !== 100 || form.note ? ' •' : ''}
                    </button>
                </div>

                {/* The amount, then the keypad in the thumb zone. */}
                <div className="flex min-h-14 items-center gap-3 px-4 pt-2">
                    <button
                        type="button"
                        onClick={() => setSheet('currency')}
                        className="min-h-12 min-w-12 rounded-xl border px-3 text-base font-semibold"
                        aria-label={`Currency: ${form.currency}. Change it`}
                    >
                        {SYMBOL[form.currency] ?? form.currency}
                    </button>
                    <div className="min-w-0 flex-1 text-right">
                        <p
                            className={`truncate text-4xl leading-tight font-semibold tabular-nums ${form.amountText ? '' : 'text-muted-foreground'}`}
                            aria-live="polite"
                        >
                            {shownAmount(form.amountText, form.currency)}
                        </p>
                        {bigAmount ? <p className="text-xs text-amber-700 dark:text-amber-300">That’s a lot: check it</p> : null}
                        {estimate ? <p className="text-muted-foreground text-xs">{estimate}</p> : null}
                    </div>
                </div>
                <div className="px-4 pt-2">
                    <Keypad
                        decimals={digits}
                        onKey={(key) => {
                            if (toast) setToast(null);
                            set({ amountText: store()?.keypad(form.amountText, key, digits) ?? form.amountText });
                        }}
                    />
                </div>

                {form.editing ? (
                    <div className="px-4 pt-2">
                        <button
                            type="button"
                            className="min-h-12 w-full rounded-xl border text-sm font-medium"
                            onClick={() => setForm({ ...EMPTY_FORM, paidWith: form.paidWith, accountId: form.accountId })}
                        >
                            Stop editing (keep it as it was)
                        </button>
                    </div>
                ) : null}

                {/* Below the form: what waits on the phone, and what was sent. */}
                <WaitingList
                    entries={waiting}
                    shared={shared}
                    signedOut={signedOut}
                    onSendNow={(key) => void sendNow(key)}
                    onEdit={edit}
                    onDelete={(e) => void undo(e.client_key)}
                    onKeepWithoutPhoto={(e) => {
                        void store()
                            ?.keepWithoutPhoto(e.client_key)
                            .then(() => reload())
                            .then(() => showToast({ text: 'Kept in Expend without the photo', tone: 'ok' }, 5000));
                    }}
                    onAddShared={() => {
                        if (shared[0]) void takeShare(shared[0], 1, shared.length, form);
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }}
                    onDeleteShared={() => void deleteShared()}
                    note={waiting.length > 0 ? syncNote : null}
                />
                {oldShared.length > 0 ? (
                    <p className="text-muted-foreground px-4 pt-2 text-sm">
                        {oldShared.length} shared {oldShared.length === 1 ? 'item has' : 'items have'} waited over 14 days. Add them, or delete them
                        above.
                    </p>
                ) : null}
                <RecentList entries={entries} server={pocket.entries} />

                <div className="text-muted-foreground flex flex-col gap-2 px-4 pt-4 pb-4 text-sm">
                    {storageWarn ? <p className="text-amber-700 dark:text-amber-300">{storageWarn}</p> : null}
                    {installPrompt ? (
                        <button
                            type="button"
                            className="min-h-12 self-start rounded-xl border px-4 font-medium"
                            onClick={() => {
                                void installPrompt.prompt();
                                setInstallPrompt(null);
                            }}
                        >
                            Add to home screen
                        </button>
                    ) : null}
                    {tgHint ? (
                        <p>
                            On iPhone, share photos, PDFs and screenshots to your Telegram bot
                            {(pocket.telegram_bot ?? props.telegram_bot)
                                ? ` @${String(pocket.telegram_bot ?? props.telegram_bot).replace(/^@/, '')}`
                                : ''}
                            , or text it “£12.50 parking Leeds”.
                        </p>
                    ) : null}
                    {!online ? <p>No signal: entries are kept on this phone and sent once there is.</p> : null}
                    {!ready ? <p>Loading…</p> : null}
                </div>
            </main>

            {/* The Save bar, always in reach. */}
            <div className="bg-background fixed inset-x-0 bottom-0 z-40 border-t" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
                {/* Taps go through the toast (to the keypad's bottom row on a small phone), except on Undo; the first
                    keypad press clears it. */}
                {toast ? (
                    <div
                        role="status"
                        className={`pointer-events-none mx-auto flex max-w-lg items-start gap-2 border-b px-4 py-2 text-sm ${toast.tone === 'bad' ? 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-100' : toast.tone === 'wait' ? 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100' : 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'}`}
                    >
                        <div className="min-w-0 flex-1 py-1">
                            <p className="line-clamp-2 font-medium">{toast.text}</p>
                            {toast.detail ? <p className="line-clamp-2 text-xs">{toast.detail}</p> : null}
                        </div>
                        {toast.undo ? (
                            <button
                                type="button"
                                className="pointer-events-auto min-h-12 px-3 font-semibold underline"
                                onClick={() => {
                                    const key = toast.undo as string;
                                    setToast(null);
                                    void undo(key);
                                }}
                            >
                                Undo
                            </button>
                        ) : null}
                    </div>
                ) : null}
                <div className="mx-auto max-w-lg px-4 py-1">
                    <button
                        type="button"
                        onClick={() => void save()}
                        disabled={busy || !ready || preparing}
                        className={`flex h-14 w-full items-center justify-center rounded-xl px-4 text-base font-semibold ${missing ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground'} disabled:opacity-60`}
                    >
                        <span className="truncate">{busy ? 'Saving…' : saveLabel}</span>
                    </button>
                </div>
            </div>

            <PaidWithSheet
                open={sheet === 'paid'}
                onOpenChange={(o) => setSheet(o ? 'paid' : null)}
                pocket={pocket}
                paid={form.paidWith}
                accountId={form.accountId}
                onPick={(paid, account, done) => {
                    set({ paidWith: paid, accountId: account });
                    setPaidFresh(true);
                    if (done) setSheet(null);
                }}
            />
            <JobWhenSheet
                open={sheet === 'job'}
                onOpenChange={(o) => setSheet(o ? 'job' : null)}
                jobs={jobChoices}
                jobId={jobId}
                onJob={(id) => set({ jobPick: { id } })}
                date={date}
                today={today}
                yesterday={yesterday}
                openFrom={pocket.open_from}
                onDate={(d) => set({ datePick: d })}
                yearNote={yearNote}
            />
            <MoreSheet
                open={sheet === 'more'}
                onOpenChange={(o) => setSheet(o ? 'more' : null)}
                pct={form.businessPct}
                onPct={(pct) => set({ businessPct: pct })}
                note={form.note}
                onNote={(note) => set({ note })}
                foreign={foreign}
                sterling={form.sterling}
                onSterling={(sterling) => set({ sterling })}
            >
                {/* "Recharge to the client" (the clients and invoices build) goes here, sent with the entry's fields. */}
            </MoreSheet>
            <WhatSheet
                open={sheet === 'what'}
                onOpenChange={(o) => setSheet(o ? 'what' : null)}
                categories={pocket.categories}
                categoryId={form.categoryId}
                onCategory={(id) => set({ categoryId: id, pinned: true })}
                what={form.whatSource === 'typed' ? form.what : ''}
                onWhat={(text) => {
                    if (text === (form.whatSource === 'typed' ? form.what : '')) return;
                    set(text ? { what: text, whatSource: 'typed' } : { what: '', whatSource: null });
                }}
            />
            <CurrencySheet
                open={sheet === 'currency'}
                onOpenChange={(o) => setSheet(o ? 'currency' : null)}
                currency={form.currency}
                lastForeign={lastForeign}
                onPick={(code) => {
                    const d = currencyDigits(code);
                    // Fewer decimals in the new currency: drop the ones it can't have.
                    const [whole, frac] = form.amountText.split('.');
                    const text = frac === undefined ? form.amountText : d === 0 ? whole : `${whole}.${frac.slice(0, d)}`;
                    set({ currency: code, amountText: text });
                    setSheet(null);
                }}
            />
            <BottomSheet
                open={sheet === 'same'}
                onOpenChange={(o) => setSheet(o ? 'same' : null)}
                title="The same receipt?"
                description={
                    same
                        ? `You added ${moneyMinor(same.amount_minor, same.currency, { signed: false })} ${lowerFirst(same.what ?? '')} ${same.date === today ? 'today' : dayWords(same.date, today, yesterday)}${
                              same.at
                                  ? ` at ${new Date(typeof same.at === 'number' ? same.at : Date.parse(same.at)).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London' })}`
                                  : ''
                          }. Add another?`
                        : undefined
                }
            >
                <div className="flex gap-2">
                    <button
                        type="button"
                        className="bg-primary text-primary-foreground min-h-12 flex-1 rounded-lg px-4 text-sm font-medium"
                        onClick={() => {
                            setSheet(null);
                            void save(true);
                        }}
                    >
                        Add another
                    </button>
                    <button type="button" className="min-h-12 flex-1 rounded-lg border px-4 text-sm font-medium" onClick={() => setSheet(null)}>
                        Cancel
                    </button>
                </div>
            </BottomSheet>
        </QuickLayout>
    );
}
