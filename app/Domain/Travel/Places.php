<?php

namespace App\Domain\Travel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * UK postcode locations from postcodes.io (free open data; only the postcode is sent), cached so each
 * postcode is looked up once. Distances are straight-line miles.
 */
final class Places
{
    public static function normalise(?string $postcode): ?string
    {
        $pc = strtoupper(preg_replace('/\s+/', '', (string) $postcode));

        return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $pc) ? substr($pc, 0, -3).' '.substr($pc, -3) : null;
    }

    /** Lookups that failed in the last minute, so a slow or down service isn't waited on again and again. */
    private static array $failed = [];

    /** @return array{0:float, 1:float}|null */
    public static function locate(?string $postcode): ?array
    {
        if (! $pc = self::normalise($postcode)) {
            return null;
        }
        $row = DB::table('places')->where('postcode', $pc)->first();
        if (! $row) {
            if (isset(self::$failed[$pc]) && time() - self::$failed[$pc] < 60) {
                return null;
            }
            try {
                $response = Http::timeout(3)->get('https://api.postcodes.io/postcodes/'.rawurlencode($pc));
            } catch (Throwable) {
                self::$failed[$pc] = time();

                return null;
            }
            // Only a definite answer is kept: found, or no such postcode. Busy or broken answers are retried later.
            if (! in_array($response->status(), [200, 404], true)) {
                self::$failed[$pc] = time();

                return null;
            }
            $row = (object) ['latitude' => $response->json('result.latitude'), 'longitude' => $response->json('result.longitude')];
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $row->latitude, 'longitude' => $row->longitude, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $row->latitude !== null ? [(float) $row->latitude, (float) $row->longitude] : null;
    }

    public static function miles(?string $a, ?string $b): ?float
    {
        $p = self::locate($a);
        $q = $p ? self::locate($b) : null;
        if (! $p || ! $q) {
            return null;
        }
        [$lat1, $lon1, $lat2, $lon2] = array_map('deg2rad', [$p[0], $p[1], $q[0], $q[1]]);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lon2 - $lon1) / 2) ** 2;

        return round(3958.8 * 2 * asin(min(1, sqrt($h))), 1);
    }
}
