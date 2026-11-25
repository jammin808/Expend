<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client area, marking a job done, and invoices whose lines follow what's linked to the job.
 *
 * clients: how a client is invoiced (the name, email and address on their invoices, their payment terms, and the
 * route: thread, new or none), and merged_into_id when you merged a duplicate into another client. Merges are resolved
 * when read (COALESCE(merged_into_id, id)); clients are never deleted.
 *
 * work_jobs: end_client_id (who the work was for, when that isn't the paying client; null when it's the same), a day
 * or hour rate in pence, the days agreed, when you marked it done, and what a cancellation is charged.
 *
 * invoices: lines_seen_event_id (the last line change you saw on the draft) and synced_at (set on a draft's first sync
 * and whenever its lines change).
 *
 * invoice_lines: where a line comes from (source, source_ref, source_job_id), whether it's ticked (included, default
 * ticked), what you changed yourself (ticked_by, own_text, own_amount), the source's own figures, and a flag. The
 * unique index is (source_ref, invoice_id), not the other way round: an index that starts with invoice_id would take
 * over the foreign key's own index on MariaDB, and dropping it would then fail (error 1553).
 *
 * New tables: charge_terms (how a client or a job charges each kind of cost), cost_charges (your own decision about
 * one cost) and invoice_emails (an invoice or reminder put in Gmail or sent).
 *
 * Additive only (docs/intelligence-cycle.md 3.1): columns appended (never ->after()), nullable or with a constant
 * default, no json and no new foreign keys, so MariaDB adds them in place and the previous release runs on this
 * schema. Nothing is backfilled: existing lines read as ticked lines you typed (source null), no client is merged,
 * no job is marked done by this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoice_name', 200)->nullable();
            $table->string('billing_email', 200)->nullable();
            $table->text('billing_address')->nullable();
            // Falls back to the invoice_terms_days setting when null.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            // thread (the default when null) | new | none ('They pay without an invoice from me')
            $table->string('invoice_route', 8)->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable()->index();
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('end_client_id')->nullable()->index();
            // In pence: a day or hour rate (a rate for the whole job stays fee_pence).
            $table->bigInteger('rate_pence')->nullable();
            $table->string('rate_unit', 4)->nullable(); // day | hour
            $table->unsignedSmallInteger('days_agreed')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('cancel_charge', 5)->nullable(); // none | fee | costs
            $table->bigInteger('cancel_fee_pence')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('lines_seen_event_id')->nullable();
            $table->timestamp('synced_at')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            // null: typed, or made before this release; po | fee | days | hours | extra | tdays | tfee | meals | cancel | mileage | cost
            $table->string('source', 12)->nullable();
            $table->string('source_ref', 191)->nullable();
            $table->unsignedBigInteger('source_job_id')->nullable()->index();
            $table->boolean('included')->default(true);
            $table->string('ticked_by', 8)->nullable(); // you
            $table->boolean('own_text')->default(false);
            $table->boolean('own_amount')->default(false);
            $table->bigInteger('source_amount_minor')->nullable();
            $table->decimal('source_quantity', 10, 2)->nullable();
            // gone | unconfirmed | no_payment | no_amount | maybe_same | check
            $table->string('flag', 12)->nullable();
            $table->unique(['source_ref', 'invoice_id']);
        });

        Schema::create('charge_terms', function (Blueprint $table) {
            $table->id();
            $table->string('owner_key', 24); // client:{id} | job:{id}
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('work_job_id')->nullable()->index();
            $table->string('class', 12);
            $table->string('charge', 10);
            // In the currency's minor units: per mile, per job, per day.
            $table->bigInteger('rate_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->timestamps();
            $table->unique(['owner_key', 'class']);
        });

        Schema::create('cost_charges', function (Blueprint $table) {
            $table->id();
            $table->string('cost_ref', 191)->unique();
            $table->string('subject_ref', 64)->index();
            $table->string('charge', 10)->nullable(); // recharge | no_charge | elsewhere | invoiced
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('note', 250)->nullable();
            $table->string('pair', 8)->nullable(); // same | distinct
            $table->string('pair_ref', 191)->nullable();
            $table->string('outcome', 10)->nullable(); // paid_back | not_paid
            $table->timestamp('outcome_at')->nullable();
            $table->unsignedBigInteger('audit_event_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('kind', 10); // invoice | reminder
            $table->unsignedTinyInteger('stage')->nullable();
            $table->string('route', 8); // gmail | mailer | by_hand
            $table->string('gmail_thread_id', 64)->nullable();
            $table->string('gmail_draft_id', 64)->nullable();
            $table->string('draft_message_id', 64)->nullable();
            $table->string('current_message_id', 64)->nullable();
            $table->string('gmail_message_id', 64)->nullable();
            $table->string('rfc_message_id', 191)->nullable();
            $table->string('to_address', 200)->nullable();
            $table->string('subject', 250)->nullable();
            $table->string('status', 10); // drafted | sent | deleted | failed | replaced | removed
            $table->timestamp('drafted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('error', 250)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
        Schema::dropIfExists('cost_charges');
        Schema::dropIfExists('charge_terms');

        // Indexes first, each table in its own call, so the columns can then be dropped (SQLite won't drop an
        // indexed column, and MariaDB keeps invoice_lines' foreign key index throughout).
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropUnique(['source_ref', 'invoice_id']);
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['source_job_id']);
        });
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropIndex(['end_client_id']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['merged_into_id']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_ref', 'source_job_id', 'included', 'ticked_by', 'own_text', 'own_amount', 'source_amount_minor', 'source_quantity', 'flag']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['lines_seen_event_id', 'synced_at']);
        });
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn(['end_client_id', 'rate_pence', 'rate_unit', 'days_agreed', 'done_at', 'cancel_charge', 'cancel_fee_pence']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['invoice_name', 'billing_email', 'billing_address', 'payment_terms_days', 'invoice_route', 'merged_into_id']);
        });
    }
};
