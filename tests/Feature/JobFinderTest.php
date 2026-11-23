<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Verification\Verifier;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Verification;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class JobFinderTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false); // runs are started by each test
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null, bool $allDay = false): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
    }

    private function poEmail(array $newJob, ?string $po = 'PO-7781', string $body = ''): Document
    {
        $raw = "From: buyer@acme.example\r\nSubject: Purchase order {$po}\r\nDate: Mon, 3 Mar 2025 10:00:00 +0000\r\n\r\n"
            ."Hi, please find PO {$po} for the Spring Gala at Leeds Arena on 14 May 2025, LS11 8BU. Call time 07:30.\r\n{$body}";
        $doc = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => [
            'summary' => 'PO for the Spring Gala', 'new_job' => $newJob, 'po' => $po ? ['po_number' => $po, 'lines' => []] : null, 'unverified_count' => 0,
        ]]);

        return $doc->fresh();
    }

    private function finder(): JobFinder
    {
        return app(JobFinder::class);
    }

    public function test_calendar_entries_that_clearly_belong_to_a_job_are_linked_and_personal_ones_are_not(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Spring Gala', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $work = $this->event('Acme load in', '2025-05-14 07:00:00', '2025-05-14 19:00:00', 'Leeds Arena');
        $dentist = $this->event('Dentist', '2025-05-14 08:30:00', '2025-05-14 09:00:00');
        $mine = $this->event('Acme rehearsal', '2025-05-14 15:00:00', '2025-05-14 17:00:00');
        app(JobFinder::class)->markNotJob($mine);

        $result = $this->finder()->run('2025-26', false);

        $this->assertSame(1, $result['linked']);
        $link = CalendarJobLink::query()->where('event_key', $work->key())->sole();
        $this->assertSame([$job->id, 'rules'], [$link->work_job_id, $link->linked_by]);
        $this->assertStringContainsString('only job then', $link->reason);
        $this->assertNull(CalendarJobLink::query()->where('event_key', $dentist->key())->first(), 'A dentist appointment on a job day stays unlinked');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $mine->key())->value('work_job_id'), 'Your "not a job" is never changed');

        // Undoing an automatic link makes it yours: it isn't linked again.
        $this->actingAs(User::factory()->create())->post('/jobs/finder/links', ['type' => 'calendar', 'id' => $link->id, 'action' => 'unlink'])->assertSessionHas('success');
        $this->finder()->run('2025-26', false);
        $this->assertNull($link->fresh()->work_job_id);
        $this->assertSame('you', $link->fresh()->linked_by);
    }

    public function test_a_po_email_calendar_entry_and_stay_become_one_possible_job_and_accepting_links_them_all(): void
    {
        $doc = $this->poEmail(['title' => 'Spring Gala', 'client' => 'Acme Events', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14',
            'venue' => 'Leeds Arena', 'postcode' => 'LS11 8BU', 'quote' => 'Spring Gala at Leeds Arena on 14 May 2025', 'verified' => true]);
        $event = $this->event('Gala show day', '2025-05-14 00:00:00', '2025-05-15 00:00:00', 'Leeds Arena', true);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-05-13', 'ends_on' => '2025-05-15',
            'place' => 'Premier Inn Leeds City', 'postcode' => 'LS1 4HX', 'source' => 'manual', 'evidence' => []]);
        $invoice = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0, 'po_number' => 'po 7781']);
        $this->event('Dentist', '2025-06-20 09:00:00', '2025-06-20 09:30:00');

        $result = $this->finder()->run('2025-26', false);

        $this->assertSame(1, $result['proposed'], 'One possible job, not three, and nothing for the dentist');
        $candidate = JobCandidate::query()->sole();
        $this->assertSame(['Spring Gala', '2025-05-14', '2025-05-14', 'PO-7781', 'LS11 8BU'], [$candidate->title, $candidate->starts_on->format('Y-m-d'), $candidate->ends_on->format('Y-m-d'), $candidate->po_number, $candidate->postcode]);
        $this->assertEqualsCanonicalizing(['doc:'.$doc->id, 'cal:'.$event->key(), 'travel:'.$stay->id], $candidate->sourceKeys());

        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/accept", [
            'title' => 'Spring Gala', 'new_client' => 'Acme Events', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781', 'postcode' => 'ls11 8bu',
        ])->assertRedirect();

        $job = WorkJob::query()->sole();
        $this->assertSame(['accepted', $job->id], [$candidate->fresh()->status, $candidate->fresh()->work_job_id]);
        $this->assertSame([$job->id, 'you'], [$doc->fresh()->work_job_id, $doc->fresh()->job_linked_by]);
        $this->assertSame($job->id, CalendarJobLink::query()->where('event_key', $event->key())->value('work_job_id'));
        $this->assertSame([$job->id, 'you'], [$stay->fresh()->work_job_id, $stay->fresh()->job_linked_by]);
        $this->assertSame([$job->id, 2, 2], [$stay->fresh()->suggestion['job_id'], $stay->fresh()->suggestion['business_nights'], $stay->fresh()->suggestion['nights']], 'The stay is lined up with the new job: both nights are for it');
        $this->assertSame($job->id, $invoice->fresh()->work_job_id, 'An invoice quoting the same PO number is linked');
        $this->assertSame('LS11 8BU', $job->postcode);

        // Nothing is proposed again for the same sources.
        $this->finder()->run('2025-26', false);
        $this->assertSame(0, JobCandidate::query()->where('status', 'open')->count());

        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn ($p) => $p->where('calendar.0.linked', true)->where('travel.0.linked', true));
    }

    public function test_a_dismissed_possible_job_isnt_proposed_again_and_merging_fills_only_blanks(): void
    {
        $this->event('Install: Expo build', '2025-09-02 08:00:00', '2025-09-02 18:00:00', 'NEC Birmingham');
        $this->finder()->run('2025-26', false);
        $this->finder()->dismiss(JobCandidate::query()->sole(), 'Cancelled before it happened');
        $this->finder()->run('2025-26', false);
        $this->assertSame(0, JobCandidate::query()->where('status', 'open')->count());

        $job = WorkJob::query()->create(['title' => 'Autumn conference', 'starts_on' => '2025-10-10', 'ends_on' => '2025-10-11', 'location' => 'ICC']);
        $doc = $this->poEmail(['title' => 'Conference crew', 'client' => null, 'starts_on' => '2025-10-20', 'ends_on' => '2025-10-20', 'venue' => 'ExCeL', 'postcode' => 'E16 1XL', 'quote' => 'Spring Gala', 'verified' => false], 'PO-9001');
        $this->finder()->run('2025-26', false);
        $candidate = JobCandidate::query()->where('status', 'open')->sole();
        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/merge", ['work_job_id' => $job->id])->assertSessionHas('success');
        $job->refresh();
        $this->assertSame(['ICC', 'E16 1XL', 'PO-9001'], [$job->location, $job->postcode, $job->po_number], 'Only blank details are filled in');
        $this->assertSame($job->id, $doc->fresh()->work_job_id);
    }

    public function test_a_document_with_the_jobs_po_number_is_linked_even_without_dates(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena tour', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'po_number' => 'PO-7781']);
        $doc = $this->poEmail([], 'PO-7781');
        $doc->update(['extraction' => array_merge($doc->extraction, ['new_job' => null])]);

        $this->assertSame(1, $this->finder()->run(null, false)['linked']);
        $this->assertSame([$job->id, 'rules'], [$doc->fresh()->work_job_id, $doc->fresh()->job_linked_by]);
    }

    public function test_ai_answers_about_calendar_entries_only_count_when_their_quote_is_in_the_entry(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        Client::query()->create(['name' => 'Northern Lights']);
        $a = $this->event('Maria @ Unit 3', '2025-08-04 09:00:00', '2025-08-04 11:00:00', 'Salford');
        $b = $this->event('Catch up with Sam', '2025-08-05 09:00:00', '2025-08-05 10:00:00', 'Media City');
        $this->ai->responses = [FakeAiClient::json(['entries' => [
            // Asked newest first: the 5 August entry, then the 4 August one.
            ['i' => 0, 'work' => true, 'quote' => 'camera rehearsal', 'client' => 'Someone Else'], // not in the entry: rejected
            ['i' => 1, 'work' => true, 'quote' => 'Unit 3', 'client' => 'Northern Lights'],
        ]])];

        $result = $this->finder()->run('2025-26');

        $this->assertSame([2, 1], [$result['ai_checked'], $result['ai_rejected']]);
        $candidate = JobCandidate::query()->sole();
        $this->assertSame(['cal:'.$a->key()], $candidate->sourceKeys());
        $this->assertSame('ai', $candidate->found_by);
        $this->assertNull($candidate->client_name, 'A client the entry doesn’t name is dropped');
        $this->assertStringNotContainsString('Catch up', json_encode($candidate->sources));

        // Answers are remembered, so a second run doesn't ask again.
        $this->finder()->run('2025-26');
        $this->assertCount(1, $this->ai->requests);
    }

    public function test_an_ai_job_link_is_only_used_when_the_document_backs_it(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $right = WorkJob::query()->create(['title' => 'Spring Gala', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $wrong = WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-01']);
        $reader = app(DocumentReader::class);
        $make = fn (string $raw) => app(DocumentIntake::class)->store($raw, 'm.eml', 'message/rfc822', 'upload', ['received_at' => '2025-03-01']);
        $data = fn (int $job) => ['kind' => 'job_invite', 'summary' => 'x', 'job_link' => ['job_id' => $job, 'reason' => 'Looks like it'], 'new_job' => null, 'facts' => [], 'po' => null, 'receipt' => null];

        $doc = $make("From: a@acme.example\r\nSubject: Crew\r\n\r\nAcme crew needed for the gala on 14 May 2025.\r\n");
        $doc = $reader->save($doc, $data($wrong->id), [['id' => $right->id], ['id' => $wrong->id]], 'claude-opus-5-5');
        $this->assertNull($doc->work_job_id, 'Nothing in it points to the Winter Ball');
        $this->assertSame($wrong->id, $doc->extraction['job_link_hint']['job_id']);

        $doc2 = $make("From: a@acme.example\r\nSubject: Crew 2\r\n\r\nAcme Events crew needed on 14 May 2025.\r\n");
        $doc2 = $reader->save($doc2, $data($right->id), [['id' => $right->id], ['id' => $wrong->id]], 'claude-opus-5-5');
        $this->assertSame([$right->id, 'ai'], [$doc2->work_job_id, $doc2->job_linked_by]);
    }

    public function test_document_findings_are_checked_again_against_the_file_itself(): void
    {
        $doc = $this->poEmail(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'quote' => 'Spring Gala at Leeds Arena on 14 May 2025', 'verified' => true]);
        $good = $doc->facts()->create(['field' => 'call_time', 'label' => 'Call time', 'value' => '07:30', 'quote' => 'Call time 07:30', 'verified' => true]);
        $bad = $doc->facts()->create(['field' => 'start_date', 'label' => 'Start date', 'value' => '15 May', 'on_date' => '2025-05-15', 'quote' => 'on 14 May 2025', 'verified' => true]);
        $doc->update(['text' => 'Something else entirely']); // the kept copy no longer matches the file

        $v = app(Verifier::class)->document($doc->fresh());

        $this->assertSame('partly', $v->status);
        $this->assertTrue($good->fresh()->verified);
        $this->assertFalse($bad->fresh()->verified, 'The quote is there, but it gives a different date');
        $this->assertStringContainsString('Spring Gala', $doc->fresh()->text, 'The text was taken from the file again');
        $this->assertTrue($doc->fresh()->extraction['new_job']['verified']);

        Storage::disk('local')->put($doc->stored_path, 'tampered');
        $this->assertSame('mismatch', app(Verifier::class)->document($doc->fresh())->status);
    }

    public function test_scans_get_a_second_independent_read(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $doc = app(DocumentIntake::class)->store(str_repeat($png, 1), 'receipt.png', 'image/png', 'upload');
        $doc->update(['status' => 'extracted', 'extraction' => ['receipt' => ['amount_minor' => 1250, 'currency' => 'GBP', 'date' => '2025-05-14']]]);
        $f = $doc->facts()->create(['field' => 'venue', 'label' => 'Venue', 'value' => 'Leeds Arena', 'quote' => 'Leeds Arena', 'verified' => false]);
        $this->ai->responses = [FakeAiClient::json(['claims' => [
            ['id' => 'fact'.$f->id, 'verdict' => 'yes', 'document_says' => 'Leeds Arena'],
            ['id' => 'receipt', 'verdict' => 'no', 'document_says' => '12.80'],
        ]])];

        $this->assertSame('unverifiable', app(Verifier::class)->document($doc->fresh(), false)->status, 'No AI is spent unless asked');
        $v = app(Verifier::class)->document($doc->fresh(), true);

        $this->assertSame('second_read_disagreed', $v->status);
        $this->assertStringContainsString('12.80', json_encode($v->checks));
        $this->assertFalse($f->fresh()->verified, 'A second read never makes a scan "verified"');
        $this->assertSame('image', $this->ai->requests[0]['messages'][0]['content'][0]['type']);
    }

    public function test_a_booking_is_checked_again_in_gmail(): void
    {
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        $raw = "From: Premier Inn <stay@premierinn.com>\r\nSubject: Booking confirmed\r\n\r\nCheck-in: 13 May 2025\r\nCheck-out: 15 May 2025\r\nTotal paid: GBP 178.00\r\nReference: PI12345\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'b.eml', 'message/rfc822', 'gmail');
        GmailMessage::query()->create(['message_id' => 'm1', 'search' => 'hotels', 'document_id' => $doc->id, 'status' => 'read']);
        $item = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'PI12345', 'status' => 'booked', 'starts_on' => '2025-05-13', 'ends_on' => '2025-05-15',
            'amount_minor' => 17800, 'source' => 'ai', 'document_id' => $doc->id, 'evidence' => [
                'starts_on' => ['quote' => 'Check-in: 13 May 2025', 'verified' => true], 'ends_on' => ['quote' => 'Check-out: 15 May 2025', 'verified' => true],
                'amount' => ['quote' => 'Total paid: GBP 178.00', 'verified' => true], 'reference' => ['quote' => 'Reference: PI12345', 'verified' => true],
            ]]);
        $found = true;
        Http::fake(['gmail.googleapis.com/*' => function () use (&$found, $raw) {
            return $found ? Http::response(['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 'threadId' => 't'])
                : Http::response(['error' => ['message' => 'Requested entity was not found.']], 404);
        }]);

        $v = app(Verifier::class)->travelItem($item);
        $this->assertSame(['verified', 'gmail_refetch'], [$v->status, $v->method]);
        $this->assertStringContainsString('unchanged', json_encode($v->checks));

        // The amount on the stay is changed to one the email doesn't say: caught.
        $item->update(['amount_minor' => 18700]);
        $this->assertSame('partly', app(Verifier::class)->travelItem($item->fresh())->status);
        $this->assertSame(1, $item->fresh()->unverified_count);

        $found = false;
        $item->update(['amount_minor' => 17800]);
        $v = app(Verifier::class)->travelItem($item->fresh());
        $this->assertSame('partly', $v->status);
        $this->assertStringContainsString('No longer in Gmail', json_encode($v->checks));
    }

    public function test_ai_suggestions_on_payments_are_checked_against_the_payment_and_job(): void
    {
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $tx = BankTransaction::query()->where('amount_pence', '<', 0)->whereDoesntHave('allocations')->first();
        $far = $tx->posted_on->copy()->addMonths(3)->format('Y-m-d');
        $job = WorkJob::query()->create(['title' => 'Later show', 'starts_on' => $far, 'ends_on' => $far]);
        $cat = Category::query()->where('code', 'travel_rail')->value('id');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $cat, $job->id), 'ai', false, 'AI: train for the show');

        $v = app(Verifier::class)->transaction($tx);
        $this->assertSame('partly', $v->status);
        $this->assertStringContainsString('doesn’t fit the job', json_encode($v->checks, JSON_UNESCAPED_UNICODE));

        $this->actingAs(User::factory()->create())->post("/checks/transaction/{$tx->id}")->assertSessionHas('error');
        $this->assertNotNull(Verification::for('transaction', $tx->id));
    }

    public function test_the_finder_page_and_scheduled_commands_work(): void
    {
        $this->event('Festival build', '2025-07-10 08:00:00', '2025-07-10 20:00:00', 'Knebworth Park');
        $this->actingAs(User::factory()->create());
        $this->post('/jobs/finder/run', ['year' => '2025-26'])->assertSessionHas('success');
        $this->get('/jobs/finder')->assertOk()->assertInertia(fn ($p) => $p->has('candidates', 1)->where('candidates.0.check.status', 'verified'));

        $this->artisan('ledger:find-jobs', ['--no-ai' => true])->assertSuccessful();
        $this->artisan('ledger:recheck', ['--no-gmail' => true])->assertSuccessful();
        $this->get('/jobs')->assertInertia(fn ($p) => $p->where('counts.possible', 1));
    }

    public function test_the_connections_map_shows_links_and_flags_calendar_jobs_with_no_po(): void
    {
        $this->actingAs(User::factory()->create());
        $job = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $linked = $this->event('Gala show day', '2025-05-14 08:00:00', '2025-05-14 20:00:00', 'Leeds Arena');
        app(JobFinder::class)->linkCalendar($linked, $job, 'you');
        $unset = $this->event('Load in: Winter Expo', '2025-06-02 07:00:00', '2025-06-02 19:00:00', 'NEC Birmingham');
        $this->event('Dentist', '2025-06-03 09:00:00', '2025-06-03 09:30:00');

        $this->get('/connections?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p
            ->where('edges.0', ['from' => 'cal:'.$linked->key(), 'to' => 'job:'.$job->id, 'kind' => 'confirmed', 'label' => null])
            ->where('attention', fn ($a) => collect($a)->pluck('code')->sort()->values()->all() === ['calendar_unallocated', 'no_po', 'not_invoiced'])
            ->where('nodes', fn ($n) => ! collect($n)->contains('label', 'Dentist')));

        // "This is a job": a job is made from the entry and linked to it; it's flagged until a PO is found.
        $this->post('/calendar/job', ['key' => $unset->key(), 'date' => '2025-06-02', 'title' => 'Winter Expo', 'starts_on' => '2025-06-02', 'ends_on' => '2025-06-02', 'location' => 'NEC Birmingham'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'no PO yet'));
        $expo = WorkJob::query()->where('title', 'Winter Expo')->sole();
        $this->assertSame([$expo->id, 'you'], [CalendarJobLink::query()->where('event_key', $unset->key())->value('work_job_id'), CalendarJobLink::query()->where('event_key', $unset->key())->value('linked_by')]);
        $this->get('/connections?year=2025-26')->assertInertia(fn ($p) => $p->where('attention', fn ($a) => collect($a)->firstWhere('code', 'no_po')['count'] === 2 && ! collect($a)->contains('code', 'calendar_unallocated')));

        // "Not a job" takes an entry off the list.
        $other = $this->event('Rehearsal tbc', '2025-07-01 10:00:00', '2025-07-01 16:00:00', 'Somewhere');
        $this->post('/calendar/not-job', ['key' => $other->key(), 'date' => '2025-07-01'])->assertSessionHas('success');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $other->key())->value('work_job_id'));
        $this->get('/connections?job='.$expo->id)->assertOk();
    }

    public function test_review_fixes_keep_your_decisions_and_need_evidence(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $gala = WorkJob::query()->create(['title' => 'Spring Gala', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $other = WorkJob::query()->create(['title' => 'Other show', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15']);

        // A re-read never undoes your unlink.
        $doc = app(DocumentIntake::class)->store("From: a@acme.example\r\nSubject: Crew\r\n\r\nAcme Events crew for the Spring Gala on 14 May 2025.\r\n", 'c.eml', 'message/rfc822', 'upload', ['work_job_id' => $gala->id]);
        app(JobFinder::class)->linkDocument($doc, null, 'you');
        $this->assertSame([null, [$gala->id]], [$doc->fresh()->work_job_id, $doc->fresh()->job_rejected]);
        $doc = app(DocumentReader::class)->save($doc->fresh(), ['kind' => 'job_invite', 'summary' => 'x', 'job_link' => ['job_id' => $gala->id, 'reason' => 'r'], 'new_job' => null, 'facts' => [], 'po' => null, 'receipt' => null],
            [['id' => $gala->id]], 'm');
        $this->assertNull($doc->work_job_id);

        // The finder doesn't link a document on what the AI read alone: the document has to back it.
        $thin = app(DocumentIntake::class)->store("From: a@acme.example\r\nSubject: Hello\r\n\r\nHi, details to follow.\r\n", 't.eml', 'message/rfc822', 'upload');
        $thin->update(['status' => 'extracted', 'kind' => 'job_invite', 'extraction' => ['new_job' => ['title' => 'Spring Gala', 'client' => 'Acme Events Ltd', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'verified' => false]]]);
        $this->finder()->run('2025-26', false);
        $this->assertNull($thin->fresh()->work_job_id);

        // An untitled group doesn't stop the run; calendar times are UK time.
        $this->event('', '2025-08-01 06:30:00', '2025-08-01 18:00:00', 'NEC Hall 5, B40 1NT');
        $po = app(DocumentIntake::class)->store("From: x@y.example\r\nSubject: PO\r\n\r\nPO 99881 for 9 September 2025\r\n", 'p.eml', 'message/rfc822', 'upload');
        $po->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['po' => ['po_number' => '99881', 'lines' => []]]]);
        $po->facts()->create(['field' => 'start_date', 'label' => 'Date', 'value' => '9 Sep', 'on_date' => '2025-09-09', 'quote' => '9 September 2025', 'verified' => true]);
        $this->event('Load in: Autumn expo', '2025-09-08 06:30:00', '2025-09-08 18:00:00', 'ExCeL London');
        $this->finder()->run('2025-26', false);
        $untitled = JobCandidate::query()->where('title', 'like', 'Possible job%')->first();
        $this->assertNotNull($untitled);
        $expo = JobCandidate::query()->where('title', 'Load in: Autumn expo')->sole();
        $this->assertSame('07:30', $expo->start_time, '06:30 UTC is 07:30 in a UK summer');

        // Ids are stable between runs, and a run for another year doesn't remove them.
        $id = $expo->id;
        $this->finder()->run('2024-25', false);
        $this->finder()->run('2025-26', false);
        $this->assertSame($id, JobCandidate::query()->where('title', 'Load in: Autumn expo')->value('id'));

        // A moved one-off entry keeps its key, so "not a job" holds.
        $dismissed = $this->event('Tech day maybe', '2025-10-01 09:00:00', '2025-10-01 17:00:00', 'Studio 2');
        app(JobFinder::class)->markNotJob($dismissed);
        $dismissed->update(['starts_at' => '2025-10-01 09:30:00']);
        $this->finder()->run('2025-26', false);
        $this->assertFalse(collect(JobCandidate::query()->pluck('sources')->flatten(1))->contains('key', 'cal:'.$dismissed->key()));

        // "Not this job": unlinked from one job, it can still go to another, never back.
        $entry = $this->event('Acme crew call', '2025-05-14 08:00:00', '2025-05-14 20:00:00', 'Leeds');
        app(JobFinder::class)->linkCalendar($entry, $gala, 'rules');
        $this->actingAs(User::factory()->create())->post("/jobs/{$gala->id}/links", ['type' => 'calendar', 'key' => $entry->key(), 'date' => '2025-05-14', 'action' => 'unlink']);
        $link = CalendarJobLink::query()->where('event_key', $entry->key())->sole();
        $this->assertSame([null, false, [$gala->id]], [$link->work_job_id, $link->not_job, $link->rejected]);
        app(JobFinder::class)->linkCalendar($entry, $gala, 'rules');
        $this->assertNull($link->fresh()->work_job_id, 'Never back to the job you unlinked it from');
        app(JobFinder::class)->linkCalendar($entry, $other, 'rules');
        $this->assertSame($other->id, $link->fresh()->work_job_id, 'but another job is still possible');
    }

    public function test_po_numbers_only_count_as_whole_references(): void
    {
        $this->assertTrue(Verifier::poInText('Purchase order PO-4500123 attached', '4500123'));
        $this->assertFalse(Verifier::poInText('Call 0161 450 0123', '4500123'));
        $this->assertFalse(Verifier::poInText('Total £1,512.00', '512'));
        $this->assertTrue(Verifier::poInText('ref PO 7781.', 'PO7781'));
        $job = WorkJob::query()->create(['title' => 'Show', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => '4500123']);
        $this->assertFalse(Verifier::jobEvidence('Call 0161 450 0123', $job, '4500123')['ok'], 'An AI-read PO number only counts if it is in the text');
    }

    public function test_your_link_on_a_stay_is_the_job_its_payment_is_split_to(): void
    {
        $a = WorkJob::query()->create(['title' => 'Job A', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        WorkJob::query()->create(['title' => 'Job B', 'starts_on' => '2025-05-15', 'ends_on' => '2025-05-15']);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'source' => 'manual', 'evidence' => []]);
        app(JobFinder::class)->linkTravel($stay, $a, 'you');
        $this->assertSame($a->id, $stay->fresh()->suggestion['job_id']);
    }
}
