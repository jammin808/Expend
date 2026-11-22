import { cn } from '@/lib/utils';

const styles: Record<string, string> = {
    unreviewed: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    suggested: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    confirmed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    outstanding: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    ready_to_file: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    filed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    amending: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
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
};

export function StatusPill({ status, className }: { status: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', styles[status] ?? styles.unreviewed, className)}>
            {labels[status] ?? status}
        </span>
    );
}
