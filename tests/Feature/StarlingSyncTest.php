<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StarlingSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_statements_import_daily_through_the_same_checks_as_an_upload(): void
    {
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
        $mine = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $csv = file_get_contents(base_path('tests/fixtures/starling.csv'));
        Http::fake(function (Request $r) use (&$csv) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/v2/accounts')) {
                return $r->hasHeader('Authorization', 'Bearer good-token-1234567890abcdef')
                    ? Http::response(['accounts' => [['accountUid' => 'uid-1', 'name' => 'Business', 'currency' => 'GBP', 'defaultCategory' => 'cat-1']]])
                    : Http::response(['error' => 'invalid_token', 'error_description' => 'Unauthorised'], 401);
            }
            if (str_contains($r->url(), '/statement/downloadForDateRange') && $r->hasHeader('Accept', 'text/csv')) {
                return Http::response($csv, 200, ['Content-Type' => 'text/csv']);
            }

            return Http::response('', 404);
        });

        $this->post('/settings/starling', ['token' => 'wrong-token-1234567890abcdef'])->assertSessionHas('error', fn ($m) => str_contains($m, 'refused'));
        $this->assertNull(Setting::get('starling_token'));
        $this->post('/settings/starling', ['token' => 'good-token-1234567890abcdef'])->assertSessionHas('success');
        $this->assertSame($mine->id, Setting::get('starling_accounts')['uid-1']['account_id'], 'Linked to your existing Starling account');
        $this->assertStringNotContainsString('good-token', (string) DB::table('settings')->where('key', 'starling_token')->value('value'), 'Stored encrypted');

        $this->post('/settings/starling/sync')->assertSessionHas('success', fn ($m) => str_contains($m, 'Imported'));
        $count = BankTransaction::query()->count();
        $this->assertGreaterThan(0, $count);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'downloadForDateRange') && str_contains($r->url(), 'start=') && str_contains($r->url(), 'end='));

        // The overlap on the next run adds nothing twice.
        $this->post('/settings/starling/sync')->assertSessionHas('success', fn ($m) => str_contains($m, 'Imported 0 new'));
        $this->assertSame($count, BankTransaction::query()->count());

        // A statement whose balance doesn't add up is held, like an upload.
        $csv = file_get_contents(base_path('tests/fixtures/starling_broken.csv'));
        $this->post('/settings/starling/sync', ['from' => '2025-04-01'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Held for review'));
        $this->assertSame('quarantined', ImportBatch::query()->latest('id')->value('status'));
        $this->assertSame($count, BankTransaction::query()->count());

        $this->artisan('schedule:list')->assertSuccessful();
    }
}
