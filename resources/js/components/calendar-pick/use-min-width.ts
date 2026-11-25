import { useEffect, useState } from 'react';

/** Whether the window is at least this wide (two months side by side from 1024 pixels). */
export function useMinWidth(px: number): boolean {
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia(`(min-width: ${px}px)`).matches);
    useEffect(() => {
        const mql = window.matchMedia(`(min-width: ${px}px)`);
        const change = () => setWide(mql.matches);
        change();
        mql.addEventListener('change', change);
        return () => mql.removeEventListener('change', change);
    }, [px]);
    return wide;
}
