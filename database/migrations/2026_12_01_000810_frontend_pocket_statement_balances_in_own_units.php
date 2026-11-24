<?php

use App\Domain\Audit\AuditLog;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A statement balance you type in for a currency pocket is kept in the pocket's own minor units, as its lines and
 * running balance are (a yen pocket's ¥17,500 is 17500), so it can be checked against them and shown as ¥17,500.
 * Balances typed in before were kept in hundredths whatever the currency (¥17,500 was 1750000): those of pockets in a
 * currency without two decimal places are put right here. Pockets in euros, dollars and the like don't change.
 * What was changed is recorded in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = DB::table('accounts')->whereNotNull('currency')->pluck('currency', 'id')
            ->map(fn ($c) => strtoupper(trim((string) $c)))->filter(fn ($c) => $c !== '' && Money::digits($c) !== 2);
        if ($accounts->isEmpty()) {
            return;
        }

        $before = [];
        $after = [];
        DB::table('statement_balances')->whereIn('account_id', $accounts->keys()->all())->select(['id', 'account_id', 'balance_pence'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($accounts, &$before, &$after) {
                foreach ($rows as $r) {
                    $currency = $accounts[$r->account_id];
                    $kept = (int) $r->balance_pence;
                    $minor = Money::fromHundredths($kept, $currency);
                    if ($minor === $kept) {
                        continue;
                    }
                    DB::table('statement_balances')->where('id', $r->id)->update(['balance_pence' => $minor]);
                    $before[$r->id] = ['account_id' => $r->account_id, 'balance' => $kept, 'currency' => $currency];
                    $after[$r->id] = ['account_id' => $r->account_id, 'balance' => $minor, 'currency' => $currency];
                }
            });

        if ($after) {
            AuditLog::record('account.balances_in_own_units', 'StatementBalance', $before, $after,
                'Statement balances of pockets in a currency without two decimal places were kept in hundredths: now in the pocket’s own units', 'system');
        }
    }

    public function down(): void
    {
        // The audit log keeps what each balance was before.
    }
};
