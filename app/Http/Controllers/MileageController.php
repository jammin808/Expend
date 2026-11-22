<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\TaxParameters;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
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
            'rates' => TaxParameters::for($year)['mileage'] ?? null,
            'trips' => $result['trips'],
            'total_pence' => $result['total_pence'],
            'total_miles' => $result['total_miles'],
            'vehicles' => Vehicle::query()->orderBy('name')->get(['id', 'name', 'type', 'method', 'registration']),
            'jobs' => WorkJob::query()->orderByDesc('starts_on')->limit(300)->get(['id', 'title', 'starts_on', 'postcode', 'location']),
        ]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'type' => 'required|in:car_van,motorcycle,bicycle',
            'method' => 'required|in:simplified,actual', 'registration' => 'nullable|string|max:12',
        ]);
        $vehicle = Vehicle::query()->create($data);
        AuditLog::record('vehicle.created', $vehicle, null, $vehicle->toArray());

        return back()->with('success', "Added {$vehicle->name}.");
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
        $data['miles'] = number_format((float) $data['miles'], 1, '.', '');
        $data['tax_year'] = UkTaxYear::fromDate($data['trip_on'])->label();
        $this->guardLocked($data['tax_year']);

        return $data;
    }

    private function guardLocked(string $label): void
    {
        abort_if(TaxYear::query()->where('label', $label)->whereNotNull('locked_at')->exists(), 422, "Tax year {$label} is locked.");
    }
}
