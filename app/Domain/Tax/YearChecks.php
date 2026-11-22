<?php

namespace App\Domain\Tax;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;

/**
 * Coverage grid and pre-filing checks for one tax year.
 */
final class YearChecks
{
    /** @return array{months: list<string>, accounts: list<array>} */
    public static function coverage(string $label): array
    {
        $year = UkTaxYear::fromLabel($label);
        $months = $year->months();
        $accounts = [];

        foreach (Account::query()->where('is_active', true)->orderBy('name')->get() as $account) {
            $txs = BankTransaction::query()->where('account_id', $account->id)
                ->whereBetween('posted_on', [$year->startsOn()->subMonth(), $year->endsOn()->addMonth()])
                ->orderBy('posted_on')->orderBy('id')->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'balance_after_pence', 'import_batch_id']);

            $cells = [];
            foreach ($months as $month) {
                $inMonth = $txs->filter(fn ($t) => $t->posted_on->format('Y-m') === $month);
                $cells[$month] = ['count' => $inMonth->count(), 'continuity' => 'unknown'];
            }

            // Balance continuity: the balance before the month's first day must equal a closing balance on the
            // previous posting day. Rows on one day can be in either order in a statement, so any order is accepted.
            $withBalance = $txs->filter(fn ($t) => $t->balance_after_pence !== null)->values();
            if ($withBalance->count() > 1) {
                $byDay = $withBalance->groupBy(fn ($t) => $t->posted_on->format('Y-m-d'))->sortKeys();
                $days = $byDay->keys()->values();
                foreach ($months as $month) {
                    $firstDay = $days->first(fn ($d) => str_starts_with($d, $month));
                    if (! $firstDay) {
                        continue;
                    }
                    $idx = $days->search($firstDay);
                    if ($idx === 0) {
                        $cells[$month]['continuity'] = 'unknown';

                        continue;
                    }
                    $prevDay = $byDay[$days[$idx - 1]];
                    $today = $byDay[$firstDay];
                    // Closing balance of the previous day: a balance there that no other row that day continues from.
                    $prevOpenings = $prevDay->map(fn ($t) => $t->balance_after_pence - $t->amount_pence)->all();
                    $prevClosings = $prevDay->pluck('balance_after_pence')->reject(fn ($b) => in_array($b, $prevOpenings, true))->all() ?: $prevDay->pluck('balance_after_pence')->all();
                    $todayClosings = $today->pluck('balance_after_pence')->all();
                    $todayOpenings = $today->map(fn ($t) => $t->balance_after_pence - $t->amount_pence)->reject(fn ($b) => in_array($b, $todayClosings, true))->all()
                        ?: $today->map(fn ($t) => $t->balance_after_pence - $t->amount_pence)->all();
                    $cells[$month]['continuity'] = array_intersect($prevClosings, $todayOpenings) ? 'ok' : 'gap';
                }
            }

            $accounts[] = [
                'id' => $account->id, 'name' => $account->name, 'kind' => $account->kind,
                'months' => $cells,
            ];
        }

        return ['months' => $months, 'accounts' => $accounts];
    }

    /** @return list<array{key:string,label:string,status:string,detail:string}> */
    public static function checks(string $label, array $summary): array
    {
        $checks = [];
        $coverage = self::coverage($label);
        $today = now()->format('Y-m');

        $missing = [];
        $gaps = [];
        foreach ($coverage['accounts'] as $account) {
            foreach ($account['months'] as $month => $cell) {
                if ($month > $today) {
                    continue;
                }
                if ($cell['count'] === 0) {
                    $missing[] = "{$account['name']} ".date('M Y', strtotime($month.'-01'));
                }
                if ($cell['continuity'] === 'gap') {
                    $gaps[] = "{$account['name']} ".date('M Y', strtotime($month.'-01'));
                }
            }
        }

        $checks[] = [
            'key' => 'coverage', 'label' => 'Every account has statements for every month',
            'status' => $missing ? 'warn' : 'ok',
            'detail' => $missing ? 'No transactions for: '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? '…' : '').'. Quiet months are fine; missing statements are not.' : 'All months have data.',
        ];
        $checks[] = [
            'key' => 'continuity', 'label' => 'Balances carry over between statements',
            'status' => $gaps ? 'fail' : 'ok',
            'detail' => $gaps ? 'The balance jumps at: '.implode(', ', $gaps).'. A statement or rows may be missing.' : 'No balance gaps found (where the bank provides balances).',
        ];

        $pending = $summary['pending'];
        $checks[] = [
            'key' => 'undecided', 'label' => 'Nothing left undecided',
            'status' => $pending['unreviewed_count'] + $pending['suggested_count'] + $pending['manual_unreviewed_count'] > 0 ? 'fail' : 'ok',
            'detail' => sprintf('%d to decide, %d suggestions to confirm, %d manual entries to review.', $pending['unreviewed_count'], $pending['suggested_count'], $pending['manual_unreviewed_count']),
        ];

        $unpairedCardPayments = BankTransaction::query()->where('tax_year', $label)->whereNull('transfer_pair_id')
            ->whereHas('account', fn ($q) => $q->where('kind', 'credit_card'))->where('amount_pence', '>', 0)
            ->whereDoesntHave('allocations', fn ($q) => $q->where('kind', '!=', 'transfer'))->count();
        $checks[] = [
            'key' => 'transfers', 'label' => 'Card-bill payments are paired with the bank',
            'status' => $unpairedCardPayments ? 'warn' : 'ok',
            'detail' => $unpairedCardPayments ? "{$unpairedCardPayments} card payments have no matching bank payment. Import the bank statement for those dates, or mark them yourself." : 'All card payments are matched.',
        ];

        $previous = UkTaxYear::fromLabel($label)->previous()->label();
        $prevRecord = TaxYear::query()->where('label', $previous)->first();
        $prevTurnover = $prevRecord?->filed_turnover_pence;
        $prevExpenses = $prevRecord?->filed_expenses_pence;
        if ($prevTurnover === null && BankTransaction::query()->where('tax_year', $previous)->exists()) {
            $prevSummary = ReturnSummary::for($previous);
            $prevTurnover = $prevSummary['turnover_pence'];
            $prevExpenses = $prevSummary['allowable_expenses_pence'];
        }
        if ($prevTurnover !== null) {
            $changes = [];
            foreach (['Turnover' => [$prevTurnover, $summary['turnover_pence']], 'Allowable expenses' => [$prevExpenses, $summary['allowable_expenses_pence']]] as $name => [$was, $now]) {
                if ($was && abs($now - $was) / max(1, abs($was)) > 0.3) {
                    $changes[] = sprintf('%s %s (was %s)', $name, Money::format($now), Money::format($was));
                }
            }
            $checks[] = [
                'key' => 'year_on_year', 'label' => "Compared with {$previous}",
                'status' => $changes ? 'warn' : 'ok',
                'detail' => $changes ? 'Changed by more than 30%: '.implode('; ', $changes).'. Check nothing is missing or double counted.' : 'No big changes from the previous year.',
            ];
        } else {
            $checks[] = ['key' => 'year_on_year', 'label' => "Compared with {$previous}", 'status' => 'warn', 'detail' => "Add {$previous}'s filed figures in Tax years to compare."];
        }

        if (! TaxParameters::has($label)) {
            $checks[] = ['key' => 'rates', 'label' => 'Tax rates loaded', 'status' => 'warn', 'detail' => "No rates for {$label}, so there's no tax estimate. The return figures are still correct."];
        }
        if (Setting::get('scottish_taxpayer')) {
            $checks[] = ['key' => 'scottish', 'label' => 'Tax estimate', 'status' => 'warn', 'detail' => "Scottish income tax bands aren't estimated. Use HMRC's calculation."];
        }

        return $checks;
    }
}
