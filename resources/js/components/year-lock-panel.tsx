import { Button } from '@/components/ui/button';
import { day, money } from '@/lib/format';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, Lock } from 'lucide-react';
import { useState } from 'react';

/** What was kept when a year was last locked (YearChecks::lockShown). */
export interface LockSnapshot {
    at: string;
    actor: string;
    reason: string | null;
    event: number;
    figures: Record<string, number>;
    pending: { unreviewed?: number; suggested?: number; manual_unreviewed?: number };
    export_sha256: string | null;
    findings: { open: number | null; this_year: number };
    checks: Record<string, string>;
    locked: boolean;
}

/** Whether it still holds (YearChecks::verifyLock). */
export interface LockCheck {
    ok: boolean;
    event_ok: boolean;
    chain_ok: boolean;
    figures: Record<string, { then: number; now: number }>;
    export: 'same' | 'changed' | 'missing';
    findings: { then: number | null; now: number; same: boolean };
}

const FIGURES: Record<string, string> = {
    turnover_pence: 'Turnover',
    other_income_pence: 'Other business income',
    allowable_expenses_pence: 'Allowable expenses',
    disallowable_expenses_pence: 'Disallowable expenses',
    mileage_pence: 'Mileage',
    net_profit_pence: 'Profit',
};

/** The year's lock snapshot on its page, with "Check it" and the spreadsheet kept with it. */
export function YearLockPanel({ label, lock, check }: { label: string; lock: LockSnapshot; check: LockCheck | null | undefined }) {
    const [checking, setChecking] = useState(false);
    const failing = Object.entries(lock.checks).filter(([, status]) => status === 'fail').length;
    const pending = (lock.pending.unreviewed ?? 0) + (lock.pending.suggested ?? 0) + (lock.pending.manual_unreviewed ?? 0);
    const verify = () => router.reload({ only: ['lock_check'], onStart: () => setChecking(true), onFinish: () => setChecking(false) });

    return (
        <section className="rounded-xl border p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Lock className="size-4" /> {lock.locked ? 'Locked' : 'Last locked'} on {day(lock.at)}
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        What the year’s figures were then is kept with the lock, in the audit log, with a copy of the spreadsheet.
                        {lock.reason && <> Your reason: “{lock.reason}”.</>}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={verify} disabled={checking}>
                        {checking ? 'Checking…' : 'Check it'}
                    </Button>
                    <Button size="sm" variant="outline" asChild>
                        <a href={`/years/${label}/export?as_locked=1`}>
                            <Download /> Spreadsheet as locked
                        </a>
                    </Button>
                </div>
            </div>
            <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                {Object.entries(FIGURES)
                    .filter(([k]) => lock.figures[k] !== undefined)
                    .map(([k, name]) => (
                        <div key={k} className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">{name}</dt>
                            <dd className="tabular-nums">{money(lock.figures[k], 'GBP', { signed: false })}</dd>
                        </div>
                    ))}
            </dl>
            <p className="text-muted-foreground mt-3 text-xs">
                {pending > 0 ? `${pending} still to decide then, not counted. ` : ''}
                {failing > 0
                    ? `${failing} ${failing === 1 ? 'check' : 'checks'} before filing failing then. `
                    : 'Every check before filing passed or warned. '}
                {lock.findings.open !== null &&
                    `${lock.findings.open} open ${lock.findings.open === 1 ? 'finding' : 'findings'} then, ${lock.findings.this_year} about this year. `}
                {lock.export_sha256 && <span className="break-all">Spreadsheet fingerprint (sha256) {lock.export_sha256.slice(0, 16)}…</span>}
            </p>
            {check && (
                <div
                    className={
                        'mt-3 space-y-1 rounded-md p-3 text-sm ' +
                        (check.ok
                            ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                            : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200')
                    }
                >
                    <p className="flex items-center gap-2 font-medium">
                        {check.ok ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}
                        {check.ok ? 'It holds: nothing has changed since the lock.' : 'Something has changed since the lock.'}
                    </p>
                    <ul className="list-disc space-y-0.5 pl-5 text-xs">
                        <li>
                            {check.event_ok && check.chain_ok
                                ? 'The lock’s record in the audit log is as written, and the log is whole.'
                                : !check.event_ok
                                  ? 'The lock’s record in the audit log has been altered.'
                                  : 'The audit log has been altered somewhere: compare the records with a backup.'}
                        </li>
                        <li>
                            {Object.keys(check.figures).length === 0
                                ? 'The figures are the same now.'
                                : 'Now: ' +
                                  Object.entries(check.figures)
                                      .map(
                                          ([k, d]) =>
                                              `${(FIGURES[k] ?? k).toLowerCase()} ${money(d.now, 'GBP', { signed: false })} (was ${money(d.then, 'GBP', { signed: false })})`,
                                      )
                                      .join('; ') +
                                  '.'}
                        </li>
                        <li>
                            {check.export === 'same'
                                ? 'The spreadsheet kept with the lock is unchanged.'
                                : check.export === 'missing'
                                  ? 'The spreadsheet kept with the lock is missing.'
                                  : 'The spreadsheet kept with the lock has changed since.'}
                        </li>
                        <li>
                            {check.findings.same
                                ? 'The open findings are the same as then.'
                                : `${check.findings.now} open ${check.findings.now === 1 ? 'finding' : 'findings'} now${check.findings.then !== null ? `, ${check.findings.then} then` : ''}: see the to-do list.`}
                        </li>
                    </ul>
                </div>
            )}
        </section>
    );
}
