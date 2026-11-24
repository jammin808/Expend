<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A finding's amount is in the currency of what it's about (an invoice in euros, a stay booked in dollars, a euro
 * pocket's gap), but it was kept without one, so the history showed euro amounts as pounds and added them into the
 * pound totals. Findings now keep their currency.
 *
 * Findings already kept are given theirs from the invoice, booking or account they're about; one that can't be
 * traced keeps none, which means pounds, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('findings', 'currency')) {
            Schema::table('findings', function (Blueprint $table) {
                $table->char('currency', 3)->nullable()->after('amount_pence');
            });
        }

        $sources = [
            'invoices' => ['invoice_overdue:', 'unsent:', 'underbilled:'],
            'travel_items' => ['recharge:', 'stay:', 'missing:'],
            'accounts' => ['gap:'],
        ];
        foreach ($sources as $table => $prefixes) {
            foreach ($prefixes as $prefix) {
                $rows = DB::table('findings')->whereNull('currency')->whereNotNull('amount_pence')->where('key', 'like', $prefix.'%')->get(['id', 'key']);
                $ids = $rows->mapWithKeys(fn ($r) => [$r->id => (int) explode(':', substr($r->key, strlen($prefix)))[0]]);
                if ($ids->isEmpty()) {
                    continue;
                }
                $currency = DB::table($table)->whereIn('id', $ids->unique()->values())->pluck('currency', 'id');
                foreach ($ids as $findingId => $sourceId) {
                    $code = strtoupper((string) ($currency[$sourceId] ?? ''));
                    if ($code !== '' && $code !== 'GBP') {
                        DB::table('findings')->where('id', $findingId)->update(['currency' => $code]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('findings', 'currency')) {
            Schema::table('findings', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
