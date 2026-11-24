<?php

use App\Domain\Audit\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Not relevant" on a to-do hides its key for good. A few to-dos come back under the same key every time they apply
 * again: Gmail needing reconnecting ('gmail'), a tax return falling due or overdue ('deadline:2025-26', and its
 * ':amend' form), and transfers to confirm ('transfers'). Marking one of those not relevant also hid every later one:
 * the next Gmail disconnection, the return once it was overdue. The page now only offers Snooze for them.
 *
 * Any already marked not relevant are turned into a week's snooze from now, so they stay out of the way for a week
 * (as you asked) and then come back if they still apply. Every other dismissal is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = DB::table('todo_dismissals')->whereNull('until')
            ->where(fn ($q) => $q->whereIn('key', ['gmail', 'transfers'])->orWhere('key', 'like', 'deadline:%'));
        $keys = (clone $fixed)->orderBy('key')->pluck('key')->all();
        if ($keys === []) {
            return;
        }
        $until = now()->addDays(7);
        $fixed->update(['until' => $until, 'updated_at' => now()]);
        AuditLog::record('todo.dismissal_changed', 'Todo', ['keys' => $keys, 'until' => null], ['keys' => $keys, 'until' => $until->toDateTimeString()],
            'Marked not relevant, but these come back under the same name whenever they apply again, so they would have stayed hidden for good: snoozed for a week instead', 'system');
    }

    public function down(): void
    {
        // Nothing to undo: by the time this runs back, the snoozes may have ended and been replaced by your own.
    }
};
