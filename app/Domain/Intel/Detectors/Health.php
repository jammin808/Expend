<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Banks\StarlingSync;
use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Todo\TodoList;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Models\TodoDismissal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Expend itself not working as it should (the health.* findings, docs/intelligence-cycle.md sections 4.1 and 4.4),
 * each first on the list: the audit log altered (found by the nightly ledger:verify-audit), Starling's last import
 * failing, the calendar not refreshed for over TodoList::CALENDAR_STALE_HOURS hours, the forwarding mailbox not read
 * (its last result, settings.mailbox_last_result), background work that failed in the last
 * TodoList::FAILED_JOBS_DAYS days, and documents or booking emails not read because of the month's AI spending cap
 * (failed at the cap, or waiting for it to allow them: status waiting_budget).
 *
 * These were the to-do list's health items (M2); they're one detector of their own now, so the cycle's health step
 * can run them alone. Each comes back under the same key for as long as it lasts, so it can be snoozed but not marked
 * not relevant, except the failed work: that's kept by the newest failure, so "Not relevant" sets aside the failures
 * you've looked at, and the next one brings it back. Each check is worked out on its own: one going wrong leaves the
 * others complete. Only reads. (A cycle step failing two nights running is CycleStepFailing.)
 */
final class Health implements CoversCodes, Detector
{
    public const CODE = 'health';

    public const VERSION = 2;

    public const CODES = ['health.audit_chain', 'health.starling', 'health.calendar', 'health.mailbox', 'health.failed_jobs', 'health.ai_cap'];

    /** Where the mailbox fetch keeps how it last went: {ok: bool, at: ISO time, error?: string}. */
    public const MAILBOX_RESULT = 'mailbox_last_result';

    /** @var array<string, Coverage> */
    private array $coverage = [];

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return self::CODES;
    }

    public function coverage(): Coverage
    {
        return $this->coverage === [] ? Coverage::Failed : array_reduce($this->coverage, fn (Coverage $all, Coverage $c) => $all->and($c), Coverage::Complete);
    }

    public function coverageOf(string $code): Coverage
    {
        return $this->coverage[$code] ?? Coverage::Failed;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = [];
        $drafts = [];
        foreach (['health.audit_chain' => 'audit', 'health.starling' => 'starling', 'health.calendar' => 'calendar', 'health.mailbox' => 'mailbox',
            'health.failed_jobs' => 'failedJobs', 'health.ai_cap' => 'aiCap'] as $code => $check) {
            try {
                $found = $this->{$check}();
                if ($found !== null) {
                    $drafts[] = $found;
                }
                $this->coverage[$code] = Coverage::Complete;
            } catch (Throwable $e) {
                report($e);
                $this->coverage[$code] = Coverage::Failed;
            }
        }

        return $drafts;
    }

    private function draft(string $key, string $code, string $title, string $detail, ?string $href, ?string $subject): FindingDraft
    {
        return new FindingDraft(key: $key, code: $code, group: 'health', priority: 1, title: $title, detail: $detail, href: $href, subjectRef: $subject, direction: 'none',
            detectorVersion: self::VERSION);
    }

    private static function london(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, config('app.timezone'))->setTimezone('Europe/London');
    }

    private function audit(): ?FindingDraft
    {
        $chain = Setting::get(TodoList::AUDIT_RESULT);
        if (! is_array($chain) || ($chain['ok'] ?? true) !== false) {
            return null;
        }

        return $this->draft('health:audit', 'health.audit_chain', 'The audit log has been altered',
            'The nightly check'.(isset($chain['at']) ? ' on '.self::london($chain['at'])->format('j M Y') : '').' found that event '.($chain['broken_at'] ?? '?')
            .' doesn’t follow on from the '.($chain['checked'] ?? 0).' before it, so something changed the records outside Expend. Nothing was changed because of it. '
            .'Compare the records with a backup before you lock or file a year.', null, 'set:'.TodoList::AUDIT_RESULT);
    }

    private function starling(): ?FindingDraft
    {
        $starling = Setting::get('starling_last_result');
        if (! is_array($starling) || ! StarlingSync::connected()) {
            return null;
        }
        $failing = collect($starling['results'] ?? [])->filter(fn ($r) => ($r['ok'] ?? true) === false)->pluck('account')->filter()->unique()->values();
        if ($failing->isEmpty()) {
            return null;
        }

        return $this->draft('health:starling', 'health.starling', 'Starling import failed: '.$failing->implode(', '),
            'The automatic import'.(isset($starling['at']) ? ' on '.self::london($starling['at'])->format('j M Y, H:i') : '').' didn’t finish, so the newest payments may be missing. '
            .'Check the connection in Settings › Integrations, or import the statement yourself.', '/settings/integrations', 'set:starling_last_result');
    }

    private function calendar(): ?FindingDraft
    {
        if (! Setting::get('calendar_ics_url')) {
            return null;
        }
        $synced = Setting::get('calendar_synced_at');
        $at = is_string($synced) && $synced !== '' ? self::london($synced) : null;
        if ($at !== null && ! $at->lt(now()->subHours(TodoList::CALENDAR_STALE_HOURS))) {
            return null;
        }

        return $this->draft('health:calendar', 'health.calendar', $at ? 'Calendar not updated since '.$at->format('j M, H:i') : 'Calendar not updated yet',
            'It’s read from your calendar link every hour, and jobs are found from it. Check the link in Settings › Ledger still works: calendar apps sometimes issue a new one.',
            '/settings/ledger', 'set:calendar_synced_at');
    }

    /**
     * The forwarding mailbox couldn't be read last time it was tried (its result says so), so documents emailed in are
     * waiting there. Says nothing until the fetch keeps a result.
     */
    private function mailbox(): ?FindingDraft
    {
        $last = Setting::get(self::MAILBOX_RESULT);
        if (! is_array($last) || ($last['ok'] ?? true) !== false || ! MailboxFetcher::configured()) {
            return null;
        }

        return $this->draft('health:mailbox', 'health.mailbox', 'Forwarding mailbox not read'.(isset($last['at']) ? ' since '.self::london((string) $last['at'])->format('j M, H:i') : ''),
            'Expend couldn’t read the mailbox you forward receipts and POs to, so anything sent there is waiting. Check its details in Settings › Integrations '
            .'(a changed password is the usual cause).', '/settings/integrations', 'set:'.self::MAILBOX_RESULT);
    }

    /**
     * Failures from the last week (Expend never clears them, so older ones would stay for good), less the ones you set
     * aside: up to the newest failure of an item you marked not relevant.
     */
    private function failedJobs(): ?FindingDraft
    {
        $aside = (int) TodoDismissal::query()->whereNull('until')->where('key', 'like', 'health:failed_jobs:%')->pluck('key')
            ->map(fn (string $k) => (int) substr($k, strlen('health:failed_jobs:')))->max();
        $recent = DB::table('failed_jobs')->where('id', '>', $aside)->where('failed_at', '>=', now()->subDays(TodoList::FAILED_JOBS_DAYS));
        $failed = (clone $recent)->count();
        if ($failed === 0) {
            return null;
        }
        $last = (clone $recent)->orderByDesc('id')->first(['id', 'payload', 'failed_at']);
        $name = class_basename((string) (json_decode((string) $last->payload, true)['displayName'] ?? ''));
        $what = ['ReadDocument' => 'reading a document', 'ReadTravelEmail' => 'reading a booking email', 'ScanGmail' => 'a Gmail search', 'FindJobs' => 'the job finder',
            'SyncGmailLabels' => 'the Gmail labels', 'GatherFromGmail' => 'gathering a job’s emails', 'GatherForJob' => 'gathering a job’s records', 'HandleTelegramUpdate' => 'a Telegram message',
            'RunCycleStep' => 'a step of the nightly check'][$name] ?? ($name ?: 'a task');

        return $this->draft('health:failed_jobs:'.$last->id, 'health.failed_jobs', $failed.' background '.($failed === 1 ? 'task' : 'tasks').' failed in the last week',
            'The newest: '.$what.($last->failed_at ? ', '.self::london((string) $last->failed_at)->format('j M Y, H:i') : '').'. '
            .'On the server, php artisan queue:failed lists them and php artisan ledger:check shows the newest error. '
            .'Run one again (php artisan queue:retry and its ID) only if it’s still wanted: an old one can read a document or answer a Telegram message a second time. '
            .'Clear the rest with php artisan queue:forget and its ID, or queue:flush for all of them. '
            .'Once you’ve looked, mark this not relevant: it comes back if something else fails.', null, null);
    }

    /**
     * Documents and booking emails not read because of the AI spending cap: refused at the cap, or because the read
     * could have taken the month past it (AiUsage::guard: "nearly reached"), or waiting for the cap to allow them
     * (waiting_budget). Only the nightly cycle reads them again on its own, and only while it's on
     * (acquire.retry_reads: waiting documents, and documents and booking emails failed on the cap); otherwise each
     * needs Read with AI (or Read again for a booking email) once the cap allows.
     */
    private function aiCap(): ?FindingDraft
    {
        $failedDocs = Document::query()->where('status', 'failed')->where('error', 'like', '%spending cap%')->pluck('error');
        $waitingDocs = Document::query()->where('status', 'waiting_budget')->count();
        $failedEmails = GmailMessage::query()->where('status', 'failed')->where('error', 'like', '%spending cap%')->count();
        $waitingEmails = GmailMessage::query()->where('status', 'waiting_budget')->count();
        // The cycle's flag (CycleRunner::mode(), settings intel_cycle), read as the setting.
        $auto = Setting::get('intel_cycle') === 'on';
        // Read again on their own: what waits for budget, and (with the cycle on) what failed on the cap.
        $waiting = $waitingDocs + $waitingEmails + ($auto ? $failedDocs->count() + $failedEmails : 0);
        // Left for you to press again.
        $unread = $auto ? 0 : $failedDocs->count() + $failedEmails;
        $n = $waiting + $unread;
        if ($n === 0) {
            return null;
        }
        $nearly = $failedDocs->isNotEmpty() && $failedEmails === 0 && $failedDocs->every(fn ($e) => str_contains((string) $e, 'nearly reached'));
        $docs = $failedDocs->count() + $waitingDocs;
        $emails = $failedEmails + $waitingEmails;
        $what = implode(' and ', array_filter([$docs ? $docs.' '.($docs === 1 ? 'document' : 'documents') : null, $emails ? $emails.' booking '.($emails === 1 ? 'email' : 'emails') : null]));
        $press = $docs > 0 && $emails > 0 ? 'press Read with AI on each document, and Read again on each booking email in Stays & travel'
            : ($docs > 0 ? 'press Read with AI on '.($unread === 1 ? 'it' : 'each') : 'press Read again on '.($unread === 1 ? 'it' : 'each').' in Stays & travel');
        $href = match (true) {
            $docs === 0 => '/travel',
            $unread > 0 || ($auto && $failedDocs->isNotEmpty()) => '/documents?status=failed',
            default => '/documents?status=waiting_budget',
        };

        return $this->draft('health:ai_cap', 'health.ai_cap', $what.' not read: the AI spending cap was '.($nearly && $waitingDocs + $waitingEmails === 0 ? 'nearly reached' : 'reached'),
            implode(' ', array_filter([
                $nearly ? 'Reading '.($n === 1 ? 'it' : 'them').' could have taken this month past the cap.' : null,
                $waiting > 0 ? ($waiting === 1 ? 'One is' : $waiting.' are').' waiting and will be read on '.($waiting === 1 ? 'its' : 'their').' own once the cap allows.' : null,
                $unread > 0 ? 'Raise this month’s cap in Settings › Integrations, or wait for next month, then '.$press.'.'
                    : 'To read '.($n === 1 ? 'it' : 'them').' sooner, raise this month’s cap in Settings › Integrations.',
            ])), $href, 'set:ai_monthly_cap_usd');
    }
}
