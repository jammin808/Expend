<?php

namespace App\Console\Commands;

use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Dismissals;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The morning after a job's last day, if it has no invoice yet: "Acme Awards finished yesterday. Invoice it?" on
 * Telegram, with a button that opens the job (docs/mail-and-calendar.md section 3 idea 1, the first step). Unbilled
 * work is where money is lost, and the to-do item alone is easy to put off.
 *
 * Once per job: the jobs asked about are kept in settings job_finished_told ({job id: the day it was sent}, kept
 * for KEEP_DAYS), the way the cycle keeps the alerts it sent (intel_alerts_sent). Not in the audit log: a message
 * isn't a change to your records, and an audit event on the job would count in the morning report as a change the
 * automation made, and would be taken as what dealt with the job's "Invoice" finding when you invoice it. Not for a
 * job that's cancelled or already invoiced (an issued or paid invoice, as the to-do list counts it), nor when you've
 * snoozed or set aside its "Invoice" to-do item. A morning missed (the server was down) is caught up for a few days.
 * Only the job's name (cleaned of postcodes and long references, as the cycle's messages are: MorningReport::clean)
 * and its link are sent: no amounts, clients' details or account numbers.
 */
class JobFinishedNudges extends Command
{
    protected $signature = 'ledger:job-finished {--dry-run : Say what would be sent, send nothing}';

    protected $description = 'The morning after a job with no invoice finishes, ask on Telegram: "Acme Awards finished yesterday. Invoice it?"';

    /** The setting that keeps the jobs asked about, {job id: 'Y-m-d' sent}, so a job is only asked about once. */
    public const TOLD = 'job_finished_told';

    /** How long a job asked about is remembered: far longer than the few days after its last day it can be asked. */
    public const KEEP_DAYS = 400;

    /** Jobs that finished up to this many days ago are still asked about (a morning missed is caught up). */
    public const CATCH_UP_DAYS = 3;

    public const TZ = 'Europe/London';

    public function handle(): int
    {
        $chat = Setting::get('telegram_chat_id');
        if (! $chat || ! Telegram::token()) {
            $this->line('Telegram isn’t paired.');

            return self::SUCCESS;
        }
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $sent = 0;
        foreach (self::due($today) as $job) {
            $text = self::message($job, $today);
            if ($this->option('dry-run')) {
                $this->line($text);

                continue;
            }
            if (! self::send($chat, $text, url('/jobs/'.$job->id))) {
                $this->warn("Telegram didn’t take the message about {$job->title}: it’s tried again tomorrow.");

                continue;
            }
            self::remember($job->id, $today);
            $sent++;
        }
        $this->info("Asked about {$sent} finished ".($sent === 1 ? 'job' : 'jobs').' with no invoice.');

        return self::SUCCESS;
    }

    /**
     * The jobs to ask about this morning: last day yesterday (or up to CATCH_UP_DAYS ago), not cancelled, no issued
     * or paid invoice, never asked about before, and their "Invoice" to-do item not snoozed or set aside.
     *
     * @return Collection<int, WorkJob>
     */
    public static function due(CarbonImmutable $today): Collection
    {
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')
            ->whereDate('ends_on', '>=', $today->subDays(self::CATCH_UP_DAYS)->format('Y-m-d'))
            ->whereDate('ends_on', '<', $today->format('Y-m-d'))
            ->orderBy('ends_on')->orderBy('id')->get();
        if ($jobs->isEmpty()) {
            return $jobs;
        }
        $ids = $jobs->pluck('id');
        $invoiced = Invoice::query()->whereIn('work_job_id', $ids)->whereIn('status', ['issued', 'paid'])->distinct()->pluck('work_job_id')->flip();
        $told = self::told();

        return $jobs->reject(fn (WorkJob $j) => isset($invoiced[$j->id]) || isset($told[$j->id]) || Dismissals::hiddenNow('invoice:'.$j->id) !== null)->values();
    }

    /** "Acme Awards finished yesterday. Invoice it?" (or "finished on Sat 21 Nov" when caught up later). */
    public static function message(WorkJob $job, CarbonImmutable $today): string
    {
        $last = CarbonImmutable::parse($job->ends_on->format('Y-m-d'), self::TZ);
        $when = $last->equalTo($today->subDay()) ? 'yesterday' : 'on '.$last->format('D j M');
        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->exists();

        return MorningReport::clean(mb_substr(trim($job->title), 0, 120)." finished {$when}. Invoice it?".($draft ? ' A draft is ready to check.' : ''));
    }

    /** @return array<int|string, string> the jobs asked about: {job id: 'Y-m-d' sent} */
    public static function told(): array
    {
        $told = Setting::get(self::TOLD, []);

        return is_array($told) ? $told : [];
    }

    /** Keep that this job was asked about today, forgetting those asked about more than KEEP_DAYS ago. */
    private static function remember(int $jobId, CarbonImmutable $today): void
    {
        $since = $today->subDays(self::KEEP_DAYS)->format('Y-m-d');
        $told = array_filter(self::told(), fn ($day) => is_string($day) && $day >= $since);
        $told[(string) $jobId] = $today->format('Y-m-d');
        Setting::put(self::TOLD, $told);
    }

    /** With a button that opens the job; as plain text with the link if Telegram won't take the button. */
    private static function send(string|int $chat, string $text, string $url): bool
    {
        try {
            $ok = (bool) (Telegram::api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true,
                'reply_markup' => ['inline_keyboard' => [[['text' => 'Open the job', 'url' => $url]]]]])['ok'] ?? false);

            return $ok || (bool) (Telegram::api('sendMessage', ['chat_id' => $chat, 'text' => $text."\n".$url, 'disable_web_page_preview' => true])['ok'] ?? false);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
