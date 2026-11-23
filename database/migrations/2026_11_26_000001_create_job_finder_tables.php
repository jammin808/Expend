<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Possible jobs the job finder put together from calendar entries, documents, stays and journeys.
        Schema::create('job_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('open')->index(); // open | accepted | merged | dismissed
            $table->string('title', 150);
            $table->string('client_name', 120)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('po_number', 100)->nullable();
            $table->json('sources'); // [{key, type, id, label, date, quote, verified, note}]
            $table->json('reasons')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('found_by', 8)->default('rules'); // rules | ai
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 250)->nullable();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        // Calendar entries are reloaded from the feed, so their links are kept by a stable key (UID + start).
        Schema::create('calendar_job_links', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 40)->unique();
            $table->string('event_uid');
            $table->dateTime('event_starts_at');
            $table->string('summary')->nullable(); // as it was when linked, to notice later changes
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete(); // null: "not a job"
            $table->string('linked_by', 8); // you | rules | ai
            $table->string('reason', 300)->nullable();
            $table->timestamps();
            $table->index('work_job_id');
        });

        // Who linked a document or a stay to its job: "you" links are never changed by automation.
        Schema::table('documents', function (Blueprint $table) {
            $table->string('job_linked_by', 8)->nullable()->after('work_job_id');
        });
        Schema::table('travel_items', function (Blueprint $table) {
            $table->string('job_linked_by', 8)->nullable()->after('work_job_id');
        });

        // The latest double-check of each AI finding against its source.
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 24); // verified | partly | mismatch | unverifiable | source_missing | second_read_agreed | second_read_disagreed
            $table->string('method', 24); // source_text | second_read | gmail_refetch | records | calendar
            $table->json('checks');
            $table->string('summary', 300);
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
        Schema::table('travel_items', fn (Blueprint $table) => $table->dropColumn('job_linked_by'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('job_linked_by'));
        Schema::dropIfExists('calendar_job_links');
        Schema::dropIfExists('job_candidates');
    }
};
