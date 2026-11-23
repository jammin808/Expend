<?php

namespace Tests\Feature;

use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\TaxGuide;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_covers_every_category_with_sources(): void
    {
        foreach (Category::query()->where('kind', 'expense')->pluck('code') as $code) {
            $g = TaxGuide::category($code);
            $this->assertNotNull($g, "Guide for {$code}");
            $this->assertNotEmpty($g['sources'], "Sources for {$code}");
            foreach ($g['sources'] as $s) {
                $this->assertMatchesRegularExpression('#^https://([a-z0-9-]+\.)*gov\.uk/#', $s['url'], 'Only GOV.UK and HMRC manual sources');
            }
        }
        $this->assertNotEmpty(TaxGuide::ways());
        $top = array_column(array_slice(TaxGuide::search('hotel night before a job'), 0, 3), 'key');
        $this->assertNotEmpty(array_intersect($top, ['travel_rules', 'travel_accommodation', 'travel_subsistence']), 'Search finds the travel rules');
    }

    public function test_the_question_mark_explains_a_payment_in_its_own_context(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $tfl = BankTransaction::query()->where('counterparty', 'TfL')->sole();
        Vehicle::query()->create(['name' => 'My car', 'type' => 'car_van', 'method' => 'simplified']);

        // No job that day: travel needs a business reason.
        app(AllocationWriter::class)->decide($tfl, AllocationWriter::lines($tfl->amount_pence, 'business_expense', Category::query()->where('code', 'travel_local')->value('id')), 'you');
        $e = $this->getJson("/explain/bank/{$tfl->id}")->assertOk()->json();
        $this->assertStringContainsString('no job on or around this date', implode(' ', $e['notes']));
        $this->assertSame('travel_local', $e['category']['code']);
        $this->assertContains('travel_rules', array_column($e['topics'], 'slug'));

        // With a job that day it says so; fuel with simplified mileage is flagged.
        WorkJob::query()->create(['title' => 'Gala', 'starts_on' => $tfl->effectiveDate()->format('Y-m-d'), 'ends_on' => $tfl->effectiveDate()->format('Y-m-d')]);
        app(AllocationWriter::class)->decide($tfl, AllocationWriter::lines($tfl->amount_pence, 'business_expense', Category::query()->where('code', 'travel_fuel')->value('id')), 'you');
        $notes = implode(' ', $this->getJson("/explain/bank/{$tfl->id}")->json('notes'));
        $this->assertStringContainsString('You had work then: Gala', $notes);
        $this->assertStringContainsString('can’t be claimed as well', $notes);

        $this->getJson('/explain/category/'.Category::query()->where('code', 'entertainment')->value('id'))->assertOk()->assertJsonPath('allowable', false);
        $this->get('/tax-guide')->assertOk();
    }

    public function test_the_assistant_looks_rules_up_and_invented_links_are_flagged(): void
    {
        $out = app(AssistantTools::class)->run('tax_guide', ['category_code' => 'travel_subsistence', 'query' => 'pension']);
        $this->assertSame('Meals and subsistence when travelling', $out['category']['title'] ?? $out['category']['title']);
        $this->assertNotEmpty($out['matches']);

        $json = json_encode($out, JSON_UNESCAPED_SLASHES);
        $real = $out['category']['sources'][0]['url'];
        $this->assertSame([], Assistant::unmatchedLinks("See {$real}.", [$json]));
        $this->assertSame(['link https://example.com/made-up'], Assistant::unmatchedLinks('See https://example.com/made-up.', [$json]));
    }
}
