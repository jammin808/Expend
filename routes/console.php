<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Banks\StarlingSync;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\Opportunities;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\TransferPairer;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Verification\Verifier;
use App\Jobs\FindJobs;
use App\Jobs\ScanGmail;
use App\Jobs\SyncGmailLabels;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\UkTaxYear;
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
    FindJobs::dispatch();

    return 0;
})->purpose('Refresh calendar events from the private iCal feed');

Artisan::command('ledger:find-jobs {year? : A tax year such as 2024-25; default the last 30 months and the next year} {--no-ai : Rules only}', function (JobFinder $finder, Verifier $verifier) {
    $r = $finder->run($this->argument('year'), ! $this->option('no-ai'));
    $open = JobCandidate::query()->where('status', 'open')->get()->each(fn ($c) => $verifier->candidate($c))->count();
    $this->info("Linked {$r['linked']} to existing jobs, {$r['proposed']} possible jobs proposed ({$open} open). AI asked about {$r['ai_checked']} calendar entries; {$r['ai_rejected']} answers failed the check against the entry and were ignored.");

    return 0;
})->purpose('Find jobs in your calendar, documents, stays and journeys, and link what clearly belongs to a job');

Artisan::command('ledger:opportunities', function (Opportunities $opportunities) {
    $r = $opportunities->refresh();
    $this->info("Opportunities: {$r['read']} read documents looked at, {$r['linked']} matched to jobs you've added, {$r['expired']} passed with no answer. "
        ."{$r['open']} open, {$r['needs_job']} confirmed with no job yet.");

    return 0;
})->purpose('Keep job opportunities up to date: match them to your jobs and close the ones that have passed (never makes a job)');

Artisan::command('ledger:recheck {--limit=80} {--second-reads=5} {--no-gmail : Don’t fetch emails again from Gmail}', function (Verifier $verifier) {
    $r = $verifier->recheck((int) $this->option('limit'), (int) $this->option('second-reads'), ! $this->option('no-gmail'));
    $this->info("Re-checked {$r['checked']} AI findings against their sources; {$r['warnings']} need a look.");

    return 0;
})->purpose('Double-check AI findings against their sources again (documents, Gmail, calendar, payments)');

Artisan::command('ledger:telegram-digest', function () {
    $chat = Setting::get('telegram_chat_id');
    if (! $chat || ! Telegram::token()) {
        $this->line('Telegram isn’t paired.');

        return 0;
    }
    Telegram::allowButtons();
    $sent = Telegram::sendTodo($chat);
    $this->info("Sent the to-do digest ({$sent} items with buttons).");

    return 0;
})->purpose('Send the to-do list to Telegram, with buttons');

Artisan::command('ledger:invoice-nudges', function () {
    $chat = Setting::get('telegram_chat_id');
    if (! $chat || ! Telegram::token()) {
        return 0;
    }
    Telegram::allowButtons();
    $n = 0;
    $overdue = Invoice::query()->where('status', 'issued')->whereNotNull('due_date')->whereDate('due_date', '<', today())
        ->where(fn ($q) => $q->whereNull('overdue_nudged_at')->orWhere('overdue_nudged_at', '<', now()->subDays(7)))->get();
    // What has come in for each, counted as the to-do list counts it (payments linked to it, income for its job).
    $received = InvoicePayments::received($overdue);
    foreach ($overdue as $invoice) {
        ['paid' => $paid, 'elsewhere' => $elsewhere, 'waiting' => $waiting] = $received[$invoice->id] ?? ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0];
        if ($paid >= $invoice->total_minor) {
            continue;
        }
        $days = (int) $invoice->due_date->diffInDays(today());
        $money = fn (int $minor) => Money::format($minor, $invoice->currency ?: 'GBP', false);
        // The reminder email tells the client no payment has been seen, so it's only offered when nothing has come in.
        $come = match (true) {
            $waiting > 0 => ' '.Money::format($waiting, 'GBP', false).' that may be for it is waiting in the Inbox: decide it first.',
            $paid > 0 => ' '.$money($paid).' of it has come in, so '.$money($invoice->total_minor - $paid).' is still to come.',
            $elsewhere > 0 => ' '.Money::format($elsewhere, 'GBP', false).' came in for this job in another currency: check whether it’s been paid.',
            default => null,
        };
        Telegram::sendWithButtons($chat, "Invoice {$invoice->number} to ".($invoice->bill_to_name ?: 'the client').' is '.$days.' days overdue ('
            .$money($invoice->total_minor).').'.($come ?? '').($invoice->reminder_count ? " Reminders sent: {$invoice->reminder_count}." : ''),
            $invoice->bill_to_email && $come === null ? [['Send a reminder', 'inv_chase', $invoice->id]] : []);
        $invoice->forceFill(['overdue_nudged_at' => now()])->save();
        $n++;
    }
    $this->info("Nudged about {$n} overdue invoices.");

    return 0;
})->purpose('Tell you on Telegram about invoices past their due date, with a button to send a reminder');

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
Artisan::command('ledger:findings', function () {
    $r = FindingsRegistry::sync();
    $this->info("Findings: {$r['new']} new, {$r['resolved']} resolved, {$r['open']} open.");
})->purpose('Record what the to-do list finds and what has been dealt with since');

Artisan::command('ledger:categorise', function (Categoriser $categoriser) {
    // The whole suggestion chain (your rules, open invoices, HMRC, fines, car hire and travel, then what you decided
    // before), as for a new statement: never the learned guesses alone, which would ignore your rules and invoices.
    $made = 0;
    BankTransaction::query()->where('review_status', 'unreviewed')->whereDoesntHave('allocations')->whereNull('transfer_pair_id')->orderBy('id')
        ->chunkById(300, function ($txs) use ($categoriser, &$made) {
            $made += $categoriser->suggestFor($txs);
        });
    $this->info("Suggested {$made} from your rules, open invoices, travel and HMRC patterns, your past decisions, the bank's categories and common merchants.");
})->purpose('Suggest what each undecided payment is, from your rules, invoices and past decisions');

Artisan::command('ledger:transfers', function (TransferPairer $pairer) {
    $pairs = $pairer->pairAll();
    $one = $pairer->suggestOneSided();
    $this->info("Paired {$pairs} transfers; suggested {$one} more from your names and accounts."
        .($pairer->cleared ? " Took back {$pairer->cleared} earlier suggestions that no longer hold." : ''));
})->purpose('Find money moving between your own accounts, so it is never counted as spending or income');

Schedule::command('ledger:sync-calendar')->hourly()->withoutOverlapping();
Schedule::command('ledger:verify-audit')->dailyAt('03:10');
Schedule::command('ledger:fetch-email')->everyFiveMinutes()->withoutOverlapping();
// Starling statements, every morning (if connected).
Schedule::call(function () {
    if (StarlingSync::connected()) {
        app(StarlingSync::class)->sync();
    }
})->dailyAt('06:41')->timezone('Europe/London')->name('starling-import')->withoutOverlapping();
// What needs you, on Telegram every Monday morning; overdue invoices each weekday.
Schedule::command('ledger:telegram-digest')->weeklyOn(1, '08:47')->timezone('Europe/London');
Schedule::command('ledger:invoice-nudges')->weekdays()->at('09:13')->timezone('Europe/London');
// The job finder and the double-check of AI findings, every night; scans get a few second reads a night.
Schedule::command('ledger:transfers')->dailyAt('04:07')->withoutOverlapping();
Schedule::command('ledger:categorise')->dailyAt('04:12')->withoutOverlapping();
Schedule::command('ledger:find-jobs')->dailyAt('04:17')->withoutOverlapping();
Schedule::command('ledger:opportunities')->dailyAt('04:27')->withoutOverlapping();
Schedule::command('ledger:recheck')->dailyAt('04:47')->withoutOverlapping();
Schedule::command('ledger:findings')->dailyAt('05:07')->withoutOverlapping();
// Re-run the Gmail searches weekly for this tax year (and last year until the 31 January deadline), so new,
// changed and cancelled bookings and POs are picked up. Emails already read are skipped.
Schedule::call(function () {
    if (! GmailClient::connected()) {
        return;
    }
    $years = [UkTaxYear::current()->label()];
    if (now()->month <= 1 || now()->month >= 4) {
        $years[] = UkTaxYear::current()->previous()->label();
    }
    foreach ($years as $year) {
        foreach (array_keys(GmailScanner::SEARCHES) as $search) {
            ScanGmail::dispatch($search, $year);
        }
    }
})->weeklyOn(0, '05:23')->name('gmail-rescan');
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
