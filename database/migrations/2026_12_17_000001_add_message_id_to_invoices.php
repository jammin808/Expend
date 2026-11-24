<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each invoice's email gets one fixed Message-ID (docs/mail-and-calendar.md 2D step 1), kept here so a re-send uses the
 * same one and each reminder can point back to it (In-Reply-To and References). Written when the invoice is first
 * emailed or chased; invoices already sent keep it empty until then.
 *
 * Additive, per docs/intelligence-cycle.md 3.1: one nullable column with no default, appended (no ->after()), so
 * MariaDB adds it in place and the release before this one runs on the new schema. No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Without the angle brackets, e.g. invoice-43.3f9c1a7b2d4e6f80@example.co.uk (at most 191, so it can be indexed).
            $table->string('message_id', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('message_id');
        });
    }
};
