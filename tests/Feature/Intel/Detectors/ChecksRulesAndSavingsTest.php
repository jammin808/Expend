<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\AcctUnchecked;
use App\Domain\Intel\Detectors\CheckUnverifiable;
use App\Domain\Intel\Detectors\Health;
use App\Domain\Intel\Detectors\RuleDrift;
use App\Domain\Intel\Detectors\TaxSaving;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Ranker;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\Reconciler;
use App\Domain\Tax\TaxSavings;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\StatementBalance;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** rule.drift, tax.saving, acct.unchecked, check.unverifiable and the health set. */
class ChecksRulesAndSavingsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-12-01 10:00:00');
        CarbonImmutable::setTestNow('2026-12-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tx(string $on, int $pence, string $name, ?Account $acc = null, ?int $balance = null): BankTransaction
    {
        $acc ??= Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't'.$acc->id.'.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [],
            'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    /** @return array<string, FindingDraft> */
    private function found(object $detector): array
    {
        return collect($detector->detect(new CycleContext))->keyBy('key')->all();
    }

    public function test_a_rule_you_changed_twice_is_found_from_your_own_decisions(): void
    {
        $equipment = Category::query()->where('code', 'equipment')->value('id');
        $rule = MerchantRule::query()->forceCreate(['merchant_key' => 'AMAZON', 'kind' => 'business_expense', 'category_id' => $equipment, 'business_pct' => 100, 'auto_confirm' => true]);
        $writer = app(AllocationWriter::class);
        $byRule = function (string $on) use ($writer, $rule, $equipment) {
            $t = $this->tx($on, -3000, 'AMAZON');
            $writer->decide($t, AllocationWriter::lines(-3000, 'business_expense', $equipment), 'rule', true, 'Your rule for AMAZON', $rule->id);

            return $t;
        };
        $a = $byRule('2026-10-01');
        $b = $byRule('2026-10-08');
        $c = $byRule('2026-10-15');
        // You confirm what the rule decided on one: not a change.
        $writer->decide($c, AllocationWriter::lines(-3000, 'business_expense', $equipment));
        $writer->decide($a, AllocationWriter::lines(-3000, 'personal'));
        $this->assertSame([], $this->found(new RuleDrift), 'Once is not drift');
        $writer->decide($b, AllocationWriter::lines(-3000, 'personal'));

        $d = $this->found(new RuleDrift)['rule_drift:'.$rule->id];
        $this->assertSame(['rule.drift', 'rule:'.$rule->id, 'none', 'certain', 2, null], [$d->code, $d->subjectRef, $d->direction, $d->grade, $d->meta['overrides'], $d->amountPence]);
        $this->assertStringStartsWith('You’ve changed what your rule for AMAZON decided 2 times', $d->title);
        $this->assertContains('tx:'.$a->id, $d->evidence);
        $this->assertNotEmpty(preg_grep('/^audit:\d+@[0-9a-f]{8}$/', $d->evidence), 'Each change is cited by its audit event');
    }

    public function test_tax_savings_from_the_review_read_only_the_records(): void
    {
        // Never AI, never the network: a detector reads the database.
        $this->app->instance(AiClient::class, new class implements AiClient
        {
            public function send(array $params): AiResult
            {
                throw new RuntimeException('A detector must never call AI.');
            }
        });
        Http::fake();
        Http::preventStrayRequests();
        $turnover = Category::query()->where('code', 'turnover')->value('id');
        foreach (['2026-05-10', '2026-08-10', '2026-11-10'] as $on) {
            $t = $this->tx($on, 1500000, 'ACME EVENTS');
            app(AllocationWriter::class)->decide($t, AllocationWriter::lines(1500000, 'business_income', $turnover));
        }

        $drafts = (new TaxSaving)->detect(new CycleContext);
        $review = TaxSavings::review('2026-27');
        $expected = collect($review['items'])->filter(fn ($i) => in_array($i['status'], ['action', 'check'], true) && ($i['saving_pence'] ?? 0) > 0);
        $this->assertNotEmpty($expected, 'Use of home, at least: nothing is claimed for it');
        $mine = collect($drafts)->filter(fn (FindingDraft $d) => $d->scopeRef === 'year:2026-27')->keyBy('key');
        $this->assertSame($expected->map(fn ($i) => 'saving:2026-27:'.$i['id'])->sort()->values()->all(), $mine->keys()->sort()->values()->all());
        $home = $mine['saving:2026-27:use-of-home'];
        $this->assertSame(['tax.saving', 'claims', 'claimable', 'likely'], [$home->code, $home->group, $home->direction, $home->grade]);
        $this->assertSame($expected->firstWhere('id', 'use-of-home')['saving_pence'], $home->amountPence);
        $this->assertIsInt($home->amountPence);
        Http::assertNothingSent();
    }

    public function test_an_account_that_cant_be_checked_agrees_with_the_reconciler(): void
    {
        $card = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card']);
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        foreach (range(1, 5) as $i) {
            $this->tx('2026-10-0'.$i, -1000, 'SHOP', $card);
            $this->tx('2026-10-0'.$i, -1000, 'SHOP', $bank, 100000 - $i * 1000);
        }
        $found = $this->found(new AcctUnchecked);
        $this->assertSame(['unchecked:'.$card->id], array_keys($found), 'The bank has its running balance');
        $this->assertSame('none', Reconciler::account($card)['method']);
        $this->assertSame(['acct.unchecked', 'acct:'.$card->id, null, 'reconcile'], [$found['unchecked:'.$card->id]->code, $found['unchecked:'.$card->id]->subjectRef,
            $found['unchecked:'.$card->id]->grade, $found['unchecked:'.$card->id]->group]);

        // Two balances from its statements: checkable.
        StatementBalance::query()->create(['account_id' => $card->id, 'as_of' => '2026-09-30', 'balance_pence' => 0]);
        StatementBalance::query()->create(['account_id' => $card->id, 'as_of' => '2026-10-31', 'balance_pence' => -5000]);
        $this->assertSame([], $this->found(new AcctUnchecked));
        $this->assertNotSame('none', Reconciler::account($card)['method']);
    }

    public function test_money_that_cant_be_checked_word_for_word(): void
    {
        $scan = Document::query()->create(['source' => 'upload', 'original_name' => 'scan.jpg', 'sha256' => hash('sha256', 'scan'), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'extracted',
            'extraction' => ['receipt' => ['merchant' => 'Pret', 'date' => '2026-10-01', 'amount_minor' => 1250, 'currency' => 'GBP']]]);
        $unread = Document::query()->create(['source' => 'upload', 'original_name' => 'new.pdf', 'sha256' => hash('sha256', 'new'), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'new']);
        $flyer = Document::query()->create(['source' => 'upload', 'original_name' => 'flyer.jpg', 'sha256' => hash('sha256', 'fl'), 'stored_path' => 'x', 'kind' => 'other', 'status' => 'extracted',
            'extraction' => ['summary' => 'A flyer']]);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2026-10-02', 'amount_minor' => 9900, 'source' => 'ai', 'evidence' => []]);
        foreach ([['document', $scan->id], ['document', $unread->id], ['document', $flyer->id], ['travel_item', $stay->id]] as [$type, $id]) {
            Verification::query()->create(['subject_type' => $type, 'subject_id' => $id, 'status' => 'unverifiable', 'method' => 'source_text', 'checks' => [], 'summary' => 'A scan or photo.', 'checked_at' => now()]);
        }
        $found = $this->found(new CheckUnverifiable);
        $this->assertSame(['unverifiable:document:'.$scan->id, 'unverifiable:travel_item:'.$stay->id], collect($found)->keys()->sort()->values()->all(),
            'Only what carries money and was read');
        $d = $found['unverifiable:document:'.$scan->id];
        $this->assertSame(['check.unverifiable', -1250, 'none', null, 'doc:'.$scan->id.'@'.substr($scan->sha256, 0, 8)], [$d->code, $d->amountPence, $d->direction, $d->grade, $d->subjectRef]);
        $this->assertSame([['code' => 'recheck', 'id' => 'document:'.$scan->id, 'label' => 'Re-check', 'style' => 'default']], $d->actions);
    }

    public function test_the_health_set_reuses_the_items_and_adds_the_mailbox_and_waiting_reads(): void
    {
        Setting::put('imap_host', 'imap.example.test');
        Setting::put('imap_username', 'inbox@example.test');
        Setting::put('imap_password', 'not-a-real-one');
        Setting::put(Health::MAILBOX_RESULT, ['ok' => false, 'at' => '2026-12-01T06:00:00+00:00', 'error' => 'Login failed']);
        Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'w'), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'waiting_budget']);
        GmailMessage::query()->create(['message_id' => 'm1', 'search' => 'travel', 'status' => 'waiting_budget']);
        Setting::put('audit_chain_result', ['ok' => false, 'at' => '2026-12-01T03:00:00+00:00', 'broken_at' => 12, 'checked' => 11]);

        $health = new Health;
        $found = collect($health->detect(new CycleContext))->keyBy('key');
        $this->assertSame(['health:audit', 'health:mailbox', 'health:ai_cap'], $found->keys()->all());
        $this->assertSame('Forwarding mailbox not read since 1 Dec, 06:00', $found['health:mailbox']->title);
        $this->assertStringNotContainsString('Login failed', (string) $found['health:mailbox']->detail, 'The error itself isn’t repeated');
        $this->assertSame('1 document and 1 booking email not read: the AI spending cap was reached', $found['health:ai_cap']->title);
        $this->assertStringContainsString('will be read on their own once the cap allows', $found['health:ai_cap']->detail);
        foreach (Health::CODES as $code) {
            $this->assertSame('complete', $health->coverageOf($code)->value, $code);
        }
        // On the list as before: first, snooze only.
        $items = collect(TodoList::build()['items'])->keyBy('key');
        $this->assertSame([1, 'health', true, 'critical'], [$items['health:audit']['priority'], $items['health:audit']['group'], $items['health:audit']['snooze_only'], $items['health:audit']['severity']]);
        $this->assertSame('important', $items['health:mailbox']['severity']);

        // A step failing two nights running is critical (contract 3: the cycle's own detector gives its nights).
        $this->assertSame('important', Ranker::severity(['code' => 'health.cycle_step', 'group' => 'health', 'nights' => 1]));
        $this->assertSame('critical', Ranker::severity(['code' => 'health.cycle_step', 'group' => 'health', 'nights' => 2]));
        // The nightly audit log check itself failing is critical from its first night (section 6.4).
        $this->assertSame('critical', Ranker::severity(['code' => 'health.cycle_step', 'group' => 'health', 'nights' => 1, 'step' => 'verify.audit_chain']));
        $this->assertSame('info', Ranker::severity(['code' => 'job.closeout', 'amount' => 900000, 'direction' => 'owed_to_you', 'grade' => 'certain', 'below_fold' => true]));
    }
}
