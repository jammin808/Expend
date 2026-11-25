<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Edit form on the Tax years page for a locked year: it always sends the filed figures back, so a locked year must
 * accept them unchanged (and save the status, filed date and HMRC reference with them), and refuse only a real change.
 */
class LockedYearEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Acme Events');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    private function lockedYear(array $figures = ['filed_profit_pence' => 1234500]): TaxYear
    {
        $year = TaxYear::track('2024-25', 'filed');
        $year->update(['filed_on' => '2025-11-01'] + $figures);
        $this->post('/years/2024-25/lock')->assertSessionHas('success');

        return $year->fresh();
    }

    /** The form exactly as YearEditor builds it from the /years props. */
    private function formFromPage(string $label): array
    {
        $page = $this->get('/years')->assertOk()->assertInertia(fn ($p) => $p->component('years/index', false))->viewData('page');
        $row = collect($page['props']['years'])->firstWhere('label', $label);
        $pounds = fn (?int $p) => $p === null ? '' : number_format($p / 100, 2, '.', '');

        return [
            'status' => $row['status'],
            'filed_on' => $row['filed_on'] ?? '',
            'hmrc_reference' => $row['hmrc_reference'] ?? '',
            'filed_turnover' => $pounds($row['filed_turnover_pence']),
            'filed_expenses' => $pounds($row['filed_expenses_pence']),
            'filed_profit' => $pounds($row['filed_profit_pence']),
        ];
    }

    public function test_a_locked_year_saves_its_form_sent_back_untouched(): void
    {
        $this->lockedYear();
        $form = $this->formFromPage('2024-25');
        $this->assertTrue($this->get('/years')->viewData('page')['props']['years'][0]['locked']);

        $this->from('/years')->patchJson('/years/2024-25', $form)->assertRedirect('/years')->assertSessionHas('success', 'Saved.');

        $this->assertSame(1234500, TaxYear::query()->where('label', '2024-25')->value('filed_profit_pence'));
    }

    public function test_a_locked_year_saves_the_hmrc_reference_status_and_filed_date(): void
    {
        $this->lockedYear();
        $form = $this->formFromPage('2024-25');

        $this->from('/years')->patchJson('/years/2024-25', ['hmrc_reference' => 'ABC123XYZ'] + $form)->assertSessionHas('success', 'Saved.');
        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame('ABC123XYZ', $year->hmrc_reference);
        $this->assertTrue($year->isLocked(), 'Saving the reference leaves the year locked');
        $this->assertSame(1234500, $year->filed_profit_pence);
        $this->assertTrue(AuditEvent::query()->where('action', 'tax_year.updated')->where('subject_id', $year->id)->exists());

        $form = $this->formFromPage('2024-25');
        $this->from('/years')->patchJson('/years/2024-25', ['status' => 'amending', 'filed_on' => '2025-11-03'] + $form)->assertSessionHas('success', 'Saved.');
        $year->refresh();
        $this->assertSame(['amending', '2025-11-03', 'ABC123XYZ'], [$year->status, $year->filed_on->format('Y-m-d'), $year->hmrc_reference]);
    }

    public function test_a_locked_year_with_no_figures_saves_the_form_with_its_blank_figures(): void
    {
        $this->lockedYear([]);
        $form = $this->formFromPage('2024-25');
        $this->assertSame(['', '', ''], [$form['filed_turnover'], $form['filed_expenses'], $form['filed_profit']]);

        $this->from('/years')->patchJson('/years/2024-25', ['hmrc_reference' => 'REF42'] + $form)->assertSessionHas('success', 'Saved.');

        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame('REF42', $year->hmrc_reference);
        $this->assertNull($year->filed_profit_pence);
    }

    public function test_a_locked_year_still_refuses_a_real_change_to_its_figures(): void
    {
        $this->lockedYear(['filed_turnover_pence' => 4000000, 'filed_profit_pence' => 1234500]);
        $form = $this->formFromPage('2024-25');
        $events = AuditEvent::query()->where('action', 'tax_year.updated')->count();

        foreach ([['filed_profit' => '12345.01'], ['filed_profit' => ''], ['filed_expenses' => '10.00'], ['filed_turnover' => '£40,000.00 extra']] as $change) {
            $this->from('/years')->patchJson('/years/2024-25', ['hmrc_reference' => 'NOPE'] + $change + $form)
                ->assertRedirect('/years')->assertSessionHas('error', '2024-25 is locked. Unlock it before changing its figures.');
        }
        // Written another way, the same figure is no change.
        $this->from('/years')->patchJson('/years/2024-25', ['filed_turnover' => '£40,000', 'filed_profit' => '12345.0'] + $form)->assertSessionHas('success', 'Saved.');

        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame([4000000, null, 1234500, null], [$year->filed_turnover_pence, $year->filed_expenses_pence, $year->filed_profit_pence, $year->hmrc_reference]);
        $this->assertSame($events + 1, AuditEvent::query()->where('action', 'tax_year.updated')->count());
    }

    public function test_a_locked_year_refuses_a_change_to_payments_on_account_but_not_the_same_amount(): void
    {
        $year = $this->lockedYear(['payments_on_account_paid_pence' => 150000]);

        $this->from('/years/2024-25')->patchJson('/years/2024-25', ['payments_on_account_paid' => '1600.00'])
            ->assertSessionHas('error', '2024-25 is locked. Unlock it before changing its figures.');
        $this->from('/years/2024-25')->patchJson('/years/2024-25', ['payments_on_account_paid' => '1500'])->assertSessionHas('success', 'Saved.');

        $this->assertSame(150000, $year->fresh()->payments_on_account_paid_pence);
    }

    public function test_a_reference_too_long_is_refused_in_plain_words_under_its_box(): void
    {
        $this->lockedYear();
        $form = $this->formFromPage('2024-25');

        // As Inertia sends it, so the error comes back for the form to show under the box.
        $this->from('/years')->json('PATCH', '/years/2024-25', ['hmrc_reference' => str_repeat('A', 61)] + $form, ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
            ->assertRedirect('/years')->assertSessionHasErrors(['hmrc_reference' => 'The HMRC reference can’t be longer than 60 characters.']);

        $this->assertNull(TaxYear::query()->where('label', '2024-25')->value('hmrc_reference'));
    }

    public function test_an_open_year_still_saves_its_figures(): void
    {
        TaxYear::track('2025-26');
        $form = $this->formFromPage('2025-26');

        $this->from('/years')->patchJson('/years/2025-26', ['filed_turnover' => '52,000.00', 'filed_profit' => '31000'] + $form)->assertSessionHas('success', 'Saved.');

        $year = TaxYear::query()->where('label', '2025-26')->first();
        $this->assertSame([5200000, null, 3100000], [$year->filed_turnover_pence, $year->filed_expenses_pence, $year->filed_profit_pence]);

        // A figure that isn't an amount is refused with a reason, not guessed.
        $this->from('/years')->patchJson('/years/2025-26', ['filed_profit' => '31,00'] + $form)->assertRedirect('/years')
            ->assertSessionHas('error', 'Can\'t read the amount "31,00". Use a full stop for pence, like 12.50.');
        $this->assertSame(3100000, $year->fresh()->filed_profit_pence);
    }
}
