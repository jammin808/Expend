<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The date HMRC agreed a payment plan (Time to Pay) for a year's tax, which stops further late payment penalties. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->date('payment_plan_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn('payment_plan_from');
        });
    }
};
