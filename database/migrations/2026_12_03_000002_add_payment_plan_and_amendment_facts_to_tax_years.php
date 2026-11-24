<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facts behind late payment penalties that only you (and HMRC's letters) can give:
 *
 *  - A payment plan (Time to Pay). What protects you is the date you ASKED HMRC (FA 2009 Sch 56 para 10; FA 2021
 *    Sch 26 paras 6 and 8), provided HMRC then agree it and you keep to it. It covers only the amount in the plan, and
 *    only penalties arising before the plan ends; if it's broken HMRC can charge the penalties it held off. The old
 *    single date ("HMRC agreed a payment plan from") becomes the request date, with the plan taken as agreed.
 *  - An amended return: when HMRC got it and the tax on the original return, because extra tax from an amendment is
 *    penalised from its own later date (TMA 1970 Sch 3ZA; Sch 56 item 19), while interest still runs from 31 January.
 *  - A determination (HMRC's own figure for a missing return, TMA 1970 s28C): it counts as the return for next year's
 *    payments on account, and sets how long a late return can replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->date('plan_requested_on')->nullable();
            $table->boolean('plan_agreed')->nullable(); // null: not known yet (treated as not agreed)
            $table->date('plan_ends_on')->nullable();
            $table->date('plan_broken_on')->nullable();
            $table->bigInteger('plan_amount_pence')->nullable(); // null: the whole of the year's tax
            $table->date('amended_on')->nullable();
            $table->bigInteger('original_liability_pence')->nullable();
            $table->date('determination_on')->nullable();
        });

        // The old field was labelled "HMRC agreed a payment plan from": keep it as the request date, agreed.
        DB::table('tax_years')->whereNotNull('payment_plan_from')->update([
            'plan_requested_on' => DB::raw('payment_plan_from'),
            'plan_agreed' => true,
        ]);

        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn('payment_plan_from');
        });
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->date('payment_plan_from')->nullable();
        });
        // The old field meant a plan HMRC agreed and you're keeping to: only such a plan goes back into it. A plan not
        // agreed (yet), refused or broken is dropped, so the old code never holds penalties off for it.
        DB::table('tax_years')->whereNotNull('plan_requested_on')->where('plan_agreed', true)->whereNull('plan_broken_on')
            ->update(['payment_plan_from' => DB::raw('plan_requested_on')]);
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn(['plan_requested_on', 'plan_agreed', 'plan_ends_on', 'plan_broken_on', 'plan_amount_pence', 'amended_on', 'original_liability_pence', 'determination_on']);
        });
    }
};
