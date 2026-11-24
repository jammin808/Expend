<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Gmail\GmailClient;
use App\Domain\Matching\Suggester;
use App\Domain\Travel\TravelDays;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Travel\TravelReader;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class GmailTravelTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        Setting::put('home_postcode', 'SW1A 1AA');
    }

    /** postcodes.io answers for a few fictional places: London (home), Leeds (far), Watford (middle), Wembley (near). */
    private function fakePlaces(array $extra = []): void
    {
        $places = ['SW1A1AA' => [51.501, -0.1416], 'LS11 5QE' => [53.7787, -1.5578], 'WD17 1AA' => [51.6565, -0.3903], 'HA9 0WS' => [51.556, -0.2796]];
        Http::fake($extra + ['api.postcodes.io/postcodes/*' => function ($request) use ($places) {
            $pc = strtoupper(str_replace(['%20', ' '], '', basename($request->url())));
            foreach ($places as $key => [$lat, $lng]) {
                if (str_replace(' ', '', $key) === $pc) {
                    return Http::response(['status' => 200, 'result' => ['latitude' => $lat, 'longitude' => $lng]]);
                }
            }

            return Http::response(['status' => 404, 'error' => 'Postcode not found'], 404);
        }]);
    }

    private function job(string $title, string $starts, string $ends, ?string $postcode, ?string $start = null, ?string $end = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'starts_on' => $starts, 'ends_on' => $ends, 'postcode' => $postcode, 'start_time' => $start, 'end_time' => $end]);
    }

    private function payment(string $date, int $pence, string $description): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'test.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'merchant_key' => strtoupper(strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $date.$pence.$description), 'occurrence' => 1,
        ]);
    }

    private function hotel(string $in, string $out, ?int $amount = 8900, string $payment = 'unknown', string $ref = 'PI-1'): TravelItem
    {
        return TravelItem::query()->create([
            'kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => $ref, 'starts_on' => $in, 'ends_on' => $out,
            'place' => 'Premier Inn Leeds City Centre', 'amount_minor' => $amount, 'currency' => 'GBP', 'payment' => $payment, 'source' => 'structured_data',
        ]);
    }

    private function bookingEmail(): string
    {
        $jsonLd = json_encode([
            '@context' => 'http://schema.org', '@type' => 'LodgingReservation', 'reservationNumber' => 'PI-48213', 'reservationStatus' => 'http://schema.org/ReservationConfirmed',
            'underName' => ['@type' => 'Person', 'name' => 'Jo Bloggs'], 'checkinDate' => '2025-03-13T15:00:00+00:00', 'checkoutDate' => '2025-03-14T12:00:00+00:00',
            'reservationFor' => ['@type' => 'LodgingBusiness', 'name' => 'Premier Inn Leeds City Centre', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Leeds', 'postalCode' => 'LS1 4DL']],
            'totalPrice' => '89.00', 'priceCurrency' => 'GBP',
        ]);

        return "From: Premier Inn <noreply@premierinn.com>\r\nTo: jo@example.com\r\nSubject: Your booking confirmation PI-48213\r\nDate: Mon, 3 Mar 2025 10:00:00 +0000\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
            ."<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body><p>Thanks for booking. Check-in Thursday 13 March 2025.</p></body></html>\r\n";
    }

    public function test_connecting_asks_only_for_read_only_gmail_and_the_return_needs_its_one_time_state(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('google_client_id', 'abc.apps.googleusercontent.com');
        Setting::put('google_client_secret', 'shh');

        $url = GmailClient::authUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(GmailClient::SCOPE, $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('offline', $q['access_type']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600, 'refresh_token' => 'rt', 'scope' => GmailClient::SCOPE]),
            'gmail.googleapis.com/*' => Http::response(['emailAddress' => 'jo@example.com']),
        ]);
        $this->get('/settings/gmail/callback?code=xyz&state=forged&error=call+0800+now')->assertOk()->assertDontSee('0800');
        $this->assertNull(Setting::get('gmail_refresh_token'), 'A forged state is refused');
        $this->assertNull(Setting::get('gmail_last_result'), 'and records nothing on your settings page');

        $url = GmailClient::authUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $response = $this->get('/settings/gmail/callback?code=xyz&state='.urlencode($q['state']));
        $response->assertOk()->assertSee('Gmail connected');
        $this->assertSame('rt', Setting::get('gmail_refresh_token'));
        $this->assertStringNotContainsString('rt', (string) Setting::query()->find('gmail_refresh_token')->value, 'Stored encrypted');
        $this->assertSame('"rt"', Crypt::decryptString(Setting::query()->find('gmail_refresh_token')->value));
        $this->assertSame('jo@example.com', Setting::get('gmail_account'));
        $this->assertSame([], $response->headers->getCookies(), 'The return from Google never starts a session, which would sign you out');

        // The same state can't be used twice.
        Setting::put('gmail_refresh_token', null);
        $this->get('/settings/gmail/callback?code=xyz&state='.urlencode($q['state']))->assertSee('expired');
        $this->assertNull(Setting::get('gmail_refresh_token'));
    }

    public function test_a_gmail_search_reads_a_booking_and_lines_it_up_with_the_job_and_payment(): void
    {
        $this->fakePlaces([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response(['raw' => rtrim(strtr(base64_encode($this->bookingEmail()), '+/', '-_'), '='), 'threadId' => 't1', 'internalDate' => '1740996000000']),
        ]);
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        $job = $this->job('Leeds Arena load-in', '2025-03-14', '2025-03-14', 'LS11 5QE', '07:00');
        $paid = $this->payment('2025-03-03', -8900, 'PREMIER INN LEEDS');

        $this->actingAs(User::factory()->create())->post('/travel/scan', ['tax_year' => '2024-25', 'searches' => ['hotels']])->assertSessionHas('success');

        $this->assertSame('read', GmailMessage::query()->value('status'));
        $stay = TravelItem::query()->sole();
        $this->assertSame(['hotel', 'Premier Inn', 'PI-48213', '2025-03-13', '2025-03-14', 8900, 'structured_data'],
            [$stay->kind, $stay->provider, $stay->reference, $stay->starts_on->format('Y-m-d'), $stay->ends_on->format('Y-m-d'), $stay->amount_minor, $stay->source]);
        $this->assertSame($paid->id, $stay->bank_transaction_id);
        $this->assertSame($job->id, $stay->work_job_id);
        $this->assertSame('business', $stay->suggestion['decision'], 'The night before a job 170 miles away is business');

        $allocation = $paid->fresh()->allocations()->sole();
        $this->assertSame(['matcher', null, 'business_expense', $job->id], [$allocation->decided_by, $allocation->confirmed_at, $allocation->kind, $allocation->work_job_id]);
        $this->assertSame('travel_accommodation', Category::find($allocation->category_id)->code);
        $this->assertSame('suggested', $paid->fresh()->review_status, 'Stays are never confirmed automatically');

        // A second scan doesn't fetch it again.
        $this->post('/travel/scan', ['tax_year' => '2024-25', 'searches' => ['hotels']]);
        $this->assertSame(1, GmailMessage::query()->count());
    }

    public function test_the_night_before_and_after_follow_distance_and_times(): void
    {
        $this->fakePlaces();
        $days = app(TravelDays::class);
        $this->job('Leeds show', '2025-05-10', '2025-05-12', 'LS11 5QE');
        $this->job('Watford early', '2025-06-10', '2025-06-10', 'WD17 1AA', '06:30', '18:00');
        $this->job('Watford normal', '2025-06-20', '2025-06-20', 'WD17 1AA', '10:00', '23:30');
        $this->job('Wembley', '2025-07-01', '2025-07-01', 'HA9 0WS', '05:00');

        $this->assertSame('business', $days->night('2025-05-09')['verdict'], 'over 40 miles, night before');
        $this->assertSame('business', $days->night('2025-05-10')['verdict'], 'between working days');
        $this->assertSame('business', $days->night('2025-05-12')['verdict'], 'over 40 miles, night after');
        $this->assertSame('business', $days->night('2025-06-09')['verdict'], '12–40 miles with an early start');
        $this->assertSame('review', $days->night('2025-06-19')['verdict'], '12–40 miles, normal start: ask, never assume personal');
        $this->assertSame('business', $days->night('2025-06-20')['verdict'], '12–40 miles with a late finish');
        $this->assertSame('review', $days->night('2025-06-30')['verdict'], 'under 12 miles needs review');
        $this->assertSame('personal', $days->night('2025-08-01')['verdict'], 'no job');
        $this->assertStringContainsString('early start (06:30)', $days->night('2025-06-09')['reason']);
    }

    public function test_stays_with_no_payment_or_paid_by_someone_else_are_proposed_as_client_booked(): void
    {
        $this->fakePlaces();
        $this->job('Leeds show', '2025-05-10', '2025-05-10', 'LS11 5QE');
        $matcher = app(TravelMatcher::class);

        $stay = $this->hotel('2025-05-09', '2025-05-10');
        $matcher->suggest($stay);
        $this->assertSame('client_booked', $stay->fresh()->suggestion['decision']);

        $prepaid = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Booking.com', 'reference' => 'B-2', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-11',
            'payment' => 'paid_by_other', 'booker' => 'Acme Events Ltd', 'source' => 'ai', 'currency' => 'GBP']);
        $matcher->suggest($prepaid);
        $this->assertSame('client_booked', $prepaid->fresh()->suggestion['decision']);
        $this->assertStringContainsString('Acme Events Ltd', implode(' ', $prepaid->fresh()->suggestion['reasons']));
    }

    public function test_your_decision_applies_to_the_payment_and_splits_by_nights(): void
    {
        $this->fakePlaces();
        $job = $this->job('Leeds show', '2025-05-10', '2025-05-10', 'LS11 5QE');
        $stay = $this->hotel('2025-05-09', '2025-05-12', 30001);
        $paid = $this->payment('2025-05-12', -30001, 'PREMIER INN LEEDS');
        $matcher = app(TravelMatcher::class);
        $matcher->suggest($stay);
        $this->assertSame('mixed', $stay->fresh()->suggestion['decision'], 'Two nights for the job, one extra');

        $this->actingAs(User::factory()->create())
            ->post("/travel/{$stay->id}/decide", ['decision' => 'mixed', 'job_id' => $job->id, 'business_nights' => 2])->assertSessionHas('success');
        $lines = $paid->fresh()->allocations()->orderBy('id')->get();
        $this->assertSame(['business_expense', 'personal'], $lines->pluck('kind')->all());
        $this->assertSame([-20000, -10001], $lines->pluck('amount_pence')->all(), 'The odd penny goes to the personal part');
        $this->assertTrue($lines->every(fn ($a) => $a->is_manual && $a->confirmed_at !== null));

        // Re-checking never overwrites your decision on the payment.
        $stay->update(['decided_at' => null]);
        $matcher->suggest($stay->fresh());
        $this->assertTrue($paid->fresh()->allocations()->get()->every(fn ($a) => $a->decided_by === 'you'));

        // Client-booked leaves the payment alone.
        $other = $this->hotel('2025-05-10', '2025-05-11', null, 'paid_by_other', 'PI-9');
        $this->post("/travel/{$other->id}/decide", ['decision' => 'client_booked'])->assertSessionHas('success');
        $this->assertSame('client_booked', $other->fresh()->decision);
    }

    public function test_emails_without_booking_data_are_read_by_ai_and_every_detail_is_checked(): void
    {
        $this->fakePlaces();
        Setting::put('anthropic_api_key', 'sk-ant-test');
        Setting::put('ai_extraction', 'on_demand');
        $raw = "From: Uber Receipts <noreply@uber.com>\r\nSubject: Your Thursday evening trip with Uber\r\nDate: Thu, 13 Mar 2025 23:40:00 +0000\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            ."Thanks for riding, Jo\r\nTotal £23.40\r\nThursday, 13 March 2025\r\nPickup 23:05 Leeds Arena, Claypit Lane\r\nDrop-off 23:21 Premier Inn Leeds City Centre\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'uber.eml', 'message/rfc822', 'gmail', ['from' => 'Uber Receipts <noreply@uber.com>', 'received_at' => '2025-03-13 23:40:00']);
        $this->ai->responses = [FakeAiClient::json(['items' => [[
            'kind' => 'taxi', 'provider' => 'Uber', 'reference' => null, 'status' => 'receipt', 'starts_on' => '2025-03-13', 'ends_on' => null, 'start_time' => '23:05',
            'place' => 'Leeds Arena, Claypit Lane', 'destination' => 'Premier Inn Leeds City Centre', 'postcode' => null, 'amount_minor' => 2340, 'currency' => 'GBP',
            'payment' => 'paid', 'booked_for' => null, 'booker' => null,
            'quotes' => ['starts_on' => 'Thursday, 12 March 2025', 'ends_on' => null, 'amount' => 'Total £23.40', 'reference' => null, 'place' => 'Pickup 23:05 Leeds Arena, Claypit Lane', 'payment' => 'Paid by card'],
        ]]])];

        $items = app(TravelReader::class)->read($doc);

        $this->assertCount(1, $items);
        $ride = $items[0];
        $this->assertSame(['taxi', 2340, 'ai'], [$ride->kind, $ride->amount_minor, $ride->source]);
        $this->assertTrue($ride->evidence['amount']['verified']);
        $this->assertFalse($ride->evidence['starts_on']['verified'], 'A quote that isn’t in the email is flagged');
        $this->assertSame('unknown', $ride->payment, 'A claim about who paid needs the email’s own words');
        $this->assertSame(2, $ride->unverified_count);
    }

    public function test_marketing_emails_are_skipped_without_spending_on_ai(): void
    {
        Setting::put('anthropic_api_key', 'sk-ant-test');
        $raw = "From: Booking.com <email@booking.com>\r\nSubject: Weekend deals just for you\r\nContent-Type: text/plain\r\n\r\nSave 15% on your next getaway. Explore now.\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'deals.eml', 'message/rfc822', 'gmail');

        $this->assertSame([], app(TravelReader::class)->read($doc));
        $this->assertSame([], $this->ai->requests);
    }

    public function test_tfl_and_taxis_on_statements_follow_job_days(): void
    {
        $this->fakePlaces();
        $job = $this->job('London conference', '2025-04-15', '2025-04-16', 'SW1A 1AA');
        $onJob = $this->payment('2025-04-16', -840, 'TFL TRAVEL CH'); // posted the day after travel on the 15th
        $noJob = $this->payment('2025-04-22', -560, 'TFL TRAVEL CH');
        $eats = $this->payment('2025-04-15', -1890, 'UBER *EATS');

        app(Suggester::class)->suggestFor(collect([$onJob, $noJob, $eats]));

        $a = $onJob->fresh()->allocations()->sole();
        $this->assertSame(['business_expense', $job->id, 'travel_local'], [$a->kind, $a->work_job_id, Category::find($a->category_id)->code]);
        $this->assertSame('personal', $noJob->fresh()->allocations()->sole()->kind);
        $this->assertSame(0, $eats->fresh()->allocations()->count(), 'Food delivery isn’t travel');
    }

    public function test_the_pages_render(): void
    {
        $this->actingAs(User::factory()->create());
        $this->hotel('2025-05-09', '2025-05-10');
        $this->get('/travel?year=2025-26')->assertOk()->assertInertia(fn ($page) => $page->has('items', 1)->where('year', '2025-26')->has('gmail.searches', 4));
        $this->get('/settings/integrations')->assertOk()->assertInertia(fn ($page) => $page->where('gmail.connected', false)->has('gmail.searches', 4));
    }
}
