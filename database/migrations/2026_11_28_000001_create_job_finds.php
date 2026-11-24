<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the search for a job's related items found: what it linked (so you can see and undo it), what it thinks may
 * belong (for you to link or reject), and what you said doesn't belong (so it isn't suggested again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_finds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->string('type', 20); // calendar, document, payment, income, travel, invoice, email
            $table->string('ref', 80);
            $table->string('status', 12); // linked, possible, rejected
            $table->string('label', 255);
            $table->string('detail', 500)->nullable();
            $table->date('on_date')->nullable();
            $table->bigInteger('amount_pence')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['work_job_id', 'type', 'ref']);
            $table->index(['work_job_id', 'status']);
        });
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->timestamp('gathered_at')->nullable();
            $table->json('gather_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_finds');
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn(['gathered_at', 'gather_state']);
        });
    }
};
