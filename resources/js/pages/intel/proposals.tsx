import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';

interface RefChip {
    ref: string;
    label: string;
    href: string | null;
}

interface Proposal {
    link: 'calendar_job' | 'document_job' | 'travel_job' | 'travel_payment' | 'invoice_job';
    subject: string;
    object: string | null;
    href: string | null;
    /** What kind of link, in plain words */
    about: string;
    /** The claim in one sentence */
    claim: string;
    to: RefChip | null;
    /** How it was made, in plain words */
    method: string | null;
    basis: string | null;
    grade: string;
    grade_basis: string;
    score: number | null;
    score_means: string | null;
    evidence: RefChip[];
    /** The alternatives it weighed, with how far behind */
    alternatives: (RefChip & { score: number | null; behind: number | null })[];
    can_accept: boolean;
}

interface Props {
    proposals: Proposal[];
    /** Why not: the reasons offered with "Not this one" */
    reasons: Record<string, string>;
}

function Chip({ r }: { r: RefChip }) {
    const body = <span className="bg-muted rounded-full px-2 py-0.5 text-xs">{r.label}</span>;
    return r.href ? (
        <a href={r.href} className="hover:underline">
            {body}
        </a>
    ) : (
        body
    );
}

/**
 * Links Expend made on its own (calendar entries, documents and stays on jobs, a stay's payment, an invoice on a job),
 * each with what it rests on and what else it weighed: Accept, Not this one (with a reason, remembered so it isn't
 * linked again), or Later (left as it is).
 */
export default function Proposals({ proposals, reasons }: Props) {
    const [asking, setAsking] = useState<string | null>(null);
    const [later, setLater] = useState<string[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const keyOf = (p: Proposal) => `${p.link}|${p.subject}|${p.object ?? ''}`;
    const answer = (p: Proposal, a: 'accept' | 'reject', reason?: string) =>
        router.post(
            '/intel/proposals',
            { link: p.link, subject: p.subject, object: p.object, answer: a, ...(reason ? { reason_code: reason } : {}) },
            {
                preserveScroll: true,
                // Keeps what you set aside with Later while the list comes back without the one you answered.
                preserveState: true,
                onStart: () => setBusy(keyOf(p)),
                onFinish: () => {
                    setBusy(null);
                    setAsking(null);
                },
            },
        );
    const shown = proposals.filter((p) => !later.includes(keyOf(p)));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Overnight', href: '/intel' },
                { title: 'Links to check', href: '/intel/proposals' },
            ]}
        >
            <Head title="Links to check" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Links Expend made on its own</h1>
                    <p className="text-muted-foreground text-sm">
                        Accept the ones that are right. “Not this one” takes a link off and remembers it, so it isn’t made again. Later leaves it as
                        it is.
                    </p>
                </div>

                {shown.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border p-4 text-sm">Nothing to check: every automatic link has been looked at.</p>
                ) : (
                    <ul className="space-y-3">
                        {shown.map((p) => {
                            const key = keyOf(p);
                            return (
                                <li key={key} className="space-y-2 rounded-xl border p-4">
                                    <p className="text-muted-foreground text-[11px] tracking-wide uppercase">{p.about}</p>
                                    <p className="font-medium">
                                        {p.href ? (
                                            <Link href={p.href} className="hover:underline">
                                                {p.claim}
                                            </Link>
                                        ) : (
                                            p.claim
                                        )}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <Badge variant="secondary" title={p.grade_basis}>
                                            Not measured yet
                                        </Badge>
                                        {p.method && (
                                            <span className="text-muted-foreground">
                                                {p.method}
                                                {p.basis ? ` (rests on ${p.basis})` : ''}
                                                {p.score !== null ? ` · score ${p.score}${p.score_means ? `: ${p.score_means}` : ''}` : ''}
                                            </span>
                                        )}
                                    </div>
                                    {p.evidence.length > 0 && (
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="text-muted-foreground text-xs">Relied on</span>
                                            {p.evidence.map((e) => (
                                                <Chip key={e.ref} r={e} />
                                            ))}
                                        </div>
                                    )}
                                    {p.alternatives.length > 0 && (
                                        <p className="text-muted-foreground text-xs">
                                            Also possible:{' '}
                                            {p.alternatives.map((a, i) => (
                                                <span key={a.ref}>
                                                    {i > 0 ? '; ' : ''}
                                                    {a.label}
                                                    {a.behind !== null ? `, ${a.behind} ${a.behind === 1 ? 'point' : 'points'} behind` : ''}
                                                </span>
                                            ))}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap items-center gap-2">
                                        {p.can_accept ? (
                                            <Button size="sm" disabled={busy === key} onClick={() => answer(p, 'accept')}>
                                                <Check /> Accept
                                            </Button>
                                        ) : (
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href="/travel">Decide the stay</Link>
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy === key}
                                            onClick={() => setAsking(asking === key ? null : key)}
                                        >
                                            <X /> Not this one
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setLater([...later, key])}>
                                            Later
                                        </Button>
                                    </div>
                                    {asking === key && (
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="text-muted-foreground text-xs">Why not?</span>
                                            {Object.entries(reasons).map(([code, label]) => (
                                                <button
                                                    key={code}
                                                    className="hover:bg-muted rounded-full border px-2.5 py-0.5 text-xs"
                                                    disabled={busy === key}
                                                    onClick={() => answer(p, 'reject', code)}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
