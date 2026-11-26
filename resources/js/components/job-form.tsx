import { ClientSelect } from '@/components/client-select';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { londonToday, money, toPence } from '@/lib/format';
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
    /** The client's name, when the page has it (a client merged away isn't in the picker's list). */
    client?: string | null;
    /** Who the work was for, when another client pays (an agency's job for a production company). */
    end_client_id?: number | null;
    end_client?: string | null;
    /** A day or hourly rate, in pence. */
    rate_pence?: number | null;
    rate_unit?: string | null;
    days_agreed?: number | null;
    done_at?: string | null;
    cancel_charge?: string | null;
    cancel_fee_pence?: number | null;
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
    end_client_id: string;
    rate: string;
    rate_unit: string;
    days_agreed: string;
    cancel_charge: string;
    cancel_fee: string;
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
    'end_client_id',
    'rate',
    'rate_unit',
    'days_agreed',
    'cancel_charge',
    'cancel_fee',
] as const;

const pounds = (pence: number | null | undefined) => (pence ? (pence / 100).toFixed(2) : '');

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
        end_client_id: job?.end_client_id ? String(job.end_client_id) : '',
        rate: pounds(job?.rate_pence),
        rate_unit: job?.rate_unit ?? 'day',
        days_agreed: job?.days_agreed ? String(job.days_agreed) : '',
        cancel_charge: job?.cancel_charge ?? '',
        cancel_fee: pounds(job?.cancel_fee_pence),
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
    workingDays,
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
    /** The job's working days (travel days left out): the days agreed are filled with this when you set a fee or day rate. */
    workingDays?: number;
}) {
    const own = useForm(jobFormData(job, { submitUrl, newClient }));
    const form = outside ?? own;
    // Each box has its own id, so tapping its label focuses it and a screen reader names it.
    const uid = useId();
    const id = (field: string) => `${formId ?? uid}-${field}`;
    // The end client, the rate and the days agreed (and what a cancelled job owes) are this app's own: the calendar and
    // the job finder make a job with the basics, and the job's page takes the rest.
    const full = !submitUrl && !outside;
    // In the review sheet and the job's own form on a phone: boxes 44 pixels high, and a tick with room to hit.
    const tall = outside || full ? 'h-11' : undefined;
    const shown = new Set<string>(['title', 'client_id', 'location', 'postcode', 'fee', 'status', 'po_number', 'po_not_required', 'notes']);
    if (!form.data.client_id) shown.add('new_client');
    if (!hideDates) ['starts_on', 'ends_on', 'start_time', 'end_time'].forEach((f) => shown.add(f));
    if (full) ['end_client_id', 'rate', 'rate_unit', 'days_agreed', 'cancel_charge', 'cancel_fee'].forEach((f) => shown.add(f));
    const editing = !!job?.id && !submitUrl;
    // A fee or day rate fills the days agreed with the job's working days, when they're empty ("£750 for 3 days").
    const fillDays = (value: string, isRate = false) => {
        if (!full || !workingDays || (form.data.days_agreed ?? '') !== '' || toPence(value) === null) return;
        if (isRate && (form.data.rate_unit ?? 'day') !== 'day') return;
        form.setData('days_agreed', String(workingDays));
    };
    const feePence = toPence(form.data.fee);
    const ratePence = toPence(form.data.rate ?? '');
    const agreed = Number(form.data.days_agreed ?? '') || 0;
    // With an outside form (the review sheet), each error is under its own field; anything else is listed at the end.
    const errorFor = (field: string): string | undefined => (outside ? (form.errors as Record<string, string | undefined>)[field] : undefined);
    const errors = Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([field, e]) => e && (!outside || ((JOB_FIELDS as readonly string[]).includes(field) && !shown.has(field))))
        .map(([, e]) => e as string);

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
                // What a cancelled job owes is only sent with Cancelled.
                const owes = (data: JobFormData) => (data.status === 'cancelled' ? data : { ...data, cancel_charge: '', cancel_fee: '' });
                if (extra) form.transform((data) => ({ ...owes(data), ...extra }));
                else if (full) form.transform(owes);
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
                <Label htmlFor={id('client_id')}>{full ? 'Client (who pays)' : 'Client'}</Label>
                <ClientSelect
                    id={id('client_id')}
                    clients={clients}
                    value={form.data.client_id ? Number(form.data.client_id) : null}
                    name={job?.client ?? null}
                    allowNone
                    noneLabel="No client yet"
                    placeholder={form.data.new_client ? `New: ${form.data.new_client}` : 'Pick the client'}
                    className="h-11"
                    onChange={(c) => form.setData((d: JobFormData) => ({ ...d, client_id: c ? String(c.id) : '', new_client: '' }))}
                />
                {!form.data.client_id && form.data.new_client && (
                    <p className="text-muted-foreground text-sm">
                        “{form.data.new_client}” is added as a new client when you save.{' '}
                        <button type="button" className="min-h-10 underline underline-offset-2" onClick={() => form.setData('new_client', '')}>
                            Don’t add it
                        </button>
                    </p>
                )}
                {fieldError('client_id')}
                {fieldError('new_client')}
            </div>
            {full && (
                <div className="space-y-1.5">
                    <Label htmlFor={id('end_client_id')}>End client (who it was for)</Label>
                    <ClientSelect
                        id={id('end_client_id')}
                        clients={clients}
                        value={form.data.end_client_id ? Number(form.data.end_client_id) : null}
                        name={job?.end_client ?? null}
                        allowNone
                        noneLabel="The same as who pays"
                        placeholder="The same as who pays"
                        className="h-11"
                        onChange={(c) => form.setData('end_client_id', c ? String(c.id) : '')}
                    />
                    <p className="text-muted-foreground text-xs">For an agency’s job: the production company or venue. Optional.</p>
                    {fieldError('end_client_id')}
                </div>
            )}
            {!hideDates && (
                <>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('starts_on')}>First day</Label>
                        <Input
                            id={id('starts_on')}
                            className={tall}
                            type="date"
                            value={form.data.starts_on}
                            onChange={(e) => form.setData('starts_on', e.target.value)}
                        />
                        {fieldError('starts_on')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('ends_on')}>Last day</Label>
                        <Input
                            id={id('ends_on')}
                            className={tall}
                            type="date"
                            value={form.data.ends_on}
                            onChange={(e) => form.setData('ends_on', e.target.value)}
                        />
                        {fieldError('ends_on')}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={id('start_time')}>Start time (call time)</Label>
                        <Input
                            id={id('start_time')}
                            className={tall}
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
                            className={tall}
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
                    onBlur={(e) => fillDays(e.target.value)}
                    placeholder="£"
                />
                {full && <p className="text-muted-foreground text-xs">For the whole job.</p>}
                {fieldError('fee')}
            </div>
            {full && (
                <div className="space-y-1.5">
                    <Label htmlFor={id('rate')}>Rate</Label>
                    <div className="flex gap-2">
                        <Input
                            id={id('rate')}
                            className="h-11 min-w-0 flex-1"
                            inputMode="decimal"
                            value={form.data.rate ?? ''}
                            onChange={(e) => form.setData('rate', e.target.value)}
                            onBlur={(e) => fillDays(e.target.value, true)}
                            placeholder="£"
                        />
                        <NativeSelect
                            aria-label="Rate for"
                            className="h-11 w-28"
                            value={form.data.rate_unit ?? 'day'}
                            onChange={(e) => form.setData('rate_unit', e.target.value)}
                        >
                            <option value="day">a day</option>
                            <option value="hour">an hour</option>
                        </NativeSelect>
                    </div>
                    <p className="text-muted-foreground text-xs">With a fee too, the rate prices any extra days.</p>
                    {fieldError('rate')}
                    {fieldError('rate_unit')}
                </div>
            )}
            {full && (
                <div className="space-y-1.5">
                    <Label htmlFor={id('days_agreed')}>Days agreed</Label>
                    <Input
                        id={id('days_agreed')}
                        className="h-11 w-28"
                        inputMode="numeric"
                        value={form.data.days_agreed ?? ''}
                        onChange={(e) => form.setData('days_agreed', e.target.value.replace(/\D/g, '').slice(0, 3))}
                    />
                    {agreed > 0 && (feePence || ((form.data.rate_unit ?? 'day') === 'day' && ratePence)) ? (
                        <p className="text-muted-foreground text-xs">
                            {feePence
                                ? `${money(feePence)} for ${agreed} ${agreed === 1 ? 'day' : 'days'}`
                                : `${money(ratePence)} a day for ${agreed} ${agreed === 1 ? 'day' : 'days'}`}
                            . Days beyond these are extra.
                        </p>
                    ) : (
                        <p className="text-muted-foreground text-xs">Filled in with the job’s working days when you set a fee or day rate.</p>
                    )}
                    {fieldError('days_agreed')}
                </div>
            )}
            <div className="space-y-1.5">
                <Label htmlFor={id('status')}>Status</Label>
                <NativeSelect id={id('status')} className={tall} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                    <option value="booked">Booked</option>
                    <option value="done">Done</option>
                    {!submitUrl && <option value="cancelled">Cancelled</option>}
                </NativeSelect>
                {full && form.data.status === 'done' && job?.status !== 'done' && (
                    <p className="text-muted-foreground text-xs">
                        {editing
                            ? 'Saving marks it done and gets its invoice ready to check.'
                            : 'Added as done: you’ll be asked whether to draft its invoice.'}
                    </p>
                )}
                {full && editing && form.data.status === 'booked' && job?.status === 'done' && (
                    <p className="text-muted-foreground text-xs">Back to booked: the draft Done made goes, unless you’ve changed it.</p>
                )}
                {fieldError('status')}
            </div>
            {full && form.data.status === 'cancelled' && (
                <div className="space-y-1.5">
                    <Label htmlFor={id('cancel_charge')}>Do they owe you anything?</Label>
                    <NativeSelect
                        id={id('cancel_charge')}
                        className="h-11"
                        value={form.data.cancel_charge || 'none'}
                        onChange={(e) => form.setData('cancel_charge', e.target.value)}
                    >
                        <option value="none">No, nothing</option>
                        <option value="fee">A cancellation fee</option>
                        <option value="costs">Just my costs</option>
                    </NativeSelect>
                    {(form.data.cancel_charge || 'none') === 'fee' && (
                        <Input
                            aria-label="Cancellation fee"
                            className="h-11"
                            inputMode="decimal"
                            placeholder="£"
                            value={form.data.cancel_fee ?? ''}
                            onChange={(e) => form.setData('cancel_fee', e.target.value)}
                        />
                    )}
                    {fieldError('cancel_charge')}
                    {fieldError('cancel_fee')}
                </div>
            )}
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
                <label className={cn('flex items-center gap-2 pb-2 text-sm', (outside || full) && 'min-h-11 pb-0')}>
                    <input
                        type="checkbox"
                        className={outside || full ? 'size-5' : 'size-4'}
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
                    <Button className={tall} disabled={form.processing}>
                        {submitLabel ?? (job?.id ? 'Save job' : 'Add job')}
                    </Button>
                </div>
            )}
        </form>
    );
}
