<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submissions to HMRC that late submission points (FA 2021 Sch 24) are counted on: the yearly return and, for people
 * in Making Tax Digital, the quarterly updates (due 7 August, 7 November, 7 February and 7 May).
 *
 *  - annual: kept in step with the tax year's filing facts (deadline from the notice to file, sent = filed_on), so
 *    the date is never entered twice. Only "excused" and the note are the owner's own here.
 *  - quarterly: created with their due dates for the years that have them; the owner records the day each was sent.
 *
 * excused: HMRC (or the tribunal) accepted a reasonable excuse, so the failure earns no point and counts as on time
 * when working out when all points expire (Sch 24 paras 8(7) and 19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('tax_year', 7);
            $table->string('kind', 12); // annual | quarterly
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->date('submitted_on')->nullable();
            $table->boolean('excused')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tax_year', 'kind', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_submissions');
    }
};
