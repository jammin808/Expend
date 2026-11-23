import { DocLine, type DocRow } from '@/components/doc-line';
import { JobsTabs } from '@/components/jobs-tabs';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import { useRef, useState } from 'react';

export default function Documents({
    documents,
    filters,
    kinds,
    aiReady,
}: {
    documents: DocRow[];
    filters: Record<string, string | undefined>;
    kinds: Record<string, string>;
    aiReady: boolean;
}) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [q, setQ] = useState(filters.q ?? '');
    const [busy, setBusy] = useState(false);
    const [drag, setDrag] = useState(false);

    const upload = (files: FileList | null) => {
        if (!files?.length) return;
        setBusy(true);
        router.post('/documents', { files: Array.from(files) }, { forceFormData: true, preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const apply = (patch: Record<string, string>) => router.get('/documents', { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={[{ title: 'Documents', href: '/documents' }]}>
            <Head title="Documents" />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
                <JobsTabs />
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Documents</h1>
                    <p className="text-muted-foreground text-sm">
                        POs, job invites, production packs, call sheets, bookings and receipts. Upload here, forward by email, or send to your
                        Telegram bot.
                        {!aiReady && ' Add a Claude API key in Settings › Integrations to have them read for you.'}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => fileRef.current?.click()}
                    onDragOver={(e) => (e.preventDefault(), setDrag(true))}
                    onDragLeave={() => setDrag(false)}
                    onDrop={(e) => (e.preventDefault(), setDrag(false), upload(e.dataTransfer.files))}
                    className={cn(
                        'flex w-full items-center justify-center gap-3 rounded-xl border-2 border-dashed p-6 text-sm',
                        drag ? 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' : 'hover:bg-muted/40',
                    )}
                >
                    <FileUp className="text-muted-foreground size-5" />{' '}
                    {busy ? 'Uploading…' : 'Drop files here or click to choose (PDF, Word, Excel, photos, saved emails)'}
                </button>
                <input
                    ref={fileRef}
                    type="file"
                    multiple
                    className="hidden"
                    accept=".pdf,.doc,.docx,.xlsx,.jpg,.jpeg,.png,.webp,.eml,.txt"
                    onChange={(e) => upload(e.target.files)}
                />

                <div className="grid gap-2 sm:grid-cols-[1fr_14rem_10rem]">
                    <form onSubmit={(e) => (e.preventDefault(), apply({ q }))}>
                        <Input placeholder="Search everything, e.g. PO number, venue, hotel" value={q} onChange={(e) => setQ(e.target.value)} />
                    </form>
                    <NativeSelect value={filters.kind ?? ''} onChange={(e) => apply({ kind: e.target.value })}>
                        <option value="">All kinds</option>
                        {Object.entries(kinds).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value })}>
                        <option value="">Any status</option>
                        <option value="held">Waiting for approval</option>
                        <option value="new">Not read yet</option>
                        <option value="extracted">Read</option>
                        <option value="failed">Couldn’t be read</option>
                    </NativeSelect>
                </div>

                <ul className="divide-y rounded-xl border">
                    {documents.map((d) => (
                        <li key={d.id}>
                            <DocLine d={d} kinds={kinds} />
                        </li>
                    ))}
                    {documents.length === 0 && <li className="text-muted-foreground p-6 text-center text-sm">Nothing here yet.</li>}
                </ul>
                <Button variant="ghost" size="sm" asChild>
                    <Link href="/settings/integrations">Set up email forwarding and Telegram</Link>
                </Button>
            </div>
        </AppLayout>
    );
}
