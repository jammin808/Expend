<?php

namespace App\Domain\Tax;

use App\Domain\Audit\AuditLog;
use App\Domain\Export\YearExporter;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Support\Facades\File;

/**
 * Coverage grid and pre-filing checks for one tax year, and the snapshot kept when you lock it (lockSnapshot(): the
 * return's figures, a fresh spreadsheet export's sha256, the open findings and the checks, in the tax_year.locked
 * event's after), which the year page shows and verifies (verifyLock()) and year.locked_drift compares with now.
 */
final class YearChecks
{
    /** The snapshot's own version: raised whenever what it holds changes. */
    public const SNAPSHOT_VERSION = 1;

    /** The return's figures a snapshot keeps, and what each is called. */
    public const FIGURE_NAMES = ['turnover_pence' => 'turnover', 'other_income_pence' => 'other business income', 'allowable_expenses_pence' => 'allowable expenses',
        'disallowable_expenses_pence' => 'disallowable expenses', 'mileage_pence' => 'mileage', 'net_profit_pence' => 'profit'];

    /** Where the spreadsheet exported at each lock is kept (under storage/app/private), so the snapshot's sha256 can be checked against it. */
    public const LOCK_EXPORTS = 'locks';

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
            // A main account that has never had lines of its own while its pockets have (a Revolut Business
            // statement whose accounts each went into one of their own): its statements are its pockets'.
            if ($txs->isEmpty() && ! BankTransaction::query()->where('account_id', $account->id)->exists()
                && BankTransaction::query()->whereIn('account_id', Account::query()->where('parent_account_id', $account->id)->select('id'))->exists()) {
                continue;
            }

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

    /** The keys of the checks that are failing (status 'fail'), which locking needs a reason for. @return list<string> */
    public static function failing(array $checks): array
    {
        return array_values(array_map(fn ($c) => $c['key'], array_filter($checks, fn ($c) => ($c['status'] ?? null) === 'fail')));
    }

    /** The return's figures a snapshot keeps, from ReturnSummary::for(). @return array<string, int> */
    public static function figures(array $summary): array
    {
        return array_map(fn ($k) => (int) ($summary[$k] ?? 0), array_combine(array_keys(self::FIGURE_NAMES), array_keys(self::FIGURE_NAMES)));
    }

    /**
     * The figures that differ between two sets of figures(), each with what it was and is.
     *
     * @return array<string, array{then:int, now:int}>
     */
    public static function figuresDiff(array $then, array $now): array
    {
        $out = [];
        foreach (array_keys(self::FIGURE_NAMES) as $k) {
            if (array_key_exists($k, $then) && (int) $then[$k] !== (int) ($now[$k] ?? 0)) {
                $out[$k] = ['then' => (int) $then[$k], 'now' => (int) ($now[$k] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * What's kept when you lock a year (docs/intelligence-cycle.md section 4.6): the return's figures as they are
     * (ReturnSummary::for), the sha256 of a spreadsheet exported now (YearExporter::write, kept under storage so it can
     * be checked and downloaded as it was), the open findings (how many, those about this year, and a digest of them
     * all: FindingsRegistry::openDigest) and the pre-filing checks' results.
     *
     * @return array{snapshot:array, failing:list<string>}
     */
    public static function lockSnapshot(string $label): array
    {
        $summary = ReturnSummary::for($label);
        $checks = self::checks($label, $summary);
        $dir = storage_path('app/private/'.self::LOCK_EXPORTS);
        File::ensureDirectoryExists($dir, 0700);
        $file = 'expense-ledger-'.$label.'-locked-'.now()->format('Ymd-His').'.xlsx';
        YearExporter::write($label, $dir.'/'.$file);
        $open = FindingsRegistry::openDigest();

        return ['failing' => self::failing($checks), 'snapshot' => [
            'version' => self::SNAPSHOT_VERSION,
            'figures' => self::figures($summary),
            'pending' => ['unreviewed' => (int) $summary['pending']['unreviewed_count'], 'suggested' => (int) $summary['pending']['suggested_count'],
                'manual_unreviewed' => (int) $summary['pending']['manual_unreviewed_count']],
            'export' => ['sha256' => hash_file('sha256', $dir.'/'.$file), 'file' => self::LOCK_EXPORTS.'/'.$file],
            'findings' => ['open' => $open['open'], 'digest' => $open['digest'], 'this_year' => FindingsRegistry::openKeys('year:'.$label)],
            'checks' => collect($checks)->mapWithKeys(fn ($c) => [$c['key'] => $c['status']])->all(),
        ]];
    }

    /** The latest time the year was locked, with its snapshot when it has one. */
    public static function lastLock(TaxYear $year): ?AuditEvent
    {
        return AuditEvent::query()->where('action', 'tax_year.locked')->where('subject_type', 'TaxYear')->where('subject_id', $year->id)->orderByDesc('id')->first();
    }

    /**
     * The year's lock snapshot as the year page shows it, or null when it was never locked with one.
     *
     * @return array{at:string, actor:string, reason:?string, event:int, figures:array<string,int>, pending:array, export_sha256:?string, findings:array, checks:array<string,string>}|null
     */
    public static function lockShown(TaxYear $year): ?array
    {
        $event = self::lastLock($year);
        $s = $event?->after['snapshot'] ?? null;
        if (! is_array($s)) {
            return null;
        }

        return ['at' => $event->occurred_at->toIso8601String(), 'actor' => $event->actor, 'reason' => $event->reason, 'event' => $event->id, 'figures' => $s['figures'] ?? [],
            'pending' => $s['pending'] ?? [], 'export_sha256' => $s['export']['sha256'] ?? null, 'findings' => ['open' => $s['findings']['open'] ?? null,
                'this_year' => count($s['findings']['this_year'] ?? [])], 'checks' => $s['checks'] ?? [], 'locked' => $year->isLocked()];
    }

    /**
     * Check the year's lock snapshot: its audit event still hashes as recorded and the audit log is whole
     * (AuditLog::verify()), the figures are the same now, the spreadsheet kept then is there and unchanged, and how the
     * open findings compare. Only reads.
     *
     * @return array{ok:bool, event_ok:bool, chain_ok:bool, figures:array<string, array{then:int, now:int}>, export:string, findings:array{then:?int, now:int, same:bool}}|null
     */
    public static function verifyLock(TaxYear $year): ?array
    {
        $event = self::lastLock($year);
        $s = $event?->after['snapshot'] ?? null;
        if (! is_array($s)) {
            return null;
        }
        $eventOk = AuditLog::hashFor($event) === $event->hash;
        $chain = AuditLog::verify();
        $figures = self::figuresDiff($s['figures'] ?? [], self::figures(ReturnSummary::for($year->label)));
        $path = isset($s['export']['file']) ? storage_path('app/private/'.$s['export']['file']) : null;
        $export = $path === null || ! is_file($path) ? 'missing' : (hash_file('sha256', $path) === ($s['export']['sha256'] ?? null) ? 'same' : 'changed');
        $open = FindingsRegistry::openDigest();

        return ['ok' => $eventOk && $chain['ok'] && $figures === [] && $export === 'same', 'event_ok' => $eventOk, 'chain_ok' => $chain['ok'], 'figures' => $figures,
            'export' => $export, 'findings' => ['then' => $s['findings']['open'] ?? null, 'now' => $open['open'], 'same' => ($s['findings']['digest'] ?? null) === $open['digest']]];
    }

    /** The spreadsheet kept when the year was last locked (its path), or null when there's none. */
    public static function lockExport(TaxYear $year): ?string
    {
        $file = self::lastLock($year)?->after['snapshot']['export']['file'] ?? null;
        $path = is_string($file) && preg_match('#^'.self::LOCK_EXPORTS.'/[A-Za-z0-9._-]+\.xlsx$#', $file) ? storage_path('app/private/'.$file) : null;

        return $path !== null && is_file($path) ? $path : null;
    }
}
