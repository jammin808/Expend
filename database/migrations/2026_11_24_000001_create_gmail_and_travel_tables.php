<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every Gmail message the app has fetched: the "what the app read" log.
        Schema::create('gmail_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->string('thread_id', 64)->nullable();
            $table->string('search', 20); // hotels | travel | job_documents
            $table->string('email_from', 300)->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('stored'); // stored | read | no_booking | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // One row per hotel stay or journey, keyed by provider and booking reference.
        Schema::create('travel_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // hotel | rail | taxi | flight | car_hire | coach | parking | other
            $table->string('provider', 120);
            $table->string('reference', 120)->nullable();
            $table->string('status', 20)->default('booked'); // booked | cancelled
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('place', 300)->nullable();
            $table->string('destination', 300)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->string('payment', 20)->default('unknown'); // paid | pay_at_property | paid_by_other | unknown
            $table->string('booked_for', 200)->nullable();
            $table->string('booker', 200)->nullable();
            $table->string('source', 20); // structured_data | ai | manual
            $table->json('evidence')->nullable();
            $table->unsignedSmallInteger('unverified_count')->default(0);
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_job_id')->nullable()->constrained()->nullOnDelete();
            $table->json('suggestion')->nullable();
            $table->string('decision', 20)->nullable(); // business | personal | mixed | client_booked | recharge
            $table->unsignedSmallInteger('business_nights')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'reference']);
            $table->index('starts_on');
        });

        // Postcode locations from postcodes.io, so each is only looked up once.
        Schema::create('places', function (Blueprint $table) {
            $table->string('postcode', 10)->primary();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
        Schema::dropIfExists('travel_items');
        Schema::dropIfExists('gmail_messages');
    }
};
