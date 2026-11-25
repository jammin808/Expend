<?php

namespace Tests\Feature\Quick;

use App\Domain\Fx\RateUnavailable;
use App\Domain\Ledger\ManualExpenses;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\ManualExpense;
use App\Models\TaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The one way a hand-added cost is added, corrected and taken back (the Transactions form and the phone alike): any
 * failure to convert is RateUnavailable, a correction checks both years before writing, and a take-back clears its
 * lines through the writer and keeps everything it was in the history.
 */
class ManualExpensesTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    private function add(string $on = '2026-11-20', int $pence = -1250): ManualExpense
    {
        $x = app(ManualExpenses::class);

        return $x->create(['spent_on' => $on, 'description' => 'Parking', 'paid_with' => 'cash', 'kind' => 'business_expense', 'category_id' => $this->cat('travel_parking')],
            $x->convert($pence, 'GBP', CarbonImmutable::parse($on)));
    }

    public function test_hmrc_out_of_reach_is_a_rate_unavailable(): void
    {
        Http::fake(['www.trade-tariff.service.gov.uk/*' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(RateUnavailable::class);
        app(ManualExpenses::class)->convert(-1500, 'EUR', CarbonImmutable::parse('2026-11-25'));
    }

    public function test_a_date_cant_move_into_or_out_of_a_locked_year(): void
    {
        $open = $this->add('2026-11-20');
        TaxYear::track('2025-26')->update(['locked_at' => now()]);
        try {
            app(ManualExpenses::class)->correct($open, ['spent_on' => '2026-03-01']);
            $this->fail('Into a locked year');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2025-26 is locked', $e->getMessage());
        }
        $this->assertSame('2026-11-20', $open->fresh()->spent_on->format('Y-m-d'));

        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => null]);
        $old = $this->add('2026-03-01');
        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => now()]);
        $this->expectException(InvalidArgumentException::class);
        app(ManualExpenses::class)->correct($old, ['spent_on' => '2026-11-01']);
    }

    public function test_a_date_moved_to_another_open_year_moves_its_lines_with_it(): void
    {
        $x = $this->add('2026-04-10');
        app(ManualExpenses::class)->correct($x, ['spent_on' => '2026-04-01']);

        $this->assertSame(['2025-26', '2025-26'], [$x->fresh()->tax_year, Allocation::query()->where('manual_expense_id', $x->id)->sole()->tax_year]);
    }

    public function test_withdraw_clears_through_the_writer_and_keeps_what_it_was(): void
    {
        $x = $this->add();
        app(ManualExpenses::class)->withdraw($x, 'Undo on the phone');

        $this->assertSame([0, 0], [ManualExpense::query()->count(), Allocation::query()->count()]);
        $this->assertTrue(AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $x->id)->exists());
        $event = AuditEvent::query()->where('action', 'manual_expense.withdrawn')->where('subject_id', $x->id)->sole();
        $this->assertSame(['Parking', -1250, 'Undo on the phone'], [$event->before['description'], $event->before['amount_pence'], $event->reason]);
    }

    public function test_withdraw_is_refused_in_a_locked_year(): void
    {
        $x = $this->add('2026-03-01');
        TaxYear::track('2025-26')->update(['locked_at' => now()]);

        $this->expectExceptionMessage('Kept: 2025-26 is locked');
        app(ManualExpenses::class)->withdraw($x);
    }

    public function test_the_transactions_form_still_adds_as_before(): void
    {
        $this->post('/expenses', ['spent_on' => '2026-11-20', 'description' => 'Gaffa tape', 'direction' => 'out', 'amount' => '8.40', 'currency' => 'GBP',
            'paid_with' => 'cash', 'kind' => 'business_expense', 'category_id' => $this->cat('materials')])->assertSessionHas('success', 'Added £8.40.');

        $x = ManualExpense::query()->sole();
        $this->assertSame([-840, 'GAFFA TAPE'], [$x->amount_pence, $x->merchant_key]);
    }
}
