<?php

use App\Domain\Ledger\Categories;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Categories::seed(); // idempotent: adds "Car hire" and renames the fuel sub-category
    }

    public function down(): void {}
};
