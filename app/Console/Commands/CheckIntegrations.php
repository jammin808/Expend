<?php

namespace App\Console\Commands;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiUsage;
use App\Domain\Banks\StarlingSync;
use App\Domain\Documents\AiConfig;
use App\Domain\Gmail\GmailClient;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Mail\OutgoingMail;
use App\Domain\Telegram\Telegram;
use App\Mail\TestEmail;
use App\Models\Account;
use App\Models\AssistantLog;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\GmailLabel;
use App\Models\GmailMessage;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A health check for the live site, safe to run from cron: it never prints keys, tokens or chat ids.
 * It makes one tiny Claude request (a fraction of a penny) and asks Telegram about the bot and webhook.
 */
class CheckIntegrations extends Command
{
    protected $signature = 'ledger:check {--telegram-message : Also send a short test message to your paired Telegram chat}
        {--email : Also send a test email to your account address}';

    protected $description = 'Check the Claude key, Telegram bot, queue and recent errors';

    public function handle(AiClient $ai): int
    {
        $problems = 0;
        $this->line('Expense Ledger check, '.now('Europe/London')->format('j M Y H:i').' (UK time)');

        $user = User::query()->first();
        $this->line('Account: '.($user ? 'created, two-factor sign-in '.($user->hasTwoFactor() ? 'on' : 'OFF') : 'not created yet'));

        $this->newLine();
        $this->line('AI (Claude)');
        $source = config('services.anthropic.key') ? 'set on the server' : (Setting::get('anthropic_api_key') ? 'saved in Settings' : 'missing');
        $this->line("  Key: {$source}");
        $this->line('  Model: '.AiConfig::model().', effort '.AiConfig::effort().', cap $'.Setting::get('ai_monthly_cap_usd')
            .', reading documents: '.Setting::get('ai_extraction').', suggestions: '.(Setting::get('ai_suggestions') ? 'on' : 'off'));
        if (AiConfig::ready()) {
            try {
                $started = microtime(true);
                $result = $ai->send([
                    'maxTokens' => 200,
                    'messages' => [['role' => 'user', 'content' => 'This is a connection test. Reply with just the word OK.']],
                    'outputConfig' => ['effort' => 'low'],
                ]);
                $this->line(sprintf('  Test request: OK, answered "%s" by %s in %.1fs (%d in, %d out tokens)',
                    mb_substr(trim($result->text()), 0, 40), $result->model, microtime(true) - $started, $result->inputTokens, $result->outputTokens));
            } catch (Throwable $e) {
                $problems++;
                $this->line('  Test request: FAILED, '.self::clean(class_basename($e).': '.$e->getMessage()));
            }
        } else {
            $problems++;
        }
        $usage = AiUsage::thisMonth();
        $this->line(sprintf('  This month: %d requests, about $%.2f (estimate)', $usage['calls'], $usage['usd']));

        $this->newLine();
        $this->line('Telegram');
        if (! Telegram::token()) {
            $this->line('  Bot token: missing');
        } else {
            $me = Telegram::api('getMe');
            $this->line('  Bot: '.(($me['ok'] ?? false) ? '@'.$me['result']['username'].' (token accepted)' : 'FAILED, '.self::clean($me['description'] ?? 'no answer')));
            $problems += ($me['ok'] ?? false) ? 0 : 1;

            $hook = Telegram::api('getWebhookInfo')['result'] ?? [];
            $pointsHere = ($hook['url'] ?? '') === url('/hooks/telegram');
            $problems += $pointsHere ? 0 : 1;
            $this->line('  Webhook: '.($pointsHere ? 'points at this site' : 'NOT pointing at this site ('.(($hook['url'] ?? '') ?: 'none').')')
                .', waiting updates: '.($hook['pending_update_count'] ?? '?'));
            if (! empty($hook['last_error_message'])) {
                $this->line('  Last delivery error: '.self::clean($hook['last_error_message']).' at '.date('j M H:i', (int) $hook['last_error_date']).' UTC');
            }
            $paired = Setting::get('telegram_chat_id') !== null;
            $problems += $paired ? 0 : 1;
            $this->line('  Paired with your chat: '.($paired ? 'yes' : 'NO (send the bot the /start code shown in Settings)'));

            $logs = AssistantLog::query()->where('channel', 'telegram');
            $last = (clone $logs)->latest()->first();
            $this->line('  Questions answered on Telegram: '.$logs->count().($last ? ', last '.$last->created_at->timezone('Europe/London')->format('j M H:i') : ''));

            if ($paired && $this->option('telegram-message')) {
                $sent = Telegram::api('sendMessage', ['chat_id' => Setting::get('telegram_chat_id'), 'text' => 'Expense Ledger check: the server can send you messages.']);
                $this->line('  Test message: '.(($sent['ok'] ?? false) ? 'sent' : 'FAILED, '.self::clean($sent['description'] ?? 'no answer')));
                $problems += ($sent['ok'] ?? false) ? 0 : 1;
            }
        }

        $this->newLine();
        $this->line('Gmail');
        if (! GmailClient::connected()) {
            $this->line('  '.(GmailClient::configured() ? 'Google client saved, not connected yet' : 'not set up yet'));
        } else {
            try {
                $profile = GmailClient::get('profile');
                $this->line('  Connected: OK, '.($profile['emailAddress'] ?? '?').(GmailClient::canModify() ? ', can add the ✓ Invoiced label' : ', read-only (reconnect for the label)'));
                $this->line('  Emails labelled ✓ Invoiced: '.GmailLabel::query()->count());
            } catch (Throwable $e) {
                $problems++;
                $this->line('  Connected: FAILED, '.self::clean($e->getMessage()));
            }
            $read = GmailMessage::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
            $this->line('  Emails fetched: '.($read->isEmpty() ? 'none yet' : $read->map(fn ($n, $s) => "{$n} {$s}")->implode(', ')));
            $this->line('  Stays and journeys found: '.TravelItem::query()->count().', decided: '.TravelItem::query()->whereNotNull('decided_at')->count());
        }

        $this->newLine();
        $this->line('Sending email: '.(OutgoingMail::method() ?? 'from .env ('.config('mail.default').')').', from '.OutgoingMail::from());
        if ($this->option('email') && $user) {
            try {
                Mail::to($user->email)->send(new TestEmail);
                $this->line('  Test email: handed over for delivery to your account address');
            } catch (Throwable $e) {
                $problems++;
                $this->line('  Test email: FAILED, '.self::clean($e->getMessage()));
            }
        }
        $this->line('Email forwarding mailbox: '.(MailboxFetcher::configured() ? 'set' : 'not set up yet'));
        $starling = Setting::get('starling_last_result');
        $this->line('Starling automatic import: '.(StarlingSync::connected()
            ? 'connected'.($starling ? ', last run '.substr($starling['at'], 0, 16).': '.collect($starling['results'])->map(fn ($r) => $r['account'].' '.($r['ok'] ? 'OK' : 'FAILED').', '.self::clean($r['result']))->implode('; ') : ', not run yet')
            : 'not connected'));

        $failed = DB::table('failed_jobs');
        $lastFailure = (clone $failed)->latest('failed_at')->first();
        $this->line('Queue: '.DB::table('jobs')->count().' waiting, '.$failed->count().' failed'
            .($lastFailure ? ', last failure '.$lastFailure->failed_at.': '.self::clean(strtok((string) $lastFailure->exception, "\n")) : ''));

        $problems += $this->cycle();

        $this->newLine();
        $this->setup();

        $errors = self::recentLogErrors();
        $this->line('Errors logged in the last 2 days: '.($errors ? count($errors).', newest last:' : 'none'));
        foreach (array_slice($errors, -6) as $error) {
            $this->line('  '.$error);
        }

        $this->newLine();
        $this->line($problems ? "{$problems} problem(s) found." : 'All checks passed.');

        return $problems ? 1 : 0;
    }

    /**
     * The nightly cycle (docs/intelligence-cycle.md 6.4): its mode, its latest run, and each step that failed in that
     * run, with the error (cleaned of keys). A failed step, or a run that failed or was given up, is a problem.
     *
     * @return int problems found
     */
    private function cycle(): int
    {
        $run = CycleRun::query()->orderByDesc('id')->first();
        $this->line('Nightly cycle: '.CycleRunner::mode().($run
            ? ', latest run '.$run->run_key.' ('.($run->isShadow() ? 'trial' : 'real').'): '.mb_strtolower(CycleRun::STATUS_WORDS[$run->status] ?? $run->status)
                .($run->finished_at ? ', ended '.$run->finished_at->tz(CycleRunner::TZ)->format('j M H:i') : ', started '.$run->created_at?->tz(CycleRunner::TZ)->format('j M H:i'))
            : ', no runs yet'));
        if (! $run) {
            return 0;
        }
        $failed = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('status', 'failed')->orderBy('seq')->get();
        foreach ($failed as $step) {
            $this->line('  Step failed: '.$step->step.' ('.CycleRunner::stepWords($step->step).')'.($step->error ? ', '.self::clean(strtok($step->error, "\n")) : ''));
        }

        return $failed->count() + (in_array($run->status, ['failed', 'abandoned'], true) ? 1 : 0);
    }

    /** What's filled in and what isn't, so nothing is left half set up. Counts only, never contents. */
    private function setup(): void
    {
        $yes = fn ($v) => $v ? 'yes' : 'NO';
        $this->line('Setup');
        $this->line('  Business details for invoices: name '.$yes(Setting::get('business_name')).', address '.$yes(Setting::get('business_address'))
            .', email '.$yes(Setting::get('business_email')).', bank details '.$yes(Setting::get('business_bank_details')));
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled');
        $this->line('  Home postcode: '.$yes(Setting::get('home_postcode')).'; jobs with a venue postcode: '.(clone $jobs)->whereNotNull('postcode')->count().' of '.(clone $jobs)->count());
        $this->line('  Calendar feed: '.$yes(Setting::get('calendar_ics_url')).', '.CalendarEvent::query()->count().' events'
            .(Setting::get('calendar_synced_at') ? ', last synced '.Setting::get('calendar_synced_at') : ''));
        $accounts = Account::query()->withCount('transactions')->get();
        $this->line('  Accounts: '.($accounts->isEmpty() ? 'none' : $accounts->map(fn ($a) => $a->name.' ('.$a->transactions_count.' transactions'
            .(($last = BankTransaction::query()->where('account_id', $a->id)->max('posted_on')) ? ', up to '.substr((string) $last, 0, 10) : '').')')->implode(', ')));
        $this->line('  Tax years: '.(TaxYear::query()->orderBy('label')->get()->map(fn ($y) => $y->label.' '.$y->status.($y->locked_at ? ' (locked)' : ''))->implode(', ') ?: 'none'));
        $this->line('  To review in the Inbox: '.BankTransaction::query()->whereIn('review_status', ['unreviewed', 'suggested'])->count());
        $this->line('  Documents: '.Document::query()->whereNull('parent_id')->count().', reading with AI: '.Setting::get('ai_extraction'));
        $this->line('  Mileage: '.Vehicle::query()->count().' vehicles, '.MileageTrip::query()->count().' trips');

        $shared = dirname((string) realpath(storage_path()));
        $dumps = glob(($_SERVER['HOME'] ?? getenv('HOME') ?: '/nonexistent').'/ledger-backups/*') ?: [];
        $this->line('  Encrypted backups: '.(is_file($shared.'/backup_public.pem') ? 'key in place' : 'NO backup key yet')
            .'; backup files on the server: '.count($dumps).($dumps ? ', newest '.date('Y-m-d H:i', max(array_map('filemtime', $dumps))) : ''));
        $this->line('  Heartbeat monitor: '.$yes(config('services.heartbeat.url')));
    }

    /** @return list<string> */
    private static function recentLogErrors(): array
    {
        $out = [];
        foreach ([now()->subDay(), now()] as $day) {
            foreach ([storage_path('logs/laravel-'.$day->format('Y-m-d').'.log'), $day->isToday() ? storage_path('logs/laravel.log') : null] as $file) {
                if (! $file || ! is_file($file)) {
                    continue;
                }
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^\[([\d\- :]{19})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                        $out[] = $m[1].' '.self::clean($m[3]);
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Short, one line, and with anything that looks like a key or bot token removed. */
    private static function clean(string $text): string
    {
        $text = preg_replace(['/bot\d+:[A-Za-z0-9_-]+/', '/sk-ant-[A-Za-z0-9_-]+/', '/\{"(userId|exception)".*$/'], ['bot[token]', 'sk-ant-[key]', ''], $text);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 300);
    }
}
