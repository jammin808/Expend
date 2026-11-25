<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\Opportunities;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/** The opportunities pages, the "No PO yet" panel, the to-do items and the assistant's job tools. */
class OpportunitySurfacesTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Hi Sam,\nAre you free 12-14 March for the Acme Awards at Leeds Arena, LS11 8BU? Rig on the first day.\n"
        ."Day rate £350 per day. Please let me know by 2 March 2026.\nThanks, Alex\nAcme Events";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-02-26 09:00:00'));
        Setting::put('business_email', 'sam@example.test');
        $this->actingAs(User::factory()->create(['name' => 'Sam Taylor', 'email' => 'sam@example.test']));
    }

    private function opportunities(): Opportunities
    {
        return app(Opportunities::class);
    }

    /** An availability check read from an email, with every detail quoted from it. */
    private function fromEmail(): array
    {
        $from = 'Alex Smith <alex@acme-events.example>';
        $text = "From: {$from}\nTo: sam@example.test\nSubject: Acme Awards crew 12-14 March\n\n".self::BODY;
        $q = fn (string $value, string $quote) => ['value' => $value, 'quote' => $quote, 'verified' => true];
        $doc = Document::query()->create([
            'source' => 'gmail', 'original_name' => 'Acme Awards crew.eml', 'mime' => 'message/rfc822', 'size' => strlen($text),
            'sha256' => hash('sha256', Str::random(40)), 'stored_path' => 'documents/test.eml', 'email_from' => $from,
            'email_subject' => 'Acme Awards crew 12-14 March', 'received_at' => '2026-02-20 10:00:00', 'text' => $text, 'text_status' => 'extracted',
            'kind' => 'email', 'status' => 'extracted', 'extracted_at' => now(),
            'extraction' => ['summary' => 'Work', 'opportunity' => [
                'status_hint' => $q('enquiry', 'Are you free 12-14 March'),
                'title' => $q('Acme Awards', 'the Acme Awards at Leeds Arena'),
                'client' => $q('Acme Events', 'Acme Events'),
                'contact' => ['name' => 'Alex Smith', 'email' => 'alex@acme-events.example', 'quote' => 'From: Alex Smith <alex@acme-events.example>', 'verified' => true],
                'venue' => $q('Leeds Arena', 'Leeds Arena, LS11 8BU'),
                'postcode' => $q('LS11 8BU', 'Leeds Arena, LS11 8BU'),
                'dates' => [
                    ['date' => '2026-03-12', 'kind' => 'rig', 'quote' => 'Are you free 12-14 March', 'verified' => true],
                    ['date' => '2026-03-13', 'kind' => 'work', 'quote' => 'Are you free 12-14 March', 'verified' => true],
                    ['date' => '2026-03-14', 'kind' => 'work', 'quote' => 'Are you free 12-14 March', 'verified' => true],
                ],
                'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'Day rate £350 per day', 'verified' => true],
                'reply_by' => $q('2026-03-02', 'Please let me know by 2 March 2026'),
                // Words that aren't in the email: kept, but marked as not found.
                'role' => $q('Sound No.2', 'we need a sound No.2'),
            ]],
        ]);

        return [$this->opportunities()->fromDocument($doc), $doc];
    }

    /** Work you told the app about. */
    private function told(array $over = []): Opportunity
    {
        return $this->opportunities()->record($over + [
            'title' => 'Acme Awards', 'client_name' => 'Acme Events', 'contact_name' => 'Alex Smith', 'contact_email' => 'alex@acme-events.example',
            'venue' => 'Leeds Arena', 'status' => 'offered', 'reply_by' => '2026-03-02',
            'dates' => [['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-13', 'kind' => 'work'], ['date' => '2026-03-14', 'kind' => 'work']],
            'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day'],
        ], 'manual', 'you');
    }

    private function item(string $key): ?array
    {
        TodoList::forget();

        return collect(TodoList::build(50)['items'])->firstWhere('key', $key);
    }

    // ---------------------------------------------------------------- pages

    public function test_the_pages_list_opportunities_upcoming_first_and_show_every_quote_linked_to_its_email(): void
    {
        [$o, $doc] = $this->fromEmail();
        $later = $this->told(['title' => 'Smith Productions launch', 'client_name' => 'Smith Productions', 'status' => 'held', 'reply_by' => null,
            'dates' => [['date' => '2026-04-20', 'kind' => 'work']]]);
        $undated = $this->told(['title' => 'Summer tour', 'client_name' => 'Smith Productions', 'status' => 'enquiry', 'reply_by' => null, 'dates' => []]);

        $this->get('/opportunities')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('opportunities/index', false)
            ->where('opportunities', fn ($rows) => collect($rows)->pluck('id')->all() === [$o->id, $later->id, $undated->id])
            ->where('opportunities.0.days.0', ['date' => '2026-03-12', 'kind' => 'rig'])
            ->where('opportunities.0.rate.text', '£350.00 a day')
            ->where('opportunities.0.unverified', ['role'])
            ->where('counts.open', 3)->where('counts.reply', 0)->where('counts.held', 1)->where('counts.closed', 0)
            ->where('filters.show', 'open'));

        $this->get('/opportunities/'.$o->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('opportunities/show', false)
            ->where('opportunity.evidence', fn ($evidence) => collect($evidence)->every(fn ($e) => $e['href'] === '/documents/'.$doc->id)
                && collect($evidence)->firstWhere('field', 'rate')['quote'] === 'Day rate £350 per day'
                && collect($evidence)->firstWhere('field', 'rate')['verified'] === true
                && collect($evidence)->firstWhere('field', 'role')['verified'] === false)
            ->where('documents.0.id', $doc->id)
            ->where('history.0.action', 'opportunity.found')
            ->where('prefill.days', [['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-13', 'kind' => 'work'], ['date' => '2026-03-14', 'kind' => 'work']])
            ->where('prefill.client_name', 'Acme Events')->where('prefill.rate_amount', '350.00')->where('prefill.rate_unit', 'day')
            ->where('prefill.venue', 'Leeds Arena')->where('prefill.status', 'booked')
            ->where('prefill.note', "Role: Sound No.2\nContact: Alex Smith <alex@acme-events.example>"));

        // Three days before the date to reply by, it's one to answer.
        $this->travelTo(CarbonImmutable::parse('2026-02-27 09:00:00'));
        $this->get('/opportunities?show=reply')->assertInertia(fn (AssertableInertia $p) => $p->where('counts.reply', 1)->has('opportunities', 1)
            ->where('opportunities.0.id', $o->id)->where('opportunities.0.reply_in', 3));
        foreach (['index', 'show'] as $page) {
            $this->assertFileExists(resource_path("js/pages/opportunities/{$page}.tsx"));
        }
        $this->get('/opportunities?show=nonsense')->assertInertia(fn (AssertableInertia $p) => $p->where('filters.show', 'open'));
        $this->get('/jobs')->assertOk();
        $this->assertSame(0, WorkJob::query()->count(), 'Looking never makes a job');
    }

    public function test_accepting_makes_the_job_with_its_days_and_links_the_email(): void
    {
        [$o, $doc] = $this->fromEmail();

        // You checked the form: the rig day and a de-rig two days later, without the day between.
        $response = $this->post('/opportunities/'.$o->id.'/accept', [
            'title' => 'Acme Awards', 'client_name' => 'Acme Events', 'venue' => 'Leeds Arena', 'postcode' => 'ls11 8bu', 'po_number' => '',
            'days' => [['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-14', 'kind' => 'derig']],
            'rate_amount' => '350.00', 'rate_currency' => 'GBP', 'rate_unit' => 'day', 'note' => 'Role: Sound No.2', 'status' => 'booked',
        ]);

        $job = WorkJob::query()->with('client')->sole();
        $response->assertRedirect('/jobs/'.$job->id)->assertSessionHas('success');
        $this->assertSame(['Acme Awards', 'Acme Events', '2026-03-12', '2026-03-14', 'Leeds Arena', 'LS11 8BU'],
            [$job->title, $job->client->name, $job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d'), $job->location, $job->postcode]);
        $this->assertSame([['2026-03-12', 'rig'], ['2026-03-14', 'derig']], $job->days()->get()->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->kind])->all());
        $this->assertSame([35000, 'day', 2], [$job->rate_pence, $job->rate_unit, $job->days_agreed], 'A day rate in pounds is the job’s rate (S4)');
        $this->assertStringNotContainsString('Rate:', (string) $job->notes);
        $this->assertSame($job->id, $doc->fresh()->work_job_id, 'its email is linked to the job');

        $o->refresh();
        $this->assertSame(['confirmed', $job->id, 'you'], [$o->status, $o->work_job_id, $o->decided_by]);
        $this->assertSame('you', AuditEvent::query()->where('action', 'job.created')->sole()->actor);
        $this->assertSame('you', AuditEvent::query()->where('action', 'opportunity.accepted')->sole()->actor);

        // Twice is refused, and the page shows it's a job.
        $this->post('/opportunities/'.$o->id.'/accept', ['title' => 'Again', 'days' => [['date' => '2026-03-12', 'kind' => 'work']]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already a job'));
        $this->assertSame(1, WorkJob::query()->count());
        $this->get('/opportunities/'.$o->id)->assertInertia(fn (AssertableInertia $p) => $p->where('opportunity.accepted', true)->where('opportunity.job.id', $job->id));
        $this->get('/opportunities?show=jobs')->assertInertia(fn (AssertableInertia $p) => $p->has('opportunities', 1)->where('counts.open', 0));

        // A form with no days is refused before anything is made.
        $other = $this->told(['title' => 'Other show']);
        $this->post('/opportunities/'.$other->id.'/accept', ['title' => 'Other show', 'days' => []])->assertSessionHasErrors('days');
    }

    public function test_declining_dismissing_reopening_and_editing_are_yours_and_recorded(): void
    {
        [$o] = $this->fromEmail();

        $this->post('/opportunities/'.$o->id.'/decline', ['note' => 'Booked elsewhere that week'])->assertSessionHas('success');
        $o->refresh();
        $this->assertSame(['declined', 'Booked elsewhere that week', 'you'], [$o->status, $o->note, $o->decided_by]);
        $this->get('/opportunities?show=closed')->assertInertia(fn (AssertableInertia $p) => $p->has('opportunities', 1));
        $this->patch('/opportunities/'.$o->id, ['title' => 'Changed', 'status' => 'held'])->assertSessionHas('error');

        $this->post('/opportunities/'.$o->id.'/reopen')->assertSessionHas('success');
        $this->assertSame('enquiry', $o->fresh()->status);

        // Edit: a 1st option hold, on a different venue and only the show days. Only what changed is recorded.
        $this->patch('/opportunities/'.$o->id, [
            'title' => 'Acme Awards', 'status' => 'held', 'hold_rank' => 1, 'client_name' => 'Acme Events', 'contact_name' => 'Alex Smith',
            'contact_email' => 'Alex@Acme-Events.example', 'role' => 'Sound No.2', 'venue' => 'First Direct Arena', 'postcode' => 'LS11 8BU',
            'days' => [['date' => '2026-03-13', 'kind' => 'work'], ['date' => '2026-03-14', 'kind' => 'work']],
            'reply_by' => '2026-03-02', 'rate_amount' => '350', 'rate_currency' => 'GBP', 'rate_unit' => 'day', 'note' => 'Booked elsewhere that week',
        ])->assertSessionHas('success', 'Saved.');
        $o->refresh();
        $this->assertSame(['held', 1, 'First Direct Arena', '2026-03-13', '2026-03-14', 'alex@acme-events.example'],
            [$o->status, $o->hold_rank, $o->venue, $o->starts_on->format('Y-m-d'), $o->ends_on->format('Y-m-d'), $o->contact_email]);
        $edit = AuditEvent::query()->where('action', 'opportunity.edited')->sole();
        $this->assertSame('you', $edit->actor);
        $this->assertEqualsCanonicalizing(['status', 'hold_rank', 'venue', 'dates', 'starts_on'], array_keys($edit->after));
        $this->assertSame('Leeds Arena', $edit->before['venue']);
        $this->get('/opportunities/'.$o->id)->assertInertia(fn (AssertableInertia $p) => $p->where('opportunity.edited', fn ($f) => collect($f)->sort()->values()->all() === ['dates', 'hold_rank', 'status', 'venue']));

        // The same again changes nothing.
        $this->patch('/opportunities/'.$o->id, [
            'title' => 'Acme Awards', 'status' => 'held', 'hold_rank' => 1, 'client_name' => 'Acme Events', 'contact_name' => 'Alex Smith',
            'contact_email' => 'alex@acme-events.example', 'role' => 'Sound No.2', 'venue' => 'First Direct Arena', 'postcode' => 'LS11 8BU',
            'days' => [['date' => '2026-03-13', 'kind' => 'work'], ['date' => '2026-03-14', 'kind' => 'work']],
            'reply_by' => '2026-03-02', 'rate_amount' => '350.00', 'rate_currency' => 'GBP', 'rate_unit' => 'day', 'note' => 'Booked elsewhere that week',
        ])->assertSessionHas('success', 'Nothing changed.');

        $this->post('/opportunities/'.$o->id.'/dismiss', ['note' => 'Not for me'])->assertSessionHas('success');
        $this->assertSame('dismissed', $o->fresh()->status);
        $this->assertSame(0, WorkJob::query()->count());

        // Work you were told about by phone.
        $phone = ['title' => 'Smith Productions launch', 'client_name' => 'Smith Productions', 'status' => 'enquiry', 'days' => [['date' => '2026-04-20', 'kind' => 'work']]];
        $this->post('/opportunities', $phone)->assertRedirect()->assertSessionHas('success', 'Added. Nothing is sent to anyone.');
        $this->post('/opportunities', $phone)->assertSessionHas('success', 'You’d already added this one.');
        $mine = Opportunity::query()->where('title', 'Smith Productions launch')->sole();
        $this->assertSame(['you', 'manual', '2026-04-20'], [$mine->found_by, $mine->source, $mine->starts_on->format('Y-m-d')]);
    }

    // ---------------------------------------------------------------- No PO yet

    public function test_jobs_with_no_po_come_with_who_to_ask_and_a_request_ready_to_send(): void
    {
        $job = $this->opportunities()->accept($this->told(), []);
        $later = app(JobCreator::class)->create(['title' => 'Autumn tour', 'client_name' => 'Smith Productions', 'starts_on' => '2026-09-01'], 'you');

        $items = $this->getJson('/opportunities/po-chase')->assertOk()->json('items');
        $this->assertSame([$job->id], array_column($items, 'job_id'), 'only work in the next 60 days');
        $item = $items[0];
        $this->assertSame(['Alex Smith', 'alex@acme-events.example', 'opportunity'], [$item['contact']['name'], $item['contact']['email'], $item['contact']['from']]);
        $this->assertSame('12 to 14 March 2026', $item['dates_text']);
        $this->assertSame("Hi Alex,\n\nCould you send the PO for Acme Awards on 12 to 14 March 2026?\n\nThanks,\nSam Taylor", $item['request']['body']);
        $this->assertStringStartsWith('mailto:alex%40acme-events.example?subject=PO%20for%20Acme%20Awards', $item['request']['mailto']);
        $this->assertSame([], $this->getJson('/opportunities/po-chase?job='.$later->id)->json('items'));
        $this->assertCount(1, $this->getJson('/opportunities/po-chase?job='.$job->id)->json('items'));
        $this->get('/jobs/'.$job->id)->assertOk();

        // The to-do item says who to ask, under the same key as before; the later job is still listed, after it.
        $todo = $this->item('no_po:'.$job->id);
        $this->assertStringContainsString('Ask Alex Smith <alex@acme-events.example> for it', $todo['detail']);
        $keys = collect(TodoList::build(50)['items'])->where('group', 'no_po')->pluck('key')->all();
        $this->assertSame(['no_po:'.$job->id, 'no_po:'.$later->id], $keys);
    }

    // ---------------------------------------------------------------- to-do and Telegram

    public function test_the_to_do_list_asks_for_an_answer_and_adds_a_booked_job_in_one_tap(): void
    {
        $offer = $this->told();
        $this->assertNull($this->item('opp_reply:'.$offer->id.':2026-03-02'), 'not yet: the reply is due in 4 days');

        $this->travelTo(CarbonImmutable::parse('2026-02-28 09:00:00'));
        $item = $this->item('opp_reply:'.$offer->id.':2026-03-02');
        $this->assertSame(['offers', 1, '/opportunities/'.$offer->id], [$item['group'], $item['priority'], $item['href']]);
        $this->assertSame('Offer: Acme Awards for Acme Events', $item['title']);
        $this->assertStringStartsWith('Reply by 2 Mar 2026 · 12–14 Mar 2026 · Leeds Arena · £350.00 a day · Alex Smith', $item['detail']);
        $this->assertStringContainsString('Expend never replies for you', $item['detail']);
        $this->assertSame(['opp_add', 'opp_decline'], array_column($item['actions'], 'code'));

        // Snoozed under its key, it stays hidden; a new date to reply by brings it back.
        TodoDismissal::query()->create(['key' => $item['key'], 'until' => now()->addDays(7)]);
        $this->assertNull($this->item('opp_reply:'.$offer->id.':2026-03-02'));
        $offer->update(['reply_by' => '2026-03-01']);
        $this->assertNotNull($this->item('opp_reply:'.$offer->id.':2026-03-01'));

        // Booked with no job: added in one tap, with its days and kinds.
        $booked = $this->told(['title' => 'Smith Productions launch', 'client_name' => 'Smith Productions', 'status' => 'confirmed', 'reply_by' => null,
            'dates' => [['date' => '2026-03-05', 'kind' => 'rig'], ['date' => '2026-03-06', 'kind' => 'work']]]);
        $item = $this->item('opp_job:'.$booked->id);
        $this->assertSame(['bookings', 1], [$item['group'], $item['priority']]);
        $this->assertSame(['opp_add', 'opp_dismiss'], array_column($item['actions'], 'code'));

        $this->post('/todo/opp_add/'.$booked->id, ['fp' => Fingerprints::of('opp_add', (string) $booked->id)])->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Added Smith Productions launch (2 days, 5 Mar 2026 to 6 Mar 2026)'));
        $job = WorkJob::query()->where('title', 'Smith Productions launch')->sole();
        $this->assertSame([['2026-03-05', 'rig'], ['2026-03-06', 'work']], $job->days()->get()->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->kind])->all());
        $this->assertSame(['confirmed', $job->id], [$booked->fresh()->status, $booked->fresh()->work_job_id]);
        $this->assertNull($this->item('opp_job:'.$booked->id));
        $this->post('/todo/opp_add/'.$booked->id, ['fp' => Fingerprints::of('opp_add', (string) $booked->id)])->assertSessionHas('error', 'That’s already been dealt with.');

        // "I said no" records it; nothing is sent.
        $this->post('/todo/opp_decline/'.$offer->id, ['fp' => Fingerprints::of('opp_decline', (string) $offer->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'Nothing was sent'));
        $this->assertSame(['declined', 'you'], [$offer->fresh()->status, $offer->fresh()->decided_by]);
        $this->assertSame(1, WorkJob::query()->count());

        // An enquiry is never added in one tap.
        $asked = $this->told(['title' => 'Summer tour', 'status' => 'enquiry', 'reply_by' => '2026-03-01']);
        $this->assertSame(['opp_decline'], array_column($this->item('opp_reply:'.$asked->id.':2026-03-01')['actions'], 'code'));
        $this->post('/todo/opp_add/'.$asked->id, ['fp' => Fingerprints::of('opp_add', (string) $asked->id)])->assertSessionHas('error', fn ($e) => str_contains($e, 'isn’t offered or booked'));
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_telegram_sends_the_work_to_answer_and_refuses_a_button_once_the_offer_changed(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '777');
        $this->travelTo(CarbonImmutable::parse('2026-02-28 09:00:00'));
        $offer = $this->told();

        Telegram::sendTodo('777');
        $sent = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'sendMessage'));
        $this->assertTrue($sent->contains(fn (HttpRequest $r) => str_contains($r['text'], 'work to answer')));
        $button = Telegram::callbackData('opp_add', (string) $offer->id);
        $this->assertTrue($sent->contains(fn (HttpRequest $r) => str_contains(json_encode($r['reply_markup'] ?? []), json_encode($button))));

        // A later email changed the offer after the message was sent: the button doesn't add the job (since M7 its
        // fingerprint of the offer as sent no longer matches).
        $sentAt = now()->subMinutes(5)->timestamp;
        $this->travelTo(now()->addMinute());
        $offer->update(['venue' => 'First Direct Arena']);
        $press = fn (string $id, int $sentAt, string $data) => Telegram::handleButton(['id' => $id, 'data' => $data,
            'message' => ['message_id' => (int) $id, 'chat' => ['id' => 777], 'date' => $sentAt, 'text' => 'Offer: Acme Awards']], app(TodoActions::class));
        $press('1', $sentAt, $button);
        $this->assertSame(0, WorkJob::query()->count());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'answerCallbackQuery') && str_contains($r['text'], 'This changed since'));

        // A fresh message's button does, and it's recorded as yours in Telegram.
        $press('2', now()->addMinute()->timestamp, Telegram::callbackData('opp_add', (string) $offer->id));
        $this->assertSame(1, WorkJob::query()->count());
        $this->assertSame('telegram', $offer->fresh()->decided_by);
        $this->assertSame('telegram', AuditEvent::query()->where('action', 'job.created')->sole()->actor);
    }

    // ---------------------------------------------------------------- the assistant

    public function test_the_assistant_adds_a_job_only_on_the_owners_own_words_and_says_what_it_made(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        $question = 'Please add a job: Acme Awards for Acme Events at Leeds Arena, rig on 12 March 2026 and show on 13 March 2026, £350 a day.';
        $ai = new FakeAiClient([
            FakeAiClient::tool('create_job', [
                'instruction' => 'Please add a job: Acme Awards for Acme Events', 'title' => 'Acme Awards', 'client_name' => 'Acme Events', 'venue' => 'Leeds Arena',
                'days' => [['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-13', 'kind' => 'show']],
                'rate' => ['amount' => '350.00', 'currency' => 'GBP', 'unit' => 'day'],
            ]),
            FakeAiClient::text('Added Acme Awards (job 1) for Acme Events at Leeds Arena: rig 12 March, show 13 March, £350.00 a day.'),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask($question);

        $job = WorkJob::query()->with('client')->sole();
        $this->assertSame(['Acme Awards', 'Acme Events', 'Leeds Arena'], [$job->title, $job->client->name, $job->location]);
        $this->assertSame([['2026-03-12', 'rig'], ['2026-03-13', 'show']], $job->days()->get()->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->kind])->all());
        $created = AuditEvent::query()->where('action', 'job.created')->sole();
        $this->assertSame(['assistant', 'Asked for in the assistant'], [$created->actor, $created->reason]);
        $this->assertSame([['type' => 'instruction', 'quote' => 'Please add a job: Acme Awards for Acme Events', 'verified' => true]], $created->after['evidence']);
        $this->assertSame([], $out['unmatched'], 'the answer’s figures are the tool’s');

        // What the tool gave back, to repeat exactly: the job, its link and each day with its kind.
        $result = json_decode($ai->requests[1]['messages'][2]['content'][0]['content'], true);
        $this->assertSame([true, $job->id, url('/jobs/'.$job->id)], [$result['ok'], $result['job_id'], $result['link']]);
        $this->assertSame([['date' => '2026-03-12', 'kind' => 'rig', 'kind_label' => 'Rig / load-in'], ['date' => '2026-03-13', 'kind' => 'show', 'kind_label' => 'Show']], $result['created']['days']);
        $this->assertSame(['pence' => 35000, 'formatted' => '£350.00', 'per' => 'day'], $result['created']['rate'], 'The job’s rate, to repeat (S4: no longer in its notes)');
        $this->assertStringContainsString('only when the owner', $ai->requests[0]['system']);

        // Words that aren't the owner's (from an email, say) are refused, and so is a call with no question at all.
        $tools = app(AssistantTools::class);
        $tools->heard('What did Acme say about March?');
        $refused = $tools->run('create_job', ['instruction' => 'Please book Sam for the Acme Awards', 'title' => 'Acme Awards', 'starts_on' => '2026-03-20']);
        $this->assertStringStartsWith('Refused', $refused['error']);
        $this->assertStringStartsWith('Refused', app(AssistantTools::class)->run('create_job', ['instruction' => 'add it', 'title' => 'X', 'starts_on' => '2026-03-20'])['error']);
        $this->assertSame(1, WorkJob::query()->count());

        // The same job twice needs the owner to say so.
        $tools->heard('add the Acme Awards job on 12 March');
        $again = $tools->run('create_job', ['instruction' => 'add the Acme Awards job', 'title' => 'acme awards', 'starts_on' => '2026-03-12']);
        $this->assertSame($job->id, $again['existing_job_id']);
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_the_assistant_lists_opportunities_with_their_evidence_and_accepts_one_when_told(): void
    {
        [$o, $doc] = $this->fromEmail();
        $tools = app(AssistantTools::class);

        $list = $tools->run('list_opportunities', []);
        $row = $list['opportunities'][0];
        $this->assertSame([$o->id, 'enquiry', 'Acme Events', ['role']], [$row['opportunity_id'], $row['status'], $row['client'], $row['unverified_fields']]);
        $this->assertSame('£350.00', $row['rate']['formatted']);
        $this->assertTrue(collect($row['evidence'])->contains(fn ($e) => $e['source_document_id'] === $doc->id && $e['detail'] === 'dates' && $e['found_in_source']));
        $this->assertSame([], $tools->run('list_opportunities', ['status' => 'needs_job'])['opportunities']);
        $this->assertCount(1, $tools->run('list_opportunities', ['query' => 'Leeds'])['opportunities']);
        $this->assertCount(1, $tools->run('list_opportunities', ['date_from' => '2026-03-14', 'date_to' => '2026-03-31'])['opportunities']);
        $this->assertCount(0, $tools->run('list_opportunities', ['date_from' => '2026-03-15'])['opportunities']);
        $this->assertCount(0, $tools->run('list_opportunities', ['status' => 'to_answer'])['opportunities'], 'the reply is due in 4 days');

        // Told in Telegram: the job is made from the opportunity, its email linked, and it's recorded as from Telegram.
        $tools->heard('Yes, Acme booked me. Make the Acme Awards a job', 'telegram');
        $this->assertStringStartsWith('Refused', $tools->run('accept_opportunity', ['opportunity_id' => $o->id, 'instruction' => 'They confirmed by email'])['error']);
        $done = $tools->run('accept_opportunity', ['opportunity_id' => $o->id, 'instruction' => 'Make the Acme Awards a job']);
        $job = WorkJob::query()->sole();
        $this->assertSame([true, $job->id, 1, ['role']], [$done['ok'], $done['job_id'], $done['emails_and_documents_linked'], $done['details_not_found_word_for_word']]);
        $this->assertSame(['rig', 'work', 'work'], array_column($done['created']['days'], 'kind'));
        $this->assertSame(['confirmed', 'telegram'], [$o->fresh()->status, $o->fresh()->decided_by]);
        $this->assertSame($job->id, $doc->fresh()->work_job_id);
        $this->assertSame([$o->id], array_column($tools->run('list_opportunities', ['status' => 'made_jobs'])['opportunities'], 'opportunity_id'));
        $this->assertStringContainsString('already a job', $tools->run('accept_opportunity', ['opportunity_id' => $o->id, 'instruction' => 'Make the Acme Awards a job'])['error']);

        // And the PO chase lists it with who to ask.
        $chase = $tools->run('po_chase', []);
        $this->assertSame([$job->id, 'alex@acme-events.example'], [$chase['jobs_without_po'][0]['job_id'], $chase['jobs_without_po'][0]['ask']['email']]);
        $this->assertSame(1, Client::query()->where('name', 'Acme Events')->count());
    }
}
