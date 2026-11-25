<?php

namespace App\Domain\Clients;

use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\CostUnits;
use App\Domain\Income\IncomeClient;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\TaxParameters;
use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Costs and profit per client, and per end client, by tax year. Read only, and never used for tax: it splits the
 * year's own figures (ReturnSummary) by client, so every client's profit plus 'Not for any client' is the year's net
 * profit, to the penny.
 *
 * It counts confirmed lines by allocations.tax_year (as ReturnSummary does), each by its worked-out client (income
 * v3's IncomeClient: its invoice's client, else its job's, else its own; merges resolved, so merged clients count as
 * one):
 *  - made from them: business income;
 *  - spent for them: allowable business costs; fees or commission taken off their payments (income v3's deductions)
 *    are shown as fees taken off, and disallowable spending (a fine) apart, as the return leaves it out;
 *  - mileage: the year's mileage claim (MileageCalculator) for each job, under the job's client;
 *  - profit = made − spent − fees taken off − mileage.
 * Overheads with no client are 'Not for any client'.
 *
 * Shown alongside, never counted: what was charged back on invoices (ticked cost, mileage and travel-fee lines on
 * issued invoices, by issue date: counted as income when paid), what's still to charge now (ChargeStatus), what was
 * invoiced, what's outstanding and what was closed short (InvoicePayments), and income still waiting in the Inbox.
 * For years before the cash basis was the default (2021-22 to 2023-24) it says the figures are counted when paid.
 */
final class ClientFigures
{
    public const FOOTNOTE = 'Confirmed records only, counted when paid.';

    /** Invoice lines that charge a cost back to the client. */
    public const CHARGED_BACK = ['cost', 'mileage', 'tfee'];

    /**
     * Every client's figures for a tax year, the row for 'Not for any client', and the totals (which are the year's
     * ReturnSummary figures).
     *
     * @return array{tax_year:string, cash_basis_note:?string, locked:bool, footnote:string, clients:array<int, array<string, mixed>>, none:array<string, mixed>, totals:array<string, mixed>}
     */
    public static function forYear(string $label): array
    {
        $rows = [];
        $none = self::blank();
        $row = function (?int $client) use (&$rows, &$none): array {
            return $client === null ? $none : ($rows[$client] ?? self::blank());
        };
        $put = function (?int $client, array $r) use (&$rows, &$none): void {
            $client === null ? $none = $r : $rows[$client] = $r;
        };

        $allowable = Category::query()->get(['id', 'is_allowable'])->mapWithKeys(fn (Category $c) => [(int) $c->id => (bool) $c->is_allowable])->all();
        foreach (self::lines($label) as $l) {
            $client = $l->{IncomeClient::COLUMN} === null ? null : (int) $l->{IncomeClient::COLUMN};
            $r = $row($client);
            $amount = (int) $l->amount_pence;
            if ($l->confirmed_at === null) {
                if ($l->kind === 'business_income') {
                    $r['waiting_pence'] += $amount;
                    $put($client, $r);
                }

                continue;
            }
            if ($l->kind === 'business_income') {
                $r['made_pence'] += $amount;
            } elseif (! ($allowable[(int) $l->category_id] ?? true)) {
                $r['disallowable_pence'] += -$amount;
            } elseif ((int) $l->source_pence > 0 && $amount < 0) {
                $r['fees_taken_pence'] += -$amount; // a fee or commission taken off a payment
            } else {
                $r['spent_pence'] += -$amount;
            }
            $put($client, $r);
        }

        // Mileage, under each job's client; trips with no job (or a job with no client) are overheads.
        $mileage = MileageCalculator::forYear($label);
        $jobClients = WorkJob::query()->whereIn('id', array_keys($mileage['by_job']) ?: [0])->pluck('client_id', 'id');
        $resolved = ClientIds::resolveMany($jobClients->values());
        $mapped = 0;
        foreach ($mileage['by_job'] as $job => $pence) {
            $client = isset($jobClients[$job]) && $jobClients[$job] !== null ? ($resolved[(int) $jobClients[$job]] ?? (int) $jobClients[$job]) : null;
            $r = $row($client);
            $r['mileage_pence'] += (int) $pence;
            $put($client, $r);
            $mapped += (int) $pence;
        }
        $none['mileage_pence'] += (int) $mileage['total_pence'] - $mapped;

        foreach (self::chargedBack($label) as $c) {
            $client = $c->client === null ? null : (int) $c->client;
            $r = $row($client);
            $r['charged_back'][strtoupper((string) $c->currency)] = ($r['charged_back'][strtoupper((string) $c->currency)] ?? 0) + (int) $c->total;
            $put($client, $r);
        }
        foreach (self::invoiced($label) as $client => $money) {
            $put($client ?: null, array_merge($row($client ?: null), $money));
        }

        $rows = array_map(fn (array $r) => self::profit($r), $rows);
        $none = self::profit($none);
        ksort($rows);
        $totals = self::blank();
        foreach ([...array_values($rows), $none] as $r) {
            foreach (['made_pence', 'spent_pence', 'fees_taken_pence', 'disallowable_pence', 'mileage_pence', 'waiting_pence'] as $k) {
                $totals[$k] += $r[$k];
            }
        }

        return [
            'tax_year' => $label, 'cash_basis_note' => self::cashBasisNote($label), 'locked' => (bool) TaxYear::query()->where('label', $label)->first()?->isLocked(),
            'footnote' => self::FOOTNOTE, 'clients' => $rows, 'none' => $none, 'totals' => self::profit($totals),
        ];
    }

    /**
     * One client's figures for a year (a client merged into another gives the one it went into), with what's still to
     * charge now and, for an agency, its rows by end client.
     *
     * @return array<string, mixed>
     */
    public static function forClient(Client|int $client, string $label): array
    {
        $id = ClientIds::resolve($client instanceof Client ? (int) $client->id : $client);
        $year = self::forYear($label);

        return ($year['clients'][$id] ?? self::profit(self::blank())) + [
            'client_id' => $id, 'tax_year' => $label, 'cash_basis_note' => $year['cash_basis_note'], 'locked' => $year['locked'], 'footnote' => self::FOOTNOTE,
            'still_to_charge' => $id === null ? self::stillBlank() : self::stillToCharge($id),
            'by_end_client' => $id === null ? [] : self::byEndClient($id, $label),
        ];
    }

    /**
     * What's still to charge a client now (costs owed, on their jobs or for them with no job; mileage at the
     * arrangement's rate): in pounds, any other currency apart, and how many. A cost left unticked until you look (maybe
     * the same as another, someone else paid, a check, no price) isn't counted: only what a draft would tick, and a
     * difference on a cost charged before.
     *
     * @return array{pence:int, other:array<string, int>, count:int}
     */
    public static function stillToCharge(Client|int $client): array
    {
        $units = collect(CostUnits::forClient($client))->keyBy('ref');
        $out = self::stillBlank();
        if ($units->isEmpty()) {
            return $out;
        }
        $terms = ChargeTerms::forJobs(WorkJob::query()->whereIn('id', $units->pluck('job_id')->filter()->unique()->values()->all() ?: [0])->get());
        foreach (ChargeStatus::for($units->values()->all()) as $ref => $s) {
            if (! in_array($s['status'], ['owed', 'owed_late'], true) || $s['remaining'] === null || (! $s['tick'] && ! $s['difference'])) {
                continue;
            }
            $u = $units[$ref];
            if ($u['kind'] === 'trip') {
                $rate = $terms[(int) $u['job_id']]['mileage']['rate_minor'] ?? null;
                $minor = $rate === null ? 0 : InvoiceService::lineAmount((string) $s['remaining'], (int) $rate);
            } else {
                $minor = ($u['kind'] === 'credit' ? -1 : 1) * (int) $s['remaining'];
            }
            $u['currency'] === 'GBP' ? $out['pence'] += $minor : $out['other'][$u['currency']] = ($out['other'][$u['currency']] ?? 0) + $minor;
            $out['count']++;
        }

        return $out;
    }

    /**
     * A client's figures for a year split by who the work was for: each end client, '<client> direct' (jobs with no
     * end client), and their lines with no job apart. A row whose costs were charged back on another job's invoice
     * says so ('Includes £41.60 recharged on another job's invoice').
     *
     * @return list<array{key:string, end_client_id:?int, name:string, made_pence:int, spent_pence:int, fees_taken_pence:int, disallowable_pence:int, mileage_pence:int, profit_pence:int, jobs:int, note:?string}>
     */
    public static function byEndClient(Client|int $client, string $label): array
    {
        $id = ClientIds::resolve($client instanceof Client ? (int) $client->id : $client);
        $name = (string) Client::query()->whereKey($id)->value('name');
        $ids = ClientIds::withMerged((int) $id);
        $jobs = WorkJob::query()->whereIn('client_id', $ids)->get(['id', 'end_client_id']);
        $endOf = $jobs->pluck('end_client_id', 'id');
        $ends = ClientIds::resolveMany($jobs->pluck('end_client_id'));
        $groupOf = fn (?int $job) => $job === null ? 'client_only'
            : (($e = $endOf[$job] ?? null) === null ? 'direct' : 'end:'.($ends[(int) $e] ?? (int) $e));
        $rows = [];
        $row = function (string $key) use (&$rows): array {
            return $rows[$key] ?? self::blank() + ['jobs' => []];
        };
        $allowable = Category::query()->get(['id', 'is_allowable'])->mapWithKeys(fn (Category $c) => [(int) $c->id => (bool) $c->is_allowable])->all();
        foreach (self::lines($label, (int) $id) as $l) {
            if ($l->confirmed_at === null) {
                continue;
            }
            $job = $l->job_id === null ? null : (int) $l->job_id;
            $key = $groupOf($job);
            $r = $row($key);
            $amount = (int) $l->amount_pence;
            if ($l->kind === 'business_income') {
                $r['made_pence'] += $amount;
            } elseif (! ($allowable[(int) $l->category_id] ?? true)) {
                $r['disallowable_pence'] += -$amount;
            } elseif ((int) $l->source_pence > 0 && $amount < 0) {
                $r['fees_taken_pence'] += -$amount;
            } else {
                $r['spent_pence'] += -$amount;
            }
            if ($job !== null) {
                $r['jobs'][$job] = true;
            }
            $rows[$key] = $r;
        }
        foreach (MileageCalculator::forYear($label)['by_job'] as $job => $pence) {
            if ($jobs->contains('id', (int) $job)) {
                $key = $groupOf((int) $job);
                $r = $row($key);
                $r['mileage_pence'] += (int) $pence;
                $r['jobs'][(int) $job] = true;
                $rows[$key] = $r;
            }
        }
        // Costs of a group's jobs charged back on another job's invoice, issued in the year.
        [$from, $to] = self::range($label);
        $elsewhere = DB::table('invoice_lines')->join('invoices as cf_inv', 'cf_inv.id', '=', 'invoice_lines.invoice_id')
            ->whereIn('cf_inv.status', InvoicePayments::PAYABLE)->whereBetween('cf_inv.issue_date', [$from, $to])
            ->where('invoice_lines.included', true)->whereIn('invoice_lines.source', self::CHARGED_BACK)
            ->whereIn('invoice_lines.source_job_id', $jobs->pluck('id')->all() ?: [0])
            ->where(fn ($q) => $q->whereNull('cf_inv.work_job_id')->orWhereColumn('cf_inv.work_job_id', '!=', 'invoice_lines.source_job_id'))
            ->get(['invoice_lines.source_job_id', 'invoice_lines.amount_minor', 'cf_inv.currency']);
        $notes = [];
        foreach ($elsewhere as $l) {
            $key = $groupOf((int) $l->source_job_id);
            $notes[$key][strtoupper((string) $l->currency)] = ($notes[$key][strtoupper((string) $l->currency)] ?? 0) + (int) $l->amount_minor;
        }
        $names = Client::query()->whereIn('id', array_values($ends) ?: [0])->pluck('name', 'id');
        $out = [];
        foreach ($rows as $key => $r) {
            $r = self::profit($r);
            $end = str_starts_with($key, 'end:') ? (int) substr($key, 4) : null;
            $note = isset($notes[$key]) ? 'Includes '.collect($notes[$key])->map(fn ($minor, $cur) => Money::format($minor, $cur, false))->implode(' and ').' recharged on another job’s invoice' : null;
            $out[] = ['key' => $key, 'end_client_id' => $end,
                'name' => match (true) {
                    $key === 'client_only' => 'Not for a job',
                    $key === 'direct' => $name.' direct',
                    default => (string) ($names[$end] ?? 'Unknown client'),
                },
                'made_pence' => $r['made_pence'], 'spent_pence' => $r['spent_pence'], 'fees_taken_pence' => $r['fees_taken_pence'], 'disallowable_pence' => $r['disallowable_pence'],
                'mileage_pence' => $r['mileage_pence'], 'profit_pence' => $r['profit_pence'], 'jobs' => count($r['jobs']), 'note' => $note];
        }
        usort($out, fn ($a, $b) => [str_starts_with($a['key'], 'end:') ? 0 : ($a['key'] === 'direct' ? 1 : 2), $a['name']] <=> [str_starts_with($b['key'], 'end:') ? 0 : ($b['key'] === 'direct' ? 1 : 2), $b['name']]);

        return $out;
    }

    /** For a year whose default wasn't the cash basis (2021-22 to 2023-24), what the client page says. */
    public static function cashBasisNote(string $label): ?string
    {
        $params = TaxParameters::for($label);
        if ($params === null || ($params['cash_basis_default'] ?? true) !== false) {
            return null;
        }

        return 'Counted when paid, as the cash basis does. If you didn’t use the cash basis for '.$label.', ask an accountant.';
    }

    /**
     * The year's ledger lines of income and business costs, each with its worked-out client (merges resolved), its job
     * (its own, else its invoice's) and what its payment or expense was (to tell a fee taken off from a cost). Voided
     * lines are left out, as everywhere.
     *
     * @return Collection<int, object>
     */
    private static function lines(string $label, ?int $client = null): Collection
    {
        $query = DB::table('allocations')
            ->select(['allocations.kind', 'allocations.category_id', 'allocations.amount_pence', 'allocations.confirmed_at'])
            ->whereNull('allocations.voided_at')->where('allocations.tax_year', $label)->whereIn('allocations.kind', ['business_income', 'business_expense']);
        $query = IncomeClient::scope($query)
            ->leftJoin('bank_transactions as cf_tx', 'cf_tx.id', '=', 'allocations.bank_transaction_id')
            ->leftJoin('manual_expenses as cf_me', 'cf_me.id', '=', 'allocations.manual_expense_id')
            ->addSelect(DB::raw('COALESCE(cf_tx.amount_pence, cf_me.amount_pence) as source_pence'))
            ->addSelect(DB::raw('COALESCE(allocations.work_job_id, ic_inv.work_job_id) as job_id'));
        if ($client !== null) {
            $query->whereRaw(IncomeClient::expression().' = ?', [$client]);
        }

        return $query->orderBy('allocations.id')->get();
    }

    /** @return Collection<int, object{client:?int, currency:string, total:int}> */
    private static function chargedBack(string $label): Collection
    {
        [$from, $to] = self::range($label);
        $client = 'COALESCE(cf_cm.merged_into_id, cf_inv.client_id, cf_job.client_id)';

        return DB::table('invoice_lines')->join('invoices as cf_inv', 'cf_inv.id', '=', 'invoice_lines.invoice_id')
            ->leftJoin('work_jobs as cf_job', 'cf_job.id', '=', 'cf_inv.work_job_id')
            ->leftJoin('clients as cf_cm', 'cf_cm.id', '=', DB::raw('COALESCE(cf_inv.client_id, cf_job.client_id)'))
            ->whereIn('cf_inv.status', InvoicePayments::PAYABLE)->whereBetween('cf_inv.issue_date', [$from, $to])
            ->where('invoice_lines.included', true)->whereIn('invoice_lines.source', self::CHARGED_BACK)
            ->selectRaw($client.' as client, cf_inv.currency as currency, SUM(invoice_lines.amount_minor) as total')
            ->groupByRaw($client.', cf_inv.currency')->get();
    }

    /**
     * What was invoiced to each client in the year (issued, by issue date), what's outstanding and what was closed
     * short, by currency (InvoicePayments).
     *
     * @return array<int, array{invoiced:array<string, int>, outstanding:array<string, int>, closed:array<string, int>}> by client (0: none)
     */
    private static function invoiced(string $label): array
    {
        [$from, $to] = self::range($label);
        $invoices = Invoice::query()->whereIn('status', InvoicePayments::PAYABLE)->whereBetween('issue_date', [$from, $to])->orderBy('id')->get();
        if ($invoices->isEmpty()) {
            return [];
        }
        $jobClients = WorkJob::query()->whereIn('id', $invoices->pluck('work_job_id')->filter()->unique()->values()->all() ?: [0])->pluck('client_id', 'id');
        $raw = $invoices->mapWithKeys(fn (Invoice $i) => [$i->id => $i->client_id ?? ($i->work_job_id ? ($jobClients[$i->work_job_id] ?? null) : null)]);
        $resolved = ClientIds::resolveMany($raw->values());
        $received = InvoicePayments::received($invoices);
        $out = [];
        foreach ($invoices as $i) {
            $client = $raw[$i->id] === null ? 0 : ($resolved[(int) $raw[$i->id]] ?? (int) $raw[$i->id]);
            $cur = strtoupper($i->currency ?: 'GBP');
            $r = $received[$i->id] ?? InvoicePayments::EMPTY;
            $out[$client] ??= ['invoiced' => [], 'outstanding' => [], 'closed' => []];
            $out[$client]['invoiced'][$cur] = ($out[$client]['invoiced'][$cur] ?? 0) + (int) $i->total_minor;
            $out[$client]['outstanding'][$cur] = ($out[$client]['outstanding'][$cur] ?? 0) + (int) $r['outstanding'];
            $out[$client]['closed'][$cur] = ($out[$client]['closed'][$cur] ?? 0) + (int) $r['closed'];
        }

        return $out;
    }

    /** @return array{0:string, 1:string} */
    private static function range(string $label): array
    {
        $year = UkTaxYear::fromLabel($label);

        return [$year->startsOn()->format('Y-m-d'), $year->endsOn()->format('Y-m-d')];
    }

    private static function blank(): array
    {
        return ['made_pence' => 0, 'spent_pence' => 0, 'fees_taken_pence' => 0, 'disallowable_pence' => 0, 'mileage_pence' => 0, 'profit_pence' => 0,
            'waiting_pence' => 0, 'charged_back' => [], 'invoiced' => [], 'outstanding' => [], 'closed' => []];
    }

    private static function stillBlank(): array
    {
        return ['pence' => 0, 'other' => [], 'count' => 0];
    }

    private static function profit(array $r): array
    {
        $r['profit_pence'] = $r['made_pence'] - $r['spent_pence'] - $r['fees_taken_pence'] - $r['mileage_pence'];

        return $r;
    }
}
