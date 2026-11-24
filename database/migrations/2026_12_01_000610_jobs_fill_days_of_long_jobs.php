<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jobs longer than two months (a theatre season) got only their first 62 days when job days were added, so their
 * later dates didn't count as working days, and the first change to their days would have cut their end date short.
 * A job whose days were all made from its dates, and stop before its last date, gets the rest of them (up to the
 * 400 days a job can now cover). Days you added, changed or linked from the calendar are left as they are.
 */
return new class extends Migration
{
    private const MAX_DAYS = 400;

    public function up(): void
    {
        $now = now();
        DB::table('work_jobs')->select(['id', 'starts_on', 'ends_on'])->orderBy('id')->chunkById(200, function ($jobs) use ($now) {
            foreach ($jobs as $j) {
                $end = substr((string) ($j->ends_on ?? $j->starts_on), 0, 10);
                $days = DB::table('job_days')->where('work_job_id', $j->id);
                $max = (clone $days)->max('on_date');
                if (! $max || substr((string) $max, 0, 10) >= $end) {
                    continue;
                }
                if ((clone $days)->distinct()->pluck('source')->all() !== ['range']) {
                    continue;
                }
                $count = (clone $days)->count();
                $rows = [];
                for ($d = CarbonImmutable::parse(substr((string) $max, 0, 10))->addDay(); $d->format('Y-m-d') <= $end && $count < self::MAX_DAYS; $d = $d->addDay(), $count++) {
                    $rows[] = ['work_job_id' => $j->id, 'on_date' => $d->format('Y-m-d'), 'kind' => 'work', 'source' => 'range', 'created_at' => $now, 'updated_at' => $now];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('job_days')->insertOrIgnore($chunk);
                }
            }
        });
    }

    public function down(): void
    {
        // The days added are real days of those jobs: nothing to undo.
    }
};
