<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Pages anyone may read, signed in or not. Google's OAuth consent screen links to the privacy page.
Route::get('privacy', fn () => Inertia::render('privacy', [
    'business' => Setting::get('business_name'),
    'email' => Setting::get('business_email'),
    'updated' => '24 November 2026',
]))->name('privacy');
