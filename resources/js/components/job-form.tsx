import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';

export interface JobData {
    id?: number;
    title: string;
    client_id: number | null;
    starts_on: string;
    ends_on: string;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    postcode: string | null;
    fee_pence: number | null;
    status: string;
    notes: string | null;
}

export function JobForm({ job, clients, onDone }: { job?: JobData; clients: { id: number; name: string }[]; onDone?: () => void }) {
    const form = useForm({
        title: job?.title ?? '',
        client_id: job?.client_id ? String(job.client_id) : '',
        new_client: '',
        starts_on: job?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: job?.ends_on ?? new Date().toISOString().slice(0, 10),
        start_time: job?.start_time ?? '',
        end_time: job?.end_time ?? '',
        location: job?.location ?? '',
        postcode: job?.postcode ?? '',
        fee: job?.fee_pence ? (job.fee_pence / 100).toFixed(2) : '',
        status: job?.status ?? 'booked',
        notes: job?.notes ?? '',
    });

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                const opts = { preserveScroll: true, onSuccess: () => (job ? onDone?.() : (form.reset(), onDone?.())) };
                if (job?.id) form.patch(`/jobs/${job.id}`, opts);
                else form.post('/jobs', opts);
            }}
        >
            <div className="space-y-1.5 sm:col-span-2">
                <Label>Job</Label>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="For example: Product launch, ExCeL London"
                />
            </div>
            <div className="space-y-1.5">
                <Label>Client</Label>
                <NativeSelect value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)}>
                    <option value="">No client / new client</option>
                    {clients.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            {!form.data.client_id && (
                <div className="space-y-1.5">
                    <Label>New client name</Label>
                    <Input value={form.data.new_client} onChange={(e) => form.setData('new_client', e.target.value)} />
                </div>
            )}
            <div className="space-y-1.5">
                <Label>First day</Label>
                <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Last day</Label>
                <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Start time (call time)</Label>
                <Input type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Finish time</Label>
                <Input type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Venue</Label>
                <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Venue postcode</Label>
                <Input value={form.data.postcode} onChange={(e) => form.setData('postcode', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label>Agreed fee</Label>
                <Input inputMode="decimal" value={form.data.fee} onChange={(e) => form.setData('fee', e.target.value)} placeholder="£" />
            </div>
            <div className="space-y-1.5">
                <Label>Status</Label>
                <NativeSelect value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                    <option value="booked">Booked</option>
                    <option value="done">Done</option>
                    <option value="cancelled">Cancelled</option>
                </NativeSelect>
            </div>
            <div className="space-y-1.5 sm:col-span-2">
                <Label>Notes</Label>
                <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </div>
            {Object.values(form.errors).map((e) => (
                <p key={e} className="text-sm text-rose-600 sm:col-span-2">
                    {e}
                </p>
            ))}
            <div className="sm:col-span-2">
                <Button disabled={form.processing}>{job?.id ? 'Save job' : 'Add job'}</Button>
            </div>
        </form>
    );
}
