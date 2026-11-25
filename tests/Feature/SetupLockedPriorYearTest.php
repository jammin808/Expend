<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Setup's "Your last filed return" writes the figures to the year before the oldest one chosen. A locked year's
 * figures can't change that way either, and a change to an open year's figures is kept in the audit log.
 */
class SetupLockedPriorYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    /** The setup form as the page sends it (useForm, posted as JSON by Inertia), with the given figures typed in. */
    private function submit(array $prior): TestResponse
    {
        $form = [
            'accounts' => [],
            'years' => ['2024-25', '2025-26'],
            'prior_year' => $prior + ['turnover' => '', 'expenses' => '', 'profit' => ''],
            'home_postcode' => '',
            'scottish_taxpayer' => false,
            'vehicle' => ['name' => '', 'method' => 'simplified'],
        ];

        return $this->from('/setup')->call('POST', '/setup', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($form));
    }

    private function priorYear(): TaxYear
    {
        return TaxYear::query()->where('label', '2023-24')->firstOrFail();
    }

    public function test_a_locked_prior_year_keeps_its_filed_figures_and_setup_says_so(): void
    {
        TaxYear::track('2023-24', 'filed')->update(['filed_turnover_pence' => 4000000, 'filed_expenses_pence' => 1000000, 'filed_profit_pence' => 3000000]);
        $this->post('/years/2023-24/lock')->assertSessionHas('success');
        $audits = AuditEvent::query()->where('action', 'tax_year.updated')->count();

        $this->submit(['turnover' => '50,000', 'expenses' => '12,000.00', 'profit' => '38000'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success', 'All set. 2023-24 is locked, so the figures from its return weren’t changed. Import your first statement to start.');

        $year = $this->priorYear();
        $this->assertTrue($year->isLocked());
        $this->assertSame([4000000, 1000000, 3000000], [$year->filed_turnover_pence, $year->filed_expenses_pence, $year->filed_profit_pence]);
        $this->assertSame($audits, AuditEvent::query()->where('action', 'tax_year.updated')->count());
        // The rest of setup still went through.
        $this->assertTrue(TaxYear::query()->where('label', '2024-25')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'setup.completed')->exists());
    }

    public function test_an_open_prior_year_takes_the_figures_and_the_change_is_audited(): void
    {
        $this->submit(['turnover' => '£40,000', 'expenses' => '10,000.50', 'profit' => '29999.50'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success', 'All set. Import your first statement to start.');

        $year = $this->priorYear();
        $this->assertSame('filed', $year->status);
        $this->assertSame([4000000, 1000050, 2999950], [$year->filed_turnover_pence, $year->filed_expenses_pence, $year->filed_profit_pence]);

        $event = AuditEvent::query()->where('action', 'tax_year.updated')->where('subject_id', $year->id)->sole();
        $this->assertNull($event->before['filed_turnover_pence']);
        $this->assertSame(4000000, $event->after['filed_turnover_pence']);
        $this->assertSame(2999950, $event->after['filed_profit_pence']);
    }

    public function test_running_setup_again_changes_only_the_figures_typed_in(): void
    {
        TaxYear::track('2023-24', 'filed')->update(['filed_turnover_pence' => 4000000, 'filed_expenses_pence' => 1000000, 'filed_profit_pence' => 3000000]);

        $this->submit(['turnover' => '41,000'])->assertSessionHas('success', 'All set. Import your first statement to start.');

        $year = $this->priorYear();
        $this->assertSame([4100000, 1000000, 3000000], [$year->filed_turnover_pence, $year->filed_expenses_pence, $year->filed_profit_pence]);
        $event = AuditEvent::query()->where('action', 'tax_year.updated')->where('subject_id', $year->id)->sole();
        $this->assertSame(4000000, $event->before['filed_turnover_pence']);
        $this->assertSame(4100000, $event->after['filed_turnover_pence']);

        // The same figures again change nothing, so there's nothing new to audit.
        $this->submit(['turnover' => '41000.00'])->assertSessionHas('success');
        $this->assertSame(1, AuditEvent::query()->where('action', 'tax_year.updated')->where('subject_id', $year->id)->count());
    }

    public function test_an_amount_that_cant_be_read_is_refused_before_anything_is_saved(): void
    {
        $this->submit(['turnover' => '40.000,00'])
            ->assertRedirect('/setup')
            ->assertSessionHas('error', 'Can\'t read the amount "40.000,00". Use a full stop for pence, like 12.50.');

        $this->assertFalse(TaxYear::query()->exists());
        $this->assertFalse(AuditEvent::query()->where('action', 'setup.completed')->exists());
    }
}
