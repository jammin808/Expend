<?php

namespace App\Domain\Charges;

use App\Domain\Clients\ClientIds;
use App\Domain\Income\PayerAliases;
use App\Domain\Intel\Detectors\DupeCrossSource;
use App\Domain\Jobs\JobCreator;
use App\Domain\Matching\MerchantKey;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\JobDay;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a job's (or a client's) costs are, worked out from your records and never stored: each cost you may charge the
 * client for, with how it's cited (CostRef), what it is, what it cost and anything to check first. Read only.
 *
 * What makes a cost:
 *  - money out and hand-entered expenses only: a bank line or a manual expense with business costs on the job
 *    (tx:{id}#j{job}, exp:{id}#j{job}), for minus the sum of those lines, cited by the payment (its ledger lines are
 *    written afresh on every decision). A cost whose lines are in different kinds (a hotel bill split into room and
 *    meals) keeps its parts.
 *  - on money in, only a refund: business costs coming back on a payment with no business income (a credit). Never
 *    the client paying you (a payer who pays only for the job's client, PayerAliases).
 *  - a booking with no payment yet (travel:{id}), at the booking's price: only when the job has no ledger cost of the
 *    same kind near its dates (TravelMatcher::findPayment's window); otherwise it's listed as maybe the same cost.
 *  - a mileage trip (trip:{id}), counted in miles: driving is priced only from trips.
 *
 * What never makes a cost: income v3's deductions (a fee or commission taken off a payment, beside the income), bank
 * charges, interest, bad debts and depreciation, mileage booked as a payment (mileagePayments() lists those to check),
 * and lines on held or undone statements.
 *
 * Costs for a client with no job (tx:{id}#c{client}): job-less costs on a line that names the client, or on a line
 * that names none when you marked it to recharge to the client (cost_charges: a locked year's cost, or a payment not
 * yet confirmed). A client merged into another counts as the one it went into.
 *
 * Two records of one cost count once: a booking and the payment it's linked to (the booking's ref becomes an alias of
 * the payment's), and two you said are the same (cost_charges pair 'same'). Ones that may be the same (a booking and a
 * ledger cost of its kind, or DupeCrossSource's hand-entered expense and card line, the one recorded second) are
 * flagged maybe_same until you say, with pair_ref naming the other; ChargeStatus leaves the other unticked too once
 * the flagged one is charged.
 *
 * Money out to the job's own client (one PayerAliases says pays only for that client: their commission or a fee) is
 * listed to check, unticked: it isn't charged back to them. A cost paid in another currency is that payment's share in
 * its own currency, shared out so a payment split between jobs adds up exactly.
 *
 * Each cost: ref, aliases, subject (the record behind it, for charging once across jobs), kind (cost, credit,
 * booking, trip or work), class, parts, date, description, words (for CostWords::couldHold), amount_pence (null when
 * it isn't known in pounds), currency and amount_minor (in hundredths of that currency, as invoices count),
 * quantity and unit (miles, days, hours), unit_price_minor (work), job_id, client_id (the paying client), tax_year,
 * confirmed, locked, flags (flag => what to say) and pair_ref (the other cost it may be).
 */
final class CostUnits
{
    /** How long before a booking's first day, and after its last, a ledger cost may be its payment (findPayment). */
    public const BEFORE_DAYS = 3;

    public const AFTER_DAYS = 10;

    /** @return list<array<string, mixed>> */
    public static function forJob(WorkJob $job): array
    {
        return self::forJobs([$job])[(int) $job->id] ?? [];
    }

    /**
     * Several jobs' costs at once.
     *
     * @param  iterable<WorkJob>  $jobs
     * @param  array<int, array>|null  $terms  ChargeTerms::forJobs() for these jobs, when the caller has it already
     * @return array<int, list<array<string, mixed>>> by job id
     */
    public static function forJobs(iterable $jobs, ?array $terms = null): array
    {
        $jobs = collect($jobs)->keyBy('id');
        if ($jobs->isEmpty()) {
            return [];
        }
        $ids = $jobs->keys()->map(fn ($id) => (int) $id)->all();
        $clients = ClientIds::resolveMany($jobs->pluck('client_id'));
        $lines = collect();
        foreach (array_chunk($ids, 500) as $chunk) {
            $lines = $lines->concat(Allocation::query()->whereIn('work_job_id', $chunk)->where('kind', 'business_expense')->orderBy('id')->get());
        }
        $ledger = self::ledger($lines);
        $out = array_fill_keys($ids, []);
        foreach ($lines->groupBy(fn (Allocation $a) => $a->work_job_id.'|'.($a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id)) as $group) {
            $first = $group->first();
            $job = $jobs[$first->work_job_id];
            $client = $job->client_id === null ? null : ($clients[(int) $job->client_id] ?? null);
            $unit = self::ledgerUnit($ledger, $first, $group, (int) $job->id, null, $client);
            if ($unit !== null) {
                $out[(int) $job->id][] = $unit;
            }
        }
        $pairs = CostCharges::pairs(collect($out)->flatten(1)->pluck('ref')->all());
        $bookings = self::bookings($ids);
        $trips = MileageTrip::query()->whereIn('work_job_id', $ids ?: [0])->orderBy('trip_on')->orderBy('id')->get();
        $terms ??= ChargeTerms::forJobs($jobs);
        $fuelRules = null;
        $simplifiedOnly = function () use (&$fuelRules): bool {
            return $fuelRules ??= self::fuelRules();
        };
        foreach ($jobs as $id => $job) {
            $id = (int) $id;
            $client = $job->client_id === null ? null : ($clients[(int) $job->client_id] ?? null);
            $units = self::withBookings($out[$id], $bookings[$id] ?? collect(), $id, $client, $pairs);
            $units = self::dupes($units, $pairs, $ledger);
            $jobTrips = $trips->where('work_job_id', $id);
            $units = self::fuel($units, $jobTrips->isNotEmpty() && ($terms[$id]['mileage']['charge'] ?? null) === 'per_mile', $simplifiedOnly, $bookings[$id] ?? collect());
            foreach ($jobTrips as $trip) {
                $units[] = self::tripUnit($trip, $job, $client);
            }
            usort($units, fn (array $a, array $b) => [$a['date'] ?? '9999', $a['ref']] <=> [$b['date'] ?? '9999', $b['ref']]);
            $out[$id] = array_values($units);
        }

        return $out;
    }

    /**
     * A client's costs: those for the client with no job and, unless $withJobs is false, every cost of the jobs they
     * pay for (a client merged into this one counts as this one).
     *
     * @return list<array<string, mixed>>
     */
    public static function forClient(Client|int $client, bool $withJobs = true): array
    {
        $id = ClientIds::resolve($client instanceof Client ? (int) $client->id : $client);
        if ($id === null) {
            return [];
        }
        $ids = ClientIds::withMerged($id);
        // Job-less costs naming the client, and job-less costs naming none that you marked to recharge to them.
        $marked = CostCharge::query()->where('charge', 'recharge')->whereIn('client_id', $ids)->where('cost_ref', 'like', '%#c%')->pluck('cost_ref')
            ->filter(fn ($ref) => CostRef::valid($ref))->map(fn ($ref) => CostRef::parse($ref));
        $lines = Allocation::query()->where('kind', 'business_expense')->whereNull('work_job_id')
            ->where(function ($q) use ($ids, $marked) {
                $q->whereIn('client_id', $ids);
                foreach (['tx' => 'bank_transaction_id', 'exp' => 'manual_expense_id'] as $type => $column) {
                    $sources = $marked->where('type', $type)->pluck('id')->all();
                    if ($sources !== []) {
                        $q->orWhere(fn ($q) => $q->whereNull('client_id')->whereIn($column, $sources));
                    }
                }
            })->orderBy('id')->get();
        $ledger = self::ledger($lines);
        $units = [];
        foreach ($lines->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id) as $group) {
            $first = $group->first();
            // Lines naming another client on the same payment aren't this client's.
            $mine = $group->filter(fn (Allocation $a) => $a->client_id === null || in_array((int) $a->client_id, $ids, true));
            if ($mine->isEmpty()) {
                continue;
            }
            $unit = self::ledgerUnit($ledger, $first, $mine->values(), null, $id, $id);
            if ($unit === null) {
                continue;
            }
            // Its ref under the client's own id before a merge (the line's, or the one you marked it to recharge to).
            $raw = $mine->pluck('client_id')->filter()->map(fn ($c) => (int) $c)
                ->concat($marked->filter(fn (array $m) => $unit['subject'] === $m['type'].':'.$m['id'])->pluck('client'))
                ->unique()->reject(fn ($c) => $c === $id);
            $unit['aliases'] = array_values(array_unique([...$unit['aliases'], ...$raw->map(fn ($c) => self::ref($first, null, (int) $c))->all()]));
            $units[] = $unit;
        }
        if ($withJobs) {
            $jobs = WorkJob::query()->whereIn('client_id', $ids)->orderBy('starts_on')->orderBy('id')->get();
            foreach (self::forJobs($jobs) as $jobUnits) {
                array_push($units, ...$jobUnits);
            }
        }

        return $units;
    }

    /**
     * A job's own work, as lines are made of it: its fee (job:J#fee, one at fee_pence), or its days (job:J#days, the
     * working days up to the days agreed, at the day rate) or hours (job:J#hours, from its days' times; a day with no
     * times is flagged 'check'), and the days over the days agreed (job:J#extra, at the day rate, or the fee divided
     * by the days agreed, flagged 'check'). A cancelled job has only its cancellation fee (job:J#cancel), when it's
     * charged one. Counted by quantity. Travel days, a travel fee and meal allowances follow the arrangement: the draft
     * makes those.
     *
     * @return list<array<string, mixed>>
     */
    public static function work(WorkJob $job): array
    {
        $client = ClientIds::resolve($job->client_id === null ? null : (int) $job->client_id);
        $dates = CostWords::dates($job);
        $make = fn (string $part, string $qty, ?int $price, string $description, string $unit, array $flags = []) => [
            'ref' => CostRef::work((int) $job->id, $part), 'aliases' => [], 'subject' => CostRef::work((int) $job->id, $part), 'kind' => 'work', 'class' => null, 'parts' => [],
            'date' => $job->starts_on?->format('Y-m-d'), 'description' => $description, 'words' => [], 'merchant' => null, 'amount_pence' => null, 'currency' => 'GBP', 'amount_minor' => null,
            'quantity' => $qty, 'unit' => $unit, 'unit_price_minor' => $price, 'job_id' => (int) $job->id, 'client_id' => $client, 'tax_year' => null,
            'confirmed' => null, 'locked' => false, 'flags' => $flags, 'pair_ref' => null, 'part' => $part,
        ];
        if ($job->status === 'cancelled') {
            return $job->cancel_charge === 'fee' && (int) $job->cancel_fee_pence > 0
                ? [$make('cancel', '1.00', (int) $job->cancel_fee_pence, 'Cancellation fee: '.$job->title.' ('.$dates.')', 'each')] : [];
        }
        $worked = JobCreator::workingDays($job);
        $agreed = $job->days_agreed !== null ? (int) $job->days_agreed : null;
        $extra = $agreed !== null && $agreed > 0 && $worked > $agreed ? $worked - $agreed : 0;
        $dayRate = $job->rate_unit === 'day' && (int) $job->rate_pence > 0 ? (int) $job->rate_pence : null;
        $units = [];
        if ((int) $job->fee_pence > 0) {
            $units[] = $make('fee', '1.00', (int) $job->fee_pence, $job->title.' ('.$dates.')', 'each');
        } elseif ($dayRate !== null) {
            $units[] = $make('days', self::qty($agreed !== null && $agreed > 0 ? min($worked, $agreed) : $worked), $dayRate, $job->title.' ('.$dates.')', 'days');
        } elseif ($job->rate_unit === 'hour' && (int) $job->rate_pence > 0) {
            [$hours, $unknown] = self::hours($job);
            $flags = $unknown === [] ? [] : ['check' => 'Hours not known for '.implode(', ', array_map(fn ($d) => CarbonImmutable::parse($d)->format('j M'), $unknown)).': type them'];
            $units[] = $make('hours', $hours, (int) $job->rate_pence, $job->title.' ('.$dates.')', 'hours', $flags);
        }
        if ($extra > 0 && $units !== [] && $units[0]['part'] !== 'hours') {
            $price = $dayRate;
            $flags = [];
            if ($price === null) {
                $price = BigDecimal::of((int) $job->fee_pence)->dividedBy($agreed, 0, RoundingMode::HALF_UP)->toInt();
                $flags = ['check' => 'Priced at the fee for '.$agreed.' '.($agreed === 1 ? 'day' : 'days').' divided by '.$agreed.': check the day rate'];
            }
            $units[] = $make('extra', self::qty($extra), $price, $extra.' more '.($extra === 1 ? 'day' : 'days').' than agreed: '.$job->title, 'days', $flags);
        }

        return $units;
    }

    /**
     * What each subject cost in all (whichever jobs or client its costs are on, or none): a payment's or expense's
     * business costs (in hundredths of its currency), a booking's price, a trip's miles. Charge status measures what's
     * left to charge against it, so a payment charged once is never charged again when it's split or moved.
     *
     * @param  list<string>  $subjects
     * @return array<string, array{measure:string, currency:string, amount:int|string}>
     */
    public static function subjects(array $subjects): array
    {
        $by = collect($subjects)->unique()->groupBy(fn (string $s) => explode(':', $s)[0])->map(fn ($g) => $g->map(fn ($s) => (int) explode(':', $s)[1])->values()->all());
        $out = [];
        if (isset($by['tx']) || isset($by['exp'])) {
            $lines = Allocation::query()->where('kind', 'business_expense')
                ->where(fn ($q) => $q->whereIn('bank_transaction_id', $by['tx'] ?? [0])->orWhereIn('manual_expense_id', $by['exp'] ?? [0]))->orderBy('id')->get();
            $ledger = self::ledger($lines);
            foreach ($lines->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id) as $subject => $group) {
                $source = $group->first()->bank_transaction_id ? ($ledger['txs'][(int) $group->first()->bank_transaction_id] ?? null) : ($ledger['exps'][(int) $group->first()->manual_expense_id] ?? null);
                $counted = $source === null ? null : self::counted($ledger, $source, $group);
                if ($counted === null) {
                    continue;
                }
                [$currency, $minor] = self::inCurrency($ledger, $source, -(int) $counted->sum('amount_pence'), $counted);
                $out[$subject] = ['measure' => 'minor', 'currency' => $currency, 'amount' => $minor];
            }
        }
        if (isset($by['travel'])) {
            foreach (TravelItem::query()->whereIn('id', $by['travel'])->get() as $t) {
                $out['travel:'.$t->id] = ['measure' => 'minor', 'currency' => strtoupper($t->currency ?: 'GBP'), 'amount' => (int) self::bookingAmount($t)];
            }
        }
        if (isset($by['trip'])) {
            foreach (MileageTrip::query()->whereIn('id', $by['trip'])->get(['id', 'miles']) as $t) {
                $out['trip:'.$t->id] = ['measure' => 'quantity', 'currency' => 'GBP', 'amount' => (string) BigDecimal::of((string) $t->miles)->toScale(2)];
            }
        }

        return $out;
    }

    /**
     * The refs a subject's costs have now (and their aliases): where a decision about it still applies. A decision
     * filed under any other ref of the subject is about a cost that has gone (the payment moved to another job).
     *
     * @return list<string>
     */
    public static function refsOf(string $subject): array
    {
        [$type, $id] = array_pad(explode(':', $subject, 2), 2, '0');
        $id = (int) $id;
        $refs = [];
        if (in_array($type, ['tx', 'exp'], true)) {
            $column = $type === 'tx' ? 'bank_transaction_id' : 'manual_expense_id';
            $lines = Allocation::query()->where($column, $id)->where('kind', 'business_expense')->get(['id', 'work_job_id', 'client_id']);
            foreach ($lines as $a) {
                if ($a->work_job_id !== null) {
                    $refs[] = $type.':'.$id.'#j'.$a->work_job_id;
                } elseif ($a->client_id !== null) {
                    $refs[] = $type.':'.$id.'#c'.$a->client_id;
                    $refs[] = $type.':'.$id.'#c'.ClientIds::resolve((int) $a->client_id);
                }
            }
            if ($lines->contains(fn ($a) => $a->work_job_id === null && $a->client_id === null)) {
                array_push($refs, ...CostCharge::query()->where('charge', 'recharge')->where('cost_ref', 'like', $subject.'#c%')->pluck('cost_ref')->all());
            }
            foreach (TravelItem::query()->where('bank_transaction_id', $type === 'tx' ? $id : 0)->pluck('id') as $t) {
                $refs[] = 'travel:'.$t;
            }
        } elseif ($type === 'travel') {
            $t = TravelItem::query()->find($id);
            if ($t !== null) {
                $refs[] = $subject;
                if ($t->bank_transaction_id !== null && $t->work_job_id !== null) {
                    $refs[] = 'tx:'.$t->bank_transaction_id.'#j'.$t->work_job_id;
                }
            }
        } elseif ($type === 'trip' && MileageTrip::query()->whereKey($id)->exists()) {
            $refs[] = $subject;
        }
        // Costs you said are the same count as one.
        foreach (CostCharges::pairs($refs) as $ref => $others) {
            foreach ($others as $other => $pair) {
                if ($pair === 'same') {
                    $refs[] = $other;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Payments on the job booked as mileage: never a cost (driving is priced from your trips), each to check.
     *
     * @return list<array{ref:string, amount_pence:int, check:string}>
     */
    public static function mileagePayments(WorkJob $job): array
    {
        $category = Category::query()->where('code', CostClass::MILEAGE_CATEGORY)->value('id');
        if ($category === null) {
            return [];
        }

        return Allocation::query()->where('work_job_id', $job->id)->where('kind', 'business_expense')->where('category_id', $category)->orderBy('id')->get()
            ->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id)
            ->map(fn (Collection $g, string $subject) => ['ref' => $subject.'#j'.$job->id, 'amount_pence' => -(int) $g->sum('amount_pence'),
                'check' => 'Mileage recorded as a payment: driving is charged from your mileage trips. Check this payment.'])
            ->values()->all();
    }

    /** The ref of a cost on a payment or expense: on a job, or for a client. */
    private static function ref(Allocation $line, ?int $job, ?int $client): string
    {
        return $line->bank_transaction_id ? CostRef::tx((int) $line->bank_transaction_id, $job, $client) : CostRef::exp((int) $line->manual_expense_id, $job, $client);
    }

    /**
     * Everything the ledger costs need, for these lines: their payments and expenses (active statements only), every
     * line of each (to tell a refund from a deduction), categories, locked years and payers.
     *
     * @param  Collection<int, Allocation>  $lines
     */
    private static function ledger(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return ['txs' => collect(), 'exps' => collect(), 'income' => collect(), 'categories' => [], 'locked' => collect(), 'payers' => null];
        }
        $txIds = $lines->pluck('bank_transaction_id')->filter()->unique()->values();
        $expIds = $lines->pluck('manual_expense_id')->filter()->unique()->values();
        $txs = collect();
        foreach ($txIds->chunk(500) as $chunk) {
            $txs = $txs->concat(BankTransaction::query()->whereIn('id', $chunk->values()->all())
                ->get(['id', 'posted_on', 'transaction_on', 'tax_year', 'amount_pence', 'original_amount_minor', 'original_currency', 'description', 'counterparty', 'merchant_key', 'created_at']));
        }
        $exps = collect();
        foreach ($expIds->chunk(500) as $chunk) {
            $exps = $exps->concat(ManualExpense::query()->whereIn('id', $chunk->values()->all())
                ->get(['id', 'spent_on', 'tax_year', 'amount_pence', 'original_amount_minor', 'original_currency', 'description', 'merchant_key', 'created_at']));
        }
        $income = collect();
        foreach ([['bank_transaction_id', $txIds], ['manual_expense_id', $expIds]] as [$column, $ids]) {
            foreach ($ids->chunk(500) as $chunk) {
                $income = $income->concat(Allocation::query()->whereIn($column, $chunk->values()->all())->where('kind', 'business_income')->distinct()
                    ->pluck($column)->map(fn ($id) => ($column === 'bank_transaction_id' ? 'tx:' : 'exp:').$id));
            }
        }
        // Who pays you for whom: money in from the client is never a refund, money out to the client never a recharge.
        $payers = $txs->pluck('merchant_key')->concat($exps->pluck('merchant_key'))->filter();
        $ledger = [
            'txs' => $txs->keyBy('id'), 'exps' => $exps->keyBy('id'), 'income' => $income->flip(),
            'categories' => self::categories(), 'locked' => TaxYear::query()->whereNotNull('locked_at')->pluck('label')->flip(),
            'payers' => $payers->isEmpty() ? null : PayerAliases::forKeys($payers), 'shares' => [],
        ];
        $ledger['shares'] = self::shares($ledger);

        return $ledger;
    }

    /**
     * Each cost line's share of a payment made in another currency, in that currency's minor units (by allocation id,
     * for each payment): the payment's own amount shared out by largest remainder, in whole units, so a payment split
     * between jobs adds up to exactly what its costs came to in all, never a cent more or less.
     *
     * @return array<string, array<int, int>> by payment (tx:N, exp:N), then line
     */
    private static function shares(array $ledger): array
    {
        $foreign = fn ($s) => ! in_array(strtoupper((string) $s->original_currency), ['', 'GBP'], true) && $s->original_amount_minor && $s->amount_pence;
        $out = [];
        foreach ([['tx', 'bank_transaction_id', $ledger['txs']], ['exp', 'manual_expense_id', $ledger['exps']]] as [$type, $column, $sources]) {
            $ids = $sources->filter($foreign)->keys()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $lines = collect();
            foreach ($ids->chunk(500) as $chunk) {
                $lines = $lines->concat(Allocation::query()->whereIn($column, $chunk->values()->all())->where('kind', 'business_expense')->orderBy('id')->get());
            }
            foreach ($lines->groupBy($column) as $id => $group) {
                $source = $sources[(int) $id];
                $counted = self::counted($ledger, $source, $group);
                if ($counted === null) {
                    continue;
                }
                $out[$type.':'.$id] = self::apportion(abs((int) $source->original_amount_minor), abs((int) $source->amount_pence),
                    $counted->mapWithKeys(fn (Allocation $a) => [(int) $a->id => abs((int) $a->amount_pence)])->all());
            }
        }

        return $out;
    }

    /**
     * $whole shared out over these parts of $amount (largest remainder, ties to the first part): in all, $whole × the
     * parts' sum ÷ $amount, rounded half up, exactly.
     *
     * @param  array<int, int>  $parts  by key, each a part of $amount
     * @return array<int, int> by key
     */
    private static function apportion(int $whole, int $amount, array $parts): array
    {
        $target = BigDecimal::of($whole)->multipliedBy(array_sum($parts))->dividedBy($amount, 0, RoundingMode::HALF_UP)->toInt();
        $out = [];
        $rest = [];
        foreach ($parts as $key => $part) {
            $exact = BigDecimal::of($whole)->multipliedBy($part);
            $out[$key] = $exact->dividedBy($amount, 0, RoundingMode::DOWN)->toInt();
            $rest[$key] = $exact->minus(BigDecimal::of($out[$key])->multipliedBy($amount))->toInt();
        }
        $left = max(0, min(count($parts), $target - array_sum($out)));
        $order = array_keys($parts);
        usort($order, fn ($a, $b) => [$rest[$b], $a] <=> [$rest[$a], $b]);
        foreach (array_slice($order, 0, $left) as $key) {
            $out[$key]++;
        }

        return $out;
    }

    /** @return array<int, array{code:string, parent:?string}> by category id */
    private static function categories(): array
    {
        $all = Category::query()->get(['id', 'code', 'parent_id'])->keyBy('id');

        return $all->map(fn (Category $c) => ['code' => (string) $c->code, 'parent' => $c->parent_id ? ($all[$c->parent_id]->code ?? null) : null])->all();
    }

    /**
     * The parts of a cost on a payment or expense, from these of its lines: on money out its business costs; on money
     * in only a refund's (no business income on it: a negative line beside income is a deduction, never a cost).
     * Each part's amount is what it cost (positive), or what came back (negative). Null when nothing counts.
     *
     * @return list<array{category:?string, class:string, amount_pence:int}>|null
     */
    private static function parts(array $ledger, BankTransaction|ManualExpense $source, Collection $lines): ?array
    {
        $counted = self::counted($ledger, $source, $lines);
        if ($counted === null) {
            return null;
        }

        return $counted->map(function (Allocation $a) use ($ledger) {
            $category = $ledger['categories'][(int) $a->category_id] ?? ['code' => null, 'parent' => null];

            return ['category' => $category['code'], 'class' => CostClass::of($category['code']), 'amount_pence' => -(int) $a->amount_pence];
        })->values()->all();
    }

    /**
     * The lines of a payment or expense that make its cost (see parts()), or null when none does.
     *
     * @param  Collection<int, Allocation>  $lines
     * @return Collection<int, Allocation>|null
     */
    private static function counted(array $ledger, BankTransaction|ManualExpense $source, Collection $lines): ?Collection
    {
        $out = (int) $source->amount_pence < 0;
        $key = ($source instanceof BankTransaction ? 'tx:' : 'exp:').$source->id;
        if (! $out && isset($ledger['income'][$key])) {
            return null;
        }
        $counted = $lines->filter(function (Allocation $a) use ($ledger, $out) {
            $amount = (int) $a->amount_pence;
            $category = $ledger['categories'][(int) $a->category_id] ?? ['code' => null, 'parent' => null];

            return ! ($out ? $amount >= 0 : $amount <= 0) && CostClass::counts($category['code'], $category['parent']);
        })->values();

        return $counted->isEmpty() ? null : $counted;
    }

    /**
     * A cost on a payment or expense from its lines on one job (or for one client), or null when they make none.
     *
     * @param  Collection<int, Allocation>  $lines
     */
    private static function ledgerUnit(array $ledger, Allocation $first, Collection $lines, ?int $job, ?int $forClient, ?int $client): ?array
    {
        $source = $first->bank_transaction_id ? ($ledger['txs'][(int) $first->bank_transaction_id] ?? null) : ($ledger['exps'][(int) $first->manual_expense_id] ?? null);
        if ($source === null) {
            return null; // a held or undone statement's line counts nowhere
        }
        $counted = self::counted($ledger, $source, $lines);
        if ($counted === null) {
            return null;
        }
        $parts = self::parts($ledger, $source, $counted);
        $pence = (int) array_sum(array_column($parts, 'amount_pence'));
        if ($pence === 0) {
            return null;
        }
        $credit = $pence < 0;
        // The client paying you is never a refund to pass on; money you paid the client (their commission, a fee)
        // isn't charged back to them either: listed to check, unticked.
        $sole = $client === null ? null : $ledger['payers']?->sole($source->merchant_key);
        $toClient = $sole !== null && ClientIds::resolve($sole) === $client;
        if ($credit && $toClient) {
            return null;
        }
        $main = collect($parts)->sortByDesc(fn (array $p) => abs($p['amount_pence']))->first();
        [$currency, $minor] = self::inCurrency($ledger, $source, $pence, $counted);
        $tx = $source instanceof BankTransaction;
        $date = ($tx ? $source->effectiveDate() : $source->spent_on)->format('Y-m-d');
        $who = $tx ? ($source->counterparty ?: $source->description) : $source->description;
        $confirmed = $lines->every(fn (Allocation $a) => $a->confirmed_at !== null);
        $taxYear = (string) ($first->tax_year ?: $source->tax_year);
        $locked = isset($ledger['locked'][$taxYear]);
        $flags = [];
        if (! $confirmed) {
            $flags['unconfirmed'] = $locked ? 'Not confirmed, and '.$taxYear.' is locked: untick it, or unlock the year first.' : 'Not confirmed yet: issuing confirms it.';
        }
        if ($toClient) {
            $flags['check'] = 'Paid to '.(Client::query()->whereKey($client)->value('name') ?? 'the client').' itself: commission or a fee isn’t charged back to them.';
        }
        $ref = self::ref($first, $job, $job === null ? $forClient : null);

        return [
            'ref' => $ref, 'aliases' => [], 'subject' => CostRef::subject($ref), 'kind' => $credit ? 'credit' : 'cost', 'class' => $main['class'], 'parts' => $parts,
            'date' => $date, 'description' => ($credit ? 'Refund: ' : '').CostWords::cost($main['class'], $who, null, $date),
            'words' => array_values(array_filter([$tx ? $source->counterparty : null, $source->merchant_key, $source->description])),
            'merchant' => $source->merchant_key ?: MerchantKey::from((string) $source->description, $tx ? $source->counterparty : null),
            'amount_pence' => $pence, 'currency' => $currency, 'amount_minor' => $minor, 'quantity' => '1.00', 'unit' => 'each', 'unit_price_minor' => null,
            'job_id' => $job, 'client_id' => $client, 'tax_year' => $taxYear, 'confirmed' => $confirmed, 'locked' => $locked,
            'flags' => $flags, 'pair_ref' => null, 'part' => null,
        ];
    }

    /**
     * A cost in the currency it was paid in: its share of the payment's own amount, in hundredths (as invoices and
     * bookings count), or pounds when it was paid in pounds.
     *
     * @return array{0:string, 1:int}
     */
    private static function inCurrency(array $ledger, BankTransaction|ManualExpense $source, int $pence, ?Collection $lines = null): array
    {
        $currency = strtoupper((string) $source->original_currency);
        if ($currency === '' || $currency === 'GBP' || ! $source->original_amount_minor || ! $source->amount_pence) {
            return ['GBP', $pence];
        }
        $shares = $ledger['shares'][($source instanceof BankTransaction ? 'tx:' : 'exp:').$source->id] ?? null;
        if ($shares !== null && $lines !== null && $lines->every(fn (Allocation $a) => isset($shares[(int) $a->id]))) {
            $own = (int) $lines->sum(fn (Allocation $a) => $shares[(int) $a->id]);
        } else {
            $own = BigDecimal::of(abs((int) $source->original_amount_minor))->multipliedBy(abs($pence))
                ->dividedBy(abs((int) $source->amount_pence), 0, RoundingMode::HALF_UP)->toInt();
        }

        return [$currency, ($pence < 0 ? -1 : 1) * (int) Money::toHundredths($own, $currency)];
    }

    /** @return array<int, Collection<int, TravelItem>> the jobs' bookings that may be costs, by job */
    private static function bookings(array $jobIds): array
    {
        $out = [];
        foreach (array_chunk($jobIds, 500) as $chunk) {
            $rows = TravelItem::query()->whereIn('work_job_id', $chunk)->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->whereNull('decision')->orWhereNotIn('decision', ['personal', 'client_booked']))
                ->orderBy('starts_on')->orderBy('id')->get();
            foreach ($rows->groupBy('work_job_id') as $job => $items) {
                $out[(int) $job] = $items;
            }
        }

        return $out;
    }

    /** A booking's price: amount_minor, scaled to the business nights when it's part business. */
    private static function bookingAmount(TravelItem $t): ?int
    {
        if ($t->amount_minor === null) {
            return null;
        }
        $nights = count($t->nights());
        if ($t->decision === 'mixed' && $t->business_nights !== null && $nights > 0) {
            return BigDecimal::of((int) $t->amount_minor)->multipliedBy((int) $t->business_nights)->dividedBy($nights, 0, RoundingMode::HALF_UP)->toInt();
        }

        return (int) $t->amount_minor;
    }

    /**
     * The job's bookings with its ledger costs: a booking linked to a payment on the job is that payment's cost (its
     * ref an alias); a booking with no payment is a cost of its own, unless the job has a ledger cost of its kind near
     * its dates, when it's listed as maybe the same (or is that cost, once you said so).
     *
     * @param  list<array<string, mixed>>  $units
     * @param  array<string, array<string, string>>  $pairs
     * @return list<array<string, mixed>>
     */
    private static function withBookings(array $units, Collection $bookings, int $jobId, ?int $client, array $pairs): array
    {
        $index = array_flip(array_column($units, 'ref'));
        foreach ($bookings->whereNotNull('bank_transaction_id') as $t) {
            $ref = CostRef::tx((int) $t->bank_transaction_id, $jobId);
            if (isset($index[$ref])) {
                $u = &$units[$index[$ref]];
                $u['aliases'][] = CostRef::travel((int) $t->id);
                $u['description'] = CostWords::cost($u['class'], $t->provider, self::where($t), $u['date']);
                $u['words'] = array_values(array_unique([...$u['words'], (string) $t->provider]));
                unset($u);
            }
            // A booking whose payment isn't a cost on this job (decided another way, or on another job) isn't one here.
        }
        foreach ($bookings->whereNull('bank_transaction_id') as $t) {
            $ref = CostRef::travel((int) $t->id);
            $class = CostClass::ofBooking($t);
            $from = CarbonImmutable::parse($t->starts_on->format('Y-m-d'))->subDays(self::BEFORE_DAYS)->format('Y-m-d');
            $to = CarbonImmutable::parse(($t->ends_on ?? $t->starts_on)->format('Y-m-d'))->addDays(self::AFTER_DAYS)->format('Y-m-d');
            $near = null;
            foreach ($units as $i => $u) {
                if ($u['kind'] === 'cost' && ($u['class'] === $class || in_array($class, array_column($u['parts'], 'class'), true))
                    && $u['date'] >= $from && $u['date'] <= $to && ! collect($u['aliases'])->contains(fn ($a) => str_starts_with($a, 'travel:'))) {
                    $near ??= $i;
                    if (($pairs[$ref][$u['ref']] ?? null) !== null) {
                        $near = $i;
                        break;
                    }
                }
            }
            $pair = $near === null ? null : ($pairs[$ref][$units[$near]['ref']] ?? null);
            if ($pair === 'same') {
                $units[$near]['aliases'][] = $ref;
                $units[$near]['words'] = array_values(array_unique([...$units[$near]['words'], (string) $t->provider]));

                continue;
            }
            $amount = self::bookingAmount($t);
            $currency = strtoupper($t->currency ?: 'GBP');
            $flags = ['no_payment' => 'No payment for it yet: it’s charged at the booking’s price.'];
            if ($amount === null || $amount <= 0) {
                $flags['no_amount'] = 'The booking has no price: type it, or untick it.';
            }
            if (($t->suggestion['decision'] ?? null) === 'client_booked' || $t->payment === 'paid_by_other') {
                $flags['paid_by_other'] = 'The booking says someone else paid.';
            }
            $pairRef = null;
            if ($near !== null && $pair === null) {
                $pairRef = $units[$near]['ref'];
                $flags['maybe_same'] = 'Maybe the same cost as '.$units[$near]['description'].': say if it is.';
            }
            $date = $t->starts_on->format('Y-m-d');
            $units[] = [
                'ref' => $ref, 'aliases' => [], 'subject' => $ref, 'kind' => 'booking', 'class' => $class,
                'parts' => [['category' => TravelItem::CATEGORY[$t->kind] ?? null, 'class' => $class, 'amount_pence' => $currency === 'GBP' ? (int) $amount : 0]],
                'date' => $date, 'description' => CostWords::cost($class, $t->provider, self::where($t), $date), 'words' => array_values(array_filter([(string) $t->provider])), 'merchant' => null,
                'amount_pence' => $currency === 'GBP' ? (int) $amount : null, 'currency' => $currency, 'amount_minor' => (int) $amount, 'quantity' => '1.00', 'unit' => 'each',
                'unit_price_minor' => null, 'job_id' => $jobId, 'client_id' => $client, 'tax_year' => null, 'confirmed' => null, 'locked' => false,
                'flags' => $flags, 'pair_ref' => $pairRef, 'part' => null,
            ];
        }

        return $units;
    }

    /** Where a booking was: a stay's place, a journey's route. */
    private static function where(TravelItem $t): ?string
    {
        if ($t->kind === 'hotel') {
            return $t->place;
        }
        if ($t->place && $t->destination) {
            return $t->place.' to '.$t->destination;
        }

        return $t->destination ?: $t->place;
    }

    /**
     * A hand-entered expense and a card line that may be one payment (DupeCrossSource's pairs, both on the job): the
     * one recorded second is flagged maybe_same until you say (the card line, when it came in after the expense you
     * added by hand); 'same' makes the expense the card line's alias. Whichever is flagged, ChargeStatus leaves the
     * other unticked too once the flagged one is charged.
     *
     * @param  list<array<string, mixed>>  $units
     * @return list<array<string, mixed>>
     */
    private static function dupes(array $units, array $pairs, array $ledger): array
    {
        $cards = array_filter($units, fn (array $u) => $u['kind'] === 'cost' && str_starts_with($u['ref'], 'tx:'));
        $recorded = function (array $u) use ($ledger): string {
            ['type' => $type, 'id' => $id] = CostRef::parse($u['ref']);
            $source = $type === 'tx' ? ($ledger['txs'][$id] ?? null) : ($ledger['exps'][$id] ?? null);

            return (string) $source?->created_at?->format('Y-m-d H:i:s');
        };
        $taken = [];
        foreach ($units as $i => $u) {
            if ($u['kind'] !== 'cost' || ! str_starts_with($u['ref'], 'exp:') || isset($u['flags']['maybe_same'])) {
                continue;
            }
            foreach ($cards as $j => $c) {
                if (isset($taken[$j]) || $c['amount_pence'] !== $u['amount_pence'] || abs(CarbonImmutable::parse($c['date'])->diffInDays(CarbonImmutable::parse($u['date']))) > DupeCrossSource::DAYS
                    || DupeCrossSource::merchantMatch((string) $u['merchant'], (string) $c['merchant']) === null) {
                    continue;
                }
                $taken[$j] = true;
                $pair = $pairs[$u['ref']][$c['ref']] ?? null;
                if ($pair === 'same') {
                    $units[$j]['aliases'] = array_values(array_unique([...$units[$j]['aliases'], $u['ref'], ...$u['aliases']]));
                    unset($units[$i]);
                } elseif ($pair === null && $recorded($c) > $recorded($u)) {
                    $units[$j]['pair_ref'] = $u['ref'];
                    $units[$j]['flags']['maybe_same'] = 'Maybe the same payment as '.$u['description'].': say if it is.';
                } elseif ($pair === null) {
                    $units[$i]['pair_ref'] = $c['ref'];
                    $units[$i]['flags']['maybe_same'] = 'Maybe the same payment as '.$c['description'].': say if it is.';
                }
                break;
            }
        }

        return array_values($units);
    }

    /** Whether fuel is covered by a mileage claim: you have vehicles, and every one is on simplified mileage. */
    private static function fuelRules(): bool
    {
        $vehicles = Vehicle::query()->get(['id', 'method']);

        return $vehicles->isNotEmpty() && $vehicles->every(fn (Vehicle $v) => $v->method === 'simplified');
    }

    /**
     * Fuel beside mileage charged per mile is maybe the same drive; fuel for your own car on simplified mileage is
     * already covered by your mileage claim (TravelDoubleClaim), unless there's a hire car on the job or that day.
     *
     * @param  list<array<string, mixed>>  $units
     * @param  callable(): bool  $simplifiedOnly  whether every vehicle is on simplified mileage (asked only when there's fuel)
     * @return list<array<string, mixed>>
     */
    private static function fuel(array $units, bool $milesCharged, callable $simplifiedOnly, Collection $bookings): array
    {
        $hires = $bookings->where('kind', 'car_hire');
        $hiredCost = collect($units)->contains(fn (array $u) => $u['class'] === 'car_hire');
        foreach ($units as $i => $u) {
            if (! in_array($u['kind'], ['cost', 'booking'], true) || ! in_array('fuel', array_column($u['parts'], 'class'), true)) {
                continue;
            }
            if ($milesCharged) {
                $units[$i]['flags']['maybe_same'] ??= 'Fuel and mileage for the same drive?';
            }
            $day = CarbonImmutable::parse($u['date']);
            $hired = $hiredCost || $hires->contains(fn (TravelItem $h) => $day->gte(CarbonImmutable::parse($h->starts_on->format('Y-m-d'))->subDay())
                && $day->lte(CarbonImmutable::parse(($h->ends_on ?? $h->starts_on)->format('Y-m-d'))->addDay()));
            if (! $hired && $simplifiedOnly()) {
                $units[$i]['flags']['check'] = 'Fuel for your own car is covered by your mileage claim. Charge driving per mile, or type the amount as your own line.';
            }
        }

        return $units;
    }

    private static function tripUnit(MileageTrip $trip, WorkJob $job, ?int $client): array
    {
        $date = $trip->trip_on->format('Y-m-d');
        $miles = (string) BigDecimal::of((string) $trip->miles)->toScale(2);

        return [
            'ref' => CostRef::trip((int) $trip->id), 'aliases' => [], 'subject' => CostRef::trip((int) $trip->id), 'kind' => 'trip', 'class' => 'mileage',
            'parts' => [['category' => null, 'class' => 'mileage', 'amount_pence' => 0]],
            'date' => $date, 'description' => CostWords::mileage($date, CostWords::venue($job), $miles), 'words' => [], 'merchant' => null,
            'amount_pence' => null, 'currency' => 'GBP', 'amount_minor' => null, 'quantity' => $miles, 'unit' => 'miles', 'unit_price_minor' => null,
            'job_id' => (int) $job->id, 'client_id' => $client, 'tax_year' => $trip->tax_year, 'confirmed' => null, 'locked' => false,
            'flags' => [], 'pair_ref' => null, 'part' => null,
        ];
    }

    /**
     * Hours from the job's working days' times (a day's own, else the job's), each day's finish after midnight counted
     * into the next morning; the days with no times.
     *
     * @return array{0:string, 1:list<string>}
     */
    private static function hours(WorkJob $job): array
    {
        $days = $job->relationLoaded('days') ? $job->days : $job->days()->get();
        $dates = $days->isEmpty() ? collect($job->workDates())->map(fn ($d) => ['date' => $d, 'start' => $job->start_time, 'end' => $job->end_time])
            : $days->filter(fn (JobDay $d) => $d->kind !== 'travel')->map(fn (JobDay $d) => ['date' => $d->on_date->format('Y-m-d'), 'start' => $d->start_time ?? $job->start_time, 'end' => $d->end_time ?? $job->end_time]);
        $minutes = 0;
        $unknown = [];
        foreach ($dates as $d) {
            if (! $d['start'] || ! $d['end']) {
                $unknown[] = $d['date'];

                continue;
            }
            $start = self::minuteOf((string) $d['start']);
            $end = self::minuteOf((string) $d['end']);
            $minutes += $end > $start ? $end - $start : $end + 1440 - $start;
        }

        return [(string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HALF_UP), $unknown];
    }

    private static function minuteOf(string $time): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return $h * 60 + $m;
    }

    private static function qty(int $n): string
    {
        return $n.'.00';
    }
}
