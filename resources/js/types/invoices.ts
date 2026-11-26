/**
 * The invoice pages' props, as InvoiceController sends them (index, show, its draft sheet and issued view) and the
 * JSON its presses answer with. Owner-facing words come from the server where it has them (state_words, a change's
 * words, a check's message); ids, refs and versions here are for the requests only, never shown.
 */

/** income design v3's state of an invoice: what has come in against it. */
export type InvoiceState = 'paid' | 'part_paid' | 'closed' | 'check_currency' | 'unpaid' | 'draft' | 'void';

/** One invoice as lists show it (InvoiceController::row). status is draft, issued, paid (issued and paid in full) or void. */
export interface InvoiceRow {
    id: number;
    number: string | null;
    status: 'draft' | 'issued' | 'paid' | 'void';
    state: InvoiceState;
    outstanding_minor: number;
    client: string | null;
    client_id: number | null;
    bill_to_name: string | null;
    job: { id: number; title: string } | null;
    work_job_id: number | null;
    po_number: string | null;
    currency: string;
    total_minor: number;
    paid_minor: number;
    issue_date: string | null;
    due_date: string | null;
    /** It has gone to the client (by any route, or you marked it as sent). One not sent is never overdue. */
    sent?: boolean;
    overdue: boolean;
    overdue_days: number | null;
}

/** Where a line comes from: the job's work, a cost or a mileage trip, a PO line, or null for your own typed line. */
export type LineSource = 'po' | 'fee' | 'days' | 'hours' | 'extra' | 'tdays' | 'tfee' | 'meals' | 'cancel' | 'cost' | 'mileage';

/** What a line needs before it's issued (gone, no_amount, check block issuing while it's ticked) or is worth a look. */
export type LineFlag = 'gone' | 'no_amount' | 'check' | 'maybe_same' | 'unconfirmed' | 'no_payment';

/** The job's own work: these line sources are grouped as "Work". */
export const WORK_SOURCES: LineSource[] = ['po', 'fee', 'days', 'hours', 'extra', 'tdays', 'tfee', 'meals', 'cancel'];

/** A draft's line (InvoiceController::lineProps): every line, ticked or not, with its version for a press tied to it. */
export interface InvoiceLine {
    id: number;
    version: string;
    sort: number;
    description: string;
    /** Decimal text, like "1.00" or "142.50". */
    quantity: string;
    /** Decimal text in the invoice's currency, like "450.00". */
    unit_price: string;
    amount_minor: number;
    source_quote: string | null;
    included: boolean;
    source: LineSource | null;
    /** For requests only (a cost's id for its charge buttons): never shown. */
    source_ref: string | null;
    source_job_id: number | null;
    source_job: string | null;
    flag: LineFlag | null;
    own_text: boolean;
    own_amount: boolean;
    ticked_by: 'you' | null;
    /**
     * A draft's payment cost on a job (one that can be shared with another job): the payment as it is now, its
     * fingerprint sent back with the share, and its whole split in words.
     */
    payment?: LinePayment | null;
}

/** A payment as the share step shows it. */
export interface LinePayment {
    fp: string;
    split: { amount_minor: number; words: string }[];
}

/** An invoice as its page shows it (InvoiceController::show's invoice). */
export interface InvoiceDetail extends InvoiceRow {
    bill_to_address: string | null;
    bill_to_email: string | null;
    notes: string | null;
    po_total_minor: number | null;
    document: { id: number; name: string } | null;
    void_reason: string | null;
    sent_at: string | null;
    sent_to: string | null;
    reminded_at: string | null;
    reminder_count: number;
    email_failed: { to: string | null; error: string | null; at: string; reminder: boolean } | null;
    /** The draft's lines as shown: every save and issue carries it, so nothing is saved over lines you didn't see. */
    lines_version: string | null;
    /** The last change shown: saving and issuing mark changes up to it as seen. */
    seen_up_to: number | null;
    lines: InvoiceLine[];
    end_client: string | null;
    closed_reason: string | null;
    closed_minor: number | null;
    replaced_by_id: number | null;
}

/** A change the job's sync made since you last looked, in words. */
export interface DraftChange {
    id: number;
    action: string;
    at: string | null;
    line: number | null;
    words: string;
}

/** What blocks issuing (blocks) and what's worth a look (warnings), as InvoiceService::checks says it. */
export interface DraftChecks {
    blocks: { code: 'line' | 'your_details' | 'locked' | 'unconfirmed' | string; message: string; line: number | null }[];
    warnings: { code: string; message: string }[];
}

/**
 * One line of a payment's decision (SourceState): kind, category id, job id, invoice id, amount in pence, business %,
 * who decided it, whether it's confirmed, and its own client id when it has one.
 */
export type SplitPart = [string, number | null, number | null, number | null, number, number | null, string, boolean, number?];

/** A payment that issuing confirms, with its whole split as you'll be shown it and the fingerprint it's tied to. */
export interface ToConfirm {
    /** "tx:381" or "exp:12": for the request only. */
    source: string;
    fp: string;
    state: { status: string; pair: number | null; lines: SplitPart[] } | null;
    locked: boolean;
    tax_year: string | null;
    lines: number[];
}

/** A conversation the invoice can go in (BookingThread::candidates), or "A new email". */
export interface MailThread {
    thread_id: string;
    how: 'po' | 'enquiry' | 'job' | 'new';
    subject: string | null;
    from: string | null;
    at: string | null;
}

/** A job's one stage and its chip (JobStage::of). */
export interface JobStageInfo {
    stage: string;
    chip: string;
    done_due: boolean;
    finish_at: string;
    invoice_id: number | null;
    draft_id: number | null;
    state: string | null;
    email: string | null;
    overdue_days: number | null;
}

/** The check screen's extras on a draft (InvoiceController::draftSheet). */
export interface DraftSheet {
    terms_days: number;
    /** The number it would be issued as now ("INV-0005"). */
    next_number?: string;
    changes: DraftChange[];
    changes_since: string | null;
    checks: DraftChecks;
    to_confirm: ToConfirm[];
    /** The job already has an issued invoice for its work: issuing this one needs you to say it's a second invoice. */
    second_invoice: { number: string } | null;
    /** Gmail is connected with permission to write drafts. */
    gmail: boolean;
    addresses: string[];
    threads: MailThread[];
    route: 'thread' | 'new' | 'none';
    client: {
        id: number;
        name: string;
        has_terms: boolean;
        /** With no usual arrangement: the one-tap answers to "How does Acme pay travel?", tied to it as it is now. */
        terms: { fp: string; presets: { key: string; label: string }[] } | null;
    } | null;
    stage: JobStageInfo | null;
}

/** A payment against an issued invoice: linked by you, or waiting in the Inbox. */
export interface InvoicePayment {
    source: 'bank' | 'manual';
    id: number;
    date: string | null;
    payer: string | null;
    amount_pence: number;
    linked: boolean;
    waiting: boolean;
}

/** Money in that may be this invoice's payment ("Link a payment"), the likeliest first. */
export interface PaymentToLink {
    source: 'bank';
    id: number;
    date: string;
    payer: string;
    amount_pence: number;
    /** Expend already suggests it for this invoice. */
    suggested: boolean;
}

/** An email of the invoice or its reminder (InvoiceController::emailRow). */
export interface EmailRow {
    id: number;
    kind: 'invoice' | 'reminder';
    stage: number | null;
    route: 'gmail' | 'mailer' | 'by_hand' | string;
    status: 'pending' | 'drafted' | 'sent' | 'failed' | 'deleted' | 'replaced' | string;
    to: string | null;
    subject: string | null;
    thread_id: string | null;
    error: string | null;
    edited: boolean;
    drafted_at: string | null;
    sent_at: string | null;
}

/** A to-do style button with the fingerprint of what it acts on now (posted to /todo/{code}/{id}). */
export interface BoundAction {
    code: string;
    id: string;
    label: string;
    fp: string | null;
}

/** A cost linked to the job after the invoice was sent: on the client's next invoice. */
export interface LateCost {
    id: string;
    what: string;
    amount_minor: number | null;
    currency: string;
    actions: BoundAction[];
}

/** An issued or void invoice's view (InvoiceController::issuedView). */
export interface IssuedView {
    state: InvoiceState;
    paid_minor: number;
    paid_linked_minor: number;
    outstanding_minor: number;
    over_minor: number;
    closed_minor: number;
    waiting_pence: number;
    refunded_minor: number;
    currency: string;
    state_words: string;
    payments: InvoicePayment[];
    /** Paid on the job without naming this invoice. */
    paid_by_job_minor: number;
    gmail: boolean;
    email: EmailRow | null;
    reminder: EmailRow | null;
    /** "Make a reminder" is offered: it's sent, overdue and something is still to come. */
    can_remind: boolean;
    overdue: { days: number; stage: number; from: string } | null;
    /** Expend can't see Gmail just now. */
    blind: { since: string | null; why: string | null } | null;
    close_reasons: CloseReason[];
    replace_with: { id: number; number: string | null; issue_date: string | null; total_minor: number; outstanding_minor: number }[];
    replaced_by: string | null;
    stale_date: string | null;
    to_link: PaymentToLink[];
    /** The first day "When did it go?" accepts: the day it was issued. */
    sent_from: string | null;
    late_costs: LateCost[];
}

export type CloseReason = 'short_paid' | 'charges' | 'wont_chase' | 'paid_other_currency' | 'other';

/** "Send it now": the Gmail draft as it is in Gmail now, and the version a send is tied to. */
export interface GmailPreview {
    email_id: number;
    version: string;
    edited: boolean;
    from: string | null;
    to: string | null;
    subject: string | null;
    files: string[];
    text: string;
}

/** A refused press because something changed since you looked (409): the draft as it is now, to show again. */
export interface ChangedSheet {
    lines_version: string;
    seen_up_to: number | null;
    lines: InvoiceLine[];
    changes: DraftChange[];
    checks: DraftChecks;
    to_confirm: ToConfirm[];
    total_minor: number;
}
