<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\Opportunities;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A sender whose address the edit form wouldn't take: the address isn't kept, but they're still the one who sent it.
 * The work is still found, and the contact isn't swapped for someone further down a forwarded thread.
 */
class OpportunitySenderAddressTest extends TestCase
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

    public function test_work_from_a_bare_address_the_form_would_refuse_is_still_found(): void
    {
        Setting::put('ai_extraction', 'off');
        $from = 'alex..smith@bright-stages.example';
        $raw = "From: {$from}\r\nTo: Sam Taylor <sam@example.test>\r\nDate: Thu, 19 Feb 2026 09:00:00 +0000\r\n"
            ."Subject: Availability - Bright Stages launch 20 March\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            ."Hi Sam,\r\n\r\nAre you available on 20 March for the Bright Stages launch at Leeds Arena?\r\n\r\nThanks\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'launch.eml', 'message/rfc822', 'email', ['from' => $from, 'subject' => 'Availability - Bright Stages launch 20 March']);

        $o = Opportunity::query()->where('fingerprint', sha1('document:'.$doc->id))->first();
        $this->assertNotNull($o, 'the rules should still find the work');
        $this->assertSame(['rules', null, null], [$o->found_by, $o->contact_name, $o->contact_email]);
        // The page still shows where the contact was read from.
        $this->assertSame("From: {$from}", $o->evidenceFor('contact')[0]['quote'] ?? null);

        $this->inertia($o, ['venue' => 'Leeds Arena'] + $this->form($o))->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
        $this->assertSame(['venue'], $o->fresh()->owner_fields);
    }

    public function test_a_forwarded_email_keeps_its_original_sender_even_when_their_address_isnt_kept(): void
    {
        $text = "---------- Forwarded message ---------\nFrom: Alex Smith <alex..smith@acme-events.example>\nSubject: Acme Awards crew\n\n"
            ."Are you free for the Acme Awards on 12 March at the First Direct Arena?\n\n"
            ."> From: Jamie Jones <jamie@venue.example>\n> Parking is round the back.\n";
        $doc = Document::query()->create([
            'source' => 'gmail', 'original_name' => 'crew.eml', 'mime' => 'message/rfc822', 'size' => strlen($text),
            'sha256' => hash('sha256', Str::random(40)), 'stored_path' => 'documents/'.Str::random(8).'.eml', 'email_from' => 'Sam Taylor <sam@example.test>',
            'email_subject' => 'Fwd: Acme Awards crew', 'received_at' => '2026-02-18 10:00:00', 'text' => $text, 'text_status' => 'extracted',
            'kind' => 'email', 'status' => 'extracted', 'extracted_at' => now(),
            'extraction' => ['summary' => 'Work', 'opportunity' => [
                'title' => ['value' => 'Acme Awards', 'quote' => 'Acme Awards', 'verified' => true],
                'venue' => ['value' => 'First Direct Arena', 'quote' => 'First Direct Arena', 'verified' => true],
                'dates' => [['date' => '2026-03-12', 'kind' => 'work', 'quote' => '12 March', 'verified' => true]],
            ]],
        ]);

        $sender = Opportunities::senderOf($doc);
        $this->assertNotNull($sender);
        $this->assertSame(['Alex Smith', null], [$sender['name'], $sender['email']]);

        $o = app(Opportunities::class)->fromDocument($doc);
        $this->assertNotNull($o);
        $this->assertSame(['Alex Smith', null], [$o->contact_name, $o->contact_email]);
        $this->inertia($o, ['venue' => 'Leeds Arena'] + $this->form($o))->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved.');
    }

    /** The edit form as resources/js/pages/opportunities/show.tsx fills it (toForm). */
    private function form(Opportunity $o): array
    {
        $page = $this->get('/opportunities/'.$o->id)->assertOk()->assertInertia(fn ($p) => $p->component('opportunities/show', false))->viewData('page');
        $props = json_decode(json_encode($page['props']), true);
        $row = $props['opportunity'];

        return [
            'title' => $row['title'], 'status' => array_key_exists($row['status'], $props['edit_statuses']) ? $row['status'] : 'enquiry',
            'hold_rank' => $row['hold_rank'] ? (string) $row['hold_rank'] : '',
            'client_name' => $row['client_name'] ?? '', 'contact_name' => $row['contact_name'] ?? '', 'contact_email' => $row['contact_email'] ?? '',
            'role' => $row['role'] ?? '', 'venue' => $row['venue'] ?? '', 'postcode' => $row['postcode'] ?? '',
            'days' => array_map(fn ($d) => ['date' => $d['date'], 'kind' => $d['kind']], $row['days']),
            'reply_by' => $row['reply_by'] ?? '', 'rate_amount' => '', 'rate_currency' => 'GBP', 'rate_unit' => 'day',
            'po_number' => $row['po_number'] ?? '', 'note' => $row['note'] ?? '',
        ];
    }

    /** Sent the way the page sends it: JSON from Inertia, back to the page it came from. */
    private function inertia(Opportunity $o, array $body): TestResponse
    {
        return $this->from('/opportunities/'.$o->id)->call('PATCH', '/opportunities/'.$o->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }
}
