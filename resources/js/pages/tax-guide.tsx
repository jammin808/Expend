import { GuideBlock } from '@/components/explain';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Entry {
    title: string;
    summary: string;
    allowable?: 'yes' | 'no' | 'partly' | 'capital';
    rules?: string[];
    sources?: { title: string; url: string }[];
}
interface Way {
    title: string;
    how: string;
    who_it_suits: string;
    limits: string;
    needs_accountant: boolean;
    sources: { title: string; url: string }[];
}

/** The tax guide: legal ways to reduce tax, the rules that matter, and what each expense category covers, with sources. */
export default function TaxGuide({
    researched_on,
    ways,
    topics,
    categories,
}: {
    researched_on: string | null;
    ways: Way[];
    topics: (Entry & { slug: string })[];
    categories: (Entry & { code: string })[];
}) {
    const [q, setQ] = useState('');
    const match = (e: { title: string; summary?: string; how?: string }) =>
        !q || `${e.title} ${e.summary ?? ''} ${e.how ?? ''}`.toLowerCase().includes(q.toLowerCase());

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tax years', href: '/years' },
                { title: 'Tax guide', href: '/tax-guide' },
            ]}
        >
            <Head title="Tax guide" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Tax guide</h1>
                    <p className="text-muted-foreground text-sm">
                        What you can claim and how to pay less tax legally, as a UK sole trader, from GOV.UK and HMRC’s own manuals
                        {researched_on ? ` (checked ${researched_on})` : ''}. General guidance, not personal advice: points marked “Ask an accountant”
                        need one. The “?” next to any payment applies this to that payment, and the assistant can answer questions from it.
                    </p>
                    <input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Filter, e.g. hotel, mileage, pension"
                        aria-label="Filter the guide"
                        className="border-input bg-background mt-2 h-9 w-full max-w-sm rounded-md border px-3 text-sm"
                    />
                </div>

                <section className="space-y-2">
                    <h2 className="font-semibold">Ways to reduce your tax legally</h2>
                    {ways.filter(match).map((w) => (
                        <details key={w.title} className="rounded-xl border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">
                                {w.title}
                                {w.needs_accountant && (
                                    <span className="text-muted-foreground ml-2 text-xs font-normal">· worth asking an accountant</span>
                                )}
                            </summary>
                            <div className="mt-2 space-y-2">
                                <p>{w.how}</p>
                                <p className="text-muted-foreground">
                                    <strong>Suits:</strong> {w.who_it_suits}
                                </p>
                                <p className="text-muted-foreground">
                                    <strong>Limits:</strong> {w.limits}
                                </p>
                                <GuideBlock g={{ title: w.title, summary: '', sources: w.sources }} compact />
                            </div>
                        </details>
                    ))}
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">Rules that matter</h2>
                    {topics.filter(match).map((t) => (
                        <details key={t.slug} id={t.slug} className="rounded-xl border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">{t.title}</summary>
                            <div className="mt-2">
                                <GuideBlock g={t} compact />
                            </div>
                        </details>
                    ))}
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">Expense categories</h2>
                    {categories.filter(match).map((c) => (
                        <details key={c.code} id={c.code} className="rounded-xl border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">{c.title}</summary>
                            <div className="mt-2">
                                <GuideBlock g={c} />
                            </div>
                        </details>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
