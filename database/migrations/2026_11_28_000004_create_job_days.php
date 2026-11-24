<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The days a job actually covers, which needn't be consecutive: a rig day, the show days, and a de-rig a few days
 * later. Existing jobs get a day for each date from their first to last (as they were treated until now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->date('on_date');
            $table->string('kind', 10)->default('work'); // work, rig, show, derig, travel
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('calendar_key', 40)->nullable();
            $table->string('source', 10)->default('range'); // range, calendar, you
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->unique(['work_job_id', 'on_date']);
            $table->index('on_date');
        });

        $now = now();
        DB::table('work_jobs')->select(['id', 'starts_on', 'ends_on'])->orderBy('id')->chunkById(200, function ($jobs) use ($now) {
            $rows = [];
            foreach ($jobs as $j) {
                $d = CarbonImmutable::parse($j->starts_on);
                $end = CarbonImmutable::parse($j->ends_on ?? $j->starts_on);
                for ($i = 0; $d->lte($end) && $i < 62; $d = $d->addDay(), $i++) {
                    $rows[] = ['work_job_id' => $j->id, 'on_date' => $d->format('Y-m-d'), 'kind' => 'work', 'source' => 'range', 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($rows) {
                DB::table('job_days')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_days');
    }
};
