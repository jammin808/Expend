<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- Only the phone's quick screens draw under the notch (they pad themselves for it); other pages' headers don't. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1{{ str_starts_with((string) ($page['component'] ?? ''), 'quick/') ? ', viewport-fit=cover' : '' }}">

        <title inertia>Expend</title>

        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#0f172a">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Expend">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        {{--
            Quick add: keep the address the page was opened with (?job=14, ?source=home, ?share_failed=1) before Inertia
            rewrites it, and once the page has loaded, register the service worker and send what waits on the phone
            (public/quick-store.js, ExpendQuick.boot). quick-store.js is a plain script the worker shares; ?v changes
            when it does.
        --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            window.__quickLaunch = { path: location.pathname, search: location.search, hash: location.hash };
            addEventListener('load', function () {
                if (window.ExpendQuick) window.ExpendQuick.boot();
            });
        </script>
        @php($quickStoreFile = public_path('quick-store.js'))
        <script src="/quick-store.js?v={{ is_file($quickStoreFile) ? substr(hash_file('sha256', $quickStoreFile), 0, 12) : '0' }}" defer></script>

        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
