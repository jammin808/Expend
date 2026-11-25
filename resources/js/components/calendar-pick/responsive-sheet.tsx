import { Button } from '@/components/ui/button';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/**
 * A bottom sheet on a phone (most of the screen, a footer that stays put above the keyboard and the home bar, and a
 * close button big enough to hit) and a dialog on a larger screen. Once something in it is changed, a tap outside, Esc
 * or the close button asks before throwing the changes away.
 */
export function ResponsiveSheet({
    open,
    onOpenChange,
    title,
    description,
    dirty = false,
    onDiscard,
    children,
    wide = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    /** Something has been changed in it: closing asks first. */
    dirty?: boolean;
    /** Closing with Discard: throw the changes away. */
    onDiscard?: () => void;
    children: ReactNode;
    wide?: boolean;
}) {
    const mobile = useIsMobile();
    const [asking, setAsking] = useState(false);
    const close = () => {
        if (dirty) setAsking(true);
        else onOpenChange(false);
    };

    return (
        <DialogPrimitive.Root
            open={open}
            onOpenChange={(o) => {
                if (o) onOpenChange(true);
                else close();
            }}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/60" />
                <DialogPrimitive.Content
                    // Without a description, say so (Radix otherwise warns that one is missing).
                    {...(description ? {} : { 'aria-describedby': undefined })}
                    // Radix closes on a tap outside and on Esc: with changes, ask instead.
                    onInteractOutside={(e) => {
                        if (dirty) {
                            e.preventDefault();
                            setAsking(true);
                        }
                    }}
                    onEscapeKeyDown={(e) => {
                        if (dirty) {
                            e.preventDefault();
                            setAsking(true);
                        }
                    }}
                    // A box the keyboard would cover is scrolled up into view once the keyboard is out.
                    onFocusCapture={(e) => {
                        const box = e.target as HTMLElement;
                        if (!mobile || !/^(INPUT|SELECT|TEXTAREA)$/.test(box.tagName)) return;
                        window.setTimeout(() => box.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300);
                    }}
                    className={cn(
                        'bg-background fixed z-50 flex flex-col shadow-lg outline-none',
                        mobile
                            ? 'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 max-h-[92dvh] rounded-t-2xl border-t'
                            : cn(
                                  'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 top-1/2 left-1/2 max-h-[90dvh] w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 rounded-xl border',
                                  wide ? 'max-w-4xl' : 'max-w-2xl',
                              ),
                    )}
                >
                    <div className="flex items-start gap-2 border-b py-2 pr-2 pl-4">
                        <div className="min-w-0 flex-1 py-2">
                            <DialogPrimitive.Title className="text-lg leading-tight font-semibold">{title}</DialogPrimitive.Title>
                            {description && (
                                <DialogPrimitive.Description className="text-muted-foreground mt-1 text-sm">
                                    {description}
                                </DialogPrimitive.Description>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={close}
                            className="hover:bg-muted focus-visible:ring-ring flex size-11 shrink-0 items-center justify-center rounded-full focus-visible:ring-2 focus-visible:outline-none"
                            aria-label="Close"
                        >
                            <X className="size-5" />
                        </button>
                    </div>
                    {children}
                    {asking && (
                        <div className="bg-background/80 absolute inset-0 z-10 flex items-end justify-center p-4 backdrop-blur-sm sm:items-center">
                            <div
                                role="alertdialog"
                                aria-label="Discard changes?"
                                className="bg-background w-full max-w-sm space-y-3 rounded-xl border p-4 shadow-lg"
                            >
                                <p className="font-medium">Discard changes?</p>
                                <p className="text-muted-foreground text-sm">Your picked days are kept. What you changed here is lost.</p>
                                <div className="flex gap-2">
                                    <Button className="h-11 flex-1" variant="outline" onClick={() => setAsking(false)} autoFocus>
                                        Keep editing
                                    </Button>
                                    <Button
                                        className="h-11 flex-1"
                                        variant="destructive"
                                        onClick={() => {
                                            setAsking(false);
                                            onDiscard?.();
                                            onOpenChange(false);
                                        }}
                                    >
                                        Discard
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/** The part of the sheet that scrolls. */
export function SheetBody({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4', className)}>{children}</div>;
}

/** The footer that stays at the bottom of the sheet, above the phone's home bar. */
export function SheetFooter({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('bg-background border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]', className)}>{children}</div>;
}
