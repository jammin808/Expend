<?php

namespace Tests\Feature\Income;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\YearChecks;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Build slice F1 (income v3's phone screens), the parts the server gives them: every page knows which years are locked
 * (so the Inbox, the drawer and Transactions say so before you press anything there), the year page's cash-basis note
 * and its turnover warning for years before 2024-25, the screens' own line rules agreeing with the ledger writer's, the
 * client picker's matching, and the demo data the screens are checked with.
 */
class IncomeScreensTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    public function test_every_page_shares_which_years_are_locked(): void
    {
        $this->signIn();
        TaxYear::track('2025-26');
        TaxYear::track('2024-25')->forceFill(['locked_at' => now()])->save();

        $this->get('/inbox')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('nav.years', function ($years) {
            $this->assertSame([['label' => '2024-25', 'status' => 'outstanding', 'locked' => true], ['label' => '2025-26', 'status' => 'outstanding', 'locked' => false]],
                collect($years)->map(fn ($y) => (array) $y)->all());

            return true;
        }));
    }

    /**
     * Accept on an Inbox card or the drawer's "Accept suggestion" accepts only the suggestion it showed (shown: its
     * lines' ids). Replaced since by an automatic one, nothing is confirmed and the card is told it changed; pressed on
     * the suggestion as it is, it's accepted.
     */
    public function test_accept_confirms_only_the_suggestion_the_card_showed(): void
    {
        $this->signIn();
        $acme = $this->client();
        $inv = $this->invoice('INV-0002', 176000, null, $acme);
        $tx = $this->tx(176000, 'ACME EVENTS LTD');
        $this->decide($tx, [$this->income(176000, ['invoice_id' => $inv->id])], 'matcher');
        $card = $this->getJson("/items/bank/{$tx->id}")->assertOk()->json('item.allocations');
        $this->assertSame($inv->id, $card[0]['invoice_id']);

        // Since the card was shown, automation replaced the suggestion: Acme's money, no invoice.
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income(176000, ['client_id' => $acme->id])], 'matcher', false, 'Acme Events (from your payments)',
            supersede: true));

        $this->visit('POST', "/items/bank/{$tx->id}/confirm", ['quiet' => true, 'shown' => array_column($card, 'id')])
            ->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $tx->id)->whereNotNull('confirmed_at')->count());

        // The card as it is now: accepted.
        $now = $this->getJson("/items/bank/{$tx->id}")->assertOk()->json('item.allocations');
        $this->visit('POST', "/items/bank/{$tx->id}/confirm", ['quiet' => true, 'shown' => array_column($now, 'id')])->assertSessionMissing('error');
        $this->assertSame([['business_income', null, null, $acme->id, 176000, true]], $this->stored($tx));
    }

    public function test_the_year_page_says_how_the_cash_basis_applied_before_2024_25(): void
    {
        $this->signIn();
        $this->get('/years/2023-24')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cash_basis_note', fn ($note) => str_contains((string) $note, 'on your return (SA103)')
            && str_contains((string) $note, 'turnover of £150,000 or less') && str_contains((string) $note, 'cash basis for 2023-24')));
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cash_basis_note', null));
    }

    public function test_years_before_2024_25_warn_when_turnover_was_above_the_cash_basis_limit(): void
    {
        $check = function (string $label, int $turnover): ?array {
            $summary = ['turnover_pence' => $turnover] + ReturnSummary::for($label);

            return collect(YearChecks::checks($label, $summary))->firstWhere('key', 'cash_basis');
        };

        $warn = $check('2023-24', YearChecks::CASH_BASIS_LIMIT_PENCE + 1);
        $this->assertSame('warn', $warn['status']);
        $this->assertStringContainsString('For 2023-24 the cash basis was allowed only with turnover of £150,000 or less, and this year\'s is £150,000.01.', $warn['detail']);
        $this->assertNull($check('2023-24', YearChecks::CASH_BASIS_LIMIT_PENCE), 'at the limit it was allowed');
        $this->assertNull($check('2021-22', 9_000_000));
        $this->assertNull($check('2024-25', 40_000_000), 'from 2024-25 the cash basis is the default, with no limit');
    }

    public function test_the_screens_line_rules_agree_with_the_ledger_writer(): void
    {
        $turnover = $this->cat('turnover');
        $fee = $this->cat('finance');
        $taxi = $this->cat('travel_taxi');
        $in = fn (int $p, ?int $cat = null) => ['kind' => 'business_income', 'category_id' => $cat, 'work_job_id' => null, 'amount_pence' => $p];
        $cost = fn (int $p, ?int $cat = null) => ['kind' => 'business_expense', 'category_id' => $cat, 'work_job_id' => null, 'amount_pence' => $p];
        $personal = fn (int $p) => ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => $p];
        $cases = [
            'one line' => [97000, [$in(97000, $turnover)]],
            'a fee taken off' => [97000, [$in(100000, $turnover), $cost(-3000, $fee)]],
            'two invoices and a fee' => [97000, [$in(50000, $turnover), $in(50000, $turnover), $cost(-3000, $fee)]],
            'income and a personal part' => [97000, [$in(90000, $turnover), $personal(7000)]],
            'a refund of a cost' => [97000, [$cost(97000, $taxi)]],
            'a negative personal line on money in' => [97000, [$in(100000, $turnover), $personal(-3000)]],
            'no category' => [97000, [$in(97000)]],
            'short of the payment' => [97000, [$in(90000, $turnover)]],
            'a zero line' => [97000, [$in(0, $turnover), $in(97000, $turnover)]],
            'money out' => [-5000, [$cost(-5000, $taxi)]],
            'money out split with personal' => [-5000, [$cost(-2500, $taxi), $personal(-2500)]],
            'money in on money out' => [-5000, [$cost(-6000, $taxi), $personal(1000)]],
            'too much on money out' => [-5000, [$cost(-6000, $taxi)]],
        ];
        $screen = $this->node(<<<'JS'
            const { linesProblem } = income;
            out(Object.fromEntries(Object.entries(input).map(([name, [amount, lines]]) => [name, linesProblem(lines, amount)])));
            JS, $cases);

        $this->signIn();
        $this->tx(1); // the account and statement the payments below sit on
        foreach ($cases as $name => [$amount, $lines]) {
            DB::beginTransaction();
            try {
                app(AllocationWriter::class)->decide($this->tx($amount), $lines, 'you', true);
                $writer = null;
            } catch (InvalidArgumentException $e) {
                $writer = $e->getMessage();
            } finally {
                DB::rollBack();
            }
            $this->assertSame($writer === null, $screen[$name] === null, "{$name}: the screen says ".json_encode($screen[$name]).', the writer '.json_encode($writer));
        }
        $this->assertSame('£70.00 still to put on a line.', $screen['short of the payment']);
        $this->assertSame('£10.00 too much: lower a line.', $screen['too much on money out']);
        $this->assertSame('Line 1 needs an HMRC category.', $screen['no category']);
    }

    public function test_the_client_picker_matches_names_the_way_you_type_them(): void
    {
        $clients = [['id' => 1, 'name' => 'Acme Events Ltd'], ['id' => 2, 'name' => 'Northern Lights Productions'], ['id' => 3, 'name' => 'The Acme Group'],
            ['id' => 4, 'name' => 'Big Prod Co']];
        $found = $this->node(<<<'JS'
            const { matchClients, shortMoney } = income;
            out({
                acme: matchClients(input, 'acme').map((c) => c.id),
                ltd: matchClients(input, 'ACME events ltd').map((c) => c.id),
                words: matchClients(input, 'northern prod').map((c) => c.id),
                none: matchClients(input, 'xyz').map((c) => c.id),
                empty: matchClients(input, '  ').length,
                money: [shortMoney(60000), shortMoney(135050), shortMoney(-3000), shortMoney(120000, 'EUR')],
            });
            JS, $clients);

        $this->assertSame([1, 3], $found['acme'], 'names starting with it first, "The" and "Ltd" aside');
        $this->assertSame([1], $found['ltd']);
        $this->assertSame([2], $found['words']);
        $this->assertSame([], $found['none']);
        $this->assertSame(4, $found['empty']);
        $this->assertSame(['£600', '£1,350.50', '£30', '€1,200'], $found['money']);
    }

    public function test_the_demo_has_money_in_for_the_income_screens(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'demo@example.com')->firstOrFail());

        $waiting = BankTransaction::query()->where('merchant_key', 'STAGECRAFT CREW')->where('review_status', 'suggested')->orderBy('posted_on')->get();
        $this->assertCount(2, $waiting);
        $invoiceLine = Allocation::query()->where('bank_transaction_id', $waiting[0]->id)->sole();
        $this->assertNotNull($invoiceLine->invoice_id, 'the festival payment is suggested for its invoice');
        $this->assertNull($invoiceLine->confirmed_at, 'and only suggested');
        $clientLine = Allocation::query()->where('bank_transaction_id', $waiting[1]->id)->sole();
        $this->assertStringContainsString('(from your payments)', (string) $clientLine->reason);
        $this->assertNull($clientLine->confirmed_at);
        $this->assertSame(1, Invoice::query()->where('status', 'issued')->whereNotNull('sent_at')->where('total_minor', 95_000)->count(), 'the part-paid invoice');

        $this->get('/inbox?year=2025-26')->assertOk();
        $this->getJson('/items/bank/'.$waiting[1]->id)->assertOk()->assertJsonPath('income.client', 'Stagecraft Crew Agency')
            ->assertJsonPath('targets.0.label', 'Spring showcase rehearsal · 20 Feb · nothing billed');
    }

    /**
     * Runs $script in node with `income` (resources/js/lib/income.ts, bundled with its imports), `input` ($input) and
     * `out(value)`, and returns what it gave out.
     */
    private function node(string $script, mixed $input): mixed
    {
        $probe = new Process(['node', '-e', 'require.resolve("esbuild", { paths: [process.argv[1]] })', base_path()]);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('node and the installed node modules are needed to run the page code.');
        }
        $dir = sys_get_temp_dir().'/income-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents("$dir/input.json", json_encode($input));
        file_put_contents("$dir/run.mjs", <<<'JS'
            import fs from 'node:fs';
            import path from 'node:path';
            import { createRequire } from 'node:module';
            import { pathToFileURL } from 'node:url';

            const [, , root, dir] = process.argv;
            const { buildSync } = createRequire(path.join(root, 'package.json'))('esbuild');
            buildSync({ entryPoints: [path.join(root, 'resources/js/lib/income.ts')], bundle: true, format: 'esm', platform: 'node',
                outfile: path.join(dir, 'income.mjs'), tsconfig: path.join(root, 'tsconfig.json'), logLevel: 'silent' });
            const income = await import(pathToFileURL(path.join(dir, 'income.mjs')).href);
            const input = JSON.parse(fs.readFileSync(path.join(dir, 'input.json'), 'utf8'));
            const out = (value) => process.stdout.write(JSON.stringify(value));
            JS."\n".$script."\n");
        $run = new Process(['node', '--no-warnings', "$dir/run.mjs", base_path(), $dir], null, ['TZ' => 'Europe/London']);
        $run->run();
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }
}
