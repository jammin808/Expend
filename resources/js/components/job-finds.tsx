import { Button } from '@/components/ui/button';
import { day, money } from '@/lib/format';
import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';

export interface Find {
    id: number;
    type: 'calendar' | 'document' | 'payment' | 'income' | 'travel' | 'invoice';
    type_label: string;
    ref: string;
    status: 'linked' | 'possible';
    label: string;
    detail: string | null;
    date: string | null;
    /** Pence for a payment or money in; for a booking or an invoice, hundredths of its own currency. */
    amount_pence: number | null;
    currency: string | null;
    /** A payment not decided yet: open it to decide what it is (the job can't take it until then). */
    undecided?: boolean;
    /** An invoice that's been issued or paid: it stays with the job (void it and issue a new one to move it). */
    locked?: 'issued' | 'paid' | null;
}
export interface GatherState {
    at: string | null;
    gmail_query: string | null;
    state: { gmail?: string; gmail_found?: number; gmail_new?: number; gmail_at?: string; gmail_error?: string | null } | null;
}

/**
 * What the search for this job found: what may belong (link it, or say it isn't this job's) and what it linked
 * (undo any of it). "Search again" looks once more, including Gmail.
 */
export function JobFinds({
    jobId,
    finds,
    gather,
    onOpenPayment,
}: {
    jobId: number;
    finds: Find[];
    gather: GatherState;
    onOpenPayment: (id: number) => void;
}) {
    const [busy, setBusy] = useState(false);
    const possible = finds.filter((f) => f.status === 'possible');
    const linked = finds.filter((f) => f.status === 'linked');
    const gmail = gather.state?.gmail;
    const opts = { preserveScroll: true };
    const href = (f: Find) =>
        f.type === 'document' ? `/documents/${f.ref}` : f.type === 'invoice' ? `/invoices/${f.ref}` : f.type === 'travel' ? '/travel' : null;

    const row = (f: Find) => (
        <li key={f.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
            <span className="text-muted-foreground w-28 shrink-0 text-xs">{f.type_label}</span>
            <span className="min-w-0 flex-1">
                {href(f) ? (
                    <a className="font-medium underline-offset-2 hover:underline" href={href(f)!}>
                        {f.label}
                    </a>
                ) : (
                    <span className="font-medium">{f.label}</span>
                )}
                {f.detail && <span className="text-muted-foreground block text-xs">{f.detail}</span>}
            </span>
            {f.date && <span className="text-muted-foreground text-xs">{day(f.date)}</span>}
            {f.amount_pence !== null && <span className="tabular-nums">{money(f.amount_pence, f.currency ?? 'GBP')}</span>}
            <span className="flex gap-1">
                {f.status === 'possible' &&
                    (f.type === 'payment' && f.undecided ? (
                        <Button size="sm" variant="outline" className="h-11" onClick={() => onOpenPayment(Number(f.ref))}>
                            Decide
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" className="h-11" onClick={() => router.post(`/job-finds/${f.id}/accept`, {}, opts)}>
                            Link
                        </Button>
                    ))}
                {f.status === 'linked' && f.locked ? (
                    <span className="text-muted-foreground text-xs">
                        {f.locked === 'paid' ? 'Paid, so it stays with this job' : 'Issued: void it and issue a new one to move it'}
                    </span>
                ) : (
                    <Button size="sm" variant="ghost" className="h-11" onClick={() => router.post(`/job-finds/${f.id}/reject`, {}, opts)}>
                        {f.status === 'linked' ? 'Undo' : 'Not this job'}
                    </Button>
                )}
            </span>
        </li>
    );

    return (
        <section className="space-y-2 rounded-xl border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="font-semibold">Found for this job</h2>
                    <p className="text-muted-foreground text-xs">
                        {gather.at ? `Searched ${gather.at}` : 'Not searched yet'}
                        {gmail === 'queued' && ' · searching Gmail now (refresh in a minute)'}
                        {gmail === 'done' && ` · Gmail: ${gather.state?.gmail_found ?? 0} emails found, ${gather.state?.gmail_new ?? 0} new`}
                        {gmail === 'failed' && ` · Gmail search failed: ${gather.state?.gmail_error ?? ''}`}
                        {gmail === 'not_connected' && ' · Gmail isn’t connected'}
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    className="h-11"
                    disabled={busy}
                    onClick={() =>
                        router.post(`/jobs/${jobId}/gather`, {}, { ...opts, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                    }
                >
                    <RefreshCw className={busy ? 'animate-spin' : ''} /> {busy ? 'Searching…' : 'Search again'}
                </Button>
            </div>
            {possible.length > 0 && (
                <div>
                    <h3 className="text-sm font-medium">May belong to this job</h3>
                    <ul className="divide-y">{possible.map(row)}</ul>
                </div>
            )}
            {linked.length > 0 && (
                <details>
                    <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                        Linked by the search ({linked.length})
                    </summary>
                    <ul className="divide-y">{linked.map(row)}</ul>
                </details>
            )}
            {possible.length === 0 && linked.length === 0 && gather.at && (
                <p className="text-muted-foreground text-sm">Nothing found yet. Add the client, venue or PO number to the job and search again.</p>
            )}
        </section>
    );
}
