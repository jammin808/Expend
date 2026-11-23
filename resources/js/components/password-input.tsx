import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import * as React from 'react';

/**
 * A password box with a show/hide button. While shown it's a plain text box, so a phone's password
 * manager ("Use strong password?") can't keep popping up over it and you can always just type.
 */
export const PasswordInput = React.forwardRef<HTMLInputElement, Omit<React.ComponentProps<'input'>, 'type'> & { defaultShown?: boolean }>(
    ({ className, defaultShown = false, ...props }, ref) => {
        const [shown, setShown] = React.useState(defaultShown);

        return (
            <div className="relative">
                <Input
                    ref={ref}
                    type={shown ? 'text' : 'password'}
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    className={cn('pr-11', className)}
                    {...props}
                />
                <button
                    type="button"
                    onClick={() => setShown((v) => !v)}
                    className="text-muted-foreground hover:text-foreground absolute inset-y-0 right-0 flex w-11 items-center justify-center"
                    aria-label={shown ? 'Hide password' : 'Show password'}
                    aria-pressed={shown}
                >
                    {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
            </div>
        );
    },
);
PasswordInput.displayName = 'PasswordInput';
