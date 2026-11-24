<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditLog;
use App\Domain\Banks\StarlingSync;
use App\Domain\Import\Importer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Tax\Explainer;
use App\Domain\Travel\TravelMatcher;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** One test (or more) per finding fixed in the financial group of the review of 1d65812..HEAD. */
class ReviewFixesFinancialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
    }

    private function payment(string $date, int $pence, string $counterparty, array $extra = []): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create($extra + [
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => strtoupper($counterparty), 'counterparty' => $counterparty, 'merchant_key' => MerchantKey::from(strtoupper($counterparty), $counterparty),
            'amount_pence' => $pence, 'raw' => [], 'fingerprint' => hash('sha256', $date.$pence.$counterparty.random_int(1, PHP_INT_MAX)), 'occurrence' => 1,
        ]);
    }

    private function category(string $code): int
    {
        return Category::query()->where('code', $code)->value('id');
    }

    private function decideYourself(BankTransaction $tx, string $kind, ?string $category): void
    {
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, $kind, $category ? $this->category($category) : null), 'you');
    }

    // ---- G1: a learned rule keeps to the direction it was learned from ------------------------------------------------

    public function test_g1_a_rule_learned_from_payments_out_is_not_applied_to_money_in(): void
    {
        $this->actingAs(User::factory()->create());
        $paid = [$this->payment('2025-05-02', -20000, 'Sam Jones'), $this->payment('2025-06-02', -20000, 'Sam Jones')];
        $fee = $this->payment('2025-07-02', 35000, 'Sam Jones');
        $owed = $this->payment('2025-07-09', -20000, 'Sam Jones');
        foreach ($paid as $tx) {
            $this->decideYourself($tx, 'business_expense', 'freelancers');
        }

        $offer = app(RuleLearner::class)->offer($fee->merchant_key);
        $this->assertSame('out', $offer['direction']);
        $this->assertStringContainsString('payments to Sam Jones', $offer['text']);
        $this->assertStringContainsString('the 1 waiting', $offer['text'], 'Only the payment out is counted as waiting');

        $this->post('/rules/learn', ['merchant_key' => $fee->merchant_key])->assertSessionHas('rule_undo', fn ($u) => $u['applied'] === 1);
        $rule = MerchantRule::query()->sole();
        $this->assertSame('out', $rule->direction);

        $this->assertSame('confirmed', $owed->fresh()->review_status);
        $this->assertSame(['business_expense', 'rule'], [$owed->allocations()->sole()->kind, $owed->allocations()->sole()->decided_by]);
        $this->assertSame('unreviewed', $fee->fresh()->review_status, 'Money in from Sam Jones is left for you');
        $this->assertSame(0, $fee->allocations()->count());

        $this->assertTrue(RuleLearner::appliesTo($rule, -100));
        $this->assertFalse(RuleLearner::appliesTo($rule, 100), 'The Suggester asks this before applying the rule to a new payment');
        $this->assertTrue(RuleLearner::appliesTo(new MerchantRule(['merchant_key' => 'X', 'kind' => 'personal']), 100), 'A rule you made yourself applies both ways');
    }

    public function test_g1_decisions_both_ways_learn_a_rule_for_both_directions(): void
    {
        $refund = $this->payment('2025-06-02', 5698, 'Adobe');
        foreach ([$this->payment('2025-05-02', -5698, 'Adobe'), $refund] as $tx) {
            $this->decideYourself($tx, 'business_expense', 'software');
        }
        $offer = app(RuleLearner::class)->offer($refund->merchant_key);
        $this->assertNull($offer['direction']);
        $this->assertStringContainsString('marked Adobe as', $offer['text']);
    }

    public function test_g1_the_repair_puts_money_in_that_a_learned_rule_decided_back_in_the_inbox(): void
    {
        // As it was before the fix: a learned rule with no direction that confirmed money in as a (negative) cost.
        $paid = [$this->payment('2025-05-02', -20000, 'Sam Jones'), $this->payment('2025-06-02', -20000, 'Sam Jones')];
        foreach ($paid as $tx) {
            $this->decideYourself($tx, 'business_expense', 'freelancers');
        }
        $fee = $this->payment('2025-07-02', 35000, 'Sam Jones');
        $owed = $this->payment('2025-07-09', -20000, 'Sam Jones');
        $rule = MerchantRule::query()->create(['merchant_key' => $fee->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->category('freelancers'), 'business_pct' => 100, 'auto_confirm' => true]);
        AuditLog::record('rule.learned', $rule, null, $rule->toArray(), 'Learned from 2 of your decisions');
        $writer = app(AllocationWriter::class);
        foreach ([$fee, $owed] as $tx) {
            $writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $rule->category_id), 'rule', true, 'Your rule for Sam Jones', $rule->id);
        }
        // A rule you saved yourself is left exactly as it is.
        $own = $this->payment('2025-07-03', 9000, 'Hire Co');
        $made = MerchantRule::query()->create(['merchant_key' => $own->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->category('freelancers'), 'auto_confirm' => true]);
        $writer->decide($own, AllocationWriter::lines(9000, 'business_expense', $made->category_id), 'rule', true, 'Your rule', $made->id);

        $migration = require database_path('migrations/2026_11_29_000301_financial_merchant_rule_direction.php');
        $migration->up();

        $this->assertSame('out', $rule->fresh()->direction);
        $this->assertNull($made->fresh()->direction);
        $this->assertSame('unreviewed', $fee->fresh()->review_status, 'Back in the Inbox');
        $this->assertSame(0, $fee->allocations()->count());
        $this->assertSame('confirmed', $owed->fresh()->review_status, 'Payments out keep the rule');
        $this->assertSame('confirmed', $own->fresh()->review_status);
        $this->assertTrue(AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $fee->id)->exists());
    }

    /** Two payments to Sam Jones you decided as freelancers, and the rule learned from them ('payments out' only). */
    private function learnSamJones(): array
    {
        $this->actingAs(User::factory()->create());
        foreach ([$this->payment('2025-05-02', -20000, 'Sam Jones'), $this->payment('2025-06-02', -20000, 'Sam Jones')] as $tx) {
            $this->decideYourself($tx, 'business_expense', 'freelancers');
        }
        $fee = $this->payment('2025-07-02', 35000, 'Sam Jones');
        $this->post('/rules/learn', ['merchant_key' => $fee->merchant_key])->assertSessionHas('success');
        $rule = MerchantRule::query()->sole();
        $this->assertSame('out', $rule->direction);

        return [$fee, $rule];
    }

    private function ruleLines(BankTransaction $tx): array
    {
        return $tx->allocations()->where('decided_by', 'rule')->get()->map(fn ($a) => [$a->kind, $a->amount_pence])->all();
    }

    // These four need the Suggester, DecisionService and TransferPairer to keep to a rule's direction too (see the
    // review notes): they ship in the same release as the repair migration, never after it.

    public function test_g1_the_suggester_and_the_travel_check_leave_money_in_alone_under_a_rule_for_payments_out(): void
    {
        [$fee] = $this->learnSamJones();
        $new = $this->payment('2025-08-02', 40000, 'Sam Jones');
        $owed = $this->payment('2025-08-09', -20000, 'Sam Jones');

        app(Suggester::class)->suggestFor(collect([$new, $owed]));
        $this->assertSame([], $this->ruleLines($new), 'A new gig fee from Sam Jones isn’t made a cost');
        $this->assertNotSame('confirmed', $new->fresh()->review_status);
        $this->assertSame([['business_expense', -20000]], $this->ruleLines($owed), 'Paying him still follows the rule');
        $this->assertSame('confirmed', $owed->fresh()->review_status);

        // The Travel page's Check runs the Suggester over everything undecided in the year.
        $this->post('/travel/check', ['tax_year' => $fee->tax_year])->assertSessionHas('success');
        $this->assertSame([], $this->ruleLines($fee), 'The fee accept() left for you stays yours to decide');
        $this->assertNotSame('confirmed', $fee->fresh()->review_status);
    }

    public function test_g1_the_repair_stays_done_when_the_suggester_runs_again(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([$this->payment('2025-05-02', -20000, 'Sam Jones'), $this->payment('2025-06-02', -20000, 'Sam Jones')] as $tx) {
            $this->decideYourself($tx, 'business_expense', 'freelancers');
        }
        $fee = $this->payment('2025-07-02', 35000, 'Sam Jones');
        $rule = MerchantRule::query()->create(['merchant_key' => $fee->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->category('freelancers'), 'business_pct' => 100, 'auto_confirm' => true]);
        AuditLog::record('rule.learned', $rule, null, $rule->toArray(), 'Learned from 2 of your decisions');
        app(AllocationWriter::class)->decide($fee, AllocationWriter::lines(35000, 'business_expense', $rule->category_id), 'rule', true, 'Your rule for Sam Jones', $rule->id);

        (require database_path('migrations/2026_11_29_000301_financial_merchant_rule_direction.php'))->up();
        $this->assertSame('unreviewed', $fee->fresh()->review_status);

        $this->post('/travel/check', ['tax_year' => $fee->tax_year])->assertSessionHas('success');
        app(Suggester::class)->suggestFor(collect([$fee->fresh()]));
        $this->assertSame([], $this->ruleLines($fee), 'Still back in the Inbox, as the history says');
        $this->assertNotSame('confirmed', $fee->fresh()->review_status);
    }

    public function test_g1_a_rule_you_save_over_a_learned_one_applies_both_ways_and_only_suggests(): void
    {
        [$fee, $rule] = $this->learnSamJones();

        $this->post("/items/bank/{$fee->id}/decide", ['kind' => 'business_income', 'category_id' => $this->category('turnover'), 'make_rule' => true])
            ->assertSessionHas('success');

        $rule = $rule->fresh();
        $this->assertSame(['business_income', $this->category('turnover')], [$rule->kind, (int) $rule->category_id]);
        $this->assertNull($rule->direction, 'A rule you save applies both ways');
        $this->assertTrue(RuleLearner::appliesTo($rule, 40000));
        $this->assertFalse($rule->auto_confirm, '“Suggest this next time” suggests: the learned rule’s confirming on its own isn’t carried over');

        $next = $this->payment('2025-08-02', 40000, 'Sam Jones');
        app(Suggester::class)->suggestFor(collect([$next]));
        $this->assertSame([['business_income', 40000]], $this->ruleLines($next), 'Applied to money in, the case you made it for');
        $this->assertSame('suggested', $next->fresh()->review_status);

        // The same decision as a learned rule's, made the other way round (an Adobe refund as software): both ways now.
        foreach (['2025-05-03', '2025-06-03'] as $date) {
            $this->decideYourself($this->payment($date, -5698, 'Adobe'), 'business_expense', 'software');
        }
        $refund = $this->payment('2025-07-03', 5698, 'Adobe');
        app(RuleLearner::class)->accept($refund->merchant_key);
        $adobe = MerchantRule::query()->where('merchant_key', $refund->merchant_key)->sole();
        $this->assertSame('out', $adobe->direction);
        $this->post("/items/bank/{$refund->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->category('software'), 'make_rule' => true]);
        $this->assertNull($adobe->fresh()->direction);
        $this->assertTrue(RuleLearner::appliesTo($adobe->fresh(), 5698));
    }

    public function test_g1_money_in_outside_a_rules_direction_can_still_be_spotted_as_your_own_money_moving(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['2025-05-02', '2025-06-02'] as $date) {
            $this->decideYourself($this->payment($date, -5000, 'Revolut', ['description' => 'REVOLUT TOP UP']), 'transfer', null);
        }
        $back = $this->payment('2025-07-02', 3000, 'Revolut', ['description' => 'MONEY ADDED FROM SAVINGS']);
        app(RuleLearner::class)->accept($back->merchant_key);
        $this->assertSame('out', MerchantRule::query()->sole()->direction);
        $this->assertSame(0, $back->allocations()->count());

        app(TransferPairer::class)->suggestOneSided();

        $line = $back->allocations()->sole();
        $this->assertSame(['transfer', 'matcher', null], [$line->kind, $line->decided_by, $line->confirmed_at], 'Suggested as a transfer, as the rule doesn’t cover money in');
    }

    // ---- R7: accepting a rule leaves invoice matches, stays and transfers alone -------------------------------------

    public function test_r7_accepting_a_rule_does_not_undo_an_invoice_match_or_a_stays_split(): void
    {
        foreach ([$this->payment('2025-05-02', 80000, 'Acme Events'), $this->payment('2025-06-02', 80000, 'Acme Events')] as $tx) {
            $this->decideYourself($tx, 'business_income', 'turnover');
        }
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-01']);
        $invoice = Invoice::query()->create(['number' => 'INV-0001', 'status' => 'issued', 'total_minor' => 120000, 'issue_date' => '2025-07-01', 'work_job_id' => $job->id]);
        $paidInvoice = $this->payment('2025-07-10', 120000, 'Acme Events');
        $lines = AllocationWriter::lines(120000, 'business_income', $this->category('turnover'), $job->id);
        $lines[0]['invoice_id'] = $invoice->id;
        app(AllocationWriter::class)->decide($paidInvoice, $lines, 'matcher', false, 'Matches invoice INV-0001');
        $plain = $this->payment('2025-07-20', 50000, 'Acme Events');
        app(AllocationWriter::class)->decide($plain, AllocationWriter::lines(50000, 'personal'), 'matcher', false, 'Like the others');

        $offer = app(RuleLearner::class)->offer($plain->merchant_key);
        $this->assertStringContainsString('the 1 waiting', $offer['text'], 'The invoice match isn’t counted');
        ['applied' => $applied] = app(RuleLearner::class)->accept($plain->merchant_key);

        $this->assertSame(1, $applied);
        $kept = $paidInvoice->allocations()->sole();
        $this->assertSame([$invoice->id, $job->id, 'matcher', null], [$kept->invoice_id, $kept->work_job_id, $kept->decided_by, $kept->confirmed_at]);
        $this->assertSame(['business_income', 'rule'], [$plain->allocations()->sole()->kind, $plain->allocations()->sole()->decided_by]);
    }

    public function test_r7_accepting_a_rule_leaves_a_stays_night_by_night_split_and_transfer_pairs(): void
    {
        foreach ([$this->payment('2025-05-02', -9000, 'Premier Inn'), $this->payment('2025-06-02', -9000, 'Premier Inn')] as $tx) {
            $this->decideYourself($tx, 'business_expense', 'travel_accommodation');
        }
        $split = $this->payment('2025-07-12', -27000, 'Premier Inn');
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'starts_on' => '2025-07-09', 'ends_on' => '2025-07-12',
            'amount_minor' => 27000, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'structured_data', 'bank_transaction_id' => $split->id]);
        app(AllocationWriter::class)->decide($split, [
            ['kind' => 'business_expense', 'category_id' => $this->category('travel_accommodation'), 'amount_pence' => -18000, 'business_use_pct' => 100],
            ['kind' => 'personal', 'amount_pence' => -9000],
        ], 'matcher', false, 'Premier Inn: 2 nights for the job');
        $paired = $this->payment('2025-07-20', -9000, 'Premier Inn');
        $other = $this->payment('2025-07-20', 9000, 'Premier Inn', ['transfer_pair_id' => $paired->id]);
        $paired->forceFill(['transfer_pair_id' => $other->id])->saveQuietly();
        $waiting = $this->payment('2025-07-25', -9000, 'Premier Inn');

        ['applied' => $applied] = app(RuleLearner::class)->accept($waiting->merchant_key);

        $this->assertSame(1, $applied);
        $this->assertSame(['business_expense', 'personal'], $split->allocations()->orderBy('id')->pluck('kind')->all(), 'The stay’s split is left for you');
        $this->assertSame('unreviewed', $paired->fresh()->review_status);
        $this->assertSame('confirmed', $waiting->fresh()->review_status);
    }

    // ---- R1: your decision on a stay applies over a rule's automatic decision on its payment --------------------------

    public function test_r1_deciding_a_stay_personal_applies_over_a_rule_that_confirmed_its_payment(): void
    {
        $tx = $this->payment('2025-08-12', -12000, 'Premier Inn');
        $rule = MerchantRule::query()->create(['merchant_key' => $tx->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->category('travel_accommodation'), 'business_pct' => 100, 'auto_confirm' => true]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-12000, 'business_expense', $rule->category_id), 'rule', true, 'Your rule for Premier Inn', $rule->id);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'starts_on' => '2025-08-11', 'ends_on' => '2025-08-12',
            'amount_minor' => 12000, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'structured_data', 'bank_transaction_id' => $tx->id]);

        $this->assertSame('applied', app(TravelMatcher::class)->decide($stay, 'personal', null, null, null));

        $line = $tx->allocations()->sole();
        $this->assertSame(['personal', 'you', true], [$line->kind, $line->decided_by, $line->confirmed_at !== null]);
    }

    public function test_r1_a_payment_you_decided_yourself_is_still_kept(): void
    {
        $tx = $this->payment('2025-08-12', -12000, 'Premier Inn');
        $this->decideYourself($tx, 'personal', null);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'starts_on' => '2025-08-11', 'ends_on' => '2025-08-12',
            'amount_minor' => 12000, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'structured_data', 'bank_transaction_id' => $tx->id]);

        $this->assertSame('kept', app(TravelMatcher::class)->decide($stay, 'business', null, null, null));
        $this->assertSame('personal', $tx->allocations()->sole()->kind);
    }

    // ---- R10: a Received-SPF line the sender wrote can't skip the hold ------------------------------------------------

    public function test_r10_a_forged_received_spf_header_does_not_skip_the_hold(): void
    {
        Setting::put('allowed_senders', ['jo.bloggs@gmail.com']);
        $forged = "Return-Path: <jo.bloggs+caf_=ledger=example.com@gmail.com>\r\n"
            ."Authentication-Results: mx.hostinger.com; spf=softfail (domain does not designate) smtp.mailfrom=jo.bloggs+caf_=ledger=example.com@gmail.com; dmarc=none\r\n"
            ."Received: from attacker.example by mx.hostinger.com\r\n"
            ."Received-SPF: pass (gmail.com: domain of jo.bloggs@gmail.com designates 1.2.3.4 as permitted sender)\r\n";
        $this->assertNotNull(MailboxFetcher::holdReason('ap@client.example', $forged));
        $this->assertNotNull(MailboxFetcher::holdReason('ap@client.example', str_replace('spf=softfail', 'spf=fail', $forged)));

        // A server that adds no Authentication-Results: only its own (topmost) Received-SPF counts.
        $noAuth = "Return-Path: <jo.bloggs+caf_=ledger=example.com@gmail.com>\r\n"
            ."Received-SPF: softfail (mx.example: domain of transitioning jo.bloggs+caf_=ledger=example.com@gmail.com)\r\n"
            ."Received: from attacker.example by mx.example\r\n"
            ."Received-SPF: pass (gmail.com: domain of jo.bloggs@gmail.com designates 1.2.3.4 as permitted sender)\r\n";
        $this->assertNotNull(MailboxFetcher::holdReason('ap@client.example', $noAuth));

        $genuine = "Return-Path: <jo.bloggs+caf_=ledger=example.com@gmail.com>\r\n"
            ."Received-SPF: pass (mx.example: domain of jo.bloggs+caf_=ledger=example.com@gmail.com designates 209.85.1.1 as permitted sender)\r\n"
            ."Received: from mail-sor-f41.google.com by mx.example\r\n";
        $this->assertNull(MailboxFetcher::holdReason('ap@client.example', $genuine), 'Still taken in when the receiving server’s own check passed');
    }

    // ---- R19: amounts without '£', and with thousands commas ----------------------------------------------------------

    public function test_r19_search_finds_amounts_typed_without_a_pound_sign_or_with_commas(): void
    {
        $this->actingAs(User::factory()->create());
        $this->payment('2025-04-02', -5698, 'Adobe');
        $this->payment('2025-04-03', 120000, 'Acme Events Ltd');
        Invoice::query()->create(['status' => 'draft', 'total_minor' => 120000, 'bill_to_name' => 'Someone']);
        $types = fn (string $q) => collect($this->getJson('/search?q='.urlencode($q))->assertOk()->json('results'))->pluck('type')->sort()->values()->all();

        foreach (['1200', '1200.00', '1,200', '£1,200.00', '£ 1,200', '−£1,200.00', '1200.0'] as $q) {
            $this->assertSame(['Invoice', 'Payment'], $types($q), "Searching {$q}");
        }
        foreach (['56.98', '£56.98', '-56.98', '56,98'] as $q) {
            $this->assertSame(['Payment'], $types($q), "Searching {$q}");
        }
        $this->assertSame([], $types('12,00,0'), 'Not an amount');
    }

    // ---- R22 and R36: Starling's daily import --------------------------------------------------------------------------

    /** A fake Starling that returns only the rows inside the requested dates, with a running balance that adds up. */
    private function fakeStarling(array &$rows, array &$asked): void
    {
        Http::fake(function (Request $r) use (&$rows, &$asked) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/v2/accounts')) {
                return Http::response(['accounts' => [['accountUid' => 'uid-1', 'name' => 'Business', 'currency' => 'GBP']]]);
            }
            if (str_contains($r->url(), '/statement/downloadForDateRange')) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $asked[] = $q['start'].'..'.$q['end'];
                $balance = 100000;
                $csv = "Date,Counter Party,Reference,Type,Amount (GBP),Balance (GBP),Spending Category,Notes\n";
                foreach ($rows as [$date, $who, $pence]) {
                    $balance += $pence;
                    if ($date >= $q['start'] && $date <= $q['end']) {
                        $csv .= CarbonImmutable::parse($date)->format('d/m/Y').",{$who},".strtoupper($who).',CARD PAYMENT,'.number_format($pence / 100, 2, '.', '').','.number_format($balance / 100, 2, '.', '').",SHOPPING,\n";
                    }
                }

                return Http::response($csv, 200, ['Content-Type' => 'text/csv']);
            }

            return Http::response('', 404);
        });
    }

    public function test_r22_undoing_an_automatic_import_sticks_and_restore_brings_your_decisions_back(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $mine = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $rows = [['2026-11-20', 'Adobe', -5698], ['2026-11-22', 'TfL', -520]];
        $asked = [];
        $this->fakeStarling($rows, $asked);
        $this->travelTo(CarbonImmutable::parse('2026-11-24 06:41', 'Europe/London'));
        $this->post('/settings/starling', ['token' => 'good-token-1234567890abcdef'])->assertSessionHas('success');
        $sync = app(StarlingSync::class);

        $this->assertStringContainsString('Imported 2 new', $sync->sync()[0]['result']);
        $first = ImportBatch::query()->where('account_id', $mine->id)->sole();
        foreach (BankTransaction::query()->get() as $tx) {
            $this->decideYourself($tx, 'business_expense', 'software');
        }
        app(Importer::class)->revert($first, 'Not sure about these');
        $this->assertSame(0, BankTransaction::query()->count());

        // Next morning: the week's overlap brings the undone rows back from Starling, plus a new one.
        $rows[] = ['2026-11-24', 'Pret', -320];
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:41', 'Europe/London'));
        $result = $sync->sync()[0]['result'];
        $this->assertSame(['2026-08-26..2026-11-23', '2026-11-16..2026-11-24'], $asked);
        $this->assertStringContainsString('Imported 1 new', $result);
        $this->assertStringContainsString('2 you undid', $result);
        $this->assertSame(['Pret'], BankTransaction::query()->pluck('counterparty')->all(), 'The undone rows stay undone');

        $this->assertSame(2, app(Importer::class)->restore($first->fresh(), 'Changed my mind'));
        $this->assertSame(3, BankTransaction::query()->count());
        $this->assertSame(2, BankTransaction::query()->where('import_batch_id', $first->id)->where('review_status', 'confirmed')->count(), 'Your decisions come back with them');
    }

    /** Connected, auto-linked to the only Starling account there was ('Starling Personal'), and imported once. */
    private function starlingIntoTheWrongAccount(array &$rows, array &$asked): Account
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $wrong = Account::query()->create(['name' => 'Starling Personal', 'kind' => 'bank', 'provider' => 'starling']);
        $this->fakeStarling($rows, $asked);
        $this->travelTo(CarbonImmutable::parse('2026-11-24 06:41', 'Europe/London'));
        $this->post('/settings/starling', ['token' => 'good-token-1234567890abcdef']);
        $this->assertSame($wrong->id, Setting::get('starling_accounts')['uid-1']['account_id']);
        $this->post('/settings/starling/sync')->assertSessionHas('success');
        $this->assertSame(3, BankTransaction::query()->where('account_id', $wrong->id)->count());

        return $wrong;
    }

    private function moneyIn(): int
    {
        return (int) BankTransaction::query()->where('amount_pence', '>', 0)->sum('amount_pence');
    }

    public function test_r36_relinking_after_undoing_the_wrong_accounts_import_brings_the_whole_three_months(): void
    {
        $rows = [['2026-09-01', 'Acme', 90000], ['2026-10-01', 'Adobe', -5698], ['2026-11-20', 'TfL', -520]];
        $asked = [];
        $wrong = $this->starlingIntoTheWrongAccount($rows, $asked);
        $right = Account::query()->create(['name' => 'Business', 'kind' => 'bank']);

        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $wrong->id])->assertSessionHas('success', 'Saved.');
        $this->assertSame('2026-11-23', Setting::get('starling_accounts')['uid-1']['synced_to'], 'Saving the same account changes nothing');

        app(Importer::class)->revert(ImportBatch::query()->where('account_id', $wrong->id)->sole(), 'Wrong account');
        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $right->id])
            ->assertSessionHas('success', 'Saved. The next import brings the last three months into Business; anything already there isn’t added twice.');
        $this->assertNull(Setting::get('starling_accounts')['uid-1']['synced_to']);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.starling_linked')->exists());

        $this->post('/settings/starling/sync')->assertSessionHas('success');
        $this->assertSame(['2026-08-26..2026-11-23', '2026-08-26..2026-11-23'], $asked);
        $this->assertSame(3, BankTransaction::query()->where('account_id', $right->id)->count(), 'The new account gets the whole three months');
        $this->assertSame(0, BankTransaction::query()->where('account_id', $wrong->id)->count());
        $this->assertSame(90000, $this->moneyIn());
    }

    public function test_r36_relinking_without_undoing_never_counts_a_day_in_both_accounts(): void
    {
        $rows = [['2026-09-01', 'Acme', 90000], ['2026-10-01', 'Adobe', -5698], ['2026-11-20', 'TfL', -520]];
        $asked = [];
        $wrong = $this->starlingIntoTheWrongAccount($rows, $asked);
        $right = Account::query()->create(['name' => 'Business', 'kind' => 'bank']);

        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $right->id])->assertSessionHas('success',
            'Saved. Starling Personal still has the 3 payments this Starling account imported into it, up to 20 Nov 2026, so Business gets what’s new from '
            .'21 Nov 2026 and nothing counts twice. If those belong in Business instead, undo those imports in Accounts › Import, and the next import '
            .'brings the last three months into Business.');

        // The next morning: only what's new goes into Business.
        $rows[] = ['2026-11-24', 'Pret', -320];
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:41', 'Europe/London'));
        $this->post('/settings/starling/sync')->assertSessionHas('success', 'Business: Imported 1 new, 0 already here.');
        $this->assertSame('2026-11-21..2026-11-24', end($asked));
        $this->assertSame(['Pret'], BankTransaction::query()->where('account_id', $right->id)->pluck('counterparty')->all());
        $this->assertSame(3, BankTransaction::query()->where('account_id', $wrong->id)->count(), 'What’s in Starling Personal is left as it is');
        $this->assertSame(90000, $this->moneyIn(), 'The £900 counts once');

        // You undo them in Starling Personal: the next import brings the three months into Business, without duplicates.
        app(Importer::class)->revert(ImportBatch::query()->where('account_id', $wrong->id)->sole(), 'They belong in Business');
        $this->travelTo(CarbonImmutable::parse('2026-11-26 06:41', 'Europe/London'));
        $this->post('/settings/starling/sync')->assertSessionHas('success', 'Business: Imported 3 new, 1 already here.');
        $this->assertSame('2026-08-28..2026-11-25', end($asked));
        $this->assertSame(4, BankTransaction::query()->where('account_id', $right->id)->count());
        $this->assertSame(0, BankTransaction::query()->where('account_id', $wrong->id)->count());
        $this->assertSame(90000, $this->moneyIn());
        $this->assertArrayNotHasKey('kept_in', Setting::get('starling_accounts')['uid-1']);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.starling_linked')->where('actor', 'system')->exists());

        // From then on, the usual week's overlap.
        $this->travelTo(CarbonImmutable::parse('2026-11-27 06:41', 'Europe/London'));
        $this->post('/settings/starling/sync')->assertSessionHas('success');
        $this->assertSame('2026-11-18..2026-11-26', end($asked));
    }

    public function test_r36_moving_on_again_before_an_import_still_knows_where_the_earlier_days_are(): void
    {
        $rows = [['2026-09-01', 'Acme', 90000], ['2026-10-01', 'Adobe', -5698], ['2026-11-20', 'TfL', -520]];
        $asked = [];
        $wrong = $this->starlingIntoTheWrongAccount($rows, $asked);
        $business = Account::query()->create(['name' => 'Business', 'kind' => 'bank']);
        $joint = Account::query()->create(['name' => 'Joint', 'kind' => 'bank']);

        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $business->id]);
        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $joint->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Starling Personal still has the 3 payments') && str_contains($m, 'Joint gets what’s new from 21 Nov 2026'));
        $this->post('/settings/starling/sync')->assertSessionHas('success');
        $this->assertSame('2026-11-21..2026-11-23', end($asked));
        $this->assertSame(0, BankTransaction::query()->whereIn('account_id', [$business->id, $joint->id])->count());

        // Back to Starling Personal: nothing is kept anywhere else, so the usual three months (already there, not added twice).
        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $wrong->id])->assertSessionHas('success', fn ($m) => str_contains($m, 'last three months into Starling Personal'));
        $this->post('/settings/starling/sync')->assertSessionHas('success', 'Starling Personal: Imported 0 new, 3 already here.');

        // Import from a date you choose is done as asked, but says when those days are already in another account.
        $this->patch('/settings/starling', ['uid' => 'uid-1', 'account_id' => $joint->id]);
        $this->post('/settings/starling/sync', ['from' => '2026-09-01'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Joint: Imported 3 new')
            && str_contains($m, 'Starling Personal still has the 3 payments this Starling account imported into it, up to 20 Nov 2026: undo those imports')
            && str_contains($m, 'or they count twice'));
    }

    // ---- R38: the tax "?" on a job day ---------------------------------------------------------------------------------

    public function test_r38_job_day_notes_fit_money_in_transfers_and_personal_items(): void
    {
        WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2025-04-03', 'ends_on' => '2025-04-03']);
        $notes = fn (BankTransaction $tx) => implode(' ', Explainer::payment($tx->fresh())['notes']);
        $allowable = 'normally allowable';
        $cost = 'counts as the job’s cost';

        $fee = $this->payment('2025-04-03', 120000, 'Acme Events Ltd');
        $this->assertStringNotContainsString($allowable, $notes($fee), 'Undecided money in');
        $this->assertStringContainsString('that job’s income', $notes($fee));
        $this->decideYourself($fee, 'business_income', 'turnover');
        $this->assertStringNotContainsString($cost, $notes($fee));
        $this->assertStringContainsString('that job’s income', $notes($fee));

        $move = $this->payment('2025-04-03', -25000, 'American Express');
        $this->decideYourself($move, 'transfer', null);
        $this->assertStringNotContainsString($allowable, $notes($move));
        $this->assertStringNotContainsString('Gala', $notes($move), 'Nothing about the job for a transfer');

        $lunch = $this->payment('2025-04-03', -1200, 'Wagamama');
        $this->decideYourself($lunch, 'personal', null);
        $this->assertStringNotContainsString($allowable, $notes($lunch));

        // Spending is unchanged: undecided gets the travel note, a business cost the link prompt.
        $taxi = $this->payment('2025-04-03', -2400, 'Addison Lee');
        $this->assertStringContainsString($allowable, $notes($taxi));
        $kit = $this->payment('2025-04-03', -4500, 'Screwfix');
        $this->decideYourself($kit, 'business_expense', 'materials');
        $this->assertStringContainsString($cost, $notes($kit));
    }
}
