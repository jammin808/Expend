<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts kept in another currency (a Revolut euro or dollar pocket): each line keeps its own amount and running
 * balance in that currency, the sterling amount it counts as, and how that was worked out (the bank's own figure,
 * the other side of a currency exchange, or HMRC's monthly rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->bigInteger('balance_after_minor')->nullable();
            $table->decimal('fx_units_per_gbp', 18, 6)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source', 20)->nullable(); // bank, exchange, hmrc_monthly, manual
            $table->bigInteger('fee_minor')->nullable();
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('parent_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn(['balance_after_minor', 'fx_units_per_gbp', 'fx_rate_date', 'fx_source', 'fee_minor']);
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_account_id');
        });
    }
};
