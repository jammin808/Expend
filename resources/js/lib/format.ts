const symbols: Record<string, string> = { GBP: '£', USD: '$', EUR: '€' };

/**
 * Currencies without two decimal places (ISO 4217, the same list the server uses: app/Support/Money::digits, and a
 * test checks they agree). Every other currency has two.
 */
// prettier-ignore
const DIGITS: Record<string, number> = {
    ADP: 0, BEF: 0, BHD: 3, BIF: 0, BYR: 0, CLF: 4, CLP: 0, DJF: 0, ESP: 0, GNF: 0, GRD: 0, IQD: 3, ISK: 0, ITL: 0, JOD: 3,
    JPY: 0, KMF: 0, KRW: 0, KWD: 3, LUF: 0, LYD: 3, MGF: 0, OMR: 3, PTE: 0, PYG: 0, RWF: 0, TND: 3, TPE: 0, TRL: 0, UGX: 0,
    UYI: 0, UYW: 4, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0,
};

/** How many decimal places a currency has: GBP 2, JPY 0, KWD 3. */
export function currencyDigits(currency: string | null | undefined): number {
    return DIGITS[(currency ?? 'GBP').toUpperCase()] ?? 2;
}

/**
 * Format an amount in hundredths of the currency's main unit, whatever the currency: pence, cents, and for an
 * invoice, PO or booking in yen, hundredths of a yen (what toPence and the server's Money::parse give). Always two
 * decimal places, as invoices and POs are written. Negative amounts use a real minus sign.
 *
 * A line's original amount and a currency pocket's balances are in the currency's own minor units: moneyMinor().
 */
export function money(minor: number | null | undefined, currency = 'GBP', opts: { signed?: boolean; plus?: boolean } = {}): string {
    if (minor === null || minor === undefined) return '';
    const signed = opts.signed ?? true;
    const abs = Math.abs(minor);
    const text = (symbols[currency] ?? currency + ' ') + (abs / 100).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (minor < 0 && signed) return '−' + text;
    if (minor > 0 && opts.plus) return '+' + text;
    return text;
}

/**
 * Format an amount in the currency's own minor units (ISO 4217: pence, cents, whole yen, fils), the way a line's
 * original amount (original_amount_minor) and a currency pocket's balances are stored: moneyMinor(1500, 'JPY') is
 * "JPY 1,500", moneyMinor(12345, 'KWD') is "KWD 12.345". The same as money() for a two-decimal currency.
 */
export function moneyMinor(
    minor: number | null | undefined,
    currency: string | null | undefined = 'GBP',
    opts: { signed?: boolean; plus?: boolean } = {},
): string {
    if (minor === null || minor === undefined) return '';
    const code = (currency || 'GBP').toUpperCase();
    const signed = opts.signed ?? true;
    const digits = currencyDigits(code);
    const abs = Math.abs(minor);
    const text =
        (symbols[code] ?? code + ' ') +
        (abs / 10 ** digits).toLocaleString('en-GB', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    if (minor < 0 && signed) return '−' + text;
    if (minor > 0 && opts.plus) return '+' + text;
    return text;
}

const rateSources: Record<string, string> = {
    hmrc_monthly: 'HMRC monthly rate',
    bank: 'the bank’s rate',
    exchange: 'the exchange it came from',
    manual: 'the rate you set',
    document: 'the document’s sterling figure',
};

/**
 * The rate a line in another currency was converted at, in words: "1.15 EUR per £1 (HMRC monthly rate)". A line
 * converted at the exchange it came from has no rate of its own, so only where its sterling came from is said.
 */
export function rateText(
    fx: { units_per_gbp?: string | null; source?: string | null } | null | undefined,
    currency: string | null | undefined,
): string | undefined {
    if (!fx) return undefined;
    const source = rateSources[fx.source ?? ''] ?? fx.source ?? '';
    if (fx.units_per_gbp) return `${fx.units_per_gbp} ${currency ?? ''} per £1${source ? ` (${source})` : ''}`;
    return source ? `Rate: ${source}` : undefined;
}

/** Today's date in the UK (YYYY-MM-DD), worked out now: not the UTC date, which is yesterday until 1am in summer. */
export function londonToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
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

/**
 * What a line was paid in, when it isn't pounds, in each currency's own units: "€42.00", "JPY 1,500", or, paid from
 * an account kept in another currency, the shop's own currency first: "$90.00 (€76.64 from Revolut EUR)". Null for
 * a line in pounds.
 */
export function paidIn(t: {
    original_amount_minor?: number | null;
    original_currency?: string | null;
    merchant_amount_minor?: number | null;
    merchant_currency?: string | null;
    account?: { name: string } | null;
}): string | null {
    const own =
        t.original_currency && t.original_currency !== 'GBP' && t.original_amount_minor !== null && t.original_amount_minor !== undefined
            ? moneyMinor(t.original_amount_minor, t.original_currency, { signed: false })
            : null;
    if (
        t.merchant_currency &&
        t.merchant_currency !== 'GBP' &&
        t.merchant_currency !== t.original_currency &&
        t.merchant_amount_minor !== null &&
        t.merchant_amount_minor !== undefined
    ) {
        const shop = moneyMinor(t.merchant_amount_minor, t.merchant_currency, { signed: false });
        return own ? `${shop} (${own}${t.account?.name ? ` from ${t.account.name}` : ''})` : shop;
    }
    return own;
}

/** The account and card a payment was made with, in a few words: "Amex · card •••• 1004 · Card payment". */
export function paidWith(t: { account?: { name: string } | null; card_last4?: string | null; payment_type?: string | null }): string {
    return [t.account?.name, t.card_last4 ? `card •••• ${t.card_last4}` : null, t.payment_type].filter(Boolean).join(' · ');
}

/**
 * A balance in words, with its sign spelled out so an overdraft never reads as money in the bank. A card's balance is
 * what you owe: as you type it in from a statement, owing is above zero ('owed'); in a card's own export (a running
 * balance, 'bank'), owing is below zero. Either way a card in credit after a refund says so. The amount is in the
 * account's own minor units (pence; whole yen for a yen pocket), as balances are kept.
 */
export function balanceText(kind: 'bank' | 'credit_card', amount: number, currency: string, sign: 'owed' | 'bank' = 'owed'): string {
    if (kind === 'credit_card') {
        const owed = sign === 'bank' ? -amount : amount;
        if (owed < 0) return `In credit ${moneyMinor(-owed, currency)}`;
        return owed === 0 ? 'Nothing owed' : `Owed ${moneyMinor(owed, currency)}`;
    }
    return amount < 0 ? `Overdrawn ${moneyMinor(-amount, currency)}` : `Balance ${moneyMinor(amount, currency)}`;
}

/**
 * Money moved between your own accounts, both ways: a card account or a savings pot mostly receives it, so the money
 * out alone can be nothing. When both sides match (every transfer paired, all accounts), it's shown once.
 */
export function movedBetweenAccounts(outPence: number, inPence: number): string {
    const out = Math.abs(outPence);
    if (out === inPence) return money(out);
    return [out > 0 ? `${money(out)} out` : null, inPence > 0 ? `${money(inPence)} in` : null].filter(Boolean).join(', ') || money(0);
}

/** Every date from the first to the last, YYYY-MM-DD (at most 31, as a job's days are added). */
export function datesBetween(first: string, last: string): string[] {
    const out: string[] = [];
    for (let d = new Date(first + 'T12:00:00Z'); out.length < 31; d.setUTCDate(d.getUTCDate() + 1)) {
        const iso = d.toISOString().slice(0, 10);
        if (iso > last) break;
        out.push(iso);
    }
    return out;
}
