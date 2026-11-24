<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly cycle's run log (docs/intelligence-cycle.md 3.2 and 6.4, M5): one row per run (nightly, morning, after an
 * import, or "Check now") and one per step of it, with what each did, how far it got (its cursor, to carry on from),
 * how much it covered and why it stopped. About twenty rows a night, kept for good.
 *
 * Dated after M6's migrations (the plan named it 2026_12_12), so a fresh install runs the migrations in the order they
 * shipped. New tables only: nothing existing changes. Lists are text holding canonical JSON from PHP (section 3.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 40)->unique(); // nightly:2026-12-14, morning:…, import:batch:91, manual:<ulid>
            $table->string('kind', 10);              // nightly, morning, import, manual
            $table->string('mode', 8);               // on, shadow
            $table->string('status', 10);            // running, ok, partial, failed, abandoned
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('ai_budget_micro')->default(0);
            $table->unsignedBigInteger('ai_spent_micro')->default(0);
            $table->text('counts')->nullable();
            $table->text('report')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->char('digest', 64)->nullable();
            $table->unsignedBigInteger('seal_audit_id')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::create('cycle_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_run_id')->constrained('cycle_runs')->restrictOnDelete();
            $table->string('step', 40);   // relate.transfers
            $table->string('stage', 10);
            $table->unsignedTinyInteger('seq');
            $table->string('status', 10); // pending, running, ok, more, budget, failed, skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('cursor')->nullable();
            $table->text('counts')->nullable();
            $table->text('scope')->nullable();
            $table->string('coverage', 10)->nullable();
            $table->unsignedSmallInteger('detector_version')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['cycle_run_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_run_steps');
        Schema::dropIfExists('cycle_runs');
    }
};
