import HeadingSmall from '@/components/heading-small';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface Props {
    settings: Record<string, unknown>;
    calendar: { events: number; synced: string | null };
    own: { names: string[]; saved_names: string[]; other_accounts: string[] };
    class2_years: string[];
}

export default function LedgerSettings({ settings, calendar, own, class2_years }: Props) {
    // Weeks of Class 2 for each year it was compulsory; empty means a full year.
    const savedWeeks = (settings.class2_weeks ?? {}) as Record<string, number>;
    const weeks: Record<string, string> = Object.fromEntries(class2_years.map((y) => [y, savedWeeks[y] === undefined ? '' : String(savedWeeks[y])]));
    const form = useForm({
        home_postcode: (settings.home_postcode as string) ?? '',
        scottish_taxpayer: !!settings.scottish_taxpayer,
        overnight_near_miles: Number(settings.overnight_near_miles ?? 12),
        overnight_far_miles: Number(settings.overnight_far_miles ?? 40),
        early_start: (settings.early_start as string) ?? '08:00',
        late_finish: (settings.late_finish as string) ?? '22:00',
        mileage_threshold_scope: (settings.mileage_threshold_scope as string) ?? 'all_vehicles',
        mtd_volunteer_from: (settings.mtd_volunteer_from as string) ?? '',
        // The year an exemption runs from; the old tick with no year meant from 2026-27.
        mtd_exempt_from: (settings.mtd_exempt_from as string) ?? (settings.mtd_exempt ? '2026-27' : ''),
        mtd_exempt_never_required: !!settings.mtd_exempt_never_required,
        class2_weeks: weeks,
        calendar_ics_url: '',
        clear_calendar: false as boolean,
        own_names: (own.saved_names.length ? own.saved_names : own.names).join('\n'),
        own_other_accounts: own.other_accounts.join('\n'),
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'Settings', href: '/settings/ledger' }]}>
            <Head title="Ledger settings" />
            <SettingsLayout>
                <form
                    className="space-y-8"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/ledger', { preserveScroll: true, onSuccess: () => form.setData('calendar_ics_url', '') });
                    }}
                >
                    <section className="space-y-4">
                        <HeadingSmall title="You" description="Used for tax estimates and to judge overnight stays." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Home postcode</Label>
                                <Input value={form.data.home_postcode} onChange={(e) => form.setData('home_postcode', e.target.value)} />
                            </div>
                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                <Checkbox checked={form.data.scottish_taxpayer} onCheckedChange={(v) => form.setData('scottish_taxpayer', !!v)} />
                                Scottish taxpayer (turns off the estimate)
                            </label>
                        </div>
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Making Tax Digital for Income Tax"
                            description="Decides which late filing and late payment penalties apply. If your income in 2024-25 was over £50,000 you're in Making Tax Digital from 6 April 2026 and the new penalties (points for a late return) apply from 2026-27; Expend works that out from your records. The new penalties are announced for everyone else from 2027-28 but aren't law yet."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="mtd_volunteer_from">HMRC accepted me as a volunteer for the new penalties from</Label>
                                <NativeSelect
                                    id="mtd_volunteer_from"
                                    value={form.data.mtd_volunteer_from}
                                    onChange={(e) => form.setData('mtd_volunteer_from', e.target.value)}
                                >
                                    <option value="">I'm not a volunteer</option>
                                    {['2024-25', '2025-26', '2026-27', '2027-28'].map((y) => (
                                        <option key={y} value={y}>
                                            {y}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <p className="text-muted-foreground text-xs">
                                    The tax year in HMRC's letter confirming it. Signing up for Making Tax Digital isn't enough on its own.
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="mtd_exempt_from">HMRC told me I'm exempt from Making Tax Digital from</Label>
                                <NativeSelect
                                    id="mtd_exempt_from"
                                    value={form.data.mtd_exempt_from}
                                    onChange={(e) => form.setData('mtd_exempt_from', e.target.value)}
                                >
                                    <option value="">I'm not exempt</option>
                                    {['2026-27', '2027-28', '2028-29', '2029-30'].map((y) => (
                                        <option key={y} value={y}>
                                            {y}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <p className="text-muted-foreground text-xs">
                                    The tax year the exemption starts in. Exempt from 2026-27: the current penalties apply to 2026-27 again. If you
                                    were in Making Tax Digital in 2026-27 and the exemption starts later, the new penalties still apply to you by law;
                                    you just stop sending quarterly updates, so your points threshold drops from 4 to 2.
                                </p>
                                {form.data.mtd_exempt_from && (
                                    <label className="flex items-start gap-2 pt-1 text-sm">
                                        <Checkbox
                                            checked={form.data.mtd_exempt_never_required}
                                            onCheckedChange={(v) => form.setData('mtd_exempt_never_required', !!v)}
                                        />
                                        <span>
                                            HMRC's letter says the earlier requirements are treated as never having applied
                                            <span className="text-muted-foreground block text-xs">
                                                Rare. Only tick this if the letter says so: then you're treated as never having been in Making Tax
                                                Digital.
                                            </span>
                                        </span>
                                    </label>
                                )}
                            </div>
                        </div>
                    </section>

                    {class2_years.length > 0 && (
                        <section className="space-y-4">
                            <HeadingSmall
                                title="Class 2 National Insurance"
                                description="Up to 2023-24 Class 2 was charged for each week you were self-employed and aged 16 to State Pension age, on profits over the threshold. Leave a year empty if that was the whole year (52 weeks)."
                            />
                            <div className="grid gap-3 sm:grid-cols-3">
                                {class2_years.map((y) => (
                                    <div key={y} className="space-y-1.5">
                                        <Label htmlFor={`class2_weeks_${y}`}>Weeks self-employed in {y}</Label>
                                        <Input
                                            id={`class2_weeks_${y}`}
                                            type="number"
                                            min={0}
                                            max={53}
                                            step={1}
                                            placeholder="52"
                                            value={form.data.class2_weeks[y] ?? ''}
                                            onChange={(e) => form.setData('class2_weeks', { ...form.data.class2_weeks, [y]: e.target.value })}
                                        />
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Your names and accounts"
                            description="Money moving between your own accounts isn’t spending or income. Payments to or from these names, and lines naming these accounts, are treated as transfers and left out of every total. Your imported accounts are recognised already."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="own_names">Names you go by on statements</Label>
                                <textarea
                                    id="own_names"
                                    rows={3}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    placeholder={'One per line, e.g.\nAlex Smith'}
                                    value={form.data.own_names}
                                    onChange={(e) => form.setData('own_names', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Initials and titles are matched too (“A Smith”, “Mr A J Smith”); someone else with your surname isn’t.
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="own_other_accounts">Other accounts of yours that aren’t imported</Label>
                                <textarea
                                    id="own_other_accounts"
                                    rows={3}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    placeholder={'One per line, as it appears on statements, e.g.\nNationwide savings\nMonzo'}
                                    value={form.data.own_other_accounts}
                                    onChange={(e) => form.setData('own_other_accounts', e.target.value)}
                                />
                            </div>
                        </div>
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Overnight stays"
                            description="Used by the hotel-stay checks coming in the next version. Under the near distance a stay goes to review; between the two it depends on start and finish times; beyond the far distance it's business."
                        />
                        <div className="grid gap-3 sm:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label>Near (miles)</Label>
                                <Input
                                    type="number"
                                    value={form.data.overnight_near_miles}
                                    onChange={(e) => form.setData('overnight_near_miles', Number(e.target.value))}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Far (miles)</Label>
                                <Input
                                    type="number"
                                    value={form.data.overnight_far_miles}
                                    onChange={(e) => form.setData('overnight_far_miles', Number(e.target.value))}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Early start before</Label>
                                <Input type="time" value={form.data.early_start} onChange={(e) => form.setData('early_start', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Late finish after</Label>
                                <Input type="time" value={form.data.late_finish} onChange={(e) => form.setData('late_finish', e.target.value)} />
                            </div>
                        </div>
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Calendar"
                            description="Paste your Google Calendar's “Secret address in iCal format” (Settings › your calendar › Integrate calendar). It's stored encrypted and only read."
                        />
                        <Input
                            type="url"
                            placeholder={
                                settings.calendar_ics_url
                                    ? 'A feed is saved. Paste a new one to replace it.'
                                    : 'https://calendar.google.com/calendar/ical/…/basic.ics'
                            }
                            value={form.data.calendar_ics_url}
                            onChange={(e) => form.setData('calendar_ics_url', e.target.value)}
                        />
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!settings.calendar_ics_url}
                                onClick={() => router.post('/settings/calendar/sync', {}, { preserveScroll: true })}
                            >
                                Sync now
                            </Button>
                            <span className="text-muted-foreground">
                                {calendar.events.toLocaleString()} events{calendar.synced ? ` · last synced ${calendar.synced}` : ''}
                            </span>
                            {!!settings.calendar_ics_url && (
                                <label className="flex items-center gap-2">
                                    <Checkbox checked={form.data.clear_calendar} onCheckedChange={(v) => form.setData('clear_calendar', !!v)} />{' '}
                                    Remove the saved feed
                                </label>
                            )}
                        </div>
                    </section>

                    <section className="space-y-2">
                        <HeadingSmall
                            title="AI"
                            description="Claude reads documents, answers questions and suggests decisions; you always make the final decision."
                        />
                        <Link href="/settings/integrations" className="text-sm font-medium underline underline-offset-4">
                            Set up AI, email and Telegram in Integrations
                        </Link>
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall
                            title="Mileage"
                            description="HMRC's 10,000-mile threshold. The cautious reading applies it across all your flat-rate cars and vans."
                        />
                        <NativeSelect
                            className="max-w-sm"
                            value={form.data.mileage_threshold_scope}
                            onChange={(e) => form.setData('mileage_threshold_scope', e.target.value)}
                        >
                            <option value="all_vehicles">Across all vehicles (cautious)</option>
                            <option value="per_vehicle">Per vehicle</option>
                        </NativeSelect>
                    </section>

                    {Object.values(form.errors).map((e) => (
                        <p key={e} className="text-sm text-rose-600">
                            {e}
                        </p>
                    ))}
                    <Button disabled={form.processing}>Save settings</Button>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
