<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gmail messages the app has given its "✓ Invoiced" label, so it can take the label off again if the
        // invoice is voided, and never labels the same message twice.
        Schema::create('gmail_labels', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('found_by', 20); // gmail_search | message_id | po_number
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_labels');
    }
};
