<?php

namespace App\Domain\Ledger;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\StatementBalance;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Checks each account against the bank's own figures, so nothing is missing: payments in or out that never made it
 * into Expend (a statement not imported, a gap between two statements, a row lost from a file).
 *
 * Accounts whose exports carry a running balance are checked line by line: each day must start where the day
 * before ended. Cards and accounts without one are checked against the balances you copy from their statements:
 * the change between two statements must equal what's imported in between. Every difference is the money
 * missing, with the dates it's missing between.
 */
final class Reconciler
{
    /** An active account with nothing imported for this long probably has a statement waiting. */
    public const STALE_DAYS = 45;

    /**
     * @return array{account_id:int, account:string, method:string, checked_to:?string, balance_pence:?int, gaps:list<array{from:string, to:string, missing_pence:int, text:string}>,
     *               last_line:?string, stale:bool, text:string}
     */
    public static function account(Account $account): array
    {
        $rows = BankTransaction::query()->where('account_id', $account->id)->orderBy('posted_on')->orderBy('id')
            ->get(['id', 'posted_on', 'amount_pence', 'balance_after_pence']);
        $last = $rows->last()?->posted_on?->format('Y-m-d');
        $withBalance = $rows->whereNotNull('balance_after_pence');
        $entries = StatementBalance::query()->where('account_id', $account->id)->orderBy('as_of')->get();

        if ($rows->isNotEmpty() && $withBalance->count() >= max(2, (int) ceil($rows->count() * 0.9))) {
            $out = self::runningBalance($withBalance);
            $method = 'running_balance';
        } elseif ($entries->count() >= 2) {
            $out = self::statementBalances($account, $entries);
            $method = 'statement_balances';
        } else {
            $out = ['checked_to' => null, 'balance_pence' => null, 'gaps' => []];
            $method = 'none';
        }
        $stale = $account->is_active && $last && CarbonImmutable::parse($last)->lt(CarbonImmutable::today()->subDays(self::STALE_DAYS));

        return ['account_id' => $account->id, 'account' => $account->name, 'method' => $method, 'last_line' => $last, 'stale' => (bool) $stale] + $out
            + ['text' => self::text($account, $method, $out, $entries->count())];
    }

    /** @return list<array> every account, checked */
    public static function all(): array
    {
        return Account::query()->orderBy('name')->get()->map(fn (Account $a) => self::account($a))->all();
    }

    /**
     * Day by day: a day's first balance minus its first amount is where it started, and that must be where the day
     * before ended. Rows within a day can be in any order in a file, so the start and end are found from the chain.
     *
     * @param  Collection<int, BankTransaction>  $rows
     */
    private static function runningBalance(Collection $rows): array
    {
        $gaps = [];
        $prevClose = null;
        $prevDay = null;
        $checkedTo = null;
        foreach ($rows->groupBy(fn ($r) => $r->posted_on->format('Y-m-d')) as $day => $list) {
            $sum = (int) $list->sum('amount_pence');
            $starts = $list->map(fn ($r) => $r->balance_after_pence - $r->amount_pence)->all();
            $ends = $list->pluck('balance_after_pence')->all();
            $open = self::unmatched($starts, $ends);
            $close = self::unmatched($ends, $starts);
            if ($open === null || $close === null) {
                if ($prevClose !== null && in_array($prevClose + $sum, $ends, true)) {
                    [$open, $close] = [$prevClose, $prevClose + $sum];
                } elseif ($close !== null) {
                    $open = $close - $sum;
                } elseif ($open !== null) {
                    $close = $open + $sum;
                } else {
                    $close = $list->last()->balance_after_pence;
                    $open = $close - $sum;
                }
            }
            if ($prevClose !== null && $open !== $prevClose) {
                $gaps[] = self::gap(CarbonImmutable::parse($prevDay)->addDay()->format('Y-m-d'), CarbonImmutable::parse($day)->subDay()->format('Y-m-d'), $open - $prevClose, $prevDay, $day);
            }
            if ($open + $sum !== $close) {
                $gaps[] = self::gap($day, $day, $close - $open - $sum, $day, $day);
            }
            $prevClose = $close;
            $prevDay = $day;
            $checkedTo = $day;
        }

        return ['checked_to' => $checkedTo, 'balance_pence' => $prevClose, 'gaps' => $gaps];
    }

    /**
     * Between two statement balances, the change must equal what's imported in between. For a card the balance is
     * what you owe, so purchases (money out) push it up.
     *
     * @param  Collection<int, StatementBalance>  $entries
     */
    private static function statementBalances(Account $account, Collection $entries): array
    {
        $gaps = [];
        $card = $account->isCard();
        $list = $entries->values();
        for ($i = 1; $i < $list->count(); $i++) {
            [$a, $b] = [$list[$i - 1], $list[$i]];
            $sum = (int) BankTransaction::query()->where('account_id', $account->id)
                ->where('posted_on', '>', $a->as_of->format('Y-m-d'))->where('posted_on', '<=', $b->as_of->format('Y-m-d'))->sum('amount_pence');
            // The transactions that would have to be added for the two balances to agree.
            $needed = $card ? ($a->balance_pence - $b->balance_pence) - $sum : ($b->balance_pence - $a->balance_pence) - $sum;
            if ($needed !== 0) {
                $gaps[] = self::gap($a->as_of->copy()->addDay()->format('Y-m-d'), $b->as_of->format('Y-m-d'), $needed, $a->as_of->format('Y-m-d'), $b->as_of->format('Y-m-d'));
            }
        }
        $lastEntry = $list->last();

        return ['checked_to' => $lastEntry->as_of->format('Y-m-d'), 'balance_pence' => $lastEntry->balance_pence, 'gaps' => $gaps];
    }

    /** The one value in $a that isn't matched by a value in $b (as multisets), or null when there isn't exactly one. */
    private static function unmatched(array $a, array $b): ?int
    {
        $counts = array_count_values(array_map('strval', $b));
        $left = [];
        foreach ($a as $v) {
            $k = (string) $v;
            if (($counts[$k] ?? 0) > 0) {
                $counts[$k]--;
            } else {
                $left[] = $v;
            }
        }

        return count($left) === 1 ? (int) $left[0] : null;
    }

    private static function gap(string $from, string $to, int $missing, string $after, string $before): array
    {
        $what = $missing > 0 ? 'money in' : 'money out';
        $when = $from > $to
            ? 'between '.CarbonImmutable::parse($after)->format('j M Y').' and '.CarbonImmutable::parse($before)->format('j M Y')
            : ($from === $to ? 'on '.CarbonImmutable::parse($from)->format('j M Y') : 'from '.CarbonImmutable::parse($from)->format('j M Y').' to '.CarbonImmutable::parse($to)->format('j M Y'));

        return ['from' => min($from, $to), 'to' => max($from, $to), 'missing_pence' => $missing,
            'text' => Money::format(abs($missing)).' of '.$what.' isn’t in Expend '.$when.'.'];
    }

    private static function text(Account $account, string $method, array $out, int $entries): string
    {
        if ($method === 'none') {
            return $entries === 1
                ? 'Add another statement balance to check this account.'
                : ($account->isCard() ? 'This card’s export has no running balance. Add what two statements say you owe to check it.' : 'No running balance in the imports. Add balances from two statements to check it.');
        }
        if (! $out['gaps']) {
            return 'Matches the bank up to '.CarbonImmutable::parse($out['checked_to'])->format('j M Y').'.';
        }
        $total = array_sum(array_map(fn ($g) => abs($g['missing_pence']), $out['gaps']));

        return count($out['gaps']).' '.(count($out['gaps']) === 1 ? 'gap' : 'gaps').' with the bank’s figures ('.Money::format($total).' in all). A statement or some rows may be missing.';
    }
}
