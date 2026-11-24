<?php

namespace Tests\Feature\Domain;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\LineDetails;
use App\Domain\Import\ProfileDetector;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\TransferPairer;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Each ledger line shows its time, place, card, currency and everything it's connected to. */
class LineDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_are_read_from_whatever_columns_the_statement_has(): void
    {
        $amex = LineDetails::from(['Date' => '04/04/2025', 'Address' => '', 'Town/City' => 'MANCHESTER', 'Postcode' => 'M1 1AA', 'Country' => 'UNITED KINGDOM']);
        $this->assertSame('Manchester, M1 1AA', $amex['place']);
        $this->assertNull($amex['time']);

        $abroad = LineDetails::from(['Town/City' => 'PARIS', 'Country' => 'FRANCE']);
        $this->assertSame('Paris, France', $abroad['place']);

        $revolut = LineDetails::from(['Type' => 'CARD_PAYMENT', 'Started Date' => '2025-07-03 14:23:10', 'Completed Date' => '2025-07-04 09:00:01', 'Description' => 'Le Café']);
        $this->assertSame(['14:23', 'Card Payment'], [$revolut['time'], $revolut['payment_type']]);

        $utc = LineDetails::from(['Date started (UTC)' => '2025-07-03 13:23:10'], null, CarbonImmutable::parse('2025-07-03'));
        $this->assertSame('14:23', $utc['time'], 'UTC times are shown in UK time');

        $this->assertSame('4821', LineDetails::from(['Card No.' => '****4821'])['card_last4']);
        $this->assertNull(LineDetails::from(['Date' => '03/07/2025 00:00'])['time'], 'A midnight time on a date is no time');
        $this->assertSame('Leeds', LineDetails::from([], 'TESCO STORES 3021      LEEDS        GB')['place']);
        $this->assertSame('Madrid, ES', LineDetails::from([], 'CAFE CENTRAL  MADRID   ES')['place']);
        $this->assertNull(LineDetails::from([], 'TESCO STORES 3021 LEEDS GB')['place'], 'Only fixed-width card lines are read this way');
    }

    public function test_imported_lines_keep_their_details_and_the_panel_shows_every_link(): void
    {
        Storage::fake('local');
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        $this->actingAs(User::factory()->create());
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex', 'last4' => '1004']);
        $path = base_path('tests/fixtures/amex.csv');
        app(Importer::class)->commit($amex, $path, 'amex.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'credit_card'));

        $hotel = BankTransaction::query()->where('description', 'like', 'PREMIER INN%')->sole();
        $this->assertSame('Manchester, M1 1AA', $hotel->place);

        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-04-04', 'ends_on' => '2025-04-05', 'status' => 'done']);
        $cat = Category::query()->where('kind', 'expense')->where('is_allowable', true)->value('id');
        app(AllocationWriter::class)->decide($hotel, AllocationWriter::lines($hotel->amount_pence, 'business_expense', $cat, $job->id));
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'place' => 'Manchester', 'starts_on' => '2025-04-04', 'ends_on' => '2025-04-05', 'status' => 'confirmed',
            'amount_minor' => 8900, 'currency' => 'GBP', 'bank_transaction_id' => $hotel->id, 'source' => 'gmail']);
        Document::query()->create(['source' => 'upload', 'original_name' => 'receipt.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('b', 64), 'stored_path' => 'x',
            'kind' => 'receipt', 'status' => 'extracted', 'received_at' => '2025-04-05 10:00:00', 'extraction' => ['receipt' => ['merchant' => 'Premier Inn', 'date' => '2025-04-04', 'amount_minor' => 8900, 'currency' => 'GBP']]]);

        $panel = $this->getJson("/items/bank/{$hotel->id}")->assertOk()->json();
        $this->assertSame('Manchester, M1 1AA', $panel['item']['place']);
        $this->assertSame(['job', 'travel', 'receipt'], collect($panel['links'])->pluck('type')->all());
        $this->assertSame('/jobs/'.$job->id, $panel['links'][0]['href']);

        // A transfer shows its other side.
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $bank->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x']);
        $out = BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-04-20', 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable('2025-04-20'))->label(),
            'description' => 'AMERICAN EXPRESS', 'counterparty' => 'AMERICAN EXPRESS', 'merchant_key' => 'AMERICAN EXPRESS', 'amount_pence' => -10740, 'raw' => [], 'fingerprint' => str_repeat('d', 64), 'occurrence' => 1]);
        $in = BankTransaction::query()->create(['account_id' => $amex->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-04-21', 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable('2025-04-21'))->label(),
            'description' => 'PAYMENT RECEIVED - THANK YOU', 'merchant_key' => 'PAYMENT RECEIVED', 'amount_pence' => 10740, 'raw' => [], 'fingerprint' => str_repeat('e', 64), 'occurrence' => 1]);
        app(TransferPairer::class)->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id);
        $links = $this->getJson("/items/bank/{$out->id}")->json('links');
        $this->assertSame('transfer', $links[0]['type']);
        $this->assertStringContainsString('Amex', $links[0]['label']);
    }

    public function test_foreign_currency_lines_show_the_rate_the_bank_used(): void
    {
        $this->seed();
        $this->actingAs(User::factory()->create());
        $acc = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 'r.csv', 'file_sha256' => str_repeat('f', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-07-03', 'tax_year' => '2025-26', 'description' => 'Le Café',
            'merchant_key' => 'LE CAFE', 'amount_pence' => -1720, 'original_amount_minor' => -2000, 'original_currency' => 'EUR', 'raw' => [], 'fingerprint' => str_repeat('a', 64), 'occurrence' => 1,
            'transacted_time' => '14:23', 'place' => 'Paris, France', 'card_last4' => '4821', 'payment_type' => 'Card Payment']);

        $this->get('/transactions')->assertInertia(fn ($p) => $p
            ->where('page.data.0.time', '14:23')
            ->where('page.data.0.place', 'Paris, France')
            ->where('page.data.0.card_last4', '4821')
            ->where('page.data.0.original_currency', 'EUR')
            ->where('page.data.0.fx.units_per_gbp', '1.1628'));
    }
}
