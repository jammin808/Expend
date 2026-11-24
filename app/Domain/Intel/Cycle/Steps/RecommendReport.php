<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Telegram\Telegram;
use App\Models\CycleRun;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * recommend.report (docs/intelligence-cycle.md 7.1): the morning report. It waits for intel_report_time (07:30 London
 * by default), builds the report (MorningReport) and keeps it with the run, for /intel and the dashboard. It's sent to
 * Telegram only when anything happened: a quiet night is one line on /intel, and no message. A shadow run builds and
 * keeps it, and sends nothing. When Telegram is paired but doesn't take it (busy, down), it's tried again every
 * RETRY_MINUTES, up to SEND_TRIES times, and then counts as failed (the report is still on /intel).
 */
final class RecommendReport implements CycleStep
{
    public const KEY = 'recommend.report';

    /** Times the report is offered to Telegram before the step counts as failed. */
    public const SEND_TRIES = 6;

    public const RETRY_MINUTES = 2;

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Recommend;
    }

    /** It keeps the report with its run (the run log) and sends it; a shadow run only keeps it. */
    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $run = CycleRun::query()->find($ctx->runId);
        if (! $run) {
            return StepOutcome::skipped('no_run', 'Only a cycle run makes a report.');
        }
        if (! ($ctx->cursor['now'] ?? false) && $run->kind === 'morning' && CarbonImmutable::now()->lt($at = self::time())) {
            return StepOutcome::waitUntil($at, [], ['waiting_until' => $at->toIso8601String()]);
        }
        $report = MorningReport::build($run);
        $sent = false;
        $send = ! $ctx->shadow && ! $report['quiet'];
        if ($send) {
            $sent = Telegram::notify(MorningReport::telegram($report, (string) (Setting::get('intel_report_detail') ?? 'full')));
        }
        $run->forceFill(['report' => $report] + ($sent ? ['reported_at' => now()] : []))->save();
        $counts = ['quiet' => $report['quiet'], 'sent' => $sent, 'found' => (int) ($report['found']['count'] ?? 0), 'dealt' => (int) ($report['dealt']['count'] ?? 0),
            'needs' => (int) ($report['needs']['total'] ?? 0)];

        if ($send && ! $sent && Setting::get('telegram_chat_id') && Telegram::token()) {
            // Paired, and Telegram didn't take it: again in a few minutes.
            $tries = (int) ($ctx->cursor['tries'] ?? 0) + 1;
            if ($tries >= self::SEND_TRIES) {
                return StepOutcome::failed('Telegram didn’t take the morning report ('.$tries.' tries). It’s on the Overnight page.', $counts + ['tries' => $tries]);
            }

            return StepOutcome::waitUntil(CarbonImmutable::now()->addMinutes(self::RETRY_MINUTES), ['tries' => $tries], $counts + ['tries' => $tries]);
        }

        return StepOutcome::ok($counts);
    }

    /** Today's report time, in London (intel_report_time, 07:30 when it isn't a time). */
    public static function time(): CarbonImmutable
    {
        $time = (string) (Setting::get('intel_report_time') ?? '07:30');
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $m = [null, '07', '30'];
        }

        return CarbonImmutable::now('Europe/London')->setTime((int) $m[1], (int) $m[2]);
    }
}
