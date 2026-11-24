import { RunStatus, timeOf, type RunRow } from '@/components/intel-run';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState, type ReactNode } from 'react';

type Mode = 'off' | 'shadow' | 'on';

interface FoundItem {
    key: string;
    title: string;
    href: string | null;
    amount: number | null;
    currency: string;
    direction: string | null;
    grade: string | null;
    severity: 'critical' | 'important' | 'info';
}

interface MoneyTotal {
    direction: string;
    currency: string;
    pence: number;
    words: string;
}

interface Report {
    at: string;
    since: string;
    mode: 'on' | 'shadow';
    quiet: boolean;
    line: string;
    found?: { count: number; money: MoneyTotal[]; items: FoundItem[]; below_fold?: number; below_items?: FoundItem[] };
    did?: { items: { action: string; count: number; text: string }[] };
    dealt?: {
        count: number;
        by: Partial<Record<'import' | 'you' | 'automatic' | 'records', number>>;
        items: { key: string; title: string; href: string | null; amount: number | null; currency: string; cause: string }[];
    };
    needs?: { total: number; groups: { group: string; label: string; count: number }[] };
    health?: { lines?: { level: 'critical' | 'important' | 'info'; text: string }[]; ai?: { spent_usd: number; allowance_usd: number } };
}

interface Props {
    mode: Mode;
    report: Report | null;
    reportRun: RunRow | null;
    runs: RunRow[];
    checking: boolean;
}

const MODE_WORDS: Record<Mode, string> = {
    off: 'The nightly check is off: the usual nightly work runs as before.',
    shadow: 'The nightly check is on trial: it only looks, and says what it would do. The usual nightly work still does the work.',
    on: 'The nightly check is on: it does the nightly work and sends this report each morning.',
};

const CAUSE_WORDS: Record<string, string> = { import: 'by an import', you: 'by you', automatic: 'automatically', records: 'as the records changed' };

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2 rounded-xl border p-4">
            <h2 className="font-semibold">{title}</h2>
            {children}
        </section>
    );
}

export default function Intel({ mode, report, reportRun, runs, checking }: Props) {
    const [busy, setBusy] = useState(false);
    const checkNow = () => {
        setBusy(true);
        router.post('/intel/check', {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'To do', href: '/todo' },
                { title: 'Overnight', href: '/intel' },
            ]}
        >
            <Head title="Overnight" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">What Expend found and did overnight</h1>
                        <p className="text-muted-foreground text-sm">{MODE_WORDS[mode]}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/intel/runs">Run log</Link>
                        </Button>
                        <Button onClick={checkNow} disabled={busy || checking || mode === 'off'}>
                            <RefreshCw className={cn(checking && 'animate-spin')} /> {checking ? 'Checking…' : 'Check now'}
                        </Button>
                    </div>
                </div>

                {!report ? (
                    <p className="text-muted-foreground rounded-xl border p-4 text-sm">
                        No report yet. Once the nightly check runs, what it found and did appears here each morning.
                    </p>
                ) : (
                    <>
                        <div className="space-y-1">
                            <p className="text-lg">{report.line}</p>
                            <p className="text-muted-foreground text-xs">
                                Since {timeOf(report.since)} · made {timeOf(report.at)}
                                {report.mode === 'shadow' && <Badge className="ml-2">Trial: nothing was changed or sent</Badge>}
                            </p>
                        </div>

                        {report.found && report.found.count > 0 && (
                            <Section title={`Found (${report.found.count} new)`}>
                                {report.found.money.length > 0 && (
                                    <p className="text-sm">
                                        {report.found.money.map((m, i) => (
                                            <span key={m.direction + m.currency}>
                                                {i > 0 && ' · '}
                                                {m.words} <span className="font-medium tabular-nums">{money(m.pence, m.currency)}</span>
                                            </span>
                                        ))}
                                    </p>
                                )}
                                <ul className="space-y-1.5 text-sm">
                                    {report.found.items.map((f) => (
                                        <li key={f.key} className="flex items-start gap-2">
                                            <span
                                                className={cn(
                                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                                    f.severity === 'critical'
                                                        ? 'bg-rose-500'
                                                        : f.severity === 'important'
                                                          ? 'bg-amber-500'
                                                          : 'bg-slate-400',
                                                )}
                                            />
                                            <Link href={f.href ?? '/todo'} className="min-w-0 hover:underline">
                                                {f.title}
                                                {f.amount !== null && f.direction && f.direction !== 'none' && (
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {' '}
                                                        · {money(Math.abs(f.amount), f.currency)}
                                                    </span>
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                {report.found.count > report.found.items.length && (
                                    <Link href="/todo" className="text-sm underline underline-offset-2">
                                        And {report.found.count - report.found.items.length} more on the to-do list
                                    </Link>
                                )}
                            </Section>
                        )}

                        {report.found?.below_fold ? (
                            <Section title={`Also found by new or turned-down checks (${report.found.below_fold})`}>
                                <p className="text-muted-foreground text-sm">
                                    These checks are shown below the fold on the to-do list and kept off Telegram until you raise them there.
                                </p>
                                <ul className="text-muted-foreground space-y-1 text-sm">
                                    {(report.found.below_items ?? []).map((f) => (
                                        <li key={f.key}>
                                            <Link href={f.href ?? '/todo'} className="hover:underline">
                                                {f.title}
                                                {f.amount !== null && f.direction && f.direction !== 'none' && (
                                                    <span className="tabular-nums"> · {money(Math.abs(f.amount), f.currency)}</span>
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                {report.found.below_fold > (report.found.below_items ?? []).length && (
                                    <Link href="/todo" className="text-sm underline underline-offset-2">
                                        And {report.found.below_fold - (report.found.below_items ?? []).length} more on the to-do list
                                    </Link>
                                )}
                            </Section>
                        ) : null}

                        {report.did && (
                            <Section title="Did on its own">
                                <ul className="list-disc space-y-1 pl-5 text-sm">
                                    {report.did.items.map((d) => (
                                        <li key={d.action}>{d.text.charAt(0).toUpperCase() + d.text.slice(1)}</li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        {report.dealt && (
                            <Section title={`Dealt with (${report.dealt.count})`}>
                                <ul className="space-y-1 text-sm">
                                    {report.dealt.items.map((d) => (
                                        <li key={d.key}>
                                            <Link href={d.href ?? '/todo/history'} className="hover:underline">
                                                {d.title}
                                            </Link>{' '}
                                            <span className="text-muted-foreground">{CAUSE_WORDS[d.cause] ?? ''}</span>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        {report.needs && (
                            <Section title={`Needs you (${report.needs.total})`}>
                                <ul className="grid gap-1 text-sm sm:grid-cols-2">
                                    {report.needs.groups.map((g) => (
                                        <li key={g.group}>
                                            <Link href={`/todo#${g.group}`} className="hover:underline">
                                                {g.label}: <span className="tabular-nums">{g.count}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        {report.health && (
                            <Section title="Health">
                                <ul className="space-y-1 text-sm">
                                    {(report.health.lines ?? []).map((l, i) => (
                                        <li key={i} className={cn(l.level === 'critical' && 'font-medium text-rose-700 dark:text-rose-400')}>
                                            {l.text}
                                        </li>
                                    ))}
                                    {report.health.ai && (
                                        <li className="text-muted-foreground">
                                            AI overnight: ${report.health.ai.spent_usd.toFixed(2)} of ${report.health.ai.allowance_usd.toFixed(2)}
                                        </li>
                                    )}
                                </ul>
                            </Section>
                        )}
                        {reportRun && (
                            <p className="text-muted-foreground text-xs">
                                From {reportRun.kind_words.toLowerCase()} {day(reportRun.started_at)}
                                {reportRun.reported_at ? `, sent to Telegram ${timeOf(reportRun.reported_at)}` : ''}.
                            </p>
                        )}
                    </>
                )}

                {runs.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-muted-foreground text-sm font-medium tracking-wide uppercase">Latest runs</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {runs.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                    <span>
                                        {r.kind_words}
                                        {r.mode === 'shadow' && <span className="text-muted-foreground"> (trial)</span>}
                                        <span className="text-muted-foreground"> · {timeOf(r.started_at)}</span>
                                    </span>
                                    <RunStatus status={r.status} words={r.status_words} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
