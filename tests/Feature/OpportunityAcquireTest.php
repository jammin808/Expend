<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Jobs\OpportunityRules;
use App\Jobs\ReadDocument;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Finding work offered by email before there's a PO: the Gmail search, the AI reading's "opportunity" block, and the
 * rules that spot obvious offers when the AI isn't reading. Realistic UK crew emails: an availability check, a 1st
 * option hold, an offer with a day rate and rig and de-rig days, a confirmation, a cancellation, and a newsletter
 * that must not count.
 */
class OpportunityAcquireTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2027-02-22 12:00:00'));
        Setting::put('business_email', 'sam@example.test'); // the freelancer's own address
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('ai_extraction', 'off');
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    // ---------------------------------------------------------------- the emails

    private const AVAILABILITY = ['Jo Bloggs <jo@crewco.example>', 'Availability - Acme Awards 12-14 March', <<<'TXT'
        Hi Sam,

        Are you available 12-14 March for the Acme Awards at Leeds Arena, LS11 8BU? We need a sound No.2. Day rate is £300 per day.

        Let me know by Friday 26 February if you can.

        Cheers,
        Jo Bloggs
        Crew Co Ltd
        TXT];

    private const HOLD = ['Alex Smith <alex@acme-events.example>', 'Smith Productions launch', <<<'TXT'
        Hi Sam,

        We'd like to put you down as 1st option for the Smith Productions launch on 3 April at Olympia London. We'll confirm by 20 March.

        Thanks,
        Alex Smith
        Acme Events
        TXT];

    private const OFFER = ['Alex Smith <alex@acme-events.example>', 'Acme Conference - LX tech', <<<'TXT'
        Hi Sam,

        We'd like to offer you the LX tech role on the Acme Conference at ExCeL London, E16 1XL. Rig day 19 May, show days 20-21 May, derig 22 May. The rate is £320 per day.

        Please confirm by 1 May.

        Best,
        Alex Smith
        Acme Events
        TXT];

    private const CONFIRMATION = ['Alex Smith <alex@acme-events.example>', 'Re: Acme Conference - LX tech', <<<'TXT'
        Hi Sam,

        Great, you're confirmed for the Acme Conference, 19-22 May. PO to follow next week.

        Alex

        On Mon, 22 Feb 2027 at 09:00, Sam Taylor <sam@example.test> wrote:
        > Yes please, count me in.
        >
        > On Mon, 22 Feb 2027 at 08:00, Alex Smith <alex@acme-events.example> wrote:
        >> We'd like to offer you the LX tech role on the Acme Conference at ExCeL London, E16 1XL.
        TXT];

    private const CANCELLATION = ['Jo Bloggs <jo@crewco.example>', 'Re: Availability - Acme Awards 12-14 March', <<<'TXT'
        Hi Sam,

        Sorry, the client has cancelled the Acme Awards, so we won't need you on 12-14 March after all.

        Jo

        On Mon, 22 Feb 2027 at 09:00, Jo Bloggs <jo@crewco.example> wrote:
        > Are you available 12-14 March for the Acme Awards at Leeds Arena, LS11 8BU?
        TXT];

    private const NEWSLETTER = ['StageJobs Pro <news@stagejobspro.com>', '50 new freelance crew jobs this week - are you available?', <<<'TXT'
        Hi there,

        Are you available this spring? Hundreds of new freelance sound, lighting and video jobs, with day rates from £150.

        View this email in your browser | Unsubscribe
        TXT];

    private const DRINK = ['Chris Green <chris@example.org>', 'Friday?', <<<'TXT'
        Hey Sam,

        Are you free for a drink on Friday 26 February? The usual place.

        Chris
        TXT];

    /** An email as it arrives. */
    private function raw(array $mail, array $headers = [], string $date = 'Mon, 22 Feb 2027 09:00:00 +0000', ?string $attachment = null): string
    {
        [$from, $subject, $body] = $mail;
        $head = "From: {$from}\r\nTo: Sam Taylor <sam@example.test>\r\nDate: {$date}\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        $body = str_replace("\n", "\r\n", $body);
        if ($attachment === null) {
            return $head."Content-Type: text/plain; charset=UTF-8\r\n\r\n".$body."\r\n";
        }

        return $head."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n"
            ."--b1\r\nContent-Type: text/plain; name=\"PO-4471.txt\"\r\nContent-Disposition: attachment; filename=\"PO-4471.txt\"\r\n\r\n{$attachment}\r\n--b1--\r\n";
    }

    /** Taken in from the forwarding mailbox, as MailboxFetcher does. */
    private function mailbox(array $mail, array $headers = []): Document
    {
        return app(DocumentIntake::class)->store($this->raw($mail, $headers), $mail[1].'.eml', 'message/rfc822', 'email', ['from' => $mail[0], 'subject' => $mail[1]]);
    }

    /** Gmail's API answering one search with these messages (id => [raw, thread]). */
    private function gmail(array $messages): void
    {
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        $fakes = ['gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => array_map(fn ($id) => ['id' => $id], array_keys($messages))])];
        $at = 1_803_000_000_000;
        foreach ($messages as $id => [$raw, $thread]) {
            $fakes['gmail.googleapis.com/gmail/v1/users/me/messages/'.$id.'*'] = Http::response([
                'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 'threadId' => $thread, 'internalDate' => (string) ($at += 60_000),
            ]);
        }
        Http::fake($fakes);
    }

    // ---------------------------------------------------------------- the Gmail search

    public function test_the_gmail_search_finds_offers_of_work_but_not_newsletters_adverts_or_your_own_mail(): void
    {
        $query = GmailScanner::query('opportunities');
        $this->assertSame($query, GmailScanner::SEARCHES['opportunities']['query']);
        $this->assertSame(substr_count($query, '('), substr_count($query, ')'), 'Brackets balance');
        $this->assertSame(0, substr_count($query, '"') % 2, 'Quotes balance');
        foreach (OpportunityRules::JOB_BOARDS as $domain) {
            $this->assertStringContainsString($domain, $query, 'Job boards’ alerts are left out');
        }
        $this->assertContains('opportunities', GmailScanner::JOB_SEARCHES);

        $mail = fn (array $m, array $labels = [], bool $me = false) => ['from' => $m[0], 'subject' => $m[1], 'body' => $m[2], 'labels' => $labels, 'me' => $me];
        foreach (['availability check' => self::AVAILABILITY, '1st option hold' => self::HOLD, 'offer' => self::OFFER,
            'confirmation (a reply quoting the offer)' => self::CONFIRMATION, 'cancellation (a reply in the thread)' => self::CANCELLATION] as $what => $m) {
            $this->assertTrue(self::gmailMatches($query, $mail($m)), "Finds the {$what}");
        }
        $this->assertTrue(self::gmailMatches($query, $mail(['Pat Lee <pat@venue.example>', 'Crew request: 2 x LX techs, 8 May', 'Hi Sam, can you help?'])));
        $this->assertTrue(self::gmailMatches($query, $mail(['Pat Lee <pat@venue.example>', 'Quick one', 'Would you be available on 8 May for a get-out?'])));
        // The usual British ways of asking, in the body, beside a word about the work.
        foreach ([
            ['Jo Bloggs <jo@crewco.example>', 'Sat 14th March', 'Hi Sam, are you free Sat 14th March? Corporate at the Hilton Park Lane, LX op, £280.'],
            ['Pat Lee <pat@stagehands.example>', 'NEC 2-3 April', 'Hi Sam, can you do 2-3 April? LX op at the NEC.'],
            ['Jo Bloggs <jo@crewco.example>', 'Leeds in March', 'Any chance you\'re free 12-14 March? Got a conference at the First Direct Arena.'],
            ['Kim Ward <kim@venueco.example>', 'Spring Ball', 'Hi Sam, would you be interested in the Spring Ball on 3 April? £350 for the day.'],
        ] as $m) {
            $this->assertTrue(self::gmailMatches($query, $mail($m)), 'Finds “'.$m[2].'”');
        }

        $notWork = [
            'the newsletter' => $mail(self::NEWSLETTER, ['promotions']),
            'the newsletter (even outside Promotions)' => $mail(self::NEWSLETTER),
            'your own reply' => $mail(['Sam Taylor <sam@example.test>', 'Re: Availability - Acme Awards 12-14 March', 'Yes, I’m available.'], ['sent'], true),
            'a parcel on hold' => $mail(['Parcel Co <updates@parcelco.example>', 'Your parcel is on hold', 'Your parcel is on hold at the depot.']),
            'an insurance quote' => $mail(['InsureCo <quotes@insureco.example>', 'Your car insurance quote', 'Your quote is ready. Are you free to talk?']),
            'a drink with a friend' => $mail(self::DRINK),
            'a job board alert' => $mail(['Indeed <alert@indeed.com>', 'Freelance sound engineer jobs', 'Are you available? New jobs match your search.']),
            'a sale' => $mail(['Kit Shop <hello@kitshop.example>', 'Day rate hire sale: 20% off', 'Book now.'], ['promotions']),
            'a drink on Friday' => $mail(['Chris Green <chris@example.org>', 'Friday', 'Are you free on Friday? Fancy a drink after work?']),
            'a PO email' => $mail(['Accounts <accounts@acme-events.example>', 'PO 4471 - Acme Conference', 'Please find below PO 4471. Could you quote this PO number on your invoice?']),
            'a van hire firm’s reply' => $mail(['Northgate <bookings@vanhire.example>', 'Thanks for your quote request', 'One of the team will call you with a price.']),
        ];
        foreach ($notWork as $what => $m) {
            $this->assertFalse(self::gmailMatches($query, $m), "Leaves out {$what}");
        }
    }

    // ---------------------------------------------------------------- the rules, when the AI isn't reading

    public function test_with_ai_off_an_availability_check_found_in_gmail_is_proposed_as_a_low_confidence_enquiry_found_by_the_rules(): void
    {
        $this->gmail(['m1' => [$this->raw(self::AVAILABILITY), 'T1'], 'm2' => [$this->raw(self::CANCELLATION, [], 'Tue, 23 Feb 2027 09:00:00 +0000'), 'T1']]);

        $result = app(GmailScanner::class)->scanPage('opportunities', '2026-27');

        $this->assertSame(['found' => 2, 'new' => 2], ['found' => $result['found'], 'new' => $result['new']]);
        $this->assertSame(['opportunities', 'opportunities'], GmailMessage::query()->orderBy('id')->pluck('search')->all());
        $o = Opportunity::query()->sole();
        $doc = Document::query()->where('email_subject', self::AVAILABILITY[1])->sole();
        $this->assertSame(['enquiry', 'rules', 'gmail', 'gmail:T1', sha1('document:'.$doc->id)], [$o->status, $o->found_by, $o->source, $o->thread_key, $o->fingerprint]);
        $this->assertLessThanOrEqual(30, $o->confidence, 'Found by its words alone: low confidence');
        $this->assertSame(['Availability - Acme Awards 12-14 March', 'Jo Bloggs', 'jo@crewco.example'], [$o->title, $o->contact_name, $o->contact_email]);

        // The matched words are quoted from the email, and checked against it.
        $status = $o->evidenceFor('status')[0];
        $this->assertSame([$doc->id, 'enquiry', true], [$status['id'], $status['value'], $status['verified']]);
        $this->assertStringContainsString('Are you available 12-14 March', $status['quote']);
        // Its days: the range, not the date to answer by.
        $this->assertSame([['date' => '2027-03-12', 'kind' => 'work'], ['date' => '2027-03-13', 'kind' => 'work'], ['date' => '2027-03-14', 'kind' => 'work']], $o->dates);
        $this->assertTrue(collect($o->evidenceFor('dates'))->every(fn ($e) => $e['verified'] === true));
        $this->assertSame([], $o->unverifiedFields());

        // The cancellation in the same thread starts nothing, and the rules never change where it stands.
        $this->assertSame('enquiry', $o->fresh()->status);
        $found = AuditEvent::query()->where('action', 'opportunity.found')->sole();
        // The opportunity finder's rules are recorded as the matcher (the actor vocabulary; 'rules' was its old name).
        $this->assertSame(['matcher', 'Are you available'], [$found->actor, $found->after['matched']]);
        $this->assertSame([], $this->ai->requests, 'No AI was asked');
        $this->assertSame(0, WorkJob::query()->count(), 'Nothing is made a job by itself');

        // Found again by another search: still one.
        app(GmailScanner::class)->scanPage('opportunities', '2026-27');
        $this->assertSame(1, Opportunity::query()->count());
    }

    public function test_with_ai_off_holds_offers_and_forwarded_enquiries_are_spotted_but_not_confirmations_cancellations_newsletters_or_drinks(): void
    {
        $hold = $this->mailbox(self::HOLD);
        $offer = $this->mailbox(self::OFFER);

        $h = Opportunity::query()->where('fingerprint', sha1('document:'.$hold->id))->sole();
        $this->assertSame(['enquiry', 'rules', 'email', 'Smith Productions launch'], [$h->status, $h->found_by, $h->source, $h->title]);
        $this->assertStringContainsString('1st option', $h->evidenceFor('status')[0]['quote']);
        $this->assertSame(['2027-04-03'], $h->dayDates(), '“3 April” in an email of February is this April; “confirm by 20 March” isn’t a work day');

        $o = Opportunity::query()->where('fingerprint', sha1('document:'.$offer->id))->sole();
        $this->assertStringContainsString('like to offer you the LX tech role', $o->evidenceFor('status')[0]['quote']);
        $this->assertSame(['2027-05-19', '2027-05-20', '2027-05-21', '2027-05-22'], $o->dayDates());
        $this->assertSame(['alex@acme-events.example', 'enquiry'], [$o->contact_email, $o->status], 'The rules only ever propose an enquiry');

        foreach ([self::CONFIRMATION, self::CANCELLATION, self::DRINK] as $mail) {
            $this->mailbox($mail);
        }
        $this->mailbox(self::NEWSLETTER, ['List-Unsubscribe' => '<mailto:leave@stagejobspro.com>', 'Precedence' => 'bulk']);
        // A newsletter from an ordinary-looking address is still known by its list headers.
        $this->mailbox(['Crew News <hello@crewnews.example>', 'Spring crew call-out', "Hi all,\n\nAre you available this spring? Reply to get on the list."], ['List-Id' => '<crew.crewnews.example>']);
        $this->assertSame(2, Opportunity::query()->count(), 'Only the hold and the offer');

        // An enquiry you forwarded to the mailbox: the person asking is the original sender, not you.
        $fwd = $this->mailbox(['Sam Taylor <sam@example.test>', 'Fwd: Availability - Acme Awards 12-14 March', "FYI\n\n---------- Forwarded message ---------\nFrom: Jo Bloggs <jo@crewco.example>\n"
            ."Date: Mon, 22 Feb 2027 at 09:00\nSubject: Availability - Acme Awards 12-14 March\nTo: Sam Taylor <sam@example.test>\n\n".self::AVAILABILITY[2]]);
        $f = Opportunity::query()->where('fingerprint', sha1('document:'.$fwd->id))->sole();
        $this->assertSame(['Jo Bloggs', 'jo@crewco.example', '2027-03-12', '2027-03-14'], [$f->contact_name, $f->contact_email, $f->starts_on->format('Y-m-d'), $f->ends_on->format('Y-m-d')]);
        $this->assertSame('Availability - Acme Awards 12-14 March', $f->title);

        // The same email again (a copy forwarded twice) is the same opportunity.
        $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'RE: Availability - Acme Awards 12-14 March', "Hi Sam, just checking: are you available 12-14 March?\n\nJo"]);
        $this->assertSame(3, Opportunity::query()->count(), 'A chaser in the same conversation isn’t a new opportunity');
        $this->assertSame([], $this->ai->requests);
    }

    public function test_with_ai_off_po_and_invoice_emails_bookings_of_your_own_parties_and_sales_pitches_are_not_work(): void
    {
        foreach ([
            '04 a restaurant' => ['Hawksmoor Reservations <reservations@hawksmoor.example>', 'Your booking request - Friday 12 March', "Dear Sam,\n\nThank you for your booking request for 4 guests on Friday 12 March at 19:30. We will be in touch to confirm shortly.\n\nThe Reservations Team"],
            '05 a van hire firm' => ['Northgate Van Hire <bookings@vanhire.example>', 'Thanks for your quote request', "Hi Sam,\n\nThanks for your quote request for a Luton van from 19 May to 22 May. One of the team will call you with a price.\n\nNorthgate Van Hire"],
            '06 a party' => ['Chris Green <chris@example.org>', 'Tom\'s 40th', "Hey Sam,\n\nAre you free on Saturday 13 March? It's Tom's 40th, we're doing a surprise party at the Crown.\n\nChris"],
            '07 a demo day' => ['Dan Price <dan@lightingsupplies.example>', 'grandMA3 demo day', "Hi Sam,\n\nWould you be interested in coming to our grandMA3 demo day on 18 March at our Manchester showroom? Free lunch included.\n\nDan Price\nLighting Supplies Ltd"],
            '08 a PO' => ['Accounts <accounts@acme-events.example>', 'PO 4471 - Acme Conference', "Hi Sam,\n\nPlease find below PO 4471 for the Acme Conference, 19-22 May. Could you quote this PO number on your invoice?\n\nThanks,\nAcme Events Accounts"],
            '09 an invoice chaser' => ['Accounts <accounts@smithproductions.example>', 'Invoice INV-0042', "Hi Sam,\n\nThanks for your invoice INV-0042 for the March show. Can you quote our PO reference SP-889 and resend it? We pay on 30 days.\n\nAccounts"],
            '10 a hotel' => ['The Grand Hotel <stay@grandhotel.example>', 'Booking confirmation - 19 May', "Dear Mr Taylor,\n\nThank you for your booking request. We are pleased to confirm your stay from 19 May to 22 May. Check-in from 3pm.\n\nThe Grand Hotel"],
            '11 an agency newsletter' => ['Crew Co <hello@crewco.example>', 'Crew Co spring update', "Hi all,\n\nAre you available over Easter? Lots of work coming in.\n\nUnsubscribe from these emails"],
        ] as $what => $mail) {
            $this->mailbox($mail);
            $this->assertSame(0, Opportunity::query()->count(), "Not work: {$what}");
        }
    }

    public function test_with_ai_off_uk_date_phrasings_and_asks_after_news_are_read_right(): void
    {
        $range = $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'LX crew - Leeds', "Hi Sam,\n\nAre you available Thurs 12th - Sat 14th March for a conference at the First Direct Arena? £300/day.\n\nJo\nCrew Co"]);
        $list = $this->mailbox(['Pat Lee <pat@stagehands.example>', 'Avail check - weekend', "Hi Sam,\n\nAvail check for Sat 6th & Sun 7th June please - outdoor festival in Hyde Park, stage crew.\n\nPat"]);
        // News before the ask isn't the work being called off.
        $cover = $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'Urgent - LX tech needed Sat', "Hi Sam,\n\nWe've had a cancellation on our team - are you available this Saturday 27 February at the Royal Albert Hall? £300 for the day.\n\nJo\nCrew Co"]);
        $moved = $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Acme Awards - new dates', "Hi Sam,\n\nThe Acme Awards have been postponed to 9-10 April. Are you available on the new dates?\n\nAlex"]);

        $o = fn (Document $d) => Opportunity::query()->where('fingerprint', sha1('document:'.$d->id))->sole();
        $this->assertSame(['2027-03-12', '2027-03-13', '2027-03-14'], $o($range)->dayDates());
        $this->assertSame([], $o($range)->unverifiedFields(), 'Each day is found in “Thurs 12th - Sat 14th March”');
        $this->assertSame(['2027-06-06', '2027-06-07'], $o($list)->dayDates());
        $this->assertSame(['2027-02-27'], $o($cover)->dayDates());
        $this->assertSame(['2027-04-09', '2027-04-10'], $o($moved)->dayDates());

        // A reply calling work off isn't an ask, whatever its subject says.
        $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'Re: Avail check - Acme Awards 12-14 March', "Hi Sam,\n\nSorry mate - the client went with their in-house crew in the end, so we're standing you down for 12-14 March.\n\nJo\n\nOn Mon, 15 Feb 2027 at 09:00, Jo Bloggs <jo@crewco.example> wrote:\n> Are you available 12-14 March for the Acme Awards?"]);
        $this->mailbox(['Alex Smith <alex@acme-events.example>', 'Re: 1st option - Acme Gala 20 March', "Hi Sam,\n\nThe gala's been pulled, so I'm letting your 1st option go. Sorry!\n\nAlex"]);
        $this->assertSame(4, Opportunity::query()->count());
    }

    public function test_with_ai_off_two_jobs_with_the_same_plain_subject_from_one_agency_stay_apart(): void
    {
        $a = app(DocumentIntake::class)->store($this->raw(['Jo Bloggs <jo@crewco.example>', 'Availability', "Hi Sam,\n\nAre you available 12-14 March for the Acme Awards at Leeds Arena?\n\nJo"]),
            'a.eml', 'message/rfc822', 'email', ['from' => 'Jo Bloggs <jo@crewco.example>', 'subject' => 'Availability', 'received_at' => '2027-02-10 09:00:00']);
        $b = app(DocumentIntake::class)->store($this->raw(['Jo Bloggs <jo@crewco.example>', 'Availability', "Hi Sam,\n\nAre you available 2-3 April for a corporate at the ICC Birmingham?\n\nJo"], [], 'Sat, 20 Feb 2027 09:00:00 +0000'),
            'b.eml', 'message/rfc822', 'email', ['from' => 'Jo Bloggs <jo@crewco.example>', 'subject' => 'Availability', 'received_at' => '2027-02-20 09:00:00']);

        $this->assertSame(2, Opportunity::query()->count(), '“Availability” says nothing about which job it is');
        $second = Opportunity::query()->where('fingerprint', sha1('document:'.$b->id))->sole();
        $this->assertSame(['2027-04-02', '2027-04-03'], $second->dayDates());
        $this->assertSame([$a->id], Opportunity::query()->where('fingerprint', sha1('document:'.$a->id))->sole()->documentIds());
    }

    public function test_with_ai_off_a_cancellation_in_the_conversation_is_shown_but_never_closes_it(): void
    {
        $ask = ['Jo Bloggs <jo@crewco.example>', 'Availability - Acme Awards 12-14 March', "Hi Sam,\n\nAre you available 12-14 March for the Acme Awards at Leeds Arena?\n\nJo"];
        $cancel = ['Jo Bloggs <jo@crewco.example>', 'Re: Availability - Acme Awards 12-14 March', "Hi Sam,\n\nSorry, the client has cancelled the Acme Awards, so we won't need you after all.\n\nJo\n\nOn Mon, 22 Feb 2027 at 09:00, Jo Bloggs <jo@crewco.example> wrote:\n> Are you available 12-14 March for the Acme Awards at Leeds Arena?"];
        // Gmail lists the newest first: the cancellation (sent later) is kept before the ask.
        $this->gmail(['a1' => [$this->raw($ask), 'T1'], 'c1' => [$this->raw($cancel, [], 'Tue, 23 Feb 2027 09:00:00 +0000'), 'T1']]);
        app(GmailScanner::class)->store('c1', 'opportunities');
        app(GmailScanner::class)->store('a1', 'opportunities');

        $o = Opportunity::query()->sole();
        $off = Document::query()->where('email_subject', $cancel[1])->sole();
        $this->assertContains($off->id, $o->documentIds(), 'The cancellation is in its evidence');
        $this->assertSame(['enquiry', 'lost'], [$o->status, collect($o->evidenceFor('status'))->last()['value']], 'Shown, never closed by the rules');
        $this->assertStringContainsString('cancelled the Acme Awards', $o->mayBeOff()['quote']);
        $this->assertTrue($o->mayBeOff()['verified']);
        $this->assertSame('matcher', AuditEvent::query()->where('action', 'opportunity.updated')->sole()->actor);
    }

    public function test_with_ai_off_a_booking_confirmation_and_a_crew_call_to_a_list_are_proposed_for_you_to_check(): void
    {
        $booked = $this->mailbox(['Kim Ward <kim@venueco.example>', 'Booking confirmation - Spring Ball 3 April', "Hi Sam,\n\nYou're booked for the Spring Ball on 3 April at the Grosvenor House. Call 14:00. £350 for the day.\n\nKim"]);
        $call = $this->mailbox(['Crew Co Bookings <bookings@crewco.example>', 'Crew call: 6 x LX techs NEC 12-14 March', "Hi all,\n\nWe need 6 LX techs for 12-14 March at the NEC, £300/day. Are you available? Reply to this email to be considered.\n\nCrew Co\nUnsubscribe from crew calls"]);

        $b = Opportunity::query()->where('fingerprint', sha1('document:'.$booked->id))->sole();
        $this->assertSame(['confirmed', 'rules', ['2027-04-03']], [$b->status, $b->found_by, $b->dayDates()]);
        $this->assertFalse($b->oneTapReady(), 'Spotted by its words alone: you check it before it’s a job');
        $this->assertSame(1, Opportunity::query()->needsJob()->count());

        $c = Opportunity::query()->where('fingerprint', sha1('document:'.$call->id))->sole();
        $this->assertSame(['2027-03-12', '2027-03-13', '2027-03-14'], $c->dayDates());
        $this->assertStringContainsString('mailing list', $c->note);
        $this->assertLessThan(Opportunity::query()->where('fingerprint', sha1('document:'.$booked->id))->value('confidence'), $c->confidence);
        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_with_ai_on_a_booking_confirmation_is_read_but_a_hotel_booking_is_not(): void
    {
        Setting::put('ai_extraction', 'on');
        Queue::fake();
        $conf = $this->mailbox(['Kim Ward <kim@venueco.example>', 'Spring Ball - 3 April', "Hi Sam,\n\nJust to confirm you're booked for the Spring Ball at Grosvenor House on Sat 3 April. Call 14:00. £350 as agreed.\n\nKim"]);
        Queue::assertPushed(ReadDocument::class, fn ($job) => $job->documentId === $conf->id);

        $this->gmail(['b1' => [$this->raw(['Kim Ward <kim@venueco.example>', 'Booking confirmation', "Hi Sam, you're booked for the Summer Ball on Sat 5 June. Call 14:00. Kim"]), 'TB']]);
        $short = app(GmailScanner::class)->store('b1', 'job_documents');
        Queue::assertPushed(ReadDocument::class, fn ($job) => $job->documentId === $short->id);

        // A hotel's confirmation isn't read, from a hotel company or about your own stay.
        $hotel = $this->mailbox(['Premier Inn <reservations@premierinn.com>', 'Booking confirmation', "Hi Sam, you're booked for 12 March 2027 at Premier Inn Leeds."]);
        $stay = $this->mailbox(['The Grand Hotel <stay@grandhotel.example>', 'Booking confirmation - 19 May', "Dear Mr Taylor,\n\nWe are pleased to confirm your stay from 19 May to 22 May."]);
        Queue::assertNotPushed(ReadDocument::class, fn ($job) => in_array($job->documentId, [$hotel->id, $stay->id], true));
    }

    public function test_the_rules_leave_alone_work_that_has_passed_and_held_mail(): void
    {
        $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'Availability', "Hi Sam,\n\nAre you available 12-14 January 2027?\n\nJo"]);
        app(DocumentIntake::class)->store($this->raw(self::HOLD), 'hold.eml', 'message/rfc822', 'email', ['held' => true, 'held_reason' => 'Unknown sender']);

        $this->assertSame(0, Opportunity::query()->count());
    }

    // ---------------------------------------------------------------- the AI reading

    /** What the reader answers for the offer email: every detail with its words, one of them not in the email. */
    private function offerReading(array $over = []): array
    {
        $q = fn (?string $value, ?string $quote) => ['value' => $value, 'quote' => $quote];

        return [
            'kind' => 'opportunity', 'summary' => 'Acme Events offers the LX tech role on the Acme Conference, 19-22 May, at £320 a day.',
            'job_link' => ['job_id' => null, 'reason' => null], 'new_job' => null, 'facts' => [], 'po' => null, 'receipt' => null,
            'opportunity' => array_replace([
                'status_hint' => $q('offered', 'We\'d like to offer you the LX tech role on the Acme Conference'),
                'hold_rank' => $q(null, null),
                'title' => $q('Acme Conference', 'the Acme Conference at ExCeL London'),
                'client' => $q('Acme Events', 'Acme Events'),
                'contact' => ['name' => null, 'email' => 'alex@acme-events.example', 'quote' => 'From: alex@acme-events.example'],
                'role' => $q('LX operator', 'the LX operator role'), // not what the email says
                'venue' => $q('ExCeL London', 'ExCeL London, E16 1XL'),
                'postcode' => $q('E16 1XL', 'ExCeL London, E16 1XL'),
                'dates' => [
                    ['date' => '2027-05-19', 'kind' => 'rig', 'quote' => 'Rig day 19 May'],
                    ['date' => '2027-05-20', 'kind' => 'work', 'quote' => 'show days 20-21 May'],
                    ['date' => '2027-05-21', 'kind' => 'work', 'quote' => 'show days 20-21 May'],
                    ['date' => '2027-05-22', 'kind' => 'derig', 'quote' => 'derig 22 May'],
                ],
                'rate' => ['amount_minor' => 32000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'The rate is £320 per day'],
                'reply_by' => $q('2027-05-01', 'Please confirm by 1 May'),
                'po_number' => $q(null, null),
            ], $over),
        ];
    }

    public function test_with_ai_on_an_offer_from_the_mailbox_is_read_once_with_its_rate_and_rig_and_derig_days_each_checked(): void
    {
        Setting::put('ai_extraction', 'on');
        $newJob = ['title' => 'Acme Conference', 'client' => 'Acme Events', 'starts_on' => '2027-05-19', 'ends_on' => '2027-05-22', 'venue' => 'ExCeL London', 'postcode' => 'E16 1XL', 'quote' => 'Rig day 19 May'];
        $this->ai->responses = [FakeAiClient::json(['new_job' => $newJob] + $this->offerReading())];

        $doc = $this->mailbox(self::OFFER)->fresh();

        $this->assertCount(1, $this->ai->requests, 'One reading of the email: no second call for the opportunity');
        $schema = $this->ai->requests[0]['outputConfig']['format']['schema'];
        $this->assertSame(DocumentReader::OPPORTUNITY_STATUSES, $schema['properties']['opportunity']['anyOf'][1]['properties']['status_hint']['properties']['value']['enum']);
        $this->assertSame('opportunity', $doc->kind);
        $this->assertSame('Job enquiry, hold or offer', Document::KINDS['opportunity']);

        // The reading keeps each detail with its words and whether they're in the email.
        $x = $doc->extraction['opportunity'];
        $this->assertTrue($x['rate']['verified']);
        $this->assertTrue($x['status_hint']['verified']);
        $this->assertFalse($x['role']['verified'], '“the LX operator role” isn’t in the email');
        $this->assertSame(['role'], $x['unverified']);
        $this->assertSame(['rig', 'work', 'work', 'derig'], array_column($x['dates'], 'kind'));
        $this->assertNull($doc->extraction['new_job'], 'Work offered isn’t a new job until you accept it');

        $o = Opportunity::query()->sole();
        $this->assertSame(['offered', 'ai', 'email', 'Acme Conference', 'Acme Events'], [$o->status, $o->found_by, $o->source, $o->title, $o->client_name]);
        $this->assertSame([32000, 'GBP', 'day', '2027-05-01'], [$o->rate_minor, $o->rate_currency, $o->rate_unit, $o->reply_by->format('Y-m-d')]);
        $this->assertSame([['date' => '2027-05-19', 'kind' => 'rig'], ['date' => '2027-05-20', 'kind' => 'work'], ['date' => '2027-05-21', 'kind' => 'work'], ['date' => '2027-05-22', 'kind' => 'derig']], $o->dates);
        $this->assertSame(['role'], $o->unverifiedFields(), 'The detail whose words aren’t in the email is marked');
        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_with_ai_on_a_confirmation_then_a_cancellation_in_the_gmail_thread_move_it_forward_then_to_lost(): void
    {
        Setting::put('ai_extraction', 'on');
        $hint = fn (string $status, string $quote) => ['status_hint' => ['value' => $status, 'quote' => $quote]];
        // Short, and with none of the usual words: read because it's a reply in the opportunity's conversation.
        $cancel = ['Alex Smith <alex@acme-events.example>', 'Re: Acme Conference - LX tech', "Hi Sam,\n\nBad news, I'm afraid: the client has pulled the event.\n\nAlex"];
        $this->ai->responses = [
            FakeAiClient::json($this->offerReading()),
            FakeAiClient::json(['kind' => 'job_invite'] + $this->offerReading($hint('confirmed', 'you\'re confirmed for the Acme Conference'))),
            FakeAiClient::json(['kind' => 'email'] + $this->offerReading($hint('lost', 'the client has pulled the event') + ['rate' => null, 'dates' => []])),
        ];
        $this->gmail([
            'o1' => [$this->raw(self::OFFER), 'T9'],
            'o2' => [$this->raw(self::CONFIRMATION, [], 'Tue, 23 Feb 2027 09:00:00 +0000'), 'T9'],
            'o3' => [$this->raw($cancel, [], 'Wed, 24 Feb 2027 09:00:00 +0000'), 'T9'],
        ]);

        app(GmailScanner::class)->store('o1', 'opportunities');
        $o = Opportunity::query()->sole();
        $this->assertSame('offered', $o->status);
        app(GmailScanner::class)->store('o2', 'opportunities');
        $this->assertSame('confirmed', $o->fresh()->status);
        app(GmailScanner::class)->store('o3', 'opportunities');

        $o = Opportunity::query()->sole();
        $this->assertSame('lost', $o->status, 'A short reply calling it off is read too: it’s in the thread');
        $this->assertCount(3, $o->documentIds(), 'Each email in the thread is evidence');
        $this->assertCount(3, $this->ai->requests);
        $this->assertSame(0, WorkJob::query()->count(), 'A confirmation is proposed, never made a job by itself');
    }

    public function test_reading_a_document_by_hand_proposes_the_work_it_offers_too(): void
    {
        Setting::put('ai_extraction', 'on_demand');
        $doc = app(DocumentIntake::class)->store("Booking request from Acme Events\n\nWe'd like to put you down as 1st option for the Smith Productions launch on 3 April at Olympia London.",
            'booking-request.txt', 'text/plain', 'upload');
        $this->assertSame([], $this->ai->requests, 'On demand: nothing is read until you ask');
        $this->ai->responses = [FakeAiClient::json([
            'kind' => 'opportunity', 'summary' => 'A 1st option hold.', 'job_link' => ['job_id' => null, 'reason' => null], 'new_job' => null, 'facts' => [], 'po' => null, 'receipt' => null,
            'opportunity' => [
                'status_hint' => ['value' => 'held', 'quote' => 'put you down as 1st option'], 'hold_rank' => ['value' => 1, 'quote' => '1st option'],
                'title' => ['value' => 'Smith Productions launch', 'quote' => 'the Smith Productions launch'], 'client' => ['value' => 'Acme Events', 'quote' => 'Booking request from Acme Events'],
                'contact' => ['name' => null, 'email' => null, 'quote' => null], 'role' => ['value' => null, 'quote' => null],
                'venue' => ['value' => 'Olympia London', 'quote' => 'at Olympia London'], 'postcode' => ['value' => null, 'quote' => null],
                'dates' => [['date' => '2027-04-03', 'kind' => 'work', 'quote' => 'on 3 April']], 'rate' => null,
                'reply_by' => ['value' => null, 'quote' => null], 'po_number' => ['value' => null, 'quote' => null],
            ],
        ])];

        app(DocumentReader::class)->read($doc); // as the document page's "Read now" does

        $o = Opportunity::query()->sole();
        $this->assertSame(['held', 1, 'upload', 'Olympia London', '2027-04-03'], [$o->status, $o->hold_rank, $o->source, $o->venue, $o->starts_on->format('Y-m-d')]);
        $this->assertSame([], $o->unverifiedFields());
    }

    public function test_the_ai_is_asked_only_what_it_needs(): void
    {
        Setting::put('ai_extraction', 'on');
        Queue::fake();

        // A PO forwarded with a short note: the attachment is read, the note isn't.
        $po = app(DocumentIntake::class)->store($this->raw(['Alex Smith <alex@acme-events.example>', 'PO for the Acme Conference', "Hi Sam,\n\nPlease find our PO attached.\n\nAlex"], [], 'Mon, 22 Feb 2027 09:00:00 +0000', 'PO-4471 Acme Events. Total 1,280.00 GBP.'),
            'po.eml', 'message/rfc822', 'email');
        Queue::assertPushed(ReadDocument::class, 1);
        Queue::assertPushed(ReadDocument::class, fn ($job) => $job->documentId !== $po->id);

        // An email asking about work is read, however short.
        $ask = $this->mailbox(['Jo Bloggs <jo@crewco.example>', 'Quick one', 'Are you free on 8 May?']);
        Queue::assertPushed(ReadDocument::class, fn ($job) => $job->documentId === $ask->id);

        // Sent by Telegram: read once, by the bot (which replies with what it found), not here as well.
        app(DocumentIntake::class)->store('A 1st option for the Smith Productions launch on 3 April.', 'hold.txt', 'text/plain', 'telegram');
        Queue::assertPushed(ReadDocument::class, 2);

        // A hotel booking a Gmail filter forwarded isn't sent to the AI; an email you forwarded yourself is read.
        $long = str_repeat('Check-in from 3pm, check-out by 11am. Breakfast is included. ', 6);
        $this->mailbox(['Premier Inn <reservations@premierinn.example>', 'Your booking at Premier Inn Leeds City', "Your booking is confirmed for 12 March 2027.\n\n{$long}"]);
        Queue::assertPushed(ReadDocument::class, 2);
        $mine = $this->mailbox(['Sam Taylor <sam@example.test>', 'Fwd: Acme', "Worth a look.\n\nFancy the Acme gig again this year? Same as last time, 12 March.\n\n{$long}"]);
        Queue::assertPushed(ReadDocument::class, 3);
        Queue::assertPushed(ReadDocument::class, fn ($job) => $job->documentId === $mine->id);
    }

    public function test_a_broken_rule_never_stops_an_email_being_kept(): void
    {
        $this->app->instance(OpportunityRules::class, new class extends \stdClass
        {
            public function spot(): never
            {
                throw new RuntimeException('boom');
            }
        });
        $doc = $this->mailbox(self::AVAILABILITY);

        $this->assertSame('new', $doc->status);
        $this->assertSame(0, Opportunity::query()->count());
    }

    // ---------------------------------------------------------------- a small stand-in for Gmail's search

    /**
     * Whether a Gmail query matches an email, enough to check what the query lets through: words and "phrases"
     * (whole words, any case, anywhere in the email), OR, -, brackets, and subject:, from:, in:, category:, filename:.
     *
     * @param  array{from: string, subject: string, body: string, labels: list<string>, me: bool}  $mail
     */
    private static function gmailMatches(string $query, array $mail): bool
    {
        preg_match_all('/\(|\)|-(?=[\w("])|[a-z]+:(?=[\w("])|"[^"]*"|[^\s()"]+/i', $query, $m);
        $tokens = $m[0];
        $i = 0;
        $result = self::orExpr($tokens, $i, $mail, null);
        if ($i !== count($tokens)) {
            throw new RuntimeException('Unread query from: '.implode(' ', array_slice($tokens, $i)));
        }

        return $result;
    }

    private static function orExpr(array $t, int &$i, array $mail, ?string $field): bool
    {
        $any = self::andExpr($t, $i, $mail, $field);
        while (($t[$i] ?? null) === 'OR') {
            $i++;
            $any = self::andExpr($t, $i, $mail, $field) || $any;
        }

        return $any;
    }

    private static function andExpr(array $t, int &$i, array $mail, ?string $field): bool
    {
        $all = true;
        $n = 0;
        while (isset($t[$i]) && $t[$i] !== ')' && $t[$i] !== 'OR') {
            $all = self::unary($t, $i, $mail, $field) && $all;
            $n++;
        }
        if ($n === 0) {
            throw new RuntimeException('Empty group at token '.$i);
        }

        return $all;
    }

    private static function unary(array $t, int &$i, array $mail, ?string $field): bool
    {
        if ($t[$i] === '-') {
            $i++;

            return ! self::unary($t, $i, $mail, $field);
        }
        $token = $t[$i++];
        if ($token === '(') {
            $v = self::orExpr($t, $i, $mail, $field);
            if (($t[$i++] ?? null) !== ')') {
                throw new RuntimeException('Unclosed bracket');
            }

            return $v;
        }
        if (str_ends_with($token, ':')) {
            $op = strtolower(substr($token, 0, -1));
            if (($t[$i] ?? null) === '(') {
                $i++;
                $v = self::orExpr($t, $i, $mail, $op);
                if (($t[$i++] ?? null) !== ')') {
                    throw new RuntimeException('Unclosed bracket after '.$op);
                }

                return $v;
            }

            return self::term($op, $t[$i++], $mail);
        }

        return self::term($field, $token, $mail);
    }

    private static function term(?string $field, string $term, array $mail): bool
    {
        $term = trim($term, '"');
        $words = fn (string $s) => preg_split('/[^a-z0-9]+/', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $has = function (string $text) use ($words, $term): bool {
            $hay = $words($text);
            $needle = $words($term);
            for ($k = 0; $k + count($needle) <= count($hay); $k++) {
                if (array_slice($hay, $k, count($needle)) === $needle) {
                    return true;
                }
            }

            return false;
        };

        return match ($field) {
            null => $has($mail['from'].' '.$mail['subject'].' '.$mail['body']),
            'subject' => $has($mail['subject']),
            'from' => $term === 'me' ? $mail['me'] : str_contains(mb_strtolower($mail['from']), mb_strtolower($term)),
            'in', 'category' => in_array($term, $mail['labels'], true),
            'filename' => false,
            default => throw new RuntimeException('Unknown operator '.$field),
        };
    }
}
