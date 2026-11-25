import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { type ReactNode, useEffect, useRef } from 'react';

// The history entry a sheet adds is taken back when it closes another way; that Back is the sheet's own, so the page
// (Inertia, which would load it again) never sees it.
let swallowBacks = 0;
if (typeof window !== 'undefined') {
    window.addEventListener(
        'popstate',
        (event) => {
            if (swallowBacks > 0) {
                swallowBacks--;
                event.stopImmediatePropagation();
            }
        },
        true,
    );
}

/** While a sheet is open, Back (Android's button, a swipe back) closes the sheet and stays on the page. */
function useBackCloses(open: boolean, onOpenChange: (open: boolean) => void) {
    const close = useRef(onOpenChange);
    close.current = onOpenChange;
    useEffect(() => {
        if (!open || typeof window === 'undefined') return;
        const id = `${Date.now()}:${Math.random()}`;
        let byBack = false;
        try {
            window.history.pushState({ ...(window.history.state ?? {}), expendSheet: id }, '');
        } catch {
            return;
        }
        const onBack = (event: PopStateEvent) => {
            if (byBack) return;
            byBack = true;
            event.stopImmediatePropagation();
            close.current(false);
        };
        window.addEventListener('popstate', onBack, true);
        return () => {
            window.removeEventListener('popstate', onBack, true);
            if (!byBack && window.history.state?.expendSheet === id) {
                swallowBacks++;
                window.history.back();
            }
        };
    }, [open]);
}

/**
 * A sheet that slides up from the bottom of the phone: within thumb reach, clear of the home bar (safe-area padding),
 * scrolling inside itself when it's tall. Closed by its ✕, by tapping outside, by Escape, or by Back (Android's
 * button, a swipe back), which closes the sheet and stays on the page.
 */
export function BottomSheet({
    open,
    onOpenChange,
    title,
    description,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    useBackCloses(open, onOpenChange);
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Overlay className="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50" />
                <Dialog.Content
                    className="bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom fixed inset-x-0 bottom-0 z-50 mx-auto flex max-h-[85svh] w-full max-w-lg flex-col rounded-t-2xl border-t shadow-lg"
                    style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
                >
                    <div className="flex min-h-14 items-center gap-2 border-b pr-1 pl-4">
                        <Dialog.Title className="flex-1 text-base font-semibold">{title}</Dialog.Title>
                        <Dialog.Close className="text-muted-foreground flex size-12 items-center justify-center rounded-full" aria-label="Close">
                            <X className="size-5" />
                        </Dialog.Close>
                    </div>
                    {description ? (
                        <Dialog.Description className="text-muted-foreground px-4 pt-3 text-sm">{description}</Dialog.Description>
                    ) : (
                        <Dialog.Description className="sr-only">{title}</Dialog.Description>
                    )}
                    <div className="overflow-y-auto overscroll-contain px-4 pt-3 pb-4">{children}</div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

/** A big choice in a sheet: at least 56 px tall, its words and what it does. */
export function SheetChoice({
    selected,
    onClick,
    title,
    detail,
    children,
}: {
    selected?: boolean;
    onClick: () => void;
    title: ReactNode;
    detail?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className={`rounded-xl border ${selected ? 'border-primary ring-primary ring-1' : ''}`}>
            <button
                type="button"
                onClick={onClick}
                className="flex min-h-14 w-full flex-col items-start justify-center px-4 py-2 text-left"
                aria-pressed={selected}
            >
                <span className="text-base font-medium">{title}</span>
                {detail ? <span className="text-muted-foreground text-sm">{detail}</span> : null}
            </button>
            {children}
        </div>
    );
}

/** A 48 px chip button. */
export function Chip({
    selected,
    onClick,
    children,
    className = '',
    tone,
}: {
    selected?: boolean;
    onClick: () => void;
    children: ReactNode;
    className?: string;
    tone?: 'warn';
}) {
    const base = 'inline-flex min-h-12 shrink-0 items-center justify-center rounded-full border px-4 text-sm font-medium select-none';
    const look = selected
        ? 'bg-primary text-primary-foreground border-primary'
        : tone === 'warn'
          ? 'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'
          : 'bg-background';
    return (
        <button type="button" onClick={onClick} aria-pressed={selected} className={`${base} ${look} ${className}`}>
            {children}
        </button>
    );
}
