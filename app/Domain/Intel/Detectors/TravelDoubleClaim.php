<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Travel\Places;
use App\Models\Allocation;
use App\Models\Category;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Travel claimed twice (travel.double_claim, M4). Three ways, each from your own records:
 *
 *  - mileage and a train (plane, coach or hire car) for the same job, the trip on the day the journey started (or came
 *    back), the journey decided by you: if the trip went to the job's venue, you went one way or the other, so only one
 *    can be claimed (JobTrips leaves such jobs out for the same reason). A trip to a station, airport or port is how
 *    you got to the train or plane, and both are claimed: never listed. A trip that doesn't say where it went is
 *    graded possible, to check;
 *  - a second trip from home within one hotel stay, on a morning you woke up at that hotel: one return trip covers a
 *    stay (as JobTrips counts it), so the trip from home that morning didn't happen. The hotel is chosen as JobTrips
 *    chooses it: linked to the job, or any other stay (not cancelled or personal) you woke up at on the morning of
 *    one of its trips (graded likely: it isn't linked to the job);
 *  - fuel recorded as a business cost while every vehicle you have uses simplified mileage, which already covers fuel
 *    (fuel for a hire car is fine: a line on a day you had one, or for a job you hired one for, is left out).
 *
 * What's at risk is the mileage claim (as MileageCalculator values it) or the fuel, so the amount is negative, as
 * money you'd lose. Grade certain when the journey or stay was decided by you and the trip went to the venue, likely
 * when it's only linked to the job (or the stay isn't linked to it), possible when where the trip went can't be told.
 * Only reads.
 */
final class TravelDoubleClaim implements Detector
{
    public const CODE = 'travel.double_claim';

    public const VERSION = 2;

    /** Where a trip went that's a step to a train, plane, coach or ferry, not the venue. */
    private const HUB = '/\b(airport|station|terminal|port|ferry|harbour|parkway|eurotunnel|interchange)\b/i';

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

    /** The trips the last detect() looked at (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $trips = MileageTrip::query()->whereNotNull('work_job_id')->with(['vehicle:id,name,type,method', 'job:id,title,postcode,location'])->orderBy('trip_on')->orderBy('id')
            ->get(['id', 'vehicle_id', 'work_job_id', 'trip_on', 'tax_year', 'from_place', 'to_place', 'miles']);
        $usable = fn ($q) => $q->where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'));
        $travel = $trips->isEmpty() ? collect() : TravelItem::query()->whereIn('work_job_id', $trips->pluck('work_job_id')->unique()->values()->all())->where($usable)
            ->whereIn('kind', ['hotel', ...JobTrips::JOURNEYS])->get(['id', 'kind', 'provider', 'place', 'destination', 'postcode', 'starts_on', 'ends_on', 'work_job_id', 'decision', 'decided_at'])
            ->groupBy('work_job_id');
        // Stays not linked to the trip's job (linked to none, or to another): one you woke up at on a trip's morning counts.
        $stays = $trips->isEmpty() ? collect() : TravelItem::query()->where('kind', 'hotel')->where($usable)
            ->whereDate('starts_on', '<=', $trips->max('trip_on')->format('Y-m-d'))
            ->where(fn ($q) => $q->whereDate('ends_on', '>=', $trips->min('trip_on')->copy()->subDay()->format('Y-m-d'))->orWhere(fn ($q) => $q->whereNull('ends_on')
                ->whereDate('starts_on', '>=', $trips->min('trip_on')->copy()->subDays(2)->format('Y-m-d'))))
            ->get(['id', 'kind', 'provider', 'place', 'starts_on', 'ends_on', 'work_job_id', 'decision', 'decided_at']);
        $home = Places::normalise((string) Setting::get('home_postcode'));
        $fromHome = fn (MileageTrip $t) => preg_match('/\bhome\b/i', (string) $t->from_place) === 1 || ($home !== null && Places::normalise((string) $t->from_place) === $home);

        /** @var array<int, array{trip:MileageTrip, why:string, title:string, item:TravelItem, grade:string}> $found */
        $found = [];
        foreach ($trips->groupBy('work_job_id') as $jobId => $jobTrips) {
            $items = $travel[$jobId] ?? collect();
            // Mileage and a journey you decided, on the day it started or came back.
            foreach ($jobTrips as $t) {
                $day = $t->trip_on->format('Y-m-d');
                $journey = $items->first(fn (TravelItem $i) => in_array($i->kind, JobTrips::JOURNEYS, true) && $i->decided_at !== null
                    && ($i->starts_on->format('Y-m-d') === $day || $i->ends_on?->format('Y-m-d') === $day));
                if (! $journey) {
                    continue;
                }
                $where = self::whereTo($t, $journey);
                if ($where === 'hub') {
                    continue; // drove to the station or airport and went on: both are claimed
                }
                $by = self::journeyWord($journey, false);
                $found[$t->id] = ['trip' => $t, 'item' => $journey, 'grade' => $where === 'venue' ? Grades::CERTAIN : Grades::POSSIBLE,
                    'title' => 'Mileage and '.self::journeyWord($journey).' on '.$t->trip_on->format('j M Y').': '.($t->job?->title ?? 'a job'),
                    'why' => 'You claimed '.self::miles($t).' miles that day and also went by '.$by.' ('.$journey->provider.', decided as '
                        .mb_strtolower(TravelItem::DECISIONS[$journey->decision] ?? 'business').'). '.($where === 'venue'
                            ? 'The trip went to the venue, so only one of them can be claimed: if you went by '.$by.', remove the trip on the Mileage page.'
                            : 'Check the trip wasn’t to the station or airport (driving there and going on by '.$by.' is fine to claim both, and saying so on the trip keeps it off this list). '
                                .'If you drove all the way, only one of them can be claimed: remove the trip on the Mileage page.')];
            }
            // A second trip from home within one stay, on a morning you woke up at the hotel: a stay linked to the job,
            // or another you woke up at on the morning of one of its trips from home.
            $days = $jobTrips->filter($fromHome)->map(fn (MileageTrip $t) => $t->trip_on->format('Y-m-d'))->flip();
            $hotels = $items->where('kind', 'hotel')->concat($stays->filter(fn (TravelItem $h) => (int) $h->work_job_id !== (int) $jobId
                && collect($h->nights())->contains(fn ($n) => isset($days[CarbonImmutable::parse($n)->addDay()->format('Y-m-d')]))));
            foreach ($hotels as $hotel) {
                $nights = $hotel->nights();
                if ($nights === []) {
                    continue;
                }
                $woke = array_flip(array_map(fn ($n) => CarbonImmutable::parse($n)->addDay()->format('Y-m-d'), $nights));
                $from = $nights[0];
                $to = CarbonImmutable::parse(end($nights))->addDay()->format('Y-m-d');
                $within = $jobTrips->filter(fn (MileageTrip $t) => $fromHome($t) && $t->trip_on->format('Y-m-d') >= $from && $t->trip_on->format('Y-m-d') <= $to)->values();
                if ($within->count() < 2) {
                    continue; // one return trip covers the stay
                }
                $linked = (int) $hotel->work_job_id === (int) $jobId;
                foreach ($within->slice(1) as $t) {
                    if (! isset($woke[$t->trip_on->format('Y-m-d')]) || isset($found[$t->id])) {
                        continue;
                    }
                    $name = $hotel->place ?: $hotel->provider;
                    $found[$t->id] = ['trip' => $t, 'item' => $hotel, 'grade' => $linked && $hotel->decided_at !== null ? Grades::CERTAIN : Grades::LIKELY,
                        'title' => 'Mileage from home after a night at '.$name.': '.($t->job?->title ?? 'a job').', '.$t->trip_on->format('j M Y'),
                        'why' => 'You woke up at '.$name.' that morning'.($linked ? '' : ' (a stay not linked to this job)').', and the trip on '.$within->first()->trip_on->format('j M')
                            .' already covers getting there and back for this stay. Remove the extra trip on the Mileage page, or correct where it started.'];
                }
            }
        }

        $drafts = [];
        $claims = [];
        foreach (collect($found)->pluck('trip')->pluck('tax_year')->unique() as $label) {
            foreach (MileageCalculator::forYear($label)['trips'] as $row) {
                $claims[$row['id']] = (int) $row['claim_pence'];
            }
        }
        foreach ($found as $id => $f) {
            $t = $f['trip'];
            $claim = $claims[$id] ?? 0;
            $drafts[] = new FindingDraft(
                key: 'double:trip:'.$id, code: self::CODE, group: 'tax', priority: 2, title: $f['title'],
                detail: $f['why'].($claim > 0 ? ' The trip claims '.Money::format($claim, 'GBP', false).'.' : ''),
                href: '/mileage?year='.$t->tax_year, subjectRef: Ref::make('trip', $id), scopeRef: Ref::make('job', $t->work_job_id),
                evidence: [Ref::make('travel', $f['item']->id), Ref::make('job', $t->work_job_id)],
                amountPence: $claim > 0 ? -$claim : null, direction: 'at_risk', grade: $f['grade'],
                detectorVersion: self::VERSION,
            );
        }
        array_push($drafts, ...$this->fuel());
        $this->checked = $trips->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * Fuel recorded as a business cost while every vehicle uses simplified mileage: one finding per tax year, less fuel
     * on a day you had a hire car (or for a job you hired one for).
     *
     * @return list<FindingDraft>
     */
    private function fuel(): array
    {
        $vehicles = Vehicle::query()->get(['id', 'method', 'type']);
        if ($vehicles->isEmpty() || $vehicles->contains(fn (Vehicle $v) => $v->method !== 'simplified')) {
            return [];
        }
        $fuel = Category::query()->where('code', 'travel_fuel')->value('id');
        if (! $fuel) {
            return [];
        }
        // Fuel on a bank line, and fuel you added yourself (by hand, or paid in cash or by a personal card on the phone).
        $lines = Allocation::query()->where('kind', 'business_expense')->where('category_id', $fuel)->whereNotNull('confirmed_at')
            ->where(fn ($q) => $q->whereNotNull('bank_transaction_id')->orWhereNotNull('manual_expense_id'))
            ->with(['transaction:id,posted_on,transaction_on,amount_pence,tax_year', 'manualExpense:id,spent_on'])->orderBy('id')
            ->get(['id', 'bank_transaction_id', 'manual_expense_id', 'tax_year', 'amount_pence', 'work_job_id']);
        if ($lines->isEmpty()) {
            return [];
        }
        $hires = TravelItem::query()->where('kind', 'car_hire')->where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'))
            ->get(['id', 'starts_on', 'ends_on', 'work_job_id']);
        $hiredJobs = $hires->pluck('work_job_id')->filter()->flip();
        $hiredOn = function (CarbonImmutable $day) use ($hires): bool {
            foreach ($hires as $h) {
                if ($day->gte(CarbonImmutable::parse($h->starts_on->format('Y-m-d'))->subDay()) && $day->lte(CarbonImmutable::parse(($h->ends_on ?? $h->starts_on)->format('Y-m-d'))->addDay())) {
                    return true;
                }
            }

            return false;
        };
        $counted = $lines->filter(function (Allocation $a) use ($hiredJobs, $hiredOn) {
            $on = $a->bank_transaction_id !== null ? $a->transaction?->effectiveDate() : $a->manualExpense?->spent_on;
            if ($on === null) {
                return false;
            }
            if ($a->work_job_id !== null && isset($hiredJobs[$a->work_job_id])) {
                return false;
            }

            return ! $hiredOn(CarbonImmutable::parse($on->format('Y-m-d')));
        });
        $drafts = [];
        foreach ($counted->groupBy('tax_year') as $label => $as) {
            $total = (int) $as->sum('amount_pence');
            if ($total >= 0) {
                continue; // refunds as much as was spent
            }
            $drafts[] = new FindingDraft(
                key: 'double:fuel:'.$label, code: self::CODE, group: 'tax', priority: 2,
                title: 'Fuel claimed as well as simplified mileage: '.Money::format(abs($total), 'GBP', false).' in '.$label,
                detail: $as->count().' fuel '.($as->count() === 1 ? 'payment is' : 'payments are').' recorded as a business cost, but every vehicle you have uses simplified mileage, '
                    .'which already covers fuel. Fuel for a hire car is fine (it’s left out here). Change the rest to personal, or move the vehicle to actual costs on the Mileage page.',
                href: '/transactions?year='.$label.'&category='.$this->fuelCategory(), subjectRef: Ref::make('year', $label), scopeRef: Ref::make('year', $label),
                evidence: $as->take(19)->map(fn (Allocation $a) => $a->bank_transaction_id !== null ? Ref::make('tx', $a->bank_transaction_id) : Ref::make('exp', $a->manual_expense_id))->all(),
                amountPence: $total, direction: 'at_risk', grade: Grades::CERTAIN, detectorVersion: self::VERSION,
            );
        }

        return $drafts;
    }

    private function fuelCategory(): ?int
    {
        return Category::query()->where('code', 'travel_fuel')->value('id');
    }

    /**
     * Where a trip went, beside a journey on the same day: 'venue' (the job's postcode or venue, or where the train or
     * plane went: it replaced the journey), 'hub' (a station, airport or port, or where the journey left from: the
     * way to it), or null when the trip doesn't say.
     */
    private static function whereTo(MileageTrip $t, TravelItem $journey): ?string
    {
        $to = mb_strtolower((string) $t->to_place);
        $squash = fn (?string $v) => strtoupper(preg_replace('/\s+/', '', (string) $v));
        $has = fn (?string $place) => $place !== null && mb_strlen(trim($place)) >= 4 && str_contains($to, mb_strtolower(trim($place)));
        $job = $t->job;
        if (($job?->postcode && str_contains($squash($t->to_place), $squash($job->postcode))) || $has($job?->location)
            || ($journey->postcode && str_contains($squash($t->to_place), $squash($journey->postcode))) || $has($journey->destination)) {
            return 'venue';
        }
        if (preg_match(self::HUB, $to) || $has($journey->place)) {
            return 'hub';
        }

        return null;
    }

    private static function miles(MileageTrip $t): string
    {
        return rtrim(rtrim(number_format((float) $t->miles, 1, '.', ','), '0'), '.');
    }

    private static function journeyWord(TravelItem $i, bool $article = true): string
    {
        $word = ['rail' => 'train', 'flight' => 'plane', 'coach' => 'coach', 'car_hire' => 'hire car'][$i->kind] ?? 'other travel';

        return $article ? ($i->kind === 'car_hire' ? 'a hire car' : 'a '.$word) : $word;
    }
}
