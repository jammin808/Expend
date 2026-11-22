<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind'); // bank | credit_card | cash
            $table->string('provider')->nullable(); // starling | amex | capital_one | other
            $table->char('currency', 3)->default('GBP');
            $table->string('last4', 4)->nullable();
            $table->json('import_profile')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->char('file_sha256', 64);
            $table->string('stored_path');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->string('balance_check')->default('not_available'); // passed | failed | not_available
            $table->text('balance_check_notes')->nullable();
            $table->string('status')->default('committed'); // committed | reverted
            $table->timestamps();
            $table->index(['account_id', 'file_sha256']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->date('posted_on');
            $table->date('transaction_on')->nullable();
            $table->string('tax_year', 7)->index();
            $table->string('description', 500);
            $table->string('counterparty')->nullable();
            $table->string('reference')->nullable();
            $table->string('merchant_key')->index();
            // Negative = money out of your pocket (spending), positive = money in.
            $table->bigInteger('amount_pence');
            $table->bigInteger('balance_after_pence')->nullable();
            $table->bigInteger('original_amount_minor')->nullable();
            $table->char('original_currency', 3)->nullable();
            $table->string('bank_category')->nullable();
            $table->json('raw');
            $table->char('fingerprint', 64);
            $table->unsignedInteger('occurrence')->default(1);
            $table->string('review_status')->default('unreviewed')->index(); // unreviewed | suggested | confirmed
            $table->foreignId('transfer_pair_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['account_id', 'fingerprint', 'occurrence']);
            $table->index(['account_id', 'posted_on']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind'); // expense | income
            $table->string('hmrc_box')->nullable();
            $table->boolean('is_allowable')->default(true);
            $table->boolean('is_capital')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('work_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location')->nullable();
            $table->string('postcode', 10)->nullable();
            $table->bigInteger('fee_pence')->nullable();
            $table->string('status')->default('booked'); // booked | done | cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('manual_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on');
            $table->string('tax_year', 7)->index();
            $table->string('description', 500);
            $table->string('merchant_key')->index();
            $table->string('paid_with')->default('cash'); // cash | personal_card | other
            $table->bigInteger('amount_pence');
            $table->bigInteger('original_amount_minor')->nullable();
            $table->char('original_currency', 3)->nullable();
            $table->decimal('fx_units_per_gbp', 18, 6)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source')->nullable(); // document | hmrc_monthly | manual
            $table->string('review_status')->default('unreviewed');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_rules', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_key')->unique();
            $table->string('kind');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('business_pct')->default(100);
            $table->boolean('auto_confirm')->default(false);
            $table->timestamps();
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('manual_expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_year', 7)->index();
            // business_expense | business_income | personal | transfer | tax_payment | drawings | capital_introduced | loan
            $table->string('kind')->index();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_pence');
            $table->unsignedTinyInteger('business_use_pct')->nullable();
            $table->string('decided_by')->default('you'); // you | rule | matcher | ai
            $table->boolean('is_manual')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('merchant_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_years', function (Blueprint $table) {
            $table->id();
            $table->string('label', 7)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('outstanding'); // outstanding | in_progress | ready_to_file | filed | amending
            $table->date('filed_on')->nullable();
            $table->string('hmrc_reference')->nullable();
            // Figures from a return filed outside the app (used for year-on-year checks).
            $table->bigInteger('filed_turnover_pence')->nullable();
            $table->bigInteger('filed_expenses_pence')->nullable();
            $table->bigInteger('filed_profit_pence')->nullable();
            // Payments on account already paid towards this year.
            $table->bigInteger('payments_on_account_paid_pence')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('car_van'); // car_van | motorcycle | bicycle
            $table->string('method')->default('simplified'); // simplified | actual
            $table->string('registration')->nullable();
            $table->timestamps();
        });

        Schema::create('mileage_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->date('trip_on');
            $table->string('tax_year', 7)->index();
            $table->string('from_place');
            $table->string('to_place');
            $table->decimal('miles', 8, 1);
            $table->string('purpose')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('units_per_gbp', 18, 6);
            $table->string('source'); // hmrc_monthly | manual
            $table->timestamps();
            $table->unique(['currency', 'period_start', 'source']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('uid');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('summary')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->index('starts_at');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('actor');
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_events', 'calendar_events', 'exchange_rates', 'mileage_trips', 'vehicles', 'tax_years',
            'allocations', 'merchant_rules', 'manual_expenses', 'work_jobs', 'clients', 'categories',
            'bank_transactions', 'import_batches', 'accounts', 'settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
