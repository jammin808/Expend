<?php

namespace App\Domain\Tax;

use App\Domain\Matching\OwnAccounts;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\RoundingMode;
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
    /**
     * @return array{tax_year:string, in_progress:bool, marginal_pct:?string, profit_pence:int, total_saving_pence:int, penalties_to_avoid_pence:int,
     *     items:list<array>, more:list<array>, researched_on:?string, note:string}
     */
    public static function review(string $label): array
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $period = UkTaxYear::fromLabel($label);
        $today = CarbonImmutable::today();
        // A year that isn't over yet only has the records so far: checks that need the whole year wait, or use a labelled projection.
        $inProgress = $today->lte($period->endsOn());
        // Claims to set a loss sideways or carry it back (ITA s64(5), s72) and amendments (TMA s9ZA): a year after the filing date.
        $claimBy = $period->filingDeadline()->addYear();
        // Whether the return is in: a filing date counts, whatever the status says (as for penalties).
        $record = TaxYear::query()->where('label', $label)->first();
        $filed = $record && ($record->filed_on !== null || in_array($record->status, ['filed', 'amending'], true));
        $s = ReturnSummary::for($label);
        $profit = (int) $s['net_profit_pence'];
        $p = TaxParameters::for($label);
        $scottish = (bool) Setting::get('scottish_taxpayer');
        $marginal = self::marginal($label, $profit);
        $items = [];
        $add = function (string $id, string $status, string $finding, ?int $deduction = null, ?int $saving = null) use (&$items, $marginal) {
            $saving ??= $deduction !== null && $marginal !== null ? self::pctOf($deduction, $marginal) : null;
            $items[] = ['id' => $id, 'status' => $status, 'finding' => $finding, 'deduction_pence' => $deduction, 'saving_pence' => $saving];
        };
        $date = fn (CarbonImmutable $d) => $d->format('j F Y');

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

        // Travel to venues, and nights and meals away (days that have happened only).
        $jobDays = self::jobDays($label, $today);
        if ($jobDays) {
            $covered = self::travelDays($label);
            $bare = array_values(array_diff($jobDays, $covered));
            if (count($bare) >= 3) {
                $add('itinerant-travel', 'check', count($bare).' of your '.count($jobDays).' job days '.($inProgress ? 'so far ' : '').'in '.$label.' have no travel cost or mileage claimed (for example '
                    .implode(', ', array_map(fn ($d) => CarbonImmutable::parse($d)->format('j M'), array_slice($bare, 0, 3))).'). Travel from home to a venue is allowable: add mileage, or the fares if you paid them.');
            }
            $away = self::nightsAway($label, $today);
            $subsistence = (int) Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->where('kind', 'business_expense')
                ->whereIn('category_id', Category::query()->where('code', 'travel_subsistence')->pluck('id'))->count();
            if ($away >= 2 && $subsistence === 0) {
                $add('meals-s57a', 'check', "You stayed away for {$away} nights on jobs ".($inProgress ? 'so far ' : '').'but no meals are claimed. Reasonable meals while away overnight for work are allowable; check your card statements for those days.');
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
        if ($p) {
            self::pension($label, $p, $profit, $inProgress, $scottish, $add);
        }
        if ($p && $profit > $p['personal_allowance_taper_from']) {
            $from = $p['personal_allowance_taper_from'];
            $to = $from + 2 * $p['personal_allowance'];
            $add('personal-allowance-and-hicbc', 'action', ($inProgress ? 'Your profit so far this year is over ' : 'Your profit is over ').self::pounds($from).', so your personal allowance is being withdrawn: '
                .($scottish
                    ? '£1 for every £2 of income above '.self::pounds($from).', until it’s gone at '.self::pounds($to).'. That makes the tax on income in that band much higher than your usual Scottish rate.'
                    : 'income between '.self::pounds($from).' and '.self::pounds($to).' is effectively taxed at about 60%.')
                .' Pension contributions or Gift Aid that bring adjusted income down restore it.');
        }

        // Losses: sideways and carry-back claims close a year after the filing date; carry-forward stays open.
        if ($profit < 0) {
            $loss = Money::format(-$profit, 'GBP', false);
            if ($inProgress) {
                $add('loss-relief', 'info', 'Your records show a loss so far of '.$loss.'. If the year ends in a loss, it can be set against other income or carried forward: you choose when you file.');
            } elseif ($today->lte($claimBy)) {
                $add('loss-relief', 'action', 'This year shows a loss of '.$loss.'. It can be set against your other income for this year or last year (claim by '.$date($claimBy).'), or carried forward against later profits from the same trade: the right choice depends on where it saves most tax. Worth asking an accountant.'
                    .($label < '2024-25' ? ' Before 2024-25, a loss worked out on the cash basis (as these figures are) could only be carried forward.' : ''));
            } else {
                $add('loss-relief', 'check', 'This year shows a loss of '.$loss.'. The deadline to set it against other income or carry it back was '.$date($claimBy)
                    .', so it can now only be carried forward against later profits from the same trade. Check your returns carry it forward.');
            }
        }

        // Payments on account: a claim to reduce them must be made before 31 January after the year (TMA s59A(3)), and only
        // makes sense before the return is filed. It needs confirmed income to compare with: with no income yet, or money
        // in still undecided, this year's tax would only look lower. While the year runs, compare a projection, never the records so far.
        if (! $filed && $today->lt($period->filingDeadline()) && $s['turnover_pence'] > 0
            && ! OwnAccounts::scopeNotTransfers(BankTransaction::query()->where('tax_year', $label)->whereIn('review_status', ['unreviewed', 'suggested'])->where('amount_pence', '>', 0))->exists()) {
            $prev = $period->previous()->label();
            $prevEst = TaxEstimator::estimate($prev, (int) ReturnSummary::for($prev)['net_profit_pence']);
            $basis = $inProgress ? ($period->startsOn()->addMonths(3)->lte($today) ? self::projection($period, $today, $profit) : null) : $profit;
            $thisEst = $basis !== null ? TaxEstimator::estimate($label, $basis) : null;
            if ($thisEst && $prevEst) {
                $prevRelevant = $prevEst['income_tax']['total_pence'] + $prevEst['class4_pence'];
                $thisRelevant = $thisEst['income_tax']['total_pence'] + $thisEst['class4_pence'];
                $each = 'Your payments on account for '.$label.' are based on '.$prev.' ('.Money::format(intdiv($prevRelevant, 2), 'GBP', false).' each)';
                if ($prevRelevant >= 100_000 && $thisRelevant < $prevRelevant - 20_000) {
                    if ($inProgress) {
                        $months = (int) floor($period->startsOn()->diffInMonths($today));
                        $confirm = ' Confirm your expected full-year profit first: reduce them too far and you pay interest on the shortfall.';
                        // A loss so far isn't scaled up: costs often come before the income they earn.
                        $add('payments-on-account', 'check', $profit <= 0
                            ? $each.'. Your records for the first '.$months.' months show '.($profit < 0 ? 'a loss so far ('.Money::format(-$profit, 'GBP', false).'). ' : 'no profit so far. ')
                                .'If you expect the whole year to make a loss or a much smaller profit, you can ask HMRC to reduce them (form SA303 or online).'.$confirm
                            : $each.'. Your records for the first '.$months.' months point to a full-year profit of about '.Money::format($basis, 'GBP', false)
                                .' (a simple projection, not a forecast), which would make them about '.Money::format(intdiv($thisRelevant, 2), 'GBP', false).' each. '
                                .'If you expect a full-year profit like that, you can ask HMRC to reduce them (form SA303 or online).'.$confirm);
                    } else {
                        $add('payments-on-account', 'action', $each.', but this year’s tax looks lower (about '.Money::format(intdiv($thisRelevant, 2), 'GBP', false).' each, an estimate). '
                            .'You can claim to reduce them (form SA303 or online) before '.$date($period->filingDeadline()).', and HMRC repays anything paid over the reduced amount. Reduce them too far and you pay interest on the shortfall.');
                    }
                }
            }
        }

        // Voluntary Class 2 and the trading allowance depend on the whole year, so they wait until it ends.
        $spt = $p['class2_voluntary']['small_profits_threshold'] ?? null;
        $weekly = $p['class2_voluntary']['weekly'] ?? null;
        // A loss means profits of nil, which is under the threshold too.
        if (! $inProgress && $spt && $weekly && max(0, $profit) < $spt && $s['turnover_pence'] > 0) {
            $add('voluntary-class-2', 'action', ($profit < 0 ? 'You made a loss, so your profit is under' : 'Your profit is under').' the small profits threshold ('.Money::format($spt, 'GBP', false).'), so this year doesn’t count towards your state pension unless you pay voluntary Class 2: '
                .Money::format($weekly, 'GBP', false).' a week, about '.Money::format($weekly * 52, 'GBP', false).' for the year, on your return. That is far cheaper than Class 3.');
        }
        // The allowance is capped at your income (ITTOIA s783AF), so it only helps when that beats your actual expenses.
        $receipts = $s['turnover_pence'] + $s['other_income_pence'];
        $allowance = min($receipts, (int) $s['trading_allowance']['allowance_pence']);
        $gain = $allowance - $s['allowable_expenses_pence'];
        if (! $inProgress && $s['turnover_pence'] > 0 && $gain > 0) {
            $add('trading-allowance-comparison', 'action', 'Your expenses ('.Money::format($s['allowable_expenses_pence'], 'GBP', false).') are less than the trading allowance you could claim instead ('
                .Money::format($allowance, 'GBP', false).($allowance < $s['trading_allowance']['allowance_pence'] ? ', as it can’t be more than your income' : '').'). Claiming it would cut your profit by '
                .Money::format($gain, 'GBP', false).' more than your expenses do, but it replaces all of them, including mileage and use of home.', $gain);
        }

        // VAT: the rolling 12-month test is about now, so it belongs on the current year's page, at today's threshold.
        if ($label === UkTaxYear::current()->label()) {
            $rolling = self::rollingTurnover();
            $threshold = $p['vat_threshold'] ?? 9_000_000;
            if ($rolling > $threshold) {
                $add('vat-review', 'action', 'Your income in the last 12 months ('.Money::format($rolling, 'GBP', false).') is over the VAT threshold ('.Money::format($threshold, 'GBP', false).'). You must register within 30 days of the end of the month you went over. Talk to an accountant now.');
            } elseif ($rolling > $threshold * 0.8) {
                $add('vat-review', 'check', 'Your income in the last 12 months is '.Money::format($rolling, 'GBP', false).', getting close to the VAT threshold ('.Money::format($threshold, 'GBP', false).'). Keep an eye on it; registering voluntarily (or the flat rate scheme) is worth modelling with an accountant.');
            }
        }

        // One client paying most of the year: keep evidence you're self-employed for each engagement.
        $share = self::topClientShare($label);
        if ($share && $share['pct'] >= 75 && $s['turnover_pence'] > 500_000) {
            $add('employment-status-evidence', 'check', $share['name'].' paid '.$share['pct'].'% of your income this year. Keep evidence that each engagement is self-employment (your own kit, control of how you work, several clients, the right to send someone else), which also protects your expense claims.');
        }

        // Things still undecided cost money if they're business costs. Before filing they simply go in the return; after
        // it, only while the return can still change.
        $pending = OwnAccounts::scopeNotTransfers(BankTransaction::query()->where('tax_year', $label)->whereIn('review_status', ['unreviewed', 'suggested'])->where('amount_pence', '<', 0))->count();
        if ($pending > 0) {
            // Overpayment relief: 4 years after the end of the tax year (TMA Sch 1AB para 3).
            $overpaidBy = $period->endsOn()->addYears(4);
            $undecided = $pending === 1 ? "1 payment in {$label} is still undecided." : "{$pending} payments in {$label} are still undecided.";
            if (! $filed) {
                $add('missed-claims-deadlines', 'action', $undecided.' Any that are business costs reduce your tax: include them in the return when you file it.'
                    .($today->lte($claimBy) ? ' After filing you can amend it until '.$date($claimBy).'.' : ''));
            } elseif ($today->lte($claimBy)) {
                $add('missed-claims-deadlines', 'action', $undecided.' Any that are business costs left out of the return reduce your tax: you can amend it until '.$date($claimBy)
                    .', and claim back overpaid tax until '.$date($overpaidBy).'.');
            } elseif ($today->lte($overpaidBy)) {
                $add('missed-claims-deadlines', 'action', $undecided.' The deadline to amend the return was '.$date($claimBy).'. If any are business costs left out of it, you can still claim back overpaid tax (overpayment relief) until '
                    .$date($overpaidBy).', though not for capital allowances.');
            } else {
                $add('missed-claims-deadlines', 'info', $undecided.' The deadlines to amend the return ('.$date($claimBy).') and to claim back overpaid tax ('.$date($overpaidBy).') have passed, so deciding them won’t change this year’s tax.');
            }
        }

        // Fines and interest: shown, but never counted as a saving (most are what happens if nothing is filed or paid).
        $fines = rescue(fn () => PenaltyEstimator::forYear($label), null, false);
        $finesDue = $fines ? (int) ($fines['penalties_pence'] + $fines['interest_pence']) : 0;
        if ($fines && ($finesDue > 0 || $fines['upcoming'])) {
            $add('penalties-and-interest', 'action', 'Fines and interest for '.$label.': '.Money::format($finesDue, 'GBP', false).' so far'
                .($fines['upcoming'] ? ', and '.Money::format($fines['upcoming_pence'], 'GBP', false).' more if nothing changes' : '').'. '.($fines['actions'][0] ?? ''));
        }

        // Zero-emission car: 100% first-year allowance on new ones bought by 5 April 2027.
        if ($today->lte(CarbonImmutable::create(2027, 4, 5)) && $label >= '2025-26') {
            $add('zero-emission-car-fya', 'info', 'If you’re buying a new car for the business, a new zero-emission car bought by 5 April 2027 gets a 100% first-year allowance (the business share). Don’t buy for the tax alone.');
        }

        $checklist = collect(TaxLaw::savingsChecklist())->keyBy('id');
        $present = fn (array $i) => $i + self::explain($checklist[$i['id']] ?? null, $i['finding']);
        $shown = array_map($present, $items);
        $listed = array_column($items, 'id');
        $more = $checklist->except($listed)->map(fn ($c) => ['id' => $c['id'], 'status' => 'idea'] + self::explain($c))->values()->all();
        usort($shown, fn ($a, $b) => [array_search($a['status'], ['action', 'check', 'info', 'ok'], true), -($a['saving_pence'] ?? 0)] <=> [array_search($b['status'], ['action', 'check', 'info', 'ok'], true), -($b['saving_pence'] ?? 0)]);

        return ['tax_year' => $label, 'in_progress' => $inProgress, 'marginal_pct' => $marginal, 'profit_pence' => $profit,
            'total_saving_pence' => self::total($items), 'penalties_to_avoid_pence' => $finesDue,
            'items' => $shown, 'more' => $more, 'researched_on' => TaxLaw::researchedOn(),
            'note' => ($inProgress ? 'This year isn’t over: figures are from your records so far, and checks that need the whole year wait until it ends. ' : '')
                .'Savings are estimates at your marginal rate, from your confirmed records. Only legal ways Parliament intends; points marked for an accountant need one.'];
    }

    /**
     * Extra relief on personal pension contributions, claimed on the return above the basic-rate relief the provider adds.
     * Contributions that bring income down to the top of the basic-rate band get more than 20%: 40%, about 60% where the
     * personal allowance is being withdrawn, and 45%. Income tax only (contributions don't reduce Class 4).
     */
    private static function pension(string $label, array $p, int $profit, bool $inProgress, bool $scottish, callable $add): void
    {
        $paidBy = $inProgress ? ' paid by '.UkTaxYear::fromLabel($label)->endsOn()->format('j F Y') : ' paid in '.$label;
        if ($scottish) {
            // Expend doesn't estimate Scottish income tax, so no rest-of-UK figures here.
            if ($profit > $p['personal_allowance']) {
                $add('pension-contributions', 'check', ($inProgress ? 'Your profit so far this year is over the personal allowance. ' : '')
                    .'As a Scottish taxpayer, personal pension contributions'.$paidBy.' get relief at your top Scottish rate of income tax. '
                    .'Your provider adds 20%; if your top rate is higher (the intermediate, higher, advanced and top rates are), the rest is claimed on your return. '
                    .'Expend doesn’t estimate Scottish tax, so check the amount with HMRC’s calculation.');
            }

            return;
        }

        $basicTop = $p['personal_allowance'] + $p['basic_rate_limit'];
        if ($profit <= $basicTop) {
            return;
        }
        $room = $profit - $basicTop;
        $cap = $p['pension_annual_allowance'] ?? null;
        $gross = $cap !== null ? min($room, $cap) : $room;
        $with = TaxEstimator::estimate($label, $profit);
        $without = TaxEstimator::estimate($label, $profit - $gross);
        if (! $with || ! $without) {
            return;
        }
        // Relief at source extends the bands by the gross contribution, which comes to the tax on (profit - gross) plus 20% of it.
        $extra = $with['income_tax']['total_pence'] - $without['income_tax']['total_pence'] - Percent::of($gross, $p['rates']['basic'], RoundingMode::DOWN);
        if ($extra <= 0) {
            return;
        }

        // Which rates the contribution comes off, as bands of profit (lowest first).
        $taperFrom = $p['personal_allowance_taper_from'];
        $taperTo = $taperFrom + 2 * $p['personal_allowance'];
        $additionalFrom = max($p['additional_rate_threshold'], $taperTo);
        $bands = [[$basicTop, $taperFrom, $p['rates']['higher'].'%'], [$taperFrom, $taperTo, 'about 60% (where your personal allowance is being withdrawn)'],
            [$taperTo, $additionalFrom, $p['rates']['higher'].'%'], [$additionalFrom, PHP_INT_MAX, $p['rates']['additional'].'%']];
        $rates = [];
        foreach ($bands as [$lo, $hi, $name]) {
            if ($profit - $gross < $hi && $profit > $lo && ! in_array($name, $rates, true)) {
                $rates[] = $name;
            }
        }
        $rateText = count($rates) > 1 ? implode(', ', array_slice($rates, 0, -1)).' and '.end($rates) : $rates[0];

        $add('pension-contributions', 'action', ($inProgress ? 'On your profit so far this year, personal' : 'Personal').' pension contributions'.$paidBy.', up to '.Money::format($gross, 'GBP', false)
            .' gross, get relief at '.$rateText.' rather than the 20% your provider adds: about '.Money::format($extra, 'GBP', false).' of extra relief (an estimate), claimed on your return.'
            .($cap !== null && $room > $cap ? ' That is capped at the '.self::pounds($cap).' annual allowance; unused allowance from the three years before can be carried forward if you were in a pension scheme then.' : '')
            .' Contributions are paid from taxed income, so it’s a saving only if you want the pension.', null, $extra);
    }

    /** Profit for the whole year, scaled up from the days so far. A labelled projection, not a forecast. */
    private static function projection(UkTaxYear $period, CarbonImmutable $today, int $profit): int
    {
        $days = (int) round($period->startsOn()->diffInDays($period->endsOn())) + 1;
        $elapsed = max(1, (int) round($period->startsOn()->diffInDays($today)) + 1);

        return (int) round($profit * $days / $elapsed);
    }

    /**
     * The headline: what the suggestions could save together. Fines aren't savings, and the trading allowance replaces
     * actual expenses, so it counts as an alternative to the new expense claims (whichever is more), once.
     */
    private static function total(array $items): int
    {
        $claims = 0;
        $allowance = 0;
        $other = 0;
        foreach ($items as $i) {
            $saving = (int) ($i['saving_pence'] ?? 0);
            if ($i['id'] === 'trading-allowance-comparison') {
                $allowance = $saving;
            } elseif ($i['deduction_pence'] !== null) {
                $claims += $saving;
            } else {
                $other += $saving;
            }
        }

        return $other + max($claims, $allowance);
    }

    /**
     * The checklist's wording and the law behind an item, with links (citable sources only). needs_accountant is there
     * (true) only when the idea or what was found says to ask one ("Talk to an accountant now"): the checklist doesn't
     * record when one isn't needed, so there is never a "false".
     */
    private static function explain(?array $c, string $finding = ''): array
    {
        if (! $c) {
            return [];
        }
        $law = TaxLaw::presentSaving($c);
        $sources = [];
        foreach ($law['legal_basis'] ?? [] as $basis) {
            if (! empty($basis['url'])) {
                // The note says when a link opens something other than the title (HMRC's summary of a case, or a later case that applies it).
                $sources[] = ['title' => $basis['name'] ?? $basis['id'], 'url' => $basis['url']] + (empty($basis['source_note']) ? [] : ['source_note' => $basis['source_note']]);
            }
        }
        $accountant = ($law['needs_accountant'] ?? false) || preg_match('/\b(?:accountant|adviser)\b/i', $finding);

        return ['title' => $c['title'] ?? '', 'conditions' => $c['conditions'] ?? null, 'how' => $c['how_to_estimate'] ?? null, 'risks' => $c['risks'] ?? null]
            + ($accountant ? ['needs_accountant' => true] : []) + ['sources' => array_slice($sources, 0, 6)];
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

    /** Whole-pound figures (thresholds and allowances) without the pence. */
    private static function pounds(int $pence): string
    {
        $text = Money::format($pence, 'GBP', false);

        return str_ends_with($text, '.00') ? substr($text, 0, -3) : $text;
    }

    /** Whether the year claims anything in this category (confirmed only, as on the return). */
    private static function hasCategory(string $label, string $code): bool
    {
        return Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->where('kind', 'business_expense')
            ->whereIn('category_id', Category::query()->where('code', $code)->pluck('id'))->exists();
    }

    /** @return list<string> the year's job days up to today (booked days still to come aren't counted) */
    private static function jobDays(string $label, CarbonImmutable $today): array
    {
        $y = UkTaxYear::fromLabel($label);
        $until = $today->format('Y-m-d');
        $days = [];
        WorkJob::query()->with('days')->where('status', '!=', 'cancelled')
            ->whereDate('ends_on', '>=', $y->startsOn()->format('Y-m-d'))->whereDate('starts_on', '<=', $y->endsOn()->format('Y-m-d'))->get()
            ->each(function (WorkJob $j) use (&$days, $y, $until) {
                foreach ($j->workDates() as $d) {
                    if ($d <= $until && $y->contains($d)) {
                        $days[$d] = true;
                    }
                }
            });
        ksort($days);

        return array_keys($days);
    }

    /** @return list<string> days with mileage or a confirmed travel cost, paid from an account or in cash */
    private static function travelDays(string $label): array
    {
        $days = MileageTrip::query()->where('tax_year', $label)->pluck('trip_on')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $travel = Category::query()->where('code', 'like', 'travel%')->pluck('id');
        $txDays = Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->where('kind', 'business_expense')->whereIn('category_id', $travel)
            ->with(['transaction:id,posted_on,transaction_on', 'manualExpense:id,spent_on'])->get()
            ->map(fn ($a) => $a->transaction?->effectiveDate()->format('Y-m-d') ?? $a->manualExpense?->spent_on?->format('Y-m-d'))->filter()->all();

        return array_values(array_unique([...$days, ...$txDays]));
    }

    /** Nights in hotels on jobs in the year that have passed: a stay still going counts only the nights before today. */
    private static function nightsAway(string $label, CarbonImmutable $today): int
    {
        $y = UkTaxYear::fromLabel($label);
        $from = $y->startsOn()->format('Y-m-d');
        $before = ($today->lte($y->endsOn()) ? $today : $y->endsOn()->addDay())->format('Y-m-d');

        return (int) TravelItem::query()->where('kind', 'hotel')->where('status', '!=', 'cancelled')->whereNotNull('work_job_id')
            ->whereDate('starts_on', '<', $before)->where(fn ($q) => $q->whereDate('starts_on', '>=', $from)->orWhereDate('ends_on', '>', $from))
            ->get()->sum(fn (TravelItem $t) => count(array_filter($t->nights(), fn (string $n) => $n >= $from && $n < $before)));
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
