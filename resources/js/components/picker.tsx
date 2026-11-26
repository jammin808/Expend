import { cn } from '@/lib/utils';
import { Check, ChevronDown } from 'lucide-react';
import { type KeyboardEvent, type ReactNode, useEffect, useRef, useState } from 'react';

/**
 * The parts the client and job pickers share: a big button showing what's picked, which opens a search box with a list
 * under it (44 px rows, one thumb). Nothing is picked until a row is tapped: closing it (Esc, a tap elsewhere) picks
 * nothing.
 */
export function PickerTrigger({
    id,
    open,
    disabled,
    onOpen,
    placeholder,
    children,
    className,
}: {
    id?: string;
    open: boolean;
    disabled?: boolean;
    onOpen: () => void;
    placeholder: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <button
            id={id}
            type="button"
            disabled={disabled}
            aria-haspopup="listbox"
            aria-expanded={open}
            onClick={onOpen}
            className={cn(
                'border-input focus-visible:ring-ring flex min-h-11 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-left text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:opacity-50 dark:bg-neutral-900',
                className,
            )}
        >
            <span className={cn('min-w-0 flex-1 truncate', !children && 'text-muted-foreground')}>{children ?? placeholder}</span>
            <ChevronDown className="text-muted-foreground size-4 shrink-0" />
        </button>
    );
}

/** One row of a picker's list. */
export function PickerOption({
    id,
    active,
    selected,
    onPick,
    onHover,
    children,
    className,
}: {
    id: string;
    active: boolean;
    selected?: boolean;
    onPick: () => void;
    onHover?: () => void;
    children: ReactNode;
    className?: string;
}) {
    return (
        <li
            id={id}
            role="option"
            aria-selected={!!selected}
            // A tap, not a focus change: the search box keeps the keyboard.
            onMouseDown={(e) => e.preventDefault()}
            onClick={onPick}
            onMouseEnter={onHover}
            className={cn('flex min-h-11 cursor-pointer items-center gap-2 px-3 py-2 text-sm', active ? 'bg-muted' : 'hover:bg-muted/60', className)}
        >
            <span className="min-w-0 flex-1">{children}</span>
            {selected && <Check className="size-4 shrink-0 text-emerald-600" />}
        </li>
    );
}

/** A group heading inside a picker's list. */
export function PickerGroup({ children }: { children: ReactNode }) {
    return (
        <li role="presentation" className="text-muted-foreground bg-muted/40 px-3 py-1.5 text-xs font-medium">
            {children}
        </li>
    );
}

/**
 * Open and close, a tap outside closing it (picking nothing), and the arrow keys and Enter over the rows. `count` is
 * how many rows can be picked; `pick(i)` picks row i.
 */
export function usePicker(count: number, pick: (i: number) => void) {
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const outside = (e: PointerEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('pointerdown', outside);
        return () => document.removeEventListener('pointerdown', outside);
    }, [open]);

    useEffect(() => {
        setActive((a) => Math.min(a, Math.max(0, count - 1)));
    }, [count]);

    const onKeyDown = (e: KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, count - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (count > 0) pick(active);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
        }
    };

    return { open, setOpen, active, setActive, onKeyDown, box };
}
