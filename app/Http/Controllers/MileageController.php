<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Vetoes;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\TaxParameters;
use App\Domain\Todo\TodoList;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MileageController extends Controller
{
    public function index(Request $request): Response
    {
        $year = $request->string('year')->toString() ?: UkTaxYear::current()->label();
        $result = MileageCalculator::forYear($year);

        return Inertia::render('mileage/index', [
            'year' => $year,
            'years' => TaxYear::query()->orderByDesc('label')->pluck('label'),
            'locked' => $this->isLocked($year),
            'rates' => TaxParameters::for($year)['mileage'] ?? null,
            'trips' => $result['trips'],
            'total_pence' => $result['total_pence'],
            'total_miles' => $result['total_miles'],
            'vehicles' => Vehicle::query()->orderBy('name')->get(['id', 'name', 'type', 'method', 'registration']),
            'fromJobs' => JobTrips::proposals($year),
            'notDriven' => JobTrips::leftOut($year),
            'homePostcode' => Setting::get('home_postcode'),
            'jobs' => WorkJob::query()->orderByDesc('starts_on')->limit(300)->get(['id', 'title', 'starts_on', 'postcode', 'location'])
                ->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'postcode' => $j->postcode, 'location' => $j->location]),
        ]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'type' => 'required|in:car_van,motorcycle',
            'method' => 'required|in:simplified,actual', 'registration' => 'nullable|string|max:12',
        ]);
        $vehicle = Vehicle::query()->create($data);
        AuditLog::record('vehicle.created', $vehicle, null, $vehicle->toArray());

        return back()->with('success', "Added {$vehicle->name}.");
    }

    /** Add a return trip for each job you ticked (estimated from postcodes), on the vehicle you chose. */
    public function fromJobs(Request $request): RedirectResponse
    {
        $data = $request->validate(['year' => 'required|string|regex:/^\d{4}-\d{2}$/', 'vehicle_id' => 'required|integer|exists:vehicles,id', 'job_ids' => 'required|array|min:1', 'job_ids.*' => 'integer']);
        $this->guardLocked($data['year']);
        $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
        $added = JobTrips::add($data['year'], $vehicle->id, array_map('intval', $data['job_ids']));
        AuditLog::record('mileage.from_jobs', 'Mileage', null, ['year' => $data['year'], 'vehicle_id' => $vehicle->id, 'jobs' => $data['job_ids'], 'trips' => $added]);

        return back()->with('success', "Added {$added} ".($added === 1 ? 'trip' : 'trips')." on {$vehicle->name}. The distances are estimates from postcodes: correct any that differ from your route.");
    }

    /**
     * "Didn't drive": this job had no mileage to claim (you went another way, or weren't there). Remembered (a veto on
     * the job's mileage, audited), so neither this page nor the to-do list offers it again until you put it back.
     */
    public function notDriven(Request $request): RedirectResponse
    {
        $data = $request->validate(['job_id' => 'required|integer|exists:work_jobs,id', 'note' => 'nullable|string|max:200']);
        $job = WorkJob::query()->findOrFail($data['job_id']);
        Vetoes::record('mileage_day', 'job:'.$job->id, null, reasonCode: 'no_drive', reason: ($data['note'] ?? null) ?: 'You didn’t drive');
        TodoList::forget();

        return back()->with('success', "OK: no mileage for {$job->title}. It won’t be offered again. Put it back from the list below if you did drive.");
    }

    /** Put a job you said you didn't drive to back on the list of jobs to add mileage for. Audited. */
    public function putBack(WorkJob $job): RedirectResponse
    {
        $lifted = Vetoes::liftMileage($job->id, 'Put back from the Mileage page');

        return back()->with('success', $lifted ? "Put back: {$job->title} can have mileage again." : "{$job->title} wasn’t left out.");
    }

    public function storeTrip(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $trip = MileageTrip::query()->create($data);
        AuditLog::record('mileage.created', $trip, null, $trip->toArray());

        return back()->with('success', 'Trip added.');
    }

    public function updateTrip(Request $request, MileageTrip $trip): RedirectResponse
    {
        $this->guardLocked($trip->tax_year); // moving a trip out of a locked year would change that year too
        $before = $trip->toArray();
        $trip->update($this->validated($request));
        AuditLog::record('mileage.updated', $trip, $before, $trip->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    public function destroyTrip(Request $request, MileageTrip $trip): RedirectResponse
    {
        $this->guardLocked($trip->tax_year);
        AuditLog::record('mileage.deleted', $trip, $trip->toArray(), null, $request->string('reason')->toString() ?: null);
        $trip->delete();

        return back()->with('success', 'Trip removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'work_job_id' => 'nullable|integer|exists:work_jobs,id',
            'trip_on' => 'required|date',
            'from_place' => 'required|string|max:150',
            'to_place' => 'required|string|max:150',
            'miles' => 'required|numeric|min:0.1|max:5000',
            'return_trip' => 'boolean',
            'purpose' => 'nullable|string|max:250',
        ]);

        if ($data['return_trip'] ?? false) {
            $data['miles'] = $data['miles'] * 2;
            $data['purpose'] = trim(($data['purpose'] ?? '').' (return trip)');
        }
        unset($data['return_trip']);
        $data['miles'] = (string) BigDecimal::of(trim((string) $data['miles']))->toScale(1, RoundingMode::HALF_UP);
        $data['tax_year'] = UkTaxYear::fromDate($data['trip_on'])->label();
        $this->guardLocked($data['tax_year']);

        return $data;
    }

    private function guardLocked(string $label): void
    {
        abort_if($this->isLocked($label), 422, "Tax year {$label} is locked.");
    }

    private function isLocked(string $label): bool
    {
        return TaxYear::query()->where('label', $label)->whereNotNull('locked_at')->exists();
    }
}
