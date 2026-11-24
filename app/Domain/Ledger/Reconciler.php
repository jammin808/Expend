<?php

namespace App\Domain\Ledger;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\StatementBalance;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Checks each account against the bank's own figures, so nothing is missing: payments in or out that never made it
 * into Expend (a statement not imported, a gap between two statements, a row lost from a file).
 *
 * Accounts whose exports carry a running balance are checked line by line: each day must start where the day
 * before ended. Lines with no balance (rows from a file without one) count towards the days they fall in; one the
 * bank's figures don't have may be in Expend twice, or (with a gap of the same amount a few days away) the gap's
 * payment with another date. A statement balance you copy in is checked against the lines too, which finds what's
 * missing before the first import or after the last. Cards and accounts without a running balance are checked against the balances you
 * copy from their statements: the change between two statements must equal what's imported in between. Every
 * difference is the money missing, with the dates it's missing between, in the account's own currency.
 */
final class Reconciler
{
    /** An active account with nothing imported for this long probably has a statement waiting. */
    public const STALE_DAYS = 45;

    /** Lines with no balance are matched to a gap they add up to exactly, among at most this many. */
    private const SUBSET_MAX = 12;

    /** A line with no balance may be dated a few days from where the bank's balance moved (another export's date). */
    private const SLACK_DAYS = 3;

    /**
     * A line with no balance that isn't in the bank's figures on its own date, and a gap of the same amount this many
     * days away: it may be that payment with another date, so it's one thing to check, not two.
     */
    private const MISDATED_DAYS = 10;

    /**
     * @return array{account_id:int, account:string, method:string, checked_to:?string, balance_pence:?int,
     *               gaps:list<array{from:string, to:string, missing_pence:int, kind:string, text:string, line_on?:string}>,
     *               last_line:?string, last_import:?string, stale:bool, currency:string, text:string}
     */
    public static function account(Account $account): array
    {
        $currency = strtoupper($account->currency ?: 'GBP');
        $foreign = $account->isForeign();
        $lines = BankTransaction::query()->where('account_id', $account->id)->orderBy('posted_on')->orderBy('id')
            ->get(['id', 'import_batch_id', 'posted_on', 'transaction_on', 'amount_pence', 'balance_after_pence', 'original_amount_minor', 'balance_after_minor'])
            ->map(fn (BankTransaction $r) => [
                'id' => (int) $r->id, 'batch' => $r->import_batch_id,
                'posted' => $r->posted_on->format('Y-m-d'), 'started' => $r->effectiveDate()->format('Y-m-d'),
                // A pocket in another currency is checked in that currency, as its statement shows it.
                'amount' => (int) ($foreign ? ($r->original_amount_minor ?? $r->amount_pence) : $r->amount_pence),
                'balance' => ($b = $foreign ? $r->balance_after_minor : $r->balance_after_pence) === null ? null : (int) $b,
            ])->all();
        $last = $lines ? max(array_column($lines, 'posted')) : null;
        $withBalance = count(array_filter($lines, fn ($l) => $l['balance'] !== null));
        // One balance a day (the latest you entered for it), oldest first.
        $entries = array_values(StatementBalance::query()->where('account_id', $account->id)->orderBy('as_of')->orderBy('id')->get()
            ->mapWithKeys(fn (StatementBalance $b) => [$b->as_of->format('Y-m-d') => ['as_of' => $b->as_of->format('Y-m-d'), 'balance' => (int) $b->balance_pence]])->sortKeys()->all());

        if ($lines && $withBalance >= max(2, (int) ceil(count($lines) * 0.9))) {
            $out = self::runningBalance($lines, $entries, $account->isCard(), $currency);
            $method = 'running_balance';
        } elseif (count($entries) >= 2) {
            $out = self::statementBalances($lines, $entries, $account->isCard(), $currency);
            $method = 'statement_balances';
        } else {
            $out = ['checked_to' => null, 'balance_pence' => null, 'gaps' => []];
            $method = 'none';
        }
        $seen = $last ? self::lastImport($account, $last) : null;
        $stale = $account->is_active && $seen && CarbonImmutable::parse($seen)->lt(CarbonImmutable::today()->subDays(self::STALE_DAYS));

        return ['account_id' => $account->id, 'account' => $account->name, 'method' => $method, 'last_line' => $last, 'last_import' => $seen, 'stale' => (bool) $stale, 'currency' => $currency] + $out
            + ['text' => self::text($account, $method, $out, count($entries), $currency)];
    }

    /** @return list<array> every account, checked */
    public static function all(): array
    {
        return Account::query()->orderBy('name')->get()->map(fn (Account $a) => self::account($a))->all();
    }

    /**
     * The newest date anything was imported for this account: its own lines and statements and, for a Revolut
     * account with currency pockets, a statement that covered every currency. A file with rows for two or more of
     * the family's accounts (pounds and euros) was an export of all of them, so a pocket with nothing in it had
     * nothing to import then. A statement of one currency says nothing about the others (a personal statement
     * covers one), so it counts only for its own account: euros spent on a trip whose statement was never imported
     * are still waiting, however recently the pounds were imported.
     */
    private static function lastImport(Account $account, string $last): string
    {
        $parent = $account->parent_account_id ?: $account->id;
        $family = Account::query()->where('id', $parent)->orWhere('parent_account_id', $parent)->pluck('id')->all();
        $batches = ImportBatch::query()->whereIn('account_id', $family)->where('status', 'committed')->whereNotNull('last_date')
            ->get(['account_id', 'file_sha256', 'last_date']);
        $accountsPerFile = $batches->groupBy('file_sha256')->map(fn ($bs) => $bs->pluck('account_id')->unique()->count());
        $dates = $batches->filter(fn (ImportBatch $b) => (int) $b->account_id === (int) $account->id || $accountsPerFile[$b->file_sha256] >= 2)
            ->map(fn (ImportBatch $b) => $b->last_date->format('Y-m-d'))->push($last)->all();

        return max($dates);
    }

    /**
     * Day by day: a day's first balance minus its first amount is where it started, and that must be where the day
     * before ended. Rows within a day can be in any order in a file, so the start and end are found from the chain.
     *
     * A statement's balance follows its own order of lines, which for some banks is the date a payment was made
     * rather than the date it went through (Revolut: a card payment started on the 2nd completes on the 5th). So the
     * lines are checked by both dates when they differ, and the order the bank's figures follow is the one that fits.
     *
     * @param  list<array{id:int, batch:?int, posted:string, started:string, amount:int, balance:?int}>  $lines
     * @param  list<array{as_of:string, balance:int}>  $entries
     */
    private static function runningBalance(array $lines, array $entries, bool $card, string $currency): array
    {
        $best = self::chain($lines, 'posted');
        if (array_filter($lines, fn ($l) => $l['started'] !== $l['posted'])) {
            $started = self::chain($lines, 'started');
            if (self::score($started) < self::score($best)) {
                $best = $started;
            }
        }
        $best = self::checkEntries($best, $entries, $card);
        $best = self::extras($best);
        usort($best['gaps'], fn ($a, $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return ['checked_to' => $best['checked_to'], 'balance_pence' => $best['balance'], 'gaps' => array_map(fn ($g) => self::gap($g, $currency), $best['gaps'])];
    }

    /** Fewer gaps, then less money in them. */
    private static function score(array $chain): array
    {
        return [count($chain['gaps']), array_sum(array_map(fn ($g) => abs($g['missing']), $chain['gaps']))];
    }

    /**
     * The day-by-day check with the lines dated by $by ('posted' or 'started'). Lines with no balance are then
     * matched to the gaps they fall in.
     *
     * @return array{days:array<string, array{open:int, close:int}>, gaps:list<array>, nulls:list<array>, checked_to:?string, balance:?int}
     */
    private static function chain(array $lines, string $by): array
    {
        $withBalance = array_values(array_filter($lines, fn ($l) => $l['balance'] !== null));
        usort($withBalance, fn ($a, $b) => [$a[$by], $a['id']] <=> [$b[$by], $b['id']]);
        $grouped = [];
        foreach ($withBalance as $l) {
            $grouped[$l[$by]][] = $l;
        }
        $newestFirst = self::newestFirstBatches($lines, $by);

        $gaps = [];
        $days = [];
        $prevClose = null;
        $prevDay = null;
        $keys = array_keys($grouped);
        foreach ($keys as $n => $day) {
            $list = $grouped[$day];
            $sum = array_sum(array_column($list, 'amount'));
            $starts = array_map(fn ($r) => $r['balance'] - $r['amount'], $list);
            $ends = array_column($list, 'balance');
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
                    $close = self::ambiguousClose($list, $ends, $grouped[$keys[$n + 1] ?? ''] ?? null, $newestFirst);
                    $open = $close - $sum;
                }
            }
            if ($prevClose !== null && $open !== $prevClose) {
                $gaps[] = self::raw(self::day($prevDay, 1), self::day($day, -1), $open - $prevClose, $prevDay, $day);
            }
            if ($open + $sum !== $close) {
                $gaps[] = self::raw($day, $day, $close - $open - $sum, $day, $day);
            }
            $days[$day] = ['open' => $open, 'close' => $close];
            $prevClose = $close;
            $prevDay = $day;
        }

        $nulls = array_values(array_map(fn ($l) => ['day' => $l[$by], 'amount' => $l['amount'], 'used' => false],
            array_filter($lines, fn ($l) => $l['balance'] === null)));
        $chain = ['days' => $days, 'gaps' => $gaps, 'nulls' => $nulls, 'explained' => [], 'checked_to' => $prevDay, 'balance' => $prevClose,
            'amounts' => array_map(fn ($list) => array_column($list, 'amount'), $grouped)];

        return self::explain($chain);
    }

    /**
     * A day whose balances don't show where it started and ended (it ends where it began, or a line is missing in
     * the middle): its close is the balance the next day starts from, if one of its own balances is that; otherwise
     * its latest line, which in a newest-first file is the first one stored.
     *
     * @param  array<int, true>  $newestFirst  import batches whose lines were stored newest first
     */
    private static function ambiguousClose(array $list, array $ends, ?array $next, array $newestFirst): int
    {
        if ($next) {
            $nextStarts = array_map(fn ($r) => $r['balance'] - $r['amount'], $next);
            $nextOpen = self::unmatched($nextStarts, array_column($next, 'balance'));
            $candidates = $nextOpen !== null ? [$nextOpen] : $nextStarts;
            foreach ($ends as $e) {
                if (in_array($e, $candidates, true)) {
                    return $e;
                }
            }
        }
        $latest = isset($newestFirst[$list[0]['batch'] ?? 0]) ? $list[0] : $list[count($list) - 1];

        return $latest['balance'];
    }

    /**
     * Files can be oldest-first or newest-first, and lines are stored in file order: the batches whose dates go
     * down as their lines were stored.
     *
     * @return array<int, true>
     */
    private static function newestFirstBatches(array $lines, string $by): array
    {
        $ends = [];
        foreach ($lines as $l) {
            if ($l['batch'] === null) {
                continue;
            }
            $b = (int) $l['batch'];
            $ends[$b]['first'] ??= $l;
            if ($l['id'] < $ends[$b]['first']['id']) {
                $ends[$b]['first'] = $l;
            }
            $ends[$b]['last'] ??= $l;
            if ($l['id'] > $ends[$b]['last']['id']) {
                $ends[$b]['last'] = $l;
            }
        }
        $out = [];
        foreach ($ends as $b => $e) {
            if ($e['first'][$by] > $e['last'][$by]) {
                $out[$b] = true;
            }
        }

        return $out;
    }

    /**
     * Lines with no balance are in Expend, and their money is in the bank's balances, so a gap they fall in is
     * explained by them: fully when some of them add up to it, otherwise the rest of the gap is what's still missing.
     * The lines that explain a gap are noted with it: the bank's balance moved by them somewhere between the two
     * days, which a statement balance dated in between may or may not include.
     */
    private static function explain(array $chain): array
    {
        $gaps = $chain['gaps'];
        $nulls = $chain['nulls'];
        $explained = [];
        if (! $nulls) {
            return $chain;
        }
        // First, gaps that some of the lines dated in them (or a few days either side) add up to exactly.
        foreach ([0, self::SLACK_DAYS] as $slack) {
            foreach ($gaps as $i => $g) {
                $in = self::within($nulls, self::day($g['after'], -$slack), self::day($g['before'], $slack));
                $pick = self::subset($in, array_map(fn ($k) => $nulls[$k]['amount'], $in), $g['missing']);
                if ($pick !== null) {
                    foreach ($pick as $k) {
                        $nulls[$k]['used'] = true;
                    }
                    $explained[] = ['after' => $g['after'], 'before' => $g['before'], 'keys' => $pick];
                    unset($gaps[$i]);
                }
            }
        }
        // Then what's dated in a gap counts towards it, and the rest is still missing.
        foreach ($gaps as $i => $g) {
            $in = self::within($nulls, $g['after'], $g['before']);
            foreach ($in as $k) {
                $gaps[$i]['missing'] -= $nulls[$k]['amount'];
                $nulls[$k]['used'] = true;
            }
            if ($gaps[$i]['missing'] === 0) {
                $explained[] = ['after' => $g['after'], 'before' => $g['before'], 'keys' => $in];
                unset($gaps[$i]);
            }
        }

        return ['gaps' => array_values($gaps), 'nulls' => $nulls, 'explained' => $explained] + $chain;
    }

    /**
     * Balances you copied from statements, checked against the lines: one dated after the last line shows what's
     * missing since, one before the first shows what's missing before it, and one in between must agree with the
     * line-by-line balance on its date (unless a gap there is already shown). A card's statement says what you owe,
     * which its running balance shows as money below zero.
     *
     * Lines with no balance on the first or last day may have gone through before the day's first balance or after
     * its last, or be in Expend twice. The statement balance beyond them tells which: if it agrees without them,
     * they're not in the bank's figures (in twice); if it agrees with them, they're real. When it agrees with
     * neither, the ones matching a line of the same day and amount are taken as in twice, and the rest as real.
     *
     * @param  list<array{as_of:string, balance:int}>  $entries
     */
    private static function checkEntries(array $chain, array $entries, bool $card): array
    {
        $days = $chain['days'];
        if (! $entries || ! $days) {
            return $chain;
        }
        $first = array_key_first($days);
        $lastDay = array_key_last($days);
        $nulls = $chain['nulls'];
        $gaps = $chain['gaps'];
        $checkedTo = $chain['checked_to'];
        $value = fn (array $e) => $card ? -$e['balance'] : $e['balance'];
        $sum = function (array $keys) use (&$nulls): int {
            return array_sum(array_map(fn ($k) => $nulls[$k]['amount'], $keys));
        };
        $take = function (string $from, string $to) use (&$nulls, $sum): int {
            $keys = self::within($nulls, $from, $to);
            foreach ($keys as $k) {
                $nulls[$k]['used'] = true;
            }

            return $sum($keys);
        };
        // The boundary day's lines with no balance, given the difference the statement balance shows without them.
        $boundary = function (string $day, int $without) use (&$nulls, &$gaps, $sum, $chain) {
            $keys = self::within($nulls, $day, $day);
            $all = $sum($keys);
            if (! $keys || $all === 0 || $without === $all) {
                return; // none, or they're real: the statement balance includes them
            }
            $twice = $without === 0 ? $keys : self::twins($nulls, $keys, $chain['amounts'][$day] ?? []);
            if ($twice) {
                foreach ($twice as $k) {
                    $nulls[$k]['used'] = true;
                }
                $gaps[] = self::raw($day, $day, -$sum($twice), $day, $day, 'extra');
            }
        };

        // After the last line: each balance against the one before it (the last day's close to start with).
        [$atDay, $atBalance, $fromDay] = [$lastDay, $days[$lastDay]['close'], $lastDay];
        foreach (array_values(array_filter($entries, fn ($e) => $e['as_of'] >= $lastDay)) as $n => $e) {
            if ($n === 0) {
                $boundary($lastDay, $value($e) - $atBalance - $sum(self::within($nulls, self::day($lastDay, 1), $e['as_of'])));
            }
            $missing = $value($e) - $atBalance - $take($fromDay, $e['as_of']);
            if ($missing !== 0) {
                $gaps[] = $e['as_of'] === $atDay ? self::raw($atDay, $atDay, $missing, $atDay, $atDay) : self::raw(self::day($atDay, 1), $e['as_of'], $missing, $atDay, $e['as_of']);
            } else {
                $checkedTo = max($checkedTo, $e['as_of']);
            }
            [$atDay, $atBalance, $fromDay] = [$e['as_of'], $value($e), self::day($e['as_of'], 1)];
        }

        // Before the first line, going back: the first day's opening balance, then each balance before it.
        [$atDay, $atBalance, $toDay] = [self::day($first, -1), $days[$first]['open'], $first];
        foreach (array_reverse(array_values(array_filter($entries, fn ($e) => $e['as_of'] < $first))) as $n => $e) {
            if ($n === 0) {
                $boundary($first, $atBalance - $value($e) - $sum(self::within($nulls, self::day($e['as_of'], 1), self::day($first, -1))));
            }
            $missing = $atBalance - $value($e) - $take(self::day($e['as_of'], 1), $toDay);
            if ($missing !== 0) {
                $gaps[] = self::raw(self::day($e['as_of'], 1), $atDay, $missing, $e['as_of'], self::day($atDay, 1));
            }
            [$atDay, $atBalance, $toDay] = [$e['as_of'], $value($e), $e['as_of']];
        }

        // In between: the balance at the end of the last day with lines on or before it, give or take the lines with
        // no balance that explain the change from that day to the next (the bank may have them on either day). A
        // line with no balance that explains nothing isn't in the bank's figures, so it's no part of the balance.
        $raw = array_filter($gaps, fn ($g) => $g['kind'] === 'missing');
        foreach (array_filter($entries, fn ($e) => $e['as_of'] >= $first && $e['as_of'] < $lastDay) as $e) {
            if (array_filter($raw, fn ($g) => $g['after'] <= $e['as_of'] && $e['as_of'] <= $g['before'])) {
                continue; // a gap around this date is already shown
            }
            $d = max(array_filter(array_keys($days), fn ($k) => $k <= $e['as_of']));
            $target = $value($e) - $days[$d]['close'];
            $around = array_merge([], ...array_map(fn ($x) => $x['keys'],
                array_filter($chain['explained'], fn ($x) => $x['after'] <= $e['as_of'] && $e['as_of'] < $x['before'])));
            if ($target === 0 || self::subset($around, array_map(fn ($k) => $nulls[$k]['amount'], $around), $target) !== null) {
                continue;
            }
            $missing = $target - $sum(array_filter($around, fn ($k) => $nulls[$k]['day'] <= $e['as_of']));
            if ($missing !== 0) {
                $gaps[] = $d === $e['as_of'] ? self::raw($d, $d, $missing, $d, $d) : self::raw(self::day($d, 1), $e['as_of'], $missing, $d, $e['as_of']);
            }
        }

        return ['gaps' => $gaps, 'nulls' => $nulls, 'checked_to' => $checkedTo] + $chain;
    }

    /**
     * Lines with no balance on days the bank's balances account for without them: their money isn't in the bank's
     * figures, so they may be in Expend twice (the same payment from two exports). Lines on the first or last day
     * may have been before or after every balance, so there only a line matching one of the day's own lines (the
     * same amount) is taken as in twice. A line like that in between, with a gap of the same amount a few days
     * away, may instead be the gap's payment dated differently by the export it came from: one thing to check.
     */
    private static function extras(array $chain): array
    {
        $days = $chain['days'];
        if (! $days) {
            return $chain;
        }
        $first = array_key_first($days);
        $lastDay = array_key_last($days);
        $nulls = $chain['nulls'];
        $between = array_values(array_filter(array_keys($nulls), fn ($k) => ! $nulls[$k]['used'] && $nulls[$k]['day'] > $first && $nulls[$k]['day'] < $lastDay));
        foreach ($chain['gaps'] as $i => $g) {
            if ($g['kind'] !== 'missing') {
                continue;
            }
            foreach ($between as $n => $k) {
                $away = $nulls[$k]['day'] < $g['after'] ? self::daysBetween($nulls[$k]['day'], $g['after']) : self::daysBetween($g['before'], $nulls[$k]['day']);
                if ($nulls[$k]['amount'] === $g['missing'] && $away <= self::MISDATED_DAYS) {
                    $chain['gaps'][$i] = ['kind' => 'misdated', 'line' => $nulls[$k]['day']] + $g;
                    $nulls[$k]['used'] = true;
                    unset($between[$n]);
                    break;
                }
            }
        }
        $byDay = [];
        foreach ($between as $k) {
            $byDay[$nulls[$k]['day']] = ($byDay[$nulls[$k]['day']] ?? 0) + $nulls[$k]['amount'];
            $nulls[$k]['used'] = true;
        }
        foreach (array_unique([$first, $lastDay]) as $day) {
            $twins = self::twins($nulls, self::within($nulls, $day, $day), $chain['amounts'][$day] ?? []);
            foreach ($twins as $k) {
                $byDay[$day] = ($byDay[$day] ?? 0) + $nulls[$k]['amount'];
                $nulls[$k]['used'] = true;
            }
        }
        foreach (array_filter($byDay) as $day => $sum) {
            $chain['gaps'][] = self::raw($day, $day, -$sum, $day, $day, 'extra');
        }

        return ['nulls' => $nulls] + $chain;
    }

    /**
     * The lines among $keys whose amount matches one of a day's lines with a balance (each matched once): the same
     * payment, likely, from a second export.
     *
     * @param  list<int>  $keys
     * @param  list<int>  $amounts  the day's lines with a balance
     * @return list<int>
     */
    private static function twins(array $nulls, array $keys, array $amounts): array
    {
        $pool = array_count_values(array_map('strval', $amounts));
        $out = [];
        foreach ($keys as $k) {
            $a = (string) $nulls[$k]['amount'];
            if (($pool[$a] ?? 0) > 0) {
                $pool[$a]--;
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * Between two statement balances, the change must equal what's imported in between. For a card the balance is
     * what you owe, so purchases (money out) push it up.
     *
     * @param  list<array{id:int, posted:string, amount:int}>  $lines
     * @param  list<array{as_of:string, balance:int}>  $entries
     */
    private static function statementBalances(array $lines, array $entries, bool $card, string $currency): array
    {
        $gaps = [];
        for ($i = 1; $i < count($entries); $i++) {
            [$a, $b] = [$entries[$i - 1], $entries[$i]];
            // After the first statement's day, up to and including the second's.
            $sum = array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['posted'] > $a['as_of'] && $l['posted'] <= $b['as_of'])));
            // The transactions that would have to be added for the two balances to agree.
            $needed = $card ? ($a['balance'] - $b['balance']) - $sum : ($b['balance'] - $a['balance']) - $sum;
            if ($needed !== 0) {
                $gaps[] = self::gap(self::raw(self::day($a['as_of'], 1), $b['as_of'], $needed, $a['as_of'], $b['as_of']), $currency);
            }
        }
        $lastEntry = $entries[count($entries) - 1];

        return ['checked_to' => $lastEntry['as_of'], 'balance_pence' => $lastEntry['balance'], 'gaps' => $gaps];
    }

    /**
     * The lines with no balance dated from $from to $to, not yet matched to anything.
     *
     * @return list<int> their indexes
     */
    private static function within(array $nulls, string $from, string $to): array
    {
        $out = [];
        foreach ($nulls as $k => $n) {
            if (! $n['used'] && $n['day'] >= $from && $n['day'] <= $to) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * Some of $keys whose amounts add up to $target exactly (all of them if they do), or null.
     *
     * @param  list<int>  $keys
     * @param  list<int>  $amounts
     * @return list<int>|null
     */
    private static function subset(array $keys, array $amounts, int $target): ?array
    {
        if (! $keys) {
            return null;
        }
        if (array_sum($amounts) === $target) {
            return $keys;
        }
        $n = count($keys);
        if ($n > self::SUBSET_MAX) {
            return null;
        }
        for ($mask = 1; $mask < (1 << $n) - 1; $mask++) {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $sum += $amounts[$i];
                }
            }
            if ($sum === $target) {
                return array_values(array_filter($keys, fn ($k, $i) => (bool) ($mask & (1 << $i)), ARRAY_FILTER_USE_BOTH));
            }
        }

        return null;
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

    private static function day(string $day, int $add): string
    {
        return CarbonImmutable::parse($day)->addDays($add)->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
    }

    /** A gap as it's worked out: the days it covers, between the two days whose balances it's between. */
    private static function raw(string $from, string $to, int $missing, string $after, string $before, string $kind = 'missing'): array
    {
        return ['from' => $from, 'to' => $to, 'missing' => $missing, 'after' => $after, 'before' => $before, 'kind' => $kind];
    }

    /**
     * A gap as it's shown: the money in the account's currency, and the days. 'missing' is money the bank's figures
     * have that Expend doesn't; 'extra' is money in Expend the bank's figures don't have; 'misdated' is money missing
     * with a line of the same amount, dated a few days away, that may be it ('line_on' is that line's date).
     */
    private static function gap(array $g, string $currency): array
    {
        ['from' => $from, 'to' => $to, 'missing' => $missing, 'after' => $after, 'before' => $before, 'kind' => $kind] = $g;
        $date = fn (string $d) => CarbonImmutable::parse($d)->format('j M Y');
        $when = $from > $to
            ? 'between '.$date($after).' and '.$date($before)
            : ($from === $to ? 'on '.$date($from) : 'from '.$date($from).' to '.$date($to));
        $amount = Money::formatMinor(abs($missing), $currency);
        $text = match ($kind) {
            'extra' => $amount.' of '.($missing > 0 ? 'money out' : 'money in').' is in Expend but not in the bank’s figures '.$when.'. It may have been imported twice.',
            'misdated' => $amount.' of '.($missing > 0 ? 'money in' : 'money out').' isn’t in Expend '.$when.', but a line for the same amount dated '.$date($g['line'])
                .' isn’t in the bank’s figures on that day. It may be the same payment, dated differently by the export it came from.',
            default => $amount.' of '.($missing > 0 ? 'money in' : 'money out').' isn’t in Expend '.$when.'.',
        };

        return ['from' => min($from, $to), 'to' => max($from, $to), 'missing_pence' => $missing, 'kind' => $kind, 'text' => $text]
            + (isset($g['line']) ? ['line_on' => $g['line']] : []);
    }

    private static function text(Account $account, string $method, array $out, int $entries, string $currency): string
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

        return count($out['gaps']).' '.(count($out['gaps']) === 1 ? 'gap' : 'gaps').' with the bank’s figures ('.Money::formatMinor($total, $currency).' in all). A statement or some rows may be missing.';
    }
}
