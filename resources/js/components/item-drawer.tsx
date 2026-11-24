import { DecisionForm, decisionProblem, initialDecision } from '@/components/decision-form';
import { Money } from '@/components/money';
import { SplitEditor } from '@/components/split-editor';
import { StatusPill } from '@/components/status-pill';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { day, decidedByLabels, kindLabels, money, paidWith } from '@/lib/format';
import { type CalendarEntry, type Decision, type Item, type JobRef, type Lookups } from '@/types';
import { router } from '@inertiajs/react';
import { CalendarDays, History } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

interface Detail {
    item: Item & { import?: string | null; raw?: Record<string, string> };
    history: { at: string; actor: string; action: string; reason: string | null }[];
    events: CalendarEntry[];
    jobs_near: JobRef[];
    links?: { type: string; label: string; detail: string | null; href: string | null }[];
}

const linkTypes: Record<string, string> = {
    job: 'Job',
    invoice: 'Invoice',
    rule: 'Rule',
    travel: 'Stay or journey',
    transfer: 'Transfer',
    receipt: 'Receipt',
};

/** Everything about one item, with full manual control: decide, split, confirm, or send back. */
export function ItemDrawer({
    target,
    lookups,
    onClose,
}: {
    target: { source: 'bank' | 'manual'; id: number } | null;
    lookups: Lookups;
    onClose: () => void;
}) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [decision, setDecision] = useState<Decision | null>(null);
    const [mode, setMode] = useState<'simple' | 'split'>('simple');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setDetail(null);
        setMode('simple');
        if (!target) return;
        fetch(`/items/${target.source}/${target.id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d: Detail) => {
                setDetail(d);
                setDecision(initialDecision(d.item.amount_pence, d.item.allocations));
                if (d.item.allocations.length > 1 && !d.item.allocations.every((a) => a.kind === 'personal' || a.business_use_pct)) setMode('split');
            });
    }, [target]);

    const post = (url: string, data: Record<string, unknown>, done: string) => {
        setBusy(true);
        router.post(url, { ...data, quiet: true } as never, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast(done);
                onClose();
            },
            onFinish: () => setBusy(false),
        });
    };

    const item = detail?.item;

    return (
        <Sheet open={!!target} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                {!item || !decision ? (
                    <SheetHeader>
                        <SheetTitle className="sr-only">Transaction details</SheetTitle>
                        <SheetDescription className="text-muted-foreground p-6 text-sm">Loading…</SheetDescription>
                    </SheetHeader>
                ) : (
                    <div className="space-y-6 p-1">
                        <SheetHeader>
                            <SheetTitle className="pr-6 text-left text-base leading-snug">{item.description}</SheetTitle>
                            <SheetDescription className="text-left">
                                {day(item.date)}
                                {item.time ? ` at ${item.time}` : ''} · tax year {item.tax_year}
                            </SheetDescription>
                        </SheetHeader>

                        <dl className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Paid with</dt>
                            <dd>{paidWith(item) || '—'}</dd>
                            {item.place && (
                                <>
                                    <dt className="text-muted-foreground">Place</dt>
                                    <dd>{item.place}</dd>
                                </>
                            )}
                            {item.counterparty && item.counterparty !== item.description && (
                                <>
                                    <dt className="text-muted-foreground">Payee or payer</dt>
                                    <dd>{item.counterparty}</dd>
                                </>
                            )}
                            {item.bank_category && (
                                <>
                                    <dt className="text-muted-foreground">Bank’s category</dt>
                                    <dd>{item.bank_category}</dd>
                                </>
                            )}
                            {item.import && (
                                <>
                                    <dt className="text-muted-foreground">From statement</dt>
                                    <dd className="truncate">{item.import}</dd>
                                </>
                            )}
                        </dl>

                        <div className="flex items-baseline justify-between gap-4">
                            <Money pence={item.amount_pence} className="text-2xl font-semibold" />
                            <StatusPill status={item.review_status} />
                        </div>
                        {item.original_currency && item.original_currency !== 'GBP' && (
                            <p className="text-muted-foreground -mt-4 text-sm">
                                Paid in {item.original_currency}: {money(item.original_amount_minor, item.original_currency)}
                                {item.fx
                                    ? ` at ${item.fx.units_per_gbp} ${item.original_currency} per £1 (${item.fx.source === 'hmrc_monthly' ? 'HMRC monthly rate' : item.fx.source === 'bank' ? 'the bank’s rate' : item.fx.source}${item.fx.rate_date ? `, ${day(item.fx.rate_date)}` : ''})`
                                    : ''}
                            </p>
                        )}

                        {detail.links && detail.links.length > 0 && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p className="mb-1 font-medium">Connected to</p>
                                <ul className="space-y-1">
                                    {detail.links.map((l, i) => (
                                        <li key={i} className="flex flex-wrap items-baseline gap-x-2">
                                            <span className="text-muted-foreground w-28 shrink-0 text-xs">{linkTypes[l.type] ?? l.type}</span>
                                            {l.href ? (
                                                <a href={l.href} className="font-medium underline-offset-2 hover:underline">
                                                    {l.label}
                                                </a>
                                            ) : (
                                                <span className="font-medium">{l.label}</span>
                                            )}
                                            {l.detail && <span className="text-muted-foreground text-xs">{l.detail}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {item.allocations.length > 0 && (
                            <div className="bg-muted/40 rounded-lg border p-3 text-sm">
                                <p className="mb-2 font-medium">Current decision</p>
                                <ul className="space-y-1">
                                    {item.allocations.map((a) => (
                                        <li key={a.id} className="flex flex-wrap justify-between gap-2">
                                            <span>
                                                {kindLabels[a.kind]}
                                                {a.category ? ` · ${a.category}` : ''}
                                                {a.job ? ` · ${a.job}` : a.kind === 'business_expense' ? ' · General business' : ''}
                                                {a.business_use_pct && a.business_use_pct < 100 ? ` · ${a.business_use_pct}% business` : ''}
                                            </span>
                                            <Money pence={a.amount_pence} />
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    {item.allocations[0].confirmed ? 'Confirmed' : 'Suggested'} by {decidedByLabels[item.allocations[0].decided_by]}
                                    {item.allocations[0].reason ? `: ${item.allocations[0].reason}` : ''}
                                </p>
                                {!item.allocations[0].confirmed && (
                                    <Button
                                        size="sm"
                                        className="mt-3"
                                        disabled={busy}
                                        onClick={() => post(`/items/${item.source}/${item.id}/confirm`, {}, 'Confirmed.')}
                                    >
                                        Accept suggestion
                                    </Button>
                                )}
                            </div>
                        )}

                        {detail.events.length > 0 && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p className="mb-1 flex items-center gap-2 font-medium">
                                    <CalendarDays className="size-4" /> On your calendar that day
                                </p>
                                <ul className="text-muted-foreground space-y-0.5">
                                    {detail.events.map((e, i) => (
                                        <li key={i}>
                                            {e.time ? `${e.time} ` : ''}
                                            {e.summary}
                                            {e.location ? ` · ${e.location}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex gap-2 text-sm">
                            <Button variant={mode === 'simple' ? 'secondary' : 'ghost'} size="sm" onClick={() => setMode('simple')}>
                                Decide
                            </Button>
                            <Button variant={mode === 'split' ? 'secondary' : 'ghost'} size="sm" onClick={() => setMode('split')}>
                                Split across lines
                            </Button>
                        </div>

                        {mode === 'simple' ? (
                            <div className="space-y-4">
                                <DecisionForm
                                    amount={item.amount_pence}
                                    lookups={lookups}
                                    value={decision}
                                    onChange={setDecision}
                                    near={detail.jobs_near}
                                    merchant={item.merchant_key}
                                />
                                <Button
                                    disabled={busy}
                                    onClick={() => {
                                        const problem = decisionProblem(decision);
                                        if (problem) return toast(problem, 'error');
                                        post(
                                            `/items/${item.source}/${item.id}/decide`,
                                            decision as never,
                                            'Saved. Your decision overrides any suggestion.',
                                        );
                                    }}
                                >
                                    Save decision
                                </Button>
                            </div>
                        ) : (
                            <SplitEditor
                                amount={item.amount_pence}
                                allocations={item.allocations}
                                lookups={lookups}
                                near={detail.jobs_near}
                                busy={busy}
                                onSave={(lines) => post(`/items/${item.source}/${item.id}/decide`, { lines }, 'Split saved.')}
                            />
                        )}

                        {item.allocations.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={() => post(`/items/${item.source}/${item.id}/clear`, {}, 'Sent back to the Inbox.')}
                            >
                                Clear decision and send back to the Inbox
                            </Button>
                        )}

                        {detail.history.length > 0 && (
                            <div className="text-sm">
                                <p className="mb-2 flex items-center gap-2 font-medium">
                                    <History className="size-4" /> History
                                </p>
                                <ol className="text-muted-foreground space-y-1 border-l pl-3">
                                    {detail.history.map((h, i) => (
                                        <li key={i}>
                                            <span className="tabular-nums">{h.at}</span> ·{' '}
                                            {h.action.replace('allocation.', '').replace('import.', 'import ')} by {h.actor}
                                            {h.reason ? ` · ${h.reason}` : ''}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}

                        {item.raw && (
                            <details className="text-muted-foreground text-xs">
                                <summary className="cursor-pointer">Original row from {item.import ?? 'the import'}</summary>
                                <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                    {Object.entries(item.raw).map(([k, v]) => (
                                        <Fragment key={k}>
                                            <dt className="font-medium">{k}</dt>
                                            <dd>{v}</dd>
                                        </Fragment>
                                    ))}
                                </dl>
                            </details>
                        )}
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
