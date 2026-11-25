<?php

namespace Tests\Feature\Income;

use App\Domain\Audit\AuditLog;
use App\Models\AuditEvent;
use App\Models\TaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Build slice S9 (income v3, critique 28): unlocking a filed year (to move a client, say) makes it 'amending'; locking
 * it again with the return's figures unchanged since the unlock puts it back to 'filed', so no false "amend your
 * return" is left. A figure that changed, a status you set by hand since, or an unlock recorded before this release
 * leaves it as it is.
 */
class TaxYearRelockTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->signIn();
        // Locking needs no reason when its checks pass: one decided payment in the year.
        $this->decide($this->tx(300000, 'ACME PAYROLL', '2025-05-20'), [$this->income(300000)]);
    }

    protected function tearDown(): void
    {
        // Only the spreadsheets these tests kept.
        foreach (AuditEvent::query()->where('action', 'tax_year.locked')->get() as $e) {
            $file = $e->after['snapshot']['export']['file'] ?? null;
            if ($file) {
                @unlink(storage_path('app/private/'.$file));
            }
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function filedAndLocked(): TaxYear
    {
        $year = TaxYear::track('2025-26');
        $year->forceFill(['status' => 'filed'])->save();
        $this->post('/years/2025-26/lock', ['reason' => 'Filed'])->assertSessionHas('success');

        return $year->fresh();
    }

    private function unlock(): void
    {
        $this->post('/years/2025-26/lock', ['reason' => 'Move a payment to another client'])->assertSessionHas('success', '2025-26 unlocked.');
    }

    public function test_relocking_with_no_figure_changed_puts_it_back_to_filed(): void
    {
        $year = $this->filedAndLocked();
        $this->unlock();
        $this->assertSame('amending', $year->fresh()->status);

        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success',
            '2025-26 locked. Nothing in it can change without unlocking. Its figures and a copy of the spreadsheet were kept with the lock. No figures changed, so there’s nothing to send HMRC: it’s marked filed again.');
        $this->assertSame('filed', $year->fresh()->status);
        $this->assertTrue($year->fresh()->isLocked());
        $locked = AuditEvent::query()->where('action', 'tax_year.locked')->orderByDesc('id')->first();
        $this->assertSame('filed', $locked->after['status_restored']);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_a_figure_that_changed_leaves_it_amending(): void
    {
        $year = $this->filedAndLocked();
        $this->unlock();
        $this->decide($this->tx(5000, 'ACME PAYROLL', '2025-06-20'), [$this->income(5000)]);

        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success');
        $this->assertSame('amending', $year->fresh()->status);
        $this->assertArrayNotHasKey('status_restored', AuditEvent::query()->where('action', 'tax_year.locked')->orderByDesc('id')->first()->after);
    }

    public function test_a_status_you_set_since_the_unlock_stays(): void
    {
        $year = $this->filedAndLocked();
        $this->unlock();
        $this->patch('/years/2025-26', ['status' => 'in_progress'])->assertSessionHas('success');

        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success');
        $this->assertSame('in_progress', $year->fresh()->status);
    }

    public function test_an_unlock_recorded_before_this_release_never_restores(): void
    {
        $year = $this->filedAndLocked();
        // An unlock as the release before this one recorded it: no before.
        $year->forceFill(['locked_at' => null, 'status' => 'amending'])->save();
        AuditLog::record('tax_year.unlocked', $year, null, null, 'Old unlock');

        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success');
        $this->assertSame('amending', $year->fresh()->status);
    }

    public function test_on_the_full_form_a_cost_moved_to_another_box_leaves_it_amending(): void
    {
        // Over the VAT threshold (the full form, expenses box by box): a £100 rail fare (box 20) and a £100 phone cost (box 23).
        $this->decide($this->tx(10000000, 'ACME PAYROLL', '2025-05-21'), [$this->income(10000000)]);
        $rail = $this->tx(-10000, 'TRAINLINE', '2025-06-01');
        $this->decide($rail, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -10000]]);
        $this->decide($this->tx(-10000, 'EE PHONE', '2025-06-02'), [['kind' => 'business_expense', 'category_id' => $this->cat('phone_internet'), 'amount_pence' => -10000]]);
        $year = $this->filedAndLocked();
        $this->unlock();
        $this->assertArrayHasKey('boxes', AuditEvent::query()->where('action', 'tax_year.unlocked')->orderByDesc('id')->first()->before);

        // The rail fare was really a phone cost: the totals stay, boxes 20 and 23 change.
        $this->decide($rail->fresh(), [['kind' => 'business_expense', 'category_id' => $this->cat('phone_internet'), 'amount_pence' => -10000]]);
        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success');
        $this->assertSame('amending', $year->fresh()->status);
        $this->assertArrayNotHasKey('status_restored', AuditEvent::query()->where('action', 'tax_year.locked')->orderByDesc('id')->first()->after);
    }

    public function test_on_the_full_form_relocking_with_every_box_as_it_was_puts_it_back_to_filed(): void
    {
        $this->decide($this->tx(10000000, 'ACME PAYROLL', '2025-05-21'), [$this->income(10000000)]);
        $rail = $this->tx(-10000, 'TRAINLINE', '2025-06-01');
        $this->decide($rail, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -10000]]);
        $year = $this->filedAndLocked();
        $this->unlock();
        // Re-saved as it was (a note added): no box changes.
        $this->decide($rail->fresh(), [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -10000, 'note' => 'Leeds']]);

        $this->post('/years/2025-26/lock', ['reason' => 'Done'])->assertSessionHas('success');
        $this->assertSame('filed', $year->fresh()->status);
    }
}
