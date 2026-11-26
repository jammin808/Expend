import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type DraftChecks, type DraftSheet, type InvoiceDetail } from '@/types/invoices';
import { router } from '@inertiajs/react';
import { AlertTriangle, Info } from 'lucide-react';
import { type ReactNode, useState } from 'react';

/** What stops the draft being issued now (the server's blocks, and who it's for), counted for the bottom bar. */
export function blockingCount(checks: DraftChecks, invoice: InvoiceDetail): number {
    return checks.blocks.length + (invoice.bill_to_name ? 0 : 1);
}

/**
 * "Needs you": what's missing, asked for in place. First how the client pays travel and expenses (one tap writes their
 * usual arrangement, as you, and the lines are ticked by it), then what blocks issuing (a line to look at, your name
 * and address, a cost whose payment issuing confirms, one in a locked year, who it's for), then what's worth a look.
 */
export function NeedsYou({
    invoice,
    draft,
    onOpenLine,
    onUntick,
    onDetails,
    onIssue,
    onDone,
}: {
    invoice: InvoiceDetail;
    draft: DraftSheet;
    onOpenLine: (lineId: number) => void;
    onUntick: (lineId: number) => void;
    onDetails: () => void;
    onIssue: () => void;
    onDone: (message: string) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const client = draft.client;
    const terms = client?.terms ?? null;
    const blocks = draft.checks.blocks;
    const warnings = draft.checks.warnings;
    const noEmail = draft.gmail && !invoice.bill_to_email && draft.addresses.length === 0;
    if (!terms && blocks.length === 0 && warnings.length === 0 && invoice.bill_to_name && !noEmail) return null;

    const usual = (preset: string) => {
        if (!client || !terms) return;
        setBusy(true);
        setProblem(null);
        router.put(
            `/clients/${client.id}/terms`,
            { fp: terms.fp, preset },
            {
                preserveScroll: true,
                onSuccess: () => onDone(''),
                onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That wasn’t saved. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <section id="needs-you" aria-label="Needs you" className="scroll-mt-24 space-y-2">
            <h2 className="text-base font-semibold">Needs you</h2>
            {terms && client && (
                <div className="space-y-2 rounded-xl border p-3">
                    <p className="text-sm font-medium">How does {client.name} pay travel and expenses?</p>
                    <p className="text-muted-foreground text-xs">Expend ticks their costs by it, on this draft and their next ones.</p>
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        {terms.presets.map((p) => (
                            <Button key={p.key} variant="outline" className="h-11 justify-start" disabled={busy} onClick={() => usual(p.key)}>
                                {p.label}
                            </Button>
                        ))}
                        <Button variant="ghost" className="h-11 justify-start" asChild>
                            <a href={`/clients/${client.id}`}>Set it up…</a>
                        </Button>
                    </div>
                    {problem && (
                        <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                            {problem}
                        </p>
                    )}
                </div>
            )}
            <ul className="space-y-2">
                {!invoice.bill_to_name && (
                    <Item tone="block" message="Who’s it for? The invoice needs a name to bill.">
                        <Button className="h-11" onClick={onDetails}>
                            Add who it’s for
                        </Button>
                    </Item>
                )}
                {blocks.map((b, i) => (
                    <Item key={`b${i}`} tone="block" message={b.message}>
                        {b.code === 'line' && b.line !== null && (
                            <Button variant="outline" className="h-11" onClick={() => onOpenLine(b.line as number)}>
                                Look at it
                            </Button>
                        )}
                        {b.code === 'your_details' && (
                            <Button variant="outline" className="h-11" asChild>
                                <a href="/settings/integrations">Add them</a>
                            </Button>
                        )}
                        {b.code === 'locked' && b.line !== null && (
                            <Button variant="outline" className="h-11" onClick={() => onUntick(b.line as number)}>
                                Untick it
                            </Button>
                        )}
                        {b.code === 'unconfirmed' && (
                            <Button variant="outline" className="h-11" onClick={onIssue}>
                                See the split
                            </Button>
                        )}
                    </Item>
                ))}
                {noEmail && (
                    <Item tone="note" message={`Where do ${client?.name ?? 'they'} want invoices? It’s needed for Gmail, not for issuing.`}>
                        <Button variant="outline" className="h-11" onClick={onDetails}>
                            Add their email
                        </Button>
                    </Item>
                )}
                {warnings.map((w, i) => (
                    <Item key={`w${i}`} tone="note" message={w.message}>
                        {w.code === 'mileage_missing' && (
                            <Button variant="outline" className="h-11" asChild>
                                <a href="/mileage">Add them</a>
                            </Button>
                        )}
                        {w.code === 'client_differs' && (
                            <Button variant="outline" className="h-11" onClick={onDetails}>
                                Check who it’s for
                            </Button>
                        )}
                    </Item>
                ))}
            </ul>
        </section>
    );
}

function Item({ tone, message, children }: { tone: 'block' | 'note'; message: string; children?: ReactNode }) {
    const Icon = tone === 'block' ? AlertTriangle : Info;
    return (
        <li
            className={cn(
                'space-y-2 rounded-xl border p-3',
                tone === 'block' ? 'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/50' : 'bg-muted/40',
            )}
        >
            <p className="flex gap-2 text-sm">
                <Icon className={cn('mt-0.5 size-4 shrink-0', tone === 'block' ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground')} />
                <span className="min-w-0 break-words">{message}</span>
            </p>
            {children && <div className="flex flex-wrap gap-2 pl-6">{children}</div>}
        </li>
    );
}
