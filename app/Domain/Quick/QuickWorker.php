<?php

namespace App\Domain\Quick;

use App\Models\Setting;

/**
 * The service worker the phone installs (GET /sw.js): resources/sw/sw.js with its version and what to keep for offline
 * use filled in. The version is the first 12 hex characters of the sha256 of the Vite manifest, public/quick-store.js
 * and the template, so every deploy updates the worker. The precache list is the quick store script, the static files
 * and the Vite files for resources/js/app.tsx and the quick page, with their imports and CSS walked from the manifest.
 * The setting quick_sw_off serves a worker that deletes its caches and unregisters itself instead (the kill switch).
 */
final class QuickWorker
{
    public const TEMPLATE = 'resources/sw/sw.js';

    /** The worker served while the setting quick_sw_off is on: it removes its caches and itself. */
    public const OFF = 'resources/sw/off.js';

    public const STORE = 'public/quick-store.js';

    /** The Vite entries the quick screen needs offline. */
    public const ENTRIES = ['resources/js/app.tsx', 'resources/js/pages/quick/index.tsx'];

    /** Static files kept for offline use (each only when it exists). */
    public const STATIC = ['/offline.html', '/manifest.webmanifest', '/icon-192.png', '/icon-512.png', '/icon-maskable-512.png', '/apple-touch-icon.png',
        '/shortcut-add-96.png', '/shortcut-todo-96.png'];

    /** Where the Vite manifest is read from (config expend.vite_manifest overrides it, for tests). */
    public static function manifestPath(): string
    {
        return (string) config('expend.vite_manifest', public_path('build/manifest.json'));
    }

    /** The worker's version: changes with the build, the quick store script and the template. */
    public static function version(): string
    {
        $parts = array_map(fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '', [self::manifestPath(), base_path(self::STORE), base_path(self::TEMPLATE)]);

        return substr(hash('sha256', implode("\0", $parts)), 0, 12);
    }

    /** The version of public/quick-store.js, for its ?v= in the page and the precache list. */
    public static function storeVersion(): string
    {
        $path = base_path(self::STORE);

        return is_file($path) ? substr(hash('sha256', (string) file_get_contents($path)), 0, 12) : '0';
    }

    /**
     * What the worker keeps: the quick store script, the static files that exist, and the Vite files for the quick
     * screen (each entry's file, its static imports and their CSS).
     *
     * @param  array<string, array<string, mixed>>|null  $manifest  the Vite manifest (read from manifestPath() when null)
     * @return list<string>
     */
    public static function precache(?array $manifest = null): array
    {
        $manifest ??= self::manifest();
        $out = ['/quick-store.js?v='.self::storeVersion()];
        foreach (self::STATIC as $file) {
            if (is_file(public_path(ltrim($file, '/')))) {
                $out[] = $file;
            }
        }
        $seen = [];
        $walk = function (string $key) use (&$walk, &$seen, &$out, $manifest) {
            if (isset($seen[$key]) || ! isset($manifest[$key])) {
                return;
            }
            $seen[$key] = true;
            $chunk = $manifest[$key];
            if (! empty($chunk['file'])) {
                $out[] = '/build/'.$chunk['file'];
            }
            foreach ((array) ($chunk['css'] ?? []) as $css) {
                $out[] = '/build/'.$css;
            }
            foreach ((array) ($chunk['imports'] ?? []) as $import) {
                $walk((string) $import);
            }
        };
        foreach (self::ENTRIES as $entry) {
            $walk($entry);
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, array<string, mixed>> */
    private static function manifest(): array
    {
        $path = self::manifestPath();
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($json) ? $json : [];
    }

    /**
     * The worker's script, as GET /sw.js serves it: the template with its two markers filled in (the quoted
     * __VERSION__ becomes the version as a JSON string, the bracketed __PRECACHE__ comment the precache list as a JSON
     * array). With quick_sw_off, the kill switch (resources/sw/off.js) instead.
     */
    public static function script(): string
    {
        if (Setting::get('quick_sw_off')) {
            return is_file(base_path(self::OFF)) ? (string) file_get_contents(base_path(self::OFF)) : self::killSwitch();
        }
        $template = is_file(base_path(self::TEMPLATE)) ? (string) file_get_contents(base_path(self::TEMPLATE)) : "// The service worker template isn't built yet.\nconst VERSION = '__VERSION__';\nconst PRECACHE = [/* __PRECACHE__ */];\n";
        $version = self::version();

        return str_replace(["'__VERSION__'", '[/* __PRECACHE__ */]'], [json_encode($version), json_encode(self::precache(), JSON_UNESCAPED_SLASHES)], $template);
    }

    /** A worker that deletes every cache it made and unregisters itself (the setting quick_sw_off). */
    public static function killSwitch(): string
    {
        return <<<'JS'
// Expend service worker: switched off. It removes its caches and itself.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            await caches.delete(key);
        }
        await self.registration.unregister();
        for (const client of await self.clients.matchAll({ type: 'window' })) {
            client.navigate(client.url);
        }
    })());
});
JS;
    }

    /**
     * The largest file the server takes in one upload, in bytes: the smaller of PHP's upload_max_filesize and
     * post_max_size, less 64 KB for the rest of the request. The phone refuses bigger PDFs before queueing them.
     */
    public static function maxUploadBytes(): int
    {
        $limits = array_filter([self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))], fn ($b) => $b > 0);

        return max(0, ($limits ? min($limits) : 2 * 1024 * 1024) - 64 * 1024);
    }

    /** "2M" → 2097152. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
