<?php

namespace Tests\Feature\Jobs;

use App\Domain\Jobs\JobStage;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Build slice S4: JobStage. When a job finishes (its last day's end, in London time, past midnight for a late show),
 * when it asks "Is it done?" (booked, finished in the last 60 days, not billed, not snoozed), and one stage and chip
 * per job from booked to paid, worked out with a few batched queries.
 */
class JobStageTest extends TestCase
{
    use RefreshDatabase;

    private ?Client $client = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->now('2026-03-11 12:00:00'); // Wed 11 Mar 2026, 12:00 in London (GMT)
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->client = Client::query()->create(['name' => 'Acme Events']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function now(string $london): CarbonImmutable
    {
        $at = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        return $at;
    }

    /**
     * A job with its days, each [date, start, end] (times may be null).
     *
     * @param  list<array{0:string, 1?:?string, 2?:?string}>  $days
     */
    private function job(array $days, string $status = 'booked', array $extra = []): WorkJob
    {
        $dates = array_map(fn ($d) => $d[0], $days);
        $job = WorkJob::query()->create($extra + ['title' => 'Arena load-in '.(++$this->n), 'client_id' => $this->client->id, 'starts_on' => min($dates), 'ends_on' => max($dates),
            'status' => $status]);
        JobDay::query()->where('work_job_id', $job->id)->delete();
        foreach ($days as $d) {
            JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => $d[0], 'kind' => 'work', 'start_time' => $d[1] ?? null, 'end_time' => $d[2] ?? null, 'source' => 'you']);
        }

        return $job->fresh();
    }

    private function finish(WorkJob $job): string
    {
        return JobStage::finishAt($job)->setTimezone('Europe/London')->format('Y-m-d H:i');
    }

    private function invoice(WorkJob $job, array $fields = []): Invoice
    {
        return Invoice::query()->create($fields + ['status' => 'issued', 'number' => 'INV-'.str_pad((string) (++$this->n), 4, '0', STR_PAD_LEFT), 'client_id' => $this->client->id,
            'work_job_id' => $job->id, 'currency' => 'GBP', 'total_minor' => 90000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-03-01', 'due_date' => '2026-03-31']);
    }

    private function paid(Invoice $invoice, int $pence): void
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'mar.csv'],
            ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-03-05',
            'tax_year' => UkTaxYear::fromDate('2026-03-05')->label(), 'description' => 'Acme Events Ltd', 'counterparty' => 'Acme Events Ltd',
            'merchant_key' => 'ACME EVENTS LTD', 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'stage-'.(++$this->n)]);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'),
            'work_job_id' => $invoice->work_job_id, 'invoice_id' => $invoice->id, 'amount_pence' => $pence]]));
    }

    /** @return array{0:string, 1:string} stage and chip */
    private function stage(WorkJob $job, array $changes = []): array
    {
        $s = JobStage::of([$job->fresh()], null, $changes)[$job->id];

        return [$s['stage'], $s['chip']];
    }

    public function test_the_finish_is_the_end_of_the_last_day_in_london_time(): void
    {
        $this->assertSame('2026-03-09 18:00', $this->finish($this->job([['2026-03-09', '09:00', '18:00']])), 'A single day: its finish');
        $this->assertSame('2026-03-11 23:59', $this->finish($this->job([['2026-03-09'], ['2026-03-10'], ['2026-03-11']])), 'Days with no times: the end of the last');
        $this->assertSame('2026-03-13 23:59', $this->finish($this->job([['2026-03-09', '08:00', '18:00'], ['2026-03-13']])), 'A rig, then a de-rig days later');
        $this->assertSame('2026-03-10 01:30', $this->finish($this->job([['2026-03-09', '19:00', '01:30']])), 'A show past midnight finishes the next morning');
        $this->assertSame('2026-03-10 07:00', $this->finish($this->job([['2026-03-09', '22:00', '07:00']])), 'An overnight shift ends before it starts: the next morning');
        $this->assertSame('2026-03-10 04:00', $this->finish($this->job([['2026-03-09', '00:00', '04:00']])), 'Ending before 06:00 counts as the next morning');

        // The job's own times count for its last day when the day has none.
        $job = $this->job([['2026-03-09']], 'booked', ['start_time' => '19:00', 'end_time' => '01:30']);
        $this->assertSame('2026-03-10 01:30', $this->finish($job));

        // No days stored: its last date.
        $bare = $this->job([['2026-03-05']]);
        JobDay::query()->where('work_job_id', $bare->id)->delete();
        $this->assertSame('2026-03-05 23:59', $this->finish($bare->fresh()));
    }

    public function test_the_finish_is_london_time_across_the_clock_changes(): void
    {
        // Clocks go forward at 01:00 GMT on Sun 29 Mar 2026: a show from 20:00 to 02:30 finishes at 02:30 BST, 01:30 UTC.
        $spring = $this->job([['2026-03-28', '20:00', '02:30']]);
        $this->assertSame('2026-03-29 01:30', JobStage::finishAt($spring)->utc()->format('Y-m-d H:i'));
        // In summer the end of a day with no times is 22:59 UTC; in winter it's 23:59.
        $this->assertSame('2026-07-01 22:59', JobStage::finishAt($this->job([['2026-07-01']]))->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 23:59', JobStage::finishAt($this->job([['2026-12-01']]))->utc()->format('Y-m-d H:i'));
    }

    public function test_done_is_asked_once_the_last_day_has_finished_and_never_while_a_day_is_still_to_come(): void
    {
        $finished = $this->job([['2026-03-09', '09:00', '18:00'], ['2026-03-10', '09:00', '18:00']]);
        $this->assertTrue(JobStage::doneDue($finished));

        // A rig on Monday and a de-rig on Friday: on Wednesday, Friday is still to come.
        $apart = $this->job([['2026-03-09', '08:00', '18:00'], ['2026-03-13', '08:00', '16:00']]);
        $this->assertFalse(JobStage::doneDue($apart));
        $this->assertSame(['2026-03-13'], JobStage::daysToCome($apart));

        // A late show yesterday that runs to 01:30: not finished at 01:00, finished at 02:00.
        $late = $this->job([['2026-03-10', '19:00', '01:30']]);
        $this->now('2026-03-11 01:00:00');
        $this->assertFalse(JobStage::doneDue($late));
        $this->now('2026-03-11 02:00:00');
        $this->assertTrue(JobStage::doneDue($late));
    }

    public function test_only_jobs_that_finished_in_the_last_60_days_ask(): void
    {
        $recent = $this->job([['2026-01-12']]); // finished 11:59 pm on 12 Jan: 57 days ago
        $old = $this->job([['2026-01-08']]);    // 61 days ago
        $due = JobStage::of([$recent, $old]);

        $this->assertSame([true, 'done_due', 'Done?'], [$due[$recent->id]['done_due'], $due[$recent->id]['stage'], $due[$recent->id]['chip']]);
        $this->assertSame([false, 'not_done', 'Not marked done'], [$due[$old->id]['done_due'], $due[$old->id]['stage'], $due[$old->id]['chip']], 'Older ones keep "Invoice <job>"');
    }

    public function test_done_cancelled_billed_and_snoozed_jobs_are_not_asked(): void
    {
        $done = $this->job([['2026-03-09']], 'done');
        $cancelled = $this->job([['2026-03-09']], 'cancelled');
        $invoiced = $this->job([['2026-03-09']]);
        $this->invoice($invoiced);
        $snoozed = $this->job([['2026-03-09']]);
        TodoDismissal::query()->create(['key' => 'job_done:'.$snoozed->id, 'until' => now()->addDays(3), 'note' => 'Not finished yet']);
        $setAside = $this->job([['2026-03-09']]);
        TodoDismissal::query()->create(['key' => 'invoice:'.$setAside->id, 'until' => null, 'note' => 'Already sorted']);
        $expired = $this->job([['2026-03-09']]);
        TodoDismissal::query()->create(['key' => 'job_done:'.$expired->id, 'until' => now()->subMinute(), 'note' => 'Not finished yet']);

        $all = JobStage::of([$done, $cancelled, $invoiced, $snoozed, $setAside, $expired]);

        $this->assertSame([false, false, false, false, false, true], array_map(fn ($j) => $all[$j->id]['done_due'], [$done, $cancelled, $invoiced, $snoozed, $setAside, $expired]),
            'A snooze that has ended asks again');
    }

    public function test_each_job_has_one_stage_and_chip_from_booked_to_paid(): void
    {
        $this->assertSame(['upcoming', 'Starts Thu 2 Apr'], $this->stage($this->job([['2026-04-02']])));
        $this->assertSame(['under_way', 'Day 2 of 3 · last day Thu 12 Mar'], $this->stage($this->job([['2026-03-10'], ['2026-03-11'], ['2026-03-12']])));
        $this->assertSame(['under_way', 'Under way'], $this->stage($this->job([['2026-03-11', '09:00', '18:00']])));
        $this->assertSame(['upcoming', 'Starts Wed 11 Mar'], $this->stage($this->job([['2026-03-11', '19:00', '23:00']])), 'Later today: not started yet');

        $done = $this->job([['2026-03-05']], 'done');
        $this->assertSame(['done_no_draft', 'Done · no invoice yet'], $this->stage($done));
        $draft = $this->invoice($done, ['status' => 'draft', 'number' => null, 'issue_date' => null, 'due_date' => null]);
        $this->assertSame(['draft', 'Draft'], $this->stage($done));
        $this->assertSame(['draft', 'Draft · 2 changes'], $this->stage($done, [$draft->id => 2]));
        $this->assertSame(['draft', 'Draft · 1 change'], $this->stage($done, [$draft->id => 1]));

        $none = Client::query()->create(['name' => 'Timesheet Crew', 'invoice_route' => 'none']);
        $this->assertSame(['waiting_payment', 'Waiting for payment'], $this->stage($this->job([['2026-03-05']], 'done', ['client_id' => $none->id])));

        $this->assertSame(['cancelled', 'Cancelled'], $this->stage($this->job([['2026-03-20']], 'cancelled', ['cancel_charge' => 'none'])));
        $this->assertSame(['cancelled_to_invoice', 'Cancelled · to invoice'], $this->stage($this->job([['2026-03-20']], 'cancelled', ['cancel_charge' => 'fee', 'cancel_fee_pence' => 20000])));
    }

    public function test_an_issued_invoice_is_not_sent_sent_overdue_part_paid_paid_or_closed(): void
    {
        $job = $this->job([['2026-03-02']], 'done');
        $invoice = $this->invoice($job, ['issue_date' => '2026-03-03', 'due_date' => '2026-03-08']);
        $this->assertSame(['not_sent', 'Not sent'], $this->stage($job), 'Not sent is never overdue');

        InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'drafted', 'gmail_draft_id' => 'r-1']);
        $this->assertSame(['not_sent', 'In Gmail drafts'], $this->stage($job));
        $this->assertSame('drafted', JobStage::of([$job])[$job->id]['email']);

        $invoice->update(['sent_at' => '2026-03-03 10:00:00', 'sent_to' => 'accounts@acme.example.test']);
        $this->assertSame(['overdue', 'Overdue 3 days'], $this->stage($job));
        $this->assertSame(3, JobStage::of([$job])[$job->id]['overdue_days']);

        $this->now('2026-03-06 12:00:00');
        $this->assertSame(['sent', 'Due 8 Mar'], $this->stage($job));

        $this->paid($invoice, 45000);
        $this->assertSame(['part_paid', 'Part paid £450 of £900'], $this->stage($job));
        $this->paid($invoice, 45000);
        $this->assertSame(['paid', 'Paid'], $this->stage($job));

        $short = $this->job([['2026-03-02']], 'done');
        $closed = $this->invoice($short, ['sent_at' => '2026-03-03 10:00:00', 'total_minor' => 3000]);
        $closed->forceFill(['closed_minor' => 3000, 'closed_reason' => 'wont_chase', 'closed_at' => now()])->save();
        $this->assertSame(['closed', 'Closed · £30 not paid'], $this->stage($short));
    }

    public function test_a_job_with_several_invoices_shows_the_one_that_needs_you_most_and_income_alone_counts_as_paid(): void
    {
        $job = $this->job([['2026-03-02']], 'done');
        $first = $this->invoice($job, ['sent_at' => '2026-03-03 10:00:00']);
        $this->paid($first, 90000);
        $followUp = $this->invoice($job, ['total_minor' => 4160]);
        $s = JobStage::of([$job])[$job->id];
        $this->assertSame(['not_sent', $followUp->id], [$s['stage'], $s['invoice_id']]);

        // Paid without an Expend invoice (income recorded for the job): paid, never "Done?".
        $cash = $this->job([['2026-03-02']]);
        $tx = BankTransaction::query()->create(['account_id' => Account::query()->value('id'), 'import_batch_id' => ImportBatch::query()->value('id'), 'posted_on' => '2026-03-05',
            'tax_year' => '2025-26', 'description' => 'Acme Events Ltd', 'counterparty' => 'Acme Events Ltd', 'merchant_key' => 'ACME EVENTS LTD', 'amount_pence' => 65000, 'raw' => [], 'fingerprint' => 'cash-1']);
        app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'), 'work_job_id' => $cash->id, 'amount_pence' => 65000]]);
        $s = JobStage::of([$cash])[$cash->id];
        $this->assertSame(['paid', 'Paid', false], [$s['stage'], $s['chip'], $s['done_due']]);
    }

    public function test_stages_for_many_jobs_take_the_same_few_queries_as_for_one(): void
    {
        $jobs = [];
        foreach (range(1, 12) as $i) {
            $job = $this->job([['2026-03-0'.(1 + $i % 8)]], $i % 3 === 0 ? 'done' : 'booked');
            if ($i % 4 === 0) {
                $this->invoice($job, ['sent_at' => '2026-03-03 10:00:00']);
            }
            $jobs[] = $job->fresh('days');
        }
        $count = function (array $jobs): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            JobStage::of($jobs);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count([$jobs[3]]), $count($jobs));
    }
}
