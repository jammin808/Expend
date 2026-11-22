import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

interface Props {
    yearChoices: string[];
    tracked: string[];
    accounts: { id: number; name: string; kind: string; provider: string | null }[];
    settings: Record<string, unknown>;
}

type AccountRow = { name: string; kind: 'bank' | 'credit_card'; provider: string };

export default function Setup({ yearChoices, tracked, accounts, settings }: Props) {
    const form = useForm<{
        accounts: AccountRow[];
        years: string[];
        prior_year: { turnover: string; expenses: string; profit: string };
        home_postcode: string;
        scottish_taxpayer: boolean;
        vehicle: { name: string; method: 'simplified' | 'actual' };
    }>({
        accounts: accounts.length
            ? []
            : [
                  { name: 'Starling Business', kind: 'bank', provider: 'starling' },
                  { name: 'Amex', kind: 'credit_card', provider: 'amex' },
                  { name: 'Capital One', kind: 'credit_card', provider: 'capital_one' },
              ],
        years: tracked.length ? tracked : yearChoices.slice(1, 3),
        prior_year: { turnover: '', expenses: '', profit: '' },
        home_postcode: (settings.home_postcode as string) ?? '',
        scottish_taxpayer: !!settings.scottish_taxpayer,
        vehicle: { name: '', method: 'simplified' },
    });

    const toggleYear = (label: string) =>
        form.setData('years', form.data.years.includes(label) ? form.data.years.filter((y) => y !== label) : [...form.data.years, label]);
    const oldest = [...form.data.years].sort()[0];
    const before = oldest ? `${Number(oldest.slice(0, 4)) - 1}-${oldest.slice(2, 4)}` : null;

    return (
        <AppLayout breadcrumbs={[{ title: 'Setup', href: '/setup' }]}>
            <Head title="Setup" />
            <form
                className="mx-auto w-full max-w-2xl space-y-10 p-4 md:p-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/setup');
                }}
            >
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Let's set up your books</h1>
                    <p className="text-muted-foreground mt-1">Five quick choices. You can change any of them later in Settings.</p>
                </div>

                <section className="space-y-3">
                    <h2 className="font-semibold">1. Which tax years still need doing?</h2>
                    <div className="flex flex-wrap gap-2">
                        {yearChoices.map((label) => (
                            <label key={label} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                                <Checkbox checked={form.data.years.includes(label)} onCheckedChange={() => toggleYear(label)} />
                                {label}
                            </label>
                        ))}
                    </div>
                    {form.errors.years && <p className="text-sm text-rose-600">{form.errors.years}</p>}
                </section>

                <section className="space-y-3">
                    <h2 className="font-semibold">2. Your accounts</h2>
                    {accounts.length > 0 && <p className="text-muted-foreground text-sm">Already added: {accounts.map((a) => a.name).join(', ')}.</p>}
                    {form.data.accounts.map((a, i) => (
                        <div key={i} className="grid grid-cols-[1fr_10rem_auto] gap-2">
                            <Input
                                value={a.name}
                                aria-label="Account name"
                                onChange={(e) =>
                                    form.setData(
                                        'accounts',
                                        form.data.accounts.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)),
                                    )
                                }
                            />
                            <select
                                className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                                value={a.kind}
                                aria-label="Account type"
                                onChange={(e) =>
                                    form.setData(
                                        'accounts',
                                        form.data.accounts.map((x, j) => (j === i ? { ...x, kind: e.target.value as AccountRow['kind'] } : x)),
                                    )
                                }
                            >
                                <option value="bank">Bank account</option>
                                <option value="credit_card">Credit card</option>
                            </select>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove"
                                onClick={() =>
                                    form.setData(
                                        'accounts',
                                        form.data.accounts.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => form.setData('accounts', [...form.data.accounts, { name: '', kind: 'bank', provider: 'other' }])}
                    >
                        <Plus /> Add account
                    </Button>
                </section>

                {before && (
                    <section className="space-y-3">
                        <h2 className="font-semibold">3. Your last filed return ({before})</h2>
                        <p className="text-muted-foreground text-sm">
                            Optional. Used to flag big changes year on year. Copy the figures from that return.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {(['turnover', 'expenses', 'profit'] as const).map((field) => (
                                <div key={field} className="space-y-1.5">
                                    <Label className="capitalize">{field}</Label>
                                    <Input
                                        inputMode="decimal"
                                        placeholder="£"
                                        value={form.data.prior_year[field]}
                                        onChange={(e) => form.setData('prior_year', { ...form.data.prior_year, [field]: e.target.value })}
                                    />
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="font-semibold">4. About you</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="postcode">Home postcode</Label>
                            <Input
                                id="postcode"
                                value={form.data.home_postcode}
                                onChange={(e) => form.setData('home_postcode', e.target.value)}
                                placeholder="Used to judge overnight stays"
                            />
                        </div>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <Checkbox checked={form.data.scottish_taxpayer} onCheckedChange={(v) => form.setData('scottish_taxpayer', !!v)} />
                            I'm a Scottish taxpayer
                        </label>
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="font-semibold">5. A vehicle for mileage (optional)</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input
                            placeholder="For example: My car"
                            value={form.data.vehicle.name}
                            onChange={(e) => form.setData('vehicle', { ...form.data.vehicle, name: e.target.value })}
                        />
                        <select
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={form.data.vehicle.method}
                            onChange={(e) => form.setData('vehicle', { ...form.data.vehicle, method: e.target.value as 'simplified' | 'actual' })}
                        >
                            <option value="simplified">Claim mileage at HMRC's flat rate</option>
                            <option value="actual">Claim actual running costs instead</option>
                        </select>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Once you use the flat rate for a vehicle, HMRC expects you to keep using it for that vehicle.
                    </p>
                </section>

                <Button type="submit" size="lg" disabled={form.processing}>
                    Save and continue
                </Button>
            </form>
        </AppLayout>
    );
}
