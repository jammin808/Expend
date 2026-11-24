<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The most money a finding involved while it was open. A gap with the bank filled in stages (a statement for part
 * of it, then the rest) is one finding whose amount goes down as it's filled; the money dealt with is the gap you
 * started with, not what was left of it at the end. The amount it has now is kept as before, for what's still open.
 * Findings kept before start from the amount they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->bigInteger('peak_amount_pence')->nullable()->after('amount_pence');
        });
        DB::table('findings')->whereNotNull('amount_pence')->update(['peak_amount_pence' => DB::raw('amount_pence')]);
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropColumn('peak_amount_pence');
        });
    }
};
