import { countsWords, isIos, isStandalone, launchParams, useQuickCounts, type Counts } from '@/lib/quick';
import { useEffect, useState } from 'react';

/**
 * "2 waiting · 1 needs you", read from the phone's own store, with the needs-you part in red. Shown on any page while
 * the phone has entries Expend hasn't got; it links to the waiting list on the Add expense screen (a full page load,
 * so it opens offline too).
 */
export function QuickPill({ counts, href = '/quick#waiting', className = '' }: { counts?: Counts; href?: string | null; className?: string }) {
    const live = useQuickCounts();
    const c = counts ?? live;
    const words = countsWords(c);
    if (!words) return null;
    const body = (
        <>
            {words.waiting ? <span>{words.waiting}</span> : null}
            {words.waiting && words.needs ? <span aria-hidden="true"> · </span> : null}
            {words.needs ? <span className="font-semibold text-red-600 dark:text-red-400">{words.needs}</span> : null}
        </>
    );
    // 32 px to look at, 48 px to tap (the ::after reaches 8 px above and below).
    const look = `relative inline-flex min-h-8 items-center rounded-full border border-amber-300 bg-amber-50 px-3 text-xs font-medium text-amber-900 after:absolute after:-inset-y-2 after:inset-x-0 after:content-[''] dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100 ${className}`;
    if (!href) return <span className={look}>{body}</span>;
    return (
        <a href={href} className={look}>
            {body}
        </a>
    );
}

const OLD_ICON_SEEN = 'expend.old_icon_note';
const HOME_LAUNCH = 'expend.home_launch';

function read(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private mode: the note may show again.
    }
}

/** Remember that this home-screen app was opened from the new Expend icon (its start address has ?source=home). */
export function recordHomeLaunch() {
    write(HOME_LAUNCH, new Date().toISOString());
}

/**
 * Inside the old "Ledger" home-screen app on an iPhone (a standalone app that has never been opened with
 * ?source=home), at most once a day: add the new icon, then remove this one. While this app still has entries waiting
 * it says to send them first, and never suggests removing it.
 */
export function OldIconNote() {
    const counts = useQuickCounts();
    const [show, setShow] = useState(false);
    useEffect(() => {
        // Opened from the new icon (its start address says so): remember it, and this is not the old app.
        if (launchParams().get('source') === 'home') {
            recordHomeLaunch();
            return;
        }
        if (!isIos() || !isStandalone() || read(HOME_LAUNCH)) return;
        const last = read(OLD_ICON_SEEN);
        if (last && Date.now() - Date.parse(last) < 24 * 60 * 60 * 1000) return;
        setShow(true);
        write(OLD_ICON_SEEN, new Date().toISOString());
    }, []);
    if (!show) return null;
    const waiting = counts.waiting + counts.needs_you + counts.shared;
    return (
        <div className="flex items-start gap-2 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p className="flex-1 py-2">
                {waiting > 0
                    ? `This is the old Ledger icon. Send ${waiting} waiting first: open Add expense with signal.`
                    : 'This is the old Ledger icon. Add Expend from Safari’s Share menu, then remove this one.'}
            </p>
            <button type="button" className="min-h-12 px-2 font-medium underline" onClick={() => setShow(false)}>
                OK
            </button>
        </div>
    );
}
