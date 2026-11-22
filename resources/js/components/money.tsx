import { money } from '@/lib/format';
import { cn } from '@/lib/utils';

export function Money({
    pence,
    currency = 'GBP',
    className,
    plus = true,
}: {
    pence: number | null | undefined;
    currency?: string;
    className?: string;
    plus?: boolean;
}) {
    if (pence === null || pence === undefined) return null;
    return (
        <span className={cn('whitespace-nowrap tabular-nums', pence > 0 ? 'text-emerald-700 dark:text-emerald-400' : '', className)}>
            {money(pence, currency, { plus })}
        </span>
    );
}
