<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Income assignment (income design v3): money in can be for a client, one job, or several jobs and invoices.
 *
 * allocations.client_id is the client a line is for when neither its invoice nor its job names one (client-only
 * income, a refund paid back to a client, a deduction with no job). The client a line counts for is always worked
 * out (App\Domain\Income\IncomeClient), never copied onto a line that has an invoice or a job.
 *
 * invoices gain what an owner's closing records (closed_minor in the invoice's own minor units, closed_reason,
 * closed_at, and closed_linked_minor, what payments naming it had paid by then; the note goes in the audit event's
 * reason) and replaced_by_id, the invoice that replaced a void one, so its payments count for the replacement. Invoice::updating allows closed_* only on an issued invoice and
 * replaced_by_id only on a void one (or in the save that voids it).
 *
 * Additive only (docs/intelligence-cycle.md 3.1): nullable columns with no default, appended (never ->after()), no
 * json and no foreign keys, so MariaDB adds them in place and the previous release runs on this schema unchanged.
 * Nothing is backfilled: existing lines have no client of their own, and no invoice is closed or replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->index();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('closed_minor')->nullable();
            // short_paid | charges | wont_chase | paid_other_currency | other
            $table->string('closed_reason', 24)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable()->index();
            // What payments naming it had paid when it was closed (InvoicePayments' paid_linked then), in its own minor
            // units: money naming it after that shrinks what was written off, never its share of the job's income.
            $table->bigInteger('closed_linked_minor')->nullable();
        });
    }

    public function down(): void
    {
        // Indexes first, each in its own call, so SQLite can then drop the columns.
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['replaced_by_id']);
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['closed_minor', 'closed_reason', 'closed_at', 'replaced_by_id', 'closed_linked_minor']);
        });
    }
};
