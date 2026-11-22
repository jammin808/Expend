<?php

namespace Database\Seeders;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

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

        $car = Vehicle::query()->firstOrCreate(['name' => 'My car'], ['type' => 'car_van', 'method' => 'simplified']);
        foreach (array_slice($jobs, 0, 4) as $job) {
            MileageTrip::query()->create([
                'vehicle_id' => $car->id, 'work_job_id' => $job->id, 'trip_on' => $job->starts_on, 'tax_year' => UkTaxYear::fromDate($job->starts_on)->label(),
                'from_place' => 'Home', 'to_place' => $job->location, 'miles' => '86.0', 'purpose' => 'Drive to venue (return trip)',
            ]);
        }
    }
}
