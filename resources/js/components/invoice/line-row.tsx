import { money } from '@/lib/format';
import { FLAG_BADGES, lineWhy } from '@/lib/invoice';
import { cn } from '@/lib/utils';
import { type InvoiceLine } from '@/types/invoices';
import { Check, ChevronRight } from 'lucide-react';

/**
 * One line of a draft: a 44px tick that saves at once, the description and amount (a tap opens the line's sheet), a
 * muted line saying why it's ticked or not, and badges where they apply. A line the sync changed since you looked has a
 * dot; a tick refused because the line changed meanwhile says so and asks again.
 */
export function LineRow({
    line,
    currency,
    client,
    hasTerms,
    changed,
    stale,
    busy,
    onTick,
    onOpen,
}: {
    line: InvoiceLine;
    currency: string;
    client: string | null;
    hasTerms: boolean;
    /** The sync changed it since you last looked. */
    changed: boolean;
    /** Your tick was refused because it changed meanwhile: "Tick it again?". */
    stale: boolean;
    busy: boolean;
    onTick: () => void;
    onOpen: () => void;
}) {
    const badges = [line.own_text ? 'your wording' : null, line.own_amount ? 'your price' : null, line.flag ? FLAG_BADGES[line.flag] : null].filter(
        (b): b is string => !!b,
    );
    const blocking = line.included && (line.flag === 'gone' || line.flag === 'no_amount' || (line.flag === 'check' && !line.own_amount));

    return (
        <li id={`line-${line.id}`} className={cn('flex scroll-mt-24 items-stretch gap-1', !line.included && 'text-muted-foreground')}>
            <button
                type="button"
                role="checkbox"
                aria-checked={line.included}
                aria-label={line.included ? `On the invoice: ${line.description}` : `Left off: ${line.description}`}
                disabled={busy}
                onClick={onTick}
                className="focus-visible:ring-ring flex min-h-11 w-11 shrink-0 items-start justify-center pt-2.5 focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50"
            >
                <span
                    className={cn(
                        'flex size-6 items-center justify-center rounded-md border-2',
                        line.included ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/50 bg-background',
                    )}
                >
                    {line.included && <Check className="size-4" strokeWidth={3} />}
                </span>
            </button>
            <button
                type="button"
                onClick={onOpen}
                className="hover:bg-muted/50 focus-visible:ring-ring flex min-h-11 min-w-0 flex-1 items-start gap-2 rounded-md py-2 pr-1 text-left focus-visible:ring-2 focus-visible:outline-none"
            >
                <span className="min-w-0 flex-1">
                    <span className="flex items-start gap-1.5">
                        {changed && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-sky-500" aria-label="Changed since you looked" />}
                        <span className={cn('min-w-0 text-sm break-words', line.included && 'text-foreground font-medium')}>{line.description}</span>
                    </span>
                    <span className="text-muted-foreground mt-0.5 block text-xs">
                        {stale ? (
                            <span className="text-amber-700 dark:text-amber-400">This changed since you looked: tick it again?</span>
                        ) : (
                            lineWhy(line, client, hasTerms)
                        )}
                    </span>
                    {badges.length > 0 && (
                        <span className="mt-1 flex flex-wrap gap-1">
                            {badges.map((b) => (
                                <span
                                    key={b}
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        blocking && FLAG_BADGES[line.flag ?? ''] === b
                                            ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                            : 'bg-muted text-neutral-700 dark:text-neutral-300',
                                    )}
                                >
                                    {b}
                                </span>
                            ))}
                        </span>
                    )}
                </span>
                <span className={cn('shrink-0 pt-0.5 text-sm tabular-nums', line.included ? 'font-medium' : 'line-through decoration-1')}>
                    {line.flag === 'no_amount' && line.amount_minor === 0 ? '—' : money(line.amount_minor, currency)}
                </span>
                <ChevronRight className="text-muted-foreground mt-0.5 size-4 shrink-0" />
            </button>
        </li>
    );
}
