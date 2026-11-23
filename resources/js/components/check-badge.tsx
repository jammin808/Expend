import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { RefreshCw, ShieldAlert, ShieldCheck, ShieldQuestion } from 'lucide-react';
import { useState } from 'react';

export interface Check {
    status: string;
    label: string;
    warn: boolean;
    summary: string;
    method: string;
    checked_at: string;
    checks: { what: string; ok: boolean | null; note: string | null }[];
}

const tone = (c: Check) =>
    c.status === 'verified'
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
        : c.status === 'second_read_agreed'
          ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'
          : c.status === 'unverifiable'
            ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
            : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300';

/**
 * How an AI finding stood up to being checked again against its source. Click to see each check; "Re-check" runs
 * it again now (a scan or photo gets a second, independent read).
 */
export function CheckBadge({
    check,
    subject,
    className,
    full = false,
}: {
    check: Check | null;
    subject?: { type: string; id: number };
    className?: string;
    /** Show passed checks too (on a document's own page). Elsewhere only what needs a look is shown. */
    full?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const recheck = () =>
        subject &&
        router.post(
            `/checks/${subject.type}/${subject.id}`,
            {},
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    if (!full && (!check || !check.warn)) return null;
    if (!check) {
        return subject ? (
            <button
                type="button"
                onClick={recheck}
                disabled={busy}
                className={cn('text-muted-foreground inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline', className)}
            >
                <ShieldQuestion className="size-3.5" /> {busy ? 'Checking…' : 'Not double-checked yet: check now'}
            </button>
        ) : null;
    }
    const Icon = check.status === 'verified' || check.status === 'second_read_agreed' ? ShieldCheck : check.warn ? ShieldAlert : ShieldQuestion;

    return (
        <span className={cn('inline-flex flex-col gap-1', className)}>
            <span className="inline-flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    title={check.summary}
                    className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', tone(check))}
                >
                    <Icon className="size-3.5" /> {check.label}
                </button>
                {subject && (
                    <button
                        type="button"
                        onClick={recheck}
                        disabled={busy}
                        className="text-muted-foreground inline-flex items-center gap-1 text-xs hover:underline"
                    >
                        <RefreshCw className={cn('size-3', busy && 'animate-spin')} /> {busy ? 'Checking…' : 'Re-check'}
                    </button>
                )}
            </span>
            {open && (
                <span className="bg-muted/40 block rounded-md border p-2 text-xs">
                    <span className="block">{check.summary}</span>
                    {check.checks.length > 0 && (
                        <ul className="mt-1 space-y-0.5">
                            {check.checks.map((c, i) => (
                                <li key={i}>
                                    {c.ok === true ? '✓' : c.ok === false ? '✗' : '–'} {c.what}
                                    {c.note ? <span className="text-muted-foreground"> · {c.note}</span> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                    <span className="text-muted-foreground mt-1 block">
                        Checked {new Date(check.checked_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}
                    </span>
                </span>
            )}
        </span>
    );
}
