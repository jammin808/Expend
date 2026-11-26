<?php

namespace Tests\Feature\Todo;

use App\Domain\Todo\TodoList;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkJob;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The to-do list's and the invoice page's words, on the demo data: one due date per invoice (one sent late says when
 * it became overdue instead of giving a second due date), "Done" only for a job you marked done, a cost's date said
 * once, and the invoice's times in UK time.
 */
class TodoWordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'demo@example.com')->firstOrFail());
    }

    /** @return array<string, array> the to-do items by key */
    private function todo(): array
    {
        return collect(TodoList::everything())->keyBy('key')->all();
    }

    public function test_an_invoice_sent_late_keeps_its_own_due_date_and_says_when_it_became_overdue(): void
    {
        // Demo INV-0002: due 19 Sep 2025 as printed, sent on 21 Aug with 30 days' terms, so overdue from 20 Sep.
        $invoice = Invoice::query()->where('number', 'INV-0002')->sole();
        $this->assertSame('2025-09-19', $invoice->due_date->format('Y-m-d'));
        $item = $this->todo()['invoice_overdue:'.$invoice->id];

        // The date printed on it, as its page and the invoice list say it, then when it became overdue: never "due 20 Sep".
        $this->assertStringContainsString('due 19 Sep 2025, sent late so overdue since 20 Sep 2025 (', $item['detail']);
        $this->assertStringNotContainsString('due 20 Sep', $item['detail']);
    }

    public function test_a_booked_job_whose_last_day_has_passed_isnt_called_done_by_the_po_chase(): void
    {
        $job = WorkJob::query()->where('title', 'Arena load-out')->sole();
        $this->assertSame('booked', $job->status);
        $todo = $this->todo();
        $this->assertSame('Is Arena load-out done?', $todo['job_done:'.$job->id]['title']);
        $po = collect($todo)->first(fn ($i) => ($i['title'] ?? '') === 'No PO: Arena load-out');
        $this->assertNotNull($po);
        $this->assertStringContainsString('Finished and not invoiced.', $po['detail']);
        $this->assertStringNotContainsString('Done and not invoiced', $po['detail']);
    }

    public function test_a_late_costs_charge_item_says_its_date_once(): void
    {
        $item = collect($this->todo())->first(fn ($i) => str_starts_with($i['key'], 'charge:'));
        $this->assertNotNull($item);
        $this->assertMatchesRegularExpression('/, \d{1,2} [A-Z][a-z]{2} \d{4} to /', $item['title'], 'the cost’s own words carry its date');
        $this->assertDoesNotMatchRegularExpression('/(\d{1,2} [A-Z][a-z]{2}) \d{4} \1\b/', $item['title'].' '.$item['detail']);
    }

    public function test_the_invoice_page_gives_its_times_in_uk_time(): void
    {
        $invoice = Invoice::query()->where('number', 'INV-0001')->sole();
        $invoice->forceFill(['sent_at' => '2025-06-12 23:30:00'])->saveQuietly(); // 00:30 on 13 June in London (BST)
        $props = json_decode(json_encode($this->get("/invoices/{$invoice->id}")->assertOk()->viewData('page')['props']), true);
        $this->assertSame('2025-06-13 00:30', $props['invoice']['sent_at']);
    }
}
