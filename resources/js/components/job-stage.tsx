import { cn } from '@/lib/utils';
import { type JobStageInfo, type StageKey } from '@/types/clients';

const needsYou = 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
const late = 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200';
const good = 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200';
const working = 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200';
// Grey, with dark enough words to read at pill size (4.5:1 at least).
const quiet = 'bg-muted text-neutral-700 dark:text-neutral-300';

/** How each stage looks: amber when it needs you, red when it's late, green once paid, grey when there's nothing to do. */
const TONES: Record<StageKey, string> = {
    upcoming: quiet,
    under_way: working,
    done_due: needsYou,
    not_done: needsYou,
    done_no_draft: needsYou,
    draft: working,
    waiting_payment: quiet,
    not_sent: needsYou,
    sent: quiet,
    overdue: late,
    part_paid: needsYou,
    paid: good,
    closed: quiet,
    cancelled_to_invoice: needsYou,
    cancelled: quiet,
};

/** Whether a stage asks something of you now (a "Done?", a draft to check, an invoice to send…). */
export function stageNeedsYou(stage: StageKey | null | undefined): boolean {
    return !!stage && (TONES[stage] === needsYou || TONES[stage] === late || stage === 'draft');
}

/**
 * Where a job is, in one chip, in the server's words (JobStage): "Starts Fri 12 Dec", "Done?", "Draft · 2 changes",
 * "In Gmail drafts", "Due 25 Dec", "Overdue 3 days", "Part paid £450 of £900", "Paid", "Cancelled · to invoice".
 */
export function StageChip({ stage, className }: { stage: JobStageInfo | null | undefined; className?: string }) {
    if (!stage) return null;
    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center rounded-full px-2 py-0.5 text-xs font-medium break-words',
                TONES[stage.stage] ?? quiet,
                className,
            )}
        >
            {stage.chip}
        </span>
    );
}
