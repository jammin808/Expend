<?php

namespace App\Console\Commands;

use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\TodoActions;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The morning after a job finishes, on Telegram: "Arena load-in: last day was yesterday. All done? Marking it done
 * gets its invoice ready to check." with [Done] [Not yet] / [Cancelled] [Open the job] (TodoActions' job_done,
 * job_not_yet and job_cancel, bound to the job as shown). A job already marked done reads "its invoice is ready to
 * check" with a link to it, or, with no draft and nothing billed, "Draft its invoice?" with [Draft it] (job_invoice).
 * Unbilled work is where money is lost, and the to-do item alone is easy to put off.
 *
 * When a job finishes (Jobs\JobStage::finishAt): the end of its last day, in London time. It's asked about the morning
 * after, for jobs that finished in the last 60 days (JobStage::DONE_WINDOW_DAYS, the owner's choice at release), not
 * cancelled or billed, and not set aside ("job_done:{id}" or "invoice:{id}" snoozed or marked not relevant).
 *
 * Once per job and finish: the jobs asked about are kept in settings job_finished_told as {"{job}@{finish}": the day
 * it was sent}, for KEEP_DAYS, so a finish that moves later (days added) asks again. When a "Not finished yet" snooze
 * ends, the job is asked about once more that morning (kept as "{job}@{finish}#{the day the snooze ended}"). Kept
 * before finishes were (by job id alone), a job counts as asked about for any finish up to that day.
 *
 * Three or more jobs on one morning go as one message, a [Done: <job>] button for each. Not in the audit log: a
 * message isn't a change to your records. Only the jobs' names (cleaned of postcodes and long references, as the
 * cycle's messages are: MorningReport::clean) and links are sent: no amounts, clients' details or account numbers.
 */
class JobFinishedNudges extends Command
{
    protected $signature = 'ledger:job-finished {--dry-run : Say what would be sent, send nothing}';

    protected $description = 'The morning after a job finishes, ask on Telegram whether it is done (and get its invoice ready)';

    /** The setting that keeps the jobs asked about, {"{job}@{finish}": 'Y-m-d' sent}. */
    public const TOLD = 'job_finished_told';

    /** How long a job asked about is remembered: far longer than the 60 days after its finish it can be asked. */
    public const KEEP_DAYS = 400;

    /** Three or more jobs on one morning are asked about in one message. */
    public const COMBINE_FROM = 3;

    public const TZ = 'Europe/London';

    public function handle(): int
    {
        $chat = Setting::get('telegram_chat_id');
        if (! $chat || ! Telegram::token()) {
            $this->line('Telegram isn’t paired.');

            return self::SUCCESS;
        }
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $due = self::due($today);
        if ($due->isEmpty()) {
            $this->info('Asked about 0 finished jobs.');

            return self::SUCCESS;
        }
        $messages = $due->count() >= self::COMBINE_FROM ? [self::combined($due, $today)] : $due->map(fn (array $d) => self::message($d, $today))->all();
        $sent = 0;
        foreach ($messages as $m) {
            if ($this->option('dry-run')) {
                $this->line($m['text']);

                continue;
            }
            if (! self::send($chat, $m['text'], $m['rows'], $m['fallback'])) {
                $this->warn('Telegram didn’t take the message: it’s tried again tomorrow.');

                continue;
            }
            foreach ($m['told'] as $key) {
                self::remember($key, $today);
            }
            $sent += count($m['told']);
        }
        $this->info("Asked about {$sent} finished ".($sent === 1 ? 'job' : 'jobs').'.');

        return self::SUCCESS;
    }

    /**
     * The jobs to ask about this morning, each with what to ask: 'done' (still booked: is it done?), 'draft' (done,
     * its invoice ready to check) or 'offer' (done, nothing drafted or billed: draft its invoice?), the key it's kept
     * under once asked, and its draft.
     *
     * @return Collection<int, array{job:WorkJob, ask:string, key:string, draft:?int}>
     */
    public static function due(CarbonImmutable $today): Collection
    {
        $today = $today->setTimezone(self::TZ)->startOfDay();
        $jobs = WorkJob::query()->with('days')->whereIn('status', ['booked', 'done'])
            ->whereDate('ends_on', '>=', $today->subDays(JobStage::DONE_WINDOW_DAYS + 1)->format('Y-m-d'))
            ->whereDate('ends_on', '<', $today->format('Y-m-d'))->orderBy('ends_on')->orderBy('id')->get();
        if ($jobs->isEmpty()) {
            return collect();
        }
        $stages = JobStage::of($jobs);
        $told = self::told();
        $drafts = Invoice::query()->whereIn('work_job_id', $jobs->pluck('id'))->where('status', 'draft')->orderBy('id')->pluck('id', 'work_job_id');
        $ended = self::notYetEnded($jobs, $today);

        return $jobs->map(function (WorkJob $job) use ($stages, $told, $drafts, $ended, $today) {
            $finish = JobStage::finishAt($job);
            // Asked the morning after: a finish today (or an overnight one ending this morning) waits for tomorrow.
            if ($finish->setTimezone(self::TZ)->gte($today) || $finish->lte($today->subDays(JobStage::DONE_WINDOW_DAYS))) {
                return null;
            }
            $key = $job->id.'@'.$finish->setTimezone(self::TZ)->format('Y-m-d\TH:i');
            $again = isset($ended[$job->id]) ? $key.'#'.$ended[$job->id] : null;
            if (self::wasTold($told, $job->id, $key, $finish) && ($again === null || isset($told[$again]))) {
                return null;
            }
            if ($job->status === 'done' && Dismissals::hiddenNow('invoice:'.$job->id) !== null) {
                return null;
            }
            $draft = isset($drafts[$job->id]) ? (int) $drafts[$job->id] : null;
            $ask = match (true) {
                $job->status === 'booked' => ($stages[$job->id]['done_due'] ?? false) ? 'done' : null,
                $draft !== null => 'draft',
                JobStatus::offersDraft($job) => 'offer',
                default => null,
            };

            return $ask === null ? null : ['job' => $job, 'ask' => $ask, 'key' => $again !== null && self::wasTold($told, $job->id, $key, $finish) ? $again : $key, 'draft' => $draft];
        })->filter()->values();
    }

    /**
     * One job's message: its words, its buttons (rows of [label, code, id] or links), and the plain words with a link
     * to send if Telegram won't take buttons.
     *
     * @param  array{job:WorkJob, ask:string, key:string, draft:?int}  $d
     * @return array{text:string, rows:list<list<array>>, fallback:string, told:list<string>}
     */
    public static function message(array $d, CarbonImmutable $today): array
    {
        $job = $d['job'];
        $name = self::name($job);
        $open = ['url' => url('/jobs/'.$job->id), 'text' => 'Open the job'];
        [$text, $rows, $link] = match ($d['ask']) {
            'draft' => [$name.': its invoice is ready to check.', [[['url' => url('/invoices/'.$d['draft']), 'text' => 'Check the invoice']]], url('/invoices/'.$d['draft'])],
            'offer' => [$name.' is done. Draft its invoice?', [[['Draft it', 'job_invoice', (string) $job->id], $open]], url('/jobs/'.$job->id)],
            default => [$name.': last day was '.self::when($job, $today).'. All done? Marking it done gets its invoice ready to check.', [
                [['Done', 'job_done', (string) $job->id], ['Not yet', 'job_not_yet', (string) $job->id]],
                [['Cancelled', 'job_cancel', (string) $job->id], $open],
            ], url('/jobs/'.$job->id)],
        };

        return ['text' => MorningReport::clean($text), 'rows' => $rows, 'fallback' => MorningReport::clean($text)."\n".$link, 'told' => [$d['key']]];
    }

    /**
     * Three or more jobs as one message: their names, a [Done: <job>] or [Draft it: <job>] button each, and a link to
     * the to-do list for the rest (Not yet, Cancelled).
     *
     * @param  Collection<int, array{job:WorkJob, ask:string, key:string, draft:?int}>  $due
     * @return array{text:string, rows:list<list<array>>, fallback:string, told:list<string>}
     */
    public static function combined(Collection $due, CarbonImmutable $today): array
    {
        $lines = $due->map(fn (array $d) => '• '.self::name($d['job']).match ($d['ask']) {
            'draft' => ': its invoice is ready to check',
            'offer' => ': done, draft its invoice?',
            default => ': last day was '.self::when($d['job'], $today),
        })->all();
        $rows = $due->map(fn (array $d) => [match ($d['ask']) {
            'draft' => ['url' => url('/invoices/'.$d['draft']), 'text' => 'Check: '.mb_substr(self::name($d['job']), 0, 30)],
            'offer' => ['Draft it: '.mb_substr(self::name($d['job']), 0, 30), 'job_invoice', (string) $d['job']->id],
            default => ['Done: '.mb_substr(self::name($d['job']), 0, 30), 'job_done', (string) $d['job']->id],
        }])->values()->all();
        $rows[] = [['url' => url('/todo'), 'text' => 'Open the to-do list']];
        $text = MorningReport::clean($due->count().' jobs have finished. All done? Marking a job done gets its invoice ready to check.'."\n".implode("\n", $lines));

        return ['text' => $text, 'rows' => $rows, 'fallback' => $text."\n".url('/todo'), 'told' => $due->pluck('key')->all()];
    }

    /** @return array<string, string> the jobs asked about: {"{job}@{finish}" (or a job id, kept before): 'Y-m-d' sent} */
    public static function told(): array
    {
        $told = Setting::get(self::TOLD, []);

        return is_array($told) ? $told : [];
    }

    /** Whether this job was asked about for this finish (or, kept before finishes were, on or after the day it finished). */
    private static function wasTold(array $told, int $jobId, string $key, CarbonImmutable $finish): bool
    {
        if (isset($told[$key])) {
            return true;
        }
        $old = $told[(string) $jobId] ?? null;

        return is_string($old) && $old >= $finish->setTimezone(self::TZ)->format('Y-m-d');
    }

    /**
     * For each of these jobs whose "Not finished yet" snooze ended since yesterday's run (by this morning): the day it
     * ended, so it's asked about once more.
     *
     * @return array<int, string> by job id
     */
    private static function notYetEnded(Collection $jobs, CarbonImmutable $today): array
    {
        // "Not finished yet", with or without your own words after it (the to-do page's snooze keeps them: Dismissals::note).
        $rows = TodoDismissal::query()->whereIn('key', $jobs->map(fn (WorkJob $j) => 'job_done:'.$j->id)->all())
            ->where(fn ($q) => $q->where('note', TodoActions::NOT_FINISHED_NOTE)->orWhere('note', 'like', addcslashes(TodoActions::NOT_FINISHED_NOTE, '%_\\').': %'))
            ->whereNotNull('until')->where('until', '<=', now())->where('until', '>', $today->subDay()->utc())->get(['key', 'until']);

        return $rows->mapWithKeys(fn (TodoDismissal $d) => [(int) substr($d->key, 9) => $d->until->setTimezone(self::TZ)->format('Y-m-d')])->all();
    }

    /** "yesterday", or "Wed 2 Dec" when it's caught up later. */
    private static function when(WorkJob $job, CarbonImmutable $today): string
    {
        $last = CarbonImmutable::parse($job->ends_on->format('Y-m-d'), self::TZ);

        return $last->equalTo($today->subDay()) ? 'yesterday' : $last->format('D j M');
    }

    private static function name(WorkJob $job): string
    {
        return MorningReport::clean(mb_substr(trim($job->title), 0, 120));
    }

    /** Keep that this was asked about today, forgetting what was asked about more than KEEP_DAYS ago. */
    private static function remember(string $key, CarbonImmutable $today): void
    {
        $since = $today->subDays(self::KEEP_DAYS)->format('Y-m-d');
        $told = array_filter(self::told(), fn ($day) => is_string($day) && $day >= $since);
        $told[$key] = $today->format('Y-m-d');
        Setting::put(self::TOLD, $told);
    }

    /**
     * With its buttons (presses are let through first: Telegram::allowButtons); as plain text with the link if Telegram
     * won't take them.
     *
     * @param  list<list<array>>  $rows
     */
    private static function send(string|int $chat, string $text, array $rows, string $fallback): bool
    {
        try {
            $keyboard = Telegram::keyboard($rows);
            if (collect($keyboard)->flatten(1)->contains(fn ($b) => isset($b['callback_data']))) {
                Telegram::allowButtons();
            }
            $ok = (bool) (Telegram::api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true]
                + ($keyboard !== [] ? ['reply_markup' => ['inline_keyboard' => $keyboard]] : []))['ok'] ?? false);

            return $ok || (bool) (Telegram::api('sendMessage', ['chat_id' => $chat, 'text' => $fallback, 'disable_web_page_preview' => true])['ok'] ?? false);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
