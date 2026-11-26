import { ClientSelect } from '@/components/client-select';
import { DecisionForm, decisionProblem, initialDecision } from '@/components/decision-form';
import { IncomeAllocator, type AllocatorTick } from '@/components/income-allocator';
import { Money } from '@/components/money';
import { SplitEditor } from '@/components/split-editor';
import { StatusPill } from '@/components/status-pill';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { WhyPanel } from '@/components/why-panel';
import { postJson, problemText } from '@/lib/api';
import { day, decidedByLabels, kindLabels, paidIn, paidWith, rateText } from '@/lib/format';
import { lockedWords, yearLocked } from '@/lib/income';
import { cn } from '@/lib/utils';
import {
    type CalendarEntry,
    type ClientRef,
    type Decision,
    type DecisionLine,
    type IncomeInfo,
    type IncomeTarget,
    type Item,
    type JobRef,
    type Lookups,
    type SharedData,
} from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, History, Lock } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

interface Detail {
    item: Item & { import?: string | null; raw?: Record<string, string> };
    /** label: the event in plain words, where it has them (a check of the line) */
    history: { at: string; actor: string; action: string; label?: string; reason: string | null }[];
    events: CalendarEntry[];
    jobs_near: JobRef[];
    links?: DrawerLink[];
    /** What the why-panels explain: the decision, and calendar entries that day that are on a job */
    why?: { decision: string; calendar: { ref: string; summary: string | null; job: string | null; linked_by: string | null }[] };
    /** Money in: who the payer usually pays for, and the client to start from. Null for money out. */
    income?: IncomeInfo | null;
    /** What it could be for, one tap each (for money out: refunds and money back). */
    targets?: IncomeTarget[];
    /** Your decisions about its costs that no longer apply (it moved to another job). */
    charges_gone?: { ref: string; charge: string; note: string | null }[];
}

/** A to-do button offered on a link (TodoList::action plus its fingerprint), posted to /todo/{code}/{id}. */
interface LinkAction {
    code: string;
    id: string;
    label: string;
    style?: string;
    fp?: string | null;
}

/**
 * Something the item is connected to (TransactionController::links). A receipt photo from the phone also carries its
 * file, its entry, "Not this payment's receipt" (detach_href) on a bank line, and on an expense added from the phone
 * "Remove this expense" and "It was the business card".
 */
interface DrawerLink {
    type: string;
    label: string;
    detail: string | null;
    href: string | null;
    document_id?: number | null;
    file?: string | null;
    quick_id?: number | null;
    detach_href?: string | null;
    actions?: LinkAction[];
}

const linkTypes: Record<string, string> = {
    job: 'Job',
    invoice: 'Invoice',
    rule: 'Rule',
    travel: 'Stay or journey',
    transfer: 'Transfer',
    receipt: 'Receipt',
};

/** A decision about a cost that no longer applies, in words. */
const chargeWords: Record<string, string> = {
    recharge: 'Charge it to the client',
    no_charge: 'Not charged',
    elsewhere: 'Charged another way',
    invoiced: 'Charged on an invoice',
};

/** A line of the decision, in words: its kind, category, job or "General business", invoice, client and share. */
function lineWords(a: Item['allocations'][number]): string {
    return [
        kindLabels[a.kind],
        a.category,
        a.invoice_number ? `pays ${a.invoice_number}` : null,
        a.job ?? (a.kind === 'business_expense' && a.amount_pence < 0 ? 'General business' : null),
        a.kind === 'business_expense' && a.amount_pence > 0 && !a.job ? 'General business' : null,
        a.client && !a.job && !a.invoice_number ? `for ${a.client}` : null,
        a.business_use_pct && a.business_use_pct < 100 ? `${a.business_use_pct}% business` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/**
 * Everything about one item, with full manual control: decide, split, confirm, or send back. Money in opens on "What is
 * this payment for?" (its client, invoices and jobs); `tick` starts it with an invoice or a job ticked (not saved).
 */
export function ItemDrawer({
    target,
    lookups,
    onClose,
    tick,
}: {
    target: { source: 'bank' | 'manual'; id: number } | null;
    lookups: Lookups;
    onClose: () => void;
    tick?: AllocatorTick | null;
}) {
    const { nav } = usePage<SharedData>().props;
    const [detail, setDetail] = useState<Detail | null>(null);
    const [decision, setDecision] = useState<Decision | null>(null);
    const [mode, setMode] = useState<'none' | 'simple' | 'split'>('simple');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    // A remove button pressed once: the next press removes.
    const [sure, setSure] = useState<string | null>(null);

    useEffect(() => {
        setDetail(null);
        setFailed(false);
        setSure(null);
        setMode('simple');
        if (!target) return;
        let live = true;
        fetch(`/items/${target.source}/${target.id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d: Detail) => {
                if (!live) return;
                setDetail(d);
                setDecision(initialDecision(d.item.amount_pence, d.item.allocations, lookups));
                // Money in (not money moved from another account of yours) opens on what it's for.
                if (d.item.amount_pence > 0 && d.income && !d.item.transfer_pair_id) setMode('none');
                else if (d.item.allocations.length > 1 && !d.item.allocations.every((a) => a.kind === 'personal' || a.business_use_pct))
                    setMode('split');
            })
            .catch(() => live && setFailed(true));
        return () => {
            live = false;
        };
        // The lookups are the page's: a new item is what reloads this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const post = (url: string, data: Record<string, unknown>, done: string) => {
        setBusy(true);
        router.post(url, { ...data, quiet: true } as never, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // Refused (it changed since it was shown, say): the message is shown, and the drawer stays open.
                if ((page.props as unknown as SharedData).flash?.error) return;
                toast(done);
                onClose();
            },
            onError: (errors) => toast(Object.values(errors)[0] ?? 'That didn’t save. Try again.', 'error'),
            onFinish: () => setBusy(false),
        });
    };

    // A receipt photo's buttons: the server says what happened (its flash), and the drawer closes unless it was refused.
    const act = (url: string, data: Record<string, unknown>) => {
        setBusy(true);
        router.post(url, data as never, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if ((page.props as unknown as SharedData).flash?.error) return;
                onClose();
            },
            onFinish: () => setBusy(false),
        });
    };

    const item = detail?.item;
    const locked = yearLocked(nav, item?.tax_year);
    const moneyIn = !!item && item.amount_pence > 0;
    // Money in from someone else: "What is this payment for?". Money moved between your own accounts isn't income.
    const incomeMode = moneyIn && !!detail?.income && !item?.transfer_pair_id;
    const shown = item?.allocations.map((a) => a.id) ?? [];
    // The lines go through the decide endpoint with what the drawer showed: a decision it didn't show isn't overridden.
    const saveLines = (lines: DecisionLine[], done: string) => item && post(`/items/${item.source}/${item.id}/decide`, { lines, shown }, done);
    const outChips = !moneyIn ? (detail?.targets ?? []) : [];
    const first = item?.allocations[0];
    const business = item?.allocations.find((a) => a.kind === 'business_expense' || a.kind === 'business_income');

    return (
        <Sheet open={!!target} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto p-4 sm:max-w-xl sm:p-6">
                {!item || !decision ? (
                    <SheetHeader>
                        <SheetTitle className="sr-only">Transaction details</SheetTitle>
                        <SheetDescription className="text-muted-foreground p-6 text-sm">
                            {failed ? 'Couldn’t load this. Close it and try again.' : 'Loading…'}
                        </SheetDescription>
                    </SheetHeader>
                ) : (
                    <div className="space-y-6 pb-4">
                        <SheetHeader>
                            <SheetTitle className="pr-12 text-left text-base leading-snug">{item.description}</SheetTitle>
                            <SheetDescription className="text-left">
                                {day(item.date)}
                                {item.time ? ` at ${item.time}` : ''} · tax year {item.tax_year}
                            </SheetDescription>
                        </SheetHeader>

                        <dl className="grid grid-cols-[6.5rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Paid with</dt>
                            <dd className="min-w-0 break-words">{paidWith(item) || '—'}</dd>
                            {item.place && (
                                <>
                                    <dt className="text-muted-foreground">Place</dt>
                                    <dd className="min-w-0 break-words">{item.place}</dd>
                                </>
                            )}
                            {item.counterparty && item.counterparty !== item.description && (
                                <>
                                    <dt className="text-muted-foreground">Payee or payer</dt>
                                    <dd className="min-w-0 break-words">{item.counterparty}</dd>
                                </>
                            )}
                            {item.bank_category && (
                                <>
                                    <dt className="text-muted-foreground">Bank’s category</dt>
                                    <dd className="min-w-0 break-words">{item.bank_category}</dd>
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
                        {paidIn(item) && (
                            <p className="text-muted-foreground -mt-4 text-sm">
                                Paid {paidIn(item)}
                                {rateText(item.fx, item.original_currency) ? ` · ${rateText(item.fx, item.original_currency)}` : ''}
                                {item.fx?.rate_date ? `, ${day(item.fx.rate_date)}` : ''}
                            </p>
                        )}

                        {locked && (
                            <p className="flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                <Lock className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {lockedWords(item.tax_year)}{' '}
                                    <Link href={`/years/${item.tax_year}`} className="font-medium underline underline-offset-2">
                                        Open {item.tax_year}
                                    </Link>
                                </span>
                            </p>
                        )}

                        {incomeMode && detail.income && (
                            <IncomeAllocator
                                item={item}
                                income={detail.income}
                                targets={detail.targets ?? []}
                                lookups={lookups}
                                busy={busy}
                                disabled={locked}
                                tick={tick}
                                onSave={saveLines}
                            />
                        )}

                        {detail.links && detail.links.length > 0 && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p className="mb-1 font-medium">Connected to</p>
                                <ul className="space-y-1">
                                    {detail.links.map((l, i) => (
                                        <li key={i} className="flex flex-wrap items-baseline gap-x-2">
                                            <span className="text-muted-foreground w-28 shrink-0 text-xs">{linkTypes[l.type] ?? l.type}</span>
                                            {l.href ? (
                                                <a
                                                    href={l.href}
                                                    className="inline-flex min-h-10 items-center font-medium underline-offset-2 hover:underline"
                                                >
                                                    {l.label}
                                                </a>
                                            ) : (
                                                <span className="font-medium">{l.label}</span>
                                            )}
                                            {l.detail && <span className="text-muted-foreground text-xs">{l.detail}</span>}
                                            {(l.file || l.detach_href || (l.actions && l.actions.length > 0)) && (
                                                <span className="flex w-full flex-wrap items-center gap-2 pt-1 sm:pl-30">
                                                    {l.file && (
                                                        <a
                                                            href={l.file}
                                                            target="_blank"
                                                            rel="noopener"
                                                            className="inline-flex min-h-10 items-center text-xs underline underline-offset-2"
                                                        >
                                                            Open the photo
                                                        </a>
                                                    )}
                                                    {l.detach_href && (
                                                        <Button
                                                            variant="outline"
                                                            className="h-10"
                                                            disabled={busy || locked}
                                                            onClick={() => act(l.detach_href!, {})}
                                                        >
                                                            Not this payment’s receipt
                                                        </Button>
                                                    )}
                                                    {l.actions?.map((a) => {
                                                        // Removing an expense added from the phone takes it out of Expend: asked first, on
                                                        // the button itself (a second tap), not in a browser box.
                                                        const removing = a.id.endsWith(':cancel');
                                                        const asking = removing && sure === a.code + a.id;
                                                        return (
                                                            <Button
                                                                key={a.code + a.id}
                                                                variant={asking ? 'destructive' : 'outline'}
                                                                className="h-10"
                                                                disabled={busy}
                                                                onClick={() => {
                                                                    if (removing && !asking) return setSure(a.code + a.id);
                                                                    setSure(null);
                                                                    act(`/todo/${a.code}/${encodeURIComponent(a.id)}`, a.fp ? { fp: a.fp } : {});
                                                                }}
                                                            >
                                                                {asking ? 'Yes, remove it from Expend' : a.label}
                                                            </Button>
                                                        );
                                                    })}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {detail.charges_gone && detail.charges_gone.length > 0 && (
                            <div className="rounded-lg border p-3 text-sm">
                                <p className="mb-1 font-medium">Earlier choices about its costs</p>
                                <p className="text-muted-foreground mb-2 text-xs">
                                    These were for a job it isn’t on any more, so they don’t apply now.
                                </p>
                                <ul className="space-y-1">
                                    {detail.charges_gone.map((c) => (
                                        <li key={c.ref + c.charge}>
                                            {chargeWords[c.charge] ?? c.charge}
                                            {c.note ? <span className="text-muted-foreground"> · {c.note}</span> : null}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {item.allocations.length > 0 && first && (
                            <div className="bg-muted/40 rounded-lg border p-3 text-sm">
                                <p className="mb-2 font-medium">{first.confirmed || first.is_manual ? 'Current decision' : 'Suggestion'}</p>
                                <ul className="space-y-1">
                                    {item.allocations.map((a) => (
                                        <li key={a.id} className="flex flex-wrap justify-between gap-2">
                                            <span className="min-w-0">{lineWords(a)}</span>
                                            <Money pence={a.amount_pence} />
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    {first.confirmed ? 'Confirmed' : 'Suggested'} by {decidedByLabels[first.decided_by]}
                                    {first.reason ? `: ${first.reason}` : ''}
                                </p>
                                {detail.why && <WhyPanel refId={detail.why.decision} className="mt-2" />}
                                {!first.confirmed && (
                                    <Button
                                        className="mt-3 h-11"
                                        disabled={busy || locked}
                                        onClick={() => post(`/items/${item.source}/${item.id}/confirm`, { shown }, 'Accepted.')}
                                    >
                                        Accept suggestion
                                    </Button>
                                )}
                            </div>
                        )}

                        {!moneyIn && !locked && item.allocations.some((a) => a.kind === 'business_expense' && !a.work_job_id) && (
                            <RechargeToClient
                                url={`/items/${item.source}/${item.id}/recharge`}
                                clients={lookups.clients}
                                onDone={(message) => {
                                    toast(message);
                                    onClose();
                                }}
                            />
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
                                {detail.why && detail.why.calendar.length > 0 && (
                                    <ul className="mt-2 space-y-1">
                                        {detail.why.calendar.map((c) => (
                                            <li key={c.ref}>
                                                <WhyPanel refId={c.ref} label={`Why is “${c.summary ?? 'this entry'}” on ${c.job ?? 'a job'}?`} />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        {outChips.length > 0 && (
                            <OutChips chips={outChips} busy={busy} disabled={locked} onSave={(t) => saveLines(t.lines, 'Saved.')} />
                        )}

                        <div className="flex flex-wrap gap-2 text-sm" role="group" aria-label="How to decide it">
                            {incomeMode ? (
                                <>
                                    <Button
                                        variant={mode === 'simple' ? 'secondary' : 'ghost'}
                                        className="h-11"
                                        onClick={() => setMode(mode === 'simple' ? 'none' : 'simple')}
                                    >
                                        Something else (personal, transfer…)
                                    </Button>
                                    <Button
                                        variant={mode === 'split' ? 'secondary' : 'ghost'}
                                        className="h-11"
                                        onClick={() => setMode(mode === 'split' ? 'none' : 'split')}
                                    >
                                        Edit the lines yourself
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <Button variant={mode === 'simple' ? 'secondary' : 'ghost'} className="h-11" onClick={() => setMode('simple')}>
                                        Decide
                                    </Button>
                                    <Button variant={mode === 'split' ? 'secondary' : 'ghost'} className="h-11" onClick={() => setMode('split')}>
                                        Split across lines
                                    </Button>
                                </>
                            )}
                        </div>

                        {mode === 'simple' && (
                            <div className="space-y-4">
                                <DecisionForm
                                    amount={item.amount_pence}
                                    lookups={lookups}
                                    value={decision}
                                    onChange={setDecision}
                                    near={detail.jobs_near}
                                    merchant={item.merchant_key}
                                    on={item.date}
                                    jobName={business?.job}
                                    clientName={business?.client}
                                    invoiceName={business?.invoice_number}
                                    disabled={locked}
                                />
                                <Button
                                    className="h-11"
                                    disabled={busy || locked}
                                    onClick={() => {
                                        const problem = decisionProblem(decision);
                                        if (problem) return toast(problem, 'error');
                                        post(
                                            `/items/${item.source}/${item.id}/decide`,
                                            // What it was shown as: deciding differently is remembered only for that.
                                            { ...decision, shown } as never,
                                            'Saved. Your decision overrides any suggestion.',
                                        );
                                    }}
                                >
                                    Save decision
                                </Button>
                            </div>
                        )}
                        {mode === 'split' && (
                            <SplitEditor
                                amount={item.amount_pence}
                                allocations={item.allocations}
                                lookups={lookups}
                                near={detail.jobs_near}
                                on={item.date}
                                busy={busy}
                                disabled={locked}
                                deductionNote={detail.income?.deduction_note}
                                onSave={(lines) => saveLines(lines, 'Split saved.')}
                            />
                        )}

                        {item.allocations.length > 0 && (
                            <Button
                                variant="ghost"
                                className="text-muted-foreground h-11"
                                disabled={busy || locked}
                                onClick={() =>
                                    // Bound to the lines shown here: if they've changed since, nothing is cleared.
                                    post(`/items/${item.source}/${item.id}/clear`, { shown }, 'Sent back to the Inbox.')
                                }
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
                                            {h.label ?? `${h.action.replace('allocation.', '').replace('import.', 'import ')} by ${h.actor}`}
                                            {h.reason ? ` · ${h.reason}` : ''}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}

                        {item.raw && (
                            <details className="text-muted-foreground text-xs">
                                <summary className="cursor-pointer py-3">Original row from {item.import ?? 'the import'}</summary>
                                <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                    {Object.entries(item.raw).map(([k, v]) => (
                                        <Fragment key={k}>
                                            <dt className="font-medium">{k}</dt>
                                            <dd className="min-w-0 break-words">{v}</dd>
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

/** Money out that may be income paid back: "Refund to Acme Events", "Money back for the £450 on 3 Mar (INV-042)". */
function OutChips({ chips, busy, disabled, onSave }: { chips: IncomeTarget[]; busy: boolean; disabled: boolean; onSave: (t: IncomeTarget) => void }) {
    const [picked, setPicked] = useState<IncomeTarget | null>(null);
    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            <p className="font-medium">Money back to a client?</p>
            <div className="flex flex-wrap gap-2">
                {chips.map((t) => (
                    <button
                        key={t.label}
                        type="button"
                        disabled={disabled}
                        aria-pressed={picked?.label === t.label}
                        onClick={() => setPicked(picked?.label === t.label ? null : t)}
                        className={cn(
                            'min-h-11 rounded-full border px-3 py-2 text-left disabled:opacity-50',
                            picked?.label === t.label ? 'border-sky-600 bg-sky-50 text-sky-900 dark:bg-sky-950 dark:text-sky-100' : 'hover:bg-muted',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
            </div>
            {picked && (
                <Button className="h-11" disabled={busy || disabled} onClick={() => onSave(picked)}>
                    {picked.save}
                </Button>
            )}
        </div>
    );
}

/**
 * "Charge it to a client" on a business cost with no job: it goes on that client's next draft, under "From earlier
 * jobs" (POST items/{source}/{id}/recharge, as you). The answer says where it went, and whether it counts in their
 * costs yet.
 */
function RechargeToClient({ url, clients, onDone }: { url: string; clients: ClientRef[]; onDone: (message: string) => void }) {
    const [open, setOpen] = useState(false);
    const [client, setClient] = useState<ClientRef | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const send = async () => {
        if (!client) return;
        setBusy(true);
        setProblem(null);
        try {
            const r = await postJson<{ message: string }>(url, { client_id: client.id });
            onDone(r.message);
        } catch (e) {
            setProblem(problemText(e));
        } finally {
            setBusy(false);
        }
    };

    if (!open) {
        return (
            <Button variant="outline" className="h-11 w-full justify-start" onClick={() => setOpen(true)}>
                Charge it to a client…
            </Button>
        );
    }

    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            <p className="font-medium">Which client do you charge it to?</p>
            <p className="text-muted-foreground text-xs">It goes on their next invoice, at what you paid.</p>
            <ClientSelect clients={clients} value={client?.id ?? null} name={client?.name} onChange={setClient} />
            {problem && (
                <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                    {problem}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button className="h-auto min-h-11 py-2 text-left whitespace-normal" disabled={busy || !client} onClick={() => void send()}>
                    {client ? `Charge it to ${client.name}` : 'Pick the client first'}
                </Button>
                <Button variant="ghost" className="h-11" disabled={busy} onClick={() => setOpen(false)}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
