/* global importScripts, ExpendQuick */
/**
 * Expend's service worker: the Add expense screen offline, the Android share sheet, and sending what waits on the
 * phone when Android allows it in the background.
 *
 * This file is a template. QuickController::serviceWorker (App\Domain\Quick\QuickWorker::script) serves it at /sw.js
 * and fills in the two markers on the VERSION and PRECACHE lines below with str_replace; left alone, the template still
 * runs, with nothing precached:
 *   VERSION   becomes a JSON string: the first 12 hex characters of
 *             sha256(public/build/manifest.json + public/quick-store.js + resources/sw/sw.js)
 *   PRECACHE  becomes a JSON array of same-origin paths: /quick-store.js?v=..., /offline.html, /manifest.webmanifest,
 *             the icons, and the Vite files (with their imports and CSS) for resources/js/app.tsx and
 *             resources/js/pages/quick/index.tsx
 *
 * It handles only four kinds of request: a GET navigation to /quick (cache-first from the cached screen), other GET
 * navigations (the network, offline.html when there's none), GET /build/assets/* and the precached files
 * (cache-first), and the share-target POST /quick/share. Inertia visits (X-Inertia), JSON calls and every other POST
 * go to the network untouched.
 */

// The two markers must stay exactly as written (QuickWorker::script replaces them).
// prettier-ignore
const VERSION = '__VERSION__';
// prettier-ignore
const PRECACHE = [/* __PRECACHE__ */];

const PREFIX = 'expend-';
const CACHE = PREFIX + VERSION;
/** The cached Add expense screen, from GET /quick/shell, kept under a fixed key so any /quick?… finds it. */
const SHELL = '/quick-shell';
const OFFLINE = '/offline.html';
/** Background Sync gives up after this long; what's left waits for the next open. */
const SYNC_LIMIT_MS = 3 * 60 * 1000;

importScripts('/quick-store.js?v=' + encodeURIComponent(VERSION));

const precached = new Set(PRECACHE.map((path) => new URL(path, self.location.origin).pathname));

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE);
            // The static files must all be cached, or the worker doesn't install (the old one stays).
            await cache.addAll(Array.from(new Set([...PRECACHE, OFFLINE])));
            // The screen itself is best-effort: signed out, /quick/shell redirects to the login page, which is never kept.
            try {
                await refreshShell(cache);
            } catch {
                // No signal or signed out: the next signed-in open fetches it.
            }
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            await self.clients.claim();
            await pruneCaches();
        })(),
    );
});

/**
 * Keep this version's cache and the one before it. Older ones go only once this version holds a screen, so an
 * update that installed while signed out never leaves the phone without one.
 */
async function pruneCaches() {
    let order = [];
    try {
        order = (await ExpendQuick.getMeta('sw_versions')) || [];
    } catch {
        order = [];
    }
    if (order[order.length - 1] !== VERSION) {
        order = order.filter((v) => v !== VERSION).concat([VERSION]);
        try {
            await ExpendQuick.setMeta('sw_versions', order.slice(-5));
        } catch {
            // Storage unavailable: pruning waits.
        }
    }
    const current = await caches.open(CACHE);
    if (!(await current.match(SHELL))) return;
    const keep = new Set([CACHE, PREFIX + order[order.length - 2]]);
    for (const name of await caches.keys()) {
        if (name.startsWith(PREFIX) && !keep.has(name)) await caches.delete(name);
    }
}

/**
 * Signed in? Asked of /quick/token (a 401 when signed out), not of the screen itself: a signed-out request for
 * /quick/shell would leave it as the page the sign-in goes on to afterwards.
 */
async function signedIn() {
    try {
        const r = await fetch('/quick/token', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        return r.status === 200;
    } catch {
        return false;
    }
}

/** Fetch the screen from /quick/shell and keep it, but only a real one (200 with X-Expend-Shell: 1, never a redirect to sign in). */
async function refreshShell(cache) {
    if (!(await signedIn())) return false;
    const response = await fetch('/quick/shell', { redirect: 'manual', credentials: 'same-origin', cache: 'no-store' });
    if (response.status !== 200 || response.headers.get('X-Expend-Shell') !== '1') return false;
    await (cache || (await caches.open(CACHE))).put(SHELL, response);
    return true;
}

/** The newest cached screen: this version's, then the others newest first. */
async function cachedShell() {
    const current = await caches.open(CACHE);
    const hit = await current.match(SHELL);
    if (hit) return hit;
    const names = (await caches.keys()).filter((name) => name.startsWith(PREFIX) && name !== CACHE).reverse();
    for (const name of names) {
        const found = await (await caches.open(name)).match(SHELL);
        if (found) return found;
    }
    return undefined;
}

async function offlinePage() {
    const hit = await caches.match(OFFLINE);
    return (
        hit ||
        new Response('<!doctype html><meta name="viewport" content="width=device-width"><p>No signal. <a href="/quick">Add expense</a></p>', {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
        })
    );
}

async function quickNavigation(event) {
    const url = new URL(event.request.url);
    // "Sign in to send" goes through the server, so the login page comes back to /quick afterwards.
    if (!url.searchParams.has('signin')) {
        const shell = await cachedShell();
        if (shell) {
            event.waitUntil(refreshShell().catch(() => false));
            return shell;
        }
    }
    try {
        return await fetch(event.request);
    } catch {
        return offlinePage();
    }
}

async function cacheFirst(request, ignoreSearch) {
    const hit = await caches.match(request);
    if (hit) return hit;
    try {
        const response = await fetch(request);
        if (response.ok && response.type === 'basic') {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
    } catch (error) {
        // Offline with a newer ?v= than the one cached: the cached copy is better than nothing.
        const near = ignoreSearch ? await caches.match(request, { ignoreSearch: true }) : undefined;
        if (near) return near;
        throw error;
    }
}

/**
 * Android's share sheet posts here. Keep each file (and the text) in the phone's 'shared' store and go to /quick,
 * which takes them one at a time. Works with no signal, and needs no sign-in or form token.
 */
async function takeShare(request) {
    try {
        const form = await request.formData();
        const title = String(form.get('title') || '');
        const text = String(form.get('text') || '');
        const link = String(form.get('url') || '');
        const files = form.getAll('files').filter((file) => file && typeof file !== 'string');
        const at = Date.now();
        if (files.length === 0 && (title || text || link)) {
            await ExpendQuick.addShared({ at, title, text, url: link, file: null });
        }
        let first = true;
        for (const file of files) {
            const bytes = await file.arrayBuffer();
            await ExpendQuick.addShared({
                at,
                title: first ? title : '',
                text: first ? text : '',
                url: first ? link : '',
                file: { name: file.name || 'shared', type: file.type || 'application/octet-stream', size: bytes.byteLength, bytes },
            });
            first = false;
        }
        await ExpendQuick.setMeta('pending_share', at);
        ExpendQuick.notify();
        return Response.redirect('/quick?source=share', 303);
    } catch {
        return Response.redirect('/quick?share_failed=1', 303);
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.method === 'POST' && url.pathname === '/quick/share') {
        event.respondWith(takeShare(request));
        return;
    }
    if (request.method !== 'GET' || request.headers.has('X-Inertia')) return;

    if (request.mode === 'navigate') {
        if (url.pathname === '/quick') {
            event.respondWith(quickNavigation(event));
        } else {
            event.respondWith(fetch(request).catch(() => offlinePage()));
        }
        return;
    }
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(request, false));
        return;
    }
    // The manifest from the network when there is one, so a renamed app or a new shortcut is seen at once.
    if (url.pathname === '/manifest.webmanifest') {
        event.respondWith(fetch(request).catch(() => caches.match(request, { ignoreSearch: true }).then((hit) => hit || Response.error())));
        return;
    }
    if (precached.has(url.pathname) && url.pathname !== '/sw.js') {
        event.respondWith(cacheFirst(request, true));
    }
});

/** Android Chrome: send what's waiting, fields first, for at most three minutes. Work left over asks Chrome to try again. */
self.addEventListener('sync', (event) => {
    if (event.tag !== 'expend-send') return;
    event.waitUntil(
        (async () => {
            const result = await ExpendQuick.flush({ deadline: Date.now() + SYNC_LIMIT_MS, from: 'sync' });
            if (result && result.left > 0 && !result.signedOut && !event.lastChance) {
                throw new Error('Still waiting to send');
            }
        })(),
    );
});

self.addEventListener('message', (event) => {
    const type = event.data && event.data.type;
    if (type === 'flush') {
        event.waitUntil(ExpendQuick.flush({ reset: !!event.data.reset, from: 'worker' }).catch(() => null));
    } else if (type === 'refresh-shell') {
        event.waitUntil(refreshShell().catch(() => false));
    } else if (type === 'clear') {
        event.waitUntil(
            (async () => {
                for (const name of await caches.keys()) {
                    if (name.startsWith(PREFIX)) await (await caches.open(name)).delete(SHELL);
                }
            })(),
        );
    }
});
