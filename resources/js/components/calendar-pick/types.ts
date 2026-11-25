/**
 * What the server sends for picking a job's days (CalendarPickController and JobCalendarController::index), as the
 * pages read it. Fields an older server doesn't send are optional, so the page still works without them.
 */

/** An entry on the calendar month, as index and GET /calendar/month send it. */
export interface MonthEntry {
    key: string;
    date: string;
    last_day: string;
    summary: string;
    location: string | null;
    all_day: boolean;
    start_time: string | null;
    end_time: string | null;
    job: { id: number; title: string } | null;
    by: string | null;
    not_job: boolean;
    looks_like_work: boolean;
    postcode: string | null;
    client_id: number | null;
    state?: 'free' | 'job' | 'not_job';
    recurring?: boolean;
    base?: string;
    /** The search's work score (2 or more looks like work). */
    score?: number;
    default_include?: boolean;
}

/** A job that has a date as one of its days. */
export interface JobDayMark {
    job_id: number;
    title: string;
    cancelled: boolean;
}

export interface LockedYear {
    label: string;
    starts_on: string;
    ends_on: string;
}

/** One month of the calendar: GET /calendar/month?m=YYYY-MM. */
export interface MonthData {
    month: string;
    prev?: string;
    next?: string;
    /** Today in the UK, as the server sees it. */
    today?: string;
    events: MonthEntry[];
    job_days: Record<string, JobDayMark[]>;
    locked_years: LockedYear[];
}

export interface JobOption {
    id: number;
    title: string;
    client: string | null;
    starts_on: string;
    ends_on: string;
    days?: string[] | number;
}

export type PreviewState = 'free' | 'this_job' | 'other_job' | 'not_job' | 'not_this_job';

/** An entry as the review shows it: POST /calendar/pick/preview. */
export interface PreviewEntry {
    key: string;
    date: string;
    last_day: string;
    summary: string;
    location: string | null;
    all_day: boolean;
    start_time: string | null;
    end_time: string | null;
    recurring: boolean;
    base: string;
    /** Of an entry longer than one day: how many of its days are picked. */
    partial: { picked: number; total: number } | null;
    /** The picked evening a small-hours entry belongs to. */
    fold_onto: string | null;
    state: PreviewState;
    /** Who linked it (this job or another). */
    by: string | null;
    /** The other job it's on, what moving it costs that job, and (when it brought every day that job has) the day it keeps. */
    job: { id: number; title: string; by: string | null; cancelled: boolean; loses: string[]; only_day: boolean; keeps?: string | null } | null;
    default_include: boolean;
    /** Expend's tick with the overrides sent applied (the page works its own ticks out the same way). */
    included?: boolean;
    move_default: boolean;
    score?: number;
}

export interface PreviewDay {
    date: string;
    tax_year: string;
    locked: boolean;
    other_jobs: { id: number; title: string; cancelled: boolean }[];
    /** Adding to a job: the day as the job has it already. */
    on_job: { kind: string; start_time: string | null; end_time: string | null; note: string | null } | null;
    suggested: { kind: string; start_time: string | null; end_time: string | null };
    /** Adding to a job: more than four months from it, so the save would refuse it. */
    too_far?: boolean;
    /** Adding to a job: it would make the job longer than 400 days, so the save would refuse it. */
    too_long?: boolean;
    entries: string[];
    folds: string[];
}

export interface PreviewCandidate {
    id: number;
    title: string;
    /** Its calendar entries: it's closed only when every one is in the job. */
    calendar_keys?: string[];
    /** What else it was found from; a document you said isn't part of the job (adding to one) is rejected. */
    others: { type: string; id: number | string; label: string; rejected?: boolean }[];
    edited?: boolean;
    /** Every one of its calendar entries is included as the preview was asked (the page checks again with its own ticks). */
    covered?: boolean;
    client_name?: string | null;
    location?: string | null;
    postcode?: string | null;
    po_number?: string | null;
}

export interface Suggest {
    title: string;
    client_id: number | null;
    new_client: string | null;
    location: string | null;
    postcode: string | null;
    po_number: string | null;
    status: 'booked' | 'done';
    from_candidate: number | null;
}

export interface Preview {
    pick: string;
    done?: { job_id: number; title: string; mode: string } | null;
    days: PreviewDay[];
    entries: PreviewEntry[];
    related: { key: string; date: string; last_day: string; summary: string; start_time: string | null; all_day: boolean }[];
    candidates: PreviewCandidate[];
    partly_covered?: number;
    job_options?: JobOption[];
    suggest?: Suggest;
    /** Adding to a job: the job, with the titles (lower case, without day words) of every entry on it. */
    job?: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
        cancelled: boolean;
        days?: string[];
        has_mileage?: boolean;
        bases?: string[];
    } | null;
}

/** A job's kinds of day, in the order the review offers them (JobDay::KINDS). */
export const DAY_KINDS: Record<string, string> = {
    rig: 'Rig / load-in',
    show: 'Show',
    derig: 'De-rig / load-out',
    travel: 'Travel',
    work: 'Work',
};
