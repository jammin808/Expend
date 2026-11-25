/**
 * The kill switch: with the setting quick_sw_off, QuickController::serviceWorker serves this file at /sw.js in place
 * of sw.js. It deletes Expend's caches, unregisters itself and reloads open pages from the network. Entries waiting on
 * the phone are in IndexedDB, not in these caches, so they stay and the page sends them as usual.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const name of await caches.keys()) {
                if (name.startsWith('expend-')) await caches.delete(name);
            }
            await self.registration.unregister();
            const open = await self.clients.matchAll({ type: 'window' });
            for (const client of open) {
                if ('navigate' in client) client.navigate(client.url);
            }
        })(),
    );
});
