<?php

namespace Tests\Feature;

use App\Domain\Import\Importer;
use App\Domain\Import\RevolutImport;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Todo\TodoActions;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Transfers between your own accounts: only real evidence of your own money pairs or suggests a line, a third party
 * named on a line vetoes it, your rules and invoice matches come first, and "Confirm all" never confirms money in
 * unseen.
 */
class NewFixesTransfersTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private Account $revolut;

    private Account $amex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('job_finder_auto', false);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $this->amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null, array $extra = []): BankTransaction
    {
        static $n = 0;
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create($extra + [
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => strtoupper($counterparty ?: strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $account->id.$date.$pence.$description.$counterparty.(++$n)), 'occurrence' => 1,
        ]);
    }

    private function kind(BankTransaction $tx): ?string
    {
        return $tx->fresh()->allocations()->value('kind');
    }

    private function batchOf(Account $account): ImportBatch
    {
        return ImportBatch::query()->where('account_id', $account->id)->sole();
    }

    private function category(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    // ---- N1 / N2: your name in a reference next to somebody else's name pairs nothing ------------------------------

    public function test_a_client_reference_naming_you_is_not_paired_with_an_unrelated_payment(): void
    {
        $fee = $this->tx($this->starling, '2025-06-02', 45000, 'Acme Events Ltd A SMITH INV 1042', 'Acme Events Ltd');
        $hire = $this->tx($this->amex, '2025-06-04', -45000, 'PRO HIRE LTD LONDON');
        $fee2 = $this->tx($this->starling, '2025-08-02', 50000, 'ACME EVENTS LTD ALEX SMITH INV42', 'ACME EVENTS LTD');
        $hire2 = $this->tx($this->revolut, '2025-08-04', -50000, 'Lighting hire', 'PRO HIRE LTD');

        $this->assertSame(0, app(TransferPairer::class)->pairAll());
        foreach ([$fee, $hire, $fee2, $hire2] as $tx) {
            $this->assertNull($tx->fresh()->transfer_pair_id, $tx->description);
            $this->assertNotSame('transfer', $this->kind($tx), $tx->description);
        }
    }

    public function test_paying_somebody_with_your_name_as_the_reference_is_not_a_transfer(): void
    {
        $crew = $this->tx($this->starling, '2025-06-02', -30000, 'JOE BLOGGS ALEX SMITH', 'JOE BLOGGS');
        $client = $this->tx($this->revolut, '2025-06-03', 30000, 'INV 1050', 'BIG SHOW LTD');
        $crew2 = $this->tx($this->starling, '2025-07-02', -25000, 'JOE BLOGGS A SMITH', 'JOE BLOGGS');
        $client2 = $this->tx($this->revolut, '2025-07-03', 25000, 'Payment from ACME EVENTS LTD');

        app(Importer::class)->afterImport($this->batchOf($this->starling), $this->batchOf($this->revolut));

        foreach ([$crew, $client, $crew2, $client2] as $tx) {
            $this->assertNull($tx->fresh()->transfer_pair_id, $tx->description);
            $this->assertNotSame('transfer', $this->kind($tx), $tx->description);
            $this->assertNull(OwnAccounts::reason($tx->fresh()), $tx->description);
        }
    }

    public function test_real_moves_between_your_accounts_are_still_paired(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $bill = $this->tx($this->starling, '2025-06-10', -25000, 'American Express AMEX PAYMENT', 'American Express');
        $paid = $this->tx($this->amex, '2025-06-11', 25000, 'PAYMENT RECEIVED - THANK YOU');
        $topUp = $this->tx($this->starling, '2025-06-20', -10000, 'REVOLUT*TOP-UP', 'Revolut');
        $added = $this->tx($this->revolut, '2025-06-20', 10000, 'Top-up by *1234');

        $this->assertSame(3, app(TransferPairer::class)->pairAll());
        $this->assertSame([$in->id, $paid->id, $added->id], [$out->fresh()->transfer_pair_id, $bill->fresh()->transfer_pair_id, $topUp->fresh()->transfer_pair_id]);
        $this->assertSame('transfer', $this->kind($in));
        $this->assertSame(2, AuditEvent::query()->where('action', 'transfer.paired')->where('subject_id', $out->id)->orWhere(fn ($q) => $q->where('action', 'transfer.paired')->where('subject_id', $in->id))->count(), 'Pairing is in the history of both lines');
    }

    public function test_the_strongest_partner_wins_not_the_earliest(): void
    {
        $weaker = $this->tx($this->revolut, '2025-06-01', 50000, 'Top-up by *9999');
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'REVOLUT*TOP-UP', 'Revolut');
        $real = $this->tx($this->revolut, '2025-06-02', 50000, 'Top-up from Starling');

        app(TransferPairer::class)->pairAll();

        $this->assertSame($real->id, $out->fresh()->transfer_pair_id);
        $this->assertNull($weaker->fresh()->transfer_pair_id);
    }

    // ---- P2 / T3 / T6: card bills to any card of yours are paired, and existing pairs kept ----------------------------

    public function test_a_card_bill_is_paired_whatever_the_card_is_called(): void
    {
        $barclaycard = Account::query()->create(['name' => 'Barclaycard Business', 'kind' => 'credit_card', 'provider' => 'other']);
        $clarity = Account::query()->create(['name' => 'Halifax Clarity', 'kind' => 'credit_card', 'provider' => 'other']);
        $halifaxCard = Account::query()->create(['name' => 'Halifax Credit Card', 'kind' => 'credit_card', 'provider' => 'other']);
        $mbna = Account::query()->create(['name' => 'Travel card', 'kind' => 'credit_card', 'provider' => 'other']);
        OwnAccounts::flush();
        $pairs = [
            [$this->tx($this->starling, '2025-06-10', -30000, 'BARCLAYCARD', 'BARCLAYCARD'), $this->tx($barclaycard, '2025-06-11', 30000, 'PAYMENT RECEIVED THANK YOU')],
            [$this->tx($this->starling, '2025-06-12', -31000, 'HALIFAX', 'HALIFAX'), $this->tx($clarity, '2025-06-13', 31000, 'PAYMENT RECEIVED THANK YOU')],
            [$this->tx($this->starling, '2025-06-14', -32000, 'HALIFAX CREDIT CARD', 'HALIFAX CREDIT CARD'), $this->tx($halifaxCard, '2025-06-15', 32000, 'DIRECT DEBIT PAYMENT')],
            [$this->tx($this->starling, '2025-06-16', -33000, 'MBNA LTD', 'MBNA LTD'), $this->tx($mbna, '2025-06-17', 33000, 'PAYMENT RECEIVED THANK YOU')],
            [$this->tx($this->revolut, '2025-06-18', -34000, 'Credit card payment'), $this->tx($mbna, '2025-06-19', 34000, 'PAYMENT RECEIVED THANK YOU')],
        ];

        app(Importer::class)->afterImport($this->batchOf($this->starling), $this->batchOf($this->revolut));

        foreach ($pairs as [$out, $in]) {
            $this->assertSame($in->id, $out->fresh()->transfer_pair_id, $out->description);
            $this->assertSame(['transfer', 'transfer'], [$this->kind($out), $this->kind($in)], $out->description);
        }
        $this->assertSame('Paid to your Halifax Credit Card account', OwnAccounts::reason($pairs[2][0]->fresh()), 'Your own card’s name is never somebody else');
        // A store card named after a shop never makes the shop yours.
        Account::query()->create(['name' => 'Tesco Credit Card', 'kind' => 'credit_card', 'provider' => 'other']);
        Account::query()->create(['name' => 'Tesco Bank', 'kind' => 'bank', 'provider' => 'other']);
        OwnAccounts::flush();
        $groceries = $this->tx($this->starling, '2025-06-30', -4500, 'TESCO STORES 2231 A SMITH', 'TESCO');
        $this->assertTrue(OwnAccounts::vetoed($groceries));
        $this->assertNull(OwnAccounts::reason($groceries));
        $this->assertNull(OwnAccounts::reason($this->tx($this->starling, '2025-06-30', -4600, 'TESCO')));
        $this->assertSame('Paid to your Tesco Credit Card account', OwnAccounts::reason($this->tx($this->starling, '2025-06-30', -4700, 'TESCO CREDIT CARD', 'TESCO CREDIT CARD')));

        // A shop or a debit card purchase of the same amount is never the card bill.
        $shop = $this->tx($this->starling, '2025-07-01', -9900, 'CARD PAYMENT TO TESCO STORES', 'TESCO STORES');
        $tesco = $this->tx($this->starling, '2025-07-01', -9800, 'TESCO');
        $this->tx($barclaycard, '2025-07-02', 9900, 'PAYMENT RECEIVED THANK YOU');
        $this->tx($barclaycard, '2025-07-02', 9800, 'PAYMENT RECEIVED THANK YOU');
        app(TransferPairer::class)->pairAll();
        $this->assertSame([null, null], [$shop->fresh()->transfer_pair_id, $tesco->fresh()->transfer_pair_id]);
    }

    public function test_bills_of_two_cards_at_one_bank_are_paired_with_the_right_card(): void
    {
        $gold = Account::query()->create(['name' => 'Amex Gold', 'kind' => 'credit_card', 'provider' => 'amex']);
        OwnAccounts::flush();
        $toGold = $this->tx($this->starling, '2025-06-10', -25000, 'American Express AMEX PAYMENT', 'American Express');
        $goldPaid = $this->tx($gold, '2025-06-11', 25000, 'PAYMENT RECEIVED - THANK YOU');
        $toAmex = $this->tx($this->starling, '2025-06-20', -12000, 'American Express', 'American Express');
        $amexPaid = $this->tx($this->amex, '2025-06-21', 12000, 'PAYMENT RECEIVED - THANK YOU');
        // Named by what only one of them is called: never the other.
        $named = $this->tx($this->starling, '2025-06-25', -8000, 'AMEX GOLD PAYMENT');
        $notGold = $this->tx($this->amex, '2025-06-26', 8000, 'PAYMENT RECEIVED - THANK YOU');

        $this->assertSame(2, app(TransferPairer::class)->pairAll());

        $this->assertSame([$goldPaid->id, $amexPaid->id, null], [$toGold->fresh()->transfer_pair_id, $toAmex->fresh()->transfer_pair_id, $named->fresh()->transfer_pair_id]);
        $this->assertNull($notGold->fresh()->transfer_pair_id);
        $alone = $this->tx($this->starling, '2025-08-01', -5000, 'American Express', 'American Express');
        $this->assertSame('Paid to your Amex or Amex Gold account', OwnAccounts::reason($alone));
        $this->assertSame('Paid to your Amex Gold account', OwnAccounts::reason($named->fresh()));
    }

    public function test_the_nightly_run_keeps_card_bill_pairs_made_before(): void
    {
        $barclaycard = Account::query()->create(['name' => 'Barclaycard Business', 'kind' => 'credit_card', 'provider' => 'other']);
        $gold = Account::query()->create(['name' => 'Amex Gold', 'kind' => 'credit_card', 'provider' => 'amex']);
        $halifaxCard = Account::query()->create(['name' => 'Halifax Credit Card', 'kind' => 'credit_card', 'provider' => 'other']);
        $paypal = Account::query()->create(['name' => 'PayPal', 'kind' => 'bank', 'provider' => 'paypal']);
        OwnAccounts::flush();
        $made = [
            [$this->tx($this->starling, '2025-06-10', -30000, 'BARCLAYCARD', 'BARCLAYCARD'), $this->tx($barclaycard, '2025-06-11', 30000, 'PAYMENT RECEIVED THANK YOU')],
            [$this->tx($this->starling, '2025-06-12', -25000, 'American Express', 'American Express'), $this->tx($gold, '2025-06-13', 25000, 'PAYMENT RECEIVED - THANK YOU')],
            [$this->tx($this->starling, '2025-06-14', -32000, 'HALIFAX CREDIT CARD', 'HALIFAX CREDIT CARD'), $this->tx($halifaxCard, '2025-06-15', 32000, 'PAYMENT RECEIVED THANK YOU')],
            [$this->tx($this->starling, '2025-06-16', -5000, 'PAYPAL *THOMANN', 'PAYPAL *THOMANN'), $this->tx($paypal, '2025-06-16', 5000, 'General Card Deposit')],
        ];
        foreach ($made as [$out, $in]) {
            $why = 'Matches '.$in->account->name.' and Starling Business: '.Money::format($in->amount_pence).' moving between your accounts';
            $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();
            $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
            foreach ([$out, $in] as $tx) {
                app(AllocationWriter::class)->decide($tx->fresh(), AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $why);
            }
        }

        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();

        $this->assertSame(0, $pairer->cleared);
        foreach ($made as [$out, $in]) {
            $this->assertSame($in->id, $out->fresh()->transfer_pair_id, $out->description);
            $this->assertSame('transfer', $this->kind($out), $out->description);
        }
        $this->assertSame([], TransferPairer::doubtful());
    }

    // ---- T4: a wallet whose statement you import -------------------------------------------------------------------

    public function test_a_purchase_through_an_imported_paypal_account_is_counted_once(): void
    {
        $paypal = Account::query()->create(['name' => 'PayPal', 'kind' => 'bank', 'provider' => 'paypal']);
        OwnAccounts::flush();
        $bank = $this->tx($this->starling, '2025-06-10', -5000, 'PAYPAL *THOMANN', 'PAYPAL *THOMANN');
        $deposit = $this->tx($paypal, '2025-06-10', 5000, 'General Card Deposit');
        $purchase = $this->tx($paypal, '2025-06-10', -5000, 'Thomann GmbH', 'Thomann GmbH');

        app(Importer::class)->afterImport($this->batchOf($this->starling), $this->batchOf($paypal));

        $this->assertSame($deposit->id, $bank->fresh()->transfer_pair_id);
        $this->assertSame(['transfer', 'transfer'], [$this->kind($bank), $this->kind($deposit)]);
        $this->assertNotSame('transfer', $this->kind($purchase), 'The purchase itself is PayPal’s own line');
        $this->assertSame(1, OwnAccounts::scopeNotTransfers(BankTransaction::query())->where('amount_pence', '<', 0)->count(), 'Counted once');

        // With no deposit to pair it with, the bank's line is spending as before (never a transfer on its own).
        $alone = $this->tx($this->starling, '2025-07-10', -7000, 'PAYPAL *EBAY', 'PAYPAL *EBAY');
        app(TransferPairer::class)->pairAll();
        app(TransferPairer::class)->suggestOneSided();
        $this->assertNull(OwnAccounts::reason($alone->fresh()));
        $this->assertNotSame('transfer', $this->kind($alone));
        // A payment from somebody into PayPal is never the other side.
        $client = $this->tx($paypal, '2025-07-11', 7000, 'Payment from ACME EVENTS LTD', 'ACME EVENTS LTD');
        app(TransferPairer::class)->pairAll();
        $this->assertNull($client->fresh()->transfer_pair_id);
    }

    // ---- T5: a phone top-up is never another account's top-up ---------------------------------------------------------

    public function test_a_phone_top_up_is_never_paired_with_a_top_up_from_another_card(): void
    {
        $this->amex->update(['last4' => '1004']);
        OwnAccounts::flush();
        $phone = $this->tx($this->amex, '2025-06-10', -2000, 'BT MOBILE TOP UP');
        $added = $this->tx($this->revolut, '2025-06-11', 2000, 'Top-up by *7777');
        $virgin = $this->tx($this->starling, '2025-06-12', -1500, 'VIRGIN MOBILE TOPUP', 'VIRGIN MOBILE');
        $added2 = $this->tx($this->revolut, '2025-06-12', 1500, 'Top-up by *1234');
        // A card's own top-up wording, from another card than the one named.
        $other = $this->tx($this->amex, '2025-06-20', -3000, 'REVOLUT*TOP-UP');
        $added3 = $this->tx($this->revolut, '2025-06-20', 3000, 'Top-up by *7777');

        $this->assertSame(0, app(TransferPairer::class)->pairAll());
        foreach ([$phone, $virgin, $other] as $tx) {
            $this->assertNull($tx->fresh()->transfer_pair_id, $tx->description);
        }
        $this->assertNull(OwnAccounts::reason($phone));

        // "Top up" on its own (no account of yours named) needs the card's digits to match.
        $vague = $this->tx($this->starling, '2025-07-01', -4000, 'TOP UP', null, ['card_last4' => '5555']);
        $this->tx($this->revolut, '2025-07-01', 4000, 'Top-up by *9999');
        $match = $this->tx($this->starling, '2025-07-05', -4500, 'TOP UP', null, ['card_last4' => '5555']);
        $from = $this->tx($this->revolut, '2025-07-05', 4500, 'Top-up by *5555');
        $real = $this->tx($this->amex, '2025-07-10', -6000, 'REVOLUT*TOP-UP');
        $fromAmex = $this->tx($this->revolut, '2025-07-10', 6000, 'Top-up by *1004');
        app(TransferPairer::class)->pairAll();
        $this->assertNull($vague->fresh()->transfer_pair_id);
        $this->assertSame([$from->id, $fromAmex->id], [$match->fresh()->transfer_pair_id, $real->fresh()->transfer_pair_id]);
        $this->assertSame([null, null], [$added->fresh()->transfer_pair_id, $added2->fresh()->transfer_pair_id]);
        $this->assertNull($added3->fresh()->transfer_pair_id);
    }

    // ---- N23 / N24: rules and invoice matches come first, and pairing never replaces another matcher's suggestion ----

    public function test_your_rule_wins_over_pairing_whichever_statement_comes_first(): void
    {
        MerchantRule::query()->create(['merchant_key' => 'A SMITH', 'kind' => 'personal', 'business_pct' => 100, 'auto_confirm' => true]);
        $in = $this->tx($this->revolut, '2025-06-02', 30000, 'Payment from Alex Smith', 'ALEX SMITH');
        app(Importer::class)->afterImport($this->batchOf($this->revolut));
        $this->assertSame('transfer', $this->kind($in), 'Your own name: suggested as a transfer');

        $out = $this->tx($this->starling, '2025-06-03', -30000, 'Pocket money', 'A SMITH');
        app(Importer::class)->afterImport($this->batchOf($this->starling));

        $line = $out->fresh()->allocations()->sole();
        $this->assertSame(['personal', 'rule'], [$line->kind, $line->decided_by]);
        $this->assertNotNull($line->confirmed_at, 'Your auto-confirm rule applied');
        $this->assertNull($out->fresh()->transfer_pair_id);
    }

    public function test_a_client_payment_matched_to_an_invoice_is_never_paired_or_replaced(): void
    {
        $invoice = Invoice::query()->create(['number' => 'INV-0042', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 50000, 'issue_date' => '2025-07-25']);
        $client = $this->tx($this->revolut, '2025-08-01', 50000, 'INV-0042 OVERTIME TOP UP', 'ACME EVENTS LTD');
        app(Importer::class)->afterImport($this->batchOf($this->revolut));
        $this->assertSame($invoice->id, $client->fresh()->allocations()->value('invoice_id'), 'A client’s payment still matches its invoice');

        // Your own top-up of the same amount the next day: it never takes the client's payment as its other side.
        $out = $this->tx($this->starling, '2025-08-02', -50000, 'REVOLUT*TOP-UP', 'Revolut');
        app(Importer::class)->afterImport($this->batchOf($this->starling));
        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();

        $line = $client->fresh()->allocations()->sole();
        $this->assertSame(['business_income', $invoice->id, 'matcher'], [$line->kind, $line->invoice_id, $line->decided_by]);
        $this->assertNull($client->fresh()->transfer_pair_id);
        $this->assertSame('transfer', $this->kind($out), 'The top-up is still your own money moving, on its own');
    }

    // ---- P1 / T1: a real transfer of the same amount as an open invoice is paired, and the invoice left open ----------

    public function test_a_top_up_equal_to_an_open_invoice_is_paired_and_the_invoice_left_open(): void
    {
        $invoice = Invoice::query()->create(['number' => 'INV-0042', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-07-25']);
        $out = $this->tx($this->starling, '2025-08-01', -45000, 'REVOLUT*TOP-UP', 'Revolut');
        $in = $this->tx($this->revolut, '2025-08-01', 45000, 'Top-up by *1234');
        $out2 = $this->tx($this->starling, '2025-08-10', -50000, 'To Revolut', 'ALEX SMITH');
        $in2 = $this->tx($this->revolut, '2025-08-10', 50000, 'Transfer from Starling');
        Invoice::query()->create(['number' => 'INV-0043', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 50000, 'issue_date' => '2025-07-25']);

        app(Importer::class)->afterImport($this->batchOf($this->starling), $this->batchOf($this->revolut));

        $this->assertSame([$in->id, $in2->id], [$out->fresh()->transfer_pair_id, $out2->fresh()->transfer_pair_id]);
        foreach ([$in, $in2] as $tx) {
            $line = $tx->fresh()->allocations()->sole();
            $this->assertSame(['transfer', null], [$line->kind, $line->invoice_id], $tx->description);
        }
        $this->assertSame(0, Allocation::query()->whereNotNull('invoice_id')->count(), 'Both invoices are left open for the clients’ payments');

        // Imported on its own first, then its other side: the same.
        $in3 = $this->tx($this->revolut, '2025-09-01', 45000, 'Top-up by *1234');
        app(Importer::class)->afterImport($this->batchOf($this->revolut));
        $this->assertSame(['transfer', null], [$this->kind($in3), $in3->fresh()->allocations()->value('invoice_id')]);
        $out3 = $this->tx($this->starling, '2025-09-01', -45000, 'REVOLUT*TOP-UP', 'Revolut');
        app(Importer::class)->afterImport($this->batchOf($this->starling));
        $this->assertSame($in3->id, $out3->fresh()->transfer_pair_id);
        $this->assertSame(0, $invoice->fresh()->paidMinor());
    }

    public function test_a_card_bill_or_top_up_matched_to_an_invoice_earlier_is_put_right_and_then_paired(): void
    {
        $invoice = Invoice::query()->create(['number' => 'INV-0050', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-07-25']);
        $paid = $this->tx($this->amex, '2025-08-02', 45000, 'PAYMENT RECEIVED - THANK YOU');
        $topUp = $this->tx($this->revolut, '2025-08-03', 45000, 'Top-up by *1234');
        foreach ([$paid, $topUp] as $tx) {
            $lines = AllocationWriter::lines(45000, 'business_income', $this->category('turnover'));
            $lines[0]['invoice_id'] = $invoice->id;
            app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Matches invoice INV-0050 for £450.00');
        }
        $bill = $this->tx($this->starling, '2025-08-01', -45000, 'American Express', 'American Express');

        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided(); // the nightly run

        foreach ([$paid, $topUp] as $tx) {
            $line = $tx->fresh()->allocations()->sole();
            $this->assertSame(['transfer', null], [$line->kind, $line->invoice_id], $tx->description);
            $this->assertStringContainsString('INV-0050', $line->reason);
        }
        $this->assertSame(0, $invoice->fresh()->paidMinor());

        $pairer->pairAll(); // the next run pairs the card bill
        $this->assertSame($paid->id, $bill->fresh()->transfer_pair_id);
    }

    // ---- N12 / N18: your own money paid in is never an invoice's income ------------------------------------------------

    public function test_your_own_money_paid_in_is_never_matched_to_an_invoice(): void
    {
        $invoice = Invoice::query()->create(['number' => 'INV-0001', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        $own = $this->tx($this->starling, '2025-06-10', 45000, 'From personal', 'ALEX SMITH');

        app(Importer::class)->afterImport($this->batchOf($this->starling));

        $line = $own->fresh()->allocations()->sole();
        $this->assertSame(['transfer', null], [$line->kind, $line->invoice_id]);
        app(AllocationWriter::class)->confirm($own->fresh());
        $this->assertSame(0, ReturnSummary::for('2025-26')['turnover_pence']);
        $this->assertSame(0, $invoice->fresh()->paidMinor(), 'Left open for the client’s payment');

        // The client's real payment still matches it.
        $client = $this->tx($this->starling, '2025-06-12', 45000, 'INV-0001', 'ACME EVENTS LTD');
        app(Importer::class)->afterImport($this->batchOf($this->starling));
        $this->assertSame($invoice->id, $client->fresh()->allocations()->value('invoice_id'));
    }

    public function test_an_earlier_invoice_match_on_your_own_money_is_put_right(): void
    {
        $invoice = Invoice::query()->create(['number' => 'INV-0003', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 120000, 'issue_date' => '2025-06-01']);
        $own = $this->tx($this->revolut, '2025-06-10', 120000, 'Payment from Alex Smith');
        $lines = AllocationWriter::lines(120000, 'business_income', $this->category('turnover'));
        $lines[0]['invoice_id'] = $invoice->id;
        app(AllocationWriter::class)->decide($own, $lines, 'matcher', false, 'Matches invoice INV-0003 for £1,200.00');

        app(TransferPairer::class)->suggestOneSided();

        $line = $own->fresh()->allocations()->sole();
        $this->assertSame(['transfer', null, 'matcher'], [$line->kind, $line->invoice_id, $line->decided_by]);
        $this->assertStringContainsString('INV-0003', $line->reason);
    }

    // ---- N11 / N21 / N17: an account's name in a shop's name or town, or a bank's own fees ----------------------------

    public function test_a_bank_or_account_name_inside_a_purchase_is_not_a_transfer(): void
    {
        Account::query()->create(['name' => 'Halifax Current', 'kind' => 'bank', 'provider' => 'halifax']);
        Account::query()->create(['name' => 'Lloyds', 'kind' => 'bank', 'provider' => 'other']);
        Account::query()->create(['name' => 'Amazon', 'kind' => 'credit_card', 'provider' => 'other']);
        Account::query()->create(['name' => 'Work', 'kind' => 'bank', 'provider' => 'other']);
        Account::query()->create(['name' => 'Tide', 'kind' => 'bank', 'provider' => 'tide']);
        Account::query()->create(['name' => 'Savings', 'kind' => 'bank', 'provider' => 'nationwide']);
        OwnAccounts::flush();

        $purchases = [];
        foreach (['LLOYDS PHARMACY 4431 LEEDS', 'LLOYDSPHARMACY ONLINE', 'AMAZON.CO.UK*AB12CD34', 'SCREWFIX HALIFAX', 'PREMIER INN HALIFAX GBR', 'NATIONWIDE PLATFORMS LTD',
            'WORKWEAR EXPRESS', 'TESCO WORKSOP', 'TIDEWAY CAFE', 'HALIFAX TOWN AFC', 'TRAVELODGE LLOYDS AVE', 'TAXI TO WORK'] as $i => $desc) {
            $purchases[] = $this->tx($this->starling, '2025-07-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), -1000 - $i, $desc);
        }
        $toHalifax = $this->tx($this->starling, '2025-07-20', -20000, 'HALIFAX CREDIT CARD', 'HALIFAX');
        $toLloyds = $this->tx($this->starling, '2025-07-21', -20000, 'To Lloyds');
        $toWork = $this->tx($this->starling, '2025-07-22', -20000, 'To Work');

        app(Importer::class)->afterImport($this->batchOf($this->starling));

        foreach ($purchases as $tx) {
            $this->assertNull(OwnAccounts::reason($tx->fresh()), $tx->description);
            $this->assertNotSame('transfer', $this->kind($tx), $tx->description);
        }
        $this->assertSame('business_expense', $this->kind($purchases[3]), 'SCREWFIX HALIFAX is kit, not a transfer');
        $this->assertSame(['transfer', 'transfer', 'transfer'], [$this->kind($toHalifax), $this->kind($toLloyds), $this->kind($toWork)]);
    }

    public function test_revolut_fees_are_not_transfers_to_its_own_pockets(): void
    {
        Storage::fake('local');
        Http::fake();
        foreach (['EUR' => '1.150000', 'USD' => '1.300000'] as $cur => $rate) {
            ExchangeRate::query()->create(['currency' => $cur, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'units_per_gbp' => $rate, 'source' => 'hmrc_monthly']);
        }
        app(RevolutImport::class)->commit($this->revolut, base_path('tests/fixtures/revolut_business.csv'), 'business.csv');

        $fee = BankTransaction::query()->where('description', 'Revolut Business Fee')->sole();
        $this->assertNull(OwnAccounts::reason($fee));
        $this->assertNull($fee->transfer_pair_id);
        $this->assertNotSame('transfer', $fee->allocations()->value('kind'));

        // Two accounts at one bank: the bank's name says nothing about which one.
        $personal = Account::query()->create(['name' => 'Starling Personal', 'kind' => 'bank', 'provider' => 'starling']);
        OwnAccounts::flush();
        $this->assertNull(OwnAccounts::reason($this->tx($this->starling, '2025-07-01', -300, 'STARLING MONTHLY FEE')));
        $this->assertNotNull(OwnAccounts::reason($this->tx($this->starling, '2025-07-02', -30000, 'To Starling Personal')));
        $this->assertNull(OwnAccounts::reason($this->tx($personal, '2025-07-03', -300, 'Starling card fee')));
    }

    // ---- N15 / N20: "top up" on a purchase, and money in from a client ------------------------------------------------

    public function test_top_ups_of_phones_and_travel_cards_are_purchases_and_a_client_top_up_is_income(): void
    {
        $purchases = [];
        foreach (['EE TOP UP', 'VODAFONE TOPUP', 'GIFFGAFF TOP-UP', 'LEBARA MOBILE TOP UP', 'OYSTER AUTO TOP-UP', 'STARBUCKS CARD TOP UP', 'DART-CHARGE TOP UP', 'O2 TOP-UP'] as $i => $desc) {
            $purchases[] = $this->tx($this->starling, '2025-07-0'.($i + 1), -1500 - $i, $desc);
        }
        $client = $this->tx($this->starling, '2025-07-10', 35000, 'ACME EVENTS LTD OVERTIME TOP UP INV-0007', 'ACME EVENTS LTD');
        $ownTopUp = $this->tx($this->starling, '2025-07-11', -10000, 'REVOLUT*TOP-UP');
        $added = $this->tx($this->revolut, '2025-07-12', 7000, 'Top-up by *1234');

        app(Importer::class)->afterImport($this->batchOf($this->starling), $this->batchOf($this->revolut));

        foreach ([...$purchases, $client] as $tx) {
            $this->assertNull(OwnAccounts::reason($tx->fresh()), $tx->description);
            $this->assertNotSame('transfer', $this->kind($tx), $tx->description);
        }
        $vodafone = $purchases[1]->fresh()->allocations()->get();
        $this->assertTrue($vodafone->isNotEmpty() && $vodafone->every(fn ($a) => $a->decided_by === 'matcher' && $a->kind !== 'transfer'), 'The categoriser gets its say');
        $this->assertSame(['transfer', 'transfer'], [$this->kind($ownTopUp), $this->kind($added)]);
    }

    public function test_your_own_decisions_for_a_merchant_beat_the_transfer_guess(): void
    {
        foreach (['2025-05-01', '2025-05-08', '2025-05-15'] as $date) {
            $gift = $this->tx($this->starling, $date, -5000, 'Birthday', 'A SMITH');
            app(AllocationWriter::class)->decide($gift, AllocationWriter::lines(-5000, 'personal'), 'you');
        }
        $waiting = $this->tx($this->starling, '2025-04-20', -5000, 'Earlier', 'A SMITH');
        app(AllocationWriter::class)->decide($waiting, AllocationWriter::lines(-5000, 'transfer'), 'matcher', false, 'Paid to you (your own name), so it’s money moving to another account of yours');
        $new = $this->tx($this->starling, '2025-06-01', -5000, 'Birthday', 'A SMITH');

        $pairer = app(TransferPairer::class);
        $pairer->suggestOneSided();

        $this->assertNull($this->kind($new), 'Left for you, the way you decide this payee');
        $this->assertNull($this->kind($waiting), 'An earlier transfer guess is taken back');
        $this->assertSame(1, $pairer->cleared);
    }

    // ---- N22: a wallet you listed only counts for moves in and out of it -----------------------------------------------

    public function test_a_listed_wallet_only_counts_for_moves_in_and_out_of_it(): void
    {
        Setting::put('own_other_accounts', ['PayPal']);
        OwnAccounts::flush();
        foreach ([['PAYPAL *THOMANN 35314369001', null], ['PAYPAL *EBAY 4029357733', null], ['Card purchase', 'PAYPAL *EBAY'], ['PAYPAL PAYMENT', null]] as [$desc, $cp]) {
            $this->assertNull(OwnAccounts::reason($this->tx($this->starling, '2025-07-01', -18900, $desc, $cp)), $desc);
        }
        $this->assertNotNull(OwnAccounts::reason($this->tx($this->starling, '2025-07-02', 5000, 'PAYPAL TRANSFER')));
        $this->assertNotNull(OwnAccounts::reason($this->tx($this->starling, '2025-07-03', -5000, 'Transfer to PayPal')));
    }

    public function test_the_settings_page_warns_about_wallets_and_saving_takes_back_what_no_longer_fits(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('own_other_accounts', ['Nationwide savings']);
        OwnAccounts::flush();
        $tx = $this->tx($this->starling, '2025-07-01', -30000, 'NATIONWIDE SAVINGS 1234');
        app(TransferPairer::class)->suggestOneSided();
        $this->assertSame('transfer', $this->kind($tx));

        $this->put('/settings/ledger', [
            'home_postcode' => '', 'overnight_near_miles' => 12, 'overnight_far_miles' => 40, 'early_start' => '08:00', 'late_finish' => '22:00',
            'mileage_threshold_scope' => 'all_vehicles', 'scottish_taxpayer' => false, 'own_names' => 'Alex Smith', 'own_other_accounts' => '',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'back for you to decide'));
        $this->assertNull($this->kind($tx));
        $this->assertStringContainsString('PayPal', (string) file_get_contents(resource_path('js/pages/settings/ledger.tsx')));
    }

    // ---- N54 / N75: your own name on lines without a counterparty ------------------------------------------------------

    public function test_your_name_after_to_or_from_counts_on_lines_without_a_counterparty(): void
    {
        foreach (['To ALEX SMITH' => -20000, 'Payment from Alex Smith' => 20000, 'Transfer from ALEX SMITH' => 20000, 'Money added from ALEX SMITH' => 20000,
            'Transfer to ALEX SMITH' => -20000, 'From A SMITH' => 20000, 'A SMITH SAVINGS FT' => -20000, 'To Alex Smith' => -20000] as $desc => $pence) {
            $this->assertNotNull(OwnAccounts::reason($this->tx($this->revolut, '2025-07-01', $pence, $desc)), $desc);
        }
        foreach (['To JO SMITH' => -20000, 'To A SMITH PLUMBING LTD' => -20000, 'Payment from ACME EVENTS LTD' => 20000] as $desc => $pence) {
            $this->assertNull(OwnAccounts::reason($this->tx($this->revolut, '2025-07-02', $pence, $desc)), $desc);
        }
    }

    // ---- N74: "Confirm all" covers pairs and money going out, never unseen money in -------------------------------------

    public function test_confirm_all_never_confirms_money_paid_in_on_its_own_or_anything_suggested_since(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $topUp = $this->tx($this->starling, '2025-07-02', -10000, 'REVOLUT*TOP-UP');
        $alone = $this->tx($this->starling, '2025-08-02', 20000, 'From personal', 'ALEX SMITH');
        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();
        $this->assertSame(['transfer', 'transfer'], [$this->kind($topUp), $this->kind($alone)]);

        $set = TransferPairer::confirmable();
        $this->assertSame([3, 1, 20000], [$set['count'], $set['left'], $set['left_pence']]);
        $this->assertSame([2, 60000, 1, 50000], [$set['out_count'], $set['out_pence'], $set['in_count'], $set['in_pence']]);

        // Something suggested after the button was offered is left alone.
        $later = $this->tx($this->starling, '2025-09-02', -7000, 'Transfer to savings space Holiday');
        $pairer->suggestOneSided();
        $message = app(TodoActions::class)->run('transfers_ok', (string) $set['up_to']);

        $this->assertStringContainsString('Confirmed 3 transfers', $message);
        $this->assertStringContainsString('£600.00 out', $message);
        $this->assertStringContainsString('1 payment in (£200.00)', $message);
        foreach ([$out, $in, $topUp] as $tx) {
            $this->assertSame('confirmed', $tx->fresh()->review_status);
        }
        $this->assertSame('suggested', $alone->fresh()->review_status, 'Money in on its own is checked one by one');
        $this->assertSame('suggested', $later->fresh()->review_status, 'Suggested after the button was offered');

        // A Telegram button pressed after a later suggestion: only what was there when the message was sent.
        $this->travel(1)->hours();
        $offered = Carbon::now();
        $this->travel(1)->hours();
        $after = $this->tx($this->starling, '2025-09-09', -8000, 'Transfer to savings space Rainy');
        $pairer->suggestOneSided();
        app(TodoActions::class)->run('transfers_ok', '0', $offered);
        $this->assertSame('confirmed', $later->fresh()->review_status);
        $this->assertSame('suggested', $after->fresh()->review_status);
    }

    // ---- N76: a big backlog never hides a new statement ----------------------------------------------------------------

    public function test_new_lines_are_checked_however_many_older_lines_are_waiting(): void
    {
        $batch = ImportBatch::query()->create(['account_id' => $this->starling->id, 'original_filename' => 'old.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x']);
        $now = now()->format('Y-m-d H:i:s');
        foreach (array_chunk(range(1, 5100), 500) as $chunk) {
            BankTransaction::query()->insert(array_map(fn ($i) => [
                'account_id' => $this->starling->id, 'import_batch_id' => $batch->id, 'posted_on' => '2024-05-01 00:00:00', 'tax_year' => '2024-25',
                'description' => 'SHOP '.$i, 'merchant_key' => 'SHOP '.$i, 'amount_pence' => -100 - $i, 'raw' => '[]', 'fingerprint' => hash('sha256', 'old'.$i),
                'occurrence' => 1, 'state' => 'active', 'live' => true, 'review_status' => 'unreviewed', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $new = $this->tx($this->starling, '2025-10-01', -2500, 'Transfer to savings space Holiday');

        app(TransferPairer::class)->suggestOneSided();

        $this->assertSame('transfer', $this->kind($new));
    }

    // ---- N108: a split with one transfer line ------------------------------------------------------------------------

    public function test_an_exchange_with_a_fee_never_counts_its_whole_amount_as_spending(): void
    {
        $this->actingAs(User::factory()->create());
        $eur = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $this->revolut->id]);
        $out = $this->tx($this->revolut, '2025-06-01', -100500, 'Exchanged to EUR');
        $in = $this->tx($eur, '2025-06-01', 100000, 'Exchanged from GBP');
        $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();
        $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
        app(AllocationWriter::class)->decide($out->fresh(), [...AllocationWriter::lines(-100000, 'transfer'), ...AllocationWriter::lines(-500, 'business_expense', $this->category('finance'))], 'matcher', false, 'Currency exchange');
        app(AllocationWriter::class)->decide($in->fresh(), AllocationWriter::lines(100000, 'transfer'), 'matcher', false, 'Currency exchange');
        $job = WorkJob::query()->create(['title' => 'Paris show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'confirmed']);

        // The Transactions page never shows the £1,000 moved as spending: whole rows leave out the exchange (and its
        // £5 fee), line-by-line totals count just the fee.
        $this->get('/transactions?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p
            ->where('totals.out_pence', fn ($v) => in_array((int) $v, [0, -500], true))
            ->where('totals.transfer_out_pence', fn ($v) => in_array((int) $v, [-100500, -100000], true))->where('totals.transfer_in_pence', 100000));
        $this->assertTrue(OwnAccounts::isTransfer($out->fresh()));
        // Nor is it put on the job's day as a £1,005 cost to link.
        $this->get('/connections?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p->where('nodes', fn ($nodes) => ! collect($nodes)->contains('id', 'tx:'.$out->id)));

        // Line by line, for totals that need the fee: £5 spent, £1,000 moved.
        $sums = BankTransaction::query()->where('id', $out->id)
            ->selectRaw('SUM('.OwnAccounts::spendingPenceSql().') as spent, SUM('.OwnAccounts::transferPenceSql().') as moved')->first();
        $this->assertSame([-500, -100000], [(int) $sums->spent, (int) $sums->moved]);
        // And a payment with a cost in it is still found when looking for a stay's or a job's payment.
        $this->assertSame([$out->id], OwnAccounts::scopeWithSpendingLines(BankTransaction::query())->pluck('id')->all());
        $this->assertNotNull($job->id);
    }

    // ---- N109: a pair across a locked year ----------------------------------------------------------------------------

    public function test_deciding_one_side_never_changes_its_other_side_in_a_locked_year(): void
    {
        $out = $this->tx($this->starling, '2025-04-05', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-04-07', 50000, 'Payment from Alex Smith', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id);
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);

        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$in->fresh()]));

        $this->assertSame($in->id, $out->fresh()->transfer_pair_id, 'The locked side is left as it is');
        $this->assertSame('transfer', $this->kind($out));
        $this->assertSame('personal', $this->kind($in));
        $this->assertSame(1, AuditEvent::query()->where('action', 'transfer.kept')->where('subject_id', $in->id)->count());

        // In open years the pair is broken on both sides, in the history of both.
        $out2 = $this->tx($this->starling, '2025-09-02', -7000, 'Rent', 'A SMITH');
        $in2 = $this->tx($this->revolut, '2025-09-02', 7000, 'Payment', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$out2->fresh()]));
        $this->assertNull($in2->fresh()->transfer_pair_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'transfer.unpaired')->where('subject_id', $in2->id)->count());
    }

    // ---- Production repair: earlier false suggestions are taken back, confirmed ones listed ---------------------------

    public function test_earlier_false_suggestions_are_taken_back_and_confirmed_ones_are_listed(): void
    {
        $why = 'Matches Starling Business and Amex: £450.00 moving between your accounts';
        $fee = $this->tx($this->starling, '2025-06-02', 45000, 'Acme Events Ltd A SMITH INV 1042', 'Acme Events Ltd');
        $hire = $this->tx($this->amex, '2025-06-04', -45000, 'PRO HIRE LTD LONDON');
        $fee->forceFill(['transfer_pair_id' => $hire->id])->saveQuietly();
        $hire->forceFill(['transfer_pair_id' => $fee->id])->saveQuietly();
        foreach ([$fee, $hire] as $tx) {
            app(AllocationWriter::class)->decide($tx->fresh(), AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $why);
        }
        $ee = $this->tx($this->starling, '2025-06-05', -1500, 'EE TOP UP', 'EE');
        app(AllocationWriter::class)->decide($ee, AllocationWriter::lines(-1500, 'transfer'), 'matcher', false, 'A top-up, savings pot, exchange or move between accounts, so it’s your own money moving');
        $confirmedEe = $this->tx($this->starling, '2025-06-06', -1600, 'EE TOP UP', 'EE');
        app(AllocationWriter::class)->decide($confirmedEe, AllocationWriter::lines(-1600, 'transfer'), 'matcher', false, 'A top-up, savings pot, exchange or move between accounts, so it’s your own money moving');
        app(AllocationWriter::class)->confirm($confirmedEe->fresh());

        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();

        $this->assertSame([null, null], [$fee->fresh()->transfer_pair_id, $hire->fresh()->transfer_pair_id]);
        $this->assertNotSame('transfer', $this->kind($fee));
        $this->assertNotSame('transfer', $this->kind($hire));
        $this->assertNotSame('transfer', $this->kind($ee));
        $this->assertSame(3, $pairer->cleared);
        $this->assertSame('transfer', $this->kind($confirmedEe), 'Your confirmed decision is never changed');
        $this->assertSame([$confirmedEe->id], TransferPairer::doubtful(), 'but it is listed to look at again');
    }
}
