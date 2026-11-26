import { ResponsiveSheet, SheetBody, SheetFooter } from '@/components/calendar-pick/responsive-sheet';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { money, toPence } from '@/lib/format';
import { type SharedData } from '@/types';
import { type AppliedTerm, type OwnTerm, type TermsSheet } from '@/types/clients';
import { router } from '@inertiajs/react';
import { useId, useMemo, useState } from 'react';

/** How each way of charging is said. */
export const CHARGE_WORDS: Record<string, string> = {
    at_cost: 'At cost',
    per_mile: 'Per mile',
    their_form: 'On their expense form',
    none: 'Not charged',
    per_day: 'Per day',
    per_job: 'Per job',
    day_rate: 'At my day rate',
};

/** Kinds of cost said the way you'd list them. */
const PLURAL: Record<string, string> = { other: 'Other costs', fines: 'Fines', fuel: 'Fuel', meals: 'Meals' };

/** Ways of charging that need a rate (per mile, per day, per job; mileage on their form). */
function needsRate(cls: string, charge: string | null): boolean {
    return charge === 'per_mile' || charge === 'per_day' || charge === 'per_job' || (cls === 'mileage' && charge === 'their_form');
}

/** "45p a mile", "£25 a day", "£40 a job". */
function rateWords(charge: string, rate: number | null): string {
    if (rate === null) return CHARGE_WORDS[charge] ?? charge;
    const amount = rate < 100 && (charge === 'per_mile' || charge === 'their_form') ? `${rate}p` : money(rate).replace(/\.00$/, '');
    const per = charge === 'per_day' ? 'a day' : charge === 'per_job' ? 'a job' : 'a mile';
    return charge === 'their_form' ? `On their expense form, ${amount} ${per}` : `${amount} ${per}`;
}

/** PHP sends an empty map as []: either way, a lookup by kind of cost. */
function asMap<T>(m: Record<string, T> | []): Record<string, T> {
    return Array.isArray(m) ? {} : m;
}

/**
 * What applies, in a few lines: kinds of cost grouped by how they're charged ("At cost: Train, Taxi, Hotel", "Mileage:
 * 45p a mile", "Not charged: Fuel"). On a job, what the job changes is marked "this job".
 */
export function termsLines(sheet: TermsSheet): { words: string; own: boolean }[] {
    const applies = asMap<AppliedTerm>(sheet.applies);
    const rated: { words: string; own: boolean }[] = [];
    const groups = new Map<string, { charge: string; names: string[]; own: boolean }>();
    for (const c of sheet.classes) {
        const t = applies[c.class];
        if (!t || t.charge === null) continue;
        const name = PLURAL[c.class] ?? c.label;
        const own = t.from === 'job';
        if (needsRate(c.class, t.charge)) {
            rated.push({ words: `${name}: ${rateWords(t.charge, t.rate_minor)}`, own });
            continue;
        }
        const key = `${t.charge}|${own}`;
        const g = groups.get(key) ?? { charge: t.charge, names: [], own };
        g.names.push(name);
        groups.set(key, g);
    }
    return [...rated, ...[...groups.values()].map((g) => ({ words: `${CHARGE_WORDS[g.charge] ?? g.charge}: ${g.names.join(', ')}`, own: g.own }))];
}

/** Whether anything is set (the job's own or its client's usual). */
export function termsSet(sheet: TermsSheet): boolean {
    return Object.values(asMap<AppliedTerm>(sheet.applies)).some((t) => t.charge !== null);
}

type Row = { charge: string | null; rate: string };

const pounds = (minor: number | null) => (minor === null ? '' : (minor / 100).toFixed(2));

function rowsFrom(sheet: TermsSheet): Record<string, Row> {
    const own = asMap<OwnTerm>(sheet.own);
    return Object.fromEntries(
        sheet.classes.map((c) => [c.class, { charge: own[c.class]?.charge ?? null, rate: pounds(own[c.class]?.rate_minor ?? null) }]),
    );
}

/** A preset's rows, as the server's ChargeTermsWriter::preset makes them: shown first, saved only when you press Save. */
function presetRows(sheet: TermsSheet, preset: string, fee: string, per: 'per_job' | 'per_day'): Record<string, Row> {
    const rows: Record<string, Row> = {};
    for (const c of sheet.classes) {
        const atCost = (preset === 'at_cost' || preset === 'mileage') && c.charges.includes('at_cost') && c.class !== 'fines';
        rows[c.class] = { charge: atCost ? 'at_cost' : 'none', rate: '' };
    }
    if (preset === 'mileage') {
        rows.mileage = { charge: 'per_mile', rate: pounds(sheet.mileage_rate) };
        rows.fuel = { charge: 'none', rate: '' };
    }
    if (preset === 'travel_fee') rows.travel_fee = { charge: per, rate: fee };
    return rows;
}

/**
 * Travel and expenses: how a client usually pays them (or, on a job, what this job changes), as a summary with Change.
 * The editor opens in a sheet: presets first ("Everything at cost", "Mileage at 55p + fares at cost", "It's in my rate",
 * "Fixed travel fee"), then one row per kind of cost. Saving is tied to the rows as they were loaded.
 */
export function TermsEditor({
    sheet,
    url,
    owner,
    clientName,
    startOpen = false,
}: {
    sheet: TermsSheet;
    /** Where the save goes: PUT clients/{id}/terms or jobs/{id}/terms. */
    url: string;
    owner: 'client' | 'job';
    /** The paying client's name (on a job: "Acme's usual"). */
    clientName?: string | null;
    startOpen?: boolean;
}) {
    const [open, setOpen] = useState(startOpen);
    const lines = termsLines(sheet);
    const set = termsSet(sheet);
    const ownCount = Object.keys(asMap<OwnTerm>(sheet.own)).length;

    return (
        <div className="space-y-2">
            {set ? (
                <ul className="space-y-1 text-sm">
                    {lines.map((l) => (
                        <li key={l.words} className="break-words">
                            {l.words}
                            {owner === 'job' && l.own && <span className="text-muted-foreground"> · this job</span>}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm">
                    Not set yet{owner === 'job' && clientName ? ` for ${clientName}` : ''}: travel and expenses aren’t ticked on the invoice until you
                    say how they’re paid.
                </p>
            )}
            {owner === 'job' && clientName && set && (
                <p className="text-muted-foreground text-xs">
                    {ownCount === 0
                        ? `${clientName}’s usual.`
                        : `This job changes ${ownCount === 1 ? '1 kind of cost' : `${ownCount} kinds of cost`}; the rest are ${clientName}’s usual.`}
                </p>
            )}
            <Button variant="outline" className="h-11" onClick={() => setOpen(true)}>
                {owner === 'job' ? (ownCount > 0 ? 'Change for this job' : 'Change for this job only') : set ? 'Change' : 'Set it up'}
            </Button>
            <TermsSheetEditor
                key={sheet.fp}
                sheet={sheet}
                url={url}
                owner={owner}
                clientName={clientName}
                open={open}
                onClose={() => setOpen(false)}
            />
        </div>
    );
}

function TermsSheetEditor({
    sheet,
    url,
    owner,
    clientName,
    open,
    onClose,
}: {
    sheet: TermsSheet;
    url: string;
    owner: 'client' | 'job';
    clientName?: string | null;
    open: boolean;
    onClose: () => void;
}) {
    const uid = useId();
    const loaded = useMemo(() => rowsFrom(sheet), [sheet]);
    const [rows, setRows] = useState<Record<string, Row>>(loaded);
    const [fee, setFee] = useState<{ amount: string; per: 'per_job' | 'per_day' } | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const applies = asMap<AppliedTerm>(sheet.applies);
    const dirty = JSON.stringify(rows) !== JSON.stringify(loaded);
    const perMile = rows.mileage?.charge === 'per_mile';

    const setRow = (cls: string, patch: Partial<Row>) => setRows((r) => ({ ...r, [cls]: { ...r[cls], ...patch } }));
    const close = () => {
        setRows(loaded);
        setFee(null);
        setProblem(null);
        onClose();
    };
    const usual = (cls: string): string => {
        const t = applies[cls];
        if (owner !== 'job') return 'Not set';
        if (!t || t.from !== 'client' || t.charge === null) return clientName ? `${clientName}’s usual` : 'The client’s usual';
        return `${clientName ?? 'Client'}’s usual: ${needsRate(cls, t.charge) ? rateWords(t.charge, t.rate_minor) : (CHARGE_WORDS[t.charge] ?? t.charge).toLowerCase()}`;
    };
    const save = () => {
        for (const c of sheet.classes) {
            const r = rows[c.class];
            if (r?.charge && needsRate(c.class, r.charge) && (toPence(r.rate) ?? 0) <= 0)
                return setProblem(`Give the rate for ${(PLURAL[c.class] ?? c.label).toLowerCase()}, like 0.45 or 25.00.`);
        }
        setBusy(true);
        setProblem(null);
        router.put(
            url,
            {
                fp: sheet.fp,
                rows: Object.fromEntries(
                    sheet.classes.map((c) => {
                        const r = rows[c.class];
                        return [c.class, r?.charge ? { charge: r.charge, rate: needsRate(c.class, r.charge) ? r.rate : null } : null];
                    }),
                ),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const error = (page.props as unknown as SharedData).flash?.error;
                    if (error) setProblem(error);
                    else onClose();
                },
                onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That didn’t save. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveSheet
            open={open}
            onOpenChange={(o) => !o && close()}
            title={owner === 'job' ? 'Travel and expenses for this job' : 'Travel and expenses'}
            description={sheet.help}
            dirty={dirty}
            onDiscard={() => setRows(loaded)}
            discardNote="What you changed here is lost."
        >
            <SheetBody className="space-y-4">
                <div className="space-y-2">
                    <p className="text-sm font-medium">Start from</p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {sheet.presets.map((p) => (
                            <Button
                                key={p.key}
                                type="button"
                                variant="outline"
                                className="h-auto min-h-11 justify-start py-2 text-left whitespace-normal"
                                onClick={() => {
                                    if (p.key === 'travel_fee') return setFee({ amount: '', per: 'per_job' });
                                    setFee(null);
                                    setRows(presetRows(sheet, p.key, '', 'per_job'));
                                }}
                            >
                                {p.label}
                            </Button>
                        ))}
                    </div>
                    {fee && (
                        <div className="bg-muted/40 space-y-2 rounded-lg border p-3">
                            <label className="text-sm font-medium" htmlFor={`${uid}-fee`}>
                                Travel fee
                            </label>
                            <div className="flex flex-wrap gap-2">
                                <div className="relative w-32">
                                    <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2">£</span>
                                    <Input
                                        id={`${uid}-fee`}
                                        inputMode="decimal"
                                        className="h-11 pl-7"
                                        placeholder="40.00"
                                        value={fee.amount}
                                        onChange={(e) => setFee({ ...fee, amount: e.target.value })}
                                    />
                                </div>
                                <NativeSelect
                                    aria-label="How often"
                                    className="h-11 w-32"
                                    value={fee.per}
                                    onChange={(e) => setFee({ ...fee, per: e.target.value as 'per_job' | 'per_day' })}
                                >
                                    <option value="per_job">a job</option>
                                    <option value="per_day">a day</option>
                                </NativeSelect>
                                <Button
                                    type="button"
                                    className="h-11"
                                    onClick={() => {
                                        if ((toPence(fee.amount) ?? 0) <= 0) return setProblem('Say how much the travel fee is, like 40.00.');
                                        setProblem(null);
                                        setRows(presetRows(sheet, 'travel_fee', fee.amount, fee.per));
                                        setFee(null);
                                    }}
                                >
                                    Use it
                                </Button>
                            </div>
                            <p className="text-muted-foreground text-xs">Fares aren’t charged as well: the fee covers them.</p>
                        </div>
                    )}
                </div>

                <div className="space-y-1">
                    <p className="text-sm font-medium">Each kind of cost</p>
                    <ul className="divide-y rounded-lg border">
                        {sheet.classes.map((c) => {
                            const r = rows[c.class] ?? { charge: null, rate: '' };
                            const field = `${uid}-${c.class}`;
                            return (
                                <li key={c.class} className="space-y-2 p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <label htmlFor={field} className="min-w-[7rem] text-sm font-medium">
                                            {PLURAL[c.class] ?? c.label}
                                        </label>
                                        <NativeSelect
                                            id={field}
                                            className="h-11 w-full sm:w-60"
                                            value={r.charge ?? ''}
                                            onChange={(e) => setRow(c.class, { charge: e.target.value || null })}
                                        >
                                            <option value="">{usual(c.class)}</option>
                                            {c.charges.map((ch) => (
                                                <option key={ch} value={ch}>
                                                    {CHARGE_WORDS[ch] ?? ch}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                    {needsRate(c.class, r.charge) && (
                                        <div className="flex items-center justify-end gap-2">
                                            <div className="relative w-32">
                                                <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2">
                                                    £
                                                </span>
                                                <Input
                                                    aria-label={`Rate for ${(PLURAL[c.class] ?? c.label).toLowerCase()}`}
                                                    inputMode="decimal"
                                                    className="h-11 pl-7"
                                                    placeholder={r.charge === 'per_mile' || c.class === 'mileage' ? '0.45' : '25.00'}
                                                    value={r.rate}
                                                    onChange={(e) => setRow(c.class, { rate: e.target.value })}
                                                />
                                            </div>
                                            <span className="text-muted-foreground w-14 text-sm">
                                                {r.charge === 'per_day' ? 'a day' : r.charge === 'per_job' ? 'a job' : 'a mile'}
                                            </span>
                                        </div>
                                    )}
                                    {c.class === 'fuel' && perMile && <p className="text-muted-foreground text-xs">{sheet.per_mile_note}.</p>}
                                </li>
                            );
                        })}
                    </ul>
                </div>
                {problem && (
                    <p className="text-sm text-rose-700 dark:text-rose-400" role="alert">
                        {problem}
                    </p>
                )}
            </SheetBody>
            <SheetFooter className="flex flex-col gap-2 sm:flex-row-reverse">
                <Button className="h-11" disabled={busy || !dirty} onClick={save}>
                    Save
                </Button>
                <Button variant="outline" className="h-11" onClick={close}>
                    Cancel
                </Button>
            </SheetFooter>
        </ResponsiveSheet>
    );
}
