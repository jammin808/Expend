<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('source'); // upload | email | telegram
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->index();
            $table->string('stored_path');
            $table->string('email_from')->nullable();
            $table->string('email_subject', 500)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->longText('text')->nullable();
            $table->string('text_status')->default('none'); // extracted | image_only | none
            $table->string('kind')->default('unknown'); // po | job_invite | production_pack | call_sheet | booking | receipt | invoice | other | unknown
            $table->string('status')->default('new'); // new | queued | extracted | failed | held
            $table->text('summary')->nullable();
            $table->json('extraction')->nullable();
            $table->string('extraction_model')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field'); // venue | address | postcode | date | call_time | ... see DocumentReader::FIELDS
            $table->string('label');
            $table->text('value');
            $table->date('on_date')->nullable();
            $table->string('at_time', 5)->nullable();
            $table->text('quote');
            $table->unsignedSmallInteger('page')->nullable();
            $table->boolean('verified')->default(false);
            $table->string('verification_note')->nullable();
            $table->timestamps();
            $table->index(['work_job_id', 'field']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('po_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->string('status')->default('draft'); // draft | issued | paid | void
            $table->string('bill_to_name')->nullable();
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_email')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('po_total_minor')->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->text('source_quote')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('work_job_id')->constrained()->nullOnDelete();
        });

        Schema::create('assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // app | telegram
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('unmatched_figures')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_logs');
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('document_facts');
        Schema::dropIfExists('documents');
    }
};
