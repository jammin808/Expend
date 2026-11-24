<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work you might get: availability checks, holds and pencils, offers and bookings found in emails and documents,
 * before there's a job (or a PO). Automation finds and proposes them; only you turn one into a job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            // enquiry | held | offered | confirmed | declined | lost | expired | dismissed
            $table->string('status', 12)->default('enquiry')->index();
            $table->unsignedTinyInteger('hold_rank')->nullable(); // 1st or 2nd option
            $table->string('title', 150);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name', 120)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('role', 120)->nullable();
            $table->string('venue', 200)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('dates')->nullable(); // [{date, kind: work|rig|derig|travel}]
            $table->bigInteger('rate_minor')->nullable();
            $table->char('rate_currency', 3)->nullable();
            $table->string('rate_unit', 8)->nullable(); // day | job | hour
            $table->date('reply_by')->nullable();
            $table->string('po_number', 100)->nullable();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 12); // gmail | email | telegram | upload | assistant | manual
            $table->string('found_by', 8); // rules | ai | you
            $table->unsignedTinyInteger('confidence')->default(0); // 0-100
            $table->json('evidence'); // [{type: document|calendar_event|gmail_message, id, field, value, quote, verified}]
            $table->string('thread_key', 191)->nullable()->index(); // gmail:<thread id> or subject:<hash>
            $table->string('fingerprint', 64)->unique();
            $table->json('rejected_job_ids')->nullable(); // jobs you said it isn't: never linked to them again
            $table->json('owner_fields')->nullable(); // details you set yourself: a later email never changes them
            $table->timestamp('reopened_at')->nullable(); // when you last reopened it: dates already past then don't close it again
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by', 12)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
