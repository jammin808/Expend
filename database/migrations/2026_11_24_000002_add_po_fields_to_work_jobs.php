<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->string('po_number', 100)->nullable()->after('fee_pence');
            $table->boolean('po_not_required')->default(false)->after('po_number');
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropColumn(['po_number', 'po_not_required']);
        });
    }
};
