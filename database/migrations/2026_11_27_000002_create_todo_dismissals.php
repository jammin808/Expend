<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // To-do items you snoozed (until a date) or marked not relevant (no date).
        Schema::create('todo_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->timestamp('until')->nullable();
            $table->string('note', 250)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_dismissals');
    }
};
