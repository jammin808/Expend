/**
 * The intelligence cycle's shared shapes on the client (docs/intelligence-cycle.md): what GET /why returns for a
 * why-panel. Refs are the cycle's evidence references ("tx:381", "cal:<key>", "job:14").
 */

/** A ref, resolved: what it points at in a few words, where to open it, whether it's still there, and whether a
 * pinned source has changed since it was relied on. */
export interface ResolvedRef {
    ref: string;
    label: string;
    href: string | null;
    exists: boolean;
    changed: boolean;
}

export interface WhyMethod {
    /** Registered name, e.g. "transfer.pair" */
    name: string;
    /** How it works, in plain words */
    label: string;
    basis: string;
    basis_label: string;
    version: number;
    /** False when worked out from who made the line and its wording (written before methods were stored) */
    recorded: boolean;
    note: string | null;
}

export interface WhyScore {
    value: number;
    /** What the score means for this method, or null */
    means: string | null;
    /** For learned categories: all your decisions looked at ("3 of 4") */
    of: number | null;
    /** How far ahead of the runner-up */
    margin: number | null;
}

export interface WhyGrade {
    grade: 'certain' | 'strong' | 'likely' | 'possible' | 'weak' | 'unmeasured';
    basis: string;
}

export interface WhyEvent {
    id: number;
    at: string;
    actor: string;
    actor_label: string;
    action: string;
    label: string;
    reason: string | null;
    channel: string | null;
    channel_label: string | null;
    correlation: string | null;
}

export interface WhyHistoryEvent extends WhyEvent {
    /** What the same action changed elsewhere (events sharing its correlation) */
    knock_on: (WhyEvent & { subject: string })[];
}

export interface WhyItem {
    /** decision | calendar_job | document_job | travel_job | travel_payment | invoice_job */
    about: string;
    title: string;
    /** none | suggested | confirmed | decided | linked | rejected | taken_back */
    state: string;
    actor: string | null;
    actor_label: string | null;
    confirmed_by?: string | null;
    headline: string;
    reason: string | null;
    method: WhyMethod | null;
    score: WhyScore | null;
    grade: WhyGrade | null;
    evidence: ResolvedRef[];
    alternatives: (ResolvedRef & { score: number | null })[];
    /** Other facts worked out now, in plain words */
    facts?: string[];
    history: WhyHistoryEvent[];
}

/** Something you said no to about it ("Not this one": a veto), which nothing automatic proposes again until lifted. */
export interface WhySaidNo {
    id: number;
    relation: string;
    /** What kind of link, in plain words */
    about: string;
    /** What was said no to, in plain words */
    what: string;
    method: string | null;
    /** The method it holds back, in plain words; null: every automatic method */
    method_label: string | null;
    effect: 'block' | 'ask';
    reason: string | null;
    at: string | null;
    /** The fingerprint lifting it is bound to */
    fp: string | null;
}

export interface Why {
    ref: string;
    items: WhyItem[];
    said_no?: WhySaidNo[];
}
