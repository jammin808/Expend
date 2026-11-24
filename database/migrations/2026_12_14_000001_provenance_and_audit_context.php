<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why each allocation says what it does, and where each audit event came from (docs/intelligence-cycle.md, M6).
 *
 * Allocations gain the method that proposed them (a registered name, App\Domain\Intel\Methods), its version and
 * basis, its own score, the evidence refs and runner-ups it weighed, the cycle run, and who confirmed the line (you,
 * a rule, the matcher), so accepting a suggestion can be told apart from making one.
 *
 * Audit events gain the channel (web, telegram, queue...), a correlation (a ULID shared by an action and every
 * knock-on write) and the run id. An event's hash covers them only when one of them is set (AuditLog::hashFor's v2),
 * so every event already in the log hashes exactly as before.
 *
 * Additive only: nullable columns with no default, appended (never ->after()), so MariaDB adds them in place and the
 * previous release runs on this schema unchanged. Lists are text (cast to arrays in the models), never json(). Nothing
 * is backfilled here: allocations.confirmed_by is filled by `php artisan ledger:intel-backfill confirmed_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocations', function (Blueprint $table) {
            $table->string('method', 40)->nullable();
            $table->unsignedSmallInteger('method_version')->nullable();
            // 16, not the plan's 12: its own vocabulary has 'ai_ungrounded' (13 characters).
            $table->string('basis', 16)->nullable();
            $table->smallInteger('score')->nullable();
            $table->text('evidence')->nullable();
            $table->text('alternatives')->nullable();
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->string('confirmed_by', 10)->nullable();
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('channel', 10)->nullable();
            $table->char('correlation', 26)->nullable()->index();
            $table->unsignedBigInteger('run_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['correlation']);
        });
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn(['channel', 'correlation', 'run_id']);
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropIndex(['run_id']);
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropColumn(['method', 'method_version', 'basis', 'score', 'evidence', 'alternatives', 'run_id', 'confirmed_by']);
        });
    }
};
