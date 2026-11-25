<?php

namespace Tests\Feature\Quick;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\QuickEntryChecks;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Quick\ReceiptAttacher;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\UkTaxYear;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the quick-add tests share: a signed-in owner, a Starling business account, the documents disk faked, and the
 * clock at 14:05 on Wednesday 25 November 2026 (London is on GMT, so UTC is London time).
 */
trait QuickHelpers
{
    protected Account $starling;

    protected User $owner;

    private int $lines = 0;

    protected function setUpQuick(): void
    {
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-25 14:05:00');
        $this->owner = User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']);
        $this->actingAs($this->owner);
        $this->starling = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling', 'is_active' => true]);
    }

    protected function tearDownQuick(): void
    {
        Carbon::setTestNow();
    }

    protected function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    protected function key(): string
    {
        return (string) Str::uuid();
    }

    /** The fields the phone sends, for £12.50 parking paid in cash today, unless changed. */
    protected function fields(array $over = []): array
    {
        return array_merge([
            'client_key' => $this->key(), 'channel' => 'phone', 'paid_with' => 'cash', 'account_id' => null, 'spent_on' => '2026-11-25', 'spent_at' => '14:05',
            'captured_at' => '2026-11-25T14:05:00Z', 'amount' => '12.50', 'currency' => 'GBP', 'sterling' => null, 'what' => 'Parking', 'what_source' => 'chip',
            'category_id' => $this->cat('travel_parking'), 'work_job_id' => null, 'job_source' => null, 'business_pct' => 100, 'note' => null, 'photo' => null,
        ], $over);
    }

    /** A photo as the phone sends it: the file and the descriptor its fields carry. @return array{0:UploadedFile, 1:array<string, mixed>} */
    protected function photo(string $name = 'receipt.jpg', int $w = 40, int $h = 60): array
    {
        $file = UploadedFile::fake()->image($name, $w, $h);
        $bytes = (string) file_get_contents($file->getRealPath());

        return [$file, ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mime' => 'image/jpeg', 'name' => $name, 'original' => false]];
    }

    protected function send(array $fields)
    {
        return $this->postJson('/quick/entries', $fields);
    }

    protected function sendPhoto(string $key, UploadedFile $file)
    {
        return $this->post('/quick/entries/'.$key.'/photo', ['_method' => 'PUT', 'photo' => $file], ['Accept' => 'application/json']);
    }

    protected function entry(string $key): QuickEntry
    {
        return QuickEntry::query()->where('client_key', $key)->firstOrFail();
    }

    /** A card line as a statement brings it. */
    protected function line(string $date, int $pence, string $description, ?Account $account = null, array $extra = []): BankTransaction
    {
        $account ??= $this->starling;
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'quick.csv'], ['file_sha256' => hash('sha256', 'quick'.$account->id), 'stored_path' => 'x']);

        return BankTransaction::query()->create(array_merge([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'counterparty' => $description, 'merchant_key' => strtoupper($description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', 'line'.(++$this->lines)), 'occurrence' => 1,
        ], $extra));
    }

    /** Skip a test that needs the changes asked of other files (Ref kind quick, method receipt.quick, the registry). */
    protected function needsIntegration(): void
    {
        if (! ReceiptAttacher::cites() || ! collect(app(Registry::class)->all())->contains(fn ($d) => $d instanceof QuickEntryChecks)) {
            $this->markTestSkipped('Needs the needs_other changes (Ref kind quick, Methods receipt.quick, QuickEntryChecks registered).');
        }
    }

    /** The detector's findings now, by code. @return array<string, list<\App\Domain\Intel\FindingDraft>> */
    protected function findings(): array
    {
        $out = [];
        foreach ((new QuickEntryChecks)->detect(new CycleContext) as $d) {
            $out[$d->code][] = $d;
        }

        return $out;
    }
}
