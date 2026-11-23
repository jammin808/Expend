<?php

namespace App\Jobs;

use App\Domain\Gmail\GmailScanner;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One page of one Gmail search; queues the next page itself, so no single run is long. */
class ScanGmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public string $search, public string $taxYear, public ?string $pageToken = null, public int $pagesLeft = 10) {}

    public function handle(GmailScanner $scanner): void
    {
        $progress = self::progress($this->taxYear);
        $row = $progress[$this->search] ?? ['found' => 0, 'new' => 0];

        try {
            $page = $scanner->scanPage($this->search, $this->taxYear, $this->pageToken);
            $row = ['found' => $row['found'] + $page['found'], 'new' => $row['new'] + $page['new'], 'error' => null];
            $more = $page['next'] && $this->pagesLeft > 1;
            $row['state'] = $more ? 'running' : 'done';
            if ($more) {
                self::dispatch($this->search, $this->taxYear, $page['next'], $this->pagesLeft - 1);
            }
        } catch (Throwable $e) {
            $row = $row + ['found' => 0, 'new' => 0];
            $row['state'] = 'failed';
            $row['error'] = mb_substr($e->getMessage(), 0, 300);
            report($e);
        }

        $row['updated_at'] = now()->toIso8601String();
        self::save($this->taxYear, $this->search, $row);
    }

    /** @return array<string, array> progress per search for a tax year */
    public static function progress(string $taxYear): array
    {
        return (Setting::get('gmail_scan') ?? [])[$taxYear] ?? [];
    }

    public static function save(string $taxYear, string $search, array $row): void
    {
        $all = Setting::get('gmail_scan') ?? [];
        $all[$taxYear][$search] = $row;
        Setting::put('gmail_scan', $all);
    }
}
