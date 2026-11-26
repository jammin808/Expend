import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AlertTriangle, CircleHelp, ExternalLink } from 'lucide-react';
import { useRef, useState, type SyntheticEvent } from 'react';

interface Guide {
    code?: string;
    slug?: string;
    title: string;
    allowable?: 'yes' | 'no' | 'partly' | 'capital';
    summary: string;
    rules?: string[];
    examples_allowed?: string[];
    examples_not_allowed?: string[];
    evidence_to_keep?: string[];
    watch_out?: string[];
    sources?: { title: string; url: string }[];
    by_year?: Record<string, string>;
}
interface Explanation {
    title: string;
    what?: string;
    status?: string;
    notes?: string[];
    category: Guide | null;
    topics?: Guide[];
    box?: string | null;
    allowable?: boolean;
    researched_on: string | null;
}

const SIGNED_OUT = 'You’ve been signed out. Reload the page to sign in again.';
const FAILED = 'Couldn’t load the explanation. Try again in a moment.';

const ALLOWABLE: Record<string, { label: string; tone: string }> = {
    yes: { label: 'Allowable', tone: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
    partly: { label: 'Allowable in some cases', tone: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300' },
    capital: { label: 'Capital item', tone: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' },
    no: { label: 'Not allowable', tone: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300' },
};

/**
 * "?": what this is, whether HMRC normally allows it and when, what to keep, and notes from its own context. Rules come
 * from the tax guide (GOV.UK and HMRC manuals) with their sources.
 */
export function Explain({ url, label = 'What is this, and can I claim it?', className }: { url: string; label?: string; className?: string }) {
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<Explanation | null>(null);
    const [error, setError] = useState<string | null>(null);
    const asked = useRef(0);

    // Asked afresh each time it opens: deciding the payment since changes what it says and which HMRC rules apply.
    const load = () => {
        setOpen(true);
        const n = ++asked.current;
        setData(null);
        setError(null);
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (r.status === 401 || r.status === 419) throw new Error(SIGNED_OUT);
                if (!r.ok) throw new Error(FAILED);
                return (await r.json()) as Explanation;
            })
            .then((d) => {
                if (n === asked.current) setData(d);
            })
            .catch((e: unknown) => {
                if (n === asked.current) setError(e instanceof Error && e.message === SIGNED_OUT ? SIGNED_OUT : FAILED);
            });
    };

    // The dialog is drawn outside the page, but React passes its clicks and key presses up through whatever holds the
    // "?" (a table row that opens a payment, the Inbox's shortcuts). Keep them in here. Stopping the click also stops
    // it reaching the dialog's own outside-tap check, which on a touch screen waits for the click, so a tap on the
    // backdrop closes it here.
    const keepInside = (e: SyntheticEvent) => e.stopPropagation();
    const closeOnBackdrop = (e: SyntheticEvent) => {
        e.stopPropagation();
        if (!(e.target instanceof Element && e.target.closest('[role="dialog"]'))) setOpen(false);
    };

    return (
        <>
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    load();
                }}
                aria-label={label}
                title={label}
                className={cn('text-muted-foreground hover:text-foreground -m-3 inline-flex shrink-0 items-center justify-center p-3', className)}
            >
                <CircleHelp className="size-4" />
            </button>
            <span className="contents" onClick={closeOnBackdrop} onDoubleClick={keepInside} onKeyDown={keepInside}>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{data?.title ?? 'Explanation'}</DialogTitle>
                        </DialogHeader>
                        {error && <p className="text-sm text-rose-600">{error}</p>}
                        {!data && !error && <p className="text-muted-foreground text-sm">Loading…</p>}
                        {data && <ExplanationBody e={data} />}
                    </DialogContent>
                </Dialog>
            </span>
        </>
    );
}

function ExplanationBody({ e }: { e: Explanation }) {
    return (
        <div className="space-y-4 text-sm">
            {e.what && <p>{e.what}</p>}
            {e.status && <p className="text-muted-foreground">{e.status}</p>}
            {e.box !== undefined && (
                <p className="text-muted-foreground">
                    {e.box ? `Self Assessment box ${e.box}. ` : ''}
                    {e.allowable === false ? 'Recorded, but not allowable for tax.' : ''}
                </p>
            )}
            {!!e.notes?.length && (
                <ul className="space-y-1.5">
                    {e.notes.map((n, i) => (
                        <li key={i} className="flex gap-2 rounded-md border border-amber-300 p-2 dark:border-amber-800">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" /> {n}
                        </li>
                    ))}
                </ul>
            )}
            {e.category ? (
                <GuideBlock g={e.category} />
            ) : (
                e.what && <p className="text-muted-foreground">Choose a category to see HMRC’s rules for it.</p>
            )}
            {e.topics?.map((t) => (
                <details key={t.slug} className="rounded-lg border p-3">
                    <summary className="cursor-pointer font-medium">{t.title}</summary>
                    <div className="mt-2">
                        <GuideBlock g={t} compact />
                    </div>
                </details>
            ))}
            <p className="text-muted-foreground border-t pt-3 text-xs">
                General guidance from GOV.UK and HMRC’s manuals{e.researched_on ? `, checked ${e.researched_on}` : ''}, not personal advice. Points
                marked “Ask an accountant” need one.{' '}
                <Link href="/tax-guide" className="underline underline-offset-2">
                    Tax guide
                </Link>
                {' · '}
                <Link href="/assistant" className="underline underline-offset-2">
                    Ask the assistant
                </Link>
            </p>
        </div>
    );
}

export function GuideBlock({ g, compact = false }: { g: Guide; compact?: boolean }) {
    const list = (title: string, items?: string[]) =>
        items?.length ? (
            <div>
                <p className="font-medium">{title}</p>
                <ul className="text-muted-foreground list-disc space-y-0.5 pl-5">
                    {items.map((x, i) => (
                        <li key={i}>{x}</li>
                    ))}
                </ul>
            </div>
        ) : null;

    return (
        <div className="space-y-2">
            {!compact && (
                <p className="flex flex-wrap items-center gap-2 font-medium">
                    {g.title}
                    {g.allowable && (
                        <span className={cn('rounded-full px-2 py-0.5 text-xs', ALLOWABLE[g.allowable]?.tone)}>{ALLOWABLE[g.allowable]?.label}</span>
                    )}
                </p>
            )}
            <p>{g.summary}</p>
            {list('Rules', g.rules)}
            {list('Usually allowed', g.examples_allowed)}
            {list('Not allowed', g.examples_not_allowed)}
            {list('Keep as evidence', g.evidence_to_keep)}
            {list('Watch out', g.watch_out)}
            {g.by_year && (
                <div>
                    <p className="font-medium">By tax year</p>
                    <ul className="text-muted-foreground list-disc space-y-0.5 pl-5">
                        {Object.entries(g.by_year).map(([y, t]) => (
                            <li key={y}>
                                <strong>{y}</strong>: {t}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {!!g.sources?.length && (
                <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                    {g.sources.map((s) => (
                        <a
                            key={s.url}
                            href={s.url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sky-700 underline underline-offset-2 dark:text-sky-400"
                        >
                            {s.title} <ExternalLink className="size-3" />
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}
