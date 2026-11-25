<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses added on the phone (the quick add screen), shared from Android, or texted to the Telegram bot: one row per
 * entry, keyed by the phone's client key (a UUID made at Save; tg:<chat>:<message id> for Telegram). The key is the
 * idempotency key: it's claimed first (status receiving), so a send repeated on poor signal finds the row and never
 * adds twice. A withdraw that arrives before the entry leaves a tombstone (status withdrawn, nothing else), so a late
 * send with that key writes nothing.
 *
 * How it was paid decides what's written: cash or a personal card add a manual expense (manual_expense_id); a business
 * card or account adds nothing until its bank line comes in, when the receipt is attached to the line
 * (bank_transaction_id, attached_by matcher|you) and its choices offered there for one tap.
 *
 * Additive: nothing existing changes. JSON is kept in text columns (read_check, answers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_entries', function (Blueprint $table) {
            $table->id();
            $table->string('client_key', 80)->unique();
            $table->string('channel', 10)->default('phone'); // phone | share | telegram
            // receiving | draft | added | waiting | attached | held | duplicate | set_aside | withdrawn | dismissed
            $table->string('status', 16);
            $table->string('hold_reason', 16)->nullable(); // locked_year | needs_rate | needs_details
            $table->string('paid_with', 16)->nullable(); // cash | personal_card | business
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->date('spent_on')->nullable();
            $table->time('spent_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->string('tax_year', 7)->nullable();
            // In the currency's own minor units (pence, cents, yen).
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            // The sterling figure on a foreign receipt, when you gave one.
            $table->bigInteger('sterling_pence')->nullable();
            $table->string('what', 500)->nullable();
            $table->string('what_source', 8)->nullable(); // typed | chip | photo | telegram
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained('work_jobs')->nullOnDelete();
            $table->string('job_source', 8)->nullable(); // today | you | words
            $table->unsignedTinyInteger('business_pct')->nullable();
            $table->text('note')->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->unsignedInteger('photo_size')->nullable();
            $table->string('photo_status', 12)->nullable(); // expected | stored | unreadable
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('manual_expense_id')->nullable()->constrained('manual_expenses')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->string('attached_by', 10)->nullable(); // matcher | you
            $table->timestamp('attached_at')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('quick_entries')->nullOnDelete();
            // What the photo's read said against what you typed (QuickReadCheck), and your answers.
            $table->text('read_check')->nullable();
            // Your answers to the checks: card lines you said weren't this payment, records you said were two payments.
            $table->text('answers')->nullable();
            $table->string('telegram_chat_id', 32)->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->char('payload_sha', 64)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('bank_transaction_id');
            $table->index('document_id');
            $table->index('photo_sha256');
            $table->index(['work_job_id', 'status']);
            $table->index(['amount_minor', 'currency']);
            $table->index('telegram_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_entries');
    }
};
