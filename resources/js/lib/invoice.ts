import { patchJson, postJson } from '@/lib/api';
import { londonToday, toPence } from '@/lib/format';
import { type ChangedSheet, type InvoiceDetail, type InvoiceLine, WORK_SOURCES } from '@/types/invoices';

/**
 * The check screen's requests and sums. Every save carries the lines version and the last change the page showed, so
 * a page left open never saves over lines it didn't show; each line carries the version you saw.
 */

/** A quantity as the server takes it: up to 7 digits and 2 decimals, more than 0; in hundredths, or null. */
export function quantityHundredths(text: string): number | null {
    const t = text.trim();
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(t)) return null;
    const [whole, frac = ''] = t.split('.');
    const n = parseInt(whole, 10) * 100 + parseInt((frac + '00').slice(0, 2), 10);
    return n > 0 ? n : null;
}

/** A line's amount as the server works it out: quantity × price, rounded half up to the penny. */
export function lineAmount(quantity: string, unitPrice: string): number | null {
    const q = quantityHundredths(quantity);
    const u = toPence(unitPrice);
    if (q === null || u === null) return null;
    const raw = Math.abs(q * u);
    return Math.sign(u) * Math.floor((raw + 50) / 100);
}

/** A line as the draft form sends it: tied to the version you saw. */
export interface LineInput {
    id: number | null;
    version: string | null;
    description: string;
    quantity: string;
    unit_price: string;
    included: boolean;
    sort?: number;
    source_quote?: string | null;
}

export interface DraftFields {
    bill_to_name: string;
    bill_to_address: string;
    bill_to_email: string;
    po_number: string;
    currency: string;
    notes: string;
    client_id: number | null;
}

/** The draft's own fields as they are now. */
export function draftFields(invoice: InvoiceDetail): DraftFields {
    return {
        bill_to_name: invoice.bill_to_name ?? '',
        bill_to_address: invoice.bill_to_address ?? '',
        bill_to_email: invoice.bill_to_email ?? '',
        po_number: invoice.po_number ?? '',
        currency: invoice.currency,
        notes: invoice.notes ?? '',
        client_id: invoice.client_id,
    };
}

/** Every line as it is now, ready to send back unchanged (or with one changed). */
export function linesAsInput(lines: InvoiceLine[]): LineInput[] {
    return lines.map((l) => ({
        id: l.id,
        version: l.version,
        description: l.description,
        quantity: l.quantity,
        unit_price: l.unit_price,
        included: l.included,
        sort: l.sort,
        source_quote: l.source_quote,
    }));
}

/**
 * Save the draft (PATCH invoices/{id}): its fields and every line, with the lines version and the last change shown.
 * A 409 (changed since) comes back as an ApiError 'changed' carrying the draft as it is now.
 */
export function saveDraft(invoice: InvoiceDetail, changes: { fields?: Partial<DraftFields>; lines?: LineInput[] }) {
    const fields = { ...draftFields(invoice), ...changes.fields };
    return patchJson<{ message: string }>(`/invoices/${invoice.id}`, {
        ...fields,
        bill_to_email: fields.bill_to_email.trim() === '' ? null : fields.bill_to_email.trim(),
        work_job_id: invoice.work_job_id,
        lines: changes.lines ?? linesAsInput(invoice.lines),
        lines_version: invoice.lines_version,
        seen_up_to: invoice.seen_up_to,
    });
}

/** One press on one line (tick, untick, reset, remove, keep, same), tied to the version you saw. */
export function lineAction(invoiceId: number, line: InvoiceLine, action: 'tick' | 'untick' | 'reset' | 'remove' | 'keep' | 'same', otherId?: number) {
    return patchJson<{ message: string; lines_version: string; seen_up_to: number | null }>(`/invoices/${invoiceId}/lines/${line.id}`, {
        action,
        version: line.version,
        ...(otherId ? { other_id: otherId } : {}),
    });
}

/** "Got it": the changes up to the last one shown are seen. */
export function markSeen(invoiceId: number, upTo: number) {
    return postJson<{ message: string }>(`/invoices/${invoiceId}/seen`, { up_to: upTo });
}

/** The draft as a 409 sent it back, when it did. */
export function changedSheet(data: unknown): ChangedSheet | null {
    const sheet = (data as { sheet?: ChangedSheet | null } | null)?.sheet;
    return sheet && Array.isArray(sheet.lines) ? sheet : null;
}

export type LineGroup = 'work' | 'travel' | 'earlier' | 'yours';

export const GROUP_TITLES: Record<LineGroup, string> = {
    work: 'Work',
    travel: 'Travel and expenses',
    earlier: 'From earlier jobs',
    yours: 'Your lines',
};

/** Which group a line is shown in: the job's work, its travel and expenses, earlier jobs' (or the client's), or yours. */
export function groupOf(line: InvoiceLine, jobId: number | null): LineGroup {
    if (line.source === null) return 'yours';
    if (WORK_SOURCES.includes(line.source)) return line.source_job_id === null || line.source_job_id === jobId ? 'work' : 'earlier';
    if (line.source_job_id !== null && line.source_job_id !== jobId) return 'earlier';
    if (line.source_job_id === null && jobId !== null) return 'earlier';
    return 'travel';
}

/** What a line's flag means for you, in a few words (a badge). */
export const FLAG_BADGES: Record<string, string> = {
    gone: 'gone from the job',
    no_amount: 'no price',
    check: 'check',
    maybe_same: 'maybe the same',
    unconfirmed: 'not confirmed',
    no_payment: 'no payment yet',
};

/** What a line's flag asks of you, in a sentence (the line sheet). */
export const FLAG_WORDS: Record<string, string> = {
    gone: 'It’s no longer linked to the job: remove it, or keep it as your own line.',
    no_amount: 'It has no price yet: type the price, or untick it.',
    check: 'Check its quantity and price before you issue it.',
    maybe_same: 'It may be the same as one of your own lines: check before you tick it.',
    unconfirmed: 'Its payment isn’t confirmed yet. Issuing confirms it: you’ll see the payment’s split first.',
    no_payment: 'There’s no payment for it yet.',
};

/** Why a line is ticked or not, in one muted line. */
export function lineWhy(line: InvoiceLine, client: string | null, hasTerms: boolean): string {
    if (line.source === null) return line.included ? 'Your own line' : 'Your own line, left off';
    if (line.ticked_by === 'you') return line.included ? 'You ticked it' : 'You unticked it';
    if (line.source === 'po') return 'From the PO';
    if (WORK_SOURCES.includes(line.source)) return line.included ? 'From the job' : 'From the job: not ticked';
    if (line.flag === 'no_amount') return 'Not ticked: it has no price yet';
    if (line.flag === 'maybe_same') return 'Not ticked: it may already be on one of your lines';
    const who = client ?? 'the client';
    if (!hasTerms) return line.included ? 'Ticked' : `Not ticked: say how ${who} pays travel and expenses`;
    return line.included ? `Ticked: ${who} usually pays this` : `Not ticked: ${who} doesn’t usually pay this`;
}

/** A cost's id for its charge buttons (CostRef::toId): its ref with the # as a colon. Null for a line that isn't a cost. */
export function costId(line: InvoiceLine): string | null {
    if (!line.source_ref || (line.source !== 'cost' && line.source !== 'mileage')) return null;
    return line.source_ref.replace('#', ':');
}

/** The payment behind a cost line, for its drawer ("tx:381#j45" is bank 381), or null. */
export function paymentOf(line: InvoiceLine): { source: 'bank' | 'manual'; id: number } | null {
    const m = /^(tx|exp):(\d+)/.exec(line.source_ref ?? '');
    if (!m || line.source !== 'cost') return null;
    return { source: m[1] === 'tx' ? 'bank' : 'manual', id: parseInt(m[2], 10) };
}

/**
 * A date and time from the server, in UK time ("2026-06-05 14:02"), the short way: "Fri 5 Jun, 14:02". Another year's
 * says its year ("Fri 13 Jun 2025, 09:30").
 */
export function when(at: string | null | undefined): string {
    if (!at) return '';
    const d = new Date(at.slice(0, 10) + 'T12:00:00');
    if (Number.isNaN(d.getTime())) return at;
    const otherYear = at.slice(0, 4) !== londonToday().slice(0, 4);
    const date = d
        .toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', ...(otherYear ? { year: 'numeric' } : {}) })
        .replace('Sept', 'Sep');
    return at.length > 10 ? `${date}, ${at.slice(11, 16)}` : date;
}

/** A date plus some days (YYYY-MM-DD). */
export function addDays(iso: string, days: number): string {
    const d = new Date(iso + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}
