<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A double-check of a calendar link is about the entry being on its job. Until now the check stayed when the link was
 * removed ("Undo not a job", or a duplicate link tidied away) or no longer put the entry on a job ("Not this job",
 * "Not a job"), so a failed one could be listed with nothing left to fix. Those leftover checks are removed. (They're
 * only the result of a check, made again from the calendar whenever a link to a job is checked.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $onJob = DB::table('calendar_job_links')->whereNotNull('work_job_id')->where('not_job', false)->select('id');
        DB::table('verifications')->where('subject_type', 'calendar_link')->whereNotIn('subject_id', $onJob)->delete();
    }

    public function down(): void
    {
        // Nothing to put back: the checks removed were about links that no longer exist or no longer link a job.
    }
};
