<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Grounding;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Suggester;
use App\Domain\Travel\Places;
use App\Domain\Travel\TravelDays;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Travel\TravelReader;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/** One test per finding of the independent review of the Gmail and travel code. */
class TravelReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AiClient::class, new FakeAiClient);
        Setting::put('home_postcode', 'SW1A 1AA');
    }

    private function places(): void
    {
        $at = ['SW1A1AA' => [51.501, -0.1416], 'WD171AA' => [51.6565, -0.3903], 'LS115QE' => [53.7787, -1.5578]];
        Http::fake(['api.postcodes.io/*' => function ($request) use ($at) {
            $pc = strtoupper(str_replace(['%20', ' '], '', basename($request->url())));

            return isset($at[$pc]) ? Http::response(['result' => ['latitude' => $at[$pc][0], 'longitude' => $at[$pc][1]]]) : Http::response(['error' => 'Invalid postcode'], 404);
        }]);
    }

    private function email(array $nodes, string $date, string $from = 'Booking.com <noreply@booking.com>'): Document
    {
        $ld = json_encode($nodes);
        $raw = "From: {$from}\r\nSubject: Booking\r\nDate: {$date}\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><head><script type=\"application/ld+json\">{$ld}</script></head><body>hi</body></html>\r\n";

        return app(DocumentIntake::class)->store($raw, 'b.eml', 'message/rfc822', 'gmail', ['from' => $from, 'received_at' => date('Y-m-d H:i:s', strtotime($date))]);
    }

    private function payment(string $date, int $pence, string $description, array $extra = []): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create($extra + [
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'merchant_key' => strtoupper(strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $date.$pence.$description.json_encode($extra)), 'occurrence' => 1,
        ]);
    }

    private function stay(array $extra = []): TravelItem
    {
        return TravelItem::query()->create($extra + ['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'P'.random_int(1, 99999), 'starts_on' => '2025-06-10',
            'ends_on' => '2025-06-11', 'amount_minor' => 9000, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data']);
    }

    public function test_an_older_email_never_undoes_a_newer_cancellation_or_amendment(): void
    {
        $this->places();
        $base = ['@context' => 'http://schema.org', '@type' => 'LodgingReservation', 'reservationNumber' => 'R1', 'reservationFor' => ['name' => 'Hotel X'],
            'totalPrice' => '100.00', 'priceCurrency' => 'GBP', 'checkinDate' => '2025-03-13', 'checkoutDate' => '2025-03-15'];
        $reader = app(TravelReader::class);

        // Gmail lists newest first: the cancellation is read before the original booking.
        $reader->read($this->email([$base + ['reservationStatus' => 'http://schema.org/ReservationCancelled']], 'Mon, 10 Mar 2025 10:00:00 +0000'));
        $reader->read($this->email([$base + ['reservationStatus' => 'http://schema.org/ReservationConfirmed']], 'Mon, 3 Mar 2025 10:00:00 +0000'));
        $this->assertSame('cancelled', TravelItem::query()->sole()->status);

        $amended = ['reservationNumber' => 'R2', 'checkinDate' => '2025-04-01', 'checkoutDate' => '2025-04-02', 'totalPrice' => '80.00'] + $base;
        $reader->read($this->email([$amended], 'Mon, 24 Mar 2025 10:00:00 +0000'));
        $reader->read($this->email([['checkinDate' => '2025-03-28', 'checkoutDate' => '2025-03-29', 'totalPrice' => '70.00'] + $amended], 'Mon, 17 Mar 2025 10:00:00 +0000'));
        $stay = TravelItem::query()->where('reference', 'R2')->sole();
        $this->assertSame(['2025-04-01', 8000], [$stay->starts_on->format('Y-m-d'), $stay->amount_minor]);
    }

    public function test_each_leg_of_a_return_ticket_is_its_own_journey(): void
    {
        $this->places();
        $leg = fn ($d) => ['@context' => 'http://schema.org', '@type' => 'TrainReservation', 'reservationNumber' => 'T1', 'totalPrice' => '50.00', 'priceCurrency' => 'GBP',
            'reservationFor' => ['departureTime' => $d, 'departureStation' => ['name' => 'London Kings Cross'], 'arrivalStation' => ['name' => 'Leeds']]];

        $items = app(TravelReader::class)->read($this->email([$leg('2025-03-13T07:00:00'), $leg('2025-03-16T18:00:00')], 'Mon, 3 Mar 2025 10:00:00 +0000', 'Trainline <noreply@trainline.com>'));

        $this->assertCount(2, $items);
        $this->assertSame(['2025-03-13', '2025-03-16'], TravelItem::query()->orderBy('starts_on')->pluck('starts_on')->map->format('Y-m-d')->all());
    }

    public function test_a_quoted_date_must_have_the_right_year_and_prices_are_not_dates(): void
    {
        $this->assertFalse(Grounding::dateInQuote('2026-03-14', 'Check-in 14/03/2025'));
        $this->assertFalse(Grounding::dateInQuote('2026-03-14', 'Total £14.03'));
        $this->assertTrue(Grounding::dateInQuote('2025-03-14', 'Check-in 14/03/2025'));
        $this->assertTrue(Grounding::dateInQuote('2025-03-14', 'Fri 14 Mar 2025, total £14.03'));
        $this->assertTrue(Grounding::dateInQuote('2025-03-14', 'Friday 14 March'));
    }

    public function test_only_definite_postcode_answers_are_cached(): void
    {
        Http::fake(['api.postcodes.io/postcodes/LS1*' => Http::response(['error' => 'busy'], 503), 'api.postcodes.io/*' => Http::response(['error' => 'Invalid postcode'], 404)]);
        $this->assertNull(Places::locate('LS1 4DL'));
        $this->assertNull(Places::locate('LS1 4DL'), 'A failure is remembered for the rest of the request');
        Http::assertSentCount(1);
        $this->assertNull(Places::locate('ZZ9 9ZZ'));
        $this->assertSame(['ZZ9 9ZZ'], DB::table('places')->pluck('postcode')->all(), 'A busy service isn’t cached; an unknown postcode is');
    }

    public function test_a_payment_in_a_locked_year_is_never_touched_and_nothing_half_saves(): void
    {
        $this->places();
        $this->actingAs(User::factory()->create());
        $tx = $this->payment('2025-03-20', -9000, 'PREMIER INN');
        $stay = $this->stay(['bank_transaction_id' => $tx->id, 'starts_on' => '2025-04-10', 'ends_on' => '2025-04-12']);
        TaxYear::track('2024-25')->update(['locked_at' => now()]);

        $this->post('/travel/check', ['tax_year' => '2025-26'])->assertRedirect();
        $this->assertSame(0, $tx->allocations()->count());
        $this->post("/travel/{$stay->id}/decide", ['decision' => 'personal'])->assertSessionHas('error');
        $this->assertNull($stay->fresh()->decided_at, 'The stay isn’t marked decided when its payment can’t be changed');
        $this->post("/travel/{$stay->id}/reopen")->assertRedirect();
    }

    public function test_a_stay_with_a_personal_night_and_a_check_night_is_split_not_all_business(): void
    {
        $this->places();
        WorkJob::query()->create(['title' => 'Watford', 'starts_on' => '2025-06-11', 'ends_on' => '2025-06-12', 'postcode' => 'WD17 1AA', 'start_time' => '10:00']);
        $tx = $this->payment('2025-06-12', -30000, 'PREMIER INN WATFORD');
        $stay = $this->stay(['starts_on' => '2025-06-09', 'ends_on' => '2025-06-12', 'amount_minor' => 30000]);

        app(TravelMatcher::class)->suggest($stay);

        $this->assertSame('review', $stay->fresh()->suggestion['decision']);
        $lines = $tx->allocations()->orderBy('id')->get();
        $this->assertSame([['business_expense', -20000], ['personal', -10000]], $lines->map(fn ($a) => [$a->kind, $a->amount_pence])->all());
    }

    public function test_payment_matching_uses_original_currency_needs_a_unique_match_and_keeps_your_decisions(): void
    {
        $this->places();
        $matcher = app(TravelMatcher::class);
        $euro = $this->payment('2025-05-02', -8650, 'BOOKING.COM AMSTERDAM', ['original_amount_minor' => -10000, 'original_currency' => 'EUR']);
        $this->payment('2025-05-03', -4200, 'BOOKING.COM PARIS', ['original_amount_minor' => -5000, 'original_currency' => 'EUR']);
        $abroad = $this->stay(['provider' => 'Booking.com', 'currency' => 'EUR', 'amount_minor' => 10000, 'starts_on' => '2025-05-01', 'ends_on' => '2025-05-02']);
        $this->assertSame($euro->id, $matcher->findPayment($abroad)?->id);

        $noAmount = $this->stay(['provider' => 'Booking.com', 'amount_minor' => null, 'starts_on' => '2025-05-01', 'ends_on' => '2025-05-02']);
        $this->assertNull($matcher->findPayment($noAmount), 'Two Booking.com payments and no amount to tell them apart: no guess');

        // A payment you've decided yourself isn't overwritten when you decide the stay.
        $mine = $this->payment('2025-06-11', -9000, 'PREMIER INN LEEDS');
        app(AllocationWriter::class)->decide($mine, AllocationWriter::lines(-9000, 'personal'), 'you');
        $stay = $this->stay(['bank_transaction_id' => $mine->id]);
        $this->assertSame('kept', $matcher->decide($stay, 'business', null, null, null));
        $this->assertSame('personal', $mine->allocations()->sole()->kind);
    }

    public function test_client_booked_unlinks_the_payment_and_takes_back_the_automatic_suggestion(): void
    {
        $this->places();
        $tx = $this->payment('2025-06-11', -9000, 'PREMIER INN LEEDS');
        $stay = $this->stay();
        app(TravelMatcher::class)->suggest($stay);
        $this->assertSame($tx->id, $stay->fresh()->bank_transaction_id);
        $this->assertSame('matcher', $tx->allocations()->sole()->decided_by);

        $this->assertSame('unlinked', app(TravelMatcher::class)->decide($stay->fresh(), 'client_booked', null, null, null));
        $this->assertNull($stay->fresh()->bank_transaction_id);
        $this->assertSame(0, $tx->allocations()->count());
        $this->assertSame('unreviewed', $tx->fresh()->review_status);
    }

    public function test_one_bad_message_doesnt_stop_a_gmail_search_and_is_tried_again(): void
    {
        $this->places();
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        $raw = "From: Booking.com <noreply@booking.com>\r\nSubject: Weekend deals\r\nContent-Type: text/plain\r\n\r\nSave now.\r\n";
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'gone'], ['id' => 'ok']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/gone*' => Http::response(['error' => ['message' => 'Requested entity was not found.']], 404),
            'gmail.googleapis.com/gmail/v1/users/me/messages/ok*' => Http::response(['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 'internalDate' => '1740996000000']),
        ]);

        $result = app(GmailScanner::class)->scanPage('hotels', '2024-25');

        $this->assertSame(1, $result['new']);
        $this->assertSame('failed', GmailMessage::query()->where('message_id', 'gone')->value('status'));
        $this->assertSame('no_booking', GmailMessage::query()->where('message_id', 'ok')->value('status'));
        app(GmailScanner::class)->scanPage('hotels', '2024-25');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'messages/gone') && $r->method() === 'GET');
    }

    public function test_long_sender_addresses_are_shortened_to_fit(): void
    {
        $from = str_repeat('a', 300).' <x@example.com>';
        $doc = app(DocumentIntake::class)->store("From: {$from}\r\nSubject: s\r\n\r\nbody\r\n", 'x.eml', 'message/rfc822', 'gmail', ['from' => $from]);
        $this->assertLessThanOrEqual(250, mb_strlen($doc->email_from));
    }

    public function test_smaller_findings(): void
    {
        $this->places();
        // TfL charges the day after you travel, whichever date the bank gives.
        $job = WorkJob::query()->create(['title' => 'London', 'starts_on' => '2025-04-15', 'ends_on' => '2025-04-15', 'postcode' => 'SW1A 1AA']);
        $tfl = $this->payment('2025-04-17', -840, 'TFL TRAVEL CH', ['transaction_on' => '2025-04-16']);
        app(Suggester::class)->suggestFor(collect([$tfl]));
        $this->assertSame($job->id, $tfl->allocations()->sole()->work_job_id);

        // A stay you decided as personal doesn't make the next day a work travel day.
        $far = WorkJob::query()->create(['title' => 'Leeds', 'starts_on' => '2025-07-10', 'ends_on' => '2025-07-10', 'postcode' => 'WD17 1AA']);
        $this->stay(['starts_on' => '2025-07-08', 'ends_on' => '2025-07-09', 'work_job_id' => $far->id, 'decision' => 'personal', 'decided_at' => now()]);
        $this->assertSame('review', app(TravelDays::class)->day('2025-07-09')['verdict']);

        // Disconnecting works even when Google's revoke endpoint doesn't answer.
        Setting::put('gmail_refresh_token', 'rt');
        Http::fake(['oauth2.googleapis.com/revoke' => fn () => throw new ConnectionException('timed out')]);
        GmailClient::disconnect();
        $this->assertNull(Setting::get('gmail_refresh_token'));
    }
}
