<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobCreator;
use App\Jobs\GatherForJob;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class JobCreatorTest extends TestCase
{
    use RefreshDatabase;

    private function creator(): JobCreator
    {
        return app(JobCreator::class);
    }

    /** @return list<array{0:string,1:string}> the job's days as [date, kind] */
    private function days(WorkJob $job): array
    {
        return $job->days()->get()->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->kind])->all();
    }

    public function test_a_job_is_made_with_every_day_from_first_to_last_and_its_history_keeps_what_it_came_from(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);

        $job = $this->creator()->create([
            'title' => 'Acme Awards', 'client_name' => 'acme events', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-14',
            'venue' => 'Leeds Arena', 'postcode' => 'ls11 8bu', 'po_number' => ' PO-1234 ', 'note' => 'Sound No.2',
        ], 'you', [['type' => 'document', 'id' => 7, 'quote' => 'Acme Awards on 12-14 March', 'verified' => true]]);

        $this->assertSame([$acme->id, 'Leeds Arena', 'LS11 8BU', 'PO-1234', 'Sound No.2', 'booked'], [$job->client_id, $job->location, $job->postcode, $job->po_number, $job->notes, $job->status]);
        $this->assertSame([['2026-03-12', 'work'], ['2026-03-13', 'work'], ['2026-03-14', 'work']], $this->days($job));
        $this->assertSame(1, Client::query()->count(), 'The client was found whatever its capitals, not added again');

        $event = AuditEvent::query()->where('action', 'job.created')->sole();
        $this->assertSame(['you', $job->id], [$event->actor, (int) $event->subject_id]);
        $this->assertSame([['type' => 'document', 'id' => 7, 'quote' => 'Acme Awards on 12-14 March', 'verified' => true]], $event->after['evidence']);
        $this->assertCount(3, $event->after['days']);
    }

    public function test_listed_days_keep_their_kinds_without_the_days_between(): void
    {
        $job = $this->creator()->create([
            'title' => 'Expo build', 'client' => 'Acme Events Ltd',
            'dates' => [['date' => '2026-05-15', 'kind' => 'derig'], ['date' => '2026-05-10', 'kind' => 'rig'], '2026-05-11'],
        ], 'assistant');

        $this->assertSame([['2026-05-10', 'rig'], ['2026-05-11', 'work'], ['2026-05-15', 'derig']], $this->days($job));
        $this->assertSame(['2026-05-10', '2026-05-15'], [$job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d')]);
        $this->assertSame('you', $job->days()->first()->source, 'Days you listed are yours');
        $this->assertSame('assistant', AuditEvent::query()->where('action', 'job.created')->value('actor'));
        $this->assertSame('client.created', AuditEvent::query()->where('subject_type', 'Client')->value('action'));
    }

    public function test_a_name_without_ltd_finds_the_one_client_that_has_it(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events Ltd']);
        Client::query()->create(['name' => 'Smith Productions']);

        $this->assertSame($acme->id, JobCreator::client('ACME EVENTS')->id);
        $this->assertSame($acme->id, JobCreator::client('The Acme Events Limited')->id);
        $this->assertSame(2, Client::query()->count());

        Client::query()->create(['name' => 'Acme Events PLC']);
        $this->assertNotSame($acme->id, JobCreator::client('Acme Events')->id, 'Two clients could be meant, so a new one is added');
    }

    public function test_automation_can_never_make_a_job(): void
    {
        foreach (['rules', 'ai', 'matcher'] as $by) {
            try {
                $this->creator()->create(['title' => 'Acme Awards', 'starts_on' => '2026-03-12'], $by);
                $this->fail("{$by} made a job");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Only you can add a job', $e->getMessage());
            }
        }
        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_wrong_details_are_refused_with_a_plain_reason(): void
    {
        $cases = [
            [['starts_on' => '2026-03-12'], 'title'],
            [['title' => 'Gig', 'starts_on' => '2026-03-14', 'ends_on' => '2026-03-12'], 'end before it starts'],
            [['title' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2027-03-01'], 'at most 400 days'],
            [['title' => 'Gig', 'starts_on' => '2026-02-30'], 'isn’t a date'],
            [['title' => 'Gig'], 'start date'],
            [['title' => 'Gig', 'starts_on' => '2026-03-12', 'start_time' => '9am'], 'HH:MM'],
            [['title' => 'Gig', 'starts_on' => '2026-03-12', 'client_id' => 999], 'isn’t in your list'],
        ];
        foreach ($cases as [$fields, $message]) {
            try {
                $this->creator()->create($fields, 'you');
                $this->fail('Accepted '.json_encode($fields));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(0, WorkJob::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'job.created')->count());
    }

    public function test_a_fee_for_the_job_is_its_fee_and_a_day_rate_is_noted(): void
    {
        $fee = $this->creator()->create(['title' => 'Gala', 'starts_on' => '2026-03-12', 'rate' => ['amount_minor' => 120000, 'currency' => 'GBP', 'unit' => 'job']], 'you');
        $this->assertSame([120000, null], [$fee->fee_pence, $fee->notes]);

        $day = $this->creator()->create(['title' => 'Conference', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-13', 'note' => 'Bring IEMs',
            'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day']], 'you');
        $this->assertNull($day->fee_pence, 'What a day rate comes to depends on the days: not guessed');
        $this->assertSame("Bring IEMs\nRate: £350.00 a day", $day->notes);

        $euro = $this->creator()->create(['title' => 'Berlin show', 'starts_on' => '2026-03-12', 'rate' => ['amount_minor' => 50000, 'currency' => 'EUR', 'unit' => 'job']], 'you');
        $this->assertNull($euro->fee_pence, 'A fee in euros isn’t put in as pounds');
        $this->assertStringContainsString('for the job', $euro->notes);
    }

    public function test_everything_that_belongs_to_a_new_job_is_searched_for_after_it_is_saved(): void
    {
        Queue::fake([GatherForJob::class]);

        $job = $this->creator()->create(['title' => 'Acme Awards', 'starts_on' => '2026-03-12'], 'you');
        $this->creator()->create(['title' => 'Other', 'starts_on' => '2026-03-20'], 'you', [], null, false);

        Queue::assertPushed(GatherForJob::class, 1);
        Queue::assertPushed(GatherForJob::class, fn (GatherForJob $g) => $g->jobId === $job->id);
    }

    public function test_the_jobs_page_adds_jobs_through_it_and_finds_the_client_by_name(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $this->actingAs(User::factory()->create());

        $this->post('/jobs', ['title' => 'Acme Awards', 'new_client' => 'ACME EVENTS', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-13', 'status' => 'booked'])
            ->assertSessionHas('success');

        $job = WorkJob::query()->sole();
        $this->assertSame([$acme->id, 2], [$job->client_id, $job->days()->count()]);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('you', AuditEvent::query()->where('action', 'job.created')->value('actor'));
    }

    public function test_a_job_made_from_a_document_links_it_and_keeps_the_document_as_evidence(): void
    {
        $this->actingAs(User::factory()->create());
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'booking.pdf', 'sha256' => str_repeat('a', 64), 'stored_path' => 'documents/a.pdf',
            'text' => 'Booking: Acme Awards, Leeds Arena, 12 March 2026', 'text_status' => 'extracted', 'kind' => 'job_invite', 'status' => 'extracted',
            'extraction' => ['new_job' => ['title' => 'Acme Awards', 'starts_on' => '2026-03-12', 'quote' => 'Acme Awards, Leeds Arena, 12 March 2026', 'verified' => true]]]);

        $this->post("/documents/{$doc->id}/job", ['title' => 'Acme Awards', 'client' => 'Acme Events', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-12', 'location' => 'Leeds Arena'])
            ->assertRedirect();

        $job = WorkJob::query()->sole();
        $this->assertSame([$job->id, 'you'], [$doc->fresh()->work_job_id, $doc->fresh()->job_linked_by]);
        $this->assertSame('Acme Events', $job->client->name);
        $created = AuditEvent::query()->where('action', 'job.created')->sole();
        $this->assertSame([['type' => 'document', 'id' => $doc->id, 'quote' => 'Acme Awards, Leeds Arena, 12 March 2026', 'verified' => true]], $created->after['evidence']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'document.job_linked')->count());
    }
}
