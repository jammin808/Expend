import { day } from '@/lib/format';
import { Link } from '@inertiajs/react';
import { FileWarning, Mail } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Evidence {
    type: string;
    id: number;
    field?: string;
    quote: string | null;
    verified: boolean;
}

export interface PoChaseItem {
    job_id: number;
    title: string;
    client: string | null;
    starts_on: string;
    ends_on: string;
    why: string;
    days_until: number;
    invoiced: boolean;
    opportunity_id: number | null;
    contact: {
        name: string | null;
        email: string;
        from: 'opportunity' | 'job_document' | 'client';
        document_id: number | null;
        opportunity_id: number | null;
        verified: boolean;
    } | null;
    evidence: Evidence[];
    dates_text: string;
    request: { to: string; subject: string; body: string; mailto: string } | null;
}

const fromLabel = { opportunity: 'the enquiry it came from', job_document: 'the job’s emails', client: 'the client’s other work' };

/**
 * Jobs still waiting for a PO, with who to ask and a short request ready to open in your own email. Nothing is sent
 * from Expend: the link opens your mail app with the request filled in, and you send it (or not) yourself.
 */
export function PoChasePanel({ jobId, className, limit, allHref }: { jobId?: number; className?: string; limit?: number; allHref?: string }) {
    const [items, setItems] = useState<PoChaseItem[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const ctrl = new AbortController();
        fetch(`/opportunities/po-chase${jobId ? `?job=${jobId}` : ''}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: ctrl.signal,
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((d: { items: PoChaseItem[] }) => setItems(d.items))
            .catch((e: Error) => e.name !== 'AbortError' && setFailed(true));
        return () => ctrl.abort();
    }, [jobId]);

    if (failed) return null;
    if (items === null || items.length === 0) return null;

    return (
        <section
            className={className ?? 'space-y-2 rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20'}
            aria-label="No PO yet"
        >
            <div>
                <h2 className="flex items-center gap-2 font-semibold">
                    <FileWarning className="size-4 text-amber-600" aria-hidden /> No PO yet{jobId ? '' : ` (${items.length})`}
                </h2>
                <p className="text-muted-foreground text-xs">
                    {jobId
                        ? 'Ask for it before the work, or before you invoice. The button opens your own email with a short request; nothing is sent until you send it.'
                        : 'Jobs starting in the next 60 days, under way, or done and not invoiced. Each button opens your own email with a short request; nothing is sent until you send it.'}
                </p>
            </div>
            <ul className="divide-y rounded-lg border bg-white/70 text-sm dark:bg-neutral-950/40">
                {(limit ? items.slice(0, limit) : items).map((i) => (
                    <li key={i.job_id} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <span className="min-w-0 space-y-0.5">
                            {!jobId && (
                                <Link href={`/jobs/${i.job_id}`} className="block truncate font-medium hover:underline">
                                    {i.title}
                                </Link>
                            )}
                            <span className="text-muted-foreground block text-xs">
                                {i.starts_on === i.ends_on ? day(i.starts_on) : `${day(i.starts_on, false)} – ${day(i.ends_on)}`}
                                {i.client ? ` · ${i.client}` : ''} · {i.why}
                            </span>
                            {i.contact ? (
                                <span className="block text-xs">
                                    Ask {i.contact.name ? `${i.contact.name} ` : ''}
                                    <span className="text-muted-foreground">&lt;{i.contact.email}&gt;</span>
                                    {!i.contact.verified && (
                                        <span className="text-amber-700 dark:text-amber-400">
                                            {' '}
                                            · not found word for word: check it’s the right person
                                        </span>
                                    )}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · from{' '}
                                        {i.contact.document_id ? (
                                            <Link href={`/documents/${i.contact.document_id}`} className="underline underline-offset-2">
                                                {fromLabel[i.contact.from]}
                                            </Link>
                                        ) : i.contact.opportunity_id ? (
                                            <Link href={`/opportunities/${i.contact.opportunity_id}`} className="underline underline-offset-2">
                                                {fromLabel[i.contact.from]}
                                            </Link>
                                        ) : (
                                            fromLabel[i.contact.from]
                                        )}
                                    </span>
                                </span>
                            ) : (
                                <span className="text-muted-foreground block text-xs">
                                    No one found to ask. Link the PO email to the job, or add the number on the job.
                                </span>
                            )}
                            {i.evidence
                                .filter((e) => e.quote)
                                .slice(0, 1)
                                .map((e) => (
                                    <span key={`${e.type}${e.id}${e.field ?? ''}`} className="text-muted-foreground block text-xs italic">
                                        {e.verified ? '✓' : '⚠'} “{e.quote}”
                                    </span>
                                ))}
                        </span>
                        {i.request && (
                            <a
                                href={i.request.mailto}
                                className="border-input hover:bg-muted inline-flex shrink-0 items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm"
                                title={i.request.body}
                            >
                                <Mail className="size-4" aria-hidden /> Ask for the PO
                            </a>
                        )}
                    </li>
                ))}
            </ul>
            {limit && allHref && items.length > limit && (
                <Link href={allHref} className="text-sm underline underline-offset-2">
                    Show all {items.length}
                </Link>
            )}
        </section>
    );
}
