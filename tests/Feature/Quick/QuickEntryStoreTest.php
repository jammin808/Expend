<?php

namespace Tests\Feature\Quick;

use App\Domain\Quick\QuickEntries;
use App\Domain\Todo\Fingerprints;
use App\Jobs\ReadDocument;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Taking in an entry from the phone: what each way of paying writes, the claim on the client key (a repeat never adds
 * twice), Undo by key (a tombstone for a key not seen yet), the exchange rate kept outside any transaction (a failure
 * holds the entry, never a 500), the photo checked against what the fields said, and what's held rather than refused.
 */
class QuickEntryStoreTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    // ---- what each way of paying writes -------------------------------------------------------------------------------

    public function test_cash_with_its_photo_adds_one_expense_decided_as_yours_with_its_receipt(): void
    {
        Queue::fake();
        Setting::put('ai_extraction', 'on');
        Setting::put('anthropic_api_key', 'test-key');
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        [$file, $photo] = $this->photo();
        $f = $this->fields(['work_job_id' => $job->id, 'job_source' => 'today', 'business_pct' => 80, 'photo' => $photo]);

        $this->send($f)->assertCreated()->assertJsonPath('entry.state', 'added')->assertJsonPath('entry.photo_wanted', true)
            ->assertJsonPath('entry.message', 'Added as a cash expense');
        $this->sendPhoto($f['client_key'], $file)->assertOk()->assertJsonPath('sha256', $photo['sha256']);

        $expense = ManualExpense::query()->sole();
        $this->assertSame(['cash', -1250, 'Parking'], [$expense->paid_with, $expense->amount_pence, $expense->description]);
        $lines = Allocation::query()->where('manual_expense_id', $expense->id)->orderByDesc('amount_pence')->get();
        $this->assertCount(2, $lines, '80% business: a business line and a personal one');
        $business = $lines->firstWhere('kind', 'business_expense');
        $this->assertSame([$this->cat('travel_parking'), $job->id, 80, 'you', true], [(int) $business->category_id, (int) $business->work_job_id, (int) $business->business_use_pct, $business->decided_by, $business->confirmed_at !== null]);
        $this->assertSame(-1250, (int) $lines->sum('amount_pence'));

        $entry = $this->entry($f['client_key']);
        $doc = Document::query()->sole();
        $this->assertSame([$expense->id, $doc->id, 'stored'], [$entry->manual_expense_id, $entry->document_id, $entry->photo_status]);
        $this->assertSame(['quick', 'receipt', $job->id, 'you'], [$doc->source, $doc->kind, (int) $doc->work_job_id, $doc->job_linked_by]);
        $this->assertTrue(AuditEvent::query()->where('action', 'quick_entry.received')->where('subject_id', $entry->id)->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'allocation.decided')->where('subject_type', 'ManualExpense')->where('subject_id', $expense->id)->exists());
        Queue::assertPushedOn(QuickEntries::READ_QUEUE, ReadDocument::class, fn (ReadDocument $j) => $j->documentId === $doc->id);
    }

    public function test_with_ai_reading_off_the_photo_is_kept_and_not_read(): void
    {
        Queue::fake();
        Setting::put('ai_extraction', 'off');
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();

        Queue::assertNotPushed(ReadDocument::class);
        $this->assertSame('receipt', Document::query()->sole()->kind);
    }

    public function test_personal_card_adds_an_expense_and_a_business_card_adds_nothing_until_its_line(): void
    {
        $this->send($this->fields(['paid_with' => 'personal_card']))->assertCreated()->assertJsonPath('entry.message', 'Added as a personal card expense');
        $this->assertSame('personal_card', ManualExpense::query()->sole()->paid_with);

        $card = $this->fields(['paid_with' => 'business', 'account_id' => $this->starling->id]);
        $this->send($card)->assertCreated()->assertJsonPath('entry.state', 'waiting')
            ->assertJsonPath('entry.message', 'Photo kept for the card payment. Nothing is added until the bank line comes in.');
        $this->assertSame(1, ManualExpense::query()->count(), 'No expense for the card');
        $this->assertSame(1, Allocation::query()->count(), 'No allocation for the card');
        $this->assertSame([$this->cat('travel_parking'), $this->starling->id], [(int) $this->entry($card['client_key'])->category_id, (int) $this->entry($card['client_key'])->account_id]);
    }

    // ---- the claim on the key ------------------------------------------------------------------------------------------

    public function test_the_same_key_sent_twice_adds_once_and_says_it_was_a_repeat(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();

        $this->send($f)->assertOk()->assertJsonPath('repeat', true)->assertJsonPath('entry.state', 'added')->assertJsonMissingPath('changed');
        $this->sendPhoto($f['client_key'], $file)->assertOk()->assertJsonPath('sha256', $photo['sha256']);

        $this->assertSame([1, 1, 1], [QuickEntry::query()->count(), ManualExpense::query()->count(), Document::query()->count()]);
    }

    public function test_a_repeat_while_the_first_is_still_arriving_waits_and_writes_nothing(): void
    {
        $f = $this->fields();
        QuickEntry::query()->create(['client_key' => $f['client_key'], 'status' => 'receiving', 'channel' => 'phone']);

        $this->send($f)->assertStatus(202)->assertJsonPath('state', 'receiving');

        $this->assertSame(['receiving', 0], [$this->entry($f['client_key'])->status, ManualExpense::query()->count()]);
    }

    public function test_a_claim_whose_request_died_is_taken_over_and_finished_once(): void
    {
        $f = $this->fields();
        QuickEntry::query()->create(['client_key' => $f['client_key'], 'status' => 'receiving', 'channel' => 'phone']);
        $this->travel(3)->minutes();

        $this->send($f)->assertCreated()->assertJsonPath('entry.state', 'added');
        $this->send($f)->assertOk()->assertJsonPath('repeat', true);

        $this->assertSame([1, 1], [QuickEntry::query()->count(), ManualExpense::query()->count()]);
    }

    public function test_the_same_key_with_other_details_changes_nothing_and_says_so(): void
    {
        $f = $this->fields(['payload_sha' => str_repeat('a', 64)]);
        $this->send($f)->assertCreated();

        $this->send(array_merge($f, ['amount' => '15.00', 'payload_sha' => str_repeat('b', 64)]))->assertOk()->assertJsonPath('changed', true)
            ->assertJsonPath('message', 'This was sent before your change, so nothing changed. Open it to change it.');

        $this->assertSame(-1250, ManualExpense::query()->sole()->amount_pence);
    }

    // ---- Undo by key ---------------------------------------------------------------------------------------------------

    public function test_a_withdraw_before_the_entry_leaves_a_tombstone_so_the_late_send_writes_nothing(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->postJson('/quick/entries/'.$f['client_key'].'/withdraw')->assertOk()->assertJsonPath('state', 'withdrawn');

        $this->send($f)->assertOk()->assertJsonPath('state', 'withdrawn');

        $this->assertSame([1, 'withdrawn', 0, 0], [QuickEntry::query()->count(), $this->entry($f['client_key'])->status, ManualExpense::query()->count(), Document::query()->count()]);
    }

    public function test_a_withdraw_while_the_entry_is_arriving_stops_it_before_anything_is_added(): void
    {
        $f = $this->fields();
        QuickEntry::created(function (QuickEntry $e) use ($f) {
            if ($e->client_key === $f['client_key'] && $e->status === 'receiving') {
                app(QuickEntries::class)->withdraw($f['client_key']); // Undo lands just after the claim
            }
        });

        $this->send($f)->assertOk()->assertJsonPath('state', 'withdrawn');

        $this->assertSame(['withdrawn', 0, 0], [$this->entry($f['client_key'])->status, ManualExpense::query()->count(), Allocation::query()->count()]);
    }

    public function test_undo_after_it_was_added_takes_the_expense_back(): void
    {
        $f = $this->fields();
        $this->send($f)->assertCreated();
        $id = ManualExpense::query()->sole()->id;

        $this->postJson('/quick/entries/'.$f['client_key'].'/withdraw')->assertOk()->assertJsonPath('state', 'withdrawn');
        $this->postJson('/quick/entries/'.$f['client_key'].'/withdraw')->assertOk()->assertJsonPath('state', 'withdrawn');

        $this->assertSame([0, 0], [ManualExpense::query()->count(), Allocation::query()->count()]);
        $gone = AuditEvent::query()->where('action', 'manual_expense.withdrawn')->where('subject_id', $id)->sole();
        $this->assertSame(-1250, $gone->before['amount_pence']);
        $this->assertSame('business_expense', $gone->before['lines'][0]['kind']);
    }

    // ---- exchange rates --------------------------------------------------------------------------------------------------

    public function test_hmrc_out_of_reach_holds_the_entry_for_a_rate_never_a_500(): void
    {
        Http::fake(['www.trade-tariff.service.gov.uk/*' => fn () => throw new ConnectionException('timed out')]);
        $f = $this->fields(['currency' => 'EUR', 'amount' => '15.00', 'what' => 'Taxi', 'what_source' => 'typed', 'category_id' => $this->cat('travel_taxi')]);

        $this->send($f)->assertCreated()->assertJsonPath('entry.state', 'held')->assertJsonPath('entry.hold_reason', 'needs_rate');

        $this->assertSame(0, ManualExpense::query()->count());
        $this->assertArrayHasKey('quick.held', $this->findings());
    }

    public function test_no_hmrc_rate_for_the_currency_holds_it_too(): void
    {
        Http::fake(['www.trade-tariff.service.gov.uk/*' => Http::response("Country/Territories,Currency,Currency Code,Currency Units per £1,Start date,End date\nUSA,Dollar,USD,1.27,01/11/2026,30/11/2026\n")]);
        $f = $this->fields(['currency' => 'EUR', 'amount' => '15.00', 'category_id' => $this->cat('travel_taxi')]);

        $this->send($f)->assertCreated()->assertJsonPath('entry.hold_reason', 'needs_rate');
        $this->assertSame(0, ManualExpense::query()->count());
    }

    public function test_a_euro_cash_entry_converts_at_hmrcs_rate_or_the_sterling_on_the_receipt(): void
    {
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'source' => 'hmrc_monthly', 'units_per_gbp' => '1.2']);
        $this->send($this->fields(['currency' => 'EUR', 'amount' => '15.00', 'category_id' => $this->cat('travel_taxi')]))->assertCreated();
        $e = ManualExpense::query()->sole();
        $this->assertSame([-1250, -1500, 'EUR', 'hmrc_monthly'], [$e->amount_pence, $e->original_amount_minor, $e->original_currency, $e->fx_source]);

        $this->send($this->fields(['currency' => 'EUR', 'amount' => '20.00', 'sterling' => '17.10', 'category_id' => $this->cat('travel_taxi')]))->assertCreated();
        $d = ManualExpense::query()->where('original_amount_minor', -2000)->sole();
        $this->assertSame([-1710, 'document'], [$d->amount_pence, $d->fx_source]);
    }

    // ---- the photo -------------------------------------------------------------------------------------------------------

    public function test_a_photo_that_isnt_what_the_fields_said_is_refused_and_nothing_is_stored(): void
    {
        [, $photo] = $this->photo('a.jpg');
        [$other] = $this->photo('b.jpg', 50, 50);
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();

        $this->sendPhoto($f['client_key'], $other)->assertStatus(422)->assertJsonPath('error', 'photo_mismatch');

        $this->assertSame(0, Document::query()->count());
        $this->assertSame('expected', $this->entry($f['client_key'])->photo_status);
    }

    public function test_a_photo_over_the_servers_limit_is_413_and_one_for_an_unknown_key_is_404(): void
    {
        [, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $tmp = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($tmp, 'x');
        $tooBig = new UploadedFile($tmp, 'big.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->sendPhoto($f['client_key'], $tooBig)->assertStatus(413)->assertJsonPath('error', 'too_big');
        [$file] = $this->photo();
        $this->sendPhoto($this->key(), $file)->assertNotFound();
    }

    public function test_a_disk_that_refuses_the_write_gives_a_500_and_no_document_pointing_at_nothing(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        $this->sendPhoto($f['client_key'], $file)->assertStatus(500)->assertJsonPath('error', 'store_failed');

        $this->assertSame(0, Document::query()->count());
        $this->assertNull($this->entry($f['client_key'])->document_id);
    }

    public function test_the_same_photo_as_a_new_entry_is_a_duplicate_with_nothing_else_written(): void
    {
        [$file, $photo] = $this->photo();
        $first = $this->fields(['photo' => $photo]);
        $this->send($first)->assertCreated();
        $this->sendPhoto($first['client_key'], $file)->assertOk();

        $second = $this->fields(['photo' => $photo, 'amount' => '12.50']);
        $this->send($second)->assertCreated()->assertJsonPath('entry.state', 'duplicate')->assertJsonPath('entry.photo_wanted', false)
            ->assertJsonPath('entry.message', 'Not added: the same photo as £12.50 parking, added on 25 Nov at 14:05');

        $this->assertSame($this->entry($first['client_key'])->id, $this->entry($second['client_key'])->duplicate_of_id);
        $this->assertSame([1, 1], [ManualExpense::query()->count(), Document::query()->count()]);
    }

    public function test_a_photo_the_gmail_scan_kept_takes_your_job_and_is_checked_without_reading_again(): void
    {
        Queue::fake();
        Setting::put('ai_extraction', 'on');
        Setting::put('anthropic_api_key', 'test-key');
        [$file, $photo] = $this->photo();
        $bytes = (string) file_get_contents($file->getRealPath());
        Storage::disk('local')->put('documents/'.$photo['sha256'].'.jpg', $bytes);
        $kept = Document::query()->create(['source' => 'gmail', 'original_name' => 'receipt.jpg', 'mime' => 'image/jpeg', 'size' => strlen($bytes), 'sha256' => $photo['sha256'],
            'stored_path' => 'documents/'.$photo['sha256'].'.jpg', 'kind' => 'other', 'status' => 'extracted', 'extracted_at' => now(),
            'extraction' => ['receipt' => ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1250, 'currency' => 'GBP']]]);
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        $f = $this->fields(['photo' => $photo, 'work_job_id' => $job->id]);

        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk()->assertJsonPath('document_id', $kept->id);

        $kept->refresh();
        $this->assertSame([$job->id, 'you', 'receipt'], [(int) $kept->work_job_id, $kept->job_linked_by, $kept->kind]);
        $this->assertTrue(AuditEvent::query()->where('action', 'document.job_linked')->where('subject_id', $kept->id)->exists());
        Queue::assertNotPushed(ReadDocument::class);
        $entry = $this->entry($f['client_key']);
        $this->assertSame('agrees', $entry->read_check['status']);
        $this->assertSame(['NCP Leeds', 'photo'], [$entry->what, $entry->what_source], 'A chip’s What takes the name read from the photo');
        $this->assertSame('NCP Leeds', ManualExpense::query()->sole()->description);
    }

    // ---- malformed against held ------------------------------------------------------------------------------------------

    public function test_only_a_malformed_request_is_refused_and_a_category_gone_since_is_held(): void
    {
        $this->send(array_diff_key($this->fields(), ['client_key' => 1]))->assertStatus(422)->assertJsonPath('error', 'malformed');
        $this->send($this->fields(['amount' => '12,50']))->assertStatus(422);
        $this->send($this->fields(['amount' => null, 'photo' => null]))->assertStatus(422);
        $this->send($this->fields(['currency' => 'POUNDS']))->assertStatus(422);
        $this->send($this->fields(['spent_on' => 'yesterday']))->assertStatus(422);
        $this->assertSame(0, QuickEntry::query()->count());

        $f = $this->fields(['category_id' => 999999]);
        $this->send($f)->assertCreated()->assertJsonPath('entry.state', 'held')->assertJsonPath('entry.hold_reason', 'needs_details');
        $this->assertSame(0, ManualExpense::query()->count());
        $this->assertArrayHasKey('quick.held', $this->findings());
    }

    // ---- locked years ----------------------------------------------------------------------------------------------------

    public function test_a_cash_entry_in_a_locked_year_is_kept_not_added_until_you_unlock_it(): void
    {
        TaxYear::track('2025-26')->update(['locked_at' => now()]);
        [$file, $photo] = $this->photo();
        $f = $this->fields(['spent_on' => '2026-03-20', 'photo' => $photo]);

        $this->send($f)->assertCreated()->assertJsonPath('entry.hold_reason', 'locked_year')->assertJsonPath('entry.message', 'Kept, not added: 2025-26 is locked');
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $this->assertSame([0, 0, 1], [ManualExpense::query()->count(), Allocation::query()->count(), Document::query()->count()]);

        $entry = $this->entry($f['client_key']);
        $this->postJson('/todo/qx/'.$entry->id.':add_held', ['fp' => Fingerprints::of('qx', $entry->id.':add_held')]);
        $this->assertSame(0, ManualExpense::query()->count(), 'Refused while locked');

        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => null]);
        $msg = app(QuickEntries::class)->act($entry->id.':add_held');
        $this->assertStringStartsWith('Added', $msg);
        try {
            app(QuickEntries::class)->act($entry->id.':add_held');
        } catch (ModelNotFoundException) {
            // added once: a second press is already dealt with
        }
        $this->assertSame(1, ManualExpense::query()->count());
    }
}
