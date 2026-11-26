import { cn } from '@/lib/utils';

const green = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300';
const amber = 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
const rose = 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300';
const slate = 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
const sky = 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300';
const violet = 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300';

const styles: Record<string, string> = {
    unreviewed: slate,
    suggested: amber,
    confirmed: green,
    outstanding: rose,
    in_progress: amber,
    ready_to_file: sky,
    filed: green,
    amending: violet,
    // An invoice's state (InvoicePayments): what's come in against it.
    paid: green,
    part_paid: amber,
    closed: slate,
    overpaid: violet,
    check_currency: sky,
    unpaid: slate,
    overdue: rose,
    draft: slate,
    void: slate,
};

const labels: Record<string, string> = {
    unreviewed: 'To decide',
    suggested: 'Suggested',
    confirmed: 'Confirmed',
    outstanding: 'Outstanding',
    in_progress: 'In progress',
    ready_to_file: 'Ready to file',
    filed: 'Filed',
    amending: 'Amending',
    paid: 'Paid',
    part_paid: 'Part paid',
    closed: 'Closed',
    overpaid: 'Overpaid',
    check_currency: 'Check: paid in another currency',
    unpaid: 'Unpaid',
    overdue: 'Overdue',
    draft: 'Draft',
    void: 'Void',
};

/**
 * A status in a coloured pill: a payment's (To decide, Suggested, Confirmed), a tax year's, or an invoice's (Paid, Part
 * paid £300 of £450, Closed · £30 not paid, Overpaid £100, Unpaid, Overdue). `label` gives the server's own words.
 */
export function StatusPill({ status, label, className }: { status: string; label?: string | null; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', styles[status] ?? styles.unreviewed, className)}>
            {label ?? labels[status] ?? status}
        </span>
    );
}
