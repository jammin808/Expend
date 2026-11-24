<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;

/**
 * The detectors whose findings are recorded (FindingsRegistry::sync(), and the cycle's detect steps from M5): the
 * to-do list's own groups and M1's three. A new detector is added here. Resolved from the container, so a test can
 * register others (app()->instance(Registry::class, new Registry([...]))).
 */
final class Registry
{
    /** @var list<Detector> */
    private array $detectors;

    /** @param  list<Detector>|null  $detectors  the usual ones when null */
    public function __construct(?array $detectors = null)
    {
        $this->detectors = $detectors ?? [new TodoGroupsDetector, new HmrcUnallocated, new TravelUnclaimed, new YearFiledDrift];
    }

    /** @return list<Detector> */
    public function all(): array
    {
        return $this->detectors;
    }

    /** The detector that makes findings of this code, if any. */
    public function forCode(?string $code): ?Detector
    {
        if ($code === null) {
            return null;
        }
        foreach ($this->detectors as $d) {
            if ($d->code() === $code || ($d instanceof CoversCodes && in_array($code, $d->codes(), true))) {
                return $d;
            }
        }

        return null;
    }
}
