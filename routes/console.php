<?php

use App\Console\Commands\IntelBackfill;
use App\Domain\Ai\AiUsage;
use App\Domain\Audit\AuditLog;
use App\Domain\Banks\StarlingSync;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Calendar\EmptyFeed;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\RelateCategorise;
use App\Domain\Intel\Cycle\Steps\RelateTransfers;
use App\Domain\Intel\Cycle\Steps\VerifyAuditChain;
use App\Domain\Intel\Detectors\Health;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\Opportunities;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\TransferPairer;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\ReceiptAttacher;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Verification\Verifier;
use App\Jobs\ScanGmail;
use App\Jobs\SyncGmailLabels;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ledger:verify-audit', function () {
    // The same check as the nightly cycle's verify.audit_chain: kept for the to-do list, a break told on Telegram at once.
    $result = VerifyAuditChain::check();
    if ($result['ok']) {
        $this->info("Audit log intact ({$result['checked']} events).");

        return 0;
    }
    $this->error("Audit log chain broken at event {$result['broken_at']} after {$result['checked']} good events.");

    return 1;
})->purpose('Check the audit log has not been altered (a break goes first on the to-do list, and to Telegram at once)');

Artisan::command('ledger:sync-calendar', function (CalendarSync $sync) {
    if (! Setting::get('calendar_ics_url')) {
        $this->line('No calendar feed set.');

        return 0;
    }
    try {
        // Then the job finder looks at what's new (CalendarSync::sync queues it).
        $count = $sync->sync();
    } catch (EmptyFeed $e) {
        // Kept, and not counted as a refresh: if it keeps happening, the to-do list says the calendar isn't updating.
        $this->warn($e->getMessage());

        return 0;
    }
    Setting::put('calendar_synced_at', now()->format('Y-m-d H:i'));
    $this->info("Synced {$count} calendar events.");

    return 0;
})->purpose('Refresh calendar events from the private iCal feed, then look for jobs in them');

Artisan::command('ledger:find-jobs {year? : A tax year such as 2024-25; default the last 30 months and the next year} {--no-ai : Rules only}', function (JobFinder $finder, Verifier $verifier) {
    // One run of the job finder at a time (shared with the queued FindJobs and the nightly cycle's relate.jobs).
    [$ran, $r] = JobFinder::exclusively(fn () => $finder->run($this->argument('year'), ! $this->option('no-ai')), 120);
    if (! $ran) {
        $this->warn('The job finder is already running (queued, or in the nightly check). Try again in a few minutes.');

        return 0;
    }
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
    // Its second reads are double-checks: held to that share of the month's AI budget.
    $r = AiUsage::withPurpose('verify', fn () => $verifier->recheck((int) $this->option('limit'), (int) $this->option('second-reads'), ! $this->option('no-gmail')));
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
    // The overdue invoices are the to-do list's invoice.overdue findings, one logic for both: brought up to date first
    // (FindingsRegistry::refresh(), as the Monday digest does), then those still open. One you snoozed or marked not
    // relevant on the to-do list isn't open, so it isn't nudged about. Your snoozes are read as they are now
    // (Dismissals::hiddenNow()), not only as last recorded: when something else is recording the findings at this
    // moment (refresh() then doesn't wait), one whose snooze has just ended is still nudged about.
    FindingsRegistry::refresh();
    $ids = Finding::query()->where(fn ($q) => $q->whereNull('resolved_at')->orWhereIn('resolution', ['snoozed', 'dismissed']))
        ->where(fn ($q) => $q->where('code', 'invoice.overdue')->orWhere(fn ($q) => $q->whereNull('code')->where('key', 'like', 'invoice_overdue:%')))
        ->get(['key'])->filter(fn (Finding $f) => Dismissals::hiddenNow($f->key) === null)
        ->map(fn (Finding $f) => (int) substr($f->key, strlen('invoice_overdue:')))->filter()->values()->all();
    $overdue = Invoice::query()->whereIn('id', $ids ?: [0])->where('status', 'issued')->whereNotNull('due_date')->whereDate('due_date', '<', today())
        ->where(fn ($q) => $q->whereNull('overdue_nudged_at')->orWhere('overdue_nudged_at', '<', now()->subDays(7)))->orderBy('id')->get();
    // What has come in for each, counted as the to-do list counts it (payments linked to it, income for its job).
    $received = InvoicePayments::received($overdue);
    foreach ($overdue as $invoice) {
        ['paid' => $paid, 'elsewhere' => $elsewhere, 'waiting' => $waiting] = $received[$invoice->id] ?? ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0];
        if ($paid >= $invoice->total_minor) {
            continue;
        }
        $days = (int) $invoice->due_date->diffInDays(today());
        $money = fn (int $minor) => Money::format($minor, $invoice->currency ?: 'GBP', false);
        // The reminder email tells the client no payment has been seen, so it's only offered when nothing has come in,
        // and never while the invoice itself waits unsent in your Gmail drafts.
        $come = match (true) {
            $waiting > 0 => ' '.Money::format($waiting, 'GBP', false).' that may be for it is waiting in the Inbox: decide it first.',
            $paid > 0 => ' '.$money($paid).' of it has come in, so '.$money($invoice->total_minor - $paid).' is still to come.',
            $elsewhere > 0 => ' '.Money::format($elsewhere, 'GBP', false).' came in for this job in another currency: check whether it’s been paid.',
            default => null,
        };
        Telegram::sendWithButtons($chat, "Invoice {$invoice->number} to ".($invoice->bill_to_name ?: 'the client').' is '.$days.' days overdue ('
            .$money($invoice->total_minor).').'.($come ?? '').($invoice->reminder_count ? " Reminders sent: {$invoice->reminder_count}." : ''),
            $invoice->bill_to_email && InvoiceEmails::open($invoice) === null && $come === null ? [['Send a reminder', 'inv_chase', $invoice->id]] : []);
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
    // How it went, kept for the health check (health.mailbox) only when that changes: read, or not (since when). Kept
    // every five minutes, it would empty the to-do list's cache each time.
    $last = Setting::get(Health::MAILBOX_RESULT);
    $wasOk = is_array($last) ? ($last['ok'] ?? null) : null;
    try {
        $result = $fetcher->fetch();
    } catch (Throwable $e) {
        if ($wasOk !== false) {
            Setting::put(Health::MAILBOX_RESULT, ['ok' => false, 'at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        throw $e;
    }
    if ($wasOk !== true) {
        Setting::put(Health::MAILBOX_RESULT, ['ok' => true, 'at' => now()->toIso8601String()]);
    }
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
    if (! empty($r['unseen'])) {
        // A limit left some out: missing ones aren't called dealt with (see FindingsRegistry).
        $this->line("{$r['unseen']} not looked for in full this time, so not marked dealt with.");
    }
    if (! empty($r['failed'])) {
        // Their findings are kept open until the check works again (see FindingsRegistry).
        $this->warn('Not worked out this time (see the log): '.implode(', ', array_map(fn ($p) => rtrim($p, ':'), $r['failed'])).'.');
    }
})->purpose('Record what the to-do list finds and what has been dealt with since');

Artisan::command('ledger:categorise', function (Categoriser $categoriser) {
    // The whole suggestion chain (your rules, open invoices, HMRC, fines, car hire and travel, then what you decided
    // before), as for a new statement: never the learned guesses alone, which would ignore your rules and invoices.
    // The same work as the nightly cycle's relate.categorise, 300 at a time.
    $made = 0;
    $after = 0;
    while (true) {
        [$n, $last] = RelateCategorise::chunk($categoriser, $after);
        if ($last === null) {
            break;
        }
        $made += $n;
        $after = $last;
    }
    $this->info("Suggested {$made} from your rules, open invoices, travel and HMRC patterns, your past decisions, the bank's categories and common merchants.");
})->purpose('Suggest what each undecided payment is, from your rules, invoices and past decisions');

Artisan::command('ledger:transfers', function (TransferPairer $pairer) {
    // The same work as the nightly cycle's relate.transfers.
    ['paired' => $pairs, 'one_sided' => $one, 'cleared' => $cleared, 'ambiguous' => $ambiguous] = RelateTransfers::work($pairer);
    $this->info("Paired {$pairs} transfers; suggested {$one} more from your names and accounts."
        .($cleared ? " Took back {$cleared} earlier suggestions that no longer hold." : '')
        .($ambiguous ? " {$ambiguous} ".($ambiguous === 1 ? 'line' : 'lines').' left for you: which partner is right isn’t clear.' : ''));
})->purpose('Find money moving between your own accounts, so it is never counted as spending or income');

Artisan::command('ledger:receipts', function (ReceiptAttacher $attacher) {
    // The same work as the nightly cycle's relate.receipts: business-card receipts from your phone still waiting go on
    // their bank lines (a statement imported by an old path, a line imported again), suggestions from receipts no
    // longer on their line are taken back; and photos whose read was refused at the AI spending cap are read again,
    // once a night.
    $attached = 0;
    $after = 0;
    $taken = 0;
    while (true) {
        $r = AuditContext::with('matcher', fn () => $attacher->sweep($after), 'schedule');
        $attached += $r['attached'];
        $taken += $r['taken_back'];
        if ($r['last_id'] === null) {
            break;
        }
        $after = $r['last_id'];
    }
    $again = QuickEntries::retryCappedReads();
    $this->info("Matched {$attached} receipts from your phone to their bank lines; took back {$taken} suggestions from receipts no longer on their line; {$again} photos to read again.");
})->purpose('Match business-card receipts from your phone to their bank lines, and read again photos refused at the AI cap');

// The nightly cycle (docs/intelligence-cycle.md section 6): tick is scheduled every minute; run and status are for you
// on the server. While intel_cycle is off, tick does nothing.
Artisan::command('ledger:cycle {action : tick, run, status, or mode to switch it} {value? : run: nightly, morning or manual (nightly when not given); mode: off, shadow or on} {--force : run: start a new run even if this one ran today}', function (CycleRunner $runner) {
    $action = (string) $this->argument('action');
    $value = $this->argument('value');
    if ($action === 'tick') {
        $r = $runner->tick();
        if ($r['started'] || $r['queued']) {
            $this->line(trim(($r['started'] ? "Started {$r['started']}. " : '').($r['queued'] ? "Queued the next step of run {$r['queued']}." : '')));
        }

        return 0;
    }
    if ($action === 'mode') {
        if (! in_array($value, CycleRunner::MODES, true)) {
            $this->error('Say off, shadow or on. Now: '.CycleRunner::mode().'.');

            return 1;
        }
        $r = $runner->switchMode($value);
        $this->info(['off' => 'The nightly cycle is off: the old nightly schedule runs as before.',
            'shadow' => 'The nightly cycle runs as a trial: it only looks and records what it would do; the old nightly schedule still does the work.',
            'on' => 'The nightly cycle is on: it does the nightly work, and the old nightly entries are skipped.'][$value]);
        if ($r['ended'] > 0) {
            $this->line("Ended {$r['ended']} ".($r['ended'] === 1 ? 'run' : 'runs').' still going.');
        }
        if ($r['released'] > 0) {
            $this->line("{$r['released']} ".($r['released'] === 1 ? 'document waiting' : 'documents waiting').' for AI budget marked failed again, so Read with AI offers '.($r['released'] === 1 ? 'it' : 'them').'.');
        }
        if ($r['before'] !== $r['after'] && $value === 'on' && CycleRun::query()->where('run_key', $runner->keyFor('nightly'))->value('mode') === 'shadow') {
            $this->line('Tonight’s run was a trial, so the old nightly entries still to come tonight do the work; the cycle takes over from tomorrow night.');
        }

        return 0;
    }
    if ($action === 'run') {
        $kind = $value ?? 'nightly';
        if (! in_array($kind, ['nightly', 'morning', 'manual'], true)) {
            $this->error('Say nightly, morning or manual.');

            return 1;
        }
        // While the cycle is off, a run by hand is a trial: it changes nothing.
        $mode = CycleRunner::mode() === 'on' ? 'on' : 'shadow';
        $key = $kind === 'manual' ? null : $runner->keyFor($kind);
        $run = $key ? CycleRun::query()->where('run_key', $key)->first() : null;
        if ($run && $run->isFinished() && ! $this->option('force')) {
            $this->line("{$run->run_key} already ran (".(CycleRun::STATUS_WORDS[$run->status] ?? $run->status).'). Use --force to run it again under a new key.');

            return 0;
        }
        if (! $run || $run->isFinished()) {
            $run = $runner->start($kind, $run ? mb_substr($key.':'.now()->format('His'), 0, 40) : $key, mode: $mode, deadline: now()->toImmutable()->addHour());
        }
        if (! $run) {
            $this->error('Couldn’t start the run.');

            return 1;
        }
        $this->info("Running {$run->run_key} ({$run->mode}).");
        // One step job at a time, as in the worker: waits for one that's running there.
        for ($i = 0; $i < 500 && ! $run->fresh()->isFinished(); $i++) {
            try {
                $did = Cache::lock(CycleRunner::LOCK, CycleRunner::LOCK_SECONDS)->block(120, fn () => $runner->work($run->id, wait: false));
            } catch (LockTimeoutException) {
                $this->error('A step job has held the cycle for two minutes: try again shortly.');

                return 1;
            }
            if ($did === []) {
                break;
            }
        }
        $run = $run->fresh();
        foreach ($run->steps as $step) {
            $this->line(sprintf('  %-28s %-10s %s', $step->step, $step->status, $step->error ?? ''));
        }
        $this->info("{$run->run_key}: ".(CycleRun::STATUS_WORDS[$run->status] ?? $run->status).'.');

        return $run->status === 'failed' ? 1 : 0;
    }
    if ($action === 'status') {
        $this->line('Mode: '.CycleRunner::mode().'.');
        foreach (CycleRun::query()->orderByDesc('id')->limit(5)->get() as $run) {
            $this->line("{$run->run_key} ({$run->mode}): ".(CycleRun::STATUS_WORDS[$run->status] ?? $run->status)
                .($run->finished_at ? ', ended '.$run->finished_at->tz(CycleRunner::TZ)->format('j M H:i') : ''));
            foreach (CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->get() as $step) {
                $this->line(sprintf('  %-28s %-10s %s', $step->step, $step->status, $step->error ?? ''));
            }
        }

        return 0;
    }
    $this->error('Say tick, run, status or mode.');

    return 1;
})->purpose('The nightly cycle: tick (every minute), run a run now, show the run log, or switch it off, to a trial (shadow) or on');

// The nightly work runs on London time, so it stays in the same quiet hours (and in order) all year round. The nightly
// entries the cycle does in its own steps once it's on are skipped then, so nothing runs twice and nothing is dropped:
// off and shadow run them as before (a shadow run only looks), on runs them in the cycle. Each stays a command you can run.
// Skipped only when tonight's nightly run is a real one: switched on after a trial run started tonight, the old entries
// still do tonight's work, and a step that changes records runs only while the flag is on (CycleRunner::doesNightlyWork()).
$cycleOn = fn () => CycleRunner::doesNightlyWork();
Schedule::command('ledger:sync-calendar')->hourly()->withoutOverlapping();
Schedule::command('ledger:verify-audit')->dailyAt('03:10')->timezone('Europe/London')->skip($cycleOn);
Schedule::command('ledger:fetch-email')->everyFiveMinutes()->withoutOverlapping();
// Starling statements, every morning (if connected). Imported by the system, not by you, so its history says so.
// Then the cycle looks at what came in (an import run), when it's on or in trial.
Schedule::call(function () {
    if (StarlingSync::connected()) {
        $results = AuditContext::with('system', fn () => app(StarlingSync::class)->sync(), 'schedule');
        if (collect($results)->contains(fn ($r) => ($r['ok'] ?? false) === true)) {
            app(CycleRunner::class)->request('import', 'import:starling:'.now()->format('Y-m-d-Hi'));
        }
    }
})->dailyAt('06:41')->timezone('Europe/London')->name('starling-import')->withoutOverlapping();
// What needs you, on Telegram every Monday morning; overdue invoices each weekday.
Schedule::command('ledger:telegram-digest')->weeklyOn(1, '08:47')->timezone('Europe/London');
Schedule::command('ledger:invoice-nudges')->weekdays()->at('09:13')->timezone('Europe/London');
// The morning after a job with no invoice finishes, after the night's work and the morning report: "Acme Awards
// finished yesterday. Invoice it?", once per job (app/Console/Commands/JobFinishedNudges.php).
Schedule::command('ledger:job-finished')->dailyAt('08:37')->timezone('Europe/London')->withoutOverlapping();
// The job finder and the double-check of AI findings, every night; scans get a few second reads a night.
Schedule::command('ledger:transfers')->dailyAt('04:07')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
Schedule::command('ledger:receipts')->dailyAt('04:09')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
Schedule::command('ledger:categorise')->dailyAt('04:12')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
Schedule::command('ledger:find-jobs')->dailyAt('04:17')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
Schedule::command('ledger:opportunities')->dailyAt('04:27')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('ledger:recheck')->dailyAt('04:47')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
Schedule::command('ledger:findings')->dailyAt('05:07')->timezone('Europe/London')->withoutOverlapping()->skip($cycleOn);
// Fill in the intelligence cycle's new columns for rows kept before them, a little each hour, until each backfill is
// done (settings intel_backfills_done). Never inside a migration. At most 20 seconds a run: the scheduler runs its
// work one after another, so a long run would hold up the queue worker below.
foreach (array_keys(IntelBackfill::backfills()) as $backfill) {
    Schedule::command('ledger:intel-backfill '.$backfill.' --seconds=20')->hourlyAt(37)->withoutOverlapping()->skip(fn () => IntelBackfill::isDone($backfill));
}
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
})->weeklyOn(0, '05:23')->timezone('Europe/London')->name('gmail-rescan');
// Catch-up for the Gmail "✓ Invoiced" label (POs linked after invoicing, or a run that failed).
Schedule::job(new SyncGmailLabels)->hourly()->name('gmail-labels')->withoutOverlapping();
// Failed background work is kept 90 days (the to-do list's health check looks at the last 7).
Schedule::command('queue:prune-failed --hours=2160')->weeklyOn(0, '05:43')->timezone('Europe/London');
// The cycle's tick, before the worker: it starts a run when one is due and queues its next step for the worker below.
// It returns in under a second and never runs a step itself. Nothing while intel_cycle is off.
Schedule::command('ledger:cycle tick')->everyMinute()->skip(fn () => CycleRunner::mode() === 'off');
// Shared hosting has no long-running worker: process queued work (Telegram, imports, document reading) every minute.
// Photos from the phone are read on their own queue ('reads'), after everything on 'default', so a button pressed in
// Telegram never waits behind a stack of receipts.
Schedule::command('queue:work --queue=default,reads --stop-when-empty --max-time=240 --tries=1')->everyMinute()->withoutOverlapping();

// Dead-man's switch: if these pings stop, the monitoring service alerts you that cron has died.
Schedule::call(function () {
    if ($url = config('services.heartbeat.url')) {
        Http::timeout(10)->get($url);
    }
})->everyFiveMinutes()->name('heartbeat');
