import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { datesBetween } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

// Types, not interfaces: Inertia's forms take only plain object types.
export type DayRow = {
    date: string;
    kind: string;
};

const dayLabel = (d: string) =>
    new Date(d + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

/**
 * The days some work covers, which needn't be consecutive (a rig day, then the show, then a de-rig days later), each
 * with its kind. Add one day, or every day from one date to another.
 */
export function DaysEditor({ days, kinds, onChange }: { days: DayRow[]; kinds: Record<string, string>; onChange: (days: DayRow[]) => void }) {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const sorted = (list: DayRow[]) => {
        const seen = new Map<string, DayRow>();
        for (const d of list) if (d.date) seen.set(d.date, d);
        return [...seen.values()].sort((a, b) => a.date.localeCompare(b.date));
    };
    const first = Object.keys(kinds)[0] ?? 'work';
    const plain = 'work' in kinds ? 'work' : first;

    return (
        <div className="space-y-2">
            {days.length > 0 ? (
                <ul className="divide-y rounded-lg border text-sm">
                    {days.map((d, i) => (
                        <li key={d.date + i} className="flex flex-wrap items-center gap-2 p-2">
                            <span className="w-36 font-medium">{d.date ? dayLabel(d.date) : 'No date'}</span>
                            <NativeSelect
                                className="h-8 w-44"
                                aria-label={`Kind of day on ${d.date}`}
                                value={d.kind}
                                onChange={(e) => onChange(days.map((x, j) => (j === i ? { ...x, kind: e.target.value } : x)))}
                            >
                                {Object.entries(kinds).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </NativeSelect>
                            <span className="flex-1" />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label={`Remove ${d.date}`}
                                onClick={() => onChange(days.filter((_, j) => j !== i))}
                            >
                                <X />
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground text-sm">No days yet.</p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Input type="date" className="w-40" aria-label="First day to add" value={from} onChange={(e) => setFrom(e.target.value)} />
                <span className="text-muted-foreground text-xs">to</span>
                <Input type="date" className="w-40" aria-label="Last day to add (optional)" value={to} onChange={(e) => setTo(e.target.value)} />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={!from || (!!to && to < from)}
                    onClick={() => {
                        const add = (to ? datesBetween(from, to) : [from]).map((date) => ({ date, kind: plain }));
                        onChange(sorted([...days, ...add.filter((a) => !days.some((d) => d.date === a.date))]));
                        setFrom('');
                        setTo('');
                    }}
                >
                    <Plus /> Add {to && to !== from ? 'days' : 'day'}
                </Button>
            </div>
        </div>
    );
}

export type OpportunityFormData = {
    title: string;
    status: string;
    hold_rank: string;
    client_name: string;
    contact_name: string;
    contact_email: string;
    role: string;
    venue: string;
    postcode: string;
    days: DayRow[];
    reply_by: string;
    rate_amount: string;
    rate_currency: string;
    rate_unit: string;
    po_number: string;
    note: string;
};

export const emptyOpportunity: OpportunityFormData = {
    title: '',
    status: 'enquiry',
    hold_rank: '',
    client_name: '',
    contact_name: '',
    contact_email: '',
    role: '',
    venue: '',
    postcode: '',
    days: [],
    reply_by: '',
    rate_amount: '',
    rate_currency: 'GBP',
    rate_unit: 'day',
    po_number: '',
    note: '',
};

/**
 * The details of some work you might get: to correct what was read from an email, or to add work you were told about
 * yourself. Saving changes nothing anywhere else and sends nothing.
 */
export function OpportunityForm({
    initial,
    statuses,
    dayKinds,
    rateUnits,
    url,
    method,
    submitLabel,
    onDone,
}: {
    initial: OpportunityFormData;
    statuses: Record<string, string>;
    dayKinds: Record<string, string>;
    rateUnits: Record<string, string>;
    url: string;
    method: 'post' | 'patch';
    submitLabel: string;
    onDone?: () => void;
}) {
    const form = useForm<OpportunityFormData>(initial);
    const held = ['held', 'offered', 'confirmed'].includes(form.data.status);

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form[method](url, { preserveScroll: true, onSuccess: () => onDone?.() });
            }}
        >
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="opp-title">What it is</Label>
                <Input
                    id="opp-title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="For example: Acme Awards, Leeds Arena"
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-status">Where it stands</Label>
                <NativeSelect id="opp-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                    {Object.entries(statuses).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-rank">Option (1st, 2nd…)</Label>
                <Input
                    id="opp-rank"
                    type="number"
                    min={1}
                    max={9}
                    disabled={!held}
                    value={form.data.hold_rank}
                    onChange={(e) => form.setData('hold_rank', e.target.value)}
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-client">Client</Label>
                <Input id="opp-client" value={form.data.client_name} onChange={(e) => form.setData('client_name', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-role">Your role</Label>
                <Input
                    id="opp-role"
                    value={form.data.role}
                    onChange={(e) => form.setData('role', e.target.value)}
                    placeholder="For example: Sound No.2"
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-contact">Contact</Label>
                <Input id="opp-contact" value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-email">Contact’s email</Label>
                <Input id="opp-email" type="email" value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-venue">Venue</Label>
                <Input id="opp-venue" value={form.data.venue} onChange={(e) => form.setData('venue', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-postcode">Venue postcode</Label>
                <Input id="opp-postcode" value={form.data.postcode} onChange={(e) => form.setData('postcode', e.target.value)} />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
                <Label>Days</Label>
                <DaysEditor days={form.data.days} kinds={dayKinds} onChange={(days) => form.setData('days', days)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-rate">Rate</Label>
                <div className="flex gap-2">
                    <Input
                        id="opp-rate"
                        inputMode="decimal"
                        className="min-w-0 flex-1"
                        value={form.data.rate_amount}
                        onChange={(e) => form.setData('rate_amount', e.target.value)}
                        placeholder="350.00"
                    />
                    <Input
                        aria-label="Currency"
                        className="w-20"
                        maxLength={3}
                        value={form.data.rate_currency}
                        onChange={(e) => form.setData('rate_currency', e.target.value.toUpperCase())}
                    />
                    <NativeSelect
                        aria-label="Rate per"
                        className="w-36"
                        value={form.data.rate_unit}
                        onChange={(e) => form.setData('rate_unit', e.target.value)}
                    >
                        {Object.entries(rateUnits).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-reply">Reply by</Label>
                <Input id="opp-reply" type="date" value={form.data.reply_by} onChange={(e) => form.setData('reply_by', e.target.value)} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="opp-po">PO number</Label>
                <Input id="opp-po" value={form.data.po_number} onChange={(e) => form.setData('po_number', e.target.value)} />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="opp-note">Note</Label>
                <Textarea id="opp-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </div>
            {Object.entries(form.errors).map(([k, e]) => (
                <p key={k} className="text-sm text-rose-600 sm:col-span-2">
                    {e}
                </p>
            ))}
            <div className="sm:col-span-2">
                <Button disabled={form.processing}>{submitLabel}</Button>
            </div>
        </form>
    );
}
