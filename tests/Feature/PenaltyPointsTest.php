<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\LateSubmissionPoints;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\Submissions;
use App\Domain\Tax\TaxSavings;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxSubmission;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Late submission points (FA 2021 Sch 24): the return and Making Tax Digital quarterly updates, a point for each late
 * one (at most one a month for each kind, none at the threshold), £200 at the threshold, expiry and the all-points
 * reset, moving into and out of quarterly updates, and where it shows (year page, to-do list, assistant). Expected
 * figures are the settled rules' worked examples (itsa_ls_points_and_threshold, itsa_ls_200_penalty,
 * itsa_ls_points_expiry).
 */
class PenaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->at('2026-11-24');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 10:00:00');
        CarbonImmutable::setTestNow($date.' 10:00:00');
    }

    private function tx(string $date, int $pence, string $kind, ?string $code = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind.' '.$this->n, 'merchant_key' => strtoupper($kind).$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null));

        return $tx;
    }

    /** A submission for the pure ledger: sent on $sent (null: not sent). */
    private function ob(string $key, string $due, ?string $sent, array $extra = []): array
    {
        return array_replace(['key' => $key, 'kind' => str_contains($key, ':q') ? 'quarterly' : 'annual', 'due' => CarbonImmutable::parse($due),
            'submitted' => $sent ? CarbonImmutable::parse($sent) : null, 'made' => $sent !== null, 'excused' => false, 'eligible' => true, 'counts' => true, 'reason' => null], $extra);
    }

    private function ledger(array $obligations, array $groups, string $asOf): array
    {
        return LateSubmissionPoints::run($obligations, $groups, CarbonImmutable::parse($asOf), PenaltyEstimator::rules()['points']);
    }

    private function codes(array $year, string $list = 'penalties'): array
    {
        return collect($year[$list])->mapWithKeys(fn ($p) => [$p['code'] => [$p['date'], $p['amount_pence']]])->all();
    }

    /** In Making Tax Digital from 6 April 2026 (2024-25 turnover over £50,000), with the 2024-25 return filed on time. */
    private function mandated(): void
    {
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10']);
    }

    /** Record the day a quarterly update was sent (null: not sent). */
    private function sent(string $label, int $quarter, ?string $on): void
    {
        Submissions::sync($label);
        TaxSubmission::query()->where('tax_year', $label)->where('kind', 'quarterly')->orderBy('period_start')->get()[$quarter - 1]->update(['submitted_on' => $on]);
    }

    // ---- The count itself ----

    public function test_a_failure_is_the_day_after_the_due_date_and_a_point_lasts_24_months_from_the_next_month(): void
    {
        $l = $this->ledger([
            $this->ob('2027-28:q1', '2027-08-07', '2027-08-08'),  // one day late
            $this->ob('2027-28:q2', '2027-11-07', '2027-11-07'),  // sent on the due date: on time
            $this->ob('2026-27:annual', '2028-01-31', null),       // never sent
        ], ['2026-27' => '1B', '2027-28' => '1B'], '2028-03-01');

        $this->assertSame(['2027-08-08', 'late', true, 1, '2029-08-31'], [$l['events']['2027-28:q1']['failure_on'], $l['events']['2027-28:q1']['state'], $l['events']['2027-28:q1']['point'],
            $l['events']['2027-28:q1']['held'], $l['events']['2027-28:q1']['expires_on']], 'update due 7 Aug 2027: fails 8 Aug, expires at the end of 31 Aug 2029');
        $this->assertSame(['on_time', null], [$l['events']['2027-28:q2']['state'], $l['events']['2027-28:q2']['failure_on']]);
        $this->assertSame(['2028-02-01', 'not_sent', 2, '2030-02-28'], [$l['events']['2026-27:annual']['failure_on'], $l['events']['2026-27:annual']['state'],
            $l['events']['2026-27:annual']['held'], $l['events']['2026-27:annual']['expires_on']], 'return due 31 Jan 2028: fails 1 Feb, expires at the end of 28 Feb 2030');
        $this->assertSame([2, 4, '1B'], [$l['held'], $l['threshold'], $l['group']]);
        $this->assertSame(['2027-08-08', '2028-02-01'], array_column($l['points'], 'failure_on'));
    }

    public function test_200_only_when_a_point_reaches_the_threshold_or_you_are_already_there_and_once_a_month_per_kind(): void
    {
        $groups = ['2026-27' => '1B', '2027-28' => '1B', '2028-29' => '1B'];
        $obs = [
            // The settled April 2026 example: late 7 Aug 2027 and 7 Nov 2027 updates, 31 Jan 2028 return, 7 Feb 2028 update.
            $this->ob('2027-28:q1', '2027-08-07', '2027-08-20'),
            $this->ob('2027-28:q2', '2027-11-07', '2027-11-30'),
            $this->ob('2026-27:annual', '2028-01-31', '2028-02-10'),
            $this->ob('2027-28:q3', '2028-02-07', '2028-02-15'),
            $this->ob('2027-28:q4', '2028-05-07', '2028-05-01'),
            $this->ob('2028-29:q1', '2028-08-07', '2028-08-01'),
            $this->ob('2028-29:q2', '2028-11-07', '2028-11-01'),
            // At the threshold: the 31 Jan 2029 return (fails 1 Feb) and the 7 Feb 2029 update (fails 8 Feb) cost £200
            // each, £400 in February; a second return failing the same month is covered by the first £200.
            $this->ob('2027-28:annual', '2029-01-31', '2029-02-05'),
            $this->ob('extra:annual', '2029-01-31', '2029-02-05'),
            $this->ob('2028-29:q3', '2029-02-07', '2029-02-12'),
        ];
        $l = $this->ledger($obs, $groups, '2029-02-20');
        $e = $l['events'];
        $this->assertSame([1, 2, 3, 4], [$e['2027-28:q1']['held'], $e['2027-28:q2']['held'], $e['2026-27:annual']['held'], $e['2027-28:q3']['held']]);
        $this->assertSame([0, 0, 0, 20_000], [$e['2027-28:q1']['penalty_pence'], $e['2027-28:q2']['penalty_pence'], $e['2026-27:annual']['penalty_pence'], $e['2027-28:q3']['penalty_pence']],
            'the 4th point, on 8 Feb 2028, brings £200');
        $this->assertSame([false, 20_000], [$e['2027-28:annual']['point'], $e['2027-28:annual']['penalty_pence']], 'no point at the threshold, but £200');
        $this->assertSame(20_000, $e['2028-29:q3']['penalty_pence'], 'the updates are capped separately from the return');
        $this->assertSame(0, $e['extra:annual']['penalty_pence']);
        $this->assertStringContainsString('already covers it', $e['extra:annual']['note']);
        $this->assertSame(4, $l['held']);
        // At the threshold points don't expire on their own; they all go together.
        $this->assertTrue(collect($l['points'])->every(fn ($p) => $p['stays']));

        // £200 is never charged for a failure that gives no point below the threshold (the old pointsBefore >=
        // threshold - 1 test charged it).
        $l = $this->ledger([
            $this->ob('2024-25:annual', '2026-01-31', '2026-02-10'),
            $this->ob('2026-27:q1', '2026-08-07', null, ['eligible' => false, 'reason' => 'No point: first year.']),
        ], ['2024-25' => '1A', '2025-26' => '1A', '2026-27' => '1A'], '2026-11-24');
        $this->assertSame([1, 0, false], [$l['held'], $l['events']['2026-27:q1']['penalty_pence'], $l['events']['2026-27:q1']['point']]);
        $this->assertSame('No point: first year.', $l['events']['2026-27:q1']['note']);
    }

    public function test_one_point_a_month_for_each_kind_of_submission(): void
    {
        $l = $this->ledger([
            $this->ob('2025-26:annual', '2028-01-31', null),
            $this->ob('2026-27:annual', '2028-01-31', null),
            $this->ob('2027-28:q3', '2028-02-07', null),
        ], ['2025-26' => '1B', '2026-27' => '1B', '2027-28' => '1B'], '2028-03-01');
        $this->assertSame([true, false, true], [$l['events']['2025-26:annual']['point'], $l['events']['2026-27:annual']['point'], $l['events']['2027-28:q3']['point']]);
        $this->assertStringContainsString('one point a month for each kind of submission', $l['events']['2026-27:annual']['note']);
        $this->assertSame(2, $l['held']);
    }

    public function test_points_stay_at_the_threshold_and_all_go_after_24_months_on_time_for_annual_filers(): void
    {
        // The settled volunteer example (threshold 2): late 2024-25 return, 1 point; late 2025-26 return, 2 points and
        // £200. The point runs for the 24 months beginning 1 March 2026, so to the end of 29 February 2028 (2028 is a
        // leap year; the settled example's "28 Feb 2028" overlooks that).
        $groups = ['2024-25' => '1A', '2025-26' => '1A', '2026-27' => '1A', '2027-28' => '1A'];
        $obs = [
            $this->ob('2024-25:annual', '2026-01-31', '2026-03-10'),
            $this->ob('2025-26:annual', '2027-01-31', '2027-02-20'),
            $this->ob('2026-27:annual', '2028-01-31', '2028-01-10'),
            $this->ob('2027-28:annual', '2029-01-31', '2029-01-15'),
        ];
        $l = $this->ledger($obs, $groups, '2027-03-01');
        $this->assertSame([1, 0, '2028-02-29'], [$l['events']['2024-25:annual']['held'], $l['events']['2024-25:annual']['penalty_pence'], $l['events']['2024-25:annual']['expires_on']]);
        $this->assertSame([2, 20_000], [$l['events']['2025-26:annual']['held'], $l['events']['2025-26:annual']['penalty_pence']]);

        // The first point reaches the end of its 24 months while you're at 2: it stays.
        $l = $this->ledger($obs, $groups, '2028-03-15');
        $this->assertSame(2, $l['held']);
        $this->assertTrue($l['points'][0]['stays']);
        $this->assertStringContainsString('stays until all your points go', collect($l['history'])->last()['text']);
        // Everything on time for 24 months from 1 March 2027, and nothing outstanding: all go on 1 March 2029.
        $this->assertSame('2029-03-01', $l['reset_on']);
        $this->assertSame(['2029-03-01', '2029-03-01'], array_column($l['points'], 'goes_on'));

        $l = $this->ledger($obs, $groups, '2029-03-02');
        $this->assertSame(0, $l['held']);
        $this->assertSame('2029-03-01', collect($l['history'])->last()['on']);
        $this->assertStringStartsWith('All 2 points went', collect($l['history'])->last()['text']);
    }

    public function test_with_quarterly_updates_points_all_go_after_12_months_once_nothing_is_outstanding(): void
    {
        $groups = ['2026-27' => '1B', '2027-28' => '1B', '2028-29' => '1B'];
        $obs = fn (?string $q4Sent) => [
            $this->ob('2026-27:q4', '2027-05-07', $q4Sent),             // late, and not sent for a while
            $this->ob('2027-28:q1', '2027-08-07', '2027-08-20'),        // late
            $this->ob('2027-28:q2', '2027-11-07', '2027-11-01'),
            $this->ob('2027-28:q3', '2028-02-07', '2028-02-01'),
            $this->ob('2027-28:q4', '2028-05-07', '2028-05-01'),
            $this->ob('2028-29:q1', '2028-08-07', '2028-08-01'),
        ];
        // 12 months on time from 1 September 2027 ends on 31 August 2028, but the 7 May 2027 update is still
        // outstanding: everything due in the 24 months before has to be sent first (condition B).
        $l = $this->ledger($obs(null), $groups, '2028-09-15');
        $this->assertSame(2, $l['held']);
        $this->assertSame(['2026-27:q4'], $l['reset_needs']);
        $this->assertSame('2028-09-16', $l['reset_on'], 'if it is sent today');
        // Sent on 10 October 2028: all points go at the start of the next day.
        $l = $this->ledger($obs('2028-10-10'), $groups, '2028-11-01');
        $this->assertSame(0, $l['held']);
        $this->assertSame('2028-10-11', collect($l['history'])->last()['on']);

        // Sent in time: all go on 1 September 2028, 12 months before the 24-month expiry (31 August 2029).
        $l = $this->ledger($obs('2027-06-01'), $groups, '2028-03-01');
        $this->assertSame([2, '2028-09-01'], [$l['held'], $l['reset_on']]);
        $this->assertSame(['2028-09-01', '2028-09-01'], array_column($l['points'], 'goes_on'));
    }

    public function test_an_excused_failure_earns_nothing_and_counts_as_on_time(): void
    {
        $l = $this->ledger([
            $this->ob('2027-28:q1', '2027-08-07', '2027-08-20'),
            $this->ob('2027-28:q2', '2027-11-07', '2027-12-20', ['excused' => true]),
            $this->ob('2027-28:q3', '2028-02-07', '2028-02-01'),
        ], ['2027-28' => '1B'], '2028-03-01');
        $this->assertSame(['excused', false, 0], [$l['events']['2027-28:q2']['state'], $l['events']['2027-28:q2']['point'], $l['events']['2027-28:q2']['penalty_pence']]);
        $this->assertSame(1, $l['held']);
        $this->assertSame('2028-09-01', $l['reset_on'], 'the 12 months run from the last unexcused failure (August 2027)');
    }

    public function test_starting_quarterly_updates_adds_2_points_and_stopping_takes_2_off(): void
    {
        // A volunteer with 1 point who becomes mandated: 1 point becomes 3 (GOV.UK), dated with the latest failure.
        $l = $this->ledger([$this->ob('2024-25:annual', '2026-01-31', null)], ['2024-25' => '1A', '2025-26' => '1A', '2026-27' => '1B'], '2026-05-01');
        $this->assertSame([3, 4, '1B'], [$l['held'], $l['threshold'], $l['group']]);
        $this->assertSame([false, true, true], array_column($l['points'], 'added'));
        $this->assertSame(['2028-02-29', '2028-02-29', '2028-02-29'], array_column($l['points'], 'expires_on'));
        $this->assertStringContainsString('from 1 to 3', collect($l['history'])->last()['text']);

        // Leaving quarterly updates with 3 points: 1 is left, the latest.
        $l = $this->ledger([
            $this->ob('2026-27:q1', '2026-08-07', '2026-08-20'),
            $this->ob('2026-27:q2', '2026-11-07', '2026-11-20'),
            $this->ob('2026-27:q3', '2027-02-07', '2027-02-20'),
        ], ['2026-27' => '1B', '2027-28' => '1A'], '2027-05-01');
        $this->assertSame([1, 2, '1A'], [$l['held'], $l['threshold'], $l['group']]);
        $this->assertSame('2027-02-08', $l['points'][0]['failure_on']);

        // No points: none are added.
        $l = $this->ledger([$this->ob('2025-26:annual', '2027-01-31', '2027-01-20')], ['2025-26' => '1A', '2026-27' => '1B'], '2026-11-24');
        $this->assertSame(0, $l['held']);
    }

    // ---- In the estimator ----

    public function test_quarterly_updates_are_listed_with_their_due_dates_and_earn_no_points_in_the_first_year(): void
    {
        $this->mandated();
        TaxYear::track('2026-27');
        $this->assertSame(['2027-08-07', '2027-11-07', '2028-02-07', '2028-05-07'], array_map(fn ($q) => $q['due_on']->format('Y-m-d'), Submissions::quarters('2027-28')));
        $this->assertSame(['2027-04-06', '2027-07-06', '2027-10-06', '2028-01-06'], array_map(fn ($q) => $q['period_start']->format('Y-m-d'), Submissions::quarters('2027-28')));
        $this->assertSame('2028-04-05', Submissions::quarters('2027-28')[3]['period_end']->format('Y-m-d'));

        $y = PenaltyEstimator::forYear('2026-27');
        $s = collect($y['submissions']);
        $this->assertSame(['annual', 'quarterly', 'quarterly', 'quarterly', 'quarterly'], $s->pluck('kind')->all());
        $this->assertSame(['2028-01-31', '2026-08-07', '2026-11-07', '2027-02-07', '2027-05-07'], $s->pluck('due_on')->all());
        $this->assertSame(['not_due', 'not_sent', 'not_sent', 'not_due', 'not_due'], $s->pluck('status')->all());
        $this->assertTrue($s[0]['derived']);
        // Nothing recorded as sent: what follows if it wasn't sent in time, not "late" as a fact.
        $this->assertSame('If it wasn’t sent on time: no point, because late quarterly updates in your first year in Making Tax Digital (2026-27) don’t earn points. That’s HMRC’s practice, not law.', $s[1]['effect']);
        $this->assertEmpty(array_filter(array_keys($this->codes($y)), fn ($c) => str_starts_with($c, 'points') || str_starts_with($c, 'sch24')));
        $this->assertSame([0, 4, false], [$y['points']['held'], $y['points']['threshold'], $y['points']['at_threshold']]);
        $this->assertStringContainsString('Quarterly updates with no “sent on” date are taken as not sent', implode(' ', $y['assumptions']));
        $this->assertStringContainsString('first year in Making Tax Digital (2026-27): that’s HMRC’s practice, not law', implode(' ', $y['points']['assumptions']));
        $this->assertContains('Send the quarterly update for 6 April to 5 July 2026 (due 7 August 2026) and the quarterly update for 6 July to 5 October 2026 (due 7 November 2026) as soon as you can, '
            .'then record the dates you sent them on this page. Already sent? Just record the date.', $y['actions']);

        // The tax savings review mentions the point the return would earn, not "£0.00 more".
        $fines = collect(TaxSavings::review('2026-27')['items'] ?? [])->firstWhere('id', 'penalties-and-interest');
        $this->assertStringContainsString('and a late submission point if nothing changes', $fines['finding']);

        // Someone not in Making Tax Digital has no quarterly updates.
        $this->assertNull(Submissions::quarterlyBasis('2025-26'));
        $this->assertSame('mandated', Submissions::quarterlyBasis('2026-27'));
        Setting::put('mtd_exempt', true);
        $this->assertNull(Submissions::quarterlyBasis('2026-27'));
    }

    public function test_the_april_2026_example_four_points_and_200_on_8_february_2028(): void
    {
        $this->mandated();
        TaxYear::track('2026-27');
        foreach ([1, 2, 3, 4] as $q) {
            $this->sent('2026-27', $q, Submissions::quarters('2026-27')[$q - 1]['due_on']->format('Y-m-d'));
        }
        $this->sent('2027-28', 1, '2027-08-20');
        $this->sent('2027-28', 2, '2027-11-30');
        $this->at('2028-02-20');

        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame(['2028-02-01', 0], $this->codes($y)['points']);
        $this->assertSame('Late return: a late submission point (3 of 4)', collect($y['penalties'])->firstWhere('code', 'points')['label']);
        $this->assertSame('2030-02-28', collect($y['penalties'])->firstWhere('code', 'points')['expires_on']);

        $y = PenaltyEstimator::forYear('2027-28');
        $codes = $this->codes($y);
        $this->assertSame(['2027-08-08', 0], $codes['points_q1']);
        $this->assertSame(['2027-11-08', 0], $codes['points_q2']);
        $this->assertSame(['2028-02-08', 0], $codes['points_q3']);
        $this->assertSame(['2028-02-08', 20_000], $codes['sch24_200_q3']);
        $this->assertSame(20_000, $y['penalties_pence']);
        $fine = collect($y['penalties'])->firstWhere('code', 'sch24_200_q3');
        $this->assertTrue($fine['estimate']);
        $this->assertStringContainsString('Assumes HMRC give each point', $fine['assumption']);
        $this->assertSame('2030-02-07', $fine['date_a'], 'HMRC must charge it within 2 years of the failure');
        $q3 = collect($y['penalties'])->firstWhere('code', 'points_q3');
        $this->assertTrue($q3['estimate'], 'no sent date: taken as not sent');
        // If nothing changes the 7 May 2028 update is late at the threshold: another £200.
        $this->assertSame(['sch24_200_q4' => ['2028-05-08', 20_000]], $this->codes($y, 'upcoming'));
        $this->assertSame(20_000, $y['upcoming_pence']);

        // Your points as they stand: 4 of 4, all staying until they all go, which needs the late return and the
        // 7 February update sent.
        $p = $y['points'];
        $this->assertSame([4, 4, true], [$p['held'], $p['threshold'], $p['at_threshold']]);
        $this->assertTrue(collect($p['points'])->every(fn ($x) => $x['stays']));
        $this->assertSame('2029-03-01', $p['reset_on']);
        $this->assertSame(['the 2026-27 return', 'the quarterly update for 6 October 2027 to 5 January 2028'], $p['reset_needs']);
        $this->assertSame([20_000], array_column(array_filter($p['penalties'], fn ($f) => ! $f['projected']), 'pence'));
        $this->assertSame(20_000, $p['penalties_pence']);
        $this->assertSame(['the quarterly update for 6 January to 5 April 2028', '2028-05-07', '£200'], [$p['next']['what'], $p['next']['due_on'], $p['next']['if_late']]);
        $this->assertSame('You have 4 late submission points, the threshold: each late submission costs £200, and your points don’t expire on their own. '
            .'If you send the 2026-27 return and the quarterly update for 6 October 2027 to 5 January 2028 now and everything else on time, they all go on 1 March 2029.', $p['text']);
        $this->assertSame($p, PenaltyEstimator::all()['points']['law']);

        // The late return's action says what it cost.
        $this->assertStringStartsWith('File the return as soon as you can. Being late has earned a late submission point (you have 4 of 4 now)', PenaltyEstimator::forYear('2026-27')['actions'][0]);
    }

    public function test_an_accepted_volunteer_has_threshold_2_and_no_points_for_quarterly_updates(): void
    {
        Setting::put('mtd_volunteer_from', '2024-25');
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        TaxYear::track('2025-26');

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame(['2026-02-01', 0], $this->codes($y)['points']);
        $point = collect($y['penalties'])->firstWhere('code', 'points');
        $this->assertSame(['Late return: a late submission point (1 of 2)', '2028-02-29'], [$point['label'], $point['expires_on']]);
        $this->assertStringContainsString('At 2 points (the threshold for annual returns only)', $point['why']);
        // If nothing changes, the late 2025-26 return makes 2 points and £200.
        $next = PenaltyEstimator::forYear('2025-26');
        $this->assertSame(['points' => ['2027-02-01', 0], 'sch24_200' => ['2027-02-01', 20_000]], array_intersect_key($this->codes($next, 'upcoming'), ['points' => 1, 'sch24_200' => 1]));
        $this->assertSame('File the return by 31 January 2027 to avoid a late submission point and a £200 penalty (it would take you to 2 points).', $next['actions'][0]);

        // Quarterly updates from 2026-27 are listed (you send them) but never counted.
        $q = collect(PenaltyEstimator::forYear('2026-27')['submissions'])->where('kind', 'quarterly');
        $this->assertCount(4, $q);
        $this->assertTrue($q->every(fn ($s) => $s['volunteer'] && $s['effect'] === 'Not counted for points: HMRC give accepted volunteers no points for quarterly updates.'));
        $this->assertStringContainsString('As an accepted volunteer your threshold is 2', implode(' ', PenaltyEstimator::forYear('2026-27')['points']['assumptions']));

        $this->at('2027-03-01');
        $this->assertSame(['2027-02-01', 20_000], $this->codes(PenaltyEstimator::forYear('2025-26'))['sch24_200']);
    }

    public function test_a_volunteer_who_joins_making_tax_digital_goes_from_1_point_to_3(): void
    {
        // Volunteer from 2024-25 (threshold 2), then in Making Tax Digital from 6 April 2026 (2024-25 turnover over
        // £50,000): the point for the late 2024-25 return becomes 3 of 4 (GOV.UK).
        Setting::put('mtd_volunteer_from', '2024-25');
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');

        $p = PenaltyEstimator::forYear('2026-27')['points'];
        $this->assertSame([3, 4, '1B'], [$p['held'], $p['threshold'], $p['group']]);
        $this->assertSame([false, true, true], array_column($p['points'], 'added'));
        $this->assertContains('Starting quarterly updates took your points from 1 to 3.', array_column($p['history'], 'text'));
        $this->assertStringContainsString('Moving into quarterly updates adds 2 points', implode(' ', $p['assumptions']));
        // 12 months on time from 1 December 2026 (the late 7 November update restarts the count, though it earns no
        // point), once the 2024-25 return and the two updates are sent.
        $this->assertSame('2027-12-01', $p['reset_on']);
        $this->assertSame(['the 2024-25 return', 'the quarterly update for 6 April to 5 July 2026', 'the quarterly update for 6 July to 5 October 2026'], $p['reset_needs']);
    }

    public function test_points_for_years_where_the_new_rules_are_only_announced_stay_out_of_the_figures(): void
    {
        config(['tax.years.2027-28' => config('tax.years.2026-27')]);
        $this->tx('2027-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2027-28');
        $this->at('2029-03-20');

        $y = PenaltyEstimator::forYear('2027-28');
        $this->assertSame('sch24_26_announced', $y['regime']);
        $this->assertNull($y['points']);
        $this->assertArrayNotHasKey('points', $this->codes($y));
        $this->assertArrayHasKey('sch55_initial', $this->codes($y));
        $alt = $y['announced'];
        $this->assertSame(0, collect($alt['penalties'])->firstWhere('code', 'points')['amount_pence']);
        $this->assertSame([1, 2, true], [$alt['points']['held'], $alt['points']['threshold'], $alt['points']['announced']]);
        $this->assertStringContainsString('Announced, not yet law', implode(' ', $alt['points']['assumptions']));
        $annual = collect($y['submissions'])->firstWhere('kind', 'annual');
        $this->assertTrue($annual['announced']);
        $this->assertStringEndsWith('(Under the announced rules, which aren’t law yet.)', $annual['effect']);
        $this->assertNull(PenaltyEstimator::all()['points']['law']);
    }

    // ---- Year page, to-do list, assistant ----

    public function test_the_year_page_lists_submissions_and_saves_the_day_each_was_sent(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mandated();

        $this->get('/years/2026-27')->assertOk()->assertInertia(fn ($p) => $p->has('penalties.submissions', 5)
            ->where('penalties.submissions.1.kind', 'quarterly')->where('penalties.submissions.1.id', fn ($id) => $id !== null)->where('penalties.points.threshold', 4));
        $this->assertSame(4, TaxSubmission::query()->where('tax_year', '2026-27')->where('kind', 'quarterly')->count());
        $annual = TaxSubmission::query()->where('tax_year', '2026-27')->where('kind', 'annual')->firstOrFail();
        $this->assertSame(['2028-01-31', null], [$annual->due_on->format('Y-m-d'), $annual->submitted_on]);
        $this->get('/years/2026-27')->assertOk();
        $this->assertSame(5, TaxSubmission::query()->where('tax_year', '2026-27')->count(), 'shown again, nothing is made twice');

        $q1 = TaxSubmission::query()->where('tax_year', '2026-27')->where('kind', 'quarterly')->orderBy('period_start')->firstOrFail();
        $this->patch("/years/2026-27/submissions/{$q1->id}", ['submitted_on' => '2026-08-01'])->assertSessionHas('success');
        $this->assertSame('2026-08-01', $q1->fresh()->submitted_on->format('Y-m-d'));
        $this->assertTrue(AuditEvent::query()->where('action', 'tax_submission.updated')->exists());
        $this->assertSame('on_time', collect(PenaltyEstimator::forYear('2026-27')['submissions'])->firstWhere('quarter', 1)['status']);

        $this->patch("/years/2026-27/submissions/{$q1->id}", ['submitted_on' => '2026-12-01'])->assertSessionHasErrors('submitted_on');
        $this->patch("/years/2026-27/submissions/{$q1->id}", ['submitted_on' => '2026-03-01'])->assertSessionHasErrors('submitted_on');
        $this->patch("/years/2026-27/submissions/{$q1->id}", ['submitted_on' => null])->assertSessionHas('success');
        $this->assertNull($q1->fresh()->submitted_on);
        $this->patch("/years/2026-27/submissions/{$q1->id}", ['excused' => true, 'note' => 'Hospital stay'])->assertSessionHas('success');
        $this->assertSame([true, 'Hospital stay'], [$q1->fresh()->excused, $q1->fresh()->note]);
        $this->assertSame('excused', collect(PenaltyEstimator::forYear('2026-27')['submissions'])->firstWhere('quarter', 1)['status']);
        $this->patch("/years/2025-26/submissions/{$q1->id}", ['submitted_on' => '2026-08-01'])->assertNotFound();

        // The return's date is the year's filed date: never entered twice.
        $this->patch("/years/2026-27/submissions/{$annual->id}", ['submitted_on' => '2027-06-01'])->assertSessionHas('error');
        $this->assertNull($annual->fresh()->submitted_on);
        $this->patch('/years/2026-27', ['status' => 'filed', 'filed_on' => '2026-11-20'])->assertSessionHas('success');
        $this->assertSame('2026-11-20', $annual->fresh()->submitted_on->format('Y-m-d'));
        $this->assertSame('on_time', collect(PenaltyEstimator::forYear('2026-27')['submissions'])->firstWhere('kind', 'annual')['status']);
    }

    public function test_the_to_do_list_reminds_about_updates_due_within_14_days_or_late(): void
    {
        $this->mandated();
        TaxYear::track('2026-27');
        $items = collect(TodoList::build()['items'])->keyBy('key');
        $late = $items['submission:2026-27:q2'];
        $this->assertSame('penalties', $late['group']);
        // Late only if it wasn't sent: Expend just has no date for it, so it says that rather than "late since".
        $this->assertSame('Quarterly update 2026-27 (6 Jul to 5 Oct 2026): no sent date recorded (due 7 November 2026)', $late['title']);
        $this->assertStringStartsWith('If late: no point, because late quarterly updates in your first year', $late['detail']);
        $this->assertStringContainsString('Already sent? Record the date on the year page.', $late['detail']);
        $this->assertTrue($late['snooze_only']);
        $this->assertArrayHasKey('submission:2026-27:q1', $items->all());
        $this->assertArrayNotHasKey('submission:2026-27:q3', $items->all(), 'due 7 February 2027: more than 14 days away');

        // Recorded as sent: no reminder. Within 14 days of the next one: a reminder saying what it costs.
        $this->sent('2026-27', 1, '2026-08-01');
        $this->sent('2026-27', 2, '2026-11-01');
        $this->at('2027-01-20');
        $this->assertEmpty(collect(TodoList::build()['items'])->filter(fn ($i) => str_starts_with($i['key'], 'submission:')));
        $this->at('2027-01-25');
        $soon = collect(TodoList::build()['items'])->keyBy('key')['submission:2026-27:q3'];
        $this->assertSame('Quarterly update 2026-27 (6 Oct 2026 to 5 Jan 2027): due 7 February 2027', $soon['title']);
        $this->assertStringContainsString('You have no late submission points', $soon['detail']);

        // The return's reminder says what being late costs in points.
        $this->sent('2026-27', 3, '2027-02-01');
        $this->sent('2026-27', 4, '2027-05-01');
        $this->sent('2027-28', 1, '2027-08-30');
        $this->sent('2027-28', 2, '2027-11-05');
        $this->at('2027-12-15');
        $deadline = collect(TodoList::build()['items'])->keyBy('key')['deadline:2026-27'];
        $this->assertStringContainsString('If it’s late: a point (2 of 4), expiring at the end of 28 February 2030.', $deadline['detail']);
    }

    public function test_the_assistant_reports_points_and_submissions(): void
    {
        $this->mandated();
        TaxYear::track('2026-27');
        $this->sent('2027-28', 1, '2027-08-20');
        $this->at('2027-09-10');

        $answer = app(AssistantTools::class)->run('penalties', ['tax_year' => '2027-28']);
        // Counted across years, so given once, at the top; the year points to it.
        $this->assertSame('see late_submission_points.law at the top', $answer['years'][0]['late_submission_points']);
        $points = $answer['late_submission_points']['law'];
        $this->assertSame([1, 4, true], [$points['points'], $points['threshold'], $points['estimate']]);
        $this->assertSame('2029-08-31', $points['each_point'][0]['expires_end_of']);
        $this->assertSame('2028-09-01', $points['all_go_on_if_everything_sent_on_time']);
        $subs = collect($answer['years'][0]['submissions']);
        $this->assertSame(['late', '2027-08-20'], [$subs[0]['status'], $subs[0]['sent']]);
        $this->assertSame('not recorded: taken as not sent', $subs[1]['sent']);
        $this->assertStringContainsString('late_submission_points', collect(AssistantTools::definitions())->firstWhere('name', 'penalties')['description']);

        // Its deadlines list carries the quarterly updates not recorded as sent.
        $next = collect(app(AssistantTools::class)->run('deadlines', [])['quarterly_updates_not_recorded_as_sent'])->firstWhere('due', '2027-11-07');
        $this->assertSame(['2027-28', false], [$next['tax_year'], $next['overdue']]);
        $this->assertStringStartsWith('If it’s late: a point (2 of 4)', $next['effect']);
    }
}
