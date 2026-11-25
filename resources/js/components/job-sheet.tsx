import { type DocRow } from '@/components/doc-line';
import { type JobData } from '@/components/job-form';
import { toast } from '@/components/toaster';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';

export interface SheetFact {
    id: number;
    field: string;
    label: string;
    value: string;
    on_date: string | null;
    at_time: string | null;
    quote: string;
    verified: boolean;
    verification_note: string | null;
    document_id: number;
    document: string | null;
    received: string | null;
}

const groups: { title: string; fields: string[] }[] = [
    { title: 'When', fields: ['start_date', 'end_date', 'call_time', 'load_in', 'start_time', 'finish_time', 'load_out', 'deadline'] },
    { title: 'Where', fields: ['venue', 'address', 'postcode', 'parking'] },
    { title: 'Travel and stay', fields: ['travel', 'accommodation'] },
    { title: 'People', fields: ['contact'] },
    { title: 'On the day', fields: ['dress_code', 'catering', 'other'] },
    { title: 'Money', fields: ['po_number', 'rate', 'payment_terms', 'invoice_to'] },
];

/** Which job field a fact can fill in, if any. */
const jobField: Record<string, keyof JobData> = {
    venue: 'location',
    postcode: 'postcode',
    call_time: 'start_time',
    start_time: 'start_time',
    finish_time: 'end_time',
    start_date: 'starts_on',
    end_date: 'ends_on',
};

/**
 * What a fact puts in the job, or null when the job can't take it: a time or date the AI couldn't read as one
 * ("7am call", "the weekend") would never save, so it gets no "Use in job".
 */
function jobValue(f: SheetFact): string | null {
    const field = jobField[f.field];
    if (field === 'start_time' || field === 'end_time') return f.at_time;
    if (field === 'starts_on' || field === 'ends_on') return f.on_date;
    return field ? f.value : null;
}

export function JobSheet({ job, facts, documents }: { job: JobData & { id: number }; facts: SheetFact[]; documents: DocRow[] }) {
    const applyFact = (f: SheetFact) => {
        const field = jobField[f.field];
        const value = jobValue(f);
        if (!field || value === null) return;
        // Only what the job needs to save and the one detail: the job keeps its PO number, fee and everything else.
        const body: Record<string, string> = { title: job.title, starts_on: job.starts_on, ends_on: job.ends_on, [field]: value };
        // A start after the job's end is refused with the reason: moving the job's dates is done on the job form,
        // which keeps each day's kind and times in view.
        router.patch(`/jobs/${job.id}`, body, {
            preserveScroll: true,
            onError: (errors) => toast(Object.values(errors)[0] ?? 'That wasn’t saved. Try again.', 'error'),
        });
    };

    if (facts.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                No details yet. Link a PO, job invite, production pack or call sheet to this job{documents.length ? ' and read it with AI' : ''}, and
                the key details will appear here.
            </p>
        );
    }

    return (
        <div className="grid gap-4 md:grid-cols-2">
            {groups.map((g) => {
                const items = facts.filter((f) => g.fields.includes(f.field));
                if (!items.length) return null;
                return (
                    <div key={g.title} className="rounded-xl border p-3">
                        <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">{g.title}</p>
                        <ul className="space-y-2 text-sm">
                            {items.map((f) => {
                                const conflict = items.some(
                                    (o) => o.field === f.field && o.id !== f.id && o.value.trim().toLowerCase() !== f.value.trim().toLowerCase(),
                                );
                                const newest = !items.some((o) => o.field === f.field && o.id > f.id);
                                return (
                                    <li key={f.id} className={conflict && !newest ? 'opacity-60' : ''}>
                                        <div className="flex items-start justify-between gap-2">
                                            <span>
                                                <span className="text-muted-foreground">{f.label}: </span>
                                                <span className="font-medium">{f.value}</span>
                                                {f.on_date && <span className="text-muted-foreground"> · {f.on_date}</span>}
                                                {f.at_time && f.value !== f.at_time && <span className="text-muted-foreground"> · {f.at_time}</span>}
                                            </span>
                                            {f.verified ? (
                                                <CheckCircle2
                                                    className="mt-0.5 size-4 shrink-0 text-emerald-600"
                                                    aria-label="Verified against the document"
                                                />
                                            ) : (
                                                <AlertTriangle
                                                    className="mt-0.5 size-4 shrink-0 text-amber-600"
                                                    aria-label={f.verification_note ?? 'Check this'}
                                                />
                                            )}
                                        </div>
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                            <a className="underline" href={`/documents/${f.document_id}`}>
                                                {f.document}
                                            </a>
                                            {f.received && <span>{f.received}</span>}
                                            {conflict &&
                                                (newest ? (
                                                    <span className="text-amber-700">newest of differing versions</span>
                                                ) : (
                                                    <span>older version</span>
                                                ))}
                                            {f.verified && jobValue(f) !== null && (
                                                <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => applyFact(f)}>
                                                    Use in job
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </div>
    );
}
