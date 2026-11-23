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
}

export default function LedgerSettings({ settings, calendar, own }: Props) {
    const form = useForm({
        home_postcode: (settings.home_postcode as string) ?? '',
        scottish_taxpayer: !!settings.scottish_taxpayer,
        overnight_near_miles: Number(settings.overnight_near_miles ?? 12),
        overnight_far_miles: Number(settings.overnight_far_miles ?? 40),
        early_start: (settings.early_start as string) ?? '08:00',
        late_finish: (settings.late_finish as string) ?? '22:00',
        mileage_threshold_scope: (settings.mileage_threshold_scope as string) ?? 'all_vehicles',
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
