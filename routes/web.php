<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\ManualExpenseController;
use App\Http\Controllers\MileageController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TaxYearController;
use App\Http\Controllers\TransactionController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => User::query()->exists() ? redirect()->route('dashboard') : redirect()->route('register'))->name('home');

Route::middleware(['auth'])->group(function () {
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

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::post('jobs', [JobController::class, 'store'])->name('jobs.store');
    Route::get('jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
    Route::patch('jobs/{job}', [JobController::class, 'update'])->name('jobs.update');

    Route::get('mileage', [MileageController::class, 'index'])->name('mileage.index');
    Route::post('vehicles', [MileageController::class, 'storeVehicle'])->name('vehicles.store');
    Route::post('mileage', [MileageController::class, 'storeTrip'])->name('mileage.store');
    Route::patch('mileage/{trip}', [MileageController::class, 'updateTrip'])->name('mileage.update');
    Route::delete('mileage/{trip}', [MileageController::class, 'destroyTrip'])->name('mileage.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
