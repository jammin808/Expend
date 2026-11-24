<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not this one" (docs/intelligence-cycle.md 3.2, M7): what you said no to, for the links that have no rejection store
 * of their own, so nothing automatic proposes it again. Narrow: one payment, pair, stay, invoice or job, and the shape
 * of what was proposed (its kind, category, business share and job) or the thing it was linked to, and the method (or
 * every automatic method). Each one is visible and can be lifted; lifting is audited.
 *
 * The rejection stores that exist already (calendar_job_links.rejected and not_job, documents.job_rejected,
 * job_finds rejected, job_candidates dismissed, settings.rule_offers_declined) stay where they are and are read
 * through Intel\Vetoes: nothing is copied here.
 *
 * Numbered after M5's migration of the same date (2026_12_16_000001), so a fresh install runs them in the order they
 * shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vetoes', function (Blueprint $table) {
            $table->id();
            // sha256 of subject, relation, object, shape and method: one row per veto, unique however MariaDB or SQLite
            // treat NULLs in a unique index.
            $table->char('veto_key', 64)->unique();
            $table->string('subject_ref', 191)->index();
            $table->string('relation', 24);
            $table->string('object_ref', 191)->nullable();
            $table->char('shape', 16)->nullable();
            // Null blocks every automatic method.
            $table->string('method', 40)->nullable();
            // block: never propose it; ask: propose it, never act on it.
            $table->string('effect', 6);
            $table->string('reason_code', 16)->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('audit_event_id')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vetoes');
    }
};
