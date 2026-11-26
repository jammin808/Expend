<?php

namespace Database\Seeders;

use App\Domain\Ai\DocumentReader;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Income\Lines;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobStatus;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Suggester;
use App\Domain\Travel\TravelReader;
use App\Domain\Verification\Verifier;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Client;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
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

        $this->demoClientsAndIncome($starling);
    }

    /**
     * Clients, jobs at each stage, invoices and money in for the client and income screens: an agency that pays for a
     * festival (a payer and an end client), a likely duplicate of Acme Events Ltd, a paid invoice, an issued and sent
     * one with its payment waiting as a suggestion, a part-paid one, a payment from a known payer with nothing to
     * match, a draft whose lines changed after it was made, an issued one whose Gmail draft was deleted unsent, and
     * jobs booked, just finished, done and cancelled. Through the app's own services, so every rule holds; fictional
     * throughout.
     */
    private function demoClientsAndIncome(Account $bank): void
    {
        // An invoice carries your name and address.
        if (trim((string) Setting::get('business_name')) === '') {
            Setting::put('business_name', 'Demo Sound and Light');
            Setting::put('business_address', "1 Example Street\nBristol BS1 1AA");
        }
        $acme = Client::query()->where('name', 'Acme Events Ltd')->firstOrFail();
        Client::query()->firstOrCreate(['name' => 'Acme Events']); // the same client typed again: offered for merging
        $agency = Client::query()->firstOrCreate(['name' => 'Stagecraft Crew Agency'], ['billing_email' => 'crew@stagecraft.example', 'payment_terms_days' => 30]);
        $festival = Client::query()->firstOrCreate(['name' => 'Harbour Arts Festival']);

        $job = fn (string $title, Client $client, string $from, string $to, int $fee, string $status, ?Client $end = null) => WorkJob::query()->create([
            'client_id' => $client->id, 'end_client_id' => $end?->id, 'title' => $title, 'starts_on' => $from, 'ends_on' => $to,
            'location' => 'Harbourside, Bristol', 'postcode' => 'BS1 5DB', 'fee_pence' => $fee, 'status' => $status,
        ]);
        $summer = $job('Summer conference crew', $agency, '2025-06-10', '2025-06-11', 60_000, 'done', $festival);
        $harbour = $job('Harbour Festival main stage', $agency, '2025-08-15', '2025-08-17', 176_000, 'done', $festival);
        $gala = $job('Winter gala', $acme, '2025-12-05', '2025-12-05', 95_000, 'done');
        $job('Spring showcase rehearsal', $agency, '2026-02-20', '2026-02-20', 42_000, 'done', $festival); // nothing billed yet
        $awards = $job('Autumn awards night', $acme, '2026-03-14', '2026-03-14', 85_000, 'booked');
        $job('Arena load-out', $agency, now()->subDays(5)->format('Y-m-d'), now()->subDays(4)->format('Y-m-d'), 52_000, 'booked', $festival); // "Is it done?"
        $job('New Year party', $acme, now()->addDays(36)->format('Y-m-d'), now()->addDays(36)->format('Y-m-d'), 70_000, 'booked');
        $job('Corporate away day', $acme, '2026-10-02', '2026-10-02', 40_000, 'cancelled');

        $invoices = app(InvoiceService::class);
        $issue = function (WorkJob $job, string $on, string $to) use ($invoices): Invoice {
            $invoice = $invoices->issue($invoices->draftForJob($job), $on);
            $invoice->forceFill(['sent_at' => CarbonImmutable::parse($on)->addDay()->setTime(9, 30), 'sent_to' => $to])->save();

            return $invoice;
        };

        $batch = ImportBatch::query()->create(['account_id' => $bank->id, 'original_filename' => 'starling-demo-income.csv', 'file_sha256' => hash('sha256', 'demo-income'),
            'stored_path' => 'demo', 'status' => 'committed']);
        $n = 0;
        $pay = function (string $on, string $payer, int $pence, string $ref) use ($bank, $batch, &$n): BankTransaction {
            $n++;

            return BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $on,
                'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $payer.' '.$ref, 'counterparty' => $payer, 'reference' => $ref,
                'merchant_key' => strtoupper($payer), 'amount_pence' => $pence, 'bank_category' => $pence > 0 ? 'INCOME' : 'TRANSPORT', 'raw' => [],
                'fingerprint' => 'demo-income-'.$n]);
        };
        $writer = app(AllocationWriter::class);

        // Paid: the agency's first payment, decided by you, so Expend knows who STAGECRAFT CREW pays for.
        $paid = $issue($summer, '2025-06-12', 'crew@stagecraft.example');
        $writer->decide($pay('2025-07-01', 'Stagecraft Crew', 60_000, 'SUMMER CONF'), [Lines::income(60_000, $paid->id)], 'you', true);
        // Issued and sent; its payment is waiting in the Inbox as a suggestion.
        $issue($harbour, '2025-08-20', 'crew@stagecraft.example');
        // The agency pays travel at cost, and a taxi was linked to the festival after its invoice went: a late cost, on
        // their next invoice (the sent one never changes).
        app(ChargeTermsWriter::class)->applyPreset($agency, 'at_cost', null, 'per_job', 'Demo: the agency pays travel at cost');
        $writer->decide($pay('2025-08-25', 'Addison Lee', -1_820, 'TAXI'), AllocationWriter::lines(-1_820, 'business_expense',
            (int) Category::query()->where('code', 'travel_taxi')->value('id'), $harbour->id), 'you', true);
        // Part paid: £500 of £950 came in.
        $part = $issue($gala, '2025-12-08', 'accounts@acme.example');
        $writer->decide($pay('2026-01-10', 'Acme Events Ltd', 50_000, 'GALA PART'), [Lines::income(50_000, $part->id)], 'you', true);

        // Waiting: the festival's payment (matches what's still to come on its invoice) and one with nothing to match.
        $waiting = collect([$pay('2025-09-18', 'Stagecraft Crew', 176_000, 'HARBOUR FEST'), $pay('2026-03-10', 'Stagecraft Crew', 42_000, 'REHEARSAL')]);
        app(Suggester::class)->suggestFor(BankTransaction::query()->whereKey($waiting->pluck('id'))->get());

        // Done, with a draft; a taxi on the job arrives afterwards, so the draft shows what changed since you looked.
        // The job has its own arrangement (mileage at HMRC's rate, fares at cost; Acme has no usual one yet).
        app(ChargeTermsWriter::class)->applyPreset($awards, 'mileage', null, 'per_job', 'Demo: Acme pays mileage and fares on this job');
        $draft = app(JobStatus::class)->markDone($awards)['invoice'];
        $taxi = (int) Category::query()->where('code', 'travel_taxi')->value('id');
        $writer->decide($pay('2026-03-14', 'Uber', -2_350, 'TRIP'), AllocationWriter::lines(-2_350, 'business_expense', $taxi, $awards->id), 'you', true);
        if ($draft) {
            app(InvoiceDraftSync::class)->sync($draft);
        }

        // The client area (Acme): a rate and the days agreed on the party ahead, a job cancelled late with a fee to
        // invoice, and parts bought for them with no job, charged back on their next invoice.
        WorkJob::query()->where('title', 'New Year party')->update(['rate_pence' => 35_000, 'rate_unit' => 'day', 'days_agreed' => 1]);
        $launch = $job('Product launch rehearsal', $acme, now()->subDays(12)->format('Y-m-d'), now()->subDays(12)->format('Y-m-d'), 60_000, 'booked');
        app(JobStatus::class)->cancel($launch, 'fee', 15_000);
        $parts = $pay(now()->subDays(20)->format('Y-m-d'), 'Stage Electrics', -2_499, 'CABLES');
        $writer->decide($parts, AllocationWriter::lines(-2_499, 'business_expense', (int) Category::query()->where('code', 'materials')->value('id'), null), 'you', true);
        app(CostCharges::class)->rechargeToClient($parts->fresh(), $acme->id, 'Demo: spare cables for their stage');

        // The to-do list's "Send invoice": issued, put in Gmail as a draft, and the draft deleted there without being
        // sent, so it asks whether it went another way (with "Pick a date" for the day it went).
        $openDay = $job('Harbour Arts open day', $festival, now()->subDays(8)->format('Y-m-d'), now()->subDays(8)->format('Y-m-d'), 30_000, 'done');
        $unsent = $invoices->issue($invoices->draftForJob($openDay), now()->subDays(6)->format('Y-m-d'));
        InvoiceEmail::query()->create(['invoice_id' => $unsent->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'deleted',
            'to_address' => 'office@harbourarts.example', 'subject' => 'Invoice '.$unsent->number, 'drafted_at' => now()->subDays(6), 'checked_at' => now()->subDays(5)]);
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
