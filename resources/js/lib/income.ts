import { money } from '@/lib/format';
import { type ClientRef, type DecisionLine, type Lookups, type SharedData } from '@/types';

/** A category's id by its code (turnover, finance), or null when it isn't there. */
export function categoryId(lookups: Lookups, code: string): number | null {
    return lookups.categories.find((c) => c.code === code)?.id ?? null;
}

/** Turnover: where money in for your work goes unless you say otherwise. */
export function turnoverId(lookups: Lookups): number | null {
    return categoryId(lookups, 'turnover') ?? lookups.categories.find((c) => c.kind === 'income')?.id ?? null;
}

/** Where a fee or commission taken off a payment goes unless you say otherwise (the server's Lines::deduction). */
export function feeCategoryId(lookups: Lookups): number | null {
    return categoryId(lookups, 'finance') ?? lookups.categories.find((c) => c.kind === 'expense')?.id ?? null;
}

/** An amount the short way, as the server's IncomeText::money: "£600", "£1,350", "£0.50". */
export function shortMoney(minor: number, currency = 'GBP'): string {
    return money(Math.abs(minor), currency, { signed: false }).replace(/\.00$/, '');
}

/** Whether a tax year is locked (nothing in it can change until you unlock it with a reason), from the shared nav. */
export function yearLocked(nav: SharedData['nav'], label: string | null | undefined): boolean {
    return !!label && !!nav?.years.find((y) => y.label === label)?.locked;
}

/** What a page says about a payment in a locked year, before any button is pressed. */
export function lockedWords(label: string): string {
    return `${label} is locked, so this can’t change. Unlock the year first: it asks why, and keeps the reason.`;
}

/**
 * Why these lines can't be saved for a payment of this amount, as the writer would say it (AllocationWriter's sign and
 * sum rules), or null when they can: every line a non-zero amount, a category on business lines, money out all money
 * out, and on money in a negative line only as a fee taken off (a business expense) beside larger income; the lines add
 * up to the payment exactly.
 */
export function linesProblem(lines: DecisionLine[], amount: number): string | null {
    if (lines.length === 0) return 'Add at least one line.';
    let gross = 0;
    let taken = 0;
    for (const l of lines) {
        if (l.amount_pence > 0 && l.kind === 'business_income') gross += l.amount_pence;
        else if (l.amount_pence < 0) taken -= l.amount_pence;
    }
    const deductions = amount > 0 && gross > taken;
    for (const [i, l] of lines.entries()) {
        if (!Number.isInteger(l.amount_pence) || l.amount_pence === 0) return `Line ${i + 1} needs an amount.`;
        if ((l.kind === 'business_expense' || l.kind === 'business_income') && !l.category_id) return `Line ${i + 1} needs an HMRC category.`;
        if (l.amount_pence < 0 !== amount < 0 && !(deductions && l.kind === 'business_expense')) {
            return amount > 0
                ? `Line ${i + 1}: only a fee taken off can come off money in, and the income must be more than it.`
                : `Line ${i + 1} must be money out, like the payment.`;
        }
    }
    const sum = lines.reduce((s, l) => s + l.amount_pence, 0);
    if (sum !== amount) {
        // In the payment's own direction: money out's lines are negative too.
        const gap = (amount - sum) * (amount < 0 ? -1 : 1);
        return gap > 0 ? `${money(Math.abs(gap))} still to put on a line.` : `${money(Math.abs(gap))} too much: lower a line.`;
    }
    return null;
}

/** Words that don't tell two clients apart ("Acme Events Ltd" is "acme events"). */
const NOISE = /\b(ltd|limited|plc|llp|the|and|co)\b/g;

function words(text: string): string {
    return text
        .toLowerCase()
        .replace(/&/g, ' ')
        .replace(/[^a-z0-9 ]+/g, ' ')
        .replace(NOISE, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** The clients whose name has every word typed, best first (a name that starts with it, then the rest A to Z). */
export function matchClients(clients: ClientRef[], query: string): ClientRef[] {
    const q = words(query);
    if (q === '') return clients;
    const parts = q.split(' ');
    return clients
        .map((c) => ({ c, w: words(c.name) }))
        .filter(({ w }) => parts.every((p) => w.includes(p)))
        .sort((a, b) => Number(!a.w.startsWith(q)) - Number(!b.w.startsWith(q)) || a.c.name.localeCompare(b.c.name))
        .map(({ c }) => c);
}
