<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactions are never deleted. "active" ones count; "quarantined" ones wait for review after a failed
        // balance check; "reversed" ones were undone (or discarded) and are kept for the record.
        // `live` is 1 only for active rows, so the duplicate check (a unique index) ignores the others.
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('state', 12)->default('active')->after('review_status')->index();
            $table->boolean('live')->nullable()->default(true)->after('state');
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->unique(['account_id', 'fingerprint', 'occurrence', 'live'], 'bank_tx_unique_live');
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'fingerprint', 'occurrence']);
        });

        // Decisions on undone transactions are voided, not deleted, so undoing and restoring loses nothing.
        Schema::table('allocations', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->index();
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->text('review_note')->nullable();
        });

        // The audit chain's head, locked for each write so two processes can never chain onto the same event.
        Schema::create('audit_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('hash', 64)->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->timestamps();
        });
        $last = DB::table('audit_events')->orderByDesc('id')->first();
        DB::table('audit_heads')->insert(['id' => 1, 'hash' => $last?->hash, 'event_id' => $last?->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_heads');
        Schema::table('import_batches', fn (Blueprint $table) => $table->dropColumn('review_note'));
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropIndex(['voided_at']);
            $table->dropColumn('voided_at');
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->unique(['account_id', 'fingerprint', 'occurrence']);
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropUnique('bank_tx_unique_live');
            $table->dropIndex(['state']);
            $table->dropColumn(['state', 'live']);
        });
    }
};
