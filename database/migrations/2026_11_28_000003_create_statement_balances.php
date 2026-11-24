<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balances you copy from a statement (for cards and accounts whose export has no running balance), so each account
 * can be checked against the bank's own figures and missing payments found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            // A bank account's balance, or what a card says you owe (both as the statement shows them).
            $table->bigInteger('balance_pence');
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_balances');
    }
};
