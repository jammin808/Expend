<?php

namespace App\Domain\Tax;

use App\Domain\Matching\OwnAccounts;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\MileageTrip;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;

/**
 * Legal ways to pay less tax, checked against your own records for one tax year: what you could claim and haven't,
 * reliefs worth using at your rate of tax, choices to review, and deadlines that cost money if missed. Each item says
 * what Expend found, an estimate of the saving where it can work one out (at your marginal rate, labelled as an
 * estimate), the conditions and risks, and the law and HMRC guidance behind it (resources/tax/law.json).
 *
 * It only ever suggests what Parliament intends: claiming what's allowable, choosing between methods the law offers,
 * timing genuine transactions, and using reliefs. Nothing here is a scheme.
 */
final class TaxSavings
{
    /** @return array{tax_year:string, marginal_pct:?string, profit_pence:int, items:list<array>, more:list<array>, researched_on:?string} */
    public static function review(string $label): array
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $period = UkTaxYear::fromLabel($label);
        $s = ReturnSummary::for($label);
        $profit = (int) $s['net_profit_pence'];
        $p = TaxParameters::for($label);
        $marginal = self::marginal($label, $profit);
        $items = [];
        $add = function (string $id, string $status, string $finding, ?int $deduction = null, ?int $saving = null) use (&$items, $marginal) {
            $saving ??= $deduction !== null && $marginal !== null ? self::pctOf($deduction, $marginal) : null;
            $items[] = ['id' => $id, 'status' => $status, 'finding' => $finding, 'deduction_pence' => $deduction, 'saving_pence' => $saving];
        };

        // Use of home: the flat rate is up to £26 a month for 101+ hours of business use at home.
        if (! self::hasCategory($label, 'use_of_home') && $p) {
            $top = end($p['use_of_home'])['monthly'] * 12;
            $add('use-of-home', 'action', 'Nothing is claimed for working from home. If you do admin, invoicing, prep or programming at home for 25 hours or more a month, the flat rate is '
                .Money::format($p['use_of_home'][0]['monthly'], 'GBP', false).' to '.Money::format(end($p['use_of_home'])['monthly'], 'GBP', false).' a month, up to '.Money::format($top, 'GBP', false)
                .' a year (or the business share of actual costs, if higher).', $top);
        }

        // Phone and broadband: the business share.
        if (! self::hasCategory($label, 'phone_internet')) {
            $add('phone-broadband', 'check', 'No phone or internet costs are claimed. If your phone and broadband are in your name and used for work, the business share of the bills is allowable (keep a note of how you worked out the share).');
        }

        // Travel to venues, and nights and meals away.
        $jobDays = self::jobDays($label);
        if ($jobDays) {
            $covered = self::travelDays($label);
            $bare = array_values(array_diff($jobDays, $covered));
            if (count($bare) >= 3) {
                $add('itinerant-travel', 'check', count($bare).' of your '.count($jobDays).' job days in '.$label.' have no travel cost or mileage claimed (for example '
                    .implode(', ', array_map(fn ($d) => CarbonImmutable::parse($d)->format('j M'), array_slice($bare, 0, 3))).'). Travel from home to a venue is allowable: add mileage, or the fares if you paid them.');
            }
            $away = self::nightsAway($label);
            $subsistence = (int) Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->where('kind', 'business_expense')
                ->whereIn('category_id', \App\Models\Category::query()->where('code', 'travel_subsistence')->pluck('id'))->count();
            if ($away >= 2 && $subsistence === 0) {
                $add('meals-s57a', 'check', "You stayed away for {$away} nights on jobs but no meals are claimed. Reasonable meals while away overnight for work are allowable; check your card statements for those days.");
            }
        }

        // Your vehicle: simplified mileage or actual costs.
        $trips = MileageTrip::query()->where('tax_year', $label)->count();
        if ($trips > 0) {
            $m = MileageCalculator::forYear($label);
            $add('vehicle-method-choice', 'info', $trips.' journeys, '.Money::format($m['total_pence'], 'GBP', false).' of mileage claimed. Simplified mileage is usually best for a modest car; for an expensive or high-mileage vehicle, actual costs plus capital allowances can be more. The choice is fixed per vehicle once made.');
        } elseif ($jobDays && Vehicle::query()->exists()) {
            $add('vehicle-method-choice', 'check', 'You have a vehicle set up but no journeys for '.$label.'. Add your business journeys (Mileage › from jobs) to claim them.');
        }

        // Pension contributions save tax at your top rate.
        if ($p && $profit > $p['personal_allowance'] + $p['basic_rate_limit']) {
            $higher = $profit - ($p['personal_allowance'] + $p['basic_rate_limit']);
            $extra = self::pctOf($higher, '20');
            $add('pension-contributions', 'action', Money::format($higher, 'GBP', false).' of your profit is taxed at the higher rate. Personal pension contributions up to that amount (gross) get relief at 40% rather than 20%: up to '
                .Money::format($extra, 'GBP', false).' of extra relief (an estimate), claimed on your return. Contributions are paid from taxed income, so it’s a saving only if you want the pension.', null, $extra);
        }
        if ($p && $profit > $p['personal_allowance_taper_from']) {
            $add('personal-allowance-and-hicbc', 'action', 'Your profit is over £100,000, so your personal allowance is being withdrawn: income between £100,000 and £125,140 is effectively taxed at about 60%. Pension contributions or Gift Aid that bring adjusted income down restore it.');
        }

        // Losses, payments on account, voluntary Class 2, the trading allowance.
        if ($profit < 0) {
            $add('loss-relief', 'action', 'This year shows a loss of '.Money::format(-$profit, 'GBP', false).'. It can be set against other income this year or last year, or carried forward: the right choice depends on where it saves most tax. Worth asking an accountant.');
        }
        $prev = $period->previous()->label();
        $thisEst = TaxEstimator::estimate($label, $profit);
        $prevEst = TaxEstimator::estimate($prev, (int) ReturnSummary::for($prev)['net_profit_pence']);
        if ($thisEst && $prevEst) {
            $prevRelevant = $prevEst['income_tax']['total_pence'] + $prevEst['class4_pence'];
            $thisRelevant = $thisEst['income_tax']['total_pence'] + $thisEst['class4_pence'];
            if ($prevRelevant >= 100_000 && $thisRelevant < $prevRelevant - 20_000) {
                $add('payments-on-account', 'action', 'Your payments on account for '.$label.' are based on '.$prev.' ('.Money::format(intdiv($prevRelevant, 2), 'GBP', false).' each), but this year’s tax looks lower (about '
                    .Money::format(intdiv($thisRelevant, 2), 'GBP', false).' each, an estimate). You can ask HMRC to reduce them (form SA303 or online) so you don’t pay more than you need upfront. Reduce them too far and you pay interest on the shortfall.');
            }
        }
        $spt = $p['class2_voluntary']['small_profits_threshold'] ?? null;
        $weekly = $p['class2_voluntary']['weekly'] ?? null;
        if ($spt && $weekly && $profit >= 0 && $profit < $spt && $s['turnover_pence'] > 0) {
            $add('voluntary-class-2', 'action', 'Your profit is under the small profits threshold ('.Money::format($spt, 'GBP', false).'), so this year doesn’t count towards your state pension unless you pay voluntary Class 2: '
                .Money::format($weekly, 'GBP', false).' a week, about '.Money::format($weekly * 52, 'GBP', false).' for the year, on your return. That is far cheaper than Class 3.');
        }
        if (($s['trading_allowance']['better'] ?? false) && $s['turnover_pence'] > 0) {
            $add('trading-allowance-comparison', 'action', 'Your expenses ('.Money::format($s['allowable_expenses_pence'], 'GBP', false).') are less than the £1,000 trading allowance, so claiming the allowance instead gives a bigger deduction.', 100_000 - $s['allowable_expenses_pence']);
        }

        // VAT: the rolling 12-month turnover against the threshold.
        $rolling = self::rollingTurnover();
        $threshold = $p['vat_threshold'] ?? 9_000_000;
        if ($rolling > $threshold) {
            $add('vat-review', 'action', 'Your income in the last 12 months ('.Money::format($rolling, 'GBP', false).') is over the VAT threshold ('.Money::format($threshold, 'GBP', false).'). You must register within 30 days of the end of the month you went over. Talk to an accountant now.');
        } elseif ($rolling > $threshold * 0.8) {
            $add('vat-review', 'check', 'Your income in the last 12 months is '.Money::format($rolling, 'GBP', false).', getting close to the VAT threshold ('.Money::format($threshold, 'GBP', false).'). Keep an eye on it; registering voluntarily (or the flat rate scheme) is worth modelling with an accountant.');
        }

        // One client paying most of the year: keep evidence you're self-employed for each engagement.
        $share = self::topClientShare($label);
        if ($share && $share['pct'] >= 75 && $s['turnover_pence'] > 500_000) {
            $add('employment-status-evidence', 'check', $share['name'].' paid '.$share['pct'].'% of your income this year. Keep evidence that each engagement is self-employment (your own kit, control of how you work, several clients, the right to send someone else), which also protects your expense claims.');
        }

        // Things still undecided cost money if they're business costs.
        $pending = OwnAccounts::scopeNotTransfers(BankTransaction::query()->where('tax_year', $label)->whereIn('review_status', ['unreviewed', 'suggested'])->where('amount_pence', '<', 0))->count();
        if ($pending > 0) {
            $add('missed-claims-deadlines', 'action', "{$pending} payments in {$label} are still undecided. Any that are business costs reduce your tax once decided. After filing you can amend until ".$period->filingDeadline()->addYear()->format('j F Y').', and claim overpaid tax for up to four years.');
        }

        // Fines and interest.
        $fines = rescue(fn () => PenaltyEstimator::forYear($label), null, false);
        if ($fines && ($fines['penalties_pence'] + $fines['interest_pence'] > 0 || $fines['upcoming'])) {
            $add('penalties-and-interest', 'action', 'Fines and interest for '.$label.': '.Money::format($fines['penalties_pence'] + $fines['interest_pence'], 'GBP', false).' so far'
                .($fines['upcoming'] ? ', and '.Money::format($fines['upcoming_pence'], 'GBP', false).' more if nothing changes' : '').'. '.($fines['actions'][0] ?? ''), null, $fines['upcoming_pence'] ?: null);
        }

        // Zero-emission car: 100% first-year allowance on new ones bought by 5 April 2027.
        if (CarbonImmutable::today()->lte(CarbonImmutable::create(2027, 4, 5)) && $label >= '2025-26') {
            $add('zero-emission-car-fya', 'info', 'If you’re buying a new car for the business, a new zero-emission car bought by 5 April 2027 gets a 100% first-year allowance (the business share). Don’t buy for the tax alone.');
        }

        $checklist = collect(TaxLaw::savingsChecklist())->keyBy('id');
        $present = fn (array $i) => $i + self::explain($checklist[$i['id']] ?? null);
        $shown = array_map($present, $items);
        $listed = array_column($items, 'id');
        $more = $checklist->except($listed)->map(fn ($c) => ['id' => $c['id'], 'status' => 'idea'] + self::explain($c))->values()->all();
        usort($shown, fn ($a, $b) => [array_search($a['status'], ['action', 'check', 'info', 'ok'], true), -($a['saving_pence'] ?? 0)] <=> [array_search($b['status'], ['action', 'check', 'info', 'ok'], true), -($b['saving_pence'] ?? 0)]);

        return ['tax_year' => $label, 'marginal_pct' => $marginal, 'profit_pence' => $profit, 'items' => $shown, 'more' => $more, 'researched_on' => TaxLaw::researchedOn(),
            'note' => 'Savings are estimates at your marginal rate, from your confirmed records. Only legal ways Parliament intends; points marked for an accountant need one.'];
    }

    /** The checklist's wording and the law behind an item, with links (citable sources only). */
    private static function explain(?array $c): array
    {
        if (! $c) {
            return [];
        }
        $sources = [];
        foreach (preg_split('/\s*,\s*/', (string) ($c['legal_basis'] ?? '')) as $id) {
            $item = $id !== '' ? TaxLaw::find($id) : null;
            if ($item && ($item['type'] !== 'case' || ($item['citable'] ?? false)) && ! empty($item['url'])) {
                $sources[] = ['title' => $item['name'] ?? $id, 'url' => $item['url']];
            }
        }

        return ['title' => $c['title'] ?? '', 'conditions' => $c['conditions'] ?? null, 'how' => $c['how_to_estimate'] ?? null, 'risks' => $c['risks'] ?? null,
            'needs_accountant' => (bool) preg_match('/accountant|adviser/i', ($c['risks'] ?? '').' '.($c['conditions'] ?? '')), 'sources' => array_slice($sources, 0, 6)];
    }

    /** Tax saved per £1 of extra deduction at this profit (income tax + Class 4), as a percentage string. */
    public static function marginal(string $label, int $profit): ?string
    {
        $a = TaxEstimator::estimate($label, $profit);
        $b = TaxEstimator::estimate($label, max(0, $profit - 100_000));
        if (! $a || ! $b || $profit <= 0) {
            return $a ? '0' : null;
        }
        $diff = ($a['income_tax']['total_pence'] + $a['class4_pence']) - ($b['income_tax']['total_pence'] + $b['class4_pence']);
        $base = min(100_000, $profit);

        return (string) round($diff * 100 / $base, 1);
    }

    private static function pctOf(int $pence, string $pct): int
    {
        return (int) floor($pence * (float) $pct / 100);
    }

    private static function hasCategory(string $label, string $code): bool
    {
        return Allocation::query()->where('tax_year', $label)->where('kind', 'business_expense')
            ->whereIn('category_id', \App\Models\Category::query()->where('code', $code)->pluck('id'))->exists();
    }

    /** @return list<string> the year's job days */
    private static function jobDays(string $label): array
    {
        $y = UkTaxYear::fromLabel($label);
        $days = [];
        WorkJob::query()->with('days')->where('status', '!=', 'cancelled')
            ->whereDate('ends_on', '>=', $y->startsOn()->format('Y-m-d'))->whereDate('starts_on', '<=', $y->endsOn()->format('Y-m-d'))->get()
            ->each(function (WorkJob $j) use (&$days, $y) {
                foreach ($j->workDates() as $d) {
                    if ($y->contains($d)) {
                        $days[$d] = true;
                    }
                }
            });
        ksort($days);

        return array_keys($days);
    }

    /** @return list<string> days with mileage or a travel cost */
    private static function travelDays(string $label): array
    {
        $days = MileageTrip::query()->where('tax_year', $label)->pluck('trip_on')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $travel = \App\Models\Category::query()->where('code', 'like', 'travel%')->pluck('id');
        $txDays = Allocation::query()->where('tax_year', $label)->where('kind', 'business_expense')->whereIn('category_id', $travel)
            ->with('transaction:id,posted_on,transaction_on')->get()->map(fn ($a) => $a->transaction?->effectiveDate()->format('Y-m-d'))->filter()->all();

        return array_values(array_unique([...$days, ...$txDays]));
    }

    private static function nightsAway(string $label): int
    {
        $y = UkTaxYear::fromLabel($label);

        return (int) TravelItem::query()->where('kind', 'hotel')->where('status', '!=', 'cancelled')->whereNotNull('work_job_id')
            ->whereDate('starts_on', '>=', $y->startsOn()->format('Y-m-d'))->whereDate('starts_on', '<=', $y->endsOn()->format('Y-m-d'))
            ->get()->sum(fn ($t) => max(1, (int) $t->starts_on->diffInDays($t->ends_on ?? $t->starts_on)));
    }

    private static function rollingTurnover(): int
    {
        $from = CarbonImmutable::today()->subMonths(12)->format('Y-m-d');

        return (int) Allocation::query()->where('kind', 'business_income')->whereNotNull('confirmed_at')
            ->whereHas('transaction', fn ($q) => $q->where('posted_on', '>=', $from))->sum('amount_pence');
    }

    /** @return array{name:string, pct:int}|null */
    private static function topClientShare(string $label): ?array
    {
        $rows = Allocation::query()->where('tax_year', $label)->where('kind', 'business_income')->whereNotNull('confirmed_at')->whereNotNull('work_job_id')
            ->with('job.client')->get()->groupBy(fn ($a) => $a->job?->client?->name ?? '');
        $total = (int) Allocation::query()->where('tax_year', $label)->where('kind', 'business_income')->whereNotNull('confirmed_at')->sum('amount_pence');
        if ($total <= 0) {
            return null;
        }
        $top = $rows->reject(fn ($g, $name) => $name === '')->map(fn ($g) => (int) $g->sum('amount_pence'))->sortDesc();
        $name = $top->keys()->first();

        return $name ? ['name' => $name, 'pct' => (int) round($top->first() * 100 / $total)] : null;
    }
}
