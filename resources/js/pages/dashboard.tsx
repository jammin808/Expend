import { Money } from '@/components/money';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { day, daysUntil, money } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Inbox, ShieldCheck, Upload } from 'lucide-react';

interface YearCard {
    label: string;
    status: string;
    deadline: string;
    overdue: boolean;
    progress: { total_count: number; confirmed_count: number; suggested_count: number; unreviewed_count: number; value_pct: number };
    turnover_pence: number;
    net_profit_pence: number;
}

interface Props {
    years: YearCard[];
    currentYear: string;
    mtd: { starts: string; based_on: string; turnover_over: number; turnover_pence: number; applies: boolean }[];
    vat: { rolling_turnover_pence: number; threshold_pence: number };
    counts: { accounts: number; transactions: number; to_review: number };
    twoFactor: boolean;
}

export default function Dashboard({ years, mtd, vat, counts, twoFactor }: Props) {
    const outstanding = years.filter((y) => y.status !== 'filed');
    const vatPct = Math.round((vat.rolling_turnover_pence / vat.threshold_pence) * 100);

    return (
        <AppLayout breadcrumbs={[{ title: 'Overview', href: '/dashboard' }]}>
            <Head title="Overview" />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                {!twoFactor && (
                    <Alert>
                        <ShieldCheck className="size-4" />
                        <AlertTitle>Turn on two-factor sign-in</AlertTitle>
                        <AlertDescription>
                            Your financial records deserve more than a password.{' '}
                            <Link href="/settings/security" className="font-medium underline">
                                Set it up in a minute
                            </Link>
                            .
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Overview</h1>
                        <p className="text-muted-foreground text-sm">
                            {counts.transactions.toLocaleString()} transactions across {counts.accounts} accounts
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/imports">
                                <Upload /> Import statement
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/inbox">
                                <Inbox /> Review {counts.to_review > 0 ? counts.to_review.toLocaleString() : ''}
                            </Link>
                        </Button>
                    </div>
                </div>

                <section className="space-y-3">
                    <h2 className="text-muted-foreground text-sm font-medium tracking-wide uppercase">
                        {outstanding.length ? 'Outstanding tax years' : 'Tax years'}
                    </h2>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {(outstanding.length ? outstanding : years).map((y) => {
                            const left = daysUntil(y.deadline);
                            return (
                                <Link
                                    key={y.label}
                                    href={`/years/${y.label}`}
                                    className="group hover:border-foreground/30 rounded-xl border p-5 transition-colors"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="text-lg font-semibold">{y.label}</p>
                                            <p className={y.overdue ? 'text-sm font-medium text-rose-600' : 'text-muted-foreground text-sm'}>
                                                {y.overdue ? `Overdue since ${day(y.deadline)}` : `Due ${day(y.deadline)} · ${left} days`}
                                            </p>
                                        </div>
                                        <StatusPill status={y.status} />
                                    </div>
                                    <div className="mt-5 space-y-1.5">
                                        <div className="flex justify-between text-sm">
                                            <span>{y.progress.value_pct}% of the money decided</span>
                                            <span className="text-muted-foreground">
                                                {(y.progress.unreviewed_count + y.progress.suggested_count).toLocaleString()} left
                                            </span>
                                        </div>
                                        <Progress value={y.progress.value_pct} />
                                    </div>
                                    <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <dt className="text-muted-foreground">Turnover so far</dt>
                                            <dd className="font-medium">{money(y.turnover_pence)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">Profit so far</dt>
                                            <dd className="font-medium">{money(y.net_profit_pence)}</dd>
                                        </div>
                                    </dl>
                                    <p className="mt-4 flex items-center gap-1 text-sm font-medium">
                                        Open catch-up <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                    </p>
                                </Link>
                            );
                        })}
                        {years.length === 0 && (
                            <div className="text-muted-foreground rounded-xl border border-dashed p-6 text-sm">
                                No tax years yet.{' '}
                                <Link href="/years" className="underline">
                                    Add one
                                </Link>
                                .
                            </div>
                        )}
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-xl border p-5">
                        <h2 className="font-semibold">Making Tax Digital</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Based on turnover (money in before expenses), not profit. Figures count confirmed items only.
                        </p>
                        <ul className="mt-4 space-y-3 text-sm">
                            {mtd.map((t) => (
                                <li key={t.starts} className="flex items-start justify-between gap-3">
                                    <span>
                                        From {day(t.starts)} if {t.based_on} turnover is over {money(t.turnover_over)}
                                        <span className="text-muted-foreground block">
                                            Your {t.based_on} turnover so far: <Money pence={t.turnover_pence} plus={false} />
                                        </span>
                                    </span>
                                    {t.applies ? (
                                        <span className="flex shrink-0 items-center gap-1 font-medium text-amber-700">
                                            <AlertTriangle className="size-4" /> Applies
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground shrink-0">Not yet</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="rounded-xl border p-5">
                        <h2 className="font-semibold">VAT threshold</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            You must register if taxable turnover in the last 12 months goes over {money(vat.threshold_pence)}.
                        </p>
                        <div className="mt-4 space-y-1.5">
                            <div className="flex justify-between text-sm">
                                <span>{money(vat.rolling_turnover_pence)} in the last 12 months</span>
                                <span className={vatPct >= 80 ? 'font-medium text-amber-700' : 'text-muted-foreground'}>{vatPct}%</span>
                            </div>
                            <Progress value={vatPct} tone={vatPct >= 80 ? 'amber' : 'sky'} />
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
