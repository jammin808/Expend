<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days picked on the calendar (or the job page) and saved as one job, or added to one: one row per pick, keyed by the
 * pick's token (a ULID the page keeps with the picked days until a save goes through). It is the idempotency key, not
 * ledger data: a second tap, or a retry after a lost answer at a venue, finds the row and goes to the job instead of
 * making another. The pick's audit events carry the same token as their correlation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_picks', function (Blueprint $table) {
            $table->id();
            $table->char('token', 26)->unique();
            // create: a new job made from the picked days; add: days added to a job you have.
            $table->string('mode', 10);
            $table->foreignId('work_job_id')->nullable()->constrained('work_jobs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_picks');
    }
};
