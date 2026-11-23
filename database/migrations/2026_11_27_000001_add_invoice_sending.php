<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamp('overdue_nudged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['sent_at', 'sent_to', 'reminded_at', 'reminder_count', 'overdue_nudged_at']));
    }
};
