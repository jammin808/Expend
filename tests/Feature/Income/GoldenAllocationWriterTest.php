<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Golden pin (build slice S0, income v3): what AllocationWriter accepts and refuses today, with its exact messages, the
 * automatic writes it turns away ('yours', 'outranked', 'vetoed'), that an unchanged re-save writes nothing, and the
 * keys of the audit snapshots it records. Pasted from running HEAD 2d54ad6, and kept green by every later slice.
 *
 * Income v3 narrows the sign rule (a negative business_expense beside income on money in becomes a deduction) and adds
 * client_id to the snapshot only when it's set: the refusals pinned here are the ones it keeps, and the shapes here
 * carry no client, so their snapshots keep exactly these keys. The automatic writes here are expenses (or an invoice
 * suggestion left open), so 'automation never confirms an income link' doesn't touch them.
 */
class GoldenAllocationWriterTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function writer(): AllocationWriter
    {
        return app(AllocationWriter::class);
    }

    private function category(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function job(string $title): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-20', 'status' => 'done']);
    }

    private function tx(int $pence, string $name = 'Adobe', string $on = '2026-05-28'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'golden-writer-'.$this->n]);
    }

    /**
     * The line's allocations as stored: kind, category code, job title, invoice number, amount, business share, maker,
     * yours or not, confirmed and by whom, method, reason and note.
     *
     * @return list<list<mixed>>
     */
    private function rows(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->with(['category', 'job', 'invoice'])->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->category?->code, $a->job?->title, $a->invoice?->number, $a->amount_pence, $a->business_use_pct, $a->decided_by,
                $a->is_manual, $a->confirmed_at !== null, $a->confirmed_by, $a->method, $a->reason, $a->note])->all();
    }

    /** What decide() threw, or null when it didn't. */
    private function refusal(callable $write): ?string
    {
        try {
            $write();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * A snapshot's keys, in order: the stored order depends on how the event is hashed (an event that records where it
     * came from is stored with its keys sorted), so the keys are compared as a set.
     *
     * @return list<string>
     */
    private static function keys(array $snapshot): array
    {
        $keys = array_keys($snapshot);
        sort($keys);

        return $keys;
    }

    private function suggestion(string $method): Provenance
    {
        return new Provenance($method, [], 1);
    }

    public function test_the_line_sets_it_accepts_today(): void
    {
        $arena = $this->job('Arena load-in');
        $gala = $this->job('Gala dinner');
        $w = $this->writer();

        // A single line.
        $single = $this->tx(-4500, 'Adobe');
        $this->assertTrue($w->decide($single, AllocationWriter::lines(-4500, 'business_expense', $this->category('software'))));
        $this->assertSame([['business_expense', 'software', null, null, -4500, 100, 'you', true, true, 'you', null, null, null]], $this->rows($single));
        $this->assertSame('confirmed', $single->fresh()->review_status);

        // Business and personal, split by share in whole pence (an odd amount: the business part rounds towards nothing).
        $lines = AllocationWriter::lines(-999, 'business_expense', $this->category('phone_internet'), null, 33, 'Phone bill');
        $this->assertSame([
            ['kind' => 'business_expense', 'category_id' => $this->category('phone_internet'), 'work_job_id' => null, 'amount_pence' => -329, 'business_use_pct' => 33, 'note' => 'Phone bill'],
            ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => -670, 'business_use_pct' => null, 'note' => 'Phone bill'],
        ], $lines);
        $split = $this->tx(-999, 'EE');
        $this->assertTrue($w->decide($split, $lines, reason: 'Two-thirds personal'));
        $this->assertSame([
            ['business_expense', 'phone_internet', null, null, -329, 33, 'you', true, true, 'you', null, 'Two-thirds personal', 'Phone bill'],
            ['personal', null, null, null, -670, null, 'you', true, true, 'you', null, 'Two-thirds personal', 'Phone bill'],
        ], $this->rows($split));
        // All business, or a kind with no category: one line.
        $this->assertSame([['kind' => 'business_expense', 'category_id' => 7, 'work_job_id' => 3, 'amount_pence' => -500, 'business_use_pct' => 100, 'note' => null]],
            AllocationWriter::lines(-500, 'business_expense', 7, 3));
        $this->assertSame([['kind' => 'drawings', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => -500, 'business_use_pct' => null, 'note' => null]],
            AllocationWriter::lines(-500, 'drawings', null, null, 40));

        // Income split across two jobs.
        $multi = $this->tx(100000, 'Acme Events Ltd');
        $this->assertTrue($w->decide($multi, [
            ['kind' => 'business_income', 'category_id' => $this->category('turnover'), 'work_job_id' => $arena->id, 'amount_pence' => 60000],
            ['kind' => 'business_income', 'category_id' => $this->category('turnover'), 'work_job_id' => $gala->id, 'amount_pence' => 40000],
        ]));
        $this->assertSame([
            ['business_income', 'turnover', 'Arena load-in', null, 60000, null, 'you', true, true, 'you', null, null, null],
            ['business_income', 'turnover', 'Gala dinner', null, 40000, null, 'you', true, true, 'you', null, null, null],
        ], $this->rows($multi));

        // Negative income on money out: money paid back to a client for a job.
        $refund = $this->tx(-5000, 'Refund Acme Events');
        $this->assertTrue($w->decide($refund, AllocationWriter::lines(-5000, 'business_income', $this->category('turnover'), $arena->id)));
        $this->assertSame([['business_income', 'turnover', 'Arena load-in', null, -5000, 100, 'you', true, true, 'you', null, null, null]], $this->rows($refund));

        // A cost refunded to you: a business expense on money in.
        $back = $this->tx(1500, 'Adobe refund');
        $this->assertTrue($w->decide($back, AllocationWriter::lines(1500, 'business_expense', $this->category('software'))));
        $this->assertSame([['business_expense', 'software', null, null, 1500, 100, 'you', true, true, 'you', null, null, null]], $this->rows($back));

        // Money moving between your own accounts, and money in that's personal.
        $transfer = $this->tx(-20000, 'To savings');
        $this->assertTrue($w->decide($transfer, AllocationWriter::lines(-20000, 'transfer')));
        $this->assertSame([['transfer', null, null, null, -20000, null, 'you', true, true, 'you', null, null, null]], $this->rows($transfer));
        $gift = $this->tx(2500, 'Birthday money');
        $this->assertTrue($w->decide($gift, AllocationWriter::lines(2500, 'personal')));
        $this->assertSame([['personal', null, null, null, 2500, null, 'you', true, true, 'you', null, null, null]], $this->rows($gift));

        // An invoice named on an income line is kept on it.
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0001', 'work_job_id' => $gala->id, 'client_id' => $gala->client_id, 'currency' => 'GBP',
            'total_minor' => 40000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-21', 'due_date' => '2026-06-20']);
        $paid = $this->tx(40000, 'Acme Events Ltd');
        $lines = AllocationWriter::lines(40000, 'business_income', $this->category('turnover'), $gala->id);
        $lines[0]['invoice_id'] = $invoice->id;
        $this->assertTrue($w->decide($paid, $lines));
        $this->assertSame([['business_income', 'turnover', 'Gala dinner', 'INV-0001', 40000, 100, 'you', true, true, 'you', null, null, null]], $this->rows($paid));
        $this->assertNull($w->refused);
    }

    public function test_the_refusals_and_their_exact_messages(): void
    {
        $w = $this->writer();
        $out = $this->tx(-4500, 'Adobe');
        $in = $this->tx(10000, 'Acme Events Ltd');
        $software = $this->category('software');
        $turnover = $this->category('turnover');
        $events = AuditEvent::query()->count();

        $refused = [
            'no lines' => $this->refusal(fn () => $w->decide($out, [])),
            'unknown type' => $this->refusal(fn () => $w->decide($out, [['kind' => 'gift', 'amount_pence' => -4500]])),
            'zero amount' => $this->refusal(fn () => $w->decide($out, [['kind' => 'personal', 'amount_pence' => 0], ['kind' => 'personal', 'amount_pence' => -4500]])),
            'amount not whole pence' => $this->refusal(fn () => $w->decide($out, [['kind' => 'personal', 'amount_pence' => '-4500']])),
            'sum mismatch' => $this->refusal(fn () => $w->decide($out, [['kind' => 'business_expense', 'category_id' => $software, 'amount_pence' => -4000]])),
            'no category' => $this->refusal(fn () => $w->decide($out, [['kind' => 'business_expense', 'amount_pence' => -4500]])),
            'income category on an expense' => $this->refusal(fn () => $w->decide($out, AllocationWriter::lines(-4500, 'business_expense', $turnover))),
            'expense category on income' => $this->refusal(fn () => $w->decide($in, AllocationWriter::lines(10000, 'business_income', $software))),
            'money in on money out' => $this->refusal(fn () => $w->decide($out, [['kind' => 'personal', 'amount_pence' => -5000], ['kind' => 'personal', 'amount_pence' => 500]])),
            'negative income on money in' => $this->refusal(fn () => $w->decide($in, [
                ['kind' => 'business_income', 'category_id' => $turnover, 'amount_pence' => 15000], ['kind' => 'business_income', 'category_id' => $turnover, 'amount_pence' => -5000]])),
            'negative personal on money in' => $this->refusal(fn () => $w->decide($in, [
                ['kind' => 'business_income', 'category_id' => $turnover, 'amount_pence' => 12000], ['kind' => 'personal', 'amount_pence' => -2000]])),
            'negative expense on money in with no income line' => $this->refusal(fn () => $w->decide($in, [
                ['kind' => 'personal', 'amount_pence' => 13000], ['kind' => 'business_expense', 'category_id' => $software, 'amount_pence' => -3000]])),
            'nothing to confirm' => $this->refusal(fn () => $w->confirm($out)),
        ];
        $this->assertSame([
            'no lines' => 'A decision needs at least one line.',
            'unknown type' => 'Line 1 has an unknown type.',
            'zero amount' => 'Line 1 needs a non-zero amount.',
            'amount not whole pence' => 'Line 1 needs a non-zero amount.',
            'sum mismatch' => 'The lines add up to −£40.00 but the amount is −£45.00. They must match exactly.',
            'no category' => 'Line 1 needs an HMRC category.',
            'income category on an expense' => 'Line 1: "Turnover (sales and fees)" isn\'t an expense category.',
            'expense category on income' => 'Line 1: "Software and subscriptions" isn\'t an income category.',
            'money in on money out' => 'Line 2 must be money out, like the transaction itself.',
            'negative income on money in' => 'Line 2 must be money in, like the transaction itself.',
            'negative personal on money in' => 'Line 2 must be money in, like the transaction itself.',
            'negative expense on money in with no income line' => 'Line 2 must be money in, like the transaction itself.',
            'nothing to confirm' => 'There is nothing to confirm yet. Choose what this is first.',
        ], $refused);
        // Automation is held to the same rules.
        $this->assertSame('Line 1 needs a non-zero amount.', $this->refusal(fn () => $w->decide($out, [['kind' => 'personal', 'amount_pence' => 0]], 'matcher', false, null, null, $this->suggestion('categorise.merchant'))));

        // A locked year changes nothing: decided, confirmed or cleared, until it's unlocked.
        $old = $this->tx(-1200, 'Parking', '2025-05-10');
        $w->decide($old, AllocationWriter::lines(-1200, 'business_expense', $this->category('travel_parking')));
        TaxYear::track('2025-26', 'filed')->update(['locked_at' => now()]);
        $events = AuditEvent::query()->count();
        $locked = 'Tax year 2025-26 is locked. Unlock it (with a reason) before changing anything in it.';
        $this->assertSame([$locked, $locked, $locked, $locked], [
            $this->refusal(fn () => $w->decide($old, AllocationWriter::lines(-1200, 'personal'))),
            $this->refusal(fn () => $w->decide($old, AllocationWriter::lines(-1200, 'personal'), 'matcher', false, null, null, $this->suggestion('categorise.merchant'))),
            $this->refusal(fn () => $w->confirm($old)),
            $this->refusal(fn () => $w->clear($old)),
        ]);

        // Nothing was written by any of them.
        $this->assertSame([], $this->rows($out));
        $this->assertSame([], $this->rows($in));
        $this->assertSame(['unreviewed', 'unreviewed'], [$out->fresh()->review_status, $in->fresh()->review_status]);
        $this->assertSame([['business_expense', 'travel_parking', null, null, -1200, 100, 'you', true, true, 'you', null, null, null]], $this->rows($old));
        $this->assertSame($events, AuditEvent::query()->count());
    }

    public function test_automation_is_refused_as_yours_outranked_and_vetoed(): void
    {
        $w = $this->writer();
        $software = $this->category('software');
        $travel = $this->category('travel_rail');
        $this->assertSame([
            'yours' => 'you have already decided or confirmed this transaction',
            'vetoed' => 'you said no to that for this transaction before',
            'outranked' => 'a stronger suggestion is waiting for it (a matcher’s or a rule’s), for you to accept or change',
        ], array_intersect_key(AllocationWriter::REFUSED_WORDS, array_flip(['yours', 'vetoed', 'outranked'])));

        // Yours: decided by you, or a suggestion you accepted.
        $decided = $this->tx(-4500, 'Adobe');
        $w->decide($decided, AllocationWriter::lines(-4500, 'business_expense', $software));
        $this->assertFalse($w->decide($decided, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'A guess', null, $this->suggestion('categorise.merchant')));
        $this->assertSame('yours', $w->refused);
        $accepted = $this->tx(-2390, 'Trainline');
        $this->assertTrue($w->decide($accepted, AllocationWriter::lines(-2390, 'business_expense', $travel), 'matcher', false, 'A train', null, $this->suggestion('suggest.travel')));
        $w->confirm($accepted);
        $this->assertFalse($w->decide($accepted, AllocationWriter::lines(-2390, 'personal'), 'rule', false, 'Your rule', null, $this->suggestion('rule.owner')));
        $this->assertSame('yours', $w->refused);
        $this->assertSame([['business_expense', 'travel_rail', null, null, -2390, 100, 'matcher', false, true, 'you', 'suggest.travel', 'A train', null]], $this->rows($accepted));

        // Outranked: an open travel match stays over a guess; the same rank, or higher, replaces it.
        $open = $this->tx(-1850, 'GWR');
        $this->assertTrue($w->decide($open, AllocationWriter::lines(-1850, 'business_expense', $travel), 'matcher', false, 'A train', null, $this->suggestion('suggest.travel')));
        $this->assertFalse($w->decide($open, AllocationWriter::lines(-1850, 'personal'), 'matcher', false, 'A guess', null, $this->suggestion('categorise.merchant')));
        $this->assertSame('outranked', $w->refused);
        $this->assertFalse($w->decide($open, AllocationWriter::lines(-1850, 'personal'), 'ai', false, 'The AI', null, $this->suggestion('ai.advisor')));
        $this->assertSame('outranked', $w->refused);
        $this->assertTrue($w->decide($open, AllocationWriter::lines(-1850, 'business_expense', $this->category('travel_penalties')), 'matcher', false, 'A fine', null, $this->suggestion('suggest.penalty')));
        $this->assertNull($w->refused);
        $this->assertTrue($w->decide($open, AllocationWriter::lines(-1850, 'business_expense', $travel), 'rule', false, 'Your rule', null, $this->suggestion('rule.owner')));
        $this->assertSame([['business_expense', 'travel_rail', null, null, -1850, 100, 'rule', false, false, null, 'rule.owner', 'Your rule', null]], $this->rows($open));
        $this->assertFalse($w->decide($open, AllocationWriter::lines(-1850, 'personal'), 'matcher', false, 'A fine', null, $this->suggestion('suggest.penalty')));
        $this->assertSame('outranked', $w->refused);

        // Vetoed: a shape you said no to, and an invoice you said a payment isn't for.
        $no = $this->tx(-6000, 'Pret');
        $shape = AllocationWriter::lines(-6000, 'business_expense', $this->category('travel_subsistence'));
        Vetoes::record('allocation_shape', 'tx:'.$no->id, null, Vetoes::shape($shape), null, 'block', 'wrong');
        $this->assertFalse($w->decide($no, $shape, 'matcher', false, 'A meal', null, $this->suggestion('categorise.merchant')));
        $this->assertSame('vetoed', $w->refused);
        $this->assertTrue($w->decide($no, AllocationWriter::lines(-6000, 'personal'), 'matcher', false, 'Lunch', null, $this->suggestion('categorise.merchant')), 'Another shape is fine');
        $gala = $this->job('Gala dinner');
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0001', 'work_job_id' => $gala->id, 'client_id' => $gala->client_id, 'currency' => 'GBP',
            'total_minor' => 40000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-21', 'due_date' => '2026-06-20']);
        $notFor = $this->tx(40000, 'Acme Events Ltd');
        Vetoes::record('invoice_payment', 'tx:'.$notFor->id, 'inv:'.$invoice->id, null, null, 'block', 'not_this');
        $lines = AllocationWriter::lines(40000, 'business_income', $this->category('turnover'), $gala->id);
        $lines[0]['invoice_id'] = $invoice->id;
        $this->assertFalse($w->decide($notFor, $lines, 'matcher', false, 'Matches invoice INV-0001', null, new Provenance('suggest.invoice', ['inv:'.$invoice->id], 1)));
        $this->assertSame('vetoed', $w->refused);
        $this->assertSame([], $this->rows($notFor));
        // You can still decide it that way yourself.
        $this->assertTrue($w->decide($notFor, $lines));
        $this->assertNull($w->refused);
    }

    public function test_an_unchanged_resave_writes_nothing(): void
    {
        $w = $this->writer();
        $tx = $this->tx(-4500, 'Adobe');
        $lines = AllocationWriter::lines(-4500, 'business_expense', $this->category('software'), null, 100, 'Creative Cloud');
        $this->assertTrue($w->decide($tx, $lines, reason: 'Monthly'));
        $ids = Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all();
        $events = AuditEvent::query()->count();

        Carbon::setTestNow('2026-06-11 09:00:00');
        $this->assertTrue($w->decide($tx->fresh(), $lines, reason: 'Monthly'), 'Already says this');
        $this->assertSame($events, AuditEvent::query()->count(), 'Nothing recorded');
        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all(), 'Nothing rewritten');
        $this->assertSame('2026-06-10 10:00:00', Allocation::query()->findOrFail($ids[0])->confirmed_at->format('Y-m-d H:i:s'), 'Still confirmed when it was');

        // An open suggestion made again by the same method: nothing written either.
        $open = $this->tx(-1850, 'GWR');
        $suggested = AllocationWriter::lines(-1850, 'business_expense', $this->category('travel_rail'));
        $this->assertTrue($w->decide($open, $suggested, 'matcher', false, 'A train', null, $this->suggestion('suggest.travel')));
        $events = AuditEvent::query()->count();
        $openIds = Allocation::query()->where('bank_transaction_id', $open->id)->pluck('id')->all();
        $this->assertTrue($w->decide($open->fresh(), $suggested, 'matcher', false, 'A train', null, $this->suggestion('suggest.travel')));
        $this->assertSame($events, AuditEvent::query()->count());
        $this->assertSame($openIds, Allocation::query()->where('bank_transaction_id', $open->id)->pluck('id')->all());

        // A change (a different note) is written and recorded.
        $lines[0]['note'] = 'Creative Cloud, yearly';
        $this->assertTrue($w->decide($tx->fresh(), $lines, reason: 'Monthly'));
        $this->assertSame($events + 1, AuditEvent::query()->count());
        $this->assertNotSame($ids, Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all());
    }

    public function test_the_audit_snapshots_keep_these_keys(): void
    {
        $w = $this->writer();
        $gala = $this->job('Gala dinner');
        $software = $this->category('software');
        $keys = ['amount_pence', 'business_use_pct', 'category_id', 'confirmed', 'decided_by', 'invoice_id', 'is_manual', 'kind', 'merchant_rule_id', 'method', 'note', 'reason', 'work_job_id'];

        // Your decision: allocation.decided, by you, nothing before.
        $tx = $this->tx(-4500, 'Adobe');
        $w->decide($tx, AllocationWriter::lines(-4500, 'business_expense', $software, $gala->id, 100, 'Creative Cloud'), reason: 'Monthly');
        $decided = AuditEvent::query()->where('action', 'allocation.decided')->sole();
        $this->assertSame(['BankTransaction', $tx->id, 'you', 'Monthly', null], [$decided->subject_type, (int) $decided->subject_id, $decided->actor, $decided->reason, $decided->before]);
        $this->assertCount(1, $decided->after);
        $this->assertSame($keys, self::keys($decided->after[0]));
        $this->assertEquals(['amount_pence' => -4500, 'business_use_pct' => 100, 'category_id' => $software, 'confirmed' => true, 'decided_by' => 'you', 'invoice_id' => null,
            'is_manual' => true, 'kind' => 'business_expense', 'merchant_rule_id' => null, 'method' => null, 'note' => 'Creative Cloud', 'reason' => 'Monthly', 'work_job_id' => $gala->id], $decided->after[0]);

        // Changed: what it was is kept as before, with the same keys.
        $w->decide($tx->fresh(), AllocationWriter::lines(-4500, 'business_expense', $software, null, 50), reason: 'Half personal');
        $changed = AuditEvent::query()->where('action', 'allocation.decided')->latest('id')->first();
        $this->assertSame($decided->after, $changed->before);
        $this->assertSame([$keys, $keys], array_map(fn ($s) => self::keys($s), $changed->after));
        $this->assertSame([['business_expense', -2250, 50], ['personal', -2250, null]], array_map(fn ($s) => [$s['kind'], $s['amount_pence'], $s['business_use_pct']], $changed->after));

        // An automatic suggestion: allocation.suggested, by its maker, with its method.
        $open = $this->tx(-1850, 'GWR');
        $w->decide($open, AllocationWriter::lines(-1850, 'business_expense', $this->category('travel_rail')), 'matcher', false, 'A train', null, $this->suggestion('suggest.travel'));
        $suggested = AuditEvent::query()->where('action', 'allocation.suggested')->sole();
        $this->assertSame(['matcher', 'A train', null], [$suggested->actor, $suggested->reason, $suggested->before]);
        $this->assertSame($keys, self::keys($suggested->after[0]));
        $this->assertSame(['matcher', false, false, 'suggest.travel', 'A train'], [$suggested->after[0]['decided_by'], $suggested->after[0]['is_manual'], $suggested->after[0]['confirmed'],
            $suggested->after[0]['method'], $suggested->after[0]['reason']]);

        // Accepted by you: allocation.confirmed, the lines as they were before the tap.
        $w->confirm($open->fresh(), 'Looks right');
        $confirmed = AuditEvent::query()->where('action', 'allocation.confirmed')->sole();
        $this->assertSame(['you', 'Looks right', null], [$confirmed->actor, $confirmed->reason, $confirmed->before]);
        $this->assertSame($keys, self::keys($confirmed->after[0]));
        $this->assertSame($suggested->after, $confirmed->after);

        // Cleared: allocation.cleared, what it was before, nothing after.
        $w->clear($open->fresh(), 'Not a train');
        $cleared = AuditEvent::query()->where('action', 'allocation.cleared')->sole();
        $this->assertSame(['you', 'Not a train', null], [$cleared->actor, $cleared->reason, $cleared->after]);
        $this->assertSame($keys, self::keys($cleared->before[0]));
        $this->assertTrue($cleared->before[0]['confirmed']);
        $this->assertSame('unreviewed', $open->fresh()->review_status);
        $this->assertSame([], $this->rows($open));
    }
}
