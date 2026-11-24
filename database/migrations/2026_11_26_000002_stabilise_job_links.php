<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each change is skipped when it's already there: MariaDB keeps column changes made before a failed run.
        // A calendar entry's key no longer changes when it's moved: UID for a one-off entry, UID + RECURRENCE-ID
        // for an occurrence of a repeating one.
        if (! Schema::hasColumn('calendar_events', 'recurrence_id')) {
            Schema::table('calendar_events', fn (Blueprint $table) => $table->string('recurrence_id', 19)->nullable()->after('uid'));
        }
        if (! Schema::hasIndex('calendar_events', ['uid'])) {
            Schema::table('calendar_events', fn (Blueprint $table) => $table->index('uid'));
        }
        // "Not a job" and "not this job" are kept apart: an unlinked entry can still be linked to another job.
        if (! Schema::hasColumn('calendar_job_links', 'not_job')) {
            Schema::table('calendar_job_links', fn (Blueprint $table) => $table->boolean('not_job')->default(false)->after('work_job_id'));
        }
        if (! Schema::hasColumn('calendar_job_links', 'rejected')) {
            Schema::table('calendar_job_links', fn (Blueprint $table) => $table->json('rejected')->nullable()->after('not_job'));
        }
        if (! Schema::hasColumn('documents', 'job_rejected')) {
            Schema::table('documents', fn (Blueprint $table) => $table->json('job_rejected')->nullable()->after('job_linked_by'));
        }
        // Possible jobs keep their id between runs, keyed by their sources.
        if (! Schema::hasColumn('job_candidates', 'fingerprint')) {
            Schema::table('job_candidates', fn (Blueprint $table) => $table->char('fingerprint', 40)->nullable()->after('status')->index());
        }

        // Your links first, then the newest, so when two old links now point at the same entry, the one that gets
        // its new key is your decision. The other keeps its old key, and the calendar sync sorts it out (see
        // CalendarSync::repairKeys) instead of the unique key stopping the upgrade.
        $links = DB::table('calendar_job_links')->get()
            ->sortBy(fn ($l) => [$l->linked_by === 'you' ? 0 : 1, -strtotime((string) $l->updated_at), $l->id])->values();
        foreach ($links as $link) {
            $count = DB::table('calendar_events')->where('uid', $link->event_uid)->count();
            $key = sha1($link->event_uid.'|'.($count > 1 ? $link->event_starts_at : ''));
            $taken = DB::table('calendar_job_links')->where('event_key', $key)->where('id', '!=', $link->id)->exists();
            DB::table('calendar_job_links')->where('id', $link->id)->update([
                'event_key' => $taken ? $link->event_key : $key, 'not_job' => $link->work_job_id === null,
            ]);
        }
        DB::table('job_candidates')->where('status', 'open')->whereNull('edited_at')->delete(); // rebuilt with fingerprints on the next run
    }

    public function down(): void
    {
        Schema::table('job_candidates', function (Blueprint $table) {
            $table->dropIndex(['fingerprint']);
            $table->dropColumn('fingerprint');
        });
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('job_rejected'));
        Schema::table('calendar_job_links', fn (Blueprint $table) => $table->dropColumn(['not_job', 'rejected']));
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['uid']);
            $table->dropColumn('recurrence_id');
        });
    }
};
