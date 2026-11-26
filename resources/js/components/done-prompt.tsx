import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money, toPence } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { useId, useState } from 'react';

/** What the Done, Not yet and Cancelled buttons need to know about a job. */
export interface DoneJob {
    id: number;
    title: string;
    ends_on: string;
    status: string;
    cancel_charge?: string | null;
    cancel_fee_pence?: number | null;
}

const weekday = (iso: string) => new Date(iso + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });

/** "Fri 14 Mar", "Fri 14 Mar and Sat 15 Mar", "Thu 13, Fri 14 and Sat 15 Mar" (kept short: the dates in full). */
export function dayList(dates: string[]): string {
    const words = dates.map(weekday);
    return words.length <= 1 ? (words[0] ?? '') : `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

/** The server's message after a press, from the page it answered with. */
function said(page: { props: unknown }): string | null {
    return (page.props as SharedData).flash?.success ?? null;
}

/** A refusal said out loud: the first reason the server gave. */
function refused(errors: Record<string, string>) {
    toast(Object.values(errors)[0] ?? 'That didn’t save. Try again.', 'error');
}

/**
 * Done: the job's marked done and its invoice made ready to check (it stays on this page, whose invoice card then
 * says "Check the invoice"). Days still to come that didn't happen are removed first. The message carries Undo (back
 * to booked; the draft Done made goes only if you haven't touched it).
 */
export function markDone(job: { id: number }, removeDays: string[] = [], onFinish?: () => void) {
    router.post(
        `/jobs/${job.id}/done`,
        { remove_days: removeDays, stay: true },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const message = said(page);
                if (message?.startsWith('Marked done'))
                    toast(message, 'success', {
                        label: 'Undo',
                        run: () => router.post(`/jobs/${job.id}/reopen`, { reason: 'Undo' }, { preserveScroll: true }),
                    });
            },
            onError: refused,
            onFinish,
        },
    );
}

/** Not yet: "All done?" is asked again in 3 days (tied to the job as shown). */
export function notYet(job: { id: number }, fp: string, onFinish?: () => void) {
    router.post(`/todo/job_not_yet/${job.id}`, { fp }, { preserveScroll: true, onError: refused, onFinish });
}

/**
 * "All done?" for a booked job whose last day has finished: Done (its invoice gets ready to check), Not yet (asked again
 * in 3 days) and Cancelled… (what they owe). A day still to come asks whether it finished early first.
 */
export function DonePrompt({
    job,
    daysToCome = [],
    notYetFp,
    className,
}: {
    job: DoneJob;
    daysToCome?: string[];
    notYetFp: string | null;
    className?: string;
}) {
    const [busy, setBusy] = useState(false);
    const [early, setEarly] = useState(false);
    const [cancel, setCancel] = useState(false);
    const done = () => {
        if (daysToCome.length > 0) return setEarly(true);
        setBusy(true);
        markDone(job, [], () => setBusy(false));
    };

    return (
        <section
            aria-label="Is it done?"
            className={cn('space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40', className)}
        >
            <p className="flex gap-2 text-sm">
                <CircleHelp className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" aria-hidden />
                <span>
                    <strong>{job.title}</strong>: last day was {weekday(job.ends_on)}. All done? Marking it done gets its invoice ready to check.
                </span>
            </p>
            <div className="grid grid-cols-2 gap-2 sm:flex">
                <Button className="col-span-2 h-11 sm:px-8" disabled={busy} onClick={done}>
                    Done
                </Button>
                {notYetFp && (
                    <Button
                        variant="outline"
                        className="h-11"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            notYet(job, notYetFp, () => setBusy(false));
                        }}
                    >
                        Not yet
                    </Button>
                )}
                <Button variant="outline" className={cn('h-11', !notYetFp && 'col-span-2')} disabled={busy} onClick={() => setCancel(true)}>
                    Cancelled…
                </Button>
            </div>
            <EarlySheet job={job} days={daysToCome} open={early} onClose={() => setEarly(false)} />
            <CancelSheet job={job} open={cancel} onClose={() => setCancel(false)} />
        </section>
    );
}

/** A small Done for a row (the jobs list, the calendar): for a job whose last day has finished. */
export function DoneButton({ job, className }: { job: { id: number; title: string }; className?: string }) {
    const [busy, setBusy] = useState(false);
    return (
        <Button
            size="sm"
            className={cn('h-11 shrink-0 px-4', className)}
            disabled={busy}
            aria-label={`${job.title} is done`}
            onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                setBusy(true);
                markDone(job, [], () => setBusy(false));
            }}
        >
            Done
        </Button>
    );
}

/** "Did it finish early?": a day still to come is removed (it didn't happen), then the job is marked done. */
export function EarlySheet({ job, days, open, onClose }: { job: DoneJob; days: string[]; open: boolean; onClose: () => void }) {
    const [busy, setBusy] = useState(false);
    const one = days.length === 1;
    return (
        <ResponsiveSheet open={open} onOpenChange={(o) => !o && onClose()} title="Did it finish early?">
            <SheetBody className="space-y-2 text-sm">
                <p>
                    {dayList(days)} {one ? 'is' : 'are'} still to come.
                </p>
                <p className="text-muted-foreground">
                    If it finished early, {one ? 'that day comes' : 'those days come'} off the job and it’s marked done. Its invoice then gets ready
                    to check.
                </p>
            </SheetBody>
            <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                <Button
                    className="h-11"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        markDone(job, days, () => {
                            setBusy(false);
                            onClose();
                        });
                    }}
                >
                    {one ? `Remove ${dayList(days)} and mark done` : 'Remove them and mark done'}
                </Button>
                <Button variant="outline" className="h-11" onClick={onClose}>
                    It isn’t done yet
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}

/** What a cancelled job still owes, in words. */
export function cancelWords(job: DoneJob): string {
    if (job.cancel_charge === 'fee') return `A cancellation fee of ${money(job.cancel_fee_pence ?? 0)}`;
    if (job.cancel_charge === 'costs') return 'Your costs';
    return 'Nothing';
}

/**
 * Cancelled: do they owe you anything? Nothing, a cancellation fee (its amount typed here), or your costs. With a fee
 * or costs, the job keeps (or gets) a draft to check. Cancelling a cancelled job again changes what they owe. Undo
 * puts back what it was.
 */
export function CancelSheet({ job, open, onClose }: { job: DoneJob; open: boolean; onClose: () => void }) {
    const id = useId();
    const [fee, setFee] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const wasCancelled = job.status === 'cancelled';
    const close = () => {
        setFee(null);
        setProblem(null);
        onClose();
    };
    const send = (charge: 'none' | 'fee' | 'costs') => {
        if (charge === 'fee') {
            const pence = toPence(fee ?? '');
            if (pence === null || pence <= 0) return setProblem('Give the cancellation fee as an amount, like 150.00.');
        }
        setBusy(true);
        setProblem(null);
        router.post(
            `/jobs/${job.id}/cancel`,
            { charge, fee: charge === 'fee' ? fee : null },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const message = said(page);
                    close();
                    if (message && !wasCancelled)
                        toast(message, 'success', {
                            label: 'Undo',
                            run: () => router.post(`/jobs/${job.id}/uncancel`, {}, { preserveScroll: true }),
                        });
                },
                onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That didn’t save. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && close()}
            title={wasCancelled ? 'What do they owe you?' : 'Cancelled: do they owe you anything?'}
            description={wasCancelled ? `Now: ${cancelWords(job)}.` : job.title}
        >
            <SheetBody className="space-y-3">
                {fee === null ? (
                    <div className="grid gap-2">
                        {/* The same answers, in the same order, as the to-do list and Telegram. */}
                        <Button variant="outline" className="h-12 justify-start text-left" disabled={busy} onClick={() => send('none')}>
                            No
                        </Button>
                        <Button variant="outline" className="h-12 justify-start text-left" disabled={busy} onClick={() => send('costs')}>
                            Just my costs
                        </Button>
                        <Button variant="outline" className="h-12 justify-start text-left" disabled={busy} onClick={() => setFee('')}>
                            A cancellation fee…
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            With a fee or your costs, its invoice gets ready to check. An invoice already sent stays: void it only if it’s wrong.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-1.5">
                        <Label htmlFor={`${id}-fee`}>Cancellation fee</Label>
                        <div className="relative">
                            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2">£</span>
                            <Input
                                id={`${id}-fee`}
                                autoFocus
                                inputMode="decimal"
                                className="h-11 pl-7"
                                placeholder="150.00"
                                value={fee}
                                onChange={(e) => setFee(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && send('fee')}
                            />
                        </div>
                        <p className="text-muted-foreground text-sm">It goes on the job’s invoice with its costs, ready for you to check.</p>
                    </div>
                )}
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            {fee !== null && (
                <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                    <Button className="h-11" disabled={busy} onClick={() => send('fee')}>
                        Save the fee
                    </Button>
                    <Button variant="outline" className="h-11" disabled={busy} onClick={() => setFee(null)}>
                        Back
                    </Button>
                </SheetFooter>
            )}
        </ResponsiveSheet>
    );
}
