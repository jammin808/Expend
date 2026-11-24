<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\ConnectionsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JobCalendarController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobFinderController;
use App\Http\Controllers\ManualExpenseController;
use App\Http\Controllers\MileageController;
use App\Http\Controllers\RuleLearningController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TaxHelpController;
use App\Http\Controllers\TaxYearController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TravelController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Before the owner exists, a live site (with a setup code) still shows the sign-in page, so the sign-up page stays hidden.
Route::get('/', fn () => redirect()->route(User::query()->exists() || config('app.setup_code') ? 'dashboard' : 'register'))->name('home');

Route::post('hooks/telegram', TelegramWebhookController::class)->middleware('throttle:120,1')->name('hooks.telegram');

// Google's redirect back after you allow Gmail access. No session here: see IntegrationSettingsController::gmailCallback.
Route::get('settings/gmail/callback', [IntegrationSettingsController::class, 'gmailCallback'])->middleware('throttle:20,1')
    ->withoutMiddleware([
        StartSession::class, ShareErrorsFromSession::class,
        ValidateCsrfToken::class, AddQueuedCookiesToResponse::class,
        HandleInertiaRequests::class,
    ])->name('gmail.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('travel', [TravelController::class, 'index'])->name('travel');
    Route::post('travel/scan', [TravelController::class, 'scan'])->name('travel.scan');
    Route::post('travel/check', [TravelController::class, 'check'])->name('travel.check');
    Route::post('travel/{item}/decide', [TravelController::class, 'decide'])->name('travel.decide');
    Route::post('travel/{item}/reopen', [TravelController::class, 'reopen'])->name('travel.reopen');
    Route::post('travel/messages/{message}/read', [TravelController::class, 'reread'])->name('travel.reread');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('setup', [SetupController::class, 'show'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

    Route::get('inbox', InboxController::class)->name('inbox');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('items/{source}/{id}', [TransactionController::class, 'show'])->whereIn('source', ['bank', 'manual'])->name('items.show');
    Route::post('items/{source}/{id}/decide', [DecisionController::class, 'decide'])->whereIn('source', ['bank', 'manual'])->name('items.decide');
    Route::post('items/{source}/{id}/confirm', [DecisionController::class, 'confirm'])->whereIn('source', ['bank', 'manual'])->name('items.confirm');
    Route::post('items/{source}/{id}/clear', [DecisionController::class, 'clear'])->whereIn('source', ['bank', 'manual'])->name('items.clear');
    Route::post('decisions/bulk', [DecisionController::class, 'bulk'])->name('decisions.bulk');
    Route::post('decisions/confirm', [DecisionController::class, 'confirmBulk'])->name('decisions.confirm');

    Route::post('expenses', [ManualExpenseController::class, 'store'])->name('expenses.store');
    Route::get('fx/rate', [ManualExpenseController::class, 'rate'])->name('fx.rate');

    Route::get('years', [TaxYearController::class, 'index'])->name('years.index');
    Route::post('years', [TaxYearController::class, 'store'])->name('years.store');
    Route::get('years/{label}', [TaxYearController::class, 'show'])->where('label', '\d{4}-\d{2}')->name('years.show');
    Route::patch('years/{label}', [TaxYearController::class, 'update'])->where('label', '\d{4}-\d{2}')->name('years.update');
    Route::post('years/{label}/lock', [TaxYearController::class, 'lock'])->where('label', '\d{4}-\d{2}')->name('years.lock');
    Route::get('years/{label}/export', [TaxYearController::class, 'export'])->where('label', '\d{4}-\d{2}')->name('years.export');

    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('imports/commit', [ImportController::class, 'commit'])->name('imports.commit');
    Route::post('imports/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
    Route::post('imports/{batch}/revert', [ImportController::class, 'revert'])->name('imports.revert');
    Route::post('imports/{batch}/accept', [ImportController::class, 'accept'])->name('imports.accept');
    Route::post('imports/{batch}/discard', [ImportController::class, 'discard'])->name('imports.discard');
    Route::post('imports/{batch}/restore', [ImportController::class, 'restore'])->name('imports.restore');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/balances', [AccountController::class, 'addBalance'])->name('accounts.balances.store');
    Route::delete('accounts/{account}/balances/{balance}', [AccountController::class, 'removeBalance'])->name('accounts.balances.destroy');

    Route::get('jobs/finder', [JobFinderController::class, 'index'])->name('jobs.finder');
    Route::post('jobs/finder/run', [JobFinderController::class, 'run'])->name('jobs.finder.run');
    Route::post('jobs/finder/links', [JobFinderController::class, 'decideLink'])->name('jobs.finder.links');
    Route::patch('jobs/finder/{candidate}', [JobFinderController::class, 'update'])->name('jobs.finder.update');
    Route::post('jobs/finder/{candidate}/accept', [JobFinderController::class, 'accept'])->name('jobs.finder.accept');
    Route::post('jobs/finder/{candidate}/merge', [JobFinderController::class, 'merge'])->name('jobs.finder.merge');
    Route::post('jobs/finder/{candidate}/dismiss', [JobFinderController::class, 'dismiss'])->name('jobs.finder.dismiss');
    Route::post('jobs/{job}/links', [JobFinderController::class, 'link'])->name('jobs.links');
    Route::post('calendar/job', [JobFinderController::class, 'fromCalendar'])->name('calendar.job');
    Route::get('jobs/calendar', [JobCalendarController::class, 'index'])->name('jobs.calendar');
    Route::post('calendar/link-job', [JobCalendarController::class, 'linkToJob'])->name('calendar.linkJob');
    Route::post('jobs/{job}/gather', [JobCalendarController::class, 'gather'])->middleware('throttle:20,1')->name('jobs.gather');
    Route::post('job-finds/{find}/accept', [JobCalendarController::class, 'accept'])->name('jobFinds.accept');
    Route::post('job-finds/{find}/reject', [JobCalendarController::class, 'reject'])->name('jobFinds.reject');
    Route::post('calendar/not-job', [JobFinderController::class, 'calendarNotJob'])->name('calendar.notJob');
    Route::get('connections', [ConnectionsController::class, 'index'])->name('connections');
    Route::get('todo', [TodoController::class, 'index'])->name('todo');
    Route::get('search', SearchController::class)->name('search');
    Route::get('explain/category/{category}', [TaxHelpController::class, 'category'])->name('explain.category');
    Route::get('explain/{source}/{id}', [TaxHelpController::class, 'payment'])->whereIn('source', ['bank', 'manual'])->whereNumber('id')->name('explain.payment');
    Route::get('tax-guide', [TaxHelpController::class, 'guide'])->name('tax-guide');
    Route::post('rules/learn', [RuleLearningController::class, 'accept'])->name('rules.learn');
    Route::post('rules/learn/decline', [RuleLearningController::class, 'decline'])->name('rules.decline');
    Route::post('rules/{rule}/undo', [RuleLearningController::class, 'undo'])->name('rules.undo');
    Route::post('todo/dismiss', [TodoController::class, 'dismiss'])->name('todo.dismiss');
    Route::post('todo/{code}/{id}', [TodoController::class, 'act'])->where('id', '[A-Za-z0-9_:]+')->name('todo.act');
    Route::post('checks/{type}/{id}', [VerificationController::class, 'store'])->whereNumber('id')->name('checks.store');
    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::post('jobs', [JobController::class, 'store'])->name('jobs.store');
    Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
    Route::patch('jobs/{job}', [JobController::class, 'update'])->name('jobs.update');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('documents/{document}/read', [DocumentController::class, 'read'])->name('documents.read');
    Route::post('documents/{document}/job', [DocumentController::class, 'createJob'])->name('documents.job');
    Route::post('documents/{document}/invoice', [DocumentController::class, 'invoice'])->name('documents.invoice');
    Route::get('documents/{document}/file', [DocumentController::class, 'download'])->name('documents.file');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('jobs/{job}/invoice', [InvoiceController::class, 'storeForJob'])->name('invoices.forJob');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant');
    Route::post('assistant/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1')->name('assistant.ask');
    Route::post('ai/suggest', [AssistantController::class, 'suggest'])->middleware('throttle:10,1')->name('ai.suggest');

    Route::get('mileage', [MileageController::class, 'index'])->name('mileage.index');
    Route::post('vehicles', [MileageController::class, 'storeVehicle'])->name('vehicles.store');
    Route::post('mileage', [MileageController::class, 'storeTrip'])->name('mileage.store');
    Route::post('mileage/from-jobs', [MileageController::class, 'fromJobs'])->name('mileage.fromJobs');
    Route::patch('mileage/{trip}', [MileageController::class, 'updateTrip'])->name('mileage.update');
    Route::delete('mileage/{trip}', [MileageController::class, 'destroyTrip'])->name('mileage.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
