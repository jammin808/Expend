<?php

namespace Tests\Feature;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_box_finds_payments_jobs_and_invoices_by_name_amount_or_po(): void
    {
        $this->get('/search?q=adobe')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        WorkJob::query()->create(['title' => 'Spring Gala', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781']);
        Invoice::query()->create(['status' => 'draft', 'total_minor' => 120000, 'bill_to_name' => 'Acme Events Ltd', 'po_number' => 'PO-7781']);

        $types = fn (string $q) => collect($this->getJson('/search?q='.urlencode($q))->assertOk()->json('results'))->pluck('type')->unique()->values()->all();
        $this->assertSame(['Payment'], $types('adobe'));
        $this->assertEqualsCanonicalizing(['Job', 'Invoice'], $types('PO-7781'));
        $this->assertContains('Payment', $types('£56.98'), 'By amount');
        $this->assertContains('Job', $types('acme'), 'By client');
        $this->assertSame([], $this->getJson('/search?q=%25')->json('results') === [] ? [] : ['wildcards are escaped']);
    }
}
