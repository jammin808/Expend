import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { day, londonToday, money } from '@/lib/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Trip {
    id: number;
    trip_on: string;
    vehicle: string;
    job: { id: number; title: string } | null;
    from_place: string;
    to_place: string;
    miles: string;
    purpose: string | null;
    claim_pence: number;
    note: string;
}

interface Vehicle {
    id: number;
    name: string;
    type: string;
    method: string;
}

interface Props {
    year: string;
    years: string[];
    locked: boolean;
    rates: { car_van_first: number; car_van_after: number; threshold_miles: number; motorcycle: number } | null;
    trips: Trip[];
    total_pence: number;
    total_miles: string;
    vehicles: Vehicle[];
    jobs: { id: number; title: string; starts_on: string; location: string | null }[];
    fromJobs: {
        job_id: number;
        title: string;
        starts_on: string;
        ends_on: string;
        venue: string | null;
        postcode: string;
        one_way: number;
        trips: number;
        miles: number;
        trip_dates: string[];
        why: string;
    }[];
    /** Jobs this year you said you didn't drive to: never offered for mileage until you put them back */
    notDriven: { job_id: number; title: string; starts_on: string; postcode: string | null }[];
    homePostcode: string | null;
}

/** The vehicle trips from jobs go on unless you pick another: a car or van at the flat rate, as most job trips are. */
function defaultVehicle(vehicles: Vehicle[]): Vehicle | undefined {
    return vehicles.find((v) => v.type === 'car_van' && v.method === 'simplified') ?? vehicles.find((v) => v.method === 'simplified') ?? vehicles[0];
}

export default function Mileage({
    year,
    years,
    locked,
    rates,
    trips,
    total_pence,
    total_miles,
    vehicles,
    jobs,
    fromJobs,
    notDriven,
    homePostcode,
}: Props) {
    // Jobs you unticked. Everything else on the list is ticked, so switching tax year or adding trips never leaves
    // ticks or counts from another list behind.
    const [skipped, setSkipped] = useState<number[]>([]);
    const chosen = fromJobs.filter((p) => !skipped.includes(p.job_id));
    const [jobVehicleId, setJobVehicleId] = useState<string>(() => String(defaultVehicle(vehicles)?.id ?? ''));
    const jobVehicle = vehicles.find((v) => String(v.id) === jobVehicleId) ?? defaultVehicle(vehicles);
    const trip = useForm({
        vehicle_id: vehicles[0]?.id ? String(vehicles[0].id) : '',
        work_job_id: '',
        trip_on: londonToday(),
        from_place: 'Home',
        to_place: '',
        miles: '',
        return_trip: true as boolean,
        purpose: '',
    });
    const vehicle = useForm({ name: '', type: 'car_van', method: 'simplified' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Mileage', href: '/mileage' }]}>
            <Head title="Mileage" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Mileage</h1>
                        <p className="text-muted-foreground text-sm">
                            Business miles at HMRC's flat rate count as a travel expense, linked to the job like a train or taxi fare.
                            {rates &&
                                ` ${year}: ${rates.car_van_first}p a mile for the first ${rates.threshold_miles.toLocaleString()}, then ${rates.car_van_after}p.`}
                        </p>
                    </div>
                    <NativeSelect
                        className="w-32"
                        value={year}
                        onChange={(e) => router.get('/mileage', { year: e.target.value }, { preserveState: true })}
                        aria-label="Tax year"
                    >
                        {[...new Set([year, ...years])].map((y) => (
                            <option key={y}>{y}</option>
                        ))}
                    </NativeSelect>
                </div>

                {locked && (
                    <p className="flex items-center gap-2 rounded-xl border p-3 text-sm">
                        <Lock className="size-4 shrink-0" />
                        <span>
                            {year} is locked, so its trips can’t be added, changed or removed.{' '}
                            <Link href={`/years/${year}`} className="underline">
                                Unlock it on the {year} page
                            </Link>{' '}
                            if you need to change them.
                        </span>
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground text-sm">Business miles in {year}</p>
                        <p className="text-xl font-semibold tabular-nums">{Number(total_miles).toLocaleString()}</p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground text-sm">Mileage claim</p>
                        <p className="text-xl font-semibold tabular-nums">{money(total_pence)}</p>
                    </div>
                </div>

                {fromJobs.length > 0 && jobVehicle && (
                    <section className="space-y-2 rounded-xl border border-sky-300 bg-sky-50/50 p-4 dark:border-sky-800 dark:bg-sky-950/20">
                        <h2 className="font-semibold">Add trips for the jobs you drove to</h2>
                        <p className="text-muted-foreground text-sm">
                            From home ({homePostcode}) to each venue and back. Distances are estimated from the postcodes (straight line × 1.25 for
                            roads): correct any that differ. Jobs you reached by train, plane, coach or hire car (claim the hire and its fuel
                            instead), jobs that already have mileage, and jobs whose last day is still to come aren’t listed.
                        </p>
                        <ul className="bg-background divide-y rounded-lg border text-sm">
                            {fromJobs.map((p) => (
                                <li key={p.job_id}>
                                    <label className="flex items-center gap-3 p-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={!skipped.includes(p.job_id)}
                                            onChange={(e) =>
                                                setSkipped(e.target.checked ? skipped.filter((id) => id !== p.job_id) : [...skipped, p.job_id])
                                            }
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate">{p.title}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {day(p.starts_on)} · {p.venue ?? p.postcode} · {p.why}
                                            </span>
                                        </span>
                                        <span className="tabular-nums">{p.miles} mi</span>
                                        <button
                                            type="button"
                                            className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2"
                                            title="Not offered again until you put it back"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                router.post('/mileage/not-driven', { job_id: p.job_id }, { preserveScroll: true });
                                            }}
                                        >
                                            Didn’t drive
                                        </button>
                                    </label>
                                </li>
                            ))}
                        </ul>
                        <div className="flex flex-wrap items-center gap-2">
                            <Label htmlFor="job-trips-vehicle" className="text-sm">
                                Vehicle
                            </Label>
                            <NativeSelect
                                id="job-trips-vehicle"
                                className="w-auto"
                                value={String(jobVehicle.id)}
                                onChange={(e) => setJobVehicleId(e.target.value)}
                            >
                                {vehicles.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.name}
                                        {v.method === 'actual' ? ' (actual costs)' : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Button
                                disabled={chosen.length === 0}
                                onClick={() =>
                                    router.post(
                                        '/mileage/from-jobs',
                                        { year, vehicle_id: jobVehicle.id, job_ids: chosen.map((p) => p.job_id) },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Add {chosen.length} {chosen.length === 1 ? 'job’s' : 'jobs’'} trips (
                                {chosen.reduce((t, p) => t + p.miles, 0).toFixed(1)} miles)
                            </Button>
                        </div>
                        {jobVehicle.method === 'actual' && (
                            <p className="text-muted-foreground text-sm">
                                {jobVehicle.name} uses actual costs, so these trips record your business miles but don’t add a mileage claim.
                            </p>
                        )}
                    </section>
                )}
                {notDriven.length > 0 && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <h2 className="text-sm font-semibold">Jobs you didn’t drive to</h2>
                        <p className="text-muted-foreground text-sm">Not offered for mileage. Put one back if you did drive.</p>
                        <ul className="divide-y rounded-lg border text-sm">
                            {notDriven.map((j) => (
                                <li key={j.job_id} className="flex items-center justify-between gap-3 p-2">
                                    <span className="min-w-0 flex-1 truncate">
                                        {j.title} <span className="text-muted-foreground text-xs">· {day(j.starts_on)}</span>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.post(`/mileage/not-driven/${j.job_id}/undo`, {}, { preserveScroll: true })}
                                    >
                                        Put back
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {!homePostcode && (
                    <p className="text-muted-foreground text-sm">Add your home postcode in Settings › Ledger to add trips for your jobs in one go.</p>
                )}

                {vehicles.length === 0 ? (
                    <form
                        className="grid gap-2 rounded-xl border p-4 sm:grid-cols-[1fr_10rem_14rem_auto]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            vehicle.post('/vehicles', { preserveScroll: true });
                        }}
                    >
                        <Input
                            placeholder="Vehicle name, e.g. My car"
                            value={vehicle.data.name}
                            onChange={(e) => vehicle.setData('name', e.target.value)}
                        />
                        <NativeSelect value={vehicle.data.type} onChange={(e) => vehicle.setData('type', e.target.value)}>
                            <option value="car_van">Car or van</option>
                            <option value="motorcycle">Motorcycle</option>
                        </NativeSelect>
                        <NativeSelect value={vehicle.data.method} onChange={(e) => vehicle.setData('method', e.target.value)}>
                            <option value="simplified">Flat-rate mileage</option>
                            <option value="actual">Actual costs (no mileage claim)</option>
                        </NativeSelect>
                        <Button>Add vehicle</Button>
                    </form>
                ) : (
                    <form
                        className="grid gap-3 rounded-xl border p-4 sm:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            trip.post('/mileage', {
                                preserveScroll: true,
                                onSuccess: () => trip.reset('to_place', 'miles', 'purpose', 'work_job_id'),
                            });
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label>Date</Label>
                            <Input type="date" value={trip.data.trip_on} onChange={(e) => trip.setData('trip_on', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>From</Label>
                            <Input value={trip.data.from_place} onChange={(e) => trip.setData('from_place', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>To</Label>
                            <Input value={trip.data.to_place} onChange={(e) => trip.setData('to_place', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Miles (one way)</Label>
                            <Input inputMode="decimal" value={trip.data.miles} onChange={(e) => trip.setData('miles', e.target.value)} />
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Job</Label>
                            <NativeSelect
                                value={trip.data.work_job_id}
                                onChange={(e) => {
                                    const job = jobs.find((j) => String(j.id) === e.target.value);
                                    trip.setData((d) => ({
                                        ...d,
                                        work_job_id: e.target.value,
                                        to_place: d.to_place || job?.location || '',
                                        trip_on: job?.starts_on ?? d.trip_on,
                                    }));
                                }}
                            >
                                <option value="">General business (no job)</option>
                                {jobs.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {j.title} · {j.starts_on}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Vehicle</Label>
                            <NativeSelect value={trip.data.vehicle_id} onChange={(e) => trip.setData('vehicle_id', e.target.value)}>
                                {vehicles.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.name}
                                        {v.method === 'actual' ? ' (actual costs)' : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <Checkbox checked={trip.data.return_trip} onCheckedChange={(v) => trip.setData('return_trip', !!v)} />
                            Return trip (doubles the miles)
                        </label>
                        <div className="sm:col-span-4">
                            <Button disabled={trip.processing}>Add trip</Button>
                            {Object.values(trip.errors).map((e) => (
                                <p key={e} className="mt-2 text-sm text-rose-600">
                                    {e}
                                </p>
                            ))}
                        </div>
                    </form>
                )}

                <ul className="divide-y rounded-xl border text-sm">
                    {trips.map((t) => (
                        <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                            <span className="min-w-0">
                                <span className="block">
                                    {t.from_place} → {t.to_place} · {t.miles} miles
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {day(t.trip_on)} · {t.vehicle} · {t.job ? t.job.title : 'General business'} · {t.note}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="font-medium tabular-nums">{money(t.claim_pence)}</span>
                                {!locked && (
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Remove trip"
                                        onClick={() =>
                                            window.confirm('Remove this trip?') && router.delete(`/mileage/${t.id}`, { preserveScroll: true })
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                    {trips.length === 0 && <li className="text-muted-foreground p-4">No trips in {year} yet.</li>}
                </ul>
            </div>
        </AppLayout>
    );
}
