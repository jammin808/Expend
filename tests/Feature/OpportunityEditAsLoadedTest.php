<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\Opportunities;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Edit details" on an opportunity saves as it loads: the contact email the reader kept is one the form accepts, an
 * expired one stays expired unless you pick another stage, and only the details you changed become yours.
 */
class OpportunityEditAsLoadedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-02-26 09:00:00'));
        Setting::put('business_email', 'sam@example.test');
        $this->actingAs(User::factory()->create(['name' => 'Sam Taylor', 'email' => 'sam@example.test']));
    }

    // ---------------------------------------------------------------- the contact email

    public function test_the_reader_keeps_only_a_contact_email_the_edit_form_accepts(): void
    {
        // Kept as the form would take it: a "mailto:" in front is dropped, capitals are lowered.
        $o = $this->found('Can you do the Acme Awards? Alex Smith, Alex@Acme-Events.example<mailto:Alex@Acme-Events.example>', [
            'contact' => ['name' => 'Alex Smith', 'email' => 'mailto:Alex@Acme-Events.example', 'quote' => 'Alex Smith, Alex@Acme-Events.example', 'verified' => true],
        ]);
        $this->assertSame('alex@acme-events.example', $o->contact_email);
        $this->saveWithVenue($o)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
        $this->assertSame(['Leeds Arena', 'alex@acme-events.example'], [$o->fresh()->venue, $o->fresh()->contact_email]);

        // One the form would refuse isn't kept; the name still is, and the rest of the form still saves.
        foreach (['alex..smith@acme-events.example', 'alex.@acme-events.example', 'zoë@acme-events.example'] as $i => $odd) {
            $o = $this->found("Can you do the Acme Awards {$i}? Alex Smith, {$odd}", [
                'contact' => ['name' => 'Alex Smith', 'email' => $odd, 'quote' => "Alex Smith, {$odd}", 'verified' => true], 'dates' => self::day('2026-04-1'.$i),
            ], 'Acme Awards '.$i);
            $this->assertSame(['Alex Smith', null], [$o->contact_name, $o->contact_email], $odd);
            $this->saveWithVenue($o)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
            $this->assertSame('Leeds Arena', $o->fresh()->venue, $odd);
        }
    }

    public function test_a_sender_address_the_edit_form_would_refuse_isnt_kept_as_the_contact(): void
    {
        foreach (['Alex Smith <alex..smith@acme-events.example>', 'Alex Smith <alex@acme-events..example>'] as $i => $from) {
            $o = $this->found("From: {$from}\nSubject: Acme Awards crew\n\nAre you free for the Acme Awards {$i}?", ['dates' => self::day('2026-04-1'.$i)], 'Acme Awards '.$i, $from);
            $this->assertSame(['Alex Smith', null], [$o->contact_name, $o->contact_email], $from);
            $this->saveWithVenue($o)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
            $this->assertSame('Leeds Arena', $o->fresh()->venue, $from);
        }

        // With AI reading off, the rules still find the work, with the sender's name alone.
        Setting::put('ai_extraction', 'off');
        $raw = "From: Alex Smith <alex..smith@bright-stages.example>\r\nTo: Sam Taylor <sam@example.test>\r\nDate: Thu, 19 Feb 2026 09:00:00 +0000\r\n"
            ."Subject: Availability - Bright Stages launch 20 March\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            ."Hi Sam,\r\n\r\nAre you available on 20 March for the Bright Stages launch at Leeds Arena?\r\n\r\nThanks,\r\nAlex Smith\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'launch.eml', 'message/rfc822', 'email', ['from' => 'Alex Smith <alex..smith@bright-stages.example>', 'subject' => 'Availability - Bright Stages launch 20 March']);
        $o = Opportunity::query()->where('fingerprint', sha1('document:'.$doc->id))->sole();
        $this->assertSame(['rules', 'Alex Smith', null], [$o->found_by, $o->contact_name, $o->contact_email]);
        $this->saveWithVenue($o)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');

        // An ordinary sender is still the contact.
        $o = $this->found("From: Alex Smith <Alex.Smith@acme-events.example>\nSubject: Acme Awards crew\n\nAre you free?", ['dates' => self::day('2026-04-20')], 'Acme Gala', 'Alex Smith <Alex.Smith@acme-events.example>');
        $this->assertSame(['Alex Smith', 'alex.smith@acme-events.example'], [$o->contact_name, $o->contact_email]);
    }

    public function test_the_reader_and_the_edit_form_use_one_rule_for_the_contact_email(): void
    {
        $o = $this->found('Can you do the Acme Awards?', []);
        $candidates = ['alex@acme-events.example', 'Alex.Smith+crew@acme-events.co.uk', "o'brien@acme-events.example", 'alex..smith@acme-events.example',
            'alex.@acme-events.example', '.alex@acme-events.example', 'alex@acme-events..example', 'alex@-acme-events.example', 'zoë@acme-events.example',
            'alex@localhost', 'alex@acme-events.c', '"alex smith"@acme-events.example', 'mailto:alex@acme-events.example', str_repeat('a', 190).'@acme-events.example'];
        foreach ($candidates as $email) {
            $kept = Opportunities::contactEmail($email);
            $response = $this->saveWith($o, ['contact_email' => $email]);
            if ($kept === mb_strtolower($email)) {
                $response->assertSessionHasNoErrors();
                $this->assertSame($kept, $o->fresh()->contact_email, $email);
            } else {
                $response->assertSessionHasErrors('contact_email');
            }
            if ($kept !== null) {
                $this->saveWith($o, ['contact_email' => $kept])->assertSessionHasNoErrors();
            }
        }
        // The same rule for what you tell the app yourself.
        $this->assertNull(app(Opportunities::class)->record(['title' => 'Acme Gala', 'contact_email' => 'alex..smith@acme-events.example'])->contact_email);
    }

    // ---------------------------------------------------------------- an expired one

    public function test_editing_an_expired_one_keeps_it_expired_unless_you_pick_another_stage(): void
    {
        $o = $this->found("From: Alex Smith <alex@acme-events.example>\nSubject: Acme Awards crew\n\nCan we pencil you as 2nd option for 12 March at the First Direct Arena? Let me know by 20 February.", [
            'status_hint' => self::q('held', 'Can we pencil you as 2nd option'), 'hold_rank' => ['value' => 2, 'quote' => '2nd option', 'verified' => true],
            'reply_by' => self::q('2026-02-20', 'Let me know by 20 February'),
        ], 'Acme Awards', 'Alex Smith <alex@acme-events.example>');
        $this->assertSame(['held', 2], [$o->status, $o->hold_rank]);
        app(Opportunities::class)->refresh();
        $o = $o->fresh();
        $this->assertSame('expired', $o->status);

        // The form offers its stage as it is, and saving it as it loads changes nothing.
        $form = $this->form($o);
        $this->assertSame('expired', $form['status']);
        $this->inertia('PATCH', $o, $form)->assertSessionHasNoErrors()->assertSessionHas('success', 'Nothing changed.');

        // Changing only the venue changes only the venue.
        $last = (int) AuditEvent::query()->max('id');
        $this->saveWithVenue($o)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
        $o = $o->fresh();
        $this->assertSame(['expired', 2, 'Leeds Arena'], [$o->status, $o->hold_rank, $o->venue]);
        $this->assertSame(['venue'], $o->owner_fields);
        $edit = AuditEvent::query()->where('id', '>', $last)->where('action', 'opportunity.edited')->sole();
        $this->assertSame(['venue' => 'First Direct Arena'], $edit->before);
        $this->assertSame(['venue' => 'Leeds Arena'], $edit->after);

        // Picking another stage is yours.
        $this->saveWith($o, ['status' => 'offered'])->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
        $this->assertSame('offered', $o->fresh()->status);
        $this->assertEqualsCanonicalizing(['venue', 'status'], $o->fresh()->owner_fields);

        // "Passed with no answer" is only kept, never set from the form.
        $this->saveWith($o, ['status' => 'expired'])->assertSessionHasErrors('status');
        $this->assertSame('offered', $o->fresh()->status);
    }

    // ---------------------------------------------------------------- only what you changed

    public function test_saving_the_form_as_it_loads_makes_nothing_yours(): void
    {
        // The reader read "Acme Events"; your list has "Acme Events Ltd", so the page shows that name.
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $o = $this->found("From: Alex Smith <alex@acme-events.example>\nSubject: Acme Awards crew\n\nAre you free 12-13 March for the Acme Awards at Leeds Arena, LS11 8BU? Day rate £350 per day.\nAcme Events", [
            'status_hint' => self::q('held', 'Are you free 12-13 March'), 'hold_rank' => ['value' => 1, 'quote' => '12-13 March', 'verified' => false],
            'client' => self::q('Acme Events', 'Acme Events'), 'venue' => self::q('Leeds Arena', 'at Leeds Arena'), 'postcode' => self::q('LS11 8BU', 'LS11 8BU'),
            'dates' => [['date' => '2026-03-12', 'kind' => 'work', 'quote' => '12-13 March', 'verified' => true], ['date' => '2026-03-13', 'kind' => 'work', 'quote' => '12-13 March', 'verified' => true]],
            'rate' => ['amount_minor' => 35000, 'currency' => 'GBP', 'unit' => 'day', 'quote' => 'Day rate £350 per day', 'verified' => true],
        ], 'Acme Awards', 'Alex Smith <alex@acme-events.example>');
        $this->assertSame([$client->id, 'Acme Events'], [$o->client_id, $o->client_name]);

        $this->inertia('PATCH', $o, $this->form($o))->assertSessionHasNoErrors()->assertSessionHas('success', 'Nothing changed.');
        $o = $o->fresh();
        $this->assertSame([], $o->owner_fields ?? []);
        $this->assertSame([$client->id, 'Acme Events'], [$o->client_id, $o->client_name]);

        // Typing another client is yours.
        $this->saveWith($o, ['client_name' => 'Bright Stages'])->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
        $o = $o->fresh();
        $this->assertSame([null, 'Bright Stages', ['client']], [$o->client_id, $o->client_name, $o->owner_fields]);
    }

    // ---------------------------------------------------------------- helpers

    private function found(string $text, array $opportunity, string $title = 'Acme Awards', string $from = 'Alex Smith <alex@acme-events.example>'): Opportunity
    {
        $doc = Document::query()->create([
            'source' => 'gmail', 'original_name' => 'crew.eml', 'mime' => 'message/rfc822', 'size' => strlen($text),
            'sha256' => hash('sha256', Str::random(40)), 'stored_path' => 'documents/'.Str::random(8).'.eml', 'email_from' => $from,
            'email_subject' => $title.' crew', 'received_at' => '2026-02-18 10:00:00', 'text' => $text, 'text_status' => 'extracted',
            'kind' => 'email', 'status' => 'extracted', 'extracted_at' => now(),
            'extraction' => ['summary' => 'Work', 'opportunity' => $opportunity + [
                'title' => self::q($title, $title), 'venue' => self::q('First Direct Arena', 'First Direct Arena'),
                'dates' => self::day('2026-03-12'),
            ]],
        ]);
        $o = app(Opportunities::class)->fromDocument($doc);
        $this->assertNotNull($o);

        return $o;
    }

    private static function day(string $date): array
    {
        return [['date' => $date, 'kind' => 'work', 'quote' => '12 March', 'verified' => true]];
    }

    private static function q(string $value, string $quote): array
    {
        return ['value' => $value, 'quote' => $quote, 'verified' => true];
    }

    /** The edit form exactly as resources/js/pages/opportunities/show.tsx fills it (toForm). */
    private function form(Opportunity $o): array
    {
        $page = $this->get('/opportunities/'.$o->id)->assertOk()->assertInertia(fn ($p) => $p->component('opportunities/show', false))->viewData('page');
        $props = json_decode(json_encode($page['props']), true);
        $row = $props['opportunity'];
        $digits = Money::digits($row['rate']['currency'] ?? null);

        return [
            'title' => $row['title'],
            'status' => array_key_exists($row['status'], $props['edit_statuses']) ? $row['status'] : 'enquiry',
            'hold_rank' => $row['hold_rank'] ? (string) $row['hold_rank'] : '',
            'client_name' => $row['client_name'] ?? '', 'contact_name' => $row['contact_name'] ?? '', 'contact_email' => $row['contact_email'] ?? '',
            'role' => $row['role'] ?? '', 'venue' => $row['venue'] ?? '', 'postcode' => $row['postcode'] ?? '',
            'days' => array_map(fn ($d) => ['date' => $d['date'], 'kind' => $d['kind']], $row['days']),
            'reply_by' => $row['reply_by'] ?? '',
            'rate_amount' => $row['rate'] ? number_format($row['rate']['minor'] / 10 ** $digits, $digits, '.', '') : '',
            'rate_currency' => $row['rate']['currency'] ?? 'GBP', 'rate_unit' => $row['rate']['unit'] ?? 'day',
            'po_number' => $row['po_number'] ?? '', 'note' => $row['note'] ?? '',
        ];
    }

    private function saveWithVenue(Opportunity $o): TestResponse
    {
        return $this->saveWith($o, ['venue' => 'Leeds Arena']);
    }

    private function saveWith(Opportunity $o, array $changed): TestResponse
    {
        return $this->inertia('PATCH', $o, $changed + $this->form($o));
    }

    /** Sent the way the page sends it: JSON from Inertia, back to the page it came from. */
    private function inertia(string $method, Opportunity $o, array $body): TestResponse
    {
        return $this->from('/opportunities/'.$o->id)->call($method, '/opportunities/'.$o->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }
}
