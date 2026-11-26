/**
 * The client and job pages' props, as ClientController and JobController send them, and the JSON their sheets read.
 * Owner-facing words come from the server where it has them (a stage's chip, a cost's reason, a merge's what_follows);
 * refs, ids and fingerprints here are for the requests only, never shown.
 */
import { type InvoiceRow } from '@/types/invoices';

/** Where a job is, from booked to paid (JobStage::of): one stage and the chip that says it. */
export type StageKey =
    | 'upcoming'
    | 'under_way'
    | 'done_due'
    | 'not_done'
    | 'done_no_draft'
    | 'draft'
    | 'waiting_payment'
    | 'not_sent'
    | 'sent'
    | 'overdue'
    | 'part_paid'
    | 'paid'
    | 'closed'
    | 'cancelled_to_invoice'
    | 'cancelled';

export interface JobStageInfo {
    stage: StageKey;
    /** "Done?", "Draft · 2 changes", "Overdue 3 days", "Part paid £450 of £900"… */
    chip: string;
    /** Its last day has finished and it's still booked: ask "All done?". */
    done_due: boolean;
    finish_at: string;
    invoice_id: number | null;
    draft_id: number | null;
    state: string | null;
    email: string | null;
    overdue_days: number | null;
}

/** An amount in a currency, in its minor units (pence for pounds). */
export interface MinorAmount {
    currency: string;
    minor: number;
}

/** Amounts by currency (an empty list when there are none: PHP sends [] for an empty map). */
export type ByCurrency = Record<string, number> | [];

/* ---------------------------------------------------------------- the client list */

export interface ClientRow {
    id: number;
    name: string;
    invoice_name: string | null;
    billing_email: string | null;
    /** Clients merged into this one: the list's search finds them. */
    merged_names: string[];
    owed: MinorAmount[];
    /** "Owes £2,836.40 · 1 overdue". */
    owed_words: string | null;
    overdue: number;
    /** Finished jobs with nothing billed. */
    to_invoice: number;
    last_job_on: string | null;
    jobs_this_year: number;
    end_client_ids: number[];
    /** "Last job 12 Mar · 4 jobs this year". */
    recent_words: string | null;
    /** An agency's: "for Big Prod Co, Venue X". */
    for: string | null;
}

/** Two clients that may be the same: the same name (Ltd, capitals and "The" aside) or the same billing email. */
export interface DuplicatePair {
    a: { id: number; name: string };
    b: { id: number; name: string };
    why: 'name' | 'email' | string;
    text: string;
}

export interface ClientsIndexProps {
    clients: ClientRow[];
    counts: { owe: number; to_invoice: number; all: number };
    filters: { q: string | null; show: 'owe' | 'to_invoice' | 'all'; year: string };
    figures: { client_id: number; made_pence: number; profit_pence: number }[];
    duplicates: DuplicatePair[];
}

/** POST /clients: the client added, or the one you have with that name already. */
export interface ClientAdded {
    id: number;
    name: string;
    existing: boolean;
    message?: string;
}

/* ---------------------------------------------------------------- comparing and merging */

export type InvoiceRoute = 'thread' | 'new' | 'none';

export interface ClientDetails {
    id: number;
    name: string;
    invoice_name: string | null;
    billing_email: string | null;
    billing_address: string | null;
    payment_terms_days: number | null;
    invoice_route: InvoiceRoute;
    notes: string | null;
    merged_into_id: number | null;
}

/** GET clients/{from}/compare/{into}: both side by side, what merging does, and why it can't. */
export interface CompareSheet {
    from: ClientDetails & { jobs: number; invoices: number; payments: number };
    into: ClientDetails & { jobs: number; invoices: number; payments: number };
    /** For the request only: the merge is refused if either changed since. */
    fp: string;
    can_merge: boolean;
    why_not: string | null;
    locked_years: string[];
    what_follows: string;
}

/* ---------------------------------------------------------------- one client's page */

export interface ClientJobRow {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    status: string;
    client: string | null;
    end_client: string | null;
    stage: JobStageInfo | null;
}

export interface StillToCharge {
    pence: number;
    other: ByCurrency;
    count: number;
}

export interface EndClientFigures {
    key: string;
    end_client_id: number | null;
    name: string;
    made_pence: number;
    spent_pence: number;
    fees_taken_pence: number;
    disallowable_pence: number;
    mileage_pence: number;
    profit_pence: number;
    jobs: number;
    /** "Includes £41.60 recharged on another job's invoice". */
    note: string | null;
}

/** ClientFigures::forClient: one tax year, confirmed records only, counted when paid. */
export interface ClientFiguresYear {
    client_id: number | null;
    tax_year: string;
    made_pence: number;
    spent_pence: number;
    fees_taken_pence: number;
    disallowable_pence: number;
    mileage_pence: number;
    profit_pence: number;
    /** Money in waiting in the Inbox (not confirmed yet). */
    waiting_pence: number;
    charged_back: ByCurrency;
    invoiced: ByCurrency;
    outstanding: ByCurrency;
    closed: ByCurrency;
    cash_basis_note: string | null;
    locked: boolean;
    footnote: string;
    still_to_charge: StillToCharge;
    by_end_client: EndClientFigures[];
}

/** What a cost's sheet offers (ChargeStatus::actions). */
export type CostAction = 'recharge' | 'no_charge' | 'elsewhere' | 'claimed' | 'clear';

/** A chip a cost is listed under (ChargeStatus::CHIPS). */
export type CostChipKey = 'to_charge' | 'on_draft' | 'charged' | 'to_claim' | 'not_charged' | 'elsewhere';

/** One cost and where it stands. ref and id are for the requests only. */
export interface CostRow {
    ref: string;
    id: string;
    subject: string;
    kind: 'cost' | 'credit' | 'trip' | 'booking' | 'work' | string;
    class: string | null;
    description: string;
    date: string | null;
    job_id?: number | null;
    currency: string;
    amount_minor: number | null;
    quantity?: string | null;
    status: string;
    /** Why, as a code (for requests and tests only: never shown). */
    reason: string | null;
    /** Where it stands, in words ("On INV-0031", "Charged another way", "Not charged by the arrangement"). */
    note?: string | null;
    chip: CostChipKey;
    actions: CostAction[];
    remaining?: number | string | null;
    tick?: boolean;
    decision?: { charge: string | null; note: string | null } | null;
}

export interface CostChip {
    key: CostChipKey;
    label: string;
    count: number;
}

/* ---------------------------------------------------------------- travel and expenses (the terms editor) */

/** A kind of cost's own row, as stored. */
export interface OwnTerm {
    charge: string;
    rate_minor: number | null;
    currency: string;
}

/** What applies to a kind of cost: its charge (null with none), and whether it's the job's own, the client's or none. */
export interface AppliedTerm {
    charge: string | null;
    rate_minor: number | null;
    currency: string;
    from: 'job' | 'client' | 'none';
}

/** The arrangement editor's props (ClientController::termsSheet), for a client or a job. */
export interface TermsSheet {
    own: Record<string, OwnTerm> | [];
    applies: Record<string, AppliedTerm> | [];
    /** For the save only: a save on rows that changed since is refused. */
    fp: string;
    presets: { key: 'at_cost' | 'mileage' | 'in_rate' | 'travel_fee'; label: string }[];
    classes: { class: string; label: string; charges: string[] }[];
    per_mile_note: string;
    /** HMRC's car rate this tax year, in pence a mile. */
    mileage_rate: number;
    help: string;
}

export interface ClientShowProps {
    client: ClientDetails & {
        merged: { id: number; name: string }[];
        payers: { key: string; payments: number; text: string }[];
        work_for: string | null;
        booked_through: string | null;
        locked_years: string[];
    };
    owed: {
        invoices: InvoiceRow[];
        jobs: ClientJobRow[];
        drafts: { id: number; work_job_id: number | null; total_minor: number; currency: string }[];
        costs_to_charge: StillToCharge;
        to_claim: { count: number; pence: number };
        last_paid: { number: string | null; issue_date: string | null } | null;
    };
    jobs: { paying: ClientJobRow[]; paying_count: number; end_client: ClientJobRow[]; end_client_count: number };
    figures: ClientFiguresYear;
    years: string[];
    costs: CostRow[];
    chips: CostChip[];
    terms: TermsSheet;
    invoice_routes: InvoiceRoute[];
}
