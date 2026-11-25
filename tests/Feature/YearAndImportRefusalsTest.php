<?php

namespace Tests\Feature;

use App\Domain\Matching\MerchantKey;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The year page's "Decide all" and the import page's missing exchange rates can be refused by the server too.
 * The refusal comes back only as a validation error, which no toast or flash shows, so the page must show it.
 */
class YearAndImportRefusalsTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function sendJson(string $from, string $method, string $url, array $body): TestResponse
    {
        return $this->from($from)->call($method, $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    private function line(int $n): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-06-1'.$n, 'tax_year' => '2025-26',
            'description' => 'Acme Events', 'counterparty' => 'Acme Events', 'merchant_key' => MerchantKey::from('Acme Events', 'Acme Events'), 'amount_pence' => -2500,
            'raw' => [], 'fingerprint' => hash('sha256', (string) $n), 'occurrence' => 1]);
    }

    /** Every call to $start in $source, from its name to its closing bracket (strings skipped). */
    private function calls(string $source, string $start): array
    {
        $found = [];
        $offset = 0;
        while (($at = strpos($source, $start, $offset)) !== false) {
            $depth = 0;
            $quote = null;
            for ($i = strpos($source, '(', $at); $i < strlen($source); $i++) {
                $c = $source[$i];
                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if (in_array($c, ["'", '"', '`'], true)) {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    break;
                }
            }
            $found[] = substr($source, $at, $i - $at + 1);
            $offset = $i;
        }

        return $found;
    }

    public function test_decide_all_on_the_year_page_says_why_a_decimal_business_use_is_refused(): void
    {
        $lines = [$this->line(1), $this->line(2)];
        // What the year page sends: the Decide all sheet's decision, with the business-use box typed as 50.5.
        $this->sendJson('/years/2025-26', 'POST', '/decisions/bulk', [
            'kind' => 'business_expense', 'business_pct' => 50.5, 'category_id' => Category::query()->value('id'), 'work_job_id' => null,
            'merchant_key' => $lines[0]->merchant_key, 'tax_year' => '2025-26',
        ])->assertSessionHasErrors('business_pct')->assertSessionMissing('success')->assertSessionMissing('error');
        foreach ($lines as $line) {
            $this->assertSame(0, $line->fresh()->allocations()->count(), 'nothing is decided');
        }

        $source = (string) file_get_contents(resource_path('js/pages/years/show.tsx'));
        $bulk = $this->calls($source, "router.post('/decisions/bulk'");
        $this->assertCount(1, $bulk);
        $this->assertStringContainsString('onError', $bulk[0], 'Decide all shows what was refused');
        $this->assertStringContainsString('whole number from 1 to 100', $source, 'in plain words, not "business pct"');
    }

    public function test_missing_exchange_rates_say_when_a_rate_is_refused(): void
    {
        // The box only needs Number(value) > 0 to enable the button; the server allows at most 100,000,000.
        $this->sendJson('/imports', 'POST', '/imports/preview', [
            'account_id' => $this->bank->id, 'rates' => [['currency' => 'USD', 'month' => '2025-06', 'units_per_gbp' => '1e9']],
        ])->assertSessionHasErrors('rates.0.units_per_gbp')->assertSessionMissing('success')->assertSessionMissing('error');

        $source = (string) file_get_contents(resource_path('js/pages/imports/index.tsx'));
        $save = collect($this->calls($source, 'router.post('))->filter(fn ($call) => str_contains($call, 'units_per_gbp'));
        $this->assertCount(1, $save);
        $this->assertStringContainsString('onError', $save->first(), 'a refused rate says so');
    }
}
