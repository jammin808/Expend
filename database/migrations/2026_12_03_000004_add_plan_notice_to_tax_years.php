<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date of HMRC's notice charging the late payment penalties a broken payment plan held off. Until HMRC send one,
 * the plan still holds them off (FA 2009 Sch 56 para 10(3); FA 2021 Sch 26 paras 7 and 9): they're at risk, not due.
 * With it, they're due from the notice, and the plan holds nothing off from then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->date('plan_notice_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn('plan_notice_on');
        });
    }
};
