<?php

namespace Tests\Feature\Intel;

use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Snooze and Not relevant on the to-do page take a reason, kept in todo_dismissals.note and the finding's note, and
 * recorded as events; undoing either is audited, including the jobs a mileage item left out.
 */
class FindingsDismissalTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->at('2025-07-01');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 09:00:00');
        CarbonImmutable::setTestNow($date.' 09:00:00');
    }

    private function row(Account $a, string $date, int $pence, ?int $balance): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $a->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);

        return BankTransaction::query()->create(['account_id' => $a->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => 'Line', 'merchant_key' => 'X', 'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    public function test_a_reason_is_kept_with_the_dismissal_and_the_finding_and_undo_is_audited(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'no_po:'.$job->id)->sole();

        $this->post('/todo/dismiss', ['key' => $finding->key, 'reason' => 'other', 'note' => 'The client never issues POs'])->assertSessionHas('success', 'Marked not relevant.');
        $this->assertSame('The client never issues POs', TodoDismissal::query()->where('key', $finding->key)->value('note'));
        $finding->refresh();
        $this->assertSame(['dismissed', 'The client never issues POs'], [$finding->state, $finding->note]);
        $dismissed = AuditEvent::query()->where('action', 'todo.dismissed')->sole();
        $this->assertSame(['you', 'The client never issues POs'], [$dismissed->actor, $dismissed->after['note']]);

        $this->post('/todo/dismiss', ['key' => $finding->key, 'undo' => true])->assertSessionHas('success', 'It’s back on the list.');
        $this->assertNull(TodoDismissal::query()->first());
        $restored = AuditEvent::query()->where('action', 'todo.restored')->sole();
        $this->assertSame('you', $restored->actor);
        $this->assertEquals([['key' => $finding->key, 'until' => null, 'note' => 'The client never issues POs']], $restored->before['dismissals']);
        $this->assertSame(['open', null, null], [$finding->fresh()->state, $finding->fresh()->note, $finding->fresh()->resolved_at]);
        $this->assertSame(['detected', 'dismissed', 'reopened'], FindingEvent::query()->where('finding_id', $finding->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame('audit:'.$restored->id.'@'.substr($restored->hash, 0, 8), FindingEvent::query()->where('finding_id', $finding->id)->where('event', 'reopened')->value('cause_ref'));

        // A snooze takes a reason of its own; one the list doesn't offer is refused.
        $this->post('/todo/dismiss', ['key' => $finding->key, 'days' => 7, 'reason' => 'waiting'])->assertSessionHas('success', 'Snoozed for 7 days.');
        $this->assertSame('Waiting to hear back', TodoDismissal::query()->where('key', $finding->key)->value('note'));
        $this->assertSame('snoozed', $finding->fresh()->state);
        $this->post('/todo/dismiss', ['key' => $finding->key, 'reason' => 'nonsense'])->assertSessionHasErrors('reason');
    }

    public function test_undoing_not_relevant_on_mileage_audits_the_jobs_it_puts_back(): void
    {
        $this->at('2026-11-24');
        Setting::put('home_postcode', 'SW1A 1AA');
        DB::table('places')->insert([['postcode' => 'SW1A 1AA', 'latitude' => 51.501, 'longitude' => -0.1416, 'created_at' => now(), 'updated_at' => now()],
            ['postcode' => 'RG1 1AA', 'latitude' => 51.4545, 'longitude' => -0.9781, 'created_at' => now(), 'updated_at' => now()]]);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $a = WorkJob::query()->create(['title' => 'Crew minibus show', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);
        $b = WorkJob::query()->create(['title' => 'Lift from a friend', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08', 'postcode' => 'RG1 1AA']);
        $item = collect(TodoList::build(100)['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $this->assertSame(['travel.unclaimed', 'claimable', 'likely', 'year:2026-27'], [$item['code'], $item['direction'], $item['grade'], $item['subject_ref']]);

        $this->post('/todo/dismiss', ['key' => $item['key'], 'reason' => 'not_mine'])->assertSessionHas('success');
        $set = substr($item['key'], strrpos($item['key'], ':') + 1);
        $this->assertSame(['Not mine', 'Not mine', 'Not mine'], TodoDismissal::query()->orderBy('id')->pluck('note')->all());

        $this->post('/todo/dismiss', ['key' => $item['key'], 'undo' => true]);
        $this->assertSame(0, TodoDismissal::query()->count());
        $restored = AuditEvent::query()->where('action', 'todo.restored')->sole();
        $this->assertEqualsCanonicalizing([$item['key'], TodoList::MILEAGE_JOB.$a->id.':'.$set, TodoList::MILEAGE_JOB.$b->id.':'.$set], array_column($restored->before['dismissals'], 'key'));
        $this->assertSame($item['key'], collect(TodoList::build(100)['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'))['key']);
    }

    public function test_a_gap_is_hidden_as_you_saw_it_and_comes_back_when_it_changes(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'is_active' => true]);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = collect(TodoList::build()['items'])->firstWhere('group', 'reconcile');
        $this->assertSame('gap:'.$bank->id.':2025-06-02', $gap['key']);

        // Marked not relevant from the page (by its key): kept as the gap you saw, its dates and amount.
        $this->post('/todo/dismiss', ['key' => $gap['key'], 'reason' => 'sorted'])->assertSessionHas('success');
        $this->assertSame(['gap:'.$bank->id.':2025-06-02:2025-06-09:5000'], TodoDismissal::query()->pluck('key')->all());
        $this->assertSame('dismissed', Finding::query()->where('key', $gap['key'])->value('state'));
        $this->assertNull(collect(TodoList::build()['items'])->firstWhere('group', 'reconcile'));

        // £30 of it turns up: what's left is a different gap from the one you set aside, so it's back.
        $this->at('2025-07-02');
        $this->row($bank, '2025-06-08', 3000, 105000);
        $this->assertSame('gap:'.$bank->id.':2025-06-02', collect(TodoList::build()['items'])->firstWhere('group', 'reconcile')['key']);
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', $gap['key'])->sole();
        $this->assertSame(['open', 2000, 1], [$f->state, $f->amount_pence, $f->reopened]);

        // Undo of a dismissal kept under the gap's dates works from its key too.
        $this->post('/todo/dismiss', ['key' => $gap['key'], 'days' => 7, 'reason' => 'later']);
        $this->assertContains('gap:'.$bank->id.':2025-06-02:2025-06-07:2000', TodoDismissal::query()->pluck('key')->all());
        $this->post('/todo/dismiss', ['key' => $gap['key'], 'undo' => true]);
        // The gap as it is now is back; the one you set aside before (£50) stays set aside.
        $this->assertSame(['gap:'.$bank->id.':2025-06-02:2025-06-09:5000'], TodoDismissal::query()->pluck('key')->all());
        $this->assertSame('gap:'.$bank->id.':2025-06-02', collect(TodoList::build()['items'])->firstWhere('group', 'reconcile')['key']);
    }

    public function test_the_page_shows_grade_direction_why_and_the_reasons_offered(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $this->get('/todo')->assertOk()->assertInertia(fn ($p) => $p
            ->where('directions.owed_to_you', 'you may be owed')
            ->where('snooze_reasons.waiting', 'Waiting to hear back')
            ->where('items', function ($items) use ($job) {
                $item = collect($items)->firstWhere('key', 'no_po:'.$job->id);

                return $item['grade'] === 'likely' && $item['why_refs'][0]['ref'] === 'job:'.$job->id && str_starts_with($item['why_refs'][0]['label'], 'Arena show')
                    && isset($item['reasons']['not_problem']) && ! isset($item['reasons']['real']) && ! isset($item['hidden_under']);
            }));
    }
}
