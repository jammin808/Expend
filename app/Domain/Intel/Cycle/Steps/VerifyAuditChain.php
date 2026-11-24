<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\TodoList;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * verify.audit_chain (docs/intelligence-cycle.md 4.3): checks the audit log hasn't been altered, exactly as
 * ledger:verify-audit does (check(), which the command also calls). A break goes first on the to-do list
 * (health.audit_chain, critical) and to Telegram at once, once for each break. In a shadow run it only looks: the
 * result is kept in the run log, nothing else.
 */
final class VerifyAuditChain implements CycleStep
{
    public const KEY = 'verify.audit_chain';

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Verify;
    }

    /** It keeps its result for the to-do list and may send the alarm, but a shadow run still checks (only looking). */
    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $result = $ctx->shadow ? AuditLog::verify() + ['alerted' => false] : self::check();

        return StepOutcome::ok(['checked' => $result['checked'], 'ok' => $result['ok'], 'broken_at' => $result['broken_at'], 'alerted' => $result['alerted']]);
    }

    /**
     * Check the chain and keep the result for the to-do list (TodoList::health() puts a break first until a check
     * finds it intact). A break is told on Telegram at once, once for each break: the same break found again the next
     * night is already on the to-do list. A message that couldn't be sent (Telegram not paired) is tried again at the
     * next check.
     *
     * @return array{ok:bool, checked:int, broken_at:?int, alerted:bool}
     */
    public static function check(): array
    {
        // Safe while events are being written: AuditLog::verify() checks the events against the chain's head as it read it.
        $result = AuditLog::verify();
        $before = Setting::get(TodoList::AUDIT_RESULT);
        $kept = ['ok' => $result['ok'], 'checked' => $result['checked'], 'broken_at' => $result['broken_at'], 'at' => now()->toIso8601String()];
        if ($result['ok']) {
            Setting::put(TodoList::AUDIT_RESULT, $kept);

            return $result + ['alerted' => false];
        }
        Log::critical('Audit log chain is broken', $result);
        $known = is_array($before) && ($before['ok'] ?? true) === false && ($before['broken_at'] ?? null) === $result['broken_at'];
        $alerted = false;
        if ($known && ! empty($before['alerted_at'])) {
            $kept['alerted_at'] = $before['alerted_at'];
        } else {
            $alerted = Telegram::auditAlarm($result);
            $kept['alerted_at'] = $alerted ? now()->toIso8601String() : null;
        }
        Setting::put(TodoList::AUDIT_RESULT, $kept);
        TodoList::forget();

        return $result + ['alerted' => $alerted];
    }
}
