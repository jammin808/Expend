<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every problem the app has found (money owed, gaps, missing POs, duplicate charges...), when, and how it ended. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('group', 20)->index();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('href', 255)->nullable();
            $table->bigInteger('amount_pence')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('resolution', 12)->nullable(); // resolved, snoozed, dismissed
            $table->unsignedInteger('reopened')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
