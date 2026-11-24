<?php

namespace App\Domain\Intel\Backfills;

/**
 * A backfill of the intelligence cycle's new columns (docs/intelligence-cycle.md section 3.5). Backfills never run
 * inside migrations: `php artisan ledger:intel-backfill {name}` runs one in chunks, and the schedule runs it hourly
 * until settings intel_backfills_done lists it (App\Console\Commands\IntelBackfill).
 */
interface Backfill
{
    /** The name for ledger:intel-backfill {name} and settings intel_backfills_done. */
    public function name(): string;

    /** Work through at most $chunk rows not yet done. Idempotent; leaves a value null when unsure. @return array{changed:int, done:bool} */
    public function run(int $chunk = 500): array;
}
