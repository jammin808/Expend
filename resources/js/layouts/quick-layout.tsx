import { OldIconNote, QuickPill } from '@/components/quick/quick-pill';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * The light layout for the phone's quick screens: no sidebar, a 48 px header ("Expend · Add expense", To do, Inbox and
 * the waiting pill), and safe-area padding so nothing sits under the notch or the home bar. Links are plain anchors
 * (full page loads), which work from the cached screen with no signal.
 */
export default function QuickLayout({ title = 'Add expense', children, pill = true }: { title?: string; children: ReactNode; pill?: boolean }) {
    return (
        <div
            className="bg-background text-foreground min-h-svh"
            style={{ paddingLeft: 'env(safe-area-inset-left)', paddingRight: 'env(safe-area-inset-right)' }}
        >
            <Head title={title} />
            <div style={{ paddingTop: 'env(safe-area-inset-top)' }} className="bg-background sticky top-0 z-30 border-b">
                <OldIconNote />
                <header className="mx-auto flex h-12 max-w-lg items-center gap-1 pl-4">
                    <a href="/quick" className="flex h-12 min-w-0 flex-1 items-center truncate text-sm font-semibold">
                        <span className="truncate">
                            Expend <span className="text-muted-foreground font-normal">· {title}</span>
                        </span>
                    </a>
                    {pill ? <QuickPill className="mr-1" /> : null}
                    <a href="/todo" className="flex h-12 min-w-12 items-center justify-center px-2 text-sm font-medium">
                        To do
                    </a>
                    <a href="/inbox" className="flex h-12 min-w-12 items-center justify-center px-2 text-sm font-medium">
                        Inbox
                    </a>
                </header>
            </div>
            {children}
        </div>
    );
}
