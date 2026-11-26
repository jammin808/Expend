import { StatusPill } from '@/components/status-pill';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { shortMoney } from '@/lib/income';
import { cn } from '@/lib/utils';
import { type InvoiceRow } from '@/types/invoices';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

// Other pages (a job's, a client's) list invoices in this shape too.
export type { InvoiceRow } from '@/types/invoices';

type Filter = 'all' | 'drafts' | 'to_send' | 'waiting' | 'overdue' | 'paid';

const FILTERS: { key: Filter; label: string; match: (i: InvoiceRow) => boolean }[] = [
    { key: 'all', label: 'All', match: () => true },
    { key: 'drafts', label: 'Drafts', match: (i) => i.status === 'draft' },
    { key: 'to_send', label: 'To send', match: (i) => i.status === 'issued' && !i.sent && i.outstanding_minor > 0 },
    { key: 'waiting', label: 'Waiting for payment', match: (i) => i.status === 'issued' && !!i.sent && i.outstanding_minor > 0 && !i.overdue },
    { key: 'overdue', label: 'Overdue', match: (i) => i.overdue },
    { key: 'paid', label: 'Paid or closed', match: (i) => i.state === 'paid' || i.state === 'closed' },
];

/** Where an invoice is up to, in a few words: the draft's changes, not sent yet, days overdue, and what's still to come. */
function stageChip(i: InvoiceRow, changes: number): string | null {
    if (i.status === 'draft') return changes > 0 ? `${changes} ${changes === 1 ? 'change' : 'changes'} since you looked` : null;
    if (i.status === 'void') return null;
    if (i.status === 'issued' && !i.sent && i.outstanding_minor > 0) return 'Not sent yet';
    const toCome = i.state === 'part_paid' ? `${shortMoney(i.outstanding_minor, i.currency)} to come` : null;
    if (i.overdue && i.overdue_days)
        return [`${i.overdue_days} ${i.overdue_days === 1 ? 'day' : 'days'} overdue`, toCome].filter(Boolean).join(' · ');
    return toCome;
}

/**
 * Every invoice, newest first: where its money stands (paid, part paid, closed, overdue…), where it's up to (a draft's
 * changes, not sent yet, days overdue), and filters for each.
 */
export default function Invoices({ invoices, changes = {} }: { invoices: InvoiceRow[]; changes?: Record<string, number> }) {
    const [filter, setFilter] = useState<Filter>('all');
    const toCome = invoices.filter((i) => i.status === 'issued' && i.currency === 'GBP').reduce((s, i) => s + i.outstanding_minor, 0);
    const shown = invoices.filter(FILTERS.find((f) => f.key === filter)!.match);

    return (
        <AppLayout breadcrumbs={[{ title: 'Invoices', href: '/invoices' }]}>
            <Head title="Invoices" />
            <div className="mx-auto w-full max-w-5xl space-y-4 px-4 pt-4 pb-24 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Invoices</h1>
                    <p className="text-muted-foreground text-sm">
                        {money(toCome)} still to come (invoices in pounds). Draft one from a job, or from a PO on its document page.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Show">
                    {FILTERS.map((f) => {
                        const n = f.key === 'all' ? invoices.length : invoices.filter(f.match).length;
                        if (f.key !== 'all' && n === 0) return null;
                        return (
                            <button
                                key={f.key}
                                type="button"
                                role="radio"
                                aria-checked={filter === f.key}
                                onClick={() => setFilter(f.key)}
                                className={cn(
                                    'min-h-11 rounded-full border px-3 text-sm',
                                    filter === f.key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                                )}
                            >
                                {f.label} ({n})
                            </button>
                        );
                    })}
                </div>
                <ul className="divide-y rounded-xl border">
                    {shown.map((i) => {
                        const chip = stageChip(i, changes[String(i.id)] ?? 0);
                        return (
                            <li key={i.id}>
                                <Link href={`/invoices/${i.id}`} className="hover:bg-muted/40 flex min-h-11 items-start justify-between gap-3 p-3">
                                    <span className="min-w-0">
                                        <span className="font-medium">{i.number ?? 'Draft'}</span>
                                        <span className="text-muted-foreground"> · {i.client ?? 'No client yet'}</span>
                                        <span className="text-muted-foreground block text-xs break-words">
                                            {[
                                                i.job?.title,
                                                i.po_number ? `PO ${i.po_number}` : null,
                                                i.due_date && i.status !== 'draft' ? `due ${day(i.due_date)}` : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                        {chip && (
                                            <span
                                                className={cn(
                                                    'mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium',
                                                    i.overdue || (i.status === 'issued' && !i.sent)
                                                        ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                                                        : 'bg-muted text-neutral-700 dark:text-neutral-300',
                                                )}
                                            >
                                                {chip}
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        <span className="font-medium tabular-nums">{money(i.total_minor, i.currency)}</span>
                                        <StatusPill status={i.overdue && i.state === 'unpaid' ? 'overdue' : i.state} />
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                    {shown.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">No invoices here.</li>}
                </ul>
            </div>
        </AppLayout>
    );
}
