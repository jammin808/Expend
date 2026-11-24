const symbols: Record<string, string> = { GBP: '£', USD: '$', EUR: '€' };

/** Format integer minor units. Negative amounts use a real minus sign. */
export function money(minor: number | null | undefined, currency = 'GBP', opts: { signed?: boolean; plus?: boolean } = {}): string {
    if (minor === null || minor === undefined) return '';
    const signed = opts.signed ?? true;
    const abs = Math.abs(minor);
    const text = (symbols[currency] ?? currency + ' ') + (abs / 100).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (minor < 0 && signed) return '−' + text;
    if (minor > 0 && opts.plus) return '+' + text;
    return text;
}

/** Parse "12.30" / "£1,234.56" into pence; null when empty or unreadable. */
export function toPence(value: string): number | null {
    const clean = value.replace(/[£$€,\s]/g, '');
    if (clean === '' || !/^-?\d+(\.\d{1,2})?$/.test(clean)) return null;
    const negative = clean.startsWith('-');
    const [whole, frac = ''] = clean.replace('-', '').split('.');
    const pence = parseInt(whole, 10) * 100 + parseInt((frac + '00').slice(0, 2), 10);
    return negative ? -pence : pence;
}

export function day(iso: string | null | undefined, withYear = true): string {
    if (!iso) return '';
    const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''));
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });
}

export const kindLabels: Record<string, string> = {
    business_expense: 'Business expense',
    business_income: 'Business income',
    personal: 'Personal',
    transfer: 'Transfer',
    tax_payment: 'Tax payment',
    drawings: 'Drawings',
    capital_introduced: 'Money in from you',
    loan: 'Loan',
};

export const decidedByLabels: Record<string, string> = { you: 'You', rule: 'Your rule', matcher: 'Matching', ai: 'AI' };

export function daysUntil(iso: string): number {
    const target = new Date(iso + 'T23:59:59');
    return Math.ceil((target.getTime() - Date.now()) / 86_400_000);
}

/** The account and card a payment was made with, in a few words: "Amex · card •••• 1004 · Card payment". */
export function paidWith(t: { account?: { name: string } | null; card_last4?: string | null; payment_type?: string | null }): string {
    return [t.account?.name, t.card_last4 ? `card •••• ${t.card_last4}` : null, t.payment_type].filter(Boolean).join(' · ');
}
