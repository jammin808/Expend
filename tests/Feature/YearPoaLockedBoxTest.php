<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The payments-on-account box on a year's own page. On a locked year it is read-only and must show the amount that is
 * saved. Locking is a router.post, which keeps the page's state, so an amount typed but never saved would otherwise
 * stay in the box after the lock, looking as if it had been locked in.
 */
class YearPoaLockedBoxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    public function test_a_locked_year_shows_the_saved_payments_on_account_not_an_unsaved_one(): void
    {
        TaxYear::track('2024-25', 'filed')->update(['payments_on_account_paid_pence' => 150000]);
        $this->post('/years/2024-25/lock')->assertSessionHas('success');

        // The page gets the saved amount and knows the year is locked.
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('years/show', false)
            ->where('year.locked', true)->where('year.payments_on_account_paid_pence', 150000));
        // An amount typed but not saved can't be saved now (the box's Save sends router.patch as JSON).
        $this->from('/years/2024-25')->call('PATCH', '/years/2024-25', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode(['payments_on_account_paid' => '900.00']))
            ->assertSessionHas('error', '2024-25 is locked. Unlock it before changing its figures.');
        $this->assertSame(150000, TaxYear::query()->where('label', '2024-25')->value('payments_on_account_paid_pence'));

        // So the locked box shows the saved amount, whatever is still in the page's state.
        $source = (string) file_get_contents(resource_path('js/pages/years/show.tsx'));
        $this->assertMatchesRegularExpression('/const savedPoa = \(year\.payments_on_account_paid_pence \/ 100\)\.toFixed\(2\);/', $source);
        $start = strpos($source, 'id="poa"');
        $this->assertNotFalse($start);
        $input = substr($source, $start, strpos($source, '/>', $start) - $start);
        $this->assertStringContainsString('value={year.locked ? savedPoa : poa}', $input, 'a locked year shows what is saved');
        $this->assertStringContainsString('readOnly={year.locked}', $input);
        $this->assertStringContainsString("aria-describedby={year.locked ? 'poa-locked' : undefined}", $input);
        $this->assertStringContainsString('id="poa-locked"', $source);
    }
}
