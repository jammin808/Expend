<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facts only HMRC's letters can tell: whether a notice to file was sent (and when), how the return was filed, a
 * deadline printed on the notice, a deferred deadline, and whether you told HMRC by 5 October that you needed to
 * file. They decide the filing date (TMA 1970 s8), the payment date (s59B(3)) and which penalties can apply. Empty
 * means unknown, and the penalty estimate says what it assumes instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->string('notice_to_file', 10)->nullable(); // given | not_given | null (unknown)
            $table->date('notice_date')->nullable();
            $table->string('filed_method', 10)->nullable(); // online | paper | null
            $table->date('filing_date_override')->nullable();
            $table->date('deferred_to')->nullable();
            $table->boolean('told_hmrc_by_5_october')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_years', function (Blueprint $table) {
            $table->dropColumn(['notice_to_file', 'notice_date', 'filed_method', 'filing_date_override', 'deferred_to', 'told_hmrc_by_5_october']);
        });
    }
};
