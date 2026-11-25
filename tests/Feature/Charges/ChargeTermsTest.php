<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Intel\AuditContext;
use App\Models\AuditEvent;
use App\Models\ChargeTerm;
use App\Models\TaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S5: how a job's costs are charged (ChargeTerms) and saving it (ChargeTermsWriter). The job's row wins,
 * else the paying client's usual (merges resolved), else none; arrangements mix. Saving is yours only and audited;
 * per mile sets fuel to not charged; the presets are ordinary rows; a stale save is refused.
 */
class ChargeTermsTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCharges();
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    private function writer(): ChargeTermsWriter
    {
        return app(ChargeTermsWriter::class);
    }

    public function test_the_jobs_row_wins_then_the_clients_usual_then_none_so_arrangements_mix(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $this->writer()->set($acme, ['rail' => 'at_cost', 'taxi' => 'at_cost', 'hotel' => 'none', 'mileage' => ['charge' => 'per_mile', 'rate_minor' => 45]]);
        $this->writer()->set($job, ['hotel' => 'at_cost']);

        $terms = ChargeTerms::forJob($job);

        $this->assertSame(['charge' => 'at_cost', 'rate_minor' => null, 'currency' => 'GBP', 'from' => 'client'], $terms['rail']);
        $this->assertSame(['at_cost', 'job'], [$terms['hotel']['charge'], $terms['hotel']['from']], 'The job’s row changes only its own kind');
        $this->assertSame(['per_mile', 45, 'client'], [$terms['mileage']['charge'], $terms['mileage']['rate_minor'], $terms['mileage']['from']]);
        $this->assertSame(['none', 'client'], [$terms['fuel']['charge'], $terms['fuel']['from']], 'Per mile covers fuel');
        $this->assertSame([null, 'none'], [$terms['flight']['charge'], $terms['flight']['from']]);
        $this->assertSame(array_keys(ChargeTerm::CHARGES), array_keys($terms));
        $this->assertTrue(ChargeTerms::any($terms));
        $this->assertFalse(ChargeTerms::any(ChargeTerms::forJob($this->job($this->client('Big Prod Co')))));
    }

    public function test_a_merged_clients_jobs_follow_the_client_it_went_into(): void
    {
        $acme = $this->client();
        $dupe = $this->client('ACME EVENTS LTD');
        $this->writer()->set($acme, ['rail' => 'at_cost']);
        $this->writer()->set($dupe, ['rail' => 'none']);
        $job = $this->job($dupe);
        $dupe->forceFill(['merged_into_id' => $acme->id])->save();

        $this->assertSame('at_cost', ChargeTerms::forJob($job)['rail']['charge']);
        $this->assertSame('at_cost', ChargeTerms::forClient($dupe)['rail']['charge']);

        try {
            $this->writer()->set($dupe->fresh(), ['rail' => 'at_cost']);
            $this->fail('A merged client’s arrangement is the kept client’s');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('ACME EVENTS LTD is merged into Acme Events: change Acme Events’s arrangement instead.', $e->getMessage());
        }
    }

    public function test_saving_replaces_the_owners_rows_is_audited_and_saving_the_same_again_records_nothing(): void
    {
        $acme = $this->client();
        $this->writer()->set($acme, ['rail' => 'at_cost', 'hotel' => 'at_cost'], 'Their crew terms');

        $after = $this->writer()->set($acme, ['rail' => 'at_cost', 'mileage' => ['charge' => 'per_mile', 'rate_minor' => 45]]);

        $this->assertSame(['fuel', 'mileage', 'rail'], array_keys($after), 'Hotel left out is removed; fuel not charged beside per mile');
        $this->assertSame(['none', null], [$after['fuel']['charge'], $after['fuel']['rate_minor']]);
        $events = AuditEvent::query()->where('action', 'charge_terms.updated')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['you', 'Client', $acme->id, 'Their crew terms'], [$events[0]->actor, $events[0]->subject_type, (int) $events[0]->subject_id, $events[0]->reason]);
        $this->assertSame(['hotel', 'rail'], array_keys($events[1]->before['terms']));
        $this->assertSame(['fuel', 'mileage', 'rail'], array_keys($events[1]->after['terms']));

        $this->writer()->set($acme, ['rail' => 'at_cost', 'mileage' => ['charge' => 'per_mile', 'rate_minor' => 45], 'fuel' => 'none']);
        $this->assertSame(2, AuditEvent::query()->where('action', 'charge_terms.updated')->count(), 'Nothing changed: nothing recorded');

        // Fuel named beside per mile is kept as you said.
        $this->assertSame('at_cost', $this->writer()->set($acme, ['mileage' => ['charge' => 'per_mile', 'rate_minor' => 45], 'fuel' => 'at_cost'])['fuel']['charge']);
    }

    public function test_presets_are_ordinary_rows(): void
    {
        $acme = $this->client();

        $atCost = ChargeTermsWriter::preset('at_cost');
        $this->assertSame(['at_cost', 'at_cost', 'at_cost', 'none', 'none', 'none', 'none'],
            [$atCost['rail']['charge'], $atCost['hotel']['charge'], $atCost['fuel']['charge'], $atCost['fines']['charge'], $atCost['mileage']['charge'], $atCost['travel_fee']['charge'], $atCost['travel_days']['charge']]);

        // 2026-27: HMRC's car rate is 55p.
        $this->assertSame('Mileage at 55p + fares at cost', ChargeTermsWriter::label('mileage'));
        $rows = $this->writer()->applyPreset($acme, 'mileage');
        $this->assertSame(['per_mile', 55, 'none', 'at_cost'], [$rows['mileage']['charge'], $rows['mileage']['rate_minor'], $rows['fuel']['charge'], $rows['taxi']['charge']]);
        $this->assertSame('Mileage at 55p + fares at cost', AuditEvent::query()->where('action', 'charge_terms.updated')->latest('id')->value('reason'));
        $this->assertSame(45, ChargeTermsWriter::mileageRate('2025-26'));

        $this->assertSame([], array_filter(ChargeTermsWriter::preset('in_rate'), fn ($r) => $r['charge'] !== 'none'), 'It’s in my rate: nothing charged');
        $fee = ChargeTermsWriter::preset('travel_fee', 7500, 'per_day');
        $this->assertSame(['per_day', 7500, 'none'], [$fee['travel_fee']['charge'], $fee['travel_fee']['rate_minor'], $fee['rail']['charge']], 'Fares not charged');
        $this->assertSame(14, ChargeTerm::query()->ownedBy($acme)->count(), 'Every kind of cost named');

        $this->expectExceptionMessage('Say how much the travel fee is.');
        ChargeTermsWriter::preset('travel_fee');
    }

    public function test_what_doesnt_fit_is_refused_and_nothing_is_saved(): void
    {
        $acme = $this->client();
        foreach ([
            [['rail' => 'per_mile'], 'Train can’t be charged that way.'],
            [['parking_fines' => 'at_cost'], "'parking_fines' isn't a kind of cost."],
            [['mileage' => 'per_mile'], 'Mileage: say the rate.'],
            [['mileage' => ['charge' => 'their_form']], 'Mileage: say the rate.'],
            [['travel_fee' => ['charge' => 'per_job', 'rate_minor' => 0]], 'Travel fee: say the rate.'],
            [['rail' => ['charge' => 'at_cost', 'currency' => 'EURO']], "'EURO' isn't a currency."],
        ] as [$rows, $message]) {
            try {
                $this->writer()->set($acme, $rows);
                $this->fail($message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
        $this->assertSame(0, ChargeTerm::query()->count());
    }

    public function test_a_save_tied_to_rows_that_changed_since_is_refused(): void
    {
        $acme = $this->client();
        $this->writer()->set($acme, ['rail' => 'at_cost']);
        $fp = ChargeTermsWriter::fingerprint($acme);
        $this->writer()->set($acme, ['rail' => 'none']);

        try {
            $this->writer()->set($acme, ['rail' => 'at_cost', 'hotel' => 'at_cost'], null, $fp);
            $this->fail('A stale save is refused');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This changed since you opened it: look again. Nothing was saved.', $e->getMessage());
        }
        $this->assertSame(['rail' => ['charge' => 'none', 'rate_minor' => null, 'currency' => 'GBP']], ChargeTerms::own($acme));

        $this->writer()->set($acme, ['hotel' => 'at_cost'], null, ChargeTermsWriter::fingerprint($acme));
        $this->assertSame(['hotel'], array_keys(ChargeTerms::own($acme)));
    }

    public function test_only_you_save_an_arrangement_and_a_locked_year_doesnt_stop_it(): void
    {
        $acme = $this->client();
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            try {
                AuditContext::with($actor, fn () => $this->writer()->set($acme, ['rail' => 'at_cost']));
                $this->fail("Refused for {$actor}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Only you can change how travel and expenses are charged.', $e->getMessage());
            }
        }
        $this->assertSame(0, ChargeTerm::query()->count());

        TaxYear::track('2026-27')->forceFill(['locked_at' => now()])->save();
        $this->writer()->set($this->job($acme), ['rail' => 'none']);
        $this->assertSame(1, ChargeTerm::query()->count(), 'Not ledger data: a locked year doesn’t block it');
    }
}
