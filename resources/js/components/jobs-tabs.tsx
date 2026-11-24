import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';

const TABS = [
    { href: '/jobs', label: 'Jobs' },
    { href: '/opportunities', label: 'Enquiries & offers' },
    { href: '/jobs/calendar', label: 'Calendar' },
    { href: '/jobs/finder', label: 'Possible jobs' },
    { href: '/documents', label: 'Documents' },
    { href: '/travel', label: 'Stays & travel' },
];

/** Jobs, enquiries and offers, possible jobs, documents and stays live together under Jobs. */
export function JobsTabs() {
    const url = usePage().url.split('?')[0];
    const current = TABS.filter((t) => url === t.href || url.startsWith(t.href + '/')).sort((a, b) => b.href.length - a.href.length)[0]?.href;

    return (
        <nav className="-mb-1 flex gap-1 overflow-x-auto border-b text-sm" aria-label="Jobs sections">
            {TABS.map((t) => (
                <Link
                    key={t.href}
                    href={t.href}
                    prefetch
                    className={cn(
                        'shrink-0 border-b-2 px-3 py-2',
                        current === t.href ? 'border-foreground font-medium' : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {t.label}
                </Link>
            ))}
        </nav>
    );
}
