<?php

namespace App\Domain\Tax;

use App\Models\MileageTrip;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Simplified mileage claims, using each tax year's own rates (45p before 6 April 2026, 55p from 2026-27).
 * Trips are valued in date order so the 10,000-mile threshold falls on the right trips.
 * Vehicles on actual costs get no mileage claim.
 */
final class MileageCalculator
{
    /**
     * @return array{trips: Collection, total_pence:int, total_miles:string, by_job: array<int,int>, has_rates:bool}
     */
    public static function forYear(string $label): array
    {
        $params = TaxParameters::for($label);
        $trips = MileageTrip::query()->with(['vehicle', 'job'])->where('tax_year', $label)->orderBy('trip_on')->orderBy('id')->get();

        if (! $params) {
            return ['trips' => $trips->map(fn ($t) => self::row($t, 0, 'No rates loaded for this year')), 'total_pence' => 0, 'total_miles' => '0', 'by_job' => [], 'has_rates' => false];
        }

        $rates = $params['mileage'];
        $scopeAll = Setting::get('mileage_threshold_scope') !== 'per_vehicle';
        $used = [];
        $total = 0;
        $miles = BigDecimal::zero();
        $byJob = [];

        $rows = $trips->map(function (MileageTrip $trip) use ($rates, $scopeAll, &$used, &$total, &$miles, &$byJob) {
            $vehicle = $trip->vehicle;
            $tripMiles = BigDecimal::of((string) $trip->miles);
            $miles = $miles->plus($tripMiles);

            if ($vehicle->method !== 'simplified') {
                return self::row($trip, 0, 'Vehicle uses actual costs, so no mileage claim');
            }

            if ($vehicle->type === 'car_van') {
                $key = $scopeAll ? 'all' : $vehicle->id;
                $already = $used[$key] ?? BigDecimal::zero();
                $remainingFirst = BigDecimal::of($rates['threshold_miles'])->minus($already);
                $atFirst = $remainingFirst->isPositive() ? ($tripMiles->isGreaterThan($remainingFirst) ? $remainingFirst : $tripMiles) : BigDecimal::zero();
                $atAfter = $tripMiles->minus($atFirst);
                $used[$key] = $already->plus($tripMiles);
                $claim = $atFirst->multipliedBy($rates['car_van_first'])->plus($atAfter->multipliedBy($rates['car_van_after']));
                $note = $atAfter->isPositive()
                    ? sprintf('%s mi at %dp, %s mi at %dp', $atFirst, $rates['car_van_first'], $atAfter, $rates['car_van_after'])
                    : sprintf('%dp a mile', $rates['car_van_first']);
            } else {
                $rate = $vehicle->type === 'motorcycle' ? $rates['motorcycle'] : $rates['bicycle'];
                $claim = $tripMiles->multipliedBy($rate);
                $note = sprintf('%dp a mile', $rate);
            }

            $pence = $claim->toScale(0, RoundingMode::HALF_UP)->toInt();
            $total += $pence;
            if ($trip->work_job_id) {
                $byJob[$trip->work_job_id] = ($byJob[$trip->work_job_id] ?? 0) + $pence;
            }

            return self::row($trip, $pence, $note);
        });

        return ['trips' => $rows, 'total_pence' => $total, 'total_miles' => (string) $miles, 'by_job' => $byJob, 'has_rates' => true];
    }

    private static function row(MileageTrip $trip, int $pence, string $note): array
    {
        return [
            'id' => $trip->id,
            'trip_on' => $trip->trip_on->format('Y-m-d'),
            'vehicle' => $trip->vehicle?->name,
            'job' => $trip->job ? ['id' => $trip->job->id, 'title' => $trip->job->title] : null,
            'from_place' => $trip->from_place,
            'to_place' => $trip->to_place,
            'miles' => (string) $trip->miles,
            'purpose' => $trip->purpose,
            'claim_pence' => $pence,
            'note' => $note,
        ];
    }
}
