import { toast } from '@/components/toaster';
import { cn } from '@/lib/utils';
import { type ResolvedRef, type Why, type WhyHistoryEvent, type WhyItem, type WhySaidNo } from '@/types/intel';
import { router } from '@inertiajs/react';
import { AlertTriangle, Ban, ChevronDown, ChevronRight, HelpCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const gradeWords: Record<string, string> = {
    certain: 'Certain',
    strong: 'Strong',
    likely: 'Likely',
    possible: 'Possible',
    weak: 'Weak',
    unmeasured: 'Not measured yet',
};

const gradeTone: Record<string, string> = {
    certain: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    strong: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    likely: 'bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    possible: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    weak: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    unmeasured: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

/**
 * "Why?" for a line's decision or an automatic link (GET /why?ref=…): who made it and how, the evidence it relied on,
 * the runner-up it beat, and its history with the knock-on changes made at the same time. Fetched when opened. It also
 * lists what you said no to about it ("Not this one"), each with Lift: the only thing it changes.
 */
export function WhyPanel({ refId, label = 'Why?', className }: { refId: string; label?: string; className?: string }) {
    const [open, setOpen] = useState(false);
    const [why, setWhy] = useState<Why | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setWhy(null);
        setFailed(false);
    }, [refId]);

    useEffect(() => {
        if (!open || why) return;
        let live = true;
        fetch(`/why?ref=${encodeURIComponent(refId)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d: Why) => live && setWhy(d))
            .catch(() => live && setFailed(true));
        return () => {
            live = false;
        };
    }, [open, refId, why]);

    return (
        <div className={cn('text-sm', className)}>
            <button
                type="button"
                className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-medium"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
            >
                {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                <HelpCircle className="size-3.5" />
                {label}
            </button>
            {open && (
                <div className="mt-2 space-y-4 rounded-lg border p-3">
                    {failed && <p className="text-muted-foreground text-xs">Couldn’t load why. Try again in a moment.</p>}
                    {!failed && !why && <p className="text-muted-foreground text-xs">Loading…</p>}
                    {why?.items.map((item, i) => <WhyItemView key={i} item={item} />)}
                    {why?.said_no && why.said_no.length > 0 && <SaidNo vetoes={why.said_no} onLifted={() => setWhy(null)} />}
                </div>
            )}
        </div>
    );
}

function WhyItemView({ item }: { item: WhyItem }) {
    return (
        <div className="space-y-2">
            {item.title && <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{item.title}</p>}
            <p className="font-medium">{item.headline}</p>
            {item.reason && <p className="text-muted-foreground text-xs">“{item.reason}”</p>}

            {(item.grade || item.method) && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {item.grade && (
                        <span className={cn('rounded-full px-2 py-0.5 font-medium', gradeTone[item.grade.grade])} title={item.grade.basis}>
                            {gradeWords[item.grade.grade] ?? item.grade.grade}
                        </span>
                    )}
                    {item.method && (
                        <span className="text-muted-foreground">
                            Rests on {item.method.basis_label}
                            {item.method.recorded ? '' : ' (worked out from its wording)'}
                        </span>
                    )}
                </div>
            )}
            {item.grade && <p className="text-muted-foreground text-xs">{item.grade.basis}</p>}
            {item.method?.note && <p className="text-muted-foreground text-xs">{item.method.note}</p>}

            {item.score && (
                <p className="text-xs">
                    {item.score.of !== null ? (
                        <>
                            {item.score.value} of {item.score.of} of your decisions agree
                        </>
                    ) : (
                        <>
                            Score {item.score.value}
                            {item.score.means ? ` (${item.score.means})` : ''}
                        </>
                    )}
                    {item.score.margin !== null && item.score.of === null ? `, ${item.score.margin} ahead of the next best` : ''}
                </p>
            )}

            {item.facts?.map((f, i) => (
                <p key={i} className="text-muted-foreground text-xs">
                    {f}
                </p>
            ))}

            {item.evidence.length > 0 && (
                <div>
                    <p className="mb-1 text-xs font-medium">Relied on</p>
                    <ul className="space-y-1">
                        {item.evidence.map((e) => (
                            <RefLine key={e.ref} r={e} />
                        ))}
                    </ul>
                </div>
            )}

            {item.alternatives.length > 0 && (
                <div>
                    <p className="mb-1 text-xs font-medium">Also considered</p>
                    <ul className="space-y-1">
                        {item.alternatives.map((a) => (
                            <RefLine key={a.ref} r={a} extra={a.score !== null ? `score ${a.score}` : null} />
                        ))}
                    </ul>
                </div>
            )}

            {item.history.length > 0 && <History events={item.history} />}
        </div>
    );
}

/** What you said no to about it: not proposed again until you lift it (audited). */
function SaidNo({ vetoes, onLifted }: { vetoes: WhySaidNo[]; onLifted: () => void }) {
    const [busy, setBusy] = useState<number | null>(null);
    const lift = (v: WhySaidNo) =>
        router.post(`/todo/veto_lift/${v.id}`, v.fp ? { fp: v.fp } : {}, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(v.id),
            onFinish: () => setBusy(null),
            onSuccess: () => onLifted(),
            onError: () => toast('That didn’t work. Try again.', 'error'),
        });

    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">You said no to</p>
            <ul className="space-y-1">
                {vetoes.map((v) => (
                    <li key={v.id} className="flex flex-wrap items-baseline gap-x-2 text-xs">
                        <Ban className="text-muted-foreground size-3 shrink-0 self-center" />
                        <span>{v.what}</span>
                        <span className="text-muted-foreground">
                            {v.method_label ? `(held back from ${v.method_label})` : '(nothing automatic suggests it)'}
                            {v.reason ? ` · ${v.reason}` : ''}
                            {v.at ? ` · ${v.at}` : ''}
                        </span>
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                            disabled={busy === v.id}
                            onClick={() => lift(v)}
                        >
                            Lift
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RefLine({ r, extra }: { r: ResolvedRef; extra?: string | null }) {
    return (
        <li className="flex flex-wrap items-baseline gap-x-2 text-xs">
            {r.href && r.exists ? (
                <a href={r.href} className="underline-offset-2 hover:underline">
                    {r.label}
                </a>
            ) : (
                <span className={cn(!r.exists && 'text-muted-foreground line-through')}>{r.label}</span>
            )}
            {extra && <span className="text-muted-foreground">{extra}</span>}
            {r.changed && (
                <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="size-3" /> changed since this was relied on
                </span>
            )}
        </li>
    );
}

function History({ events }: { events: WhyHistoryEvent[] }) {
    return (
        <div>
            <p className="mb-1 text-xs font-medium">History</p>
            <ol className="text-muted-foreground space-y-1 border-l pl-3 text-xs">
                {events.map((h) => (
                    <li key={h.id}>
                        <span className="tabular-nums">{h.at}</span> · {h.label} by {h.actor_label}
                        {h.channel_label ? ` ${h.channel_label}` : ''}
                        {h.reason ? ` · ${h.reason}` : ''}
                        {h.knock_on.length > 0 && (
                            <ul className="mt-0.5 space-y-0.5 pl-3">
                                {h.knock_on.map((k) => (
                                    <li key={k.id}>
                                        At the same time: {k.label.toLowerCase()} by {k.actor_label} · {k.subject}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}
