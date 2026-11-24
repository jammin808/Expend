<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Ai\AiUsage;
use App\Domain\Banks\StarlingSync;
use App\Domain\Gmail\GmailClient;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Todo\TodoList;
use App\Jobs\ReadDocument;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * acquire.health (docs/intelligence-cycle.md 4.1): how fresh the sources are and what has stopped working, only
 * looking: the last Starling import, when the calendar was last read, the Gmail connection, background work that
 * failed in the last week, reads waiting for AI budget, the audit log's last check and this month's AI spend. The
 * morning report's Health section is made from it; the to-do list's health group turns the same into findings.
 */
final class AcquireHealth implements CycleStep
{
    public const KEY = 'acquire.health';

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Acquire;
    }

    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        return StepOutcome::ok(self::snapshot());
    }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $out = [];

        $starling = Setting::get('starling_last_result');
        if (StarlingSync::connected()) {
            $failing = collect(is_array($starling) ? ($starling['results'] ?? []) : [])->filter(fn ($r) => ($r['ok'] ?? true) === false)->count();
            $out['starling'] = ['at' => is_array($starling) ? ($starling['at'] ?? null) : null, 'failing' => $failing];
        }

        if (Setting::get('calendar_ics_url')) {
            $synced = Setting::get('calendar_synced_at');
            $at = is_string($synced) && $synced !== '' ? Carbon::parse($synced, 'Europe/London') : null;
            $out['calendar'] = ['at' => $at?->toIso8601String(), 'stale' => $at === null || $at->lt(now()->subHours(TodoList::CALENDAR_STALE_HOURS))];
        }

        if (GmailClient::configured()) {
            $gmail = Setting::get('gmail_last_result');
            $out['gmail'] = ['connected' => GmailClient::connected(), 'ok' => ! is_array($gmail) || ($gmail['ok'] ?? true) !== false];
        }

        $out['failed_jobs'] = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(TodoList::FAILED_JOBS_DAYS))->count();
        $out['reads_waiting'] = Document::query()->where(fn ($q) => $q->where('status', ReadDocument::WAITING)
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('error', 'like', '%spending cap%')))->count()
            + GmailMessage::query()->where('status', 'failed')->where('error', 'like', '%spending cap%')->count();

        $audit = Setting::get(TodoList::AUDIT_RESULT);
        if (is_array($audit)) {
            $out['audit'] = ['ok' => (bool) ($audit['ok'] ?? true), 'checked' => (int) ($audit['checked'] ?? 0), 'at' => $audit['at'] ?? null];
        }

        $usage = AiUsage::thisMonth();
        $out['ai'] = ['usd' => round((float) $usage['usd'], 2), 'cap' => AiUsage::cap(), 'cycle_usd' => AiUsage::cycleSpent()];

        return $out;
    }
}
