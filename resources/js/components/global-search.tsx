import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Result {
    type: string;
    label: string;
    sub: string;
    href: string;
}

/** Why there are no results to show: nothing matched, still looking, signed out, or the search itself failed. */
type Status = 'idle' | 'searching' | 'done' | 'signed_out' | 'failed';

/** One box for everything: payments, jobs, documents, invoices and stays, by name, amount, PO number or reference. Press / to search. */
export function GlobalSearch() {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [status, setStatus] = useState<Status>('idle');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    // "/" and Ctrl/⌘+K are this box's, so they're taken before the page sees them (the Inbox would otherwise read the
    // K as "previous payment"): listened for on the way down, and not passed on.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const t = e.target;
            const typing =
                t instanceof HTMLInputElement ||
                t instanceof HTMLTextAreaElement ||
                t instanceof HTMLSelectElement ||
                (t instanceof HTMLElement && t.isContentEditable);
            if ((e.key === '/' && !typing && !e.metaKey && !e.ctrlKey && !e.altKey) || (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey))) {
                e.preventDefault();
                e.stopPropagation();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, []);

    useEffect(() => {
        if (q.trim().length < 2) {
            setResults([]);
            setStatus('idle');
            return;
        }
        setStatus('searching');
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then(async (r) => {
                    // A session that ran out answers 401 (or 419), not with results: say so, rather than "Nothing found".
                    if (r.status === 401 || r.status === 419 || (r.redirected && new URL(r.url).pathname === '/login')) {
                        setResults([]);
                        setStatus('signed_out');
                        return;
                    }
                    if (!r.ok) throw new Error(`Search answered ${r.status}`);
                    const d = await r.json();
                    setResults(d.results ?? []);
                    setActive(0);
                    setStatus('done');
                })
                .catch((e: unknown) => {
                    if (e instanceof DOMException && e.name === 'AbortError') return; // a newer search replaced it
                    setResults([]);
                    setStatus('failed');
                });
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

    // Only "Nothing found." when a search ran and found nothing.
    let message: string | null = null;
    if (status === 'failed') message = 'Search didn’t work just now. Try again in a moment.';
    else if (status !== 'signed_out' && results.length === 0) message = status === 'done' ? 'Nothing found.' : 'Searching…';

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
                    // Keys typed here are for the search, not for the page's own shortcuts (the Inbox's Ctrl+Enter saves).
                    e.stopPropagation();
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
                    {status === 'signed_out' && (
                        <li className="p-2">
                            You’ve been signed out, so nothing was searched.{' '}
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => window.location.reload()}
                                className="font-medium text-sky-700 underline underline-offset-2 dark:text-sky-400"
                            >
                                Reload to sign in
                            </button>
                        </li>
                    )}
                    {message && <li className="text-muted-foreground p-2">{message}</li>}
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
