<?php

use App\Http\Controllers\QuickController;
use App\Http\Middleware\AuditChannel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Setting;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

// Pages anyone may read, signed in or not. Google's OAuth consent screen links to the privacy page.
Route::get('privacy', fn () => Inertia::render('privacy', [
    'business' => Setting::get('business_name'),
    'email' => Setting::get('business_email'),
    'updated' => '24 November 2026',
]))->name('privacy');

// Adding expenses on the phone, with no session: the service worker (an update check never starts a session or sets a
// cookie), Android's share sheet when the worker wasn't there to take it (no CSRF token can come from the share sheet;
// it stores nothing), and the page Telegram's links open, which goes on to the entry page within the site so the
// browser sends your sign-in.
$sessionless = [StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class, HandleInertiaRequests::class, AuditChannel::class];
Route::get('sw.js', [QuickController::class, 'serviceWorker'])->withoutMiddleware($sessionless)->name('quick.sw');
Route::post('quick/share', [QuickController::class, 'shareFallback'])->withoutMiddleware($sessionless)->middleware('throttle:30,1,quick-share')->name('quick.share');
Route::get('go', [QuickController::class, 'go'])->withoutMiddleware($sessionless)->middleware('throttle:60,1,quick-go')->name('quick.go');
