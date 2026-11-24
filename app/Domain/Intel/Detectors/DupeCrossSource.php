<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Matching\MerchantKey;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A cash expense that's also on a card (dupe.cross_source, M4): an expense you added by hand (cash, a personal card)
 * as a business cost, and a bank or card line of the same amount, from a merchant that looks the same, within
 * DAYS days of it, that isn't decided as something else (personal, a transfer). The statement was imported after you
 * added it, say, and now the cost is claimed twice. What's at risk is the one charge.
 *
 * The same merchant means a word in common that names it (SCREWFIX), never only a word for the kind of cost (PARKING,
 * SERVICES, FUEL: GENERIC), so two different car parks or service stations aren't paired. An expense you described
 * only by the kind of cost ("Parking") can't say which merchant it was: paired with a line for that kind of cost, it's
 * graded possible. Only reads.
 */
final class DupeCrossSource implements Detector
{
    public const CODE = 'dupe.cross_source';

    public const VERSION = 2;

    public const DAYS = 1;

    /** Words for a kind of cost or business, not a merchant: never enough on their own to say two are the same. */
    public const GENERIC = ['PARKING', 'PARK', 'CAR', 'CARPARK', 'SERVICES', 'SERVICE', 'STATION', 'STATIONS', 'FUEL', 'PETROL', 'DIESEL', 'GARAGE', 'FILLING', 'TAXI', 'TAXIS',
        'CAB', 'CABS', 'HOTEL', 'HOTELS', 'INN', 'LODGE', 'CAFE', 'COFFEE', 'STORE', 'STORES', 'SHOP', 'SHOPS', 'SUPERSTORE', 'SUPERMARKET', 'EXPRESS', 'EXTRA', 'LOCAL', 'LTD',
        'LIMITED', 'PLC', 'FOOD', 'FOODS', 'RESTAURANT', 'BAR', 'PUB', 'KITCHEN', 'TRAIN', 'TRAINS', 'RAIL', 'RAILWAY', 'TICKET', 'TICKETS', 'TRAVEL', 'BUS', 'COACH', 'TOLL',
        'MOTORWAY', 'SUPPLIES', 'SUPPLY', 'TOOLS', 'HARDWARE', 'MARKET', 'MART', 'MEAL', 'MEALS', 'LUNCH', 'DINNER', 'BREAKFAST', 'SANDWICH', 'SANDWICHES', 'DRINKS', 'CASH',
        'EXPENSE', 'EXPENSES', 'MISC', 'SUNDRIES', 'STATIONERY', 'POSTAGE', 'POST', 'OFFICE', 'CENTRE', 'CENTER', 'GROUP', 'TRADING', 'AND', 'FOR', 'AT', 'OF', 'NEAR', 'VENUE'];

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $expenses = ManualExpense::query()->where('amount_pence', '<', 0)->whereHas('allocations', fn ($q) => $q->where('kind', 'business_expense'))
            ->orderBy('spent_on')->orderBy('id')->get(['id', 'spent_on', 'tax_year', 'description', 'merchant_key', 'paid_with', 'amount_pence']);
        $drafts = [];
        if ($expenses->isNotEmpty()) {
            $from = $expenses->min('spent_on')->copy()->subDays(self::DAYS + 3)->format('Y-m-d');
            $to = $expenses->max('spent_on')->copy()->addDays(self::DAYS + 3)->format('Y-m-d');
            $lines = collect();
            foreach ($expenses->pluck('amount_pence')->unique()->chunk(500) as $chunk) {
                $lines = $lines->concat(BankTransaction::query()->whereIn('amount_pence', $chunk->values()->all())->whereNull('transfer_pair_id')
                    ->whereDate('posted_on', '>=', $from)->whereDate('posted_on', '<=', $to)
                    ->with('allocations:id,bank_transaction_id,kind')->get(['id', 'account_id', 'posted_on', 'transaction_on', 'amount_pence', 'description', 'counterparty', 'merchant_key']));
            }
            $byAmount = $lines->groupBy('amount_pence');
            foreach ($expenses as $e) {
                $day = CarbonImmutable::parse($e->spent_on->format('Y-m-d'));
                $mine = $e->merchant_key ?: MerchantKey::from($e->description);
                $grade = null;
                $candidates = ($byAmount[$e->amount_pence] ?? collect())->filter(function (BankTransaction $t) use ($day) {
                    // Decided as something other than a business cost: not claimed twice.
                    if ($t->allocations->isNotEmpty() && ! $t->allocations->contains('kind', 'business_expense')) {
                        return false;
                    }

                    return abs(CarbonImmutable::parse($t->effectiveDate()->format('Y-m-d'))->diffInDays($day)) <= self::DAYS;
                });
                // The same merchant by name first (likely); else one for the same kind of cost (possible).
                $twin = null;
                foreach ($candidates as $t) {
                    $match = self::merchantMatch($mine, $t->merchant_key ?: MerchantKey::from($t->description, $t->counterparty));
                    if ($match !== null && ($twin === null || $match === Grades::LIKELY)) {
                        [$twin, $grade] = [$t, $match];
                        if ($match === Grades::LIKELY) {
                            break;
                        }
                    }
                }
                if (! $twin) {
                    continue;
                }
                $how = ['cash' => 'in cash', 'personal_card' => 'on a personal card'][$e->paid_with] ?? 'by hand';
                $drafts[] = new FindingDraft(
                    key: 'xdupe:'.$e->id, code: self::CODE, group: 'duplicates', priority: 3,
                    title: 'Added '.$how.' and on a statement: '.$e->description.', '.Money::format(abs((int) $e->amount_pence), 'GBP', false),
                    detail: 'You added '.Money::format(abs((int) $e->amount_pence), 'GBP', false).' on '.$day->format('j M Y').' '.$how.', and '.($twin->counterparty ?: $twin->description)
                        .' on '.$twin->effectiveDate()->format('j M Y').' is the same amount'.($grade === Grades::POSSIBLE ? ' for the same kind of cost (yours doesn’t say where it was)' : '').'. '
                        .'If it’s the same payment, delete the one you added by hand so it isn’t claimed twice; if you paid twice, mark this not relevant.',
                    href: '/transactions?year='.$e->tax_year.'&q='.urlencode($twin->counterparty ?: $twin->description),
                    subjectRef: Ref::make('exp', $e->id, null, sha1($e->spent_on?->format('Y-m-d').'|'.$e->amount_pence.'|'.$e->description)),
                    scopeRef: Ref::make('acct', $twin->account_id), evidence: [Ref::make('tx', $twin->id)],
                    amountPence: (int) $e->amount_pence, direction: 'at_risk', grade: $grade, detectorVersion: self::VERSION,
                );
            }
        }
        $this->checked = $expenses->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * Whether two merchant keys (MerchantKey::from(): up to three words, noise taken out) look like the same merchant:
     * likely when they share a word that names it (not GENERIC); possible when one says only what kind of cost it
     * was and the other is for that kind ("PARKING" and "NCP PARKING"); otherwise null (two merchants that name
     * themselves differently, "WELCOME BREAK SERVICES" and "MOTO SERVICES", are different).
     */
    public static function merchantMatch(string $a, string $b): ?string
    {
        $words = fn (string $k) => array_values(array_filter(explode(' ', strtoupper(trim($k))), fn ($w) => strlen($w) >= 2 && $w !== 'UNKNOWN'));
        [$a, $b] = [$words($a), $words($b)];
        if ($a === [] || $b === []) {
            return null;
        }
        $named = fn (array $ws) => array_values(array_diff($ws, self::GENERIC));
        [$na, $nb] = [$named($a), $named($b)];
        if ($na !== [] && $nb !== []) {
            return array_intersect($na, $nb) !== [] ? Grades::LIKELY : null;
        }

        return array_intersect($a, $b) !== [] ? Grades::POSSIBLE : null;
    }

    /** Whether two merchant keys look like the same merchant (merchantMatch(), at any grade). */
    public static function sameMerchant(string $a, string $b): bool
    {
        return self::merchantMatch($a, $b) !== null;
    }
}
