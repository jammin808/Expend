import { useQuickCounts } from '@/lib/quick';
import { usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

/**
 * The round + at the bottom right of every page on a phone: Add expense. A plain link (a full page load), so it opens
 * the cached screen with no signal, which an Inertia visit can't. It carries the number of entries still on the phone,
 * and isn't shown on the Add expense screen itself.
 */
export function QuickAddButton() {
    const url = usePage().url;
    const counts = useQuickCounts();
    if (url === '/quick' || url.startsWith('/quick?') || url.startsWith('/quick/')) return null;
    const waiting = counts.waiting + counts.shared + counts.needs_you;
    return (
        <a
            href="/quick"
            aria-label={waiting > 0 ? `Add expense (${waiting} waiting to send)` : 'Add expense'}
            className="bg-primary text-primary-foreground fixed z-40 flex size-14 items-center justify-center rounded-full shadow-lg md:hidden"
            style={{ right: 'calc(16px + env(safe-area-inset-right))', bottom: 'calc(16px + env(safe-area-inset-bottom))' }}
        >
            <Plus className="size-7" />
            {waiting > 0 ? (
                <span
                    className={`absolute -top-1 -right-1 flex min-h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-semibold text-white ${counts.needs_you > 0 ? 'bg-red-600' : 'bg-amber-600'}`}
                >
                    {waiting > 99 ? '99+' : waiting}
                </span>
            ) : null}
        </a>
    );
}
