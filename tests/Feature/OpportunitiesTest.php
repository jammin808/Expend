<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\Opportunities;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class OpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Hi Sam,\nAre you free 12-14 March for the Acme Awards at Leeds Arena, LS11 8BU? Rig on the first day.\n"
        ."Day rate £350 per day. Please let me know by 2 March 2026.\nThanks, Alex\nAcme Events";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-02-26 09:00:00'));
        Setting::put('business_email', 'sam@example.test'); // the owner's own address
    }

    private function opportunities(): Opportunities
    {
        return app(Opportunities::class);
    }

    /** A read email whose reading found an opportunity block. */
    private function email(array $block, array $o = []): Document
    {
        $from = $o['from'] ?? 'Alex Smith <alex@acme-events.example>';
        $subject = $o['subject'] ?? 'Acme Awards crew 12-14 March';
        $text = $o['text'] ?? "From: {$from}\nTo: sam@example.test\nDate: Fri, 20 Feb 2026 10:00:00 +0000\nSubject: {$subject}\n\n".($o['body'] ?? self::BODY);
        $doc = Document::query()->create([
            'parent_id' => $o['parent_id'] ?? null, 'source' => $o['source'] ?? 'gmail', 'original_name' => $o['name'] ?? $subject.'.eml',
            'mime' => $o['mime'] ?? 'message/rfc822', 'size' => strlen((string) $text), 'sha256' => hash('sha256', Str::random(40)), 'stored_path' => 'documents/test.eml',
            'email_from' => $from, 'email_subject' => $subject, 'received_at' => $o['at'] ?? '2026-02-20 10:00:00',
            'text' => ($o['text_status'] ?? 'extracted') === 'extracted' ? $text : null, 'text_status' => $o['text_status'] ?? 'extracted',
            'kind' => 'email', 'status' => 'extracted', 'extracted_at' => now(), 'work_job_id' => $o['work_job_id'] ?? null,
            'extraction' => ['summary' => 'Work', 'opportunity' => $block],
        ]);
        if (isset($o['thread'])) {
            GmailMessage::query()->create(['message_id' => Str::random(16), 'thread_id' => $o['thread'], 'search' => 'job_documents', 'document_id' => $doc->id, 'status' => 'stored']);
        }

        return $doc;
    }

    private function block(array $over = []): array
    {
        $q = fn (string $value, string $quote) => ['value' => $value, 'quote' => $quote, 'verified' => true];

        return array_replace([
            'status_hint' => $q('enquiry', 'Are you free 12-14 March'),
            'hold_rank' => null,
            'title' => $q('Acme Awards', 'the Acme Awards at Leeds Arena'),
            'client' => $q('Acme Events', 'Acme Events'),
            'contact' => ['name' => 'Alex Smith', 'email' => 'alex@acme-events.example', 'quote' => 'From: Alex Smith <alex@acme-events.example>', 'verified' => true],
            'role' => null,
            'venue' => $q('Leeds Arena', 'Leeds Arena, LS11 8BU'),
            'postcode' => $q('LS11 8BU', 'Leeds Arena, LS11 8BU'),
            'dates' => [
                ['date' => '2026-03-12', 'kind' => 'rig', 'quote' => 'Are you free 12-14 March', 'verified' => true],
                ['date' => '2026-03-13', 'kind' => 'work', 'quote' => 'Are you free 12-14 March', 'verified' => true],
                ['date' => '2026-03-14', 'kind' => 'work', 'quote' => 'Are you free 12-14 March', 'verified' => true],
            ],
            'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'Day rate £350 per day', 'verified' => true],
            'reply_by' => $q('2026-03-02', 'Please let me know by 2 March 2026'),
            'po_number' => null,
        ], $over);
    }

    private function hint(string $status, string $quote): array
    {
        return ['status_hint' => ['value' => $status, 'quote' => $quote, 'verified' => true]];
    }

    /** @return list<array{0:string,1:string}> */
    private function days(WorkJob $job): array
    {
        return $job->days()->get()->map(fn ($d) => [$d->on_date->format('Y-m-d'), $d->kind])->all();
    }

    // ---------------------------------------------------------------- reading

    public function test_an_availability_check_becomes_an_enquiry_with_every_detail_checked_against_the_email(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events Ltd']);
        $doc = $this->email($this->block(['role' => ['value' => 'Sound No.2', 'quote' => 'we need a sound No.2', 'verified' => true]]));

        $o = $this->opportunities()->fromDocument($doc);

        $this->assertSame(['enquiry', 'Acme Awards', 'Acme Events', $acme->id, 'Alex Smith', 'alex@acme-events.example'],
            [$o->status, $o->title, $o->client_name, $o->client_id, $o->contact_name, $o->contact_email]);
        $this->assertSame([['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-13', 'kind' => 'work'], ['date' => '2026-03-14', 'kind' => 'work']], $o->dates);
        $this->assertSame(['2026-03-12', '2026-03-14', '2026-03-02'], [$o->starts_on->format('Y-m-d'), $o->ends_on->format('Y-m-d'), $o->reply_by->format('Y-m-d')]);
        $this->assertSame([35000, 'GBP', 'day', 'Leeds Arena', 'LS11 8BU'], [$o->rate_minor, $o->rate_currency, $o->rate_unit, $o->venue, $o->postcode]);
        $this->assertSame(['gmail', 'ai', $doc->id], [$o->source, $o->found_by, $o->documentIds()[0]]);

        // Each date is checked in its own quote: "12-14 March" gives the 13th too. The role's words aren't in the email.
        $this->assertTrue(collect($o->evidenceFor('dates'))->every(fn ($e) => $e['verified'] === true));
        $this->assertSame(['role'], $o->unverifiedFields());
        $this->assertFalse($o->evidenceFor('role')[0]['verified']);
        $this->assertSame(100, $o->confidence);

        $found = AuditEvent::query()->where('action', 'opportunity.found')->sole();
        $this->assertSame(['ai', ['role']], [$found->actor, $found->after['unchecked']]);
        $this->assertSame(0, WorkJob::query()->count(), 'Nothing is made a job by itself');
    }

    public function test_details_the_reader_got_wrong_are_marked_unchecked(): void
    {
        $doc = $this->email($this->block([
            'rate' => ['amount_minor' => 45000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'Day rate £350 per day', 'verified' => true],
            'dates' => [['date' => '2026-03-15', 'kind' => 'work', 'quote' => 'Are you free 12-14 March', 'verified' => true]],
            'client' => ['value' => 'Smith Productions', 'quote' => 'Acme Events', 'verified' => true],
            'venue' => ['value' => 'Leeds Arena', 'quote' => 'Leeds Arena, LS11 8BU', 'verified' => false],
        ]));

        $o = $this->opportunities()->fromDocument($doc);

        $this->assertEqualsCanonicalizing(['rate', 'dates', 'client', 'venue'], $o->unverifiedFields(),
            'An amount not in its quote, a date outside it, a client not in it, and what the reader itself doubted');
        $this->assertLessThan(100, $o->confidence);
    }

    public function test_a_scan_cant_be_checked_word_for_word_so_nothing_from_it_counts_as_checked(): void
    {
        $doc = $this->email($this->block(), ['text_status' => 'image_only', 'mime' => 'image/jpeg', 'name' => 'booking.jpg']);

        $o = $this->opportunities()->fromDocument($doc);

        $this->assertContains('dates', $o->unverifiedFields());
        $this->assertContains('rate', $o->unverifiedFields());
        $this->assertContains('client', $o->unverifiedFields());
        $this->assertLessThan(50, $o->confidence);
    }

    public function test_no_block_or_nothing_to_go_on_gives_no_opportunity(): void
    {
        $plain = $this->email($this->block());
        $plain->update(['extraction' => ['summary' => 'A newsletter']]);
        $empty = $this->email(['status_hint' => 'enquiry', 'dates' => [], 'title' => null, 'client' => null]);

        $this->assertNull($this->opportunities()->fromDocument($plain->fresh()));
        $this->assertNull($this->opportunities()->fromDocument($empty));
        $this->assertSame(0, Opportunity::query()->count());
    }

    // ---------------------------------------------------------------- a thread over time

    public function test_later_emails_in_the_thread_move_it_forward_and_never_back(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(), ['thread' => 't1', 'at' => '2026-02-20 10:00:00']));
        $pencil = "I'll pencil you in as 1st option for the Acme Awards.";
        $held = $this->opportunities()->fromDocument($this->email($this->hint('held', 'pencil you in as 1st option') + [
            'hold_rank' => ['value' => 1, 'quote' => 'pencil you in as 1st option', 'verified' => true],
            'client' => ['value' => 'Acme Events', 'quote' => 'Acme Events', 'verified' => true],
        ], ['thread' => 't1', 'at' => '2026-02-22 10:00:00', 'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => $pencil."\nAcme Events"]));
        $this->assertSame([$o->id, 'held', 1], [$held->id, $held->status, $held->hold_rank]);

        $offer = $this->email($this->hint('offered', 'We’d like to offer you the job'), ['thread' => 't1', 'at' => '2026-02-24 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'We’d like to offer you the job at £350 per day.']);
        $this->assertSame('offered', $this->opportunities()->fromDocument($offer)->status);

        // An earlier email read late, and the same email read again, change nothing but the evidence.
        $late = $this->email($this->hint('enquiry', 'Are you around in March'), ['thread' => 't1', 'at' => '2026-02-21 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'Are you around in March?']);
        $this->assertSame('offered', $this->opportunities()->fromDocument($late)->status);
        $events = AuditEvent::query()->count();
        $this->assertSame('offered', $this->opportunities()->fromDocument($offer->fresh())->status);
        $this->assertSame($events, AuditEvent::query()->count(), 'Reading the same email again is no change');

        $this->opportunities()->fromDocument($this->email($this->hint('confirmed', 'You’re confirmed'), ['thread' => 't1', 'at' => '2026-02-25 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'You’re confirmed for the Acme Awards.']));
        $o = $this->opportunities()->fromDocument($this->email($this->hint('enquiry', 'Are you free for the get-out'), ['thread' => 't1', 'at' => '2026-02-26 08:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'Are you free for the get-out too?']));

        $this->assertSame('confirmed', $o->status, 'A question after the booking doesn’t undo it');
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertCount(6, $o->documentIds());
        $this->assertSame('2026-02-26 08:00', $o->last_seen_at->format('Y-m-d H:i'));
        $this->assertSame('2026-02-20 10:00', $o->first_seen_at->format('Y-m-d H:i'));
    }

    public function test_went_elsewhere_closes_it_only_from_a_newer_email_and_a_newer_offer_reopens_it(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(), ['thread' => 't2', 'at' => '2026-02-20 10:00:00']));
        $lost = $this->opportunities()->fromDocument($this->email($this->hint('lost', 'gone with someone else'), ['thread' => 't2', 'at' => '2026-02-23 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'Sorry, they have gone with someone else.']));
        $this->assertSame([$o->id, 'lost'], [$lost->id, $lost->status]);

        $older = $this->opportunities()->fromDocument($this->email($this->hint('offered', 'offer you the job'), ['thread' => 't2', 'at' => '2026-02-21 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'We would like to offer you the job.']));
        $this->assertSame('lost', $older->status, 'An older offer read late doesn’t bring it back');

        $back = $this->opportunities()->fromDocument($this->email($this->hint('offered', 'it is back on'), ['thread' => 't2', 'at' => '2026-02-25 10:00:00',
            'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'Good news, it is back on if you want it.']));
        $this->assertSame('offered', $back->status);
    }

    public function test_your_decline_or_dismissal_is_never_changed_by_a_later_email(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(['status_hint' => ['value' => 'offered', 'quote' => 'Are you free 12-14 March']]), ['thread' => 't3']));
        $this->opportunities()->decline($o, 'Already booked that week');

        $later = $this->email($this->hint('confirmed', 'confirmed at £400 per day') + [
            'rate' => ['amount_minor' => 40000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'confirmed at £400 per day', 'verified' => true],
        ], ['thread' => 't3', 'at' => '2026-02-24 10:00:00', 'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'You are confirmed at £400 per day.']);
        $o = $this->opportunities()->fromDocument($later);

        $this->assertSame(['declined', 35000, 'Already booked that week', 'you'], [$o->status, $o->rate_minor, $o->note, $o->decided_by]);
        $this->assertContains($later->id, $o->documentIds(), 'The email is kept as evidence all the same');
        $this->assertStringStartsWith('Evidence only', AuditEvent::query()->where('action', 'opportunity.updated')->latest('id')->value('reason'));

        // Reopened, it's back at the furthest stage its emails reached.
        $o = $this->opportunities()->reopen($o);
        $this->assertSame(['confirmed', null, null], [$o->status, $o->decided_at, $o->decided_by]);

        $o = $this->opportunities()->dismiss($o, 'Not for me');
        $this->assertSame('dismissed', $o->status);
        $this->assertSame(['opportunity.declined', 'opportunity.reopened', 'opportunity.dismissed'],
            AuditEvent::query()->where('subject_type', 'Opportunity')->whereIn('action', ['opportunity.declined', 'opportunity.reopened', 'opportunity.dismissed'])->orderBy('id')->pluck('action')->all());

        try {
            $this->opportunities()->decline($o, null, 'ai');
            $this->fail('Automation declined');
        } catch (InvalidArgumentException) {
        }
    }

    public function test_a_forwarded_copy_joins_the_same_opportunity_and_the_original_sender_is_the_contact(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(), ['thread' => 't4']));

        $forward = "---------- Forwarded message ---------\nFrom: Alex Smith <alex@acme-events.example>\nDate: Tue, 24 Feb 2026\n"
            ."Subject: Acme Awards crew 12-14 March\n\nWe’d like to offer you the Acme Awards. Acme Events";
        $copy = $this->email([
            'status_hint' => ['value' => 'offered', 'quote' => 'We’d like to offer you the Acme Awards', 'verified' => true],
            'client' => ['value' => 'Acme Events', 'quote' => 'Acme Events', 'verified' => true],
        ], ['source' => 'email', 'from' => 'sam@example.test', 'subject' => 'Fwd: Acme Awards crew 12-14 March', 'body' => $forward, 'at' => '2026-02-24 12:00:00']);

        $joined = $this->opportunities()->fromDocument($copy);
        $this->assertSame([$o->id, 'offered', 'alex@acme-events.example'], [$joined->id, $joined->status, $joined->contact_email]);

        // On its own, the forwarded email's contact is the original sender, not you.
        $alone = $this->email(['title' => ['value' => 'Acme Gala', 'quote' => 'offer you the Acme Awards', 'verified' => true], 'status_hint' => 'offered'],
            ['source' => 'email', 'from' => 'sam@example.test', 'subject' => 'Fwd: Gala', 'body' => $forward]);
        $other = $this->opportunities()->fromDocument($alone);
        $this->assertNotSame($o->id, $other->id);
        $this->assertSame(['Alex Smith', 'alex@acme-events.example'], [$other->contact_name, $other->contact_email]);
        $this->assertSame([['type' => 'document', 'id' => $alone->id, 'field' => 'contact', 'value' => 'Alex Smith <alex@acme-events.example>',
            'quote' => 'From: Alex Smith <alex@acme-events.example>', 'verified' => true]], $other->evidenceFor('contact'));
        $this->assertSame('offered', $other->status);
        $this->assertContains('status', $other->unverifiedFields(), 'A status with no quote isn’t checked');
    }

    public function test_the_same_client_on_overlapping_dates_is_the_same_work_and_another_client_is_not(): void
    {
        $a = $this->opportunities()->fromDocument($this->email($this->block(), ['subject' => 'Availability']));
        $b = $this->opportunities()->fromDocument($this->email($this->hint('confirmed', 'Booking confirmed for 13-14 March') + [
            'client' => ['value' => 'Acme Events Ltd', 'quote' => 'Acme Events Ltd', 'verified' => true],
            'dates' => [['date' => '2026-03-13', 'kind' => 'derig', 'quote' => 'Booking confirmed for 13-14 March', 'verified' => true],
                ['date' => '2026-03-14', 'kind' => 'work', 'quote' => 'Booking confirmed for 13-14 March', 'verified' => true]],
        ], ['subject' => 'Booking confirmation', 'from' => 'Bookings <bookings@acme-events.example>', 'at' => '2026-02-23 10:00:00',
            'body' => "Booking confirmed for 13-14 March.\nAcme Events Ltd"]));

        $this->assertSame([$a->id, 'confirmed'], [$b->id, $b->status]);
        $this->assertSame([['date' => '2026-03-12', 'kind' => 'rig'], ['date' => '2026-03-13', 'kind' => 'derig'], ['date' => '2026-03-14', 'kind' => 'work']], $b->dates,
            'Some of its days again: only their kinds are taken');

        $c = $this->opportunities()->fromDocument($this->email([
            'status_hint' => 'enquiry', 'client' => ['value' => 'Smith Productions', 'quote' => 'Smith Productions', 'verified' => true],
            'dates' => [['date' => '2026-03-13', 'kind' => 'work', 'quote' => 'free on 13 March', 'verified' => true]],
        ], ['subject' => 'Availability', 'from' => 'Jo Bloggs <jo@smith.example>', 'body' => "Are you free on 13 March?\nSmith Productions"]));
        $this->assertNotSame($a->id, $c->id);
        $this->assertSame(2, Opportunity::query()->count());
    }

    public function test_a_title_made_from_the_subject_gives_way_to_the_readers_but_the_readers_title_stays(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(['title' => null]), ['thread' => 't6', 'subject' => 'RE: Fwd: Acme Awards crew 12-14 March']));
        $this->assertSame('Acme Awards crew 12-14 March', $o->title);

        $o = $this->opportunities()->fromDocument($this->email($this->hint('held', 'pencil you in') + ['title' => ['value' => 'Acme Awards 2026', 'quote' => 'Acme Awards', 'verified' => true]],
            ['thread' => 't6', 'at' => '2026-02-22 10:00:00', 'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'I will pencil you in for the Acme Awards.']));
        $this->assertSame('Acme Awards 2026', $o->title);

        $o = $this->opportunities()->fromDocument($this->email($this->hint('offered', 'offer you the Acme Awards') + ['title' => ['value' => 'Acme Awards crew', 'quote' => 'Acme Awards', 'verified' => true]],
            ['thread' => 't6', 'at' => '2026-02-24 10:00:00', 'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'We would like to offer you the Acme Awards.']));
        $this->assertSame(['Acme Awards 2026', 'offered'], [$o->title, $o->status]);
    }

    public function test_a_newer_email_moving_the_dates_replaces_them(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block(), ['thread' => 't5']));
        $moved = $this->opportunities()->fromDocument($this->email($this->hint('offered', 'moved to 19-20 March') + [
            'dates' => [['date' => '2026-03-19', 'kind' => 'work', 'quote' => 'moved to 19-20 March', 'verified' => true],
                ['date' => '2026-03-20', 'kind' => 'work', 'quote' => 'moved to 19-20 March', 'verified' => true]],
        ], ['thread' => 't5', 'at' => '2026-02-24 10:00:00', 'subject' => 'Re: Acme Awards crew 12-14 March', 'body' => 'The awards have moved to 19-20 March.']));

        $this->assertSame([$o->id, '2026-03-19', '2026-03-20'], [$moved->id, $moved->starts_on->format('Y-m-d'), $moved->ends_on->format('Y-m-d')]);
        $updated = AuditEvent::query()->where('action', 'opportunity.updated')->latest('id')->first();
        $this->assertSame('2026-03-12', $updated->before['starts_on']);
        $this->assertSame('2026-03-19', $updated->after['starts_on']);
    }

    public function test_an_opportunity_whose_email_is_on_a_job_is_that_job(): void
    {
        $job = WorkJob::query()->create(['title' => 'Awards', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-14']);
        $o = $this->opportunities()->fromDocument($this->email($this->block(), ['work_job_id' => $job->id]));

        $this->assertSame([$job->id, 'confirmed', null], [$o->work_job_id, $o->status, $o->decided_by]);
        // Recorded as the matcher (the actor vocabulary; 'rules' was its old name).
        $this->assertSame('matcher', AuditEvent::query()->where('action', 'opportunity.linked_to_job')->value('actor'));
        $this->assertFalse($o->isAccepted(), 'Matched by the rules, not made a job by you');
    }

    // ---------------------------------------------------------------- your decisions

    public function test_accepting_makes_the_job_with_its_days_and_links_its_emails(): void
    {
        $email = $this->email(['status_hint' => null, 'title' => null, 'client' => null, 'dates' => []], ['subject' => 'Acme Expo crew', 'body' => 'See the attached offer. Acme Events']);
        $email->update(['extraction' => ['summary' => 'Cover note']]);
        $pdfText = "Offer: Acme Expo, NEC Birmingham B40 1NT.\nRig 10 May 2026, show 11 May 2026, de-rig 15 May 2026.\nRate £350 per day.";
        $offer = $this->email([
            'status_hint' => ['value' => 'offered', 'quote' => 'Offer: Acme Expo', 'verified' => true],
            'title' => ['value' => 'Acme Expo', 'quote' => 'Offer: Acme Expo', 'verified' => true],
            'client' => null,
            'venue' => ['value' => 'NEC Birmingham', 'quote' => 'NEC Birmingham B40 1NT', 'verified' => true],
            'postcode' => ['value' => 'B40 1NT', 'quote' => 'NEC Birmingham B40 1NT', 'verified' => true],
            'dates' => [['date' => '2026-05-10', 'kind' => 'rig', 'quote' => 'Rig 10 May 2026', 'verified' => true],
                ['date' => '2026-05-11', 'kind' => 'work', 'quote' => 'show 11 May 2026', 'verified' => true],
                ['date' => '2026-05-15', 'kind' => 'derig', 'quote' => 'de-rig 15 May 2026', 'verified' => true]],
            'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'Rate £350 per day', 'verified' => true],
        ], ['parent_id' => $email->id, 'text' => $pdfText, 'name' => 'offer.pdf', 'mime' => 'application/pdf', 'subject' => 'Acme Expo crew']);
        $o = $this->opportunities()->fromDocument($offer);
        $this->assertSame([$email->id, 'Alex Smith'], [$o->evidenceFor('contact')[0]['id'], $o->contact_name], 'An attachment’s contact is its email’s sender');

        $job = $this->opportunities()->accept($o, ['client_name' => 'Acme Events'], 'you', $links);

        $this->assertSame([['2026-05-10', 'rig'], ['2026-05-11', 'work'], ['2026-05-15', 'derig']], $this->days($job));
        $this->assertSame(['Acme Expo', 'Acme Events', 'NEC Birmingham', 'B40 1NT', 'booked'], [$job->title, $job->client->name, $job->location, $job->postcode, $job->status]);
        $this->assertStringContainsString('Rate: £350.00 a day', $job->notes);
        $this->assertStringContainsString('Contact: Alex Smith <alex@acme-events.example>', $job->notes);
        $this->assertSame(['linked' => 1, 'missed' => []], $links);
        $this->assertSame([$job->id, 'you'], [$email->fresh()->work_job_id, $email->fresh()->job_linked_by]);
        $this->assertSame($job->id, $offer->fresh()->work_job_id, 'The attachment goes with its email');

        $o->refresh();
        $this->assertSame(['confirmed', $job->id, 'you'], [$o->status, $o->work_job_id, $o->decided_by]);
        $this->assertTrue($o->isAccepted());
        $this->assertSame('you', AuditEvent::query()->where('action', 'opportunity.accepted')->value('actor'));
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'job.created')->sole()->after['evidence']);

        foreach ([fn () => $this->opportunities()->accept($o, []), fn () => $this->opportunities()->decline($o), fn () => $this->opportunities()->reopen($o)] as $again) {
            try {
                $again();
                $this->fail('A job you made was changed');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_only_you_can_accept_and_your_dates_replace_its_days(): void
    {
        $o = $this->opportunities()->fromDocument($this->email($this->block()));
        try {
            $this->opportunities()->accept($o, [], 'rules');
            $this->fail('The rules made a job');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, WorkJob::query()->count());

        $job = $this->opportunities()->accept($o, ['starts_on' => '2026-03-11', 'ends_on' => '2026-03-14', 'title' => 'Acme Awards 2026'], 'assistant');
        $this->assertSame(['2026-03-11', '2026-03-12', '2026-03-13', '2026-03-14'], array_column($this->days($job), 0));
        $this->assertSame(['Acme Awards 2026', 'Acme Events', 'assistant'], [$job->title, $job->client->name, $o->fresh()->decided_by]);
    }

    public function test_accepting_one_with_no_dates_asks_for_them(): void
    {
        $o = $this->opportunities()->record(['title' => 'Acme tour', 'client_name' => 'Acme Events']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('start date');
        $this->opportunities()->accept($o, []);
    }

    public function test_work_you_tell_it_about_is_recorded_once(): void
    {
        $o = $this->opportunities()->record(['title' => 'Smith launch', 'client_name' => 'Smith Productions', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-02',
            'status' => 'held', 'hold_rank' => 2, 'rate' => ['amount_minor' => 30000, 'unit' => 'day']], 'telegram');
        $again = $this->opportunities()->record(['title' => 'Smith launch', 'client_name' => 'smith productions', 'starts_on' => '2026-04-01'], 'telegram');

        $this->assertSame($o->id, $again->id);
        $this->assertSame(['held', 2, 'you', 'telegram', 100, 30000, 'GBP', 'day'], [$o->status, $o->hold_rank, $o->found_by, $o->source, $o->confidence, $o->rate_minor, $o->rate_currency, $o->rate_unit]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'opportunity.recorded')->count());
        $this->expectException(InvalidArgumentException::class);
        $this->opportunities()->record(['title' => 'X', 'starts_on' => '2026-04-01'], 'manual', 'ai');
    }

    // ---------------------------------------------------------------- every night

    public function test_the_nightly_refresh_matches_jobs_closes_what_has_passed_and_counts_bookings_with_no_job(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-20 04:27:00'));
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $make = fn (array $f) => Opportunity::query()->create($f + ['title' => 'Work', 'source' => 'gmail', 'found_by' => 'ai', 'evidence' => [],
            'fingerprint' => Str::random(40), 'first_seen_at' => '2026-02-01', 'last_seen_at' => '2026-02-01']);

        $passed = $make(['status' => 'enquiry', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-14']);
        $noReply = $make(['status' => 'offered', 'starts_on' => '2026-04-10', 'ends_on' => '2026-04-10', 'reply_by' => '2026-03-15']);
        $booked = $make(['status' => 'confirmed', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-01']);
        $quiet = $make(['status' => 'enquiry', 'last_seen_at' => '2025-12-01']);
        $job = WorkJob::query()->create(['title' => 'Acme Gala', 'client_id' => $acme->id, 'starts_on' => '2026-04-20', 'ends_on' => '2026-04-21']);
        $held = $make(['status' => 'held', 'client_name' => 'acme events', 'starts_on' => '2026-04-21', 'ends_on' => '2026-04-21']);
        WorkJob::query()->create(['title' => 'Acme day 1', 'client_id' => $acme->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01']);
        WorkJob::query()->create(['title' => 'Acme day 2', 'client_id' => $acme->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-02']);
        $twoJobs = $make(['status' => 'enquiry', 'client_id' => $acme->id, 'client_name' => 'Acme Events', 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01']);
        $unread = $this->email($this->block(['dates' => [['date' => '2026-06-12', 'kind' => 'work', 'quote' => 'x']], 'reply_by' => null]), ['subject' => 'Summer show']);

        $r = $this->opportunities()->refresh();

        $this->assertSame(['read' => 1, 'linked' => 1, 'expired' => 3, 'needs_job' => 1, 'open' => 3], $r);
        $this->assertSame(['expired', 'expired', 'confirmed', 'expired'], [$passed->fresh()->status, $noReply->fresh()->status, $booked->fresh()->status, $quiet->fresh()->status]);
        $this->assertSame([$job->id, 'held'], [$held->fresh()->work_job_id, $held->fresh()->status], 'Matched by its dates and client: a suggestion, where it stands is left alone');
        $this->assertNull($twoJobs->fresh()->work_job_id, 'Two jobs could be it: left for you');
        $this->assertSame(1, Opportunity::query()->needsJob()->count());
        $this->assertSame($unread->id, Opportunity::query()->latest('id')->first()->documentIds()[0], 'A read document that was missed is taken in');
        $this->assertSame(3, AuditEvent::query()->where('action', 'opportunity.expired')->where('actor', 'matcher')->count());

        // Not that job: reopened, it's never matched to it again.
        $this->opportunities()->reopen($held->fresh());
        $this->assertSame(0, $this->opportunities()->refresh()['linked']);
        $this->assertSame([null, [$job->id], 'held'], [$held->fresh()->work_job_id, $held->fresh()->rejected_job_ids, $held->fresh()->status]);
    }

    public function test_the_nightly_refresh_counts_a_match_made_while_taking_in_a_document(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $acme->id, 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-14']);
        $this->email($this->block());

        $r = $this->opportunities()->refresh();

        $this->assertSame([1, 1], [$r['read'], $r['linked']]);
        $this->assertSame($job->id, Opportunity::query()->sole()->work_job_id);
        $this->assertSame(0, $this->opportunities()->refresh()['linked'], 'Taken in again, nothing new is matched');
        $this->assertSame(1, AuditEvent::query()->where('action', 'opportunity.linked_to_job')->count());
    }

    public function test_the_nightly_command_runs_the_refresh(): void
    {
        $this->artisan('ledger:opportunities')->expectsOutputToContain('Opportunities: 0 read documents looked at')->assertSuccessful();
        $this->assertNotNull(Setting::get('opportunities_refreshed_at'));
    }

    // ---------------------------------------------------------------- PO chase

    public function test_jobs_with_no_po_are_listed_with_who_to_ask_and_where_that_came_from(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $smith = Client::query()->create(['name' => 'Smith Productions']);
        $job = fn (string $title, string $from, string $to, array $f = []) => WorkJob::query()->create($f + ['title' => $title, 'starts_on' => $from, 'ends_on' => $to, 'client_id' => $acme->id]);

        $soon = $job('Acme Awards', '2026-03-20', '2026-03-21');
        Opportunity::query()->create(['status' => 'confirmed', 'title' => 'Acme Awards', 'work_job_id' => $soon->id, 'client_id' => $acme->id, 'contact_name' => 'Alex Smith', 'contact_email' => 'alex@acme-events.example',
            'source' => 'gmail', 'found_by' => 'ai', 'fingerprint' => 'a', 'evidence' => [['type' => 'document', 'id' => 1, 'field' => 'contact', 'value' => 'Alex Smith', 'quote' => 'From: Alex Smith <alex@acme-events.example>', 'verified' => true]]]);
        $job('Has a PO', '2026-03-10', '2026-03-10', ['po_number' => 'PO-1']);
        $done = $job('Acme Gala', '2026-02-10', '2026-02-11');
        $this->email($this->block(), ['from' => 'sam@example.test', 'subject' => 'Fwd: gala', 'text' => "From: sam@example.test\nSubject: Fwd: gala\n\nfrom me", 'work_job_id' => $done->id, 'at' => '2026-02-05']);
        $this->email($this->block(), ['from' => 'Jo Bloggs <jo@leeds-arena.example>', 'subject' => 'Gala', 'work_job_id' => $done->id, 'at' => '2026-02-01']);
        $invoiced = $job('Invoiced', '2026-02-01', '2026-02-01');
        Invoice::query()->create(['status' => 'issued', 'total_minor' => 10000, 'work_job_id' => $invoiced->id]);
        $job('Far off', '2026-06-01', '2026-06-01');
        $job('Cancelled', '2026-03-05', '2026-03-05', ['status' => 'cancelled']);
        $job('No PO needed', '2026-03-05', '2026-03-05', ['po_not_required' => true]);
        $running = $job('Smith launch', '2026-02-28', '2026-03-02', ['client_id' => $smith->id]);
        Opportunity::query()->create(['status' => 'enquiry', 'title' => 'Smith other', 'client_id' => $smith->id, 'contact_email' => 'jo@smith.example', 'source' => 'email',
            'found_by' => 'ai', 'fingerprint' => 'b', 'evidence' => [], 'last_seen_at' => '2026-01-10']);

        $list = collect($this->opportunities()->poChase())->keyBy('title');

        $this->assertSame(['Acme Gala', 'Smith launch', 'Acme Awards'], $list->keys()->all(), 'Soonest first, and only those still needing a PO');
        $this->assertSame(['alex@acme-events.example', 'opportunity', 'Starts in 19 days with no PO'], [$list['Acme Awards']['contact']['email'], $list['Acme Awards']['contact']['from'], $list['Acme Awards']['why']]);
        $this->assertSame('From: Alex Smith <alex@acme-events.example>', $list['Acme Awards']['evidence'][0]['quote']);
        $this->assertSame(['alex@acme-events.example', 'Alex Smith', 'client', 'Done and not invoiced, with no PO'],
            [$list['Acme Gala']['contact']['email'], $list['Acme Gala']['contact']['name'], $list['Acme Gala']['contact']['from'], $list['Acme Gala']['why']],
            'Neither your own forward nor an email from the venue (not the client’s company) is who to ask: the client’s contact is');
        $this->assertTrue($list['Acme Gala']['evidence'][0]['verified']);
        $this->assertSame(['jo@smith.example', 'client', 'Under way with no PO'], [$list['Smith launch']['contact']['email'], $list['Smith launch']['contact']['from'], $list['Smith launch']['why']]);
    }

    // ---------------------------------------------------------------- dates in words

    public function test_a_date_is_found_in_a_range_as_well_as_on_its_own(): void
    {
        $this->assertTrue(Opportunities::dateCovered('2026-03-13', 'Are you free 12-14 March'));
        $this->assertTrue(Opportunities::dateCovered('2026-03-13', 'from the 12th to 14th of March'));
        $this->assertTrue(Opportunities::dateCovered('2026-03-13', 'March 12–14'));
        $this->assertTrue(Opportunities::dateCovered('2026-04-01', '30 March - 2 April 2026'));
        $this->assertTrue(Opportunities::dateCovered('2026-03-14', 'on 14 March'));
        $this->assertFalse(Opportunities::dateCovered('2026-03-13', '12-14 March 2025'), 'The wrong year');
        $this->assertFalse(Opportunities::dateCovered('2026-03-15', 'Are you free 12-14 March'));
        $this->assertFalse(Opportunities::dateCovered('2026-03-13', 'Rig on the 13th'), 'No month: can’t be checked');
    }

    public function test_your_own_address_is_never_the_contact(): void
    {
        $doc = $this->email($this->block(['contact' => ['name' => 'Sam', 'email' => 'SAM@example.test', 'quote' => 'To: sam@example.test', 'verified' => true]]));

        $o = $this->opportunities()->fromDocument($doc);

        $this->assertSame('alex@acme-events.example', $o->contact_email, 'The reader picked you; the sender is used instead');
        $this->assertSame(JobCreator::OWNER, ['you', 'assistant', 'telegram']);
    }
}
