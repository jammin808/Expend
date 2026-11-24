<?php

use App\Domain\Audit\AuditLog;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The amount a line was paid in another currency is kept in that currency's own minor units: ¥1,500 is 1500, KWD
 * 12.345 is 12345 (as Revolut lines and manual expenses always were, and as the pages, the tax explanation and the
 * year spreadsheet now show them). Lines imported from a statement with a "Local amount" or "Original amount" column
 * (Monzo and the like) were kept in hundredths whatever the currency, so ¥1,500 was 150000 and now reads as
 * ¥150,000. Those are put right here, for currencies that don't have two decimal places.
 *
 * A line is only changed when the statement row it was imported from (kept unchanged in "raw") shows it was kept in
 * hundredths: the row's currency is the line's, and its original amount, read in hundredths, is exactly what was
 * kept. Revolut lines, manual expenses and lines already in the currency's own units never match, so running this
 * again changes nothing. Nothing about the pounds changes. What was changed is recorded in the audit log.
 */
return new class extends Migration
{
    private const AMOUNT_COLUMNS = ['Original Amount', 'Local Amount', 'Foreign Amount'];

    private const CURRENCY_COLUMNS = ['Currency', 'Original Currency', 'Local Currency'];

    public function up(): void
    {
        $currencies = DB::table('bank_transactions')->whereNotNull('original_amount_minor')->whereNotNull('original_currency')
            ->distinct()->pluck('original_currency')
            ->map(fn ($c) => strtoupper(trim((string) $c)))->filter(fn ($c) => $c !== '' && Money::digits($c) !== 2)->unique()->values();
        if ($currencies->isEmpty()) {
            return;
        }
        $profiles = DB::table('accounts')->pluck('import_profile', 'id')->map(fn ($p) => is_string($p) ? (json_decode($p, true) ?: []) : (array) $p);

        $before = [];
        $after = [];
        DB::table('bank_transactions')->whereNotNull('original_amount_minor')->whereIn('original_currency', $currencies->all())
            ->select(['id', 'account_id', 'raw', 'original_amount_minor', 'original_currency'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($profiles, &$before, &$after) {
                foreach ($rows as $r) {
                    $profile = $profiles[$r->account_id] ?? [];
                    $raw = json_decode((string) $r->raw, true) ?: [];
                    $kept = (int) $r->original_amount_minor;
                    $currency = strtoupper(trim((string) $r->original_currency));
                    if (! self::inHundredths($raw, $profile, $kept, $currency)) {
                        continue;
                    }
                    $minor = Money::fromHundredths($kept, $currency);
                    if ($minor === $kept) {
                        continue;
                    }
                    DB::table('bank_transactions')->where('id', $r->id)->update(['original_amount_minor' => $minor]);
                    $before[$r->id] = ['original_amount_minor' => $kept, 'original_currency' => $currency];
                    $after[$r->id] = ['original_amount_minor' => $minor, 'original_currency' => $currency];
                }
            });

        if ($after) {
            AuditLog::record('ledger.original_amounts_in_own_units', 'BankTransaction', $before, $after,
                'Amounts paid in a currency without two decimal places were kept in hundredths: now in the currency’s own units', 'system');
        }
    }

    public function down(): void
    {
        // The audit log keeps what each line held before.
    }

    /**
     * Whether the line's original amount was kept in hundredths: the statement row it came from has the line's
     * currency in its currency column, and its original amount column, read in hundredths, is exactly the amount kept.
     */
    private static function inHundredths(array $raw, array $profile, int $kept, string $currency): bool
    {
        if ($kept === 0) {
            return false;
        }
        $currencyColumns = array_values(array_filter([$profile['currencyColumn'] ?? null, ...self::CURRENCY_COLUMNS]));
        $amountColumns = array_values(array_filter([$profile['originalAmountColumn'] ?? null, ...self::AMOUNT_COLUMNS]));

        $sameCurrency = false;
        foreach (self::values($raw, $currencyColumns) as $value) {
            $sameCurrency = $sameCurrency || strtoupper(trim((string) $value)) === $currency;
        }
        if (! $sameCurrency) {
            return false;
        }
        foreach (self::values($raw, $amountColumns) as $value) {
            try {
                $hundredths = Money::parse(is_scalar($value) ? (string) $value : '');
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($hundredths !== null && abs($hundredths) === abs($kept)) {
                return true;
            }
        }

        return false;
    }

    /** The row's values for these column names, matched as the importer matches headers (any case, spaces trimmed). */
    private static function values(array $raw, array $names): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            foreach ($names as $name) {
                if (is_string($key) && strcasecmp(trim($key), trim((string) $name)) === 0) {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }
};
