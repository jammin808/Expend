<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an automatic link that isn't an allocation was made: a calendar entry, document or stay put on a job, a stay's
 * payment, an invoice on a job (docs/intelligence-cycle.md 3.2, M6). Written in the same transaction as the link
 * itself, one row per link and subject. It is only metadata: a row counts only while its object_ref still equals the
 * link as it is now (the job the entry is on), so it can never become a second record of the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_provenance', function (Blueprint $table) {
            $table->id();
            $table->string('link', 16);
            $table->string('subject_ref', 191);
            $table->string('object_ref', 191)->nullable();
            $table->string('method', 40);
            $table->unsignedSmallInteger('method_version');
            $table->string('basis', 16); // 'ai_ungrounded' is 13 characters
            $table->smallInteger('score')->nullable();
            $table->text('evidence')->nullable();
            $table->text('alternatives')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->char('correlation', 26)->nullable();
            $table->timestamps();
            $table->unique(['link', 'subject_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_provenance');
    }
};
