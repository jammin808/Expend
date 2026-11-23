<?php

namespace Database\Seeders;

use App\Domain\Ai\DocumentReader;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Travel\TravelReader;
use App\Domain\Verification\Verifier;
use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\GmailMessage;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fictional sample data for trying the app locally. Never run this on the live site.
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to load demo data in production.');

            return;
        }

        User::query()->firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Demo Owner', 'password' => 'demo-password-123', 'email_verified_at' => now()]);
        TaxYear::track('2024-25');
        TaxYear::track('2025-26');
        TaxYear::track('2023-24', 'filed')->update(['filed_turnover_pence' => 3_850_000, 'filed_expenses_pence' => 820_000, 'filed_profit_pence' => 3_030_000]);

        $starling = Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
        $amex = Account::query()->firstOrCreate(['name' => 'Amex'], ['kind' => 'credit_card', 'provider' => 'amex']);

        $acme = Client::query()->firstOrCreate(['name' => 'Acme Events Ltd']);
        $north = Client::query()->firstOrCreate(['name' => 'Northern Lights Productions']);

        $jobs = [];
        $start = CarbonImmutable::parse('2024-04-12');
        for ($i = 0; $i < 10; $i++) {
            $d = $start->addWeeks($i * 5);
            $jobs[] = WorkJob::query()->create([
                'client_id' => $i % 2 ? $north->id : $acme->id,
                'title' => ['Product launch', 'Awards night', 'Conference', 'Festival stage', 'Corporate dinner'][$i % 5].' '.($i + 1),
                'starts_on' => $d, 'ends_on' => $d->addDays($i % 3),
                'start_time' => $i % 2 ? '07:00' : '10:00', 'end_time' => '23:00',
                'location' => ['Manchester Central', 'ExCeL London', 'Leeds Arena', 'Birmingham NEC', 'Glasgow SEC'][$i % 5],
                'postcode' => ['M2 3GX', 'E16 1XL', 'LS2 8BY', 'B40 1NT', 'G3 8YW'][$i % 5],
                'fee_pence' => 120_000 + $i * 15_000, 'status' => 'done',
            ]);
            CalendarEvent::query()->create([
                'uid' => "demo-{$i}", 'starts_at' => $d->setTime($i % 2 ? 7 : 10, 0), 'ends_at' => $d->addDays($i % 3)->setTime(23, 0),
                'summary' => $jobs[$i]->title.' (show)', 'location' => $jobs[$i]->location,
            ]);
        }

        $rows = [['Date', 'Counter Party', 'Reference', 'Type', 'Amount (GBP)', 'Balance (GBP)', 'Spending Category', 'Notes']];
        $amexRows = [['Date', 'Description', 'Amount', 'Extended Details', 'Appears On Your Statement As', 'Address', 'Town/City', 'Postcode', 'Country', 'Reference', 'Category']];
        $balance = 420_000;
        $add = function (\DateTimeInterface $d, string $party, string $ref, int $pence, string $cat) use (&$rows, &$balance) {
            $balance += $pence;
            $rows[] = [$d->format('d/m/Y'), $party, $ref, $pence > 0 ? 'FASTER PAYMENT' : 'CARD PAYMENT', number_format($pence / 100, 2, '.', ''), number_format($balance / 100, 2, '.', ''), $cat, ''];
        };

        $cursor = CarbonImmutable::parse('2024-04-08');
        foreach (range(0, 23) as $m) {
            $month = CarbonImmutable::parse('2024-04-01')->addMonths($m);
            $add($month->addDays(1), 'Adobe', 'ADOBE CREATIVE CLD', -5_698, 'SHOPPING');
            $add($month->addDays(3), 'Vodafone', 'VODAFONE LTD', -3_500, 'BILLS');
            $add($month->addDays(5), 'Tesco', 'TESCO STORES 2041', -(4_000 + $m * 137 % 3_000), 'GROCERIES');
            $add($month->addDays(9), 'TfL', 'TFL TRAVEL CH', -(820 + $m * 11 % 400), 'TRANSPORT');
            $add($month->addDays(14), 'Pret A Manger', 'PRET A MANGER', -420, 'EATING_OUT');
            if ($m % 3 === 1) {
                $add($month->addDays(18), 'American Express', 'AMEX PAYMENT', -30_000, 'PAYMENTS');
            }
        }
        foreach ($jobs as $i => $job) {
            $starts = CarbonImmutable::instance($job->starts_on);
            $ends = CarbonImmutable::instance($job->ends_on);
            $add($starts->subDays(1), 'Trainline', 'THETRAINLINE.COM', -(6_430 + $i * 210), 'TRANSPORT');
            $add($ends->addDays(12), $job->client->name, 'INV '.(1040 + $i), $job->fee_pence, 'INCOME');
            $amexRows[] = [$starts->format('d/m/Y'), 'PREMIER INN '.strtoupper(explode(' ', $job->location)[0]), number_format((8_900 + $i * 300) / 100, 2, '.', ''), '', 'PREMIER INN', '', '', '', 'UNITED KINGDOM', "'AT{$i}'", 'Travel-Lodging'];
            $amexRows[] = [$starts->format('d/m/Y'), 'UBER *TRIP', number_format((1_840 + $i * 50) / 100, 2, '.', ''), '', 'UBER', '', '', '', 'UNITED KINGDOM', "'AU{$i}'", 'Travel-Taxis'];
        }
        $add(CarbonImmutable::parse('2025-01-30'), 'HMRC', 'HMRC SELF ASSESSMENT', -180_000, 'PAYMENTS');
        $add(CarbonImmutable::parse('2025-07-29'), 'HMRC', 'HMRC SELF ASSESSMENT', -90_000, 'PAYMENTS');
        foreach (range(0, 7) as $q) {
            $amexRows[] = [CarbonImmutable::parse('2024-05-20')->addMonths($q * 3)->format('d/m/Y'), 'PAYMENT RECEIVED - THANK YOU', '-300.00', '', '', '', '', '', '', "'P{$q}'", ''];
        }
        $amexRows[] = ['14/03/2025', 'THOMANN MUSIC GMBH', '389.00', '', 'THOMANN', '', '', '', 'GERMANY', "'AT99'", 'Shopping'];

        // Banks list oldest first with a running balance; sort by date, then recompute balances in order.
        $header = array_shift($rows);
        usort($rows, fn ($a, $b) => CarbonImmutable::createFromFormat('d/m/Y', $a[0]) <=> CarbonImmutable::createFromFormat('d/m/Y', $b[0]));
        $running = 420_000;
        foreach ($rows as &$r) {
            $running += (int) round(((float) $r[4]) * 100);
            $r[5] = number_format($running / 100, 2, '.', '');
        }
        unset($r);
        array_unshift($rows, $header);

        $importer = app(Importer::class);
        foreach ([[$starling, $rows, 'starling-demo.csv'], [$amex, $amexRows, 'amex-demo.csv']] as [$account, $data, $name]) {
            $path = storage_path("app/private/{$name}");
            @mkdir(dirname($path), 0700, true);
            $fh = fopen($path, 'w');
            foreach ($data as $line) {
                fputcsv($fh, $line, ',', '"', '');
            }
            fclose($fh);
            $importer->commit($account, $path, $name, ProfileDetector::detect(CsvReader::read($path)['headers'], $account->kind));
            @unlink($path);
        }

        $this->demoPurchaseOrder($jobs[1]);
        $this->demoHotelBookings($jobs);
        $this->demoJobFinder();

        $car = Vehicle::query()->firstOrCreate(['name' => 'My car'], ['type' => 'car_van', 'method' => 'simplified']);
        foreach (array_slice($jobs, 0, 4) as $job) {
            MileageTrip::query()->create([
                'vehicle_id' => $car->id, 'work_job_id' => $job->id, 'trip_on' => $job->starts_on, 'tax_year' => UkTaxYear::fromDate($job->starts_on)->label(),
                'from_place' => 'Home', 'to_place' => $job->location, 'miles' => '86.0', 'purpose' => 'Drive to venue (return trip)',
            ]);
        }
    }

    /**
     * Fictional booking emails with the structured booking data booking sites embed, read without AI, to show
     * Stays & travel. Postcode locations are stored here so the demo never calls postcodes.io.
     */
    private function demoHotelBookings(array $jobs): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        foreach (['SW1A 1AA' => [51.501, -0.1416], 'M2 3GX' => [53.4767, -2.2469], 'E16 1XL' => [51.5085, 0.0295], 'LS2 8BY' => [53.8035, -1.5487],
            'B40 1NT' => [52.4531, -1.7178], 'G3 8YW' => [55.8607, -4.2871]] as $pc => [$lat, $lng]) {
            DB::table('places')->updateOrInsert(['postcode' => $pc], ['latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }

        $intake = app(DocumentIntake::class);
        foreach ([0, 2, 4, 6] as $n) {
            $job = $jobs[$n];
            $in = CarbonImmutable::instance($job->starts_on)->subDay();
            $out = CarbonImmutable::instance($job->starts_on);
            $town = explode(' ', $job->location)[0];
            // Job 6's stay was booked by the client: no payment for it on any statement.
            $price = $n === 6 ? '112.00' : number_format((8_900 + $n * 300) / 100, 2, '.', '');
            $provider = $n === 6 ? ['Booking.com', 'noreply@booking.com', 'Hotel Indigo '.$town] : ['Premier Inn', 'noreply@premierinn.com', 'Premier Inn '.$town.' City Centre'];
            $data = json_encode(['@context' => 'http://schema.org', '@type' => 'LodgingReservation', 'reservationNumber' => 'DEMO-'.(4800 + $n),
                'checkinDate' => $in->format('Y-m-d'), 'checkoutDate' => $out->format('Y-m-d'),
                'reservationFor' => ['@type' => 'LodgingBusiness', 'name' => $provider[2], 'address' => ['addressLocality' => $town, 'postalCode' => $job->postcode]],
                'totalPrice' => $price, 'priceCurrency' => 'GBP', 'underName' => ['name' => 'Demo User']]);
            $raw = "From: {$provider[0]} <{$provider[1]}>\r\nSubject: Booking confirmed: {$provider[2]}\r\nDate: ".$in->subWeeks(3)->format('D, d M Y').' 09:00:00 +0000'
                ."\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><head><script type=\"application/ld+json\">{$data}</script></head><body><p>Your stay at {$provider[2]}, check-in "
                .$in->format('j F Y').".</p></body></html>\r\n";
            $doc = $intake->store($raw, "booking-{$n}.eml", 'message/rfc822', 'gmail', ['from' => "{$provider[0]} <{$provider[1]}>", 'subject' => "Booking confirmed: {$provider[2]}", 'received_at' => $in->subWeeks(3)]);
            $message = GmailMessage::query()->create(['message_id' => 'demo-'.$n, 'search' => 'hotels', 'email_from' => "{$provider[0]} <{$provider[1]}>",
                'subject' => "Booking confirmed: {$provider[2]}", 'sent_at' => $in->subWeeks(3), 'document_id' => $doc->id]);
            $items = app(TravelReader::class)->read($doc);
            $message->update(['status' => $items ? 'read' : 'no_booking']);
        }
    }

    /**
     * A job that isn't in the app yet, spread over two calendar entries, a job invite email and a hotel stay, plus a
     * dentist appointment that isn't work: the job finder (rules only, no AI call) groups the first three.
     */
    private function demoJobFinder(): void
    {
        foreach ([
            ['Load in: Spring Showcase', '2025-03-12 07:00:00', '2025-03-12 19:00:00', 'O2 Academy Brixton, SW9 9SL', false],
            ['Spring Showcase show day', '2025-03-13 00:00:00', '2025-03-14 00:00:00', 'O2 Academy Brixton', true],
            ['Dentist', '2025-03-14 09:00:00', '2025-03-14 09:30:00', null, false],
        ] as [$summary, $start, $end, $location, $allDay]) {
            CalendarEvent::query()->create(['uid' => 'demo-finder-'.md5($summary), 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
        }
        $raw = "From: Brightside Live <crew@example.com>\r\nSubject: Crew invite: Spring Showcase, Brixton\r\nDate: Mon, 3 Feb 2025 10:00:00 +0000\r\n\r\n"
            ."Hi, we'd love you on the Spring Showcase at O2 Academy Brixton on 12-13 March 2025.\r\nLoad in 07:00 on 12 March, show day 13 March. Day rate 450.00 GBP.\r\nBrightside Live\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'invite.eml', 'message/rfc822', 'gmail', ['from' => 'Brightside Live <crew@example.com>', 'subject' => 'Crew invite: Spring Showcase, Brixton', 'received_at' => '2025-02-03 10:00:00']);
        app(DocumentReader::class)->save($doc, [
            'kind' => 'job_invite', 'summary' => 'Brightside Live invite you to crew the Spring Showcase at O2 Academy Brixton on 12–13 March 2025.',
            'job_link' => ['job_id' => null, 'reason' => null],
            'new_job' => ['title' => 'Spring Showcase', 'client' => 'Brightside Live', 'starts_on' => '2025-03-12', 'ends_on' => '2025-03-13', 'venue' => 'O2 Academy Brixton', 'postcode' => 'SW9 9SL',
                'quote' => 'Spring Showcase at O2 Academy Brixton on 12-13 March 2025'],
            'facts' => [
                ['field' => 'load_in', 'label' => 'Load-in', 'value' => '07:00', 'date' => '2025-03-12', 'time' => '07:00', 'quote' => 'Load in 07:00 on 12 March', 'page' => null],
                ['field' => 'rate', 'label' => 'Day rate', 'value' => '£450', 'date' => null, 'time' => null, 'quote' => 'Day rate 450.00 GBP', 'page' => null],
            ],
            'po' => null, 'receipt' => null,
        ], [], 'demo (no AI call)');
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-03-11', 'ends_on' => '2025-03-13',
            'place' => 'Premier Inn London Brixton', 'postcode' => 'SW9 8PQ', 'amount_minor' => 18400, 'currency' => 'GBP', 'source' => 'manual', 'evidence' => []]);

        app(JobFinder::class)->run('2024-25', false);
        app(Verifier::class)->recheck(200, 0, false);
    }

    /** A fictional PO, read as if by the AI (no API call), to show the job sheet and invoice drafting. */
    private function demoPurchaseOrder(WorkJob $job): void
    {
        $date = CarbonImmutable::instance($job->starts_on);
        $pdf = new Dompdf;
        $pdf->loadHtml("<h1>Purchase Order PO-7781</h1><p>{$job->client->name}, 12 Wharf Street, Manchester M1 2AB. Accounts: accounts@example.com</p>"
            ."<p>Event: {$job->title} at {$job->location}, {$date->format('j F Y')}. Call time 07:00, load-in 07:30, show finishes 23:00.</p>"
            .'<p>Travel: rail tickets booked by production. Hotel: Premier Inn, 1 night, booked and paid by production.</p>'
            .'<p>Parking: crew car park C, pass at stage door.</p>'
            .'<table><tr><td>Show day technician</td><td>1 x 950.00</td><td>950.00</td></tr><tr><td>Prep day</td><td>1 x 350.00</td><td>350.00</td></tr></table>'
            .'<p>Total 1,300.00 GBP. Payment terms: 30 days from invoice.</p>');
        $pdf->render();
        $doc = app(DocumentIntake::class)->store($pdf->output(), 'PO-7781.pdf', 'application/pdf', 'email', [
            'from' => 'bookings@example.com', 'subject' => 'Booking confirmation and PO for '.$job->title, 'received_at' => $date->subDays(20),
        ]);
        $quote = fn (string $q) => $q;
        app(DocumentReader::class)->save($doc, [
            'kind' => 'po', 'summary' => "PO-7781 from {$job->client->name} for {$job->title}: show day and prep day, £1,300 total. Hotel and rail booked by production.",
            'job_link' => ['job_id' => $job->id, 'reason' => 'Same client, venue and date'], 'new_job' => null,
            'facts' => [
                ['field' => 'call_time', 'label' => 'Call time', 'value' => '07:00', 'date' => $date->format('Y-m-d'), 'time' => '07:00', 'quote' => $quote('Call time 07:00'), 'page' => 1],
                ['field' => 'load_in', 'label' => 'Load-in', 'value' => '07:30', 'date' => $date->format('Y-m-d'), 'time' => '07:30', 'quote' => 'load-in 07:30', 'page' => 1],
                ['field' => 'finish_time', 'label' => 'Show finishes', 'value' => '23:00', 'date' => $date->format('Y-m-d'), 'time' => '23:00', 'quote' => 'show finishes 23:00', 'page' => 1],
                ['field' => 'venue', 'label' => 'Venue', 'value' => $job->location, 'date' => null, 'time' => null, 'quote' => "at {$job->location}", 'page' => 1],
                ['field' => 'travel', 'label' => 'Travel', 'value' => 'Rail tickets booked by production', 'date' => null, 'time' => null, 'quote' => 'rail tickets booked by production', 'page' => 1],
                ['field' => 'accommodation', 'label' => 'Hotel', 'value' => 'Premier Inn, 1 night, booked and paid by production', 'date' => null, 'time' => null, 'quote' => 'Hotel: Premier Inn, 1 night, booked and paid by production', 'page' => 1],
                ['field' => 'parking', 'label' => 'Parking', 'value' => 'Crew car park C, pass at stage door', 'date' => null, 'time' => null, 'quote' => 'crew car park C, pass at stage door', 'page' => 1],
                ['field' => 'contact', 'label' => 'Production manager', 'value' => 'Sam (mobile on the call sheet)', 'date' => null, 'time' => null, 'quote' => 'Production manager Sam', 'page' => 1],
            ],
            'po' => [
                'po_number' => 'PO-7781', 'bill_to_name' => $job->client->name, 'bill_to_address' => "12 Wharf Street\nManchester M1 2AB", 'bill_to_email' => 'accounts@example.com',
                'currency' => 'GBP', 'payment_terms_days' => 30, 'total' => ['amount_minor' => 130000, 'quote' => 'Total 1,300.00 GBP'],
                'lines' => [
                    ['description' => 'Show day technician', 'quantity' => 1, 'unit_price_minor' => 95000, 'amount_minor' => 95000, 'quote' => 'Show day technician 1 x 950.00 950.00'],
                    ['description' => 'Prep day', 'quantity' => 1, 'unit_price_minor' => 35000, 'amount_minor' => 35000, 'quote' => 'Prep day 1 x 350.00 350.00'],
                ],
            ],
            'receipt' => null,
        ], [['id' => $job->id]], 'demo (no AI call)');
        app(InvoiceService::class)->draftFromDocument($doc->fresh('job'));
    }
}
