<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Verification\Verifier;
use App\Jobs\GatherFromGmail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Every automatic change is recorded as what made it (the matcher, the system, the checker), never as you, and your
 * own taps still are yours. The actor comes from an in-memory AuditContext where the code doesn't name one.
 */
class HonestActorsTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private Account $revolut;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('job_finder_auto', false);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => MerchantKey::from($description, $counterparty), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1,
        ]);
    }

    private function event(string $action, BankTransaction|TravelItem|WorkJob $subject): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->where('subject_type', class_basename($subject))->where('subject_id', $subject->id)->sole();
    }

    // ---- the context itself ---------------------------------------------------------------------------------------

    public function test_the_context_names_the_actor_only_inside_it_and_nests(): void
    {
        $this->assertSame('you', AuditContext::actor());
        $this->assertNull(AuditContext::current());

        $seen = AuditContext::with('matcher', function () {
            $inner = AuditContext::with('system', fn () => AuditContext::actor(), 'schedule');

            return [$inner, AuditContext::actor(), AuditLog::record('test.inside', 'Test')->actor, AuditLog::record('test.named', 'Test', actor: 'checker')->actor];
        }, 'queue');

        $this->assertSame(['system', 'matcher', 'matcher', 'checker'], $seen, 'The innermost wins; an actor named by the caller wins over the context');
        $this->assertSame('you', AuditContext::actor());
        $this->assertSame('you', AuditLog::record('test.outside', 'Test')->actor);

        try {
            AuditContext::with('matcher', fn () => throw new RuntimeException('stopped'));
        } catch (RuntimeException) {
        }
        $this->assertSame('you', AuditContext::actor(), 'An exception never leaves the context behind');

        $this->expectException(InvalidArgumentException::class);
        AuditContext::with('server', fn () => null);
    }

    public function test_a_subject_given_by_name_can_carry_its_id(): void
    {
        $event = AuditLog::record('test.named_subject', 'Document', null, ['ok' => true], null, 'checker', 55);

        $this->assertSame(['Document', 55, 'checker'], [$event->subject_type, $event->subject_id, $event->actor]);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    // ---- your taps stay yours ------------------------------------------------------------------------------------

    public function test_a_web_decision_and_confirmation_carry_you(): void
    {
        $this->actingAs(User::factory()->create());
        $coffee = $this->tx($this->starling, '2025-06-10', -350, 'PRET A MANGER', 'PRET A MANGER');
        $this->post("/items/bank/{$coffee->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();
        $this->assertSame('you', $this->event('allocation.decided', $coffee)->actor);

        $lunch = $this->tx($this->starling, '2025-06-11', -650, 'GREGGS', 'GREGGS');
        app(AllocationWriter::class)->decide($lunch, AllocationWriter::lines(-650, 'personal'), 'matcher', false, 'Looks like food or drink, with no job that day: personal');
        app(AllocationWriter::class)->confirm($lunch);
        $this->assertSame(['matcher', 'you'], [$this->event('allocation.suggested', $lunch)->actor, $this->event('allocation.confirmed', $lunch)->actor]);
    }

    // ---- a transfer's other side -----------------------------------------------------------------------------------

    /** @return array{0:BankTransaction, 1:BankTransaction} money out of Starling and into Revolut, paired and suggested as a transfer */
    private function pair(): array
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $this->assertSame(1, app(TransferPairer::class)->pairAll());

        return [$out->fresh(), $in->fresh()];
    }

    public function test_the_partner_confirmed_because_of_your_decision_is_the_matchers(): void
    {
        $this->actingAs(User::factory()->create());
        [$out, $in] = $this->pair();
        $this->assertSame('matcher', $this->event('transfer.paired', $in)->actor);

        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'transfer', 'business_pct' => 100])->assertSessionHasNoErrors();

        $this->assertSame('you', $this->event('allocation.decided', $out)->actor);
        $confirmed = $this->event('allocation.confirmed', $in);
        $this->assertSame(['matcher', 'Its other side was decided as a transfer'], [$confirmed->actor, $confirmed->reason]);
        $this->assertSame('confirmed', $in->fresh()->review_status);
    }

    public function test_the_partner_taken_back_because_of_your_decision_is_the_matchers(): void
    {
        $this->actingAs(User::factory()->create());
        [$out, $in] = $this->pair();

        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();

        $cleared = $this->event('allocation.cleared', $in);
        $this->assertSame(['matcher', 'Its other side was decided as not a transfer'], [$cleared->actor, $cleared->reason]);
        $this->assertSame('you', AuditEvent::query()->where('action', 'transfer.unpaired')->where('subject_id', $out->id)->sole()->actor, 'Unpairing your side is your decision');
        $this->assertNull($in->fresh()->transfer_pair_id);
    }

    // ---- automation in the background -----------------------------------------------------------------------------

    public function test_a_clear_inside_gather_from_gmail_is_the_matchers(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests(); // nothing leaves the test
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);
        $taxi = $this->tx($this->starling, '2025-05-14', -2350, 'UBER *TRIP', 'UBER');
        // Guessed personal before the job existed.
        app(AllocationWriter::class)->decide($taxi, AllocationWriter::lines(-2350, 'personal'), 'matcher', false, 'Looks like a taxi, with no job that day: personal');

        (new GatherFromGmail($job->id))->handle(app(GmailScanner::class), app(JobGatherer::class)); // Gmail isn't connected: the gather still runs

        $cleared = $this->event('allocation.cleared', $taxi);
        $this->assertSame(['matcher', 'Looked at again: there’s now a job that day'], [$cleared->actor, $cleared->reason]);
        $this->assertFalse(AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $taxi->id)->where('actor', 'you')->exists());
    }

    public function test_a_payment_taken_back_from_a_client_booked_stay_is_the_matchers(): void
    {
        $paid = $this->tx($this->starling, '2025-05-12', -8900, 'PREMIER INN LEEDS', 'PREMIER INN');
        app(AllocationWriter::class)->decide($paid, AllocationWriter::lines(-8900, 'personal'), 'matcher', false, 'Premier Inn Leeds: not near a job');
        $stay = TravelItem::query()->create([
            'kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'PI-1', 'starts_on' => '2025-05-11', 'ends_on' => '2025-05-12', 'place' => 'Premier Inn Leeds',
            'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data', 'bank_transaction_id' => $paid->id,
        ]);

        app(TravelMatcher::class)->decide($stay, 'client_booked', null, null, null);

        $this->assertSame('matcher', $this->event('allocation.cleared', $paid)->actor);
        $this->assertSame('you', $this->event('travel.decided', $stay)->actor, 'The decision on the stay is yours');
        $this->assertSame([], $paid->fresh()->allocations()->get()->all());
    }

    public function test_the_gmail_label_is_the_systems(): void
    {
        Storage::fake('local');
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/labels') && $request->method() === 'GET' => Http::response(['labels' => []]),
                str_contains($url, '/labels') => Http::response(['id' => 'Label_9', 'name' => $request['name']]),
                str_contains($url, 'batchModify') => Http::response('', 204),
                default => Http::response(['messages' => []]),
            };
        });
        $job = WorkJob::query()->create(['title' => 'May show', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10']);
        $email = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: PO\r\n\r\nPO-7781\r\n", 'po.eml', 'message/rfc822', 'gmail');
        $email->update(['kind' => 'po', 'work_job_id' => $job->id, 'extraction' => ['po' => ['po_number' => 'PO-7781']]]);
        GmailMessage::query()->create(['message_id' => 'gm1', 'search' => 'job_documents', 'document_id' => $email->id]);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 50000, 'bill_to_name' => 'Acme Events']);
        $invoice->allLines()->create(['description' => 'Show day', 'quantity' => 1, 'unit_price_minor' => 50000, 'amount_minor' => 50000]);

        $invoice = app(InvoiceService::class)->issue($invoice->fresh()); // labels the PO email (the queue runs at once in tests)
        app(InvoiceService::class)->void($invoice->fresh(), 'Wrong amount'); // and takes the label off again

        $this->assertSame('system', $this->event('gmail.labelled', $job)->actor);
        $this->assertSame('system', AuditEvent::query()->where('action', 'gmail.unlabelled')->sole()->actor);
        $this->assertSame('you', AuditEvent::query()->where('action', 'invoice.issued')->sole()->actor, 'Issuing the invoice is yours');
    }

    public function test_what_arrives_by_itself_is_the_systems_and_what_you_hand_over_is_yours(): void
    {
        Storage::fake('local');
        $intake = app(DocumentIntake::class);
        $gmail = $intake->store("From: buyer@acme.example\r\nSubject: PO\r\n\r\nPO-7781\r\n", 'po.eml', 'message/rfc822', 'gmail');
        $mailbox = $intake->store("From: Alex Smith <alex@example.com>\r\nSubject: Receipt\r\n\r\nTaxi to the venue\r\n", 'receipt.eml', 'message/rfc822', 'email');
        $upload = $intake->store('Parking at the venue, £12.00', 'parking.txt', 'text/plain', 'upload');

        $actor = fn ($doc) => AuditEvent::query()->where('action', 'document.received')->where('subject_type', 'Document')->where('subject_id', $doc->id)->sole()->actor;
        $this->assertSame(['system', 'system', 'you'], [$actor($gmail), $actor($mailbox), $actor($upload)]);
    }

    public function test_a_scheduled_starling_import_is_the_systems_and_one_you_start_is_yours(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $csv = file_get_contents(base_path('tests/fixtures/starling.csv'));
        Http::fake(function (Request $r) use (&$csv) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/v2/accounts')) {
                return Http::response(['accounts' => [['accountUid' => 'uid-1', 'name' => 'Business', 'currency' => 'GBP', 'defaultCategory' => 'cat-1']]]);
            }

            return str_contains($r->url(), '/statement/downloadForDateRange') ? Http::response($csv, 200, ['Content-Type' => 'text/csv']) : Http::response('', 404);
        });
        $this->post('/settings/starling', ['token' => 'good-token-1234567890abcdef'])->assertSessionHas('success');

        collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'starling-import')->run(app());
        $this->assertSame('system', AuditEvent::query()->where('action', 'import.committed')->sole()->actor);

        $csv = file_get_contents(base_path('tests/fixtures/starling_broken.csv'));
        $this->post('/settings/starling/sync', ['from' => '2025-04-01'])->assertSessionHas('success');
        $this->assertSame('you', AuditEvent::query()->where('action', 'import.quarantined')->sole()->actor, 'You asked for this import');
        $this->assertSame('you', AuditContext::actor());
    }

    public function test_the_job_finder_and_gathering_record_as_the_matcher_whoever_starts_them(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);
        $taxi = $this->tx($this->starling, '2025-05-14', -2350, 'UBER *TRIP', 'UBER');
        app(AllocationWriter::class)->decide($taxi, AllocationWriter::lines(-2350, 'personal'), 'matcher', false, 'Looks like a taxi, with no job that day: personal');
        // What the context says each time an event is written (an actor the code doesn't name comes from it).
        $actors = [];
        AuditEvent::creating(function () use (&$actors) {
            $actors[] = AuditContext::actor();
        });

        app(JobFinder::class)->run(null, false);
        app(JobGatherer::class)->gather($job, false);

        $this->assertGreaterThanOrEqual(2, count($actors), 'The run, and the taxi taken back');
        $this->assertSame(['matcher'], array_values(array_unique($actors)));
        $this->assertSame('you', AuditContext::actor(), 'Yours again afterwards');
    }

    public function test_a_check_of_a_line_reads_in_plain_words_in_its_history(): void
    {
        $this->actingAs(User::factory()->create());
        $tx = $this->tx($this->starling, '2025-07-31', -50000, 'HMRC SELF ASSESSMENT', 'HMRC');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');
        app(Verifier::class)->transaction($tx);

        $check = collect($this->getJson("/items/bank/{$tx->id}")->assertOk()->json('history'))->firstWhere('action', 'verification.verified');
        $this->assertSame(['checker', 'Checked against the source', 'All 1 checked against the payment and your jobs.'], [$check['actor'], $check['label'], $check['reason']]);
    }

    // ---- learning leaves the other matchers' suggestions alone ---------------------------------------------------

    public function test_learning_from_your_decision_leaves_an_hmrc_suggestion_alone(): void
    {
        $this->actingAs(User::factory()->create());
        $waiting = $this->tx($this->starling, '2025-07-31', -50000, 'HMRC SELF ASSESSMENT', 'HMRC');
        app(AllocationWriter::class)->decide($waiting, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');
        $before = $waiting->fresh()->allocations()->get(['kind', 'reason', 'decided_by', 'matcher'])->toArray();

        // You decide another payment to the same payee differently (an HMRC payment for somebody else, say).
        $other = $this->tx($this->starling, '2025-06-30', -12000, 'HMRC SELF ASSESSMENT', 'HMRC');
        $this->post("/items/bank/{$other->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();
        $this->assertSame(0, app(Categoriser::class)->learnFrom(collect([$other->fresh()])));

        $this->assertSame($before, $waiting->fresh()->allocations()->get(['kind', 'reason', 'decided_by', 'matcher'])->toArray());
        $this->assertSame(1, AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $waiting->id)->count(), 'Nothing new in its history');
    }
}
