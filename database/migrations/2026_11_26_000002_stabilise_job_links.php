<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A calendar entry's key no longer changes when it's moved: UID for a one-off entry, UID + RECURRENCE-ID
        // for an occurrence of a repeating one.
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('recurrence_id', 19)->nullable()->after('uid');
            $table->index('uid');
        });
        // "Not a job" and "not this job" are kept apart: an unlinked entry can still be linked to another job.
        Schema::table('calendar_job_links', function (Blueprint $table) {
            $table->boolean('not_job')->default(false)->after('work_job_id');
            $table->json('rejected')->nullable()->after('not_job');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->json('job_rejected')->nullable()->after('job_linked_by');
        });
        // Possible jobs keep their id between runs, keyed by their sources.
        Schema::table('job_candidates', function (Blueprint $table) {
            $table->char('fingerprint', 40)->nullable()->after('status')->index();
        });

        foreach (DB::table('calendar_job_links')->get() as $link) {
            $count = DB::table('calendar_events')->where('uid', $link->event_uid)->count();
            $key = sha1($link->event_uid.'|'.($count > 1 ? $link->event_starts_at : ''));
            DB::table('calendar_job_links')->where('id', $link->id)->update([
                'event_key' => $key, 'not_job' => $link->work_job_id === null,
            ]);
        }
        DB::table('job_candidates')->where('status', 'open')->whereNull('edited_at')->delete(); // rebuilt with fingerprints on the next run
    }

    public function down(): void
    {
        Schema::table('job_candidates', fn (Blueprint $table) => $table->dropColumn('fingerprint'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('job_rejected'));
        Schema::table('calendar_job_links', fn (Blueprint $table) => $table->dropColumn(['not_job', 'rejected']));
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['uid']);
            $table->dropColumn('recurrence_id');
        });
    }
};
