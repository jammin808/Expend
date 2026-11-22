import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { day, money } from '@/lib/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface YearRow {
    label: string;
    status: string;
    filed_on: string | null;
    hmrc_reference: string | null;
    locked: boolean;
    filed_turnover_pence: number | null;
    filed_expenses_pence: number | null;
    filed_profit_pence: number | null;
    payments_on_account_paid_pence: number;
    deadline: string;
    progress: { value_pct: number; unreviewed_count: number; suggested_count: number; total_count: number };
    has_rates: boolean;
}

const pounds = (p: number | null) => (p === null ? '' : (p / 100).toFixed(2));

function YearEditor({ year, statuses }: { year: YearRow; statuses: Record<string, string> }) {
    const form = useForm({
        status: year.status,
        filed_on: year.filed_on ?? '',
        hmrc_reference: year.hmrc_reference ?? '',
        filed_turnover: pounds(year.filed_turnover_pence),
        filed_expenses: pounds(year.filed_expenses_pence),
        filed_profit: pounds(year.filed_profit_pence),
    });
    return (
        <form
            className="grid gap-3 border-t p-4 sm:grid-cols-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.patch(`/years/${year.label}`, { preserveScroll: true });
            }}
        >
            <label className="space-y-1 text-sm">
                <span className="text-muted-foreground">Status</span>
                <select
                    className="border-input h-9 w-full rounded-md border bg-transparent px-2"
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                >
                    {Object.entries(statuses).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
            </label>
            <label className="space-y-1 text-sm">
                <span className="text-muted-foreground">Filed on</span>
                <Input type="date" value={form.data.filed_on} onChange={(e) => form.setData('filed_on', e.target.value)} />
            </label>
            <label className="space-y-1 text-sm">
                <span className="text-muted-foreground">HMRC submission reference</span>
                <Input value={form.data.hmrc_reference} onChange={(e) => form.setData('hmrc_reference', e.target.value)} />
            </label>
            <p className="text-muted-foreground text-xs sm:col-span-3">
                If this year was filed outside the app, enter its figures to compare with the next year.
            </p>
            {(['filed_turnover', 'filed_expenses', 'filed_profit'] as const).map((f) => (
                <label key={f} className="space-y-1 text-sm">
                    <span className="text-muted-foreground">{f.replace('filed_', 'Filed ')}</span>
                    <Input inputMode="decimal" value={form.data[f]} onChange={(e) => form.setData(f, e.target.value)} />
                </label>
            ))}
            <div className="sm:col-span-3">
                <Button size="sm" disabled={form.processing}>
                    Save
                </Button>
            </div>
        </form>
    );
}

export default function YearsIndex({ years, statuses }: { years: YearRow[]; statuses: Record<string, string> }) {
    const [label, setLabel] = useState('');
    const [open, setOpen] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={[{ title: 'Tax years', href: '/years' }]}>
            <Head title="Tax years" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Tax years</h1>
                        <p className="text-muted-foreground text-sm">Any year can be caught up. Each one uses that year's own tax rules.</p>
                    </div>
                    <form
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post('/years', { label }, { onSuccess: () => setLabel('') });
                        }}
                    >
                        <Input
                            placeholder="2023-24"
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                            className="w-28"
                            aria-label="Tax year to add"
                        />
                        <Button variant="outline">Add year</Button>
                    </form>
                </div>
                <div className="space-y-3">
                    {years.map((y) => (
                        <div key={y.label} className="rounded-xl border">
                            <div className="flex flex-wrap items-center gap-4 p-4">
                                <Link href={`/years/${y.label}`} className="text-lg font-semibold hover:underline">
                                    {y.label}
                                </Link>
                                <StatusPill status={y.status} />
                                <span className="text-muted-foreground text-sm">Due {day(y.deadline)}</span>
                                {!y.has_rates && <span className="text-xs text-amber-700">No tax rates loaded</span>}
                                <div className="ml-auto flex min-w-48 flex-1 items-center gap-3 sm:max-w-xs">
                                    <Progress value={y.progress.value_pct} />
                                    <span className="text-muted-foreground w-10 text-right text-sm">{y.progress.value_pct}%</span>
                                </div>
                                <Button size="sm" variant="ghost" onClick={() => setOpen(open === y.label ? null : y.label)}>
                                    {open === y.label ? 'Close' : 'Edit'}
                                </Button>
                            </div>
                            {y.filed_profit_pence !== null && open !== y.label && (
                                <p className="text-muted-foreground px-4 pb-3 text-sm">Filed profit {money(y.filed_profit_pence)}</p>
                            )}
                            {open === y.label && <YearEditor year={y} statuses={statuses} />}
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
