<?php

namespace App\Console\Commands;

use App\Domain\Intel\Backfills\Backfill;
use App\Models\Setting;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * Fills in the intelligence cycle's new columns for rows kept before them (docs/intelligence-cycle.md section 3.5).
 * Backfills never run inside migrations: this runs one by name, in chunks of 500, for up to a minute, and once it
 * has nothing left to do, settings intel_backfills_done lists it. The schedule (routes/console.php) runs each one
 * hourly until then. Every backfill is idempotent and leaves a value null when unsure, so running one again (or
 * after it's done, with --again) changes nothing it already did.
 *
 * The backfills are every class in app/Domain/Intel/Backfills that implements Backfill (backfills()).
 */
class IntelBackfill extends Command
{
    protected $signature = 'ledger:intel-backfill {name : A backfill’s name, or "all"} {--chunk=500 : Rows at a time} {--seconds=60 : Stop after about this long} {--again : Run it even if it’s done}';

    protected $description = 'Fill in the intelligence cycle’s new columns for rows kept before them (run hourly until each is done)';

    /** Where the names of finished backfills are kept. */
    public const DONE = 'intel_backfills_done';

    public function handle(): int
    {
        $all = self::backfills();
        $name = (string) $this->argument('name');
        if ($name !== 'all' && ! isset($all[$name])) {
            $this->error('No backfill called “'.$name.'”. There are: '.(implode(', ', array_keys($all)) ?: 'none').'.');

            return self::FAILURE;
        }
        $chunk = max(1, (int) $this->option('chunk'));
        $until = microtime(true) + max(1, (int) $this->option('seconds'));
        foreach ($name === 'all' ? $all : [$name => $all[$name]] as $key => $backfill) {
            if (self::isDone($key) && ! $this->option('again')) {
                $this->line("{$key}: already done.");

                continue;
            }
            $changed = 0;
            do {
                $r = $backfill->run($chunk);
                $changed += $r['changed'];
            } while (! $r['done'] && microtime(true) < $until);
            if ($r['done']) {
                self::markDone($key);
            }
            $this->info("{$key}: {$changed} changed".($r['done'] ? '; done.' : '; more to do (it carries on next time).'));
        }

        return self::SUCCESS;
    }

    /**
     * Every backfill, by name: the classes in app/Domain/Intel/Backfills that implement Backfill.
     *
     * @return array<string, Backfill>
     */
    public static function backfills(): array
    {
        $out = [];
        foreach (glob(app_path('Domain/Intel/Backfills/*.php')) ?: [] as $file) {
            $class = 'App\\Domain\\Intel\\Backfills\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Backfill::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            /** @var Backfill $backfill */
            $backfill = app($class);
            $out[$backfill->name()] = $backfill;
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    public static function done(): array
    {
        $done = Setting::get(self::DONE);

        return is_array($done) ? array_values(array_filter($done, 'is_string')) : [];
    }

    public static function isDone(string $name): bool
    {
        return in_array($name, self::done(), true);
    }

    private static function markDone(string $name): void
    {
        if (! self::isDone($name)) {
            Setting::put(self::DONE, [...self::done(), $name]);
        }
    }
}
