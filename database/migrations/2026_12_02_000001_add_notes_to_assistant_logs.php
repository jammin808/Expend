<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Things to check before relying on an answer (case names, law from the library only), kept apart from unmatched figures and links. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->json('notes')->nullable()->after('unmatched_figures');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
