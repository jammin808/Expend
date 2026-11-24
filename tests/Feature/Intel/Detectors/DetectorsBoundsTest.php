<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\Health;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Intel\RefResolver;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every registered detector: never writes (DB::listen), reports in the shared vocabulary (integer amounts, valid refs,
 * grades and directions), stays within a time bound on a large ledger (thousands of lines), and the to-do list's build
 * stays near what it was before M4 (on the same ledger: 135 queries and about 0.7s at the commit before; the new
 * checks are worked out apart, below the fold).
 */
class DetectorsBoundsTest extends TestCase
{
    use RefreshDatabase;

    /** Seconds any one detector may take on the large ledger (generous: CI and MariaDB are slower than a laptop). */
    private const DETECTOR_SECONDS = 10;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> every write's SQL while $work runs */
    private function writes(callable $work): array
    {
        $writes = [];
        DB::listen(function (QueryExecuted $q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $work();

        return $writes;
    }

    /** @return array<string, list<FindingDraft>> each registered detector's drafts, by its code */
    private function detectAll(): array
    {
        $out = [];
        foreach (app(Registry::class)->all() as $detector) {
            $out[$detector->code()] = iterator_to_array((fn () => yield from $detector->detect(new CycleContext))(), false);
            $this->assertNotSame(Coverage::Failed, $detector->coverage(), $detector->code().' ran in full');
        }

        return $out;
    }

    private function tx(string $on, int $pence, string $name, ?Account $acc = null): BankTransaction
    {
        $acc ??= Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't'.$acc->id.'.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n),
            'occurrence' => 1]);
    }

    /** Something for every M4 detector to find. */
    private function everyKind(): void
    {
        $writer = app(AllocationWriter::class);
        $cat = fn (string $code) => Category::query()->where('code', $code)->value('id');
        // Statements' span, income with no invoice, a cost with no receipt and a receipt with no payment.
        $this->tx('2026-04-07', -500, 'First');
        $income = $this->tx('2026-05-20', 4500000, 'ACME EVENTS'); // enough profit for tax to be saved
        $writer->decide($income, AllocationWriter::lines(4500000, 'business_income', $cat('turnover')));
        $cost = $this->tx('2026-05-21', -25000, 'THOMANN');
        $writer->decide($cost, AllocationWriter::lines(-25000, 'business_expense', $cat('equipment')));
        Document::query()->create(['source' => 'email', 'original_name' => 'r.pdf', 'sha256' => hash('sha256', 'r'), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'extracted',
            'received_at' => '2026-06-01 09:00:00', 'text' => 'SECRET', 'extraction' => ['receipt' => ['merchant' => 'Pret', 'date' => '2026-06-01', 'amount_minor' => 777, 'currency' => 'GBP']]]);
        $this->tx('2026-11-20', -500, 'Last');
        // A job to close out: its PO and no invoice.
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'status' => 'done', 'postcode' => 'B40 1NT']);
        Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted',
            'work_job_id' => $job->id, 'job_linked_by' => 'you', 'extraction' => ['po' => ['stated_total_minor' => 120000, 'currency' => 'GBP', 'total_verified' => true, 'lines' => []]]]);
        // Mileage and a train the same day.
        $van = Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        MileageTrip::query()->create(['vehicle_id' => $van->id, 'work_job_id' => $job->id, 'trip_on' => '2026-06-03', 'tax_year' => '2026-27', 'from_place' => 'Home', 'to_place' => 'NEC', 'miles' => 80]);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'Trainline', 'status' => 'booked', 'starts_on' => '2026-06-03', 'amount_minor' => 6000, 'source' => 'manual', 'evidence' => [],
            'work_job_id' => $job->id, 'decision' => 'business', 'decided_at' => now()]);
        // A cash expense also on the card.
        $cash = ManualExpense::query()->create(['spent_on' => '2026-06-10', 'tax_year' => '2026-27', 'description' => 'Screwfix', 'merchant_key' => 'SCREWFIX', 'paid_with' => 'cash', 'amount_pence' => -4599]);
        $writer->decide($cash, AllocationWriter::lines(-4599, 'business_expense', $cat('materials')));
        $this->tx('2026-06-10', -4599, 'SCREWFIX');
        // A rule you keep changing.
        $rule = MerchantRule::query()->forceCreate(['merchant_key' => 'AMAZON', 'kind' => 'business_expense', 'category_id' => $cat('equipment'), 'business_pct' => 100, 'auto_confirm' => true]);
        foreach (['2026-10-01', '2026-10-08'] as $on) {
            $t = $this->tx($on, -3000, 'AMAZON');
            $writer->decide($t, AllocationWriter::lines(-3000, 'business_expense', $cat('equipment')), 'rule', true, 'Your rule', $rule->id);
            $writer->decide($t, AllocationWriter::lines(-3000, 'personal'));
        }
        // A card that can't be checked; a scan that can't be checked word for word; the mailbox not read.
        $card = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card']);
        $this->tx('2026-10-02', -1500, 'SHOP', $card);
        $scan = Document::query()->create(['source' => 'upload', 'original_name' => 's.jpg', 'sha256' => hash('sha256', 's'), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'extracted',
            'extraction' => ['receipt' => ['amount_minor' => 1250, 'currency' => 'GBP']]]);
        Verification::query()->create(['subject_type' => 'document', 'subject_id' => $scan->id, 'status' => 'unverifiable', 'method' => 'source_text', 'checks' => [], 'summary' => 'A scan.', 'checked_at' => now()]);
        Setting::put('imap_host', 'imap.example.test');
        Setting::put('imap_username', 'inbox@example.test');
        Setting::put('imap_password', 'not-a-real-one');
        Setting::put(Health::MAILBOX_RESULT, ['ok' => false, 'at' => now()->toIso8601String()]);
        // A locked year whose figures have changed since: its snapshot says otherwise.
        $year = TaxYear::track('2025-26');
        $year->update(['locked_at' => now()]);
        AuditLog::record('tax_year.locked', $year, null, ['snapshot' => ['version' => 1, 'figures' => ['turnover_pence' => 100, 'other_income_pence' => 0, 'allowable_expenses_pence' => 0,
            'disallowable_expenses_pence' => 0, 'mileage_pence' => 0, 'net_profit_pence' => 100]]]);
    }

    public function test_every_detector_finds_its_kind_reads_only_and_speaks_the_vocabulary(): void
    {
        $this->everyKind();
        $found = [];
        $this->assertSame([], $this->writes(function () use (&$found) {
            $found = $this->detectAll();
        }), 'Detectors never write');
        foreach (['job.closeout', 'travel.double_claim', 'income.unlinked', 'receipt.unmatched', 'cost.no_receipt', 'dupe.cross_source', 'rule.drift', 'tax.saving', 'acct.unchecked',
            'check.unverifiable', 'year.locked_drift', 'health'] as $code) {
            $this->assertNotEmpty($found[$code], $code.' found something');
        }
        $refs = [];
        foreach (app(Registry::class)->all() as $detector) {
            $codes = $detector instanceof CoversCodes ? $detector->codes() : [$detector->code()];
            foreach ($found[$detector->code()] as $d) {
                $this->assertContains($d->code, $codes, $d->key);
                $this->assertTrue($d->amountPence === null || is_int($d->amountPence), $d->key.': whole pence');
                $this->assertContains($d->direction, FindingDraft::DIRECTIONS, $d->key);
                $this->assertTrue($d->grade === null || Grades::valid($d->grade), $d->key);
                $this->assertTrue($d->subjectRef === null || Ref::valid($d->subjectRef), $d->key);
                $this->assertTrue($d->scopeRef === null || Ref::valid($d->scopeRef), $d->key);
                $this->assertSame($d->key, mb_substr($d->key, 0, 191), $d->key);
                $refs = [...$refs, ...$d->evidence];
                if (in_array($d->code, Health::CODES, true) || in_array($d->code, ['acct.unchecked', 'check.unverifiable'], true)) {
                    $this->assertNull($d->grade, $d->code.' has no grade: about Expend, not your money');
                }
            }
        }
        // Every ref they cite is there.
        foreach (RefResolver::resolveMany(array_values(array_unique($refs))) as $ref => $r) {
            $this->assertTrue($r['exists'], $ref);
        }
        // And on the list or below the fold, and in the findings history, like M1's.
        $list = TodoList::build(100);
        $keys = array_column([...$list['items'], ...$list['below'], ...TodoList::belowFold(100)['items']], 'key');
        foreach ($found as $drafts) {
            foreach ($drafts as $d) {
                $this->assertContains($d->key, $keys);
            }
        }
        FindingsRegistry::sync();
        $this->assertGreaterThanOrEqual(12, Finding::query()->whereIn('code', array_keys($found))->orWhere('code', 'like', 'health.%')->count());
        Http::assertNothingSent();
    }

    public function test_on_a_large_ledger_each_detector_stays_within_its_bound_and_the_list_near_what_it_was(): void
    {
        self::bigLedger();
        $this->everyKind();
        foreach (app(Registry::class)->all() as $detector) {
            $started = microtime(true);
            $writes = $this->writes(fn () => iterator_to_array((fn () => yield from $detector->detect(new CycleContext))(), false));
            $this->assertSame([], $writes, $detector->code().' never writes');
            $this->assertLessThan(self::DETECTOR_SECONDS, microtime(true) - $started, $detector->code().' within its bound');
        }

        // The list: about as many queries as before M4 (135 on this ledger at the commit before), whatever it holds.
        Cache::flush();
        $count = function (callable $work): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $work();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $started = microtime(true);
        $list = $count(fn () => TodoList::build());
        $this->assertLessThan(200, $list, "The list took {$list} queries");
        $this->assertLessThan(15, microtime(true) - $started);
        // What's below the fold is apart, and bounded too (the tax savings review reads each open year).
        $below = $count(fn () => TodoList::belowFold());
        $this->assertLessThan(500, $below, "Below the fold took {$below} queries");
    }

    /** A large ledger: thousands of lines with decisions, jobs, invoices, bookings, receipts and cash expenses. */
    private static function bigLedger(int $lines = 4000): void
    {
        mt_srand(42);
        $now = now();
        $cat = DB::table('categories')->pluck('id', 'code');
        $bank = DB::table('accounts')->insertGetId(['name' => 'Starling', 'kind' => 'bank', 'currency' => 'GBP', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $card = DB::table('accounts')->insertGetId(['name' => 'Amex', 'kind' => 'credit_card', 'currency' => 'GBP', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $batches = [];
        foreach ([$bank, $card] as $acc) {
            $batches[$acc] = DB::table('import_batches')->insertGetId(['account_id' => $acc, 'original_filename' => 'big'.$acc.'.csv', 'file_sha256' => hash('sha256', 'big'.$acc), 'stored_path' => 'x',
                'status' => 'committed', 'first_date' => '2024-04-06', 'last_date' => '2026-11-20', 'created_at' => $now, 'updated_at' => $now]);
        }
        $merchants = ['TRAINLINE', 'SHELL FUEL', 'PREMIER INN', 'AMAZON', 'THOMANN', 'PRET A MANGER', 'TESCO STORES', 'ADOBE', 'APPLE', 'UBER', 'TFL TRAVEL', 'SCREWFIX',
            'B AND Q', 'CURRYS', 'ARGOS', 'NCP PARKING', 'BP FUEL', 'ESSO', 'TRAVELODGE', 'WICKES', 'ACME EVENTS', 'STAGE CO', 'HMRC SELF ASSESSMENT', 'VIRGIN MEDIA', 'EE MOBILE'];
        $cats = ['TRAINLINE' => 'travel_rail', 'SHELL FUEL' => 'travel_fuel', 'PREMIER INN' => 'travel_accommodation', 'AMAZON' => 'equipment', 'THOMANN' => 'equipment', 'PRET A MANGER' => 'travel_subsistence',
            'ADOBE' => 'software', 'UBER' => 'travel_taxi', 'TFL TRAVEL' => 'travel_local', 'SCREWFIX' => 'materials', 'NCP PARKING' => 'travel_parking', 'BP FUEL' => 'travel_fuel',
            'TRAVELODGE' => 'travel_accommodation', 'VIRGIN MEDIA' => 'phone_internet', 'EE MOBILE' => 'phone_internet', 'CURRYS' => 'equipment', 'WICKES' => 'materials'];
        $start = CarbonImmutable::parse('2024-04-06');
        $days = (int) $start->diffInDays(CarbonImmutable::parse('2026-11-20'));
        $rows = [];
        $allocs = [];
        $balance = 500000;
        for ($i = 1; $i <= $lines; $i++) {
            $date = $start->addDays((int) floor($i * $days / $lines));
            $m = $merchants[$i % count($merchants)];
            $in = in_array($m, ['ACME EVENTS', 'STAGE CO'], true);
            $amount = $in ? 30000 + ($i % 7) * 5000 : -(500 + ($i * 37) % 25000);
            $acc = $i % 5 === 0 ? $card : $bank;
            $balance += $amount;
            $label = $date->month >= 4 && ! ($date->month === 4 && $date->day < 6) ? $date->year.'-'.substr((string) ($date->year + 1), 2) : ($date->year - 1).'-'.substr((string) $date->year, 2);
            $rows[] = ['account_id' => $acc, 'import_batch_id' => $batches[$acc], 'posted_on' => $date->format('Y-m-d'), 'tax_year' => $label, 'description' => $m.' '.($i % 97),
                'counterparty' => $m, 'merchant_key' => $m, 'amount_pence' => $amount, 'balance_after_pence' => $acc === $bank ? $balance : null, 'raw' => '[]',
                'fingerprint' => md5('big'.$i), 'occurrence' => 1, 'review_status' => $i % 10 === 0 ? 'unreviewed' : 'confirmed', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bank_transactions')->insert($chunk);
        }
        $txs = DB::table('bank_transactions')->orderBy('id')->get(['id', 'amount_pence', 'tax_year', 'merchant_key', 'review_status']);
        foreach ($txs as $t) {
            if ($t->review_status !== 'confirmed') {
                continue;
            }
            $kind = $t->amount_pence > 0 ? 'business_income' : (isset($cats[$t->merchant_key]) ? 'business_expense' : 'personal');
            $allocs[] = ['bank_transaction_id' => $t->id, 'tax_year' => $t->tax_year, 'kind' => $kind,
                'category_id' => $kind === 'business_expense' ? $cat[$cats[$t->merchant_key]] : ($kind === 'business_income' ? $cat['turnover'] : null),
                'amount_pence' => $t->amount_pence, 'business_use_pct' => $kind === 'business_expense' ? 100 : null, 'decided_by' => 'you', 'is_manual' => true, 'confirmed_at' => $now,
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($allocs, 500) as $chunk) {
            DB::table('allocations')->insert($chunk);
        }
        // Jobs, mostly finished, with invoices and days.
        $client = DB::table('clients')->insertGetId(['name' => 'Acme Events', 'created_at' => $now, 'updated_at' => $now]);
        $jobRows = [];
        for ($j = 1; $j <= 250; $j++) {
            $d = $start->addDays($j * 3);
            $jobRows[] = ['client_id' => $client, 'title' => 'Job '.$j, 'starts_on' => $d->format('Y-m-d'), 'ends_on' => $d->addDays($j % 3)->format('Y-m-d'), 'postcode' => 'SW1A 1AA',
                'status' => 'done', 'fee_pence' => 100000, 'po_number' => $j % 4 === 0 ? null : 'PO'.$j, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('work_jobs')->insert($jobRows);
        $jobs = DB::table('work_jobs')->orderBy('id')->get(['id', 'starts_on', 'ends_on']);
        $days = [];
        foreach ($jobs as $job) {
            for ($d = CarbonImmutable::parse($job->starts_on); $d->lte(CarbonImmutable::parse($job->ends_on)); $d = $d->addDay()) {
                $days[] = ['work_job_id' => $job->id, 'on_date' => $d->format('Y-m-d'), 'kind' => 'work', 'source' => 'range', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('job_days')->insert($days);
        $n = 0;
        foreach ($jobs as $k => $job) {
            if ($k % 5 === 4) {
                continue;
            }
            $n++;
            $inv = DB::table('invoices')->insertGetId(['number' => 'INV-'.$n, 'client_id' => $client, 'work_job_id' => $job->id, 'issue_date' => $job->ends_on, 'due_date' => $job->ends_on,
                'status' => $k % 3 === 0 ? 'paid' : 'issued', 'bill_to_name' => 'Acme Events', 'total_minor' => 100000, 'po_total_minor' => $k % 6 === 0 ? 110000 : null,
                'issued_at' => $now, 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('invoice_lines')->insert(['invoice_id' => $inv, 'description' => 'Crew day', 'quantity' => 1, 'unit_price_minor' => 100000, 'amount_minor' => 100000, 'created_at' => $now, 'updated_at' => $now]);
        }
        // Bookings: hotels and trains on job days, some to recharge.
        foreach ($jobs->take(120) as $k => $job) {
            DB::table('travel_items')->insert(['kind' => $k % 2 ? 'hotel' : 'rail', 'provider' => $k % 2 ? 'Premier Inn' : 'Trainline', 'reference' => 'R'.$k, 'status' => 'booked',
                'starts_on' => $job->starts_on, 'ends_on' => $k % 2 ? CarbonImmutable::parse($job->starts_on)->addDay()->format('Y-m-d') : null, 'amount_minor' => 8900 + $k, 'currency' => 'GBP',
                'payment' => 'paid', 'source' => 'manual', 'evidence' => '[]', 'work_job_id' => $job->id, 'decision' => $k % 7 === 0 ? 'recharge' : 'business', 'decided_at' => $now,
                'created_at' => $now, 'updated_at' => $now]);
        }
        // Receipts, cash expenses, a car on simplified mileage with trips, statement balances, checks.
        $docs = [];
        for ($r = 1; $r <= 400; $r++) {
            $d = $start->addDays($r * 2);
            $docs[] = ['source' => 'email', 'original_name' => 'receipt'.$r.'.pdf', 'sha256' => hash('sha256', 'r'.$r), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'extracted',
                'received_at' => $d->addDays(3)->format('Y-m-d H:i:s'), 'extraction' => json_encode(['receipt' => ['merchant' => 'Shop '.$r, 'date' => $d->format('Y-m-d'), 'amount_minor' => 500 + ($r * 37) % 25000, 'currency' => 'GBP']]),
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('documents')->insert($docs);
        $exp = [];
        for ($e = 1; $e <= 200; $e++) {
            $d = $start->addDays($e * 4);
            $label = $d->month >= 4 && ! ($d->month === 4 && $d->day < 6) ? $d->year.'-'.substr((string) ($d->year + 1), 2) : ($d->year - 1).'-'.substr((string) $d->year, 2);
            $exp[] = ['spent_on' => $d->format('Y-m-d'), 'tax_year' => $label, 'description' => 'Cash '.$merchants[$e % count($merchants)], 'merchant_key' => $merchants[$e % count($merchants)],
                'paid_with' => 'cash', 'amount_pence' => -(300 + $e * 11), 'review_status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('manual_expenses')->insert($exp);
        $car = DB::table('vehicles')->insertGetId(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified', 'created_at' => $now, 'updated_at' => $now]);
        $trips = [];
        foreach ($jobs->take(150) as $job) {
            $d = CarbonImmutable::parse($job->starts_on);
            $label = $d->month >= 4 && ! ($d->month === 4 && $d->day < 6) ? $d->year.'-'.substr((string) ($d->year + 1), 2) : ($d->year - 1).'-'.substr((string) $d->year, 2);
            $trips[] = ['vehicle_id' => $car, 'work_job_id' => $job->id, 'trip_on' => $job->starts_on, 'tax_year' => $label, 'from_place' => 'Home', 'to_place' => 'Venue', 'miles' => 40, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('mileage_trips')->insert($trips);
        $ver = [];
        foreach (DB::table('documents')->limit(150)->pluck('id') as $k => $id) {
            $ver[] = ['subject_type' => 'document', 'subject_id' => $id, 'status' => $k % 3 ? 'verified' : 'unverifiable', 'method' => 'source_text', 'checks' => '[]', 'summary' => 'x', 'checked_at' => $now,
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('verifications')->insert($ver);
        foreach (['2024-25', '2025-26', '2026-27'] as $label) {
            DB::table('tax_years')->insertOrIgnore(['label' => $label, 'starts_on' => substr($label, 0, 4).'-04-06', 'ends_on' => (substr($label, 0, 4) + 1).'-04-05', 'status' => 'outstanding',
                'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
