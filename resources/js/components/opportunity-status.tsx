import { day } from '@/lib/format';
import { cn } from '@/lib/utils';

export interface OpportunityRow {
    id: number;
    status: string;
    status_label: string;
    hold_rank: number | null;
    title: string;
    client_id: number | null;
    client_name: string | null;
    contact_name: string | null;
    contact_email: string | null;
    role: string | null;
    venue: string | null;
    postcode: string | null;
    starts_on: string | null;
    ends_on: string | null;
    days: { date: string; kind: string }[];
    rate: { minor: number; currency: string; unit: string; text: string } | null;
    reply_by: string | null;
    reply_in: number | null;
    po_number: string | null;
    job: { id: number; title: string } | null;
    source: string;
    found_by: 'rules' | 'ai' | 'you';
    confidence: number;
    unverified: string[];
    may_be_off: { quote: string | null; id: number | null; verified: boolean } | null;
    documents: number;
    first_seen: string | null;
    last_seen: string | null;
    decided_at: string | null;
    decided_by: string | null;
    note: string | null;
    open: boolean;
    accepted: boolean;
    linked_by_rules: boolean;
}

const styles: Record<string, string> = {
    enquiry: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    held: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    offered: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    confirmed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    declined: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    lost: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    expired: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    dismissed: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
};

export function OpportunityStatus({ status, label, className }: { status: string; label: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', styles[status] ?? styles.declined, className)}>
            {label}
        </span>
    );
}

export function ordinal(n: number): string {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

/**
 * Its dates in a few words: "12 Mar 2026", "12 – 14 Mar 2026", or for days that aren't consecutive, the first and
 * last with how many: "12 – 20 Mar 2026 (3 days)".
 */
export function daysText(days: { date: string }[]): string {
    if (!days.length) return '';
    const first = days[0].date;
    const last = days[days.length - 1].date;
    if (first === last) return day(first);
    const span = Math.round((Date.parse(last) - Date.parse(first)) / 86_400_000) + 1;
    return `${day(first, false)} – ${day(last)}${span !== days.length ? ` (${days.length} days)` : ''}`;
}
