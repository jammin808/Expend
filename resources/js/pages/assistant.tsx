import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Loader2, Send, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Turn {
    id?: number;
    question: string;
    answer: string | null;
    unmatched: string[];
    tools: string[];
    error?: string;
}

const examples = [
    'What’s coming up in the next two weeks, and what do I need for each job?',
    'Where am I staying for my next job, and who booked it?',
    'Which invoices are unpaid or overdue?',
    'How much of 2024-25 is still undecided, and what’s the biggest?',
    'What can I claim for travel on the Leeds job?',
    'Suggest what to do first to get 2024-25 ready to file.',
];

function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

/** Very small, safe renderer: paragraphs, bullet lists and bold. No HTML from the model is ever injected. */
function Answer({ text }: { text: string }) {
    const blocks = text.split(/\n{2,}/);
    const inline = (s: string) =>
        s
            .split(/(\*\*[^*]+\*\*)/g)
            .map((part, i) => (part.startsWith('**') ? <strong key={i}>{part.slice(2, -2)}</strong> : <span key={i}>{part}</span>));
    return (
        <div className="space-y-2 text-sm leading-relaxed">
            {blocks.map((b, i) => {
                const lines = b.split('\n');
                if (lines.every((l) => /^\s*([-*•]|\d+\.)\s/.test(l))) {
                    return (
                        <ul key={i} className="list-disc space-y-1 pl-5">
                            {lines.map((l, j) => (
                                <li key={j}>{inline(l.replace(/^\s*([-*•]|\d+\.)\s/, ''))}</li>
                            ))}
                        </ul>
                    );
                }
                return (
                    <p key={i} className="whitespace-pre-wrap">
                        {inline(b)}
                    </p>
                );
            })}
        </div>
    );
}

export default function AssistantPage({ history, aiReady, usage }: { history: Turn[]; aiReady: boolean; usage: { usd: number; calls: number } }) {
    const [turns, setTurns] = useState<Turn[]>(history);
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => end.current?.scrollIntoView({ behavior: 'smooth' }), [turns.length, busy]);

    const ask = async (q: string) => {
        if (!q.trim() || busy) return;
        setBusy(true);
        setQuestion('');
        setTurns((t) => [...t, { question: q, answer: null, unmatched: [], tools: [] }]);
        try {
            const res = await fetch('/assistant/ask', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
                body: JSON.stringify({ question: q }),
            });
            const j = await res.json();
            setTurns((t) =>
                t.map((x, i) =>
                    i === t.length - 1
                        ? j.ok
                            ? { ...x, answer: j.answer, unmatched: j.unmatched, tools: j.tools, id: j.id }
                            : { ...x, error: j.message ?? 'Something went wrong.' }
                        : x,
                ),
            );
        } catch {
            setTurns((t) => t.map((x, i) => (i === t.length - 1 ? { ...x, error: 'Couldn’t reach the server.' } : x)));
        } finally {
            setBusy(false);
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Assistant', href: '/assistant' }]}>
            <Head title="Assistant" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                        <Sparkles className="size-5 text-amber-500" /> Assistant
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Ask about your money, jobs, dates and documents. Facts come only from your records, with references; suggestions and estimates
                        are labelled. It can suggest decisions for your Inbox but never confirms anything.
                    </p>
                </div>

                {!aiReady && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                        Add your Claude API key in{' '}
                        <Link href="/settings/integrations" className="underline">
                            Settings › Integrations
                        </Link>{' '}
                        to start.
                    </p>
                )}

                <div className="flex-1 space-y-5">
                    {turns.length === 0 && (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {examples.map((e) => (
                                <button
                                    key={e}
                                    className="hover:bg-muted/50 rounded-xl border p-3 text-left text-sm"
                                    onClick={() => ask(e)}
                                    disabled={!aiReady}
                                >
                                    {e}
                                </button>
                            ))}
                        </div>
                    )}
                    {turns.map((t, i) => (
                        <div key={i} className="space-y-2">
                            <div className="ml-auto w-fit max-w-[85%] rounded-2xl bg-neutral-900 px-4 py-2 text-sm text-white dark:bg-white dark:text-neutral-900">
                                {t.question}
                            </div>
                            <div className={cn('max-w-[95%] rounded-2xl border px-4 py-3', t.error && 'border-rose-300')}>
                                {t.error ? (
                                    <p className="text-sm text-rose-700">{t.error}</p>
                                ) : t.answer === null ? (
                                    <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                        <Loader2 className="size-4 animate-spin" /> Looking through your records…
                                    </p>
                                ) : (
                                    <>
                                        <Answer text={t.answer} />
                                        {t.unmatched.length > 0 && (
                                            <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" /> Not found in your records:{' '}
                                                {t.unmatched.join(', ')}. Treat these as unverified.
                                            </p>
                                        )}
                                        {t.tools.length > 0 && (
                                            <p className="text-muted-foreground mt-2 text-xs">
                                                Checked: {t.tools.map((x) => x.replace(/_/g, ' ')).join(', ')}
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    ))}
                    <div ref={end} />
                </div>

                <form
                    className="bg-background sticky bottom-2 flex items-end gap-2 rounded-2xl border p-2 shadow-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        ask(question);
                    }}
                >
                    <Textarea
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                ask(question);
                            }
                        }}
                        placeholder="Ask anything about your jobs, money or dates…"
                        className="min-h-10 resize-none border-0 shadow-none focus-visible:ring-0"
                        rows={1}
                        disabled={!aiReady}
                    />
                    <Button size="icon" disabled={busy || !question.trim() || !aiReady} aria-label="Send">
                        {busy ? <Loader2 className="animate-spin" /> : <Send />}
                    </Button>
                </form>
                <p className="text-muted-foreground text-center text-xs">
                    This month: {usage.calls} AI requests, about ${usage.usd.toFixed(2)} (estimate).
                </p>
            </div>
        </AppLayout>
    );
}
