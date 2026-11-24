import { RunStatus, timeOf, type RunRow } from '@/components/intel-run';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Step {
    step: string;
    words: string;
    stage: string;
    status: string;
    status_words: string;
    attempts: number;
    coverage: string | null;
    detector_version: number | null;
    counts: Record<string, unknown> | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
}

interface Props {
    mode: 'off' | 'shadow' | 'on';
    page: { current_page: number; last_page: number; links: { prev: string | null; next: string | null } };
    runs: (RunRow & { steps: Step[] })[];
}

const STEP_TONE: Record<string, string> = {
    ok: 'text-emerald-700 dark:text-emerald-400',
    failed: 'text-rose-700 dark:text-rose-400',
    budget: 'text-amber-700 dark:text-amber-400',
    more: 'text-sky-700 dark:text-sky-400',
    running: 'text-sky-700 dark:text-sky-400',
};

/** A step's counts in a line: numbers and yes or no, leaving out the lists. */
function countsText(counts: Record<string, unknown> | null): string {
    if (!counts) return '';
    return Object.entries(counts)
        .filter(([k, v]) => k !== 'skipped' && (typeof v === 'number' || typeof v === 'boolean'))
        .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${typeof v === 'boolean' ? (v ? 'yes' : 'no') : v}`)
        .join(', ');
}

export default function IntelRuns({ mode, page, runs }: Props) {
    const [open, setOpen] = useState<number | null>(runs[0]?.id ?? null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Overnight', href: '/intel' },
                { title: 'Run log', href: '/intel/runs' },
            ]}
        >
            <Head title="Run log" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Run log</h1>
                    <p className="text-muted-foreground text-sm">
                        Every run of the nightly check and each of its steps: what it did, how far it got and why it stopped.
                        {mode === 'off' && ' The nightly check is off.'}
                        {mode === 'shadow' && ' It’s on trial: steps that would change records are skipped.'}
                    </p>
                </div>

                {runs.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border p-4 text-sm">No runs yet.</p>
                ) : (
                    <ul className="space-y-3">
                        {runs.map((r) => (
                            <li key={r.id} className="rounded-xl border">
                                <button
                                    type="button"
                                    onClick={() => setOpen(open === r.id ? null : r.id)}
                                    className="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left"
                                    aria-expanded={open === r.id}
                                >
                                    <span className="min-w-0">
                                        <span className="font-medium">{r.kind_words}</span>
                                        {r.mode === 'shadow' && <span className="text-muted-foreground"> (trial)</span>}
                                        <span className="text-muted-foreground text-sm">
                                            {' '}
                                            · {timeOf(r.started_at)}
                                            {r.finished_at ? ` to ${timeOf(r.finished_at)}` : ''}
                                            {r.ai_budget_usd > 0 ? ` · AI $${r.ai_spent_usd.toFixed(2)} of $${r.ai_budget_usd.toFixed(2)}` : ''}
                                            {r.sealed ? ' · sealed in the audit log' : ''}
                                        </span>
                                    </span>
                                    <RunStatus status={r.status} words={r.status_words} />
                                </button>
                                {open === r.id && (
                                    <div className="overflow-x-auto border-t">
                                        <table className="w-full text-sm">
                                            <thead className="text-muted-foreground text-left text-xs">
                                                <tr>
                                                    <th className="px-4 py-2 font-medium">Step</th>
                                                    <th className="px-4 py-2 font-medium">Result</th>
                                                    <th className="px-4 py-2 font-medium">What it did</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {r.steps.map((s) => (
                                                    <tr key={s.step} className="align-top">
                                                        <td className="px-4 py-2">
                                                            <div>{s.words.charAt(0).toUpperCase() + s.words.slice(1)}</div>
                                                            <div className="text-muted-foreground font-mono text-xs">{s.step}</div>
                                                        </td>
                                                        <td className={cn('px-4 py-2 whitespace-nowrap', STEP_TONE[s.status])}>
                                                            {s.status_words}
                                                            {s.attempts > 1 && <span className="text-muted-foreground"> ({s.attempts} tries)</span>}
                                                            {s.coverage && s.coverage !== 'complete' && (
                                                                <div className="text-muted-foreground text-xs">
                                                                    {s.coverage === 'capped' ? 'Didn’t look at everything' : 'Couldn’t work it out'}
                                                                </div>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <div className="text-muted-foreground">{countsText(s.counts)}</div>
                                                            {s.error && <div className="text-xs text-rose-700 dark:text-rose-400">{s.error}</div>}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {page.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <Button variant="outline" disabled={!page.links.prev} onClick={() => page.links.prev && router.get(page.links.prev)}>
                            Newer
                        </Button>
                        <span className="text-muted-foreground text-sm">
                            Page {page.current_page} of {page.last_page}
                        </span>
                        <Button variant="outline" disabled={!page.links.next} onClick={() => page.links.next && router.get(page.links.next)}>
                            Older
                        </Button>
                    </div>
                )}
                <Link href="/intel" className="text-sm underline underline-offset-2">
                    Back to the report
                </Link>
            </div>
        </AppLayout>
    );
}
