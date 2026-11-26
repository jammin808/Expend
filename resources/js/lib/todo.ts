import { type FollowUp, type FollowUpButton, type FollowUpLink } from '@/types';
import { router } from '@inertiajs/react';

/**
 * To-do buttons on the web: where they post, what the server's words look like here, and a post that always ends in
 * words (the server's when it answered; otherwise why it didn't), never a blank screen or a silent nothing.
 */

/** A follow-up that only opens a page ("Check the invoice", "Add days"). */
export function isLink(f: FollowUp): f is FollowUpLink {
    return 'href' in f && typeof f.href === 'string';
}

/** A follow-up that does something when pressed (Undo, or a choice such as Cancelled's "No"). */
export function isButton(f: FollowUp): f is FollowUpButton {
    return 'code' in f && typeof f.code === 'string';
}

/** Where a to-do button posts (its id may hold colons: CostRef's "tx:381:j45"). */
export function todoUrl(b: { code: string; id: string }): string {
    return `/todo/${b.code}/${encodeURIComponent(b.id)}`;
}

/** An address the server wrote in full (https://…/invoices/9#sent) as a path in this app, or null for another site. */
export function localPath(href: string): string | null {
    try {
        const u = new URL(href, window.location.origin);
        return u.origin === window.location.origin ? u.pathname + u.search + u.hash : null;
    } catch {
        return null;
    }
}

/** Whether a link only leads back to this page, as it is (a link with #part still does something: opens a sheet). */
export function isHere(href: string): boolean {
    const path = localPath(href);
    return path !== null && !path.includes('#') && path === window.location.pathname + window.location.search;
}

/** Open a link: a page of this app as an Inertia visit, anything else as a plain one. */
export function openLink(href: string) {
    const path = localPath(href);
    if (path !== null) router.visit(path);
    else window.location.assign(href);
}

/**
 * A message as the web shows it. Telegram needs its addresses written out ("…give it on the job’s page:
 * https://…/jobs/5#cancel"); here each becomes a button, so the words come back without them, and the addresses in
 * the order they came.
 */
export function withoutLinks(text: string): { text: string; hrefs: string[] } {
    const hrefs: string[] = [];
    const words = text
        .replace(/(?::\s*|\s+)?(https?:\/\/\S+?)([.,;)]?)(?=\s|$)/g, (_all, href: string, tail: string) => {
            hrefs.push(href);
            return tail;
        })
        .replace(/\s{2,}/g, ' ')
        .trim();
    return { text: words === '' || /[.!?…)]$/.test(words) ? words : `${words}.`, hrefs };
}

const OPEN_WORDS: [RegExp, string][] = [
    [/^\/invoices\/\d+#payments/, 'Link a payment'],
    [/^\/invoices\/\d+/, 'Open the invoice'],
    [/^\/jobs\/finder/, 'Open the job finder'],
    [/^\/jobs\/\d+#cancel/, 'Give the fee'],
    [/^\/jobs\/\d+/, 'Open the job'],
    [/^\/inbox/, 'Open the Inbox'],
    [/^\/transactions/, 'Open Transactions'],
    [/^\/mileage/, 'Open Mileage'],
    [/^\/settings\/integrations/, 'Open Connections'],
];

/** What a button for an address in a message says: where it goes, in a few words. */
export function openWords(href: string): string {
    const path = localPath(href) ?? '';
    return OPEN_WORDS.find(([re]) => re.test(path))?.[1] ?? 'Open it';
}

/** Why a press got no answer the page could use, in words: never a blank error. */
export function noAnswerWords(status: number): string {
    if (status === 419 || status === 401) return 'You’ve been signed out, or the page is too old. Reload the page and try again.';
    if (status === 429) return 'That’s a lot of taps in a minute. Wait a moment, then try again.';
    if (status >= 500) return 'Something went wrong on Expend’s side. Reload the page to see whether it was done.';
    if (status >= 400) return 'That wasn’t accepted. Reload the page and try again.';
    return 'No answer from Expend (no signal?). Reload the page to see whether it was done.';
}

// How many posts are saying their own "no answer" words now (postQuietly): the toaster's catch-all stays quiet for them.
let quiet = 0;
export const saysItsOwnProblems = () => quiet > 0;

/**
 * A post that always ends in words: on success the page comes back with the server's own message (the toaster shows
 * it, a refusal included: "This changed since…"); a refusal of what was sent gives its first reason; and no answer, or
 * an answer that isn't a page (signed out, a server error), is said in words instead of Inertia covering the page.
 */
export function postQuietly(
    url: string,
    data: Record<string, string | number | boolean>,
    on: { problem: (words: string) => void; start?: () => void; finish?: () => void; success?: () => void },
) {
    let answered = false;
    let status = 0;
    let said: string | null = null;
    const stop = [
        router.on('exception', (e) => {
            e.preventDefault();
        }),
        router.on('invalid', (e) => {
            e.preventDefault();
            status = e.detail.response.status;
            const body = e.detail.response.data as unknown;
            if (body && typeof body === 'object' && typeof (body as { message?: unknown }).message === 'string') {
                said = (body as { message: string }).message || null;
            }
        }),
    ];
    quiet++;
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => on.start?.(),
        onSuccess: () => {
            answered = true;
            on.success?.();
        },
        onError: (errors) => {
            answered = true;
            on.problem(Object.values(errors)[0] || 'That wasn’t accepted, so nothing was done.');
        },
        onFinish: (visit) => {
            quiet--;
            stop.forEach((s) => s());
            if (!answered && !visit.cancelled && !visit.interrupted) on.problem(said ?? noAnswerWords(status));
            on.finish?.();
        },
    });
}
