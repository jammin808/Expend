import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Result {
    type: string;
    label: string;
    sub: string;
    href: string;
}

/** One box for everything: payments, jobs, documents, invoices and stays, by name, amount, PO number or reference. Press / to search. */
export function GlobalSearch() {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const typing = e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement || e.target instanceof HTMLSelectElement;
            if ((e.key === '/' && !typing) || (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey))) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (q.trim().length < 2) {
            setResults([]);
            return;
        }
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then((r) => (r.ok ? r.json() : { results: [] }))
                .then((d) => {
                    setResults(d.results ?? []);
                    setActive(0);
                })
                .catch(() => {});
        }, 200);
        return () => {
            clearTimeout(t);
            ctrl.abort();
        };
    }, [q]);

    const go = (r: Result) => {
        setOpen(false);
        setQ('');
        router.visit(r.href);
    };

    return (
        <div className="relative ml-auto w-full max-w-xs">
            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
            <input
                ref={input}
                type="search"
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') setActive(Math.min(active + 1, results.length - 1));
                    else if (e.key === 'ArrowUp') setActive(Math.max(active - 1, 0));
                    else if (e.key === 'Enter' && results[active]) go(results[active]);
                    else if (e.key === 'Escape') (e.target as HTMLInputElement).blur();
                }}
                placeholder="Search payments, jobs, POs, £…"
                aria-label="Search everything"
                role="combobox"
                aria-expanded={open && results.length > 0}
                aria-controls="global-search-results"
                className="border-input bg-background h-9 w-full rounded-md border pr-2 pl-8 text-sm"
            />
            {open && q.trim().length >= 2 && (
                <ul
                    id="global-search-results"
                    role="listbox"
                    className="bg-popover absolute right-0 z-50 mt-1 max-h-[70vh] w-[min(28rem,calc(100vw-2rem))] overflow-y-auto rounded-lg border p-1 text-sm shadow-lg"
                >
                    {results.length === 0 && <li className="text-muted-foreground p-2">Nothing found.</li>}
                    {results.map((r, i) => (
                        <li key={r.type + r.href + i} role="option" aria-selected={i === active}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => go(r)}
                                onMouseEnter={() => setActive(i)}
                                className={`flex w-full flex-col items-start rounded-md px-2 py-1.5 text-left ${i === active ? 'bg-muted' : ''}`}
                            >
                                <span className="text-muted-foreground text-[10px] tracking-wide uppercase">{r.type}</span>
                                <span className="font-medium">{r.label}</span>
                                <span className="text-muted-foreground text-xs">{r.sub}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
