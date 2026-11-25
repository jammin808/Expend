<?php

namespace Tests\Feature\Schema;

use App\Models\Allocation;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\InvoiceLine;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Build slice S1 (the client-area design, with income v3's schema): the two additive migrations, on SQLite here and on
 * MariaDB 10.11 when the suite runs there.
 *  - every new column is appended to its table and the new tables are there, with no new foreign keys;
 *  - migrate:rollback takes both off again (the unique (source_ref, invoice_id) index drops on MariaDB, because
 *    invoice_lines' foreign key keeps its own index), and migrating again works with rows already in the tables;
 *  - rows written by the release before (no new columns) read back as today: lines ticked, typed (source null) and
 *    with none of your edits; invoices not closed, replaced or synced; clients not merged; jobs with no end client;
 *  - the new models read and check their rows.
 *
 * On MariaDB a migration commits the test's transaction (DDL), so the next test migrates afresh
 * (RefreshDatabase sees it): nothing written here is left for other tests.
 */
class ClientAreaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_12_21_000001_income_assignment.php',
        'database/migrations/2026_12_21_000002_client_area_and_invoices.php',
    ];

    /** @var array<string, list<string>> the columns each migration appends, in order */
    private const APPENDED = [
        'allocations' => ['client_id'],
        'clients' => ['invoice_name', 'billing_email', 'billing_address', 'payment_terms_days', 'invoice_route', 'merged_into_id'],
        'work_jobs' => ['end_client_id', 'rate_pence', 'rate_unit', 'days_agreed', 'done_at', 'cancel_charge', 'cancel_fee_pence'],
        // income v3's five (closed_linked_minor added after stage 1's checks), then this design's two
        'invoices' => ['closed_minor', 'closed_reason', 'closed_at', 'replaced_by_id', 'closed_linked_minor', 'lines_seen_event_id', 'synced_at'],
        'invoice_lines' => ['source', 'source_ref', 'source_job_id', 'included', 'ticked_by', 'own_text', 'own_amount', 'source_amount_minor', 'source_quantity', 'flag'],
    ];

    private const TABLES = [
        'charge_terms' => ['id', 'owner_key', 'client_id', 'work_job_id', 'class', 'charge', 'rate_minor', 'currency', 'created_at', 'updated_at'],
        'cost_charges' => ['id', 'cost_ref', 'subject_ref', 'charge', 'client_id', 'invoice_id', 'note', 'pair', 'pair_ref', 'outcome', 'outcome_at', 'audit_event_id', 'created_at', 'updated_at'],
        'invoice_emails' => ['id', 'invoice_id', 'kind', 'stage', 'route', 'gmail_thread_id', 'gmail_draft_id', 'draft_message_id', 'current_message_id', 'gmail_message_id',
            'rfc_message_id', 'to_address', 'subject', 'status', 'drafted_at', 'sent_at', 'checked_at', 'error', 'created_at', 'updated_at'],
    ];

    public function test_new_columns_are_appended_and_the_new_tables_have_no_foreign_keys(): void
    {
        foreach (self::APPENDED as $table => $columns) {
            $all = Schema::getColumnListing($table);
            $this->assertSame($columns, array_slice($all, -count($columns)), "{$table}: appended at the end, in order");
        }
        foreach (self::TABLES as $table => $columns) {
            $this->assertSame($columns, Schema::getColumnListing($table), $table);
            $this->assertSame([], Schema::getForeignKeys($table), "{$table}: no foreign keys");
        }

        // The only foreign keys are the ones there before.
        $this->assertSame([['invoice_id']], array_column(Schema::getForeignKeys('invoice_lines'), 'columns'));
        $this->assertSame([], Schema::getForeignKeys('clients'));
        $this->assertSame([['client_id']], array_column(Schema::getForeignKeys('work_jobs'), 'columns'));

        $this->assertTrue($this->hasIndex('invoice_lines', ['source_ref', 'invoice_id'], unique: true), 'unique (source_ref, invoice_id): not led by invoice_id');
        $this->assertTrue($this->hasIndex('invoice_lines', ['source_job_id']));
        $this->assertTrue($this->hasIndex('charge_terms', ['owner_key', 'class'], unique: true));
        $this->assertTrue($this->hasIndex('cost_charges', ['cost_ref'], unique: true));
        $this->assertTrue($this->hasIndex('cost_charges', ['subject_ref']));
        $this->assertTrue($this->hasIndex('invoice_emails', ['invoice_id']));
        $this->assertTrue($this->hasIndex('allocations', ['client_id']));
        $this->assertTrue($this->hasIndex('invoices', ['replaced_by_id']));
        $this->assertTrue($this->hasIndex('clients', ['merged_into_id']));
        $this->assertTrue($this->hasIndex('work_jobs', ['end_client_id']));
    }

    public function test_rollback_then_migrate_again_and_rows_from_before_read_as_today(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATIONS]), Artisan::output());
        $this->assertSchemaRolledBack();

        // What the release before writes: none of the new columns.
        $now = now()->format('Y-m-d H:i:s');
        $clientId = DB::table('clients')->insertGetId(['name' => 'Acme Events', 'notes' => null, 'created_at' => $now, 'updated_at' => $now]);
        $jobId = DB::table('work_jobs')->insertGetId(['client_id' => $clientId, 'title' => 'Arena load-in', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03',
            'fee_pence' => 65000, 'status' => 'done', 'created_at' => $now, 'updated_at' => $now]);
        $issuedId = DB::table('invoices')->insertGetId(['number' => 'INV-0041', 'client_id' => $clientId, 'work_job_id' => $jobId, 'status' => 'issued', 'currency' => 'GBP',
            'bill_to_name' => 'Acme Events', 'total_minor' => 69500, 'issue_date' => '2026-06-05', 'due_date' => '2026-07-05', 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $draftId = DB::table('invoices')->insertGetId(['client_id' => $clientId, 'work_job_id' => $jobId, 'status' => 'draft', 'currency' => 'GBP',
            'bill_to_name' => 'Acme Events', 'total_minor' => 65000, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$issuedId, 'Arena load-in (3 Jun 2026)', 65000, 0], [$issuedId, 'Taxi', 4500, 1], [$draftId, 'Arena load-in (3 Jun 2026)', 65000, 0]] as [$invoiceId, $description, $amount, $sort]) {
            DB::table('invoice_lines')->insert(['invoice_id' => $invoiceId, 'description' => $description, 'quantity' => 1, 'unit_price_minor' => $amount, 'amount_minor' => $amount,
                'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
        }
        $allocationId = DB::table('allocations')->insertGetId(['tax_year' => '2026-27', 'kind' => 'business_income', 'work_job_id' => $jobId, 'invoice_id' => $issuedId,
            'amount_pence' => 69500, 'decided_by' => 'you', 'created_at' => $now, 'updated_at' => $now]);

        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATIONS]), Artisan::output());

        // Lines read back ticked, typed and with nothing of yours changed.
        $this->assertSame([1, 1, 1], DB::table('invoice_lines')->orderBy('id')->pluck('included')->map(fn ($v) => (int) $v)->all());
        foreach (InvoiceLine::query()->get() as $line) {
            $this->assertSame([true, null, null, null, null, false, false, null, null, null],
                [$line->included, $line->source, $line->source_ref, $line->source_job_id, $line->ticked_by, $line->own_text, $line->own_amount, $line->source_amount_minor, $line->source_quantity, $line->flag]);
        }
        $issued = Invoice::query()->findOrFail($issuedId);
        $this->assertSame(['Arena load-in (3 Jun 2026)', 'Taxi'], $issued->lines->pluck('description')->all(), 'Every line from before is charged');
        $this->assertSame(2, $issued->allLines()->count());
        $this->assertSame(69500, (int) $issued->lines()->sum('amount_minor'));
        $this->assertSame([null, null, null, null, null, null, null],
            [$issued->closed_minor, $issued->closed_reason, $issued->closed_at, $issued->replaced_by_id, $issued->closed_linked_minor, $issued->lines_seen_event_id, $issued->synced_at]);
        $this->assertSame(1, Invoice::query()->findOrFail($draftId)->lines()->count());

        $client = Client::query()->findOrFail($clientId);
        $this->assertSame([null, null, null, null, null, null], [$client->invoice_name, $client->billing_email, $client->billing_address, $client->payment_terms_days, $client->invoice_route, $client->merged_into_id]);
        $this->assertSame('thread', $client->invoiceRoute());
        $this->assertSame([$clientId], Client::query()->unmerged()->pluck('id')->all());
        $job = WorkJob::query()->findOrFail($jobId);
        $this->assertSame([null, null, null, null, null, null, null], [$job->end_client_id, $job->rate_pence, $job->rate_unit, $job->days_agreed, $job->done_at, $job->cancel_charge, $job->cancel_fee_pence]);
        $this->assertNull(Allocation::query()->findOrFail($allocationId)->client_id);

        // And back again with the rows there, then up again for the tests that follow.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATIONS]), Artisan::output());
        $this->assertSchemaRolledBack();
        $this->assertSame(3, DB::table('invoice_lines')->count(), 'Rolling back loses no line');
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATIONS]), Artisan::output());
        $this->assertTrue(Schema::hasColumns('invoice_lines', self::APPENDED['invoice_lines']));
    }

    public function test_the_new_models_read_their_rows_and_relations(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events', 'invoice_name' => 'Acme Events Ltd', 'billing_email' => 'accounts@example.test',
            'billing_address' => "1 High Street\nLeeds", 'payment_terms_days' => '14', 'invoice_route' => 'new']);
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD', 'merged_into_id' => $acme->id]);
        $bigProd = Client::query()->create(['name' => 'Big Prod Co']);
        $this->assertSame(14, $acme->fresh()->payment_terms_days);
        $this->assertSame('new', $acme->invoiceRoute());
        $this->assertSame($acme->id, $dupe->mergedInto->id);
        $this->assertTrue($dupe->isMerged());
        $this->assertSame([$dupe->id], $acme->merged()->pluck('id')->all());
        $this->assertSame([$acme->id, $bigProd->id], Client::query()->unmerged()->orderBy('id')->pluck('id')->all(), 'Merged clients are hidden');

        $job = WorkJob::query()->create(['client_id' => $acme->id, 'end_client_id' => $bigProd->id, 'title' => 'Arena load-in', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-05',
            'rate_pence' => '45000', 'rate_unit' => 'day', 'days_agreed' => '3', 'done_at' => '2026-06-05 18:00:00', 'cancel_charge' => 'fee', 'cancel_fee_pence' => '20000'])->fresh();
        $this->assertSame([$bigProd->id, 45000, 'day', 3, '2026-06-05 18:00', 'fee', 20000],
            [$job->endClient->id, $job->rate_pence, $job->rate_unit, $job->days_agreed, $job->done_at->format('Y-m-d H:i'), $job->cancel_charge, $job->cancel_fee_pence]);
        $this->assertSame([$job->id], $bigProd->endClientJobs()->pluck('id')->all());
        $this->assertSame([], $bigProd->jobs()->pluck('id')->all(), 'The end client pays for nothing');
        $same = WorkJob::query()->create(['client_id' => $acme->id, 'end_client_id' => $acme->id, 'title' => 'Office party', 'starts_on' => '2026-06-10', 'ends_on' => '2026-06-10']);
        $this->assertNull($same->fresh()->end_client_id, 'The paying client is stored as no end client');

        $line = Allocation::query()->create(['tax_year' => '2026-27', 'kind' => 'business_income', 'amount_pence' => 30000, 'client_id' => $acme->id]);
        $this->assertSame($acme->id, $line->fresh()->client->id);

        $usual = ChargeTerm::query()->create(['client_id' => $acme->id, 'class' => 'mileage', 'charge' => 'per_mile', 'rate_minor' => 45]);
        $override = ChargeTerm::query()->create(['owner_key' => 'job:'.$job->id, 'work_job_id' => $job->id, 'class' => 'hotel', 'charge' => 'none']);
        $this->assertSame('client:'.$acme->id, $usual->owner_key, 'Filled from its owner');
        $this->assertSame([$usual->id], $acme->chargeTerms()->pluck('id')->all());
        $this->assertSame([$override->id], $job->chargeTerms()->pluck('id')->all());
        $this->assertSame([$override->id], ChargeTerm::query()->ownedBy($job)->pluck('id')->all());
        $this->assertSame('GBP', $usual->fresh()->currency);

        $invoice = Invoice::query()->create(['status' => 'draft', 'client_id' => $acme->id, 'work_job_id' => $job->id, 'bill_to_name' => 'Acme Events Ltd']);
        $charge = CostCharge::query()->create(['cost_ref' => 'tx:381#j'.$job->id, 'subject_ref' => 'tx:381', 'charge' => 'invoiced', 'invoice_id' => $invoice->id, 'client_id' => $acme->id]);
        $this->assertSame([$invoice->id, $acme->id], [$charge->invoice->id, $charge->client->id]);
        CostCharge::query()->create(['cost_ref' => 'travel:7', 'subject_ref' => 'travel:7', 'pair' => 'same', 'pair_ref' => 'tx:381#j'.$job->id]);

        $email = InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'drafted', 'gmail_draft_id' => 'r-123', 'drafted_at' => now()]);
        InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'reminder', 'stage' => 3, 'route' => 'gmail', 'status' => 'sent']);
        $this->assertSame([$email->id], $invoice->emails()->open()->pluck('id')->all());
        $this->assertSame($invoice->id, $email->invoice->id);
    }

    public function test_charge_arrangements_cost_decisions_and_email_rows_refuse_what_cannot_be(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['client_id' => $acme->id, 'title' => 'Arena load-in', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03']);

        foreach ([
            'neither owner' => [['class' => 'rail', 'charge' => 'at_cost'], 'one client or one job'],
            'both owners' => [['client_id' => $acme->id, 'work_job_id' => $job->id, 'class' => 'rail', 'charge' => 'at_cost'], 'one client or one job'],
            'the wrong key' => [['owner_key' => 'job:'.$acme->id, 'client_id' => $acme->id, 'class' => 'rail', 'charge' => 'at_cost'], "can't be filed"],
            'an unknown kind' => [['client_id' => $acme->id, 'class' => 'boats', 'charge' => 'at_cost'], "isn't a kind of cost"],
            'fines on their form' => [['client_id' => $acme->id, 'class' => 'fines', 'charge' => 'their_form'], "can't be charged"],
            'hotels per day' => [['client_id' => $acme->id, 'class' => 'hotel', 'charge' => 'per_day', 'rate_minor' => 1000], "can't be charged"],
            'per mile, no rate' => [['client_id' => $acme->id, 'class' => 'mileage', 'charge' => 'per_mile'], 'needs a rate'],
            'their form for driving, no rate' => [['client_id' => $acme->id, 'class' => 'mileage', 'charge' => 'their_form'], 'needs a rate'],
            'a travel fee of 0' => [['work_job_id' => $job->id, 'class' => 'travel_fee', 'charge' => 'per_job', 'rate_minor' => 0], 'needs a rate'],
        ] as $case => [$row, $says]) {
            $this->assertRefused(fn () => ChargeTerm::query()->create($row), InvalidArgumentException::class, $says, $case);
        }
        // Allowed as they are: at cost and their form need no rate, travel days at the job's day rate neither.
        ChargeTerm::query()->create(['client_id' => $acme->id, 'class' => 'rail', 'charge' => 'their_form']);
        ChargeTerm::query()->create(['client_id' => $acme->id, 'class' => 'travel_days', 'charge' => 'day_rate']);
        ChargeTerm::query()->create(['work_job_id' => $job->id, 'class' => 'meals', 'charge' => 'per_day', 'rate_minor' => 2500]);
        $this->assertSame(3, ChargeTerm::query()->count());

        foreach ([
            'an unknown charge' => [['cost_ref' => 'tx:1#j1', 'subject_ref' => 'tx:1', 'charge' => 'free'], "charge can't be"],
            'an unknown pairing' => [['cost_ref' => 'tx:1#j1', 'subject_ref' => 'tx:1', 'pair' => 'twin', 'pair_ref' => 'travel:2'], "pair can't be"],
            'a pairing with no other cost' => [['cost_ref' => 'tx:1#j1', 'subject_ref' => 'tx:1', 'pair' => 'same'], 'names the other cost'],
            'invoiced on no invoice' => [['cost_ref' => 'tx:1#j1', 'subject_ref' => 'tx:1', 'charge' => 'invoiced'], 'which invoice'],
            'an unknown outcome' => [['cost_ref' => 'tx:1#j1', 'subject_ref' => 'tx:1', 'charge' => 'elsewhere', 'outcome' => 'maybe'], "outcome can't be"],
        ] as $case => [$row, $says]) {
            $this->assertRefused(fn () => CostCharge::query()->create($row), InvalidArgumentException::class, $says, $case);
        }

        $invoice = Invoice::query()->create(['status' => 'draft', 'client_id' => $acme->id]);
        $this->assertRefused(fn () => InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'fax', 'status' => 'sent']), LogicException::class, "route can't be", 'route');
        $open = InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'drafted']);
        $this->assertRefused(fn () => InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'drafted']),
            LogicException::class, 'already has an open invoice draft', 'a second open draft');
        InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'reminder', 'stage' => 3, 'route' => 'gmail', 'status' => 'drafted']);
        $open->update(['status' => 'replaced']);
        $open->update(['error' => null, 'checked_at' => now()]);
        $next = InvoiceEmail::query()->create(['invoice_id' => $invoice->id, 'kind' => 'invoice', 'route' => 'gmail', 'status' => 'drafted']);
        $next->update(['checked_at' => now()]); // saving the open row itself again is fine
        $this->assertSame(2, InvoiceEmail::query()->open()->count());
    }

    private function assertSchemaRolledBack(): void
    {
        foreach (self::APPENDED as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} dropped");
            }
        }
        foreach (array_keys(self::TABLES) as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} dropped");
        }
        $this->assertFalse($this->hasIndex('invoice_lines', ['source_ref', 'invoice_id'], unique: true));
        $this->assertSame([['invoice_id']], array_column(Schema::getForeignKeys('invoice_lines'), 'columns'), 'invoice_lines keeps its foreign key');
    }

    /** @param list<string> $columns */
    private function hasIndex(string $table, array $columns, bool $unique = false): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns && (! $unique || $index['unique'])) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string<\Throwable> $class */
    private function assertRefused(callable $write, string $class, string $says, string $case): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $case);
            $this->assertStringContainsString($says, $e->getMessage(), $case);

            return;
        }
        $this->fail("Not refused: {$case}");
    }
}
