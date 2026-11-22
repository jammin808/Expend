<?php

use App\Domain\Ledger\Categories;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Categories::seed();
    }

    public function down(): void {}
};
