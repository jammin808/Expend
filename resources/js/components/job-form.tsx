import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { londonToday } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useForm, type InertiaFormProps } from '@inertiajs/react';
import { useId } from 'react';

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
    po_number?: string | null;
    po_not_required?: boolean;
}

/** What the form holds, as it's sent (the fee as typed, the client as the select's value). */
export interface JobFormData {
    [field: string]: string | boolean;
    title: string;
    client_id: string;
    new_client: string;
    starts_on: string;
    ends_on: string;
    start_time: string;
    end_time: string;
    location: string;
    postcode: string;
    fee: string;
    status: string;
    notes: string;
    po_number: string;
    po_not_required: boolean;
}

/** The form's own fields: an outside form's errors for anything else (a day of a picked job) are shown by its owner. */
export const JOB_FIELDS = [
    'title',
    'client_id',
    'new_client',
    'starts_on',
    'ends_on',
    'start_time',
    'end_time',
    'location',
    'postcode',
    'fee',
    'status',
    'notes',
    'po_number',
    'po_not_required',
] as const;

/** The form filled in from a job (or empty for a new one). */
export function jobFormData(job?: JobData, opts: { submitUrl?: string; newClient?: string | null } = {}): JobFormData {
    return {
        title: job?.title ?? '',
        client_id: job?.client_id ? String(job.client_id) : '',
        new_client: opts.newClient ?? '',
        starts_on: job?.starts_on ?? londonToday(),
        ends_on: job?.ends_on ?? londonToday(),
        start_time: job?.start_time ?? '',
        end_time: job?.end_time ?? '',
        location: job?.location ?? '',
        postcode: job?.postcode ?? '',
        fee: job?.fee_pence ? (job.fee_pence / 100).toFixed(2) : '',
        status: opts.submitUrl && job?.status === 'cancelled' ? 'booked' : (job?.status ?? 'booked'),
        notes: job?.notes ?? '',
        po_number: job?.po_number ?? '',
        po_not_required: job?.po_not_required ?? false,
    };
}

export function JobForm({
    job,
    clients,
    onDone,
    submitUrl,
    submitLabel,
    newClient,
    extra,
    form: outside,
    formId,
    hideSubmit = false,
    hideDates = false,
    onSubmit,
}: {
    job?: JobData;
    clients: { id: number; name: string }[];
    onDone?: () => void;
    /**
     * Post here instead (for example, adding a possible job from the job finder or the calendar). Those make a job
     * you're doing, so Cancelled isn't offered: they don't accept it (they search for the job's costs and fee).
     */
    submitUrl?: string;
    submitLabel?: string;
    newClient?: string | null;
    /** Sent along with the form (for example, which calendar entry it comes from). */
    extra?: Record<string, unknown>;
    /**
     * A form held by the page instead of this one's own (the days you picked are reviewed beside it): it's filled in
     * and saved by its owner, and only this form's own fields' errors are shown here.
     */
    form?: InertiaFormProps<JobFormData>;
    /** The form's id, so a button elsewhere (a sheet's footer) can submit it. */
    formId?: string;
    hideSubmit?: boolean;
    /** Leaves out the first and last day and the job's times: the days picked say those. */
    hideDates?: boolean;
    /** Called instead of saving (Enter in a box, or the submit button). */
    onSubmit?: () => void;
}) {
    const own = useForm(jobFormData(job, { submitUrl, newClient }));
    const form = outside ?? own;
    // Each box has its own id, so tapping its label focuses it and a screen reader names it.
    const uid = useId();
    const id = (field: string) => `${formId ?? uid}-${field}`;
    const shown = new Set<string>(['title', 'client_id', 'location', 'postcode', 'fee', 'status', 'po_number', 'po_not_required', 'notes']);
    if (!form.data.client_id) shown.add('new_client');
    if (!hideDates) ['starts_on', 'ends_on', 'start_time', 'end_time'].forEach((f) => shown.add(f));
    // With an outside form (the review sheet), each error is under its own field; anything else is listed at the end.
    const errorFor = (field: string): string | undefined => (outside ? (form.errors as Record<string, string | undefined>)[field] : undefined);
    const errors = Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([field, e]) => e && (!outside || ((JOB_FIELDS as readonly string[]).includes(field) && !shown.has(field))))
        .map(([, e]) => e as string);
    // In the review sheet on a phone: boxes 44 pixels high, and a tick with room to hit.
    const tall = outside ? 'h-11' : undefined;
    const fieldError = (field: string) => {
        const message = errorFor(field);
        return message ? (
            <p data-field-error className="text-sm text-rose-600">
                {message}
            </p>
        ) : null;
    };

    return (
        <form
            id={formId}
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                if (onSubmit) return onSubmit();
                const opts = { preserveScroll: true, onSuccess: () => (job ? onDone?.() : (form.reset(), onDone?.())) };
                if (extra) form.transform((data) => ({ ...data, ...extra }));
                if (submitUrl) form.post(submitUrl, { preserveScroll: true, onSuccess: () => onDone?.() });
                else if (job?.id) form.patch(`/jobs/${job.id}`, opts);
                else form.post('/jobs', opts);
            }}
        >
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor={id('title')}>Job</Label>
                <Input
                    id={id('title')}
                    className={tall}
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="For example: Product launch, ExCeL London"
                />
                {fieldError('title')}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor={id('client_id')}>Client</Label>
                <NativeSelect
                    id={id('client_id')}
                    className={tall}
                    value={form.data.client_id}
                    onChange={(e) => form.setData('client_id', e.target.value)}
                >
                    <option value="">No client / new client</option>
                    {clients.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </NativeSelect>
                {fieldError('client_id')}
            </div>
            {!form.data.client_id && (
                <div className="space-y-1.5">
                    <Label htmlFor={id('new_client')}>New client name</Label>
                    <Input
                        id={id('new_client')}
                        className={tall}
                        value={form.data.new_client}
                        onChange={(e) => form.setData('new_client', e.target.value)}
                    />
                    {fieldError('new_client')}
                </div>
            )}
            {!hideDates && (
                <>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('starts_on')}>First day</Label>
                        <Input
                            id={id('starts_on')}
                            type="date"
                            value={form.data.starts_on}
                            onChange={(e) => form.setData('starts_on', e.target.value)}
                        />
                        {fieldError('starts_on')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('ends_on')}>Last day</Label>
                        <Input id={id('ends_on')} type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                        {fieldError('ends_on')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('start_time')}>Start time (call time)</Label>
                        <Input
                            id={id('start_time')}
                            type="time"
                            value={form.data.start_time}
                            onChange={(e) => form.setData('start_time', e.target.value)}
                        />
                        {fieldError('start_time')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('end_time')}>Finish time</Label>
                        <Input
                            id={id('end_time')}
                            type="time"
                            value={form.data.end_time}
                            onChange={(e) => form.setData('end_time', e.target.value)}
                        />
                        {fieldError('end_time')}
                    </div>
                </>
            )}
            <div className="space-y-1.5">
                <Label htmlFor={id('location')}>Venue</Label>
                <Input id={id('location')} className={tall} value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
                {fieldError('location')}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor={id('postcode')}>Venue postcode</Label>
                <Input id={id('postcode')} className={tall} value={form.data.postcode} onChange={(e) => form.setData('postcode', e.target.value)} />
                {fieldError('postcode')}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor={id('fee')}>Agreed fee</Label>
                <Input
                    id={id('fee')}
                    className={tall}
                    inputMode="decimal"
                    value={form.data.fee}
                    onChange={(e) => form.setData('fee', e.target.value)}
                    placeholder="£"
                />
                {fieldError('fee')}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor={id('status')}>Status</Label>
                <NativeSelect id={id('status')} className={tall} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                    <option value="booked">Booked</option>
                    <option value="done">Done</option>
                    {!submitUrl && <option value="cancelled">Cancelled</option>}
                </NativeSelect>
                {fieldError('status')}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor={id('po_number')}>PO number</Label>
                <Input
                    id={id('po_number')}
                    className={tall}
                    value={form.data.po_number}
                    onChange={(e) => form.setData('po_number', e.target.value)}
                    placeholder="If the PO didn’t come as a document"
                />
                {fieldError('po_number')}
            </div>
            <div className="self-end">
                <label className={cn('flex items-center gap-2 pb-2 text-sm', outside && 'min-h-11 pb-0')}>
                    <input
                        type="checkbox"
                        className={outside ? 'size-5' : 'size-4'}
                        checked={form.data.po_not_required}
                        onChange={(e) => form.setData('po_not_required', e.target.checked)}
                    />
                    No PO needed for this job
                </label>
                {fieldError('po_not_required')}
            </div>
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor={id('notes')}>Notes</Label>
                <Textarea id={id('notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                {fieldError('notes')}
            </div>
            {errors.map((e) => (
                <p key={e} className="text-sm text-rose-600 sm:col-span-2">
                    {e}
                </p>
            ))}
            {!hideSubmit && (
                <div className="sm:col-span-2">
                    <Button disabled={form.processing}>{submitLabel ?? (job?.id ? 'Save job' : 'Add job')}</Button>
                </div>
            )}
        </form>
    );
}
