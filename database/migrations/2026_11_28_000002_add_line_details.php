<?php

use App\Domain\Import\LineDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The time, place, card and payment type of each statement line, read from the statement at import. Existing lines
 * are filled in from the statement row each one was imported from (kept unchanged in "raw").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('transacted_time', 5)->nullable();
            $table->string('place', 200)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('payment_type', 40)->nullable();
        });

        DB::table('bank_transactions')->select(['id', 'raw', 'description', 'transaction_on', 'posted_on'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                $raw = json_decode((string) $r->raw, true) ?: [];
                $d = LineDetails::from($raw, $r->description, CarbonImmutable::parse($r->transaction_on ?? $r->posted_on));
                if (array_filter($d)) {
                    DB::table('bank_transactions')->where('id', $r->id)->update([
                        'transacted_time' => $d['time'], 'place' => $d['place'], 'card_last4' => $d['card_last4'], 'payment_type' => $d['payment_type'],
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn(['transacted_time', 'place', 'card_last4', 'payment_type']);
        });
    }
};
