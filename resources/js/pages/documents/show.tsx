import { DocLine, type DocRow } from '@/components/doc-line';
import { Money } from '@/components/money';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, Sparkles, Trash2 } from 'lucide-react';

interface Fact {
    id: number;
    field: string;
    label: string;
    value: string;
    on_date: string | null;
    at_time: string | null;
    quote: string;
    page: number | null;
    verified: boolean;
    verification_note: string | null;
}

interface PoLine {
    description: string;
    quantity: number;
    unit_price_minor: number;
    amount_minor: number;
    quote: string;
    verified: boolean;
    note: string | null;
}

interface Props {
    document: DocRow & {
        text: string | null;
        text_status: string;
        error: string | null;
        extraction: null | {
            summary: string;
            job_link_reason: string | null;
            new_job: null | {
                title: string;
                client: string | null;
                starts_on: string | null;
                ends_on: string | null;
                venue: string | null;
                postcode: string | null;
                verified: boolean;
            };
            po: null | {
                po_number: string | null;
                bill_to_name: string | null;
                currency: string;
                lines: PoLine[];
                stated_total_minor: number | null;
                lines_total_minor: number;
                lines_match_total: boolean | null;
                total_verified: boolean;
            };
            receipt: null | { merchant: string | null; date: string | null; amount_minor: number; currency: string; verified: boolean };
            unverified_count: number;
        };
        facts: Fact[];
        children: DocRow[];
        parent: DocRow | null;
        invoices: { id: number; number: string | null; status: string; total_minor: number }[];
    };
    jobs: { id: number; title: string; starts_on: string }[];
    kinds: Record<string, string>;
    aiReady: boolean;
}

function Verified({ ok, note }: { ok: boolean; note?: string | null }) {
    return ok ? (
        <span className="inline-flex items-center gap-1 text-xs text-emerald-700" title="The quoted words were found in the document">
            <CheckCircle2 className="size-3.5" /> verified
        </span>
    ) : (
        <span className="inline-flex items-center gap-1 text-xs text-amber-700" title={note ?? ''}>
            <AlertTriangle className="size-3.5" /> check
        </span>
    );
}

export default function DocumentShow({ document: d, jobs, kinds, aiReady }: Props) {
    const x = d.extraction;
    const newJob = useForm({
        title: x?.new_job?.title ?? '',
        client: x?.new_job?.client ?? '',
        starts_on: x?.new_job?.starts_on ?? '',
        ends_on: x?.new_job?.ends_on ?? x?.new_job?.starts_on ?? '',
        location: x?.new_job?.venue ?? '',
        postcode: x?.new_job?.postcode ?? '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Documents', href: '/documents' },
                { title: d.subject ?? d.name, href: `/documents/${d.id}` },
            ]}
        >
            <Head title={d.name} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tracking-tight break-words">{d.subject ?? d.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {kinds[d.kind]} · {d.received} · via {d.source}
                            {d.from ? ` · from ${d.from}` : ''}
                        </p>
                        {d.parent && (
                            <p className="text-sm">
                                Attachment of{' '}
                                <Link href={`/documents/${d.parent.id}`} className="underline">
                                    {d.parent.subject ?? d.parent.name}
                                </Link>
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={`/documents/${d.id}/file`}>
                                <Download /> Original
                            </a>
                        </Button>
                        {d.status !== 'held' && d.kind !== 'email' && (
                            <Button
                                disabled={!aiReady}
                                onClick={() => router.post(`/documents/${d.id}/read`, {}, { preserveScroll: true })}
                                title={aiReady ? '' : 'Add a Claude API key in Settings › Integrations'}
                            >
                                <Sparkles /> {d.status === 'extracted' ? 'Read again' : 'Read with AI'}
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Delete"
                            onClick={() => window.confirm('Delete this document (and its attachments)?') && router.delete(`/documents/${d.id}`)}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </div>

                {d.status === 'held' && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30">
                        <span>
                            <strong>Held for your approval.</strong> {d.held_reason}
                        </span>
                        <Button size="sm" onClick={() => router.patch(`/documents/${d.id}`, { approve: true }, { preserveScroll: true })}>
                            Approve
                        </Button>
                    </div>
                )}
                {d.error && d.status === 'failed' && (
                    <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-900 dark:bg-rose-950/30 dark:text-rose-200">{d.error}</p>
                )}

                <div className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                    <label className="space-y-1 text-sm">
                        <span className="text-muted-foreground">Job</span>
                        <NativeSelect
                            value={d.job?.id ?? ''}
                            onChange={(e) =>
                                router.patch(
                                    `/documents/${d.id}`,
                                    { work_job_id: e.target.value ? Number(e.target.value) : null },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <option value="">Not linked to a job</option>
                            {jobs.map((j) => (
                                <option key={j.id} value={j.id}>
                                    {j.title} · {j.starts_on}
                                </option>
                            ))}
                        </NativeSelect>
                        {x?.job_link_reason && d.job && <span className="text-muted-foreground text-xs">Linked by AI: {x.job_link_reason}</span>}
                    </label>
                    <label className="space-y-1 text-sm">
                        <span className="text-muted-foreground">Kind</span>
                        <NativeSelect
                            value={d.kind}
                            onChange={(e) => router.patch(`/documents/${d.id}`, { kind: e.target.value }, { preserveScroll: true })}
                        >
                            {Object.entries(kinds).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                </div>

                {x?.summary && <p className="text-base">{x.summary}</p>}

                {x?.new_job && !d.job && (
                    <form
                        className="space-y-3 rounded-xl border border-sky-300 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            newJob.post(`/documents/${d.id}/job`);
                        }}
                    >
                        <p className="font-medium">This looks like a new job. Check the details, then create it.</p>
                        <div className="grid gap-2 sm:grid-cols-3">
                            <Input value={newJob.data.title} onChange={(e) => newJob.setData('title', e.target.value)} placeholder="Job" />
                            <Input value={newJob.data.client} onChange={(e) => newJob.setData('client', e.target.value)} placeholder="Client" />
                            <Input value={newJob.data.location} onChange={(e) => newJob.setData('location', e.target.value)} placeholder="Venue" />
                            <Input type="date" value={newJob.data.starts_on} onChange={(e) => newJob.setData('starts_on', e.target.value)} />
                            <Input type="date" value={newJob.data.ends_on} onChange={(e) => newJob.setData('ends_on', e.target.value)} />
                            <Input value={newJob.data.postcode} onChange={(e) => newJob.setData('postcode', e.target.value)} placeholder="Postcode" />
                        </div>
                        <Button size="sm">Create job and link</Button>
                    </form>
                )}

                {d.facts.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Details found</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {d.facts.map((f) => (
                                <li
                                    key={f.id}
                                    className={cn(
                                        'grid gap-1 p-3 sm:grid-cols-[10rem_1fr_auto]',
                                        !f.verified && 'bg-amber-50/50 dark:bg-amber-950/20',
                                    )}
                                >
                                    <span className="text-muted-foreground">{f.label}</span>
                                    <span>
                                        <span className="font-medium">{f.value}</span>
                                        {(f.on_date || f.at_time) && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {f.on_date} {f.at_time}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground block text-xs italic">
                                            “{f.quote}”{f.page ? ` (page ${f.page})` : ''}
                                        </span>
                                    </span>
                                    <Verified ok={f.verified} note={f.verification_note} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {x?.po && (
                    <section className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">
                                Purchase order {x.po.po_number}
                                {x.po.bill_to_name ? ` · ${x.po.bill_to_name}` : ''}
                            </h2>
                            <Button size="sm" onClick={() => router.post(`/documents/${d.id}/invoice`)}>
                                Draft invoice from this PO
                            </Button>
                        </div>
                        <table className="w-full rounded-xl border text-sm">
                            <tbody className="divide-y">
                                {x.po.lines.map((l, i) => (
                                    <tr key={i}>
                                        <td className="p-2">
                                            {l.description}
                                            <span className="text-muted-foreground block text-xs italic">“{l.quote}”</span>
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {l.quantity} × {money(l.unit_price_minor, x.po!.currency)}
                                        </td>
                                        <td className="p-2 text-right font-medium tabular-nums">{money(l.amount_minor, x.po!.currency)}</td>
                                        <td className="p-2">
                                            <Verified ok={l.verified} note={l.note} />
                                        </td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="p-2">Lines total</td>
                                    <td />
                                    <td className="p-2 text-right tabular-nums">{money(x.po.lines_total_minor, x.po.currency)}</td>
                                    <td className="p-2 text-xs">
                                        {x.po.lines_match_total === true && <span className="text-emerald-700">matches the PO total</span>}
                                        {x.po.lines_match_total === false && (
                                            <span className="text-rose-600">PO says {money(x.po.stated_total_minor, x.po.currency)}</span>
                                        )}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                )}

                {x?.receipt && (
                    <p className="text-sm">
                        Receipt: {x.receipt.merchant} · {x.receipt.date} · <Money pence={-x.receipt.amount_minor} currency={x.receipt.currency} />{' '}
                        <Verified ok={x.receipt.verified} />
                    </p>
                )}

                {d.invoices.length > 0 && (
                    <p className="text-sm">
                        Invoices from this document:{' '}
                        {d.invoices.map((i) => (
                            <Link key={i.id} href={`/invoices/${i.id}`} className="mr-2 underline">
                                {i.number ?? 'draft'}
                            </Link>
                        ))}
                    </p>
                )}

                {d.children.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">Attachments</h2>
                        <ul className="divide-y rounded-xl border">
                            {d.children.map((c) => (
                                <li key={c.id}>
                                    <DocLine d={c} kinds={kinds} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {d.text && (
                    <details className="rounded-xl border p-4 text-sm">
                        <summary className="cursor-pointer font-medium">Text of the document</summary>
                        <pre className="text-muted-foreground mt-3 max-h-[32rem] overflow-auto text-xs whitespace-pre-wrap">{d.text}</pre>
                    </details>
                )}
                {d.text_status === 'image_only' && (
                    <p className="text-muted-foreground text-sm">
                        This is an image or scan, so details read from it can’t be checked word for word. Check them against the original.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
