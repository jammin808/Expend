<?php

use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\LedgerSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/ledger');

    Route::get('settings/ledger', [LedgerSettingsController::class, 'edit'])->name('ledger.edit');
    Route::put('settings/ledger', [LedgerSettingsController::class, 'update'])->name('ledger.update');
    Route::post('settings/calendar/sync', [LedgerSettingsController::class, 'syncCalendar'])->name('calendar.sync');

    Route::get('settings/integrations', [IntegrationSettingsController::class, 'edit'])->name('integrations.edit');
    Route::put('settings/integrations/ai', [IntegrationSettingsController::class, 'updateAi'])->name('integrations.ai');
    Route::put('settings/integrations/business', [IntegrationSettingsController::class, 'updateBusiness'])->name('integrations.business');
    Route::put('settings/integrations/email', [IntegrationSettingsController::class, 'updateEmail'])->name('integrations.email');
    Route::post('settings/integrations/telegram', [IntegrationSettingsController::class, 'connectTelegram'])->name('integrations.telegram');
    Route::delete('settings/integrations/telegram', [IntegrationSettingsController::class, 'disconnectTelegram'])->name('integrations.telegram.disconnect');
    Route::put('settings/integrations/mail', [IntegrationSettingsController::class, 'updateMail'])->name('integrations.mail');
    Route::post('settings/integrations/mail/test', [IntegrationSettingsController::class, 'testMail'])->middleware('throttle:5,1,mail-test')->name('integrations.mail.test');
    Route::put('settings/integrations/gmail', [IntegrationSettingsController::class, 'updateGmail'])->name('integrations.gmail');
    Route::post('settings/starling', [IntegrationSettingsController::class, 'connectStarling'])->middleware('throttle:10,1,starling-connect')->name('starling.connect');
    Route::patch('settings/starling', [IntegrationSettingsController::class, 'linkStarling'])->name('starling.link');
    Route::put('settings/starling/tax-pot', [IntegrationSettingsController::class, 'chooseTaxPot'])->middleware('throttle:10,1,starling-tax-pot')->name('starling.tax_pot');
    Route::post('settings/starling/sync', [IntegrationSettingsController::class, 'syncStarling'])->middleware('throttle:10,1,starling-sync')->name('starling.sync');
    Route::delete('settings/starling', [IntegrationSettingsController::class, 'disconnectStarling'])->name('starling.disconnect');
    Route::post('settings/gmail/connect', [IntegrationSettingsController::class, 'connectGmail'])->name('gmail.connect');
    Route::delete('settings/gmail', [IntegrationSettingsController::class, 'disconnectGmail'])->name('gmail.disconnect');
    Route::post('settings/gmail/labels', [IntegrationSettingsController::class, 'syncGmailLabels'])->name('gmail.labels');

    Route::get('settings/rules', [LedgerSettingsController::class, 'rules'])->name('rules.index');
    Route::patch('settings/rules/{rule}', [LedgerSettingsController::class, 'updateRule'])->name('rules.update');
    Route::delete('settings/rules/{rule}', [LedgerSettingsController::class, 'destroyRule'])->name('rules.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::post('settings/security/enable', [SecurityController::class, 'enable'])->name('security.enable');
    Route::post('settings/security/confirm', [SecurityController::class, 'confirm'])->name('security.confirm');
    Route::post('settings/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery');
    Route::delete('settings/security', [SecurityController::class, 'disable'])->name('security.disable');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
