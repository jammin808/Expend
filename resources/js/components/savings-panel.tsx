import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Lightbulb, Scale } from 'lucide-react';

export interface SavingItem {
    id: string;
    status: 'action' | 'check' | 'info' | 'ok' | 'idea';
    finding?: string;
    deduction_pence?: number | null;
    saving_pence?: number | null;
    title?: string;
    conditions?: string | null;
    how?: string | null;
    risks?: string | null;
    needs_accountant?: boolean;
    /** source_note says when a link opens something other than its title (HMRC's summary of a case, or a later case). */
    sources?: { title: string; url: string; source_note?: string }[];
}
export interface SavingsReview {
    tax_year: string;
    /** The year isn't over, so the figures are from the records so far. */
    in_progress: boolean;
    marginal_pct: string | null;
    profit_pence: number;
    /** The headline, worked out on the server: fines left out, the trading allowance counted once against expense claims. */
    total_saving_pence: number;
    /** Fines and interest already due (not counted as a saving). */
    penalties_to_avoid_pence: number;
    items: SavingItem[];
    more: SavingItem[];
    researched_on: string | null;
    note: string;
}

const pounds = (p: number) => money(p, 'GBP', { signed: false });
const badge: Record<SavingItem['status'], string> = {
    action: 'Worth doing',
    check: 'Worth checking',
    info: 'Good to know',
    ok: 'Done',
    idea: 'Idea',
};

function Law({ item }: { item: SavingItem }) {
    if (!item.sources?.length && !item.conditions && !item.risks) return null;
    return (
        <details className="text-muted-foreground mt-1 text-xs">
            <summary className="cursor-pointer">Conditions, risks and the law</summary>
            <div className="mt-1 space-y-1">
                {item.conditions && <p>Conditions: {item.conditions}</p>}
                {item.risks && <p>Risks: {item.risks}</p>}
                {item.sources && item.sources.length > 0 && (
                    <ul className="space-y-0.5">
                        {item.sources.map((s, i) => (
                            <li key={i}>
                                <a href={s.url} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                    {s.title}
                                </a>
                                {s.source_note && <span> ({s.source_note})</span>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </details>
    );
}

/**
 * Legal ways to pay less tax for one year, checked against the records: claims that look missing, reliefs worth using
 * at your rate of tax, choices to review and deadlines. Each says what was found, an estimated saving where one can
 * be worked out, and the law behind it.
 */
export function SavingsPanel({ s }: { s: SavingsReview }) {
    const total = s.total_saving_pence;
    const fines = s.penalties_to_avoid_pence;

    return (
        <section className="space-y-3 rounded-xl border p-4" aria-labelledby={`savings-${s.tax_year}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id={`savings-${s.tax_year}`} className="flex items-center gap-2 font-semibold">
                        <Lightbulb className="size-4 text-amber-500" /> Ways to pay less tax in {s.tax_year}
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Checked against your records
                        {s.marginal_pct !== null ? `. Each extra £1 of allowable cost saves about ${s.marginal_pct}p at your rate` : ''}. Only legal
                        ways Parliament intends.
                    </p>
                </div>
                {(total > 0 || fines > 0) && (
                    <div className="text-right">
                        {total > 0 && (
                            <>
                                <p className="text-xl font-semibold tabular-nums">up to {pounds(total)}</p>
                                <p className="text-muted-foreground text-xs">estimated, if these apply to you</p>
                            </>
                        )}
                        {fines > 0 && (
                            <p className="text-xs text-red-700 tabular-nums dark:text-red-300">Fines and interest so far: {pounds(fines)}</p>
                        )}
                    </div>
                )}
            </div>

            {s.in_progress && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">{s.note}</p>
            )}

            {s.items.length === 0 ? (
                <p className="text-sm">Nothing obvious missing from this year's records.</p>
            ) : (
                <ul className="divide-y text-sm">
                    {s.items.map((i) => (
                        <li key={i.id} className="flex flex-wrap items-baseline gap-x-3 py-2">
                            <span
                                className={cn(
                                    'w-28 shrink-0 text-xs font-medium',
                                    i.status === 'action' && 'text-emerald-700 dark:text-emerald-300',
                                    i.status === 'check' && 'text-amber-700 dark:text-amber-300',
                                    i.status === 'info' && 'text-muted-foreground',
                                )}
                            >
                                {badge[i.status]}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">{i.title ?? i.id}</span>
                                {i.needs_accountant && (
                                    <span className="text-muted-foreground ml-2 inline-flex items-center gap-1 text-xs">
                                        <Scale className="size-3" /> ask an accountant
                                    </span>
                                )}
                                <span className="block">{i.finding}</span>
                                <Law item={i} />
                            </span>
                            {i.saving_pence ? (
                                <span className="text-right tabular-nums">
                                    about {pounds(i.saving_pence)}
                                    <span className="text-muted-foreground block text-xs">estimate</span>
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            {s.more.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer font-medium">Other legal ways to consider ({s.more.length})</summary>
                    <ul className="mt-1 divide-y">
                        {s.more.map((i) => (
                            <li key={i.id} className="py-1.5">
                                <span className="font-medium">{i.title ?? i.id}</span>
                                {i.how && <span className="text-muted-foreground block text-xs">{i.how}</span>}
                                <Law item={i} />
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            <p className="text-muted-foreground text-xs">
                {s.in_progress ? '' : `${s.note} `}
                {s.researched_on ? `Law checked ${s.researched_on}. ` : ''}Ask the assistant about any of these.
            </p>
        </section>
    );
}
