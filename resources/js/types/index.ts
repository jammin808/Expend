import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
}

/**
 * A to-do button (TodoList::action, with Fingerprints::attach's fp), posted to /todo/{code}/{id} with its fp. Code
 * 'link' only opens href: it has no fp and decides nothing.
 */
export interface TodoAction {
    code: string;
    id: string;
    label: string;
    style: 'default' | 'ghost';
    /** A fingerprint of what it acts on, as shown: a press on something changed since is refused in words. */
    fp?: string | null;
    /** A link (code 'link'): it opens a page and decides nothing. */
    href?: string;
}

/** A button offered after a to-do press (TodoController::followUp): Undo, or a choice such as Cancelled's "No". */
export interface FollowUpButton {
    code: string;
    id: string;
    label: string;
    /** What it acts on as it was left by the press: a press after something changed is refused in words. */
    fp: string | null;
}

/** A link offered after a to-do press ("Check the invoice", "Add days"): opens a page, decides nothing. */
export interface FollowUpLink {
    label: string;
    href: string;
}

export type FollowUp = FollowUpButton | FollowUpLink;

/** What the server said with a page (HandleInertiaRequests::share): once, then taken off the page (the toaster). */
export interface Flash {
    success?: string | null;
    error?: string | null;
    rule_offer?: { merchant_key: string; text: string } | null;
    rule_undo?: { id: number; applied: number } | null;
    /** A to-do press's Undo, posted to /todo/{code}/{id} with its fp (TodoController::act). */
    undo?: FollowUpButton | null;
    /** Everything to offer after a to-do press: links, Undo and any choices it asks for (Cancelled: No, Just my costs). */
    follow_up?: FollowUp[] | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: Flash;
    /** years: each tax year's status, and whether it's locked (nothing in it can change until it's unlocked with a reason). */
    nav: { inbox: number; todo: number; years: { label: string; status: string; locked?: boolean }[] } | null;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    two_factor?: boolean;
    [key: string]: unknown;
}

export type Kind = 'business_expense' | 'business_income' | 'personal' | 'transfer' | 'tax_payment' | 'drawings' | 'capital_introduced' | 'loan';

export interface Allocation {
    id: number;
    kind: Kind;
    category_id: number | null;
    category: string | null;
    work_job_id: number | null;
    job: string | null;
    amount_pence: number;
    business_use_pct: number | null;
    decided_by: 'you' | 'rule' | 'matcher' | 'ai';
    is_manual: boolean;
    confirmed: boolean;
    reason: string | null;
    note: string | null;
    /**
     * Income design v3 (Present::allocation): the invoice a line pays and its number, the client stored on the line
     * itself (only where its invoice and job name none), and the client it counts for, worked out (its invoice's, else
     * its job's, else its own). A form sends back what it loaded, so editing a split never drops a link.
     */
    invoice_id?: number | null;
    invoice_number?: string | null;
    client_id?: number | null;
    client?: string | null;
}

/** A ledger line as the decide endpoint takes it, with explicit links (null takes a link off). */
export interface DecisionLine {
    kind: Kind;
    category_id: number | null;
    work_job_id: number | null;
    invoice_id?: number | null;
    client_id?: number | null;
    amount_pence: number;
    business_use_pct?: number | null;
    note?: string | null;
}

/**
 * What a payment could be for, as one tap (IncomeTargets): an open invoice, a set of them or one net of a fee, a
 * finished job with nothing billed, the payer's client with no job, or for money out a refund. Tapping one fills the
 * decision with its lines; nothing is saved until you press Save.
 */
export interface IncomeTarget {
    kind: 'invoice' | 'set' | 'net' | 'job' | 'client' | 'reversal' | 'overpaid' | 'refund';
    label: string;
    /** The Save button's words once it's picked: "Save INV-042". */
    save: string;
    lines: DecisionLine[];
    invoice_ids: number[];
    job_id: number | null;
    client_id: number | null;
}

/** Who a payer usually pays for, and where the drawer starts (TransactionController::income). */
export interface IncomeInfo {
    /** "ACME PAYROLL · usually Acme Events (3 payments)", "… · pays for several clients", or null when it's new. */
    payer: string | null;
    client_id: number | null;
    client: string | null;
    shared: boolean;
    /** The payer's words look like a payslip or tax taken off: no "fee was taken off" shortcut. */
    payslip_like: boolean;
    deduction_note: string;
}

export interface ClientRef {
    id: number;
    name: string;
}

/** A job as the job search gives it (GET /lookups/jobs). */
export interface JobHit {
    id: number;
    title: string;
    client: string | null;
    client_id: number | null;
    end_client: string | null;
    location: string | null;
    po_number: string | null;
    starts_on: string;
    ends_on: string;
    status: string;
    cancelled: boolean;
    /** A finished job of the client with nothing billed ("Still owed by …"). */
    owed?: boolean;
}

/** An issued invoice with something still to come (LookupController::open). Amounts in the invoice's currency, in hundredths. */
export interface OpenInvoice {
    id: number;
    number: string | null;
    job: string | null;
    work_job_id: number | null;
    client_id: number | null;
    client: string | null;
    bill_to_name: string | null;
    currency: string;
    total_minor: number;
    outstanding_minor: number;
    state: string;
    issue_date: string | null;
    due_date: string | null;
}

/** "Still owed by Acme Events" (GET /lookups/owed). */
export interface Owed {
    client: ClientRef;
    invoices: OpenInvoice[];
    jobs: { id: number; title: string; starts_on: string; ends_on: string; fee_pence: number | null; label: string }[];
    label: string;
}

export interface Item {
    id: number;
    source: 'bank' | 'manual';
    date: string;
    tax_year: string;
    account: { id: number; name: string; kind: string; last4?: string | null } | null;
    time?: string | null;
    place?: string | null;
    card_last4?: string | null;
    payment_type?: string | null;
    description: string;
    counterparty?: string | null;
    merchant_key: string;
    amount_pence: number;
    original_amount_minor: number | null;
    original_currency: string | null;
    /** The currency the shop charged, when it isn't the account's own ($90.00 from a euro account), in its own units. */
    merchant_amount_minor?: number | null;
    merchant_currency?: string | null;
    bank_category?: string | null;
    review_status: 'unreviewed' | 'suggested' | 'confirmed';
    transfer_pair_id?: number | null;
    allocations: Allocation[];
    check?: import('@/components/check-badge').Check | null;
    merchant_count?: number;
    events?: CalendarEntry[];
    jobs_near?: JobRef[];
    fx?: { units_per_gbp: string | null; rate_date: string | null; source: string | null } | null;
    /** Money in on the Inbox: what it could be for, one tap each (IncomeTargets), and who the payer usually pays for. */
    targets?: IncomeTarget[];
    payer?: string | null;
    /** An open suggestion in words (IncomeText): "INV-041 £600 + INV-043 £750 · Acme Events". */
    suggestion_text?: string | null;
    /** Your receipt photo from the phone on it (Present::receipt), where the page loads it: the Inbox does. */
    receipt?: { quick_id: number; document_id: number; href: string; file: string; what: string | null } | null;
}

export interface CalendarEntry {
    summary: string | null;
    location: string | null;
    time: string | null;
}

export interface JobRef {
    id: number;
    title: string;
    client?: string | null;
    starts_on?: string;
    ends_on?: string;
}

export interface Category {
    id: number;
    code: string;
    name: string;
    kind: 'expense' | 'income';
    box: string | null;
    group: string;
    is_top: boolean;
    allowable: boolean;
    capital: boolean;
}

export interface Lookups {
    kinds: { value: Kind; label: string }[];
    categories: Category[];
    jobs: JobRef[];
    accounts: { id: number; name: string; kind: string; provider: string | null }[];
    /** For the client picker (merged duplicates left out). */
    clients: ClientRef[];
}

export interface Decision {
    kind: Kind;
    category_id?: number | null;
    work_job_id?: number | null;
    business_pct?: number;
    note?: string | null;
    make_rule?: boolean;
    /** Income v3: the invoice and client the decision names (null takes the link off; no key says nothing about them). */
    invoice_id?: number | null;
    client_id?: number | null;
}
