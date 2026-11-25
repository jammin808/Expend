<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\CalendarPickController;
use App\Http\Controllers\ConnectionsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\IntelController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JobCalendarController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobFinderController;
use App\Http\Controllers\ManualExpenseController;
use App\Http\Controllers\MileageController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\QuickController;
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
use App\Http\Controllers\WhyController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Before the owner exists, a live site (with a setup code) still shows the sign-in page, so the sign-up page stays hidden.
Route::get('/', fn () => redirect()->route(User::query()->exists() || config('app.setup_code') ? 'dashboard' : 'register'))->name('home');

Route::post('hooks/telegram', TelegramWebhookController::class)->middleware('throttle:120,1,telegram-hook')->name('hooks.telegram');

// Google's redirect back after you allow Gmail access. No session here: see IntegrationSettingsController::gmailCallback.
Route::get('settings/gmail/callback', [IntegrationSettingsController::class, 'gmailCallback'])->middleware('throttle:20,1,gmail-callback')
    ->withoutMiddleware([
        StartSession::class, ShareErrorsFromSession::class,
        ValidateCsrfToken::class, AddQueuedCookiesToResponse::class,
        HandleInertiaRequests::class,
    ])->name('gmail.callback');

// Adding expenses on the phone: the token, the day's data and the suggestion answer JSON, with a 401 (never a redirect
// to the sign-in page) when you're signed out, so the phone keeps what it has and says "Sign in to send". A remember-me
// cookie signs you back in here.
Route::get('quick/token', [QuickController::class, 'token'])->middleware('throttle:60,1,quick-token')->name('quick.token');
Route::get('quick/pocket', [QuickController::class, 'pocket'])->middleware('throttle:60,1,quick-pocket')->name('quick.pocket');
Route::get('quick/suggest', [QuickController::class, 'suggest'])->middleware('throttle:120,1,quick-suggest')->name('quick.suggest');

Route::middleware(['auth'])->group(function () {
    // The quick add screen, its copy for the service worker, and what the phone sends: an entry's fields, then its
    // photo (multipart field "photo", POST with _method=PUT or a PUT), and Undo by the entry's key. Each limit has its
    // own count (the third part).
    Route::get('quick', [QuickController::class, 'show'])->name('quick');
    Route::get('quick/shell', [QuickController::class, 'shell'])->name('quick.shell');
    Route::post('quick/entries', [QuickController::class, 'store'])->middleware('throttle:60,1,quick-entries')->name('quick.entries.store');
    Route::match(['put', 'post'], 'quick/entries/{key}/photo', [QuickController::class, 'photo'])->where('key', '[A-Za-z0-9\-]{8,80}')
        ->middleware('throttle:30,1,quick-photos')->name('quick.entries.photo');
    Route::post('quick/entries/{key}/withdraw', [QuickController::class, 'withdraw'])->where('key', '[A-Za-z0-9\-]{8,80}')
        ->middleware('throttle:60,1,quick-withdraw')->name('quick.entries.withdraw');
    Route::get('quick/entries/{entry}', [QuickController::class, 'entry'])->whereNumber('entry')->name('quick.entries.show');
    Route::patch('quick/entries/{entry}', [QuickController::class, 'update'])->whereNumber('entry')->name('quick.entries.update');
    Route::post('quick/entries/{entry}/attach', [QuickController::class, 'attach'])->whereNumber('entry')->name('quick.entries.attach');
    Route::post('quick/entries/{entry}/detach', [QuickController::class, 'detach'])->whereNumber('entry')->name('quick.entries.detach');
    Route::post('quick/entries/{entry}/read', [QuickController::class, 'readAgain'])->whereNumber('entry')->middleware('throttle:10,1,quick-read')->name('quick.entries.read');

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
    // Why a line or an automatic link is as it is (read only).
    Route::get('why', [WhyController::class, 'show'])->name('why');
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
    Route::patch('years/{label}/submissions/{submission}', [TaxYearController::class, 'submission'])->where('label', '\d{4}-\d{2}')->name('years.submission');
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
    Route::post('jobs/{job}/gather', [JobCalendarController::class, 'gather'])->middleware('throttle:20,1,jobs-gather')->name('jobs.gather');
    Route::post('jobs/{job}/days', [JobCalendarController::class, 'addDay'])->name('jobs.days.store');
    Route::patch('job-days/{day}', [JobCalendarController::class, 'updateDay'])->name('jobs.days.update');
    Route::delete('job-days/{day}', [JobCalendarController::class, 'removeDay'])->name('jobs.days.destroy');
    Route::post('job-finds/{find}/accept', [JobCalendarController::class, 'accept'])->name('jobFinds.accept');
    Route::post('job-finds/{find}/reject', [JobCalendarController::class, 'reject'])->name('jobFinds.reject');
    Route::post('calendar/not-job', [JobFinderController::class, 'calendarNotJob'])->name('calendar.notJob');
    // Picking days on the calendar (or "Add days" on a job) and making them one job, or adding them to one. The month,
    // the review (a POST so a long pick isn't held to URL limits) and the job search only read. Each limit has its own
    // count (the third part): without one, every limited route shares one count per person, so flicking through
    // months could use up the saves'.
    Route::get('calendar/month', [CalendarPickController::class, 'month'])->middleware('throttle:120,1,calendar-month')->name('calendar.month');
    Route::post('calendar/pick/preview', [CalendarPickController::class, 'preview'])->middleware('throttle:60,1,calendar-preview')->name('calendar.pick.preview');
    Route::get('calendar/pick/jobs', [CalendarPickController::class, 'jobs'])->middleware('throttle:60,1,calendar-jobs')->name('calendar.pick.jobs');
    Route::post('calendar/pick', [CalendarPickController::class, 'store'])->middleware('throttle:20,1,calendar-pick')->name('calendar.pick.store');
    Route::post('jobs/{job}/days/pick', [CalendarPickController::class, 'addToJob'])->middleware('throttle:20,1,calendar-pick')->name('jobs.days.pick');
    Route::get('connections', [ConnectionsController::class, 'index'])->name('connections');
    Route::get('todo', [TodoController::class, 'index'])->name('todo');
    Route::get('todo/history', [TodoController::class, 'history'])->name('todo.history');
    // What Expend found and did overnight, "Check now", and the nightly cycle's run log.
    Route::get('intel', [IntelController::class, 'index'])->name('intel.index');
    Route::get('intel/runs', [IntelController::class, 'runs'])->name('intel.runs');
    Route::post('intel/check', [IntelController::class, 'check'])->middleware('throttle:6,1,intel-check')->name('intel.check');
    // Links Expend made on its own: accept, or "Not this one" (remembered, so it isn't linked again).
    Route::get('intel/proposals', [IntelController::class, 'proposals'])->name('intel.proposals');
    Route::post('intel/proposals', [IntelController::class, 'decideProposal'])->name('intel.proposals.decide');
    Route::get('search', SearchController::class)->name('search');
    Route::get('explain/category/{category}', [TaxHelpController::class, 'category'])->name('explain.category');
    Route::get('explain/{source}/{id}', [TaxHelpController::class, 'payment'])->whereIn('source', ['bank', 'manual'])->whereNumber('id')->name('explain.payment');
    Route::get('tax-guide', [TaxHelpController::class, 'guide'])->name('tax-guide');
    // Structures and planning: sole trader, company or partnership side by side, legal ideas, grants and loans, traps.
    Route::get('planning', [PlanningController::class, 'index'])->name('planning');
    Route::post('rules/learn', [RuleLearningController::class, 'accept'])->name('rules.learn');
    Route::post('rules/learn/decline', [RuleLearningController::class, 'decline'])->name('rules.decline');
    Route::post('rules/{rule}/undo', [RuleLearningController::class, 'undo'])->name('rules.undo');
    Route::post('todo/dismiss', [TodoController::class, 'dismiss'])->name('todo.dismiss');
    Route::post('todo/{code}/{id}', [TodoController::class, 'act'])->where('id', '[A-Za-z0-9_:,]+')->name('todo.act');
    Route::post('checks/{type}/{id}', [VerificationController::class, 'store'])->whereNumber('id')->name('checks.store');
    // Work you might get, from your emails and documents: nothing here replies to anyone.
    Route::get('opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
    Route::post('opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
    Route::get('opportunities/po-chase', [OpportunityController::class, 'poChase'])->name('opportunities.poChase');
    Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])->whereNumber('opportunity')->name('opportunities.show');
    Route::patch('opportunities/{opportunity}', [OpportunityController::class, 'update'])->whereNumber('opportunity')->name('opportunities.update');
    Route::post('opportunities/{opportunity}/accept', [OpportunityController::class, 'accept'])->whereNumber('opportunity')->name('opportunities.accept');
    Route::post('opportunities/{opportunity}/decline', [OpportunityController::class, 'decline'])->whereNumber('opportunity')->name('opportunities.decline');
    Route::post('opportunities/{opportunity}/dismiss', [OpportunityController::class, 'dismiss'])->whereNumber('opportunity')->name('opportunities.dismiss');
    Route::post('opportunities/{opportunity}/reopen', [OpportunityController::class, 'reopen'])->whereNumber('opportunity')->name('opportunities.reopen');
    Route::post('opportunities/{opportunity}/link', [OpportunityController::class, 'confirmLink'])->whereNumber('opportunity')->name('opportunities.link');

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
    Route::post('assistant/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1,assistant-ask')->name('assistant.ask');
    Route::post('ai/suggest', [AssistantController::class, 'suggest'])->middleware('throttle:10,1,ai-suggest')->name('ai.suggest');

    Route::get('mileage', [MileageController::class, 'index'])->name('mileage.index');
    Route::post('vehicles', [MileageController::class, 'storeVehicle'])->name('vehicles.store');
    Route::post('mileage', [MileageController::class, 'storeTrip'])->name('mileage.store');
    Route::post('mileage/from-jobs', [MileageController::class, 'fromJobs'])->name('mileage.fromJobs');
    Route::post('mileage/not-driven', [MileageController::class, 'notDriven'])->name('mileage.notDriven');
    Route::post('mileage/not-driven/{job}/undo', [MileageController::class, 'putBack'])->name('mileage.putBack');
    Route::patch('mileage/{trip}', [MileageController::class, 'updateTrip'])->name('mileage.update');
    Route::delete('mileage/{trip}', [MileageController::class, 'destroyTrip'])->name('mileage.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
