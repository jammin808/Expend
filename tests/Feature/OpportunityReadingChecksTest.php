<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\Opportunities;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * What the AI reading of a work email is trusted with: every day it lists must be in the email, a rate must have its
 * own currency and unit, the stage must be in the newest message and say that stage, two jobs from one agency stay
 * apart, a PO email joins the booking it names, and the rules' guesses give way to a checked reading.
 */
class OpportunityReadingChecksTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2027-02-22 12:00:00'));
        Setting::put('business_email', 'sam@example.test');
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('ai_extraction', 'on');
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function mailbox(array $mail, ?string $at = null): Document
    {
        [$from, $subject, $body] = $mail;
        $date = CarbonImmutable::parse($at ?? '2027-02-22 09:00:00')->format('D, d M Y H:i:s O');
        $raw = "From: {$from}\r\nTo: Sam Taylor <sam@example.test>\r\nDate: {$date}\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            .str_replace("\n", "\r\n", $body)."\r\n";

        return app(DocumentIntake::class)->store($raw, $subject.'.eml', 'message/rfc822', 'email', ['from' => $from, 'subject' => $subject] + ($at ? ['received_at' => $at] : []));
    }

    /** A reading as the model answers it: the opportunity block, the rest empty. */
    private static function reading(array $opportunity, string $kind = 'opportunity'): array
    {
        $none = ['value' => null, 'quote' => null];

        return [
            'kind' => $kind, 'summary' => 'Work.', 'job_link' => ['job_id' => null, 'reason' => null], 'new_job' => null, 'facts' => [], 'po' => null, 'receipt' => null,
            'opportunity' => $opportunity + [
                'hold_rank' => $none, 'title' => $none, 'client' => $none, 'contact' => ['name' => null, 'email' => null, 'quote' => null], 'role' => $none,
                'venue' => $none, 'postcode' => $none, 'dates' => [], 'rate' => null, 'reply_by' => $none, 'po_number' => $none,
            ],
        ];
    }

    private static function q(?string $value, ?string $quote): array
    {
        return ['value' => $value, 'quote' => $quote];
    }

    public function test_a_day_the_reader_made_up_leaves_the_dates_unchecked_and_one_tap_refuses(): void
    {
        $this->ai->responses = [FakeAiClient::json(self::reading([
            'status_hint' => self::q('offered', 'We\'d like to offer you the LX tech role'), 'title' => self::q('Acme Conference', 'the Acme Conference'),
            'client' => self::q('Acme Events', 'Acme Events'),
            'dates' => [
                ['date' => '2027-05-19', 'kind' => 'rig', 'quote' => 'Rig day 19 May'], // not in the email
                ['date' => '2027-05-20', 'kind' => 'work', 'quote' => 'Show days 20-21 May'],
                ['date' => '2027-05-21', 'kind' => 'work', 'quote' => 'Show days 20-21 May'],
            ],
            'rate' => ['amount_minor' => 32000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'The rate is £320 per day'],
            'reply_by' => self::q('2027-02-24', 'Please confirm by 24 February'),
        ]))];
        $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Acme Conference - LX tech offer', "Hi Sam,\n\nWe'd like to offer you the LX tech role on the Acme Conference at ExCeL London. Show days 20-21 May. The rate is £320 per day. Please confirm by 24 February.\n\nAlex Smith\nAcme Events"]);

        $o = Opportunity::query()->sole();
        $this->assertContains('dates', $o->unverifiedFields(), 'One day of three isn’t in the email');
        $this->assertFalse($o->oneTapReady());
        TodoList::forget();
        $item = collect(TodoList::build(50)['items'])->firstWhere('key', 'opp_reply:'.$o->id.':2027-02-24');
        $this->assertSame([], collect($item['actions'])->where('code', 'opp_add')->all(), 'No one-tap add');
        try {
            app(TodoActions::class)->run('opp_add', (string) $o->id);
            $this->fail('A job was made with a day the email never gave');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Check its details first', $e->getMessage());
        }
        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_a_rate_read_with_the_wrong_unit_or_currency_is_unchecked_and_never_becomes_the_jobs_fee(): void
    {
        $this->ai->responses = [FakeAiClient::json(self::reading([
            'status_hint' => self::q('offered', 'We\'d like to offer you the LX tech role'), 'title' => self::q('Acme Conference', 'the Acme Conference'),
            'client' => self::q('Acme Events', 'Acme Events'),
            'dates' => [['date' => '2027-05-20', 'kind' => 'work', 'quote' => 'show days 20-21 May'], ['date' => '2027-05-21', 'kind' => 'work', 'quote' => 'show days 20-21 May']],
            'rate' => ['amount_minor' => 32000, 'currency' => 'EUR', 'unit' => 'job', 'quote' => 'The rate is £320 per day'],
        ]))];
        $doc = $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Acme Conference - LX tech', "Hi Sam,\n\nWe'd like to offer you the LX tech role on the Acme Conference, show days 20-21 May. The rate is £320 per day.\n\nAlex Smith\nAcme Events"]);

        $this->assertFalse($doc->fresh()->extraction['opportunity']['rate']['verified']);
        $o = Opportunity::query()->sole();
        $this->assertSame(['rate'], $o->unverifiedFields());
        $job = app(Opportunities::class)->accept($o, []);
        $this->assertNull($job->fee_pence, 'A rate not found word for word is never the fee');
        $this->assertStringContainsString('not found word for word', $job->notes);

        $this->assertFalse(Opportunities::rateInQuote('GBP', 'job', '£320 per day'), 'A day rate isn’t a fee for the job');
        $this->assertFalse(Opportunities::rateInQuote('EUR', 'day', '£320 per day'), 'Pounds aren’t euros');
        $this->assertFalse(Opportunities::rateInQuote('GBP', 'job', '£40/hr'));
        $this->assertTrue(Opportunities::rateInQuote('GBP', 'job', '£1,200 for the job'));
        $this->assertTrue(Opportunities::rateInQuote('GBP', 'day', '£320 per day'));
        $this->assertTrue(Opportunities::rateInQuote('EUR', 'day', 'EUR 400 a day'));
        $this->assertTrue(Opportunities::rateInQuote('USD', 'hour', '$50 an hour'));
    }

    public function test_two_jobs_from_one_agency_on_the_same_day_at_different_venues_stay_apart(): void
    {
        $this->ai->responses = [
            FakeAiClient::json(self::reading([
                'status_hint' => self::q('enquiry', 'Are you available on 12 March'), 'title' => self::q('Concert at the O2 Arena', 'a concert at the O2 Arena'),
                'client' => self::q('Crew Co', 'Crew Co'), 'venue' => self::q('O2 Arena', 'the O2 Arena'),
                'dates' => [['date' => '2027-03-12', 'kind' => 'work', 'quote' => 'on 12 March']],
                'rate' => ['amount_minor' => 28000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => '£280 a day'],
            ])),
            FakeAiClient::json(self::reading([
                'status_hint' => self::q('enquiry', 'would you be available 12-13 March'), 'title' => self::q('Corporate at Wembley Arena', 'a corporate at Wembley Arena'),
                'client' => self::q('Crew Co', 'Crew Co'), 'venue' => self::q('Wembley Arena', 'Wembley Arena'),
                'dates' => [['date' => '2027-03-12', 'kind' => 'work', 'quote' => '12-13 March'], ['date' => '2027-03-13', 'kind' => 'work', 'quote' => '12-13 March']],
                'rate' => ['amount_minor' => 25000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => '£250 a day'],
            ])),
        ];
        $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'O2 Arena - Fri 12 March', "Hi Sam,\n\nAre you available on 12 March for a concert at the O2 Arena? LX crew, £280 a day.\n\nJo\nCrew Co"], '2027-02-20 09:00:00');
        $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'Wembley - 12-13 March', "Hi Sam,\n\nAlso, would you be available 12-13 March for a corporate at Wembley Arena? Stage crew, £250 a day.\n\nJo\nCrew Co"], '2027-02-21 09:00:00');

        $this->assertSame([['Concert at the O2 Arena', 'O2 Arena', 28000], ['Corporate at Wembley Arena', 'Wembley Arena', 25000]],
            Opportunity::query()->orderBy('id')->get()->map(fn ($o) => [$o->title, $o->venue, $o->rate_minor])->all());
    }

    public function test_a_stage_quoted_from_the_earlier_messages_is_unchecked_and_moves_nothing(): void
    {
        $this->ai->responses = [
            FakeAiClient::json(self::reading([
                'status_hint' => self::q('offered', 'We\'d like to offer you the LX tech role on the Acme Conference'), 'title' => self::q('Acme Conference', 'the Acme Conference'),
                'client' => self::q('Acme Events', 'Acme Events'), 'dates' => [['date' => '2027-05-20', 'kind' => 'work', 'quote' => '20 May']],
            ])),
            // The reply turns it down; the reader takes its stage from the offer quoted below it.
            FakeAiClient::json(self::reading([
                'status_hint' => self::q('confirmed', 'We\'d like to offer you the LX tech role on the Acme Conference'), 'title' => self::q('Acme Conference', 'the Acme Conference'),
                'client' => self::q('Acme Events', 'Acme Events'),
            ], 'email')),
        ];
        $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Acme Conference - LX tech', "Hi Sam,\n\nWe'd like to offer you the LX tech role on the Acme Conference on 20 May.\n\nAlex Smith\nAcme Events"]);
        $reply = $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Re: Acme Conference - LX tech', "Hi Sam,\n\nSorry, we've gone with someone else for this one.\n\nAlex\n\nOn Mon, 22 Feb 2027 at 09:00, Alex Smith <alex@acme-events.example> wrote:\n> We'd like to offer you the LX tech role on the Acme Conference on 20 May."], '2027-02-23 09:00:00');

        $this->assertFalse($reply->fresh()->extraction['opportunity']['status_hint']['verified'], 'Not in the newest message, and an offer isn’t a booking');
        $o = Opportunity::query()->sole();
        $this->assertSame('offered', $o->status, 'An unchecked stage moves nothing');
        $this->assertFalse(collect($o->evidenceFor('status'))->firstWhere('value', 'confirmed')['verified']);
        $this->assertTrue(Opportunities::stageInQuote('held', 'pencil you in as 1st option'));
        $this->assertFalse(Opportunities::stageInQuote('confirmed', 'We\'d like to offer you the job'));
        $this->assertFalse(Opportunities::stageInQuote('lost', 'Are you free on the 12th?'));
    }

    public function test_an_email_the_rules_spotted_then_read_with_ai_takes_the_checked_days_and_kinds(): void
    {
        Setting::put('ai_extraction', 'on_demand');
        $doc = $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'LX crew - Leeds', "Hi Sam,\n\nAre you available Thurs 12th - Sat 14th March for a conference at the First Direct Arena? Rig on the Thursday. £300/day.\n\nJo\nCrew Co"]);
        $o = Opportunity::query()->sole();
        $this->assertSame('rules', $o->found_by);
        // The rules' reading, as an older version left it: only the last day.
        $o->update(['dates' => [['date' => '2027-03-14', 'kind' => 'work']], 'starts_on' => '2027-03-14', 'ends_on' => '2027-03-14']);

        $this->ai->responses = [FakeAiClient::json(self::reading([
            'status_hint' => self::q('enquiry', 'Are you available Thurs 12th - Sat 14th March'), 'client' => self::q('Crew Co', 'Crew Co'),
            'venue' => self::q('First Direct Arena', 'the First Direct Arena'),
            'dates' => [['date' => '2027-03-12', 'kind' => 'rig', 'quote' => 'Thurs 12th - Sat 14th March'], ['date' => '2027-03-13', 'kind' => 'work', 'quote' => 'Thurs 12th - Sat 14th March'],
                ['date' => '2027-03-14', 'kind' => 'work', 'quote' => 'Thurs 12th - Sat 14th March']],
            'rate' => ['amount_minor' => 30000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => '£300/day'],
        ]))];
        app(DocumentReader::class)->read($doc->fresh());

        $o = Opportunity::query()->sole();
        $this->assertSame([['date' => '2027-03-12', 'kind' => 'rig'], ['date' => '2027-03-13', 'kind' => 'work'], ['date' => '2027-03-14', 'kind' => 'work']], $o->dates);
        $this->assertSame([true, true, true], array_column($doc->fresh()->extraction['opportunity']['dates'], 'verified'));
        $this->assertSame(['ai', 30000, 'First Direct Arena'], [$o->found_by, $o->rate_minor, $o->venue]);
        $this->assertSame([], $o->unverifiedFields());
    }

    public function test_a_po_email_with_no_dates_joins_the_booking_it_names_and_starts_nothing_on_its_own(): void
    {
        $base = ['title' => self::q('Acme Conference', 'the Acme Conference'), 'client' => self::q('Acme Events', 'Acme Events')];
        $this->ai->responses = [
            FakeAiClient::json(self::reading(['status_hint' => self::q('confirmed', 'you\'re confirmed for the LX tech role'), 'venue' => self::q('ExCeL London', 'ExCeL London'),
                'dates' => array_map(fn ($d) => ['date' => $d, 'kind' => 'work', 'quote' => '19-22 May'], ['2027-05-19', '2027-05-20', '2027-05-21', '2027-05-22'])] + $base, 'job_invite')),
            FakeAiClient::json(self::reading(['status_hint' => self::q('confirmed', 'Please find our purchase order 4471'), 'po_number' => self::q('4471', 'purchase order 4471')] + $base, 'po')),
            FakeAiClient::json(self::reading(['status_hint' => self::q('confirmed', 'Please find our purchase order 5100'), 'po_number' => self::q('5100', 'purchase order 5100'),
                'title' => self::q('Smith Gala', 'the Smith Gala'), 'client' => self::q('Smith Productions', 'Smith Productions')], 'po')),
        ];
        $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Acme Conference - LX tech', "Hi Sam,\n\nGreat news: you're confirmed for the LX tech role on the Acme Conference at ExCeL London, 19-22 May.\n\nAlex Smith\nAcme Events"]);
        $this->mailbox(['Acme Events Accounts <accounts@acme-events.example>', 'Purchase order 4471', "Hi Sam,\n\nPlease find our purchase order 4471 for your work on the Acme Conference. Please quote the PO number on your invoice.\n\nAcme Events Accounts"], '2027-02-25 09:00:00');
        $this->mailbox(['Smith Accounts <accounts@smithproductions.example>', 'Purchase order 5100', "Hi Sam,\n\nPlease find our purchase order 5100 for the Smith Gala.\n\nSmith Productions"], '2027-02-25 10:00:00');

        $this->assertCount(3, $this->ai->requests, 'Each email is read once');
        $o = Opportunity::query()->sole();
        $this->assertSame(['confirmed', '4471', 2], [$o->status, $o->po_number, count($o->documentIds())]);
        $this->assertSame(1, Opportunity::query()->needsJob()->count(), 'One booking, listed once');
    }
}
