/**
 * JSON calls a page makes outside Inertia (the pickers' searches, adding a client), with a time limit so a venue with
 * no signal says so instead of spinning, and each failure in words the page can show.
 */

export const TIMEOUT_MS = 15_000;

export type ApiProblem = 'offline' | 'expired' | 'busy' | 'refused' | 'changed' | 'server';

/** Why a call didn't work, in words the page can show. */
export class ApiError extends Error {
    problem: ApiProblem;
    errors: Record<string, string>;
    /** What the server sent back with a refusal: for 'changed' (409), the sheet as it is now. */
    data: unknown;

    constructor(problem: ApiProblem, message: string, errors: Record<string, string> = {}, data: unknown = null) {
        super(message);
        this.problem = problem;
        this.errors = errors;
        this.data = data;
    }
}

const WORDS: Record<ApiProblem, string> = {
    offline: 'Can’t reach Expend. Try again when you have signal.',
    expired: 'You’ve been signed out, or the page is too old. Reload the page.',
    busy: 'That’s a lot of tries in a minute. Wait a moment, then try again.',
    refused: 'That wasn’t accepted.',
    changed: 'This changed since you looked: check it and try again. Nothing was done.',
    server: 'Something went wrong on Expend’s side. Try again in a moment.',
};

/** The token Laravel's cookie carries, sent back as a header. */
function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

async function call<T>(method: 'GET' | 'POST' | 'PATCH', url: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) throw new ApiError('offline', WORDS.offline);
    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), TIMEOUT_MS);
    const stop = () => abort.abort();
    signal?.addEventListener('abort', stop);
    let response: Response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            signal: abort.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrf(),
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch (e) {
        // Called off by the page (a newer search replaced it): not a problem to show.
        if (signal?.aborted) throw e;
        // No signal, the connection dropped, or no answer in 15 seconds.
        throw new ApiError('offline', WORDS.offline);
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', stop);
    }
    if (response.status === 419 || response.status === 401) throw new ApiError('expired', WORDS.expired);
    if (response.status === 429) throw new ApiError('busy', WORDS.busy);
    if (response.status === 422) {
        const data = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[] | string> };
        const errors: Record<string, string> = {};
        for (const [k, v] of Object.entries(data.errors ?? {})) errors[k] = Array.isArray(v) ? (v[0] ?? '') : v;
        throw new ApiError('refused', Object.values(errors)[0] || data.message || WORDS.refused, errors);
    }
    // Changed since you looked: nothing was done, and the server sends what it is now (a sheet to show again).
    if (response.status === 409) {
        const data = (await response.json().catch(() => ({}))) as { message?: string };
        throw new ApiError('changed', data.message || WORDS.changed, {}, data);
    }
    // Another service didn't answer (Gmail): the server says what to do instead.
    if (response.status === 503) {
        const data = (await response.json().catch(() => ({}))) as { message?: string };
        throw new ApiError('server', data.message || WORDS.server);
    }
    if (!response.ok) throw new ApiError('server', WORDS.server);
    try {
        return (await response.json()) as T;
    } catch {
        throw new ApiError('server', WORDS.server);
    }
}

export function getJson<T>(url: string, params: Record<string, string | number | null | undefined> = {}, signal?: AbortSignal): Promise<T> {
    const query = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) if (v !== null && v !== undefined && v !== '') query.set(k, String(v));
    const qs = query.toString();
    return call<T>('GET', qs ? `${url}?${qs}` : url, undefined, signal);
}

export function postJson<T>(url: string, body: unknown): Promise<T> {
    return call<T>('POST', url, body);
}

export function patchJson<T>(url: string, body: unknown): Promise<T> {
    return call<T>('PATCH', url, body);
}

/** The words for any error a call throws. */
export function problemText(e: unknown): string {
    return e instanceof ApiError ? e.message : WORDS.offline;
}

/** Whether an error is only the page calling off its own search. */
export function wasCalledOff(e: unknown): boolean {
    return e instanceof DOMException && e.name === 'AbortError';
}
