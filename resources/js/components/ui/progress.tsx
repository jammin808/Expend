import { cn } from '@/lib/utils';

export function Progress({ value, className, tone = 'emerald' }: { value: number; className?: string; tone?: 'emerald' | 'amber' | 'sky' }) {
    const bar = { emerald: 'bg-emerald-500', amber: 'bg-amber-500', sky: 'bg-sky-500' }[tone];
    return (
        <div className={cn('bg-muted h-2 w-full overflow-hidden rounded-full', className)} role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100}>
            <div className={cn('h-full rounded-full transition-all duration-500', bar)} style={{ width: `${Math.max(0, Math.min(100, value))}%` }} />
        </div>
    );
}
