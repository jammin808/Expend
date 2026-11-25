import { Money } from '@/components/money';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { day, daysUntil, money } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Camera, Inbox, ShieldCheck, Upload } from 'lucide-react';

interface YearCard {
    label: string;
    status: string;
    /** The return's deadline, or for a filed return being amended, the last day to amend it. */
    deadline: string;
    /** HMRC sent no notice to file: deadline is then the last day to tell them you need to file (5 October). */
    no_notice?: boolean;
    /** No notice yet, but you told HMRC by 5 October. */
    told_hmrc?: boolean;
    /** estimate: worked out from the notice's date; assumed: rests on a notice assumed sent by 31 July. */
    deadline_basis?: 'estimate' | 'assumed' | null;
    overdue: boolean;
    /** Filed, and unlocked to amend. */
    amending?: boolean;
    /** Being amended, and the last day to amend it hasn't passed. */
    amend_open?: boolean;
    progress: { total_count: number; confirmed_count: number; suggested_count: number; unreviewed_count: number; value_pct: number };
    turnover_pence: number;
    net_profit_pence: number;
}

interface TaxPotPayment {
    due_on: string;
    pence: number;
    label: string;
}

interface TaxPotCard {
    /** The share of the expected profit that goes in income tax and NI, as a percentage; null where it can't be estimated (Scottish rates). */
    rate_pct: number | null;
    per_thousand_pence: number | null;
    basis: string | null;
    /** The expected profit the rate rests on (null when none is recorded yet). */
    basis_profit_pence?: number | null;
    /** No profit recorded in either year: the basic rate plus Class 4 on all of it. */
    fallback?: boolean;
    need: {
        /** The next payment: 31 July's from February to July, otherwise 31 January's. */
        due_on: string;
        pence: number;
        label: string;
        balancing_pence: number;
        payment_on_account_pence: number;
        year: string;
        next_year: string;
        january?: TaxPotPayment;
        july?: TaxPotPayment | null;
        /** How that year's profit was found: filed, records (confirmed plus suggested) or expected (the year is still running). */
        basis?: 'filed' | 'records' | 'expected';
        profit_pence?: number;
        /** Money in suggested as business income, not confirmed yet, that's counted. */
        suggested_pence?: number;
        /** Money in still to decide, not counted: the figure may be more. */
        undecided_pence?: number;
        at_least?: boolean;
    } | null;
    pot: { pence: number; currency: string; name: string; at: string | null } | null;
    /** ok, none (no Space chosen), scope (the token can't read Spaces), missing, failed, not_connected */
    pot_status: string;
}

const pounds = (pence: number) => '£' + Math.round(pence / 100).toLocaleString('en-GB');

/** How much to put aside for tax, and how much is due by 31 January. Expend only reads: it never moves money. */
function TaxPotSection({ pot }: { pot: TaxPotCard }) {
    const need = pot.need;
    const have = pot.pot?.pence ?? null;
    const pct = need && need.pence > 0 && have !== null ? Math.min(100, Math.round((have / need.pence) * 100)) : null;
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">
                    Tax pot <span className="text-muted-foreground text-sm font-normal">(estimate)</span>
                </h2>
                <Link href="/settings/integrations#starling" className="text-sm underline underline-offset-2">
                    {pot.pot_status === 'none' ? 'Choose your Tax Space' : 'Settings'}
                </Link>
            </div>
            {pot.rate_pct !== null && pot.per_thousand_pence !== null ? (
                <p className="text-sm">
                    {pot.per_thousand_pence > 0 ? (
                        <>
                            From each £1,000 of business money you receive, put about <strong>{pounds(pot.per_thousand_pence)}</strong> aside.
                        </>
                    ) : (
                        <>At the profit expected, there’s no income tax or Class 4 to put aside yet.</>
                    )}
                    <span className="text-muted-foreground">
                        {' '}
                        {pot.fallback
                            ? 'No profit is recorded yet, so this is the basic rate plus Class 4 on all of it.'
                            : `About ${pot.rate_pct}% of the profit goes in income tax and National Insurance, from ${pot.basis}${
                                  pot.basis_profit_pence ? ` (about ${pounds(pot.basis_profit_pence)})` : ''
                              }.`}
                    </span>
                </p>
            ) : (
                <p className="text-muted-foreground text-sm">Expend can’t estimate this for Scottish income tax rates.</p>
            )}
            {need && (
                <div className="space-y-1.5 text-sm">
                    {need.july && need.january ? (
                        <p>
                            {need.at_least ? 'At least' : 'About'} <strong>{pounds(need.july.pence)}</strong> is due by {day(need.july.due_on)}
                            <span className="text-muted-foreground">: the {need.july.label.replace(/^2nd/, 'second')}.</span>
                            {need.january.pence > 0 && (
                                <>
                                    {' '}
                                    Then {need.at_least ? 'at least' : 'about'} <strong>{pounds(need.january.pence)}</strong> by{' '}
                                    {day(need.january.due_on)}
                                    <span className="text-muted-foreground">
                                        : the {need.year} balance
                                        {need.payment_on_account_pence > 0 ? ` plus the first payment on account for ${need.next_year}` : ''}.
                                    </span>
                                </>
                            )}
                        </p>
                    ) : need.pence > 0 || !need.at_least ? (
                        <p>
                            {need.at_least ? 'At least' : 'About'} <strong>{pounds(need.pence)}</strong> is due by {day(need.due_on)}
                            <span className="text-muted-foreground">
                                : the {need.year} balance
                                {need.payment_on_account_pence > 0 ? ` plus the first payment on account for ${need.next_year}` : ''}.
                            </span>
                        </p>
                    ) : (
                        <p>Nothing is due by {day(need.due_on)} from what’s decided so far, but that can change: see below.</p>
                    )}
                    <p className="text-muted-foreground text-xs">
                        {need.basis === 'filed'
                            ? `From the profit you filed for ${need.year}.`
                            : need.basis === 'expected'
                              ? `${need.year} hasn’t ended yet, so this is on the profit expected for the whole year${
                                    need.profit_pence ? ` (about ${pounds(need.profit_pence)})` : ''
                                }.`
                              : `From your ${need.year} records so far.`}
                        {need.basis !== 'filed' && (need.suggested_pence ?? 0) > 0
                            ? ` It counts ${pounds(need.suggested_pence ?? 0)} of money in suggested as business income that you haven’t confirmed yet.`
                            : ''}
                        {need.at_least
                            ? ` ${pounds(need.undecided_pence ?? 0)} of money in for ${need.year} is still to decide and isn’t counted, so it may be more.`
                            : ''}
                    </p>
                    {pot.pot && pct !== null && (
                        <>
                            <div className="flex justify-between">
                                <span>
                                    {pot.pot.name}: {pounds(pot.pot.pence)} of {need.at_least ? 'at least' : 'about'} {pounds(need.pence)}
                                </span>
                                <span className={pct < 100 ? 'font-medium text-amber-700' : 'text-emerald-700'}>
                                    {pct < 100 ? `about ${pounds(need.pence - pot.pot.pence)} to go` : 'enough'}
                                </span>
                            </div>
                            <Progress value={pct} tone={pct < 100 ? 'amber' : 'emerald'} />
                        </>
                    )}
                </div>
            )}
            {pot.pot_status === 'scope' && (
                <p className="text-muted-foreground text-xs">
                    To show your Tax Space here, Starling’s token needs space:read and balance:read: make a new token with them ticked in Starling’s
                    developer portal and connect it in Settings › Integrations.
                </p>
            )}
            {pot.pot_status === 'missing' && (
                <p className="text-muted-foreground text-xs">
                    Starling no longer has the Space you chose: choose another in Settings › Integrations.
                </p>
            )}
            {pot.pot_status === 'failed' && (
                <p className="text-muted-foreground text-xs">Your Tax Space’s balance couldn’t be read at the last Starling import.</p>
            )}
            <p className="text-muted-foreground text-xs">Expend never moves money: putting it aside is up to you.</p>
        </section>
    );
}

interface Props {
    years: YearCard[];
    currentYear: string;
    mtd: { starts: string; based_on: string; turnover_over: number; turnover_pence: number; applies: boolean }[];
    vat: { rolling_turnover_pence: number; threshold_pence: number };
    counts: { accounts: number; transactions: number; to_review: number; jobs_without_po: number };
    todo: { total: number; items: { key: string; title: string; detail: string | null; href: string | null; priority: number }[] };
    twoFactor: boolean;
    /** The tax pot, an estimate (older servers don't send it; null if it couldn't be worked out). */
    taxPot?: TaxPotCard | null;
    /** The first lines of the latest morning report (null until there's one from the last two days). */
    overnight?: {
        line: string;
        at: string | null;
        trial: boolean;
        items: { title: string; amount: number | null; currency: string; direction: string | null }[];
    } | null;
}

export default function Dashboard({ years, mtd, vat, counts, twoFactor, todo, overnight, taxPot }: Props) {
    // A return filed is done; one being amended is still to finish until the last day to amend it.
    const done = (y: YearCard) => y.status === 'filed' || (!!y.amending && !y.amend_open);
    const outstanding = years.filter((y) => !done(y));
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
                        {counts.jobs_without_po > 0 && (
                            <Link
                                href="/jobs?show=no_po"
                                className="mt-1 inline-block text-sm text-amber-700 underline underline-offset-2 dark:text-amber-400"
                            >
                                {counts.jobs_without_po} job{counts.jobs_without_po === 1 ? '' : 's'} without a PO
                            </Link>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* A plain link, not an Inertia visit: the Add expense screen opens from the phone's cache with no signal. */}
                        <Button variant="outline" asChild>
                            <a href="/quick">
                                <Camera /> Add expense
                            </a>
                        </Button>
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

                {overnight && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">
                                Overnight{overnight.trial && <span className="text-muted-foreground text-sm font-normal"> (trial run)</span>}
                            </h2>
                            <Link href="/intel" className="text-sm underline underline-offset-2">
                                The full report
                            </Link>
                        </div>
                        <p className="text-sm">{overnight.line}</p>
                        {overnight.items.length > 0 && (
                            <ul className="space-y-1 text-sm">
                                {overnight.items.map((i, n) => (
                                    <li key={n} className="flex items-start gap-2">
                                        <span className="mt-1.5 size-2 shrink-0 rounded-full bg-sky-500" />
                                        <span className="min-w-0">
                                            {i.title}
                                            {i.amount !== null && i.direction && i.direction !== 'none' && (
                                                <span className="text-muted-foreground tabular-nums"> · {money(Math.abs(i.amount), i.currency)}</span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {todo.total > 0 && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">To do ({todo.total})</h2>
                            <Link href="/todo" className="text-sm underline underline-offset-2">
                                See all and act
                            </Link>
                        </div>
                        <ul className="space-y-1.5 text-sm">
                            {todo.items.map((i) => (
                                <li key={i.key} className="flex items-start gap-2">
                                    <span
                                        className={`mt-1.5 size-2 shrink-0 rounded-full ${i.priority === 1 ? 'bg-rose-500' : i.priority === 2 ? 'bg-amber-500' : 'bg-slate-400'}`}
                                    />
                                    <Link href={i.href ?? '/todo'} className="min-w-0 hover:underline">
                                        <span className="font-medium">{i.title}</span>
                                        {i.detail && <span className="text-muted-foreground"> · {i.detail}</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {taxPot && <TaxPotSection pot={taxPot} />}

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
                                                {done(y)
                                                    ? 'Filed'
                                                    : y.amending
                                                      ? `Amend by ${day(y.deadline)} · ${left} days`
                                                      : y.no_notice
                                                        ? y.told_hmrc
                                                            ? 'No notice to file yet · you told HMRC'
                                                            : left >= 0
                                                              ? `No notice to file · if you need to file, tell HMRC by ${day(y.deadline)}`
                                                              : 'No notice to file from HMRC'
                                                        : y.overdue
                                                          ? `Overdue since ${day(y.deadline)}${y.deadline_basis === 'estimate' ? ' (estimated)' : ''}`
                                                          : `Due ${day(y.deadline)}${y.deadline_basis === 'estimate' ? ' (estimated)' : ''} · ${left} days`}
                                            </p>
                                            {!done(y) && !y.amending && y.deadline_basis && (
                                                <p className="text-muted-foreground text-xs">
                                                    {y.deadline_basis === 'estimate'
                                                        ? 'Estimated from your notice date; the date on HMRC’s notice counts'
                                                        : 'Assumes HMRC sent a notice to file'}
                                                </p>
                                            )}
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
