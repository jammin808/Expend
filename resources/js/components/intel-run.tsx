import { cn } from '@/lib/utils';

/** A run of the nightly check, as /intel and its run log show it. */
export interface RunRow {
    id: number;
    run_key: string;
    kind: string;
    kind_words: string;
    mode: 'on' | 'shadow';
    status: string;
    status_words: string;
    started_at: string | null;
    finished_at: string | null;
    reported_at: string | null;
    ai_spent_usd: number;
    ai_budget_usd: number;
    summary: { steps?: Record<string, number>; findings?: Record<string, number> } | null;
    sealed: boolean;
    error: string | null;
}

/** A time in London, short: "14 Dec, 07:30". */
export function timeOf(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London' });
}

export function RunStatus({ status, words }: { status: string; words: string }) {
    const tone =
        status === 'ok'
            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
            : status === 'running'
              ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'
              : status === 'partial'
                ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300';
    return <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium whitespace-nowrap', tone)}>{words}</span>;
}
