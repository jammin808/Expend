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

export interface SharedData {
    name: string;
    auth: Auth;
    flash: {
        success?: string | null;
        error?: string | null;
        rule_offer?: { merchant_key: string; text: string } | null;
        rule_undo?: { id: number; applied: number } | null;
    };
    nav: { inbox: number; todo: number; years: { label: string; status: string }[] } | null;
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
}

export interface Decision {
    kind: Kind;
    category_id?: number | null;
    work_job_id?: number | null;
    business_pct?: number;
    note?: string | null;
    make_rule?: boolean;
}
