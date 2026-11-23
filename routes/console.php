<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Mail\MailboxFetcher;
use App\Jobs\SyncGmailLabels;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ledger:verify-audit', function () {
    $result = AuditLog::verify();
    if ($result['ok']) {
        $this->info("Audit log intact ({$result['checked']} events).");

        return 0;
    }
    Log::critical('Audit log chain is broken', $result);
    $this->error("Audit log chain broken at event {$result['broken_at']} after {$result['checked']} good events.");

    return 1;
})->purpose('Check the audit log has not been altered');

Artisan::command('ledger:sync-calendar', function (CalendarSync $sync) {
    if (! Setting::get('calendar_ics_url')) {
        $this->line('No calendar feed set.');

        return 0;
    }
    $count = $sync->sync();
    Setting::put('calendar_synced_at', now()->format('Y-m-d H:i'));
    $this->info("Synced {$count} calendar events.");

    return 0;
})->purpose('Refresh calendar events from the private iCal feed');

Artisan::command('ledger:fetch-email', function (MailboxFetcher $fetcher) {
    if (! MailboxFetcher::configured()) {
        $this->line('No forwarding mailbox set.');

        return 0;
    }
    $result = $fetcher->fetch();
    $this->info("Took in {$result['taken']} emails, held {$result['held']} for approval.");

    return 0;
})->purpose('Read new emails from the forwarding mailbox');

Artisan::command('ledger:reset-link', function () {
    $user = User::query()->first();
    if (! $user) {
        $this->error('No account yet.');

        return 1;
    }
    $token = Password::broker()->createToken($user);
    AuditLog::record('auth.reset_link_created', $user, null, null, 'Created on the server with ledger:reset-link', 'server');
    $this->line(url(route('password.reset', ['token' => $token, 'email' => $user->email], false)));
    $this->line('Valid for '.config('auth.passwords.users.expire').' minutes, once.');

    return 0;
})->purpose('Print a one-time password reset link for the owner (for when email can’t be sent)');

// One cron entry on the server runs all of these: * * * * * php artisan schedule:run
Schedule::command('ledger:sync-calendar')->hourly()->withoutOverlapping();
Schedule::command('ledger:verify-audit')->dailyAt('03:10');
Schedule::command('ledger:fetch-email')->everyFiveMinutes()->withoutOverlapping();
// Catch-up for the Gmail "✓ Invoiced" label (POs linked after invoicing, or a run that failed).
Schedule::job(new SyncGmailLabels)->hourly()->name('gmail-labels')->withoutOverlapping();
// Shared hosting has no long-running worker: process queued work (document reading, Telegram) every minute.
Schedule::command('queue:work --stop-when-empty --max-time=240 --tries=1')->everyMinute()->withoutOverlapping();

// Dead-man's switch: if these pings stop, the monitoring service alerts you that cron has died.
Schedule::call(function () {
    if ($url = config('services.heartbeat.url')) {
        Http::timeout(10)->get($url);
    }
})->everyFiveMinutes()->name('heartbeat');
