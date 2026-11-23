import { Link } from '@inertiajs/react';
import { AlertTriangle, CircleCheck, FileText, Mail, MessageCircle, PauseCircle } from 'lucide-react';

export interface DocRow {
    id: number;
    name: string;
    kind: string;
    status: string;
    source: string;
    summary: string | null;
    subject: string | null;
    from: string | null;
    received: string | null;
    job: { id: number; title: string } | null;
    children: number | null;
    unverified: number | null;
    held_reason: string | null;
    invoiced?: Invoiced | null;
}

export interface Invoiced {
    id: number;
    number: string | null;
    status: string;
}

/** The green tick on a PO (or the email carrying it) once its job is invoiced. */
export function InvoicedTick({ invoiced, className = '' }: { invoiced: Invoiced; className?: string }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 ${className}`}
            title={`Job invoiced: ${invoiced.number ?? ''} (${invoiced.status})`}
        >
            <CircleCheck className="size-3.5" aria-hidden />
            {invoiced.status === 'paid' ? 'Paid' : 'Invoiced'} {invoiced.number}
        </span>
    );
}

const sourceIcon = { upload: FileText, email: Mail, gmail: Mail, telegram: MessageCircle } as const;

export function DocLine({ d, kinds }: { d: DocRow; kinds: Record<string, string> }) {
    const Icon = sourceIcon[d.source as keyof typeof sourceIcon] ?? FileText;
    return (
        <Link href={`/documents/${d.id}`} className="hover:bg-muted/40 flex items-start gap-3 p-3">
            <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" />
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-medium">{d.subject ?? d.name}</span>
                    <span className="bg-muted rounded px-1.5 py-0.5 text-xs">{kinds[d.kind] ?? d.kind}</span>
                    {d.invoiced && <InvoicedTick invoiced={d.invoiced} />}
                    {d.status === 'held' && (
                        <span className="flex items-center gap-1 text-xs text-amber-700">
                            <PauseCircle className="size-3" /> Waiting for your approval
                        </span>
                    )}
                    {d.status === 'failed' && <span className="text-xs text-rose-600">Couldn’t be read</span>}
                    {!!d.unverified && (
                        <span className="flex items-center gap-1 text-xs text-amber-700">
                            <AlertTriangle className="size-3" /> {d.unverified} to check
                        </span>
                    )}
                </span>
                <span className="text-muted-foreground block truncate text-sm">
                    {d.summary ?? (d.children ? `${d.children} attachments` : d.name)}
                </span>
                <span className="text-muted-foreground text-xs">
                    {d.received}
                    {d.from ? ` · ${d.from}` : ''}
                    {d.job ? ` · ${d.job.title}` : ''}
                </span>
            </span>
        </Link>
    );
}
