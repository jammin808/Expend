import { Button } from '@/components/ui/button';
import { when } from '@/lib/invoice';
import { type DraftChange } from '@/types/invoices';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';

/**
 * "Since you last looked": what the job's sync changed on the draft (lines added, changed or removed), up to three and
 * then "and 2 more". Got it marks them seen, up to the last one shown; one that came after stays listed. Saving and
 * issuing mark them seen too.
 */
export function ChangesBanner({
    changes,
    since,
    busy,
    onGotIt,
}: {
    changes: DraftChange[];
    since: string | null;
    busy: boolean;
    onGotIt: () => void;
}) {
    const [all, setAll] = useState(false);
    if (changes.length === 0) return null;
    const shown = all ? changes : changes.slice(0, 3);

    return (
        <section
            aria-label="Since you last looked"
            className="space-y-2 rounded-xl border border-sky-300 bg-sky-50 p-3 dark:border-sky-800 dark:bg-sky-950/60"
        >
            <p className="flex items-center gap-2 text-sm font-medium text-sky-900 dark:text-sky-200">
                <RefreshCw className="size-4 shrink-0" />
                Since you last looked{since ? ` (${when(since)})` : ''}
            </p>
            <ul className="space-y-1 text-sm text-sky-950 dark:text-sky-100">
                {shown.map((c) => (
                    <li key={c.id} className="flex gap-2">
                        <span className="mt-2 size-1.5 shrink-0 rounded-full bg-sky-500" />
                        <span className="min-w-0 break-words">{c.words}</span>
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap items-center gap-2">
                <Button className="h-11" disabled={busy} onClick={onGotIt}>
                    Got it
                </Button>
                {changes.length > 3 && (
                    <Button variant="ghost" className="h-11" onClick={() => setAll((a) => !a)}>
                        {all ? 'Show fewer' : `and ${changes.length - 3} more: show`}
                    </Button>
                )}
            </div>
        </section>
    );
}
