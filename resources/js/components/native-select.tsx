import { cn } from '@/lib/utils';
import * as React from 'react';

/** A styled native <select>: fast, accessible and keyboard friendly on phones and desktops. */
export const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, children, ...props }, ref) => (
    <select
        ref={ref}
        className={cn(
            'border-input focus-visible:ring-ring h-9 w-full rounded-md border bg-transparent px-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:opacity-50 dark:bg-neutral-900',
            className,
        )}
        {...props}
    >
        {children}
    </select>
));
NativeSelect.displayName = 'NativeSelect';
