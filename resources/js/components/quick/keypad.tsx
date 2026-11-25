import { Delete } from 'lucide-react';

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'back'];

/**
 * The amount keypad: 1-9, the point, 0 and ⌫, in four rows of 52 px in the thumb zone, so the phone's own keyboard
 * never opens. No point for a currency without decimals (yen). A long press on ⌫ clears it.
 */
export function Keypad({ onKey, decimals }: { onKey: (key: string) => void; decimals: number }) {
    let timer: ReturnType<typeof setTimeout> | null = null;
    const hold = () => {
        timer = setTimeout(() => {
            onKey('clear');
            timer = null;
        }, 600);
    };
    const release = () => {
        if (timer) clearTimeout(timer);
        timer = null;
    };
    return (
        <div className="grid grid-cols-3 gap-1.5" role="group" aria-label="Amount keypad">
            {KEYS.map((key) => {
                if (key === '.' && decimals === 0) return <span key={key} aria-hidden="true" />;
                const label = key === 'back' ? <Delete className="size-6" aria-label="Delete" /> : key;
                return (
                    <button
                        key={key}
                        type="button"
                        className="bg-muted active:bg-accent flex h-[52px] touch-manipulation items-center justify-center rounded-xl text-2xl font-medium tabular-nums select-none"
                        onClick={() => onKey(key)}
                        onPointerDown={key === 'back' ? hold : undefined}
                        onPointerUp={key === 'back' ? release : undefined}
                        onPointerLeave={key === 'back' ? release : undefined}
                        aria-label={key === 'back' ? 'Delete (hold to clear)' : key === '.' ? 'Point' : key}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
