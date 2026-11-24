<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\TaxParameters;
use App\Domain\Todo\TodoList;
use App\Domain\Travel\Places;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mileage you could claim (travel.unclaimed, M1): for each open tax year (not locked or filed; the current one even
 * if you're not tracking it yet), the finished jobs you drove to with no mileage recorded, as the Mileage page offers
 * them (JobTrips::proposals), valued at the year's simplified mileage rates with the miles already recorded that year
 * counting towards the 10,000-mile threshold (for all your cars and vans together, or only the one the trips would go
 * on when the threshold is per vehicle). Left out: jobs you reached by train, plane, coach or hire car (JobTrips'
 * own rule: JobTrips::journeys(); a hire car's cost is claimed instead) and jobs you said had no mileage to claim.
 * Without a home postcode there's one item asking for it instead. Only vehicles on actual costs: nothing to claim, so
 * nothing's listed.
 *
 * Distances come only from postcodes already looked up (the Mileage page and the travel checks look them up):
 * finding them never sends a postcode anywhere. Where one isn't known yet, the item says so and points to the Mileage
 * page instead of giving the miles.
 *
 * Each item is kept by its jobs (mileage:{year}:{set}), so "Not relevant" says those jobs have no mileage to claim
 * (they're left out from then on: TodoList::MILEAGE_JOB), and a job that finishes later brings it back. With one
 * vehicle on simplified mileage, an item whose miles are known offers "Add the trips" (TodoActions' trips_add, bound to
 * the jobs, the vehicle and the miles shown: actionId()); with none or several, the link to the Mileage page is the way. Only reads:
 * the to-do list keeps what it found (TodoList::mileageFound()), and detect() uses that when it's still current.
 */
final class TravelUnclaimed implements Detector
{
    public const CODE = 'travel.unclaimed';

    public const VERSION = 1;

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

    /** The finished jobs the last detect() looked at (null: it didn't finish). */
    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $hidden = TodoDismissal::activeKeys();
        $found = TodoList::mileageKept($hidden) ?? self::found(self::excluded($hidden));
        $this->checked = $found['jobs'];
        $this->coverage = Coverage::Complete;

        return array_map(fn (array $i) => self::draft($i), $found['items']);
    }

    /**
     * The jobs you said had no mileage to claim (dismissals kept as TodoList::MILEAGE_JOB.{job}:{set}).
     *
     * @param  array<string, true>  $hidden  keys you snoozed or marked not relevant
     * @return array<int, true>
     */
    public static function excluded(array $hidden): array
    {
        $excluded = [];
        foreach (array_keys($hidden) as $k) {
            if (str_starts_with($k, TodoList::MILEAGE_JOB)) {
                $excluded[(int) substr($k, strlen(TodoList::MILEAGE_JOB))] = true;
            }
        }
        ksort($excluded);

        return $excluded;
    }

    /** The finding a mileage item is. */
    public static function draft(array $i): FindingDraft
    {
        $setup = str_starts_with($i['key'], 'mileage:setup:');

        return new FindingDraft(
            key: $i['key'], code: self::CODE, group: 'claims', priority: 2, title: $i['title'], detail: $i['detail'], href: $i['href'],
            subjectRef: $setup ? 'set:home_postcode' : 'year:'.$i['year'], scopeRef: $setup ? null : 'year:'.$i['year'],
            evidence: array_map(fn ($id) => 'job:'.$id, array_slice($i['job_ids'], 0, 19)),
            amountPence: isset($i['extra']['amount']) ? (int) $i['extra']['amount'] : null, direction: $setup ? 'none' : 'claimable',
            grade: $setup ? null : Grades::forCode(self::CODE)[0], actions: $i['actions'] ?? [], meta: ['job_ids' => $i['job_ids']], detectorVersion: self::VERSION,
        );
    }

    /**
     * The mileage items and how many finished jobs were looked at, worked out now.
     *
     * @param  array<int, true>  $excluded  jobs you said had no mileage to claim
     * @return array{items:list<array{key:string, year:?string, title:string, detail:string, href:string, extra:array, job_ids:list<int>}>, jobs:int}
     */
    public static function found(array $excluded): array
    {
        // What the trips would go on: a car or van on simplified mileage (or none yet: you'd add one), else a motorbike.
        // The car or van is the one the Mileage page offers first (by name).
        $vehicles = Vehicle::query()->orderBy('name')->get(['id', 'name', 'type', 'method']);
        $simplified = $vehicles->where('method', 'simplified');
        $type = $vehicles->isEmpty() || $simplified->contains('type', 'car_van') ? 'car_van' : ($simplified->contains('type', 'motorcycle') ? 'motorcycle' : null);
        if ($type === null) {
            return ['items' => [], 'jobs' => 0];
        }
        $vans = $simplified->where('type', 'car_van')->values();
        $perVehicle = Setting::get('mileage_threshold_scope') === 'per_vehicle';
        $current = UkTaxYear::current()->label();
        $tracked = TaxYear::query()->get(['label', 'status', 'locked_at'])->keyBy('label');
        $years = $tracked->filter(fn (TaxYear $y) => $y->locked_at === null && $y->status !== 'filed')->keys()->all();
        if (! isset($tracked[$current])) {
            $years[] = $current;
        }
        sort($years);
        $home = Setting::get('home_postcode');
        $today = today()->format('Y-m-d');
        $items = [];
        $item = function (string $key, ?string $year, string $title, string $detail, string $href, array $jobIds, array $extra = [], array $actions = []) use (&$items) {
            $items[] = ['key' => $key, 'year' => $year, 'title' => $title, 'detail' => $detail, 'href' => $href, 'extra' => $extra, 'job_ids' => array_values($jobIds), 'actions' => $actions];
        };
        // "Add the trips" needs to know what they go on: offered only with one vehicle on simplified mileage.
        $only = self::onlyVehicle();
        $set = function (array $ids) {
            sort($ids);

            return substr(md5(implode(',', $ids)), 0, 10);
        };
        $seen = [];
        $noHome = [];
        foreach ($years as $label) {
            $period = UkTaxYear::fromLabel($label);
            // Finished as JobTrips counts it: its last working day is done.
            $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', min($period->endsOn()->format('Y-m-d'), $today))
                ->whereDate('ends_on', '>=', $period->startsOn()->format('Y-m-d'))->with('days')->withExists('mileageTrips')->get()
                ->filter(function (WorkJob $j) use ($today) {
                    $dates = $j->workDates();

                    return $dates !== [] && end($dates) <= $today;
                })->keyBy('id');
            $seen += array_fill_keys($jobs->keys()->all(), true);
            // As JobTrips picks them (any postcode at all, so one it can't read is counted when looking up yours).
            $candidates = $jobs->filter(fn (WorkJob $j) => ! $j->mileage_trips_exists && $j->postcode !== null);
            $waiting = $candidates->reject(fn (WorkJob $j) => isset($excluded[$j->id]));
            if ($waiting->isEmpty()) {
                continue;
            }
            if (! $home) {
                $noHome += array_fill_keys($waiting->filter(fn (WorkJob $j) => filled($j->postcode))->keys()->all(), true);

                continue;
            }
            $href = '/mileage?year='.$label;
            // How you reached each job, if not in your own vehicle (train, plane, coach or hire car): JobTrips leaves
            // those jobs out by the same rule, and never looks their postcodes up.
            $journeys = JobTrips::journeys($candidates);
            $mayDrive = $waiting->reject(fn (WorkJob $j) => isset($journeys[$j->id]))->keys()->all();
            if (self::lookupsNeeded($home, $candidates->reject(fn (WorkJob $j) => isset($journeys[$j->id])))) {
                // Working the miles out would look a postcode up: the Mileage page does that, not the list.
                $n = count($mayDrive);
                if ($n > 0) {
                    $item('mileage:'.$label.':'.$set($mayDrive), $label, $n.' finished '.($n === 1 ? 'job' : 'jobs').' may have mileage to claim',
                        $label.': how far '.($n === 1 ? 'it is' : 'some of them are').' from home hasn’t been worked out yet. Open the Mileage page for '.$label.' to see the miles (each postcode is looked up once). '
                        .'If you didn’t drive to '.($n === 1 ? 'it' : 'any of them').', mark this not relevant and '.($n === 1 ? 'it' : 'they').' won’t be listed again.', $href, $mayDrive);
                }

                continue;
            }
            $trips = array_values(array_filter(JobTrips::proposals($label), fn ($p) => isset($waiting[$p['job_id']])));
            if ($trips === []) {
                continue;
            }
            $miles = array_sum(array_column($trips, 'miles'));
            $rates = TaxParameters::for($label)['mileage'] ?? null;
            $value = null;
            $on = null;
            if ($rates && $type === 'motorcycle') {
                $value = (int) round($miles * $rates['motorcycle']);
            } elseif ($rates) {
                // Miles already on cars and vans this year come first towards the threshold (as MileageCalculator counts
                // them): on all of them together, or with a threshold for each vehicle, on the one these would go on.
                $on = $perVehicle ? $vans->first() : null;
                $already = $perVehicle && ! $on ? 0.0 : (float) MileageTrip::query()->where('tax_year', $label)
                    ->whereHas('vehicle', fn ($q) => $q->where('type', 'car_van')->where('method', 'simplified')->when($on, fn ($q) => $q->whereKey($on->id)))->sum('miles');
                $first = max(0.0, min($miles, $rates['threshold_miles'] - $already));
                $value = (int) round($first * $rates['car_van_first'] + ($miles - $first) * $rates['car_van_after']);
            }
            $n = count($trips);
            $titles = array_column($trips, 'title');
            $rate = $rates ? ($type === 'motorcycle' ? $rates['motorcycle'].'p a mile' : $rates['car_van_first'].'p a mile ('.$rates['car_van_after'].'p after '.number_format($rates['threshold_miles']).' miles in the year)') : null;
            $leftOut = $waiting->filter(fn (WorkJob $j) => in_array('car_hire', $journeys[$j->id] ?? [], true))->count();
            $key = 'mileage:'.$label.':'.$set(array_column($trips, 'job_id'));
            $item($key, $label, $n.' finished '.($n === 1 ? 'job' : 'jobs').' with no mileage: about '.number_format($miles).' miles'
                .($value !== null ? ', about £'.number_format($value / 100).' to claim' : ''),
                implode(' ', array_filter([
                    $label.': '.implode(', ', array_slice($titles, 0, 3)).($n > 3 ? ' and '.($n - 3).' more' : '').'.',
                    'The miles are estimated from postcodes (the straight line × '.JobTrips::ROAD_FACTOR.' for roads)'.($rate ? ' at '.$rate : '').': check them on the Mileage page, where they’re added in one go.',
                    $on && $vans->count() > 1 ? 'Valued as trips on '.$on->name.', the vehicle the Mileage page offers first: each of your vehicles has its own 10,000 miles.' : null,
                    $leftOut > 0 ? $leftOut.' '.($leftOut === 1 ? 'job' : 'jobs').' you went to by hire car '.($leftOut === 1 ? 'is' : 'are').' left out: claim the hire and its fuel instead.' : null,
                    'If you didn’t drive to '.($n === 1 ? 'it' : 'any of them').', mark this not relevant and '.($n === 1 ? 'it' : 'they').' won’t be listed again.',
                ])), $href, array_column($trips, 'job_id'), $value !== null ? ['amount' => $value] : [],
                $only ? [TodoList::action('trips_add', self::actionId($key, $only->id, $trips), 'Add the trips to '.$only->name)] : []);
        }
        if ($noHome !== []) {
            $n = count($noHome);
            $item('mileage:setup:'.$set(array_keys($noHome)), null, 'Add your home postcode to find mileage to claim',
                $n.' finished '.($n === 1 ? 'job has' : 'jobs have').' a venue postcode and no mileage. Expend works out the miles from your home postcode: add it in Settings › Ledger.', '/settings/ledger', []);
        }

        return ['items' => $items, 'jobs' => count($seen)];
    }

    /** The one vehicle on simplified mileage (a car, van or motorbike) trips can go on, or null with none or several. */
    public static function onlyVehicle(): ?Vehicle
    {
        $simplified = Vehicle::query()->where('method', 'simplified')->whereIn('type', ['car_van', 'motorcycle'])->get(['id', 'name', 'type', 'method']);

        return $simplified->count() === 1 && Vehicle::query()->where('method', 'simplified')->count() === 1 ? $simplified->first() : null;
    }

    /**
     * The id "Add the trips" carries for an item (mileage:{year}:{set}), as a to-do button takes it: {first year}:{set}
     * :{vehicle}:{miles}, binding it to what was shown: the jobs (the set), the vehicle the trips go on, and the miles
     * (a short hash of each job's trips and miles). Within Telegram's 64 bytes.
     *
     * @param  list<array{job_id:int, one_way:float, trips:int}>  $trips  JobTrips::proposals()'s, for the item's jobs
     */
    public static function actionId(string $key, int $vehicleId, array $trips): string
    {
        [, $label, $set] = explode(':', $key);
        $miles = collect($trips)->sortBy('job_id')->map(fn ($t) => $t['job_id'].'='.$t['one_way'].'x'.$t['trips'])->implode(',');

        return substr($label, 0, 4).':'.$set.':'.$vehicleId.':'.substr(md5($miles), 0, 8);
    }

    /** The item an "Add the trips" id is for ({first year}:{set}, then what binds it), or null for one that isn't. */
    public static function keyFor(string $actionId): ?string
    {
        if (! preg_match('/^(\d{4}):([0-9a-f]{10})(?::\d+:[0-9a-f]{8})?$/', $actionId, $m)) {
            return null;
        }

        return 'mileage:'.UkTaxYear::fromLabel($m[1].'-'.substr((string) ((int) $m[1] + 1), 2))->label().':'.$m[2];
    }

    /**
     * Whether JobTrips::proposals would look a postcode up (yours or a venue's) for any of these jobs, because it isn't
     * known yet. Finding mileage never does: the Mileage page does it, once for each postcode.
     *
     * @param  Collection<int, WorkJob>  $jobs  the jobs JobTrips would work the miles out for
     */
    private static function lookupsNeeded(string $home, Collection $jobs): bool
    {
        $homeCode = Places::normalise($home);
        if ($homeCode === null || $jobs->isEmpty()) {
            return false; // not a postcode: nothing is looked up (and no distance found)
        }
        $codes = $jobs->map(fn (WorkJob $j) => Places::normalise($j->postcode));
        $known = DB::table('places')->whereIn('postcode', $codes->filter()->push($homeCode)->unique()->values()->all())->pluck('postcode')->flip();

        return ! isset($known[$homeCode]) || $codes->contains(fn (?string $code) => $code !== null && ! isset($known[$code]));
    }
}
