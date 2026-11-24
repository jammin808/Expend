<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Import\RowNormalizer;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The currency a payment was actually made in, where it isn't the currency of the account it came out of: a $90.00
 * purchase paid from a Revolut euro account as €76.64 keeps the $90.00 as well (merchant_*), alongside the euros
 * (original_*) and the pounds it counts as.
 *
 * Lines already kept are filled in from the statement row each was imported from (kept unchanged in "raw"):
 * - Revolut Business lines from an account in another currency, with an "Orig currency" that isn't the account's;
 * - Amex lines whose Extended Details give the foreign amount ("Foreign Spend Amount: 90.00 US DOLLARS ..."), where
 *   no original currency was kept, with the rate the bank's sterling figure works out at (as new imports keep it). A
 *   currency name that isn't known for sure is left, not guessed.
 *
 * Nothing about the pounds changes. What was filled in is recorded in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->bigInteger('merchant_amount_minor')->nullable();
            $table->char('merchant_currency', 3)->nullable();
        });
        $this->fill();
    }

    /** Fill in the lines already kept, from their statement rows (on its own, so it can be checked without the columns changing). */
    public function fill(): void
    {
        $filled = [];
        $foreign = DB::table('accounts')->where('currency', '!=', 'GBP')->pluck('currency', 'id');
        if ($foreign->isNotEmpty()) {
            DB::table('bank_transactions')->whereIn('account_id', $foreign->keys())->whereNull('merchant_currency')
                ->select(['id', 'account_id', 'raw', 'amount_pence'])->orderBy('id')
                ->chunkById(500, function ($rows) use ($foreign, &$filled) {
                    foreach ($rows as $r) {
                        $raw = json_decode((string) $r->raw, true) ?: [];
                        $orig = strtoupper(trim((string) ($raw['Orig currency'] ?? '')));
                        $pocket = strtoupper((string) $foreign[$r->account_id]);
                        if (strlen($orig) !== 3 || $orig === $pocket || strtoupper(trim((string) ($raw['Type'] ?? ''))) === 'EXCHANGE') {
                            continue;
                        }
                        $minor = self::minor((string) ($raw['Orig amount'] ?? ''), $orig);
                        if (! $minor) {
                            continue;
                        }
                        $change = ['merchant_amount_minor' => $r->amount_pence < 0 ? -abs($minor) : abs($minor), 'merchant_currency' => $orig];
                        DB::table('bank_transactions')->where('id', $r->id)->update($change);
                        $filled[$r->id] = $change;
                    }
                });
        }

        $amex = [];
        // Any case, and any spacing between the words (the row is kept as JSON, which MariaDB compares case by case).
        DB::table('bank_transactions')->whereNull('original_currency')->whereRaw('LOWER(raw) LIKE ?', ['%foreign%spend%'])
            ->select(['id', 'raw', 'amount_pence', 'posted_on', 'transaction_on', 'fx_source'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$amex) {
                foreach ($rows as $r) {
                    $spend = RowNormalizer::foreignSpend(json_decode((string) $r->raw, true) ?: []);
                    if (! $spend) {
                        continue;
                    }
                    $change = ['original_amount_minor' => $r->amount_pence < 0 ? -abs($spend['minor']) : abs($spend['minor']), 'original_currency' => $spend['currency']];
                    $rate = $r->fx_source === null ? RowNormalizer::rate($spend['minor'], $spend['currency'], (int) $r->amount_pence) : null;
                    if ($rate !== null) {
                        $change += ['fx_units_per_gbp' => $rate, 'fx_rate_date' => substr((string) ($r->transaction_on ?? $r->posted_on), 0, 10), 'fx_source' => 'bank'];
                    }
                    DB::table('bank_transactions')->where('id', $r->id)->update($change);
                    $amex[$r->id] = $change;
                }
            });

        if ($filled || $amex) {
            AuditLog::record('ledger.currency_used_filled', 'BankTransaction', null, ['merchant' => $filled, 'original' => $amex],
                'The currency a payment was made in was on the statement but not kept with the line', 'system');
        }
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn(['merchant_amount_minor', 'merchant_currency']);
        });
    }

    /** An amount in the currency's own minor units, or null when it can't be read exactly. */
    private static function minor(string $value, string $currency): ?int
    {
        $v = str_replace([',', ' ', "'"], '', trim($value));
        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $v)) {
            return null;
        }
        try {
            return BigDecimal::of(ltrim($v, '+'))->toScale(Money::digits($currency))->getUnscaledValue()->toInt();
        } catch (Throwable) {
            return null;
        }
    }
};
