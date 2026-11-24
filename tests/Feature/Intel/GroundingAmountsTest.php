<?php

namespace Tests\Feature\Intel;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Grounding;
use App\Domain\Verification\Verifier;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An amount the AI read from a document is only "checked" when the document states that figure where the quote is,
 * with its own commas and decimal point: "£12.50" is never found in "Crew fee £1,250".
 */
class GroundingAmountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quote_whose_figure_differs_only_by_its_separators_is_not_verified(): void
    {
        $check = Grounding::check('Crew fee £1,250', 'extracted', '£12.50', 1250);

        $this->assertFalse($check['verified']);
        $this->assertStringContainsString('different figure', $check['note']);
        $this->assertTrue(Grounding::quoteFound('Crew fee £1,250', '£12.50'), 'The words alone still match, ignoring punctuation');
    }

    public function test_the_right_quote_is_verified(): void
    {
        $this->assertTrue(Grounding::check('Crew fee £1,250', 'extracted', 'Crew fee £1,250', 125000)['verified']);
        $this->assertTrue(Grounding::check('Crew fee £1,250', 'extracted', '£1,250', 125000)['verified']);
        $this->assertTrue(Grounding::check('Crew fee £12.50', 'extracted', '£12.50', 1250)['verified']);
        // Spacing and line breaks as a PDF text layer gives them, and a quote without the thousands comma.
        $this->assertTrue(Grounding::check("Crew   fee\n£1,250.00 per day", 'extracted', 'Crew fee £1250.00', 125000)['verified']);
        // Ligatures in the text layer.
        $this->assertTrue(Grounding::check('Conﬁrmed fee £300.00', 'extracted', 'Confirmed fee £300.00', 30000)['verified']);
        // A table's cells run together in the text layer: each amount with pence is still read on its own.
        $table = "Show day technician2 x 450.00900.00\nPrep day 1 x 300.00300.00\nCrew 3 x 1,250.003,750.00";
        $this->assertTrue(Grounding::check($table, 'extracted', 'Show day technician 2 x 450.00 900.00', 90000)['verified']);
        $this->assertTrue(Grounding::check($table, 'extracted', 'Prep day 1 x 300.00 300.00', 30000)['verified']);
        $this->assertTrue(Grounding::check($table, 'extracted', 'Crew 3 x 1,250.00 3,750.00', 375000)['verified']);
        $this->assertFalse(Grounding::check($table, 'extracted', 'Show day technician 2 x 450.00 900.00', 9000)['verified']);
    }

    public function test_a_number_cut_short_by_the_quote_is_read_whole(): void
    {
        // "£1" is in "£1,250", and "250.00" in "1,250.00", but neither is what the document says.
        $this->assertFalse(Grounding::check('Fee £1,250', 'extracted', 'Fee £1', 100)['verified']);
        $this->assertFalse(Grounding::check('Total £1,250.00', 'extracted', '250.00', 25000)['verified']);
        $this->assertFalse(Grounding::amountInText('Day rate 12.505', '12.50', 1250), 'A longer decimal is another number');
        $this->assertTrue(Grounding::amountInText('Total £1,250.00', '1,250', 125000), 'The whole number there is the amount');
    }

    public function test_the_amount_counts_where_any_copy_of_the_quote_states_it(): void
    {
        $text = "Deposit £1,250\nBalance £12.50";

        $this->assertTrue(Grounding::check($text, 'extracted', '£12.50', 1250)['verified']);
        $this->assertTrue(Grounding::check($text, 'extracted', '£1,250', 125000)['verified']);
        $this->assertFalse(Grounding::check($text, 'extracted', '£1,250', 1250)['verified'], 'The amount must also be in the quote');
    }

    public function test_quotes_without_an_amount_are_checked_as_before(): void
    {
        $this->assertTrue(Grounding::check('Call time 07:30 at the Grand Hall', 'extracted', 'call time 07:30')['verified']);
        $this->assertFalse(Grounding::check('Call time 07:30', 'extracted', 'Call time 08:30')['verified']);
        $this->assertFalse(Grounding::check('Crew fee £1,250', 'scanned', '£1,250', 125000)['verified'], 'A scan can’t be checked word for word');
        $this->assertFalse(Grounding::amountInText(null, '£12.50', 1250));
    }

    public function test_a_document_re_check_catches_the_wrong_figure_and_is_recorded_against_the_document(): void
    {
        Storage::fake('local');
        $doc = app(DocumentIntake::class)->store("From: crew@acme.example\r\nSubject: Booking\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAlex Smith, crew fee £1,250 for the Acme Events awards.\r\n", 'booking.eml', 'message/rfc822', 'upload');
        $doc->update(['status' => 'extracted', 'extraction' => ['receipt' => ['amount_minor' => 1250, 'currency' => 'GBP', 'quote' => '£12.50', 'verified' => true]]]);

        $v = app(Verifier::class)->document($doc->fresh());

        $this->assertSame('mismatch', $v->status);
        $this->assertFalse($doc->fresh()->extraction['receipt']['verified']);
        $event = AuditEvent::query()->where('action', 'verification.mismatch')->sole();
        $this->assertSame(['Document', $doc->id, 'checker'], [$event->subject_type, $event->subject_id, $event->actor]);
        $this->assertSame('document', $event->after['check']);

        $doc->update(['extraction' => ['receipt' => ['amount_minor' => 125000, 'currency' => 'GBP', 'quote' => 'crew fee £1,250', 'verified' => false]]]);
        $this->assertSame('verified', app(Verifier::class)->document($doc->fresh())->status);
        $this->assertTrue($doc->fresh()->extraction['receipt']['verified']);
    }
}
