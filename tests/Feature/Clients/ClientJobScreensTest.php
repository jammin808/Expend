<?php

namespace Tests\Feature\Clients;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Invoice;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;
use Tests\Feature\Income\HttpFixtures;
use Tests\TestCase;

/**
 * Build slice F3 (the client and job screens): what the pages need from the server beyond S9's props. "Not yet" on a
 * job's "All done?" is tied to the job as shown; the jobs list shows one client's jobs (as payer or end client, merges
 * included) for "All 12" on their page; a client's page sends the decision pickers only when a cost's payment is opened;
 * "Use in job" reads a day or hourly rate; and the demo has a job at each stage the screens show.
 */
class ClientJobScreensTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_not_yet_on_a_jobs_page_is_tied_to_the_job_as_shown_and_asks_again_in_three_days(): void
    {
        $this->signIn();
        $job = $this->job('Arena load-in', $this->client(), '2026-06-08', ['status' => 'booked', 'fee_pence' => 65000]);
        $fp = null;
        $this->get("/jobs/{$job->id}")->assertInertia(function (AssertableInertia $p) use (&$fp) {
            $p->component('jobs/show', false)->where('stage.done_due', true)->where('not_yet_fp', fn ($v) => is_string($v) && strlen($v) === 6);
            $fp = $p->toArray()['props']['not_yet_fp'];
        });

        // The page's Not yet: the to-do list's job_not_yet, with the fingerprint it was shown.
        $this->visit('POST', "/todo/job_not_yet/{$job->id}", ['fp' => $fp], "/jobs/{$job->id}")
            ->assertSessionHas('success', 'OK, I’ll ask again on Sat 13 Jun. If it has more days, add them and I’ll ask after the last one.');
        $this->assertNotNull(TodoDismissal::query()->where('key', 'job_done:'.$job->id)->value('until'));
        $this->get("/jobs/{$job->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('stage.done_due', false));

        // Shown before it was marked done: refused, nothing changes.
        TodoDismissal::query()->delete();
        $this->visit('POST', "/jobs/{$job->id}/done", ['stay' => true], "/jobs/{$job->id}")->assertSessionHas('success');
        $this->visit('POST', "/todo/job_not_yet/{$job->id}", ['fp' => $fp], "/jobs/{$job->id}")->assertSessionHas('error');
        $this->assertSame(0, TodoDismissal::query()->count());
        $this->get("/jobs/{$job->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('not_yet_fp', null)->where('stage.stage', 'draft'));
    }

    public function test_the_jobs_list_shows_one_clients_jobs_as_payer_or_end_client_with_merged_clients(): void
    {
        $this->signIn();
        $acme = $this->client('Acme Events');
        $old = $this->client('ACME EVENTS LTD', ['merged_into_id' => $acme->id]);
        $agency = $this->client('Stagecraft Crew');
        $this->job('Gala', $acme, '2026-05-01');
        $this->job('Old gala', $old, '2026-04-01');
        $this->job('Festival', $agency, '2026-05-10', ['end_client_id' => $acme->id]);
        $this->job('Someone else’s', $agency, '2026-05-12');

        $titles = fn (string $uri) => collect(json_decode(json_encode($this->get($uri)->viewData('page')['props']), true)['jobs'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['Festival', 'Gala', 'Old gala'], $titles("/jobs?client={$acme->id}"));
        // A merged name opens the client it went into.
        $this->assertSame(['Festival', 'Gala', 'Old gala'], $titles("/jobs?client={$old->id}"));
        $this->get("/jobs?client={$old->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('client.name', 'Acme Events')->where('filters.client', $acme->id));
        $this->assertCount(4, $titles('/jobs'));
        $this->get('/jobs?client=abc')->assertInertia(fn (AssertableInertia $p) => $p->where('client', null)->has('jobs', 4));
    }

    public function test_a_clients_page_sends_the_pickers_only_when_asked_for(): void
    {
        $this->signIn();
        $acme = $this->client('Acme Events');
        $this->get("/clients/{$acme->id}")->assertInertia(fn (AssertableInertia $p) => $p->component('clients/show', false)->missing('lookups')->has('terms'));

        // Asked for ("Open the payment", "Merge into another client"): only the pickers come back.
        $props = $this->get("/clients/{$acme->id}", ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Partial-Component' => 'clients/show',
            'X-Inertia-Partial-Data' => 'lookups', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->assertOk()->json('props');
        $this->assertArrayNotHasKey('costs', $props);
        $this->assertNotEmpty($props['lookups']['categories']);
        $this->assertSame('Acme Events', $props['lookups']['clients'][0]['name']);
    }

    public function test_use_in_job_reads_a_day_or_hourly_rate_and_fills_the_days_agreed(): void
    {
        $read = $this->rates([
            ['field' => 'rate', 'label' => 'Day rate', 'value' => '£450'],
            ['field' => 'rate', 'label' => 'Rate', 'value' => '£35 an hour'],
            ['field' => 'rate', 'label' => 'Rate', 'value' => '1,250.00 GBP per day'],
            ['field' => 'rate', 'label' => 'Day rate', 'value' => '€450'],
            ['field' => 'rate', 'label' => 'Rate', 'value' => '£450 plus £40 an hour overtime'],
            ['field' => 'rate', 'label' => 'Fee', 'value' => '£1,200'],
            ['field' => 'venue', 'label' => 'Day rate', 'value' => '£450'],
        ]);
        $this->assertSame([
            ['rate' => '450.00', 'rate_unit' => 'day'],
            ['rate' => '35.00', 'rate_unit' => 'hour'],
            ['rate' => '1250.00', 'rate_unit' => 'day'],
            null, null, null, null,
        ], $read);

        // What the job sheet then sends (the job's title and dates, the rate and its unit) saves, and the days agreed are
        // filled with the job's working days.
        $this->signIn();
        $job = $this->job('Product launch', $this->client(), '2026-06-20', ['ends_on' => '2026-06-22', 'status' => 'booked']);
        $this->visit('PATCH', "/jobs/{$job->id}", ['title' => 'Product launch', 'starts_on' => '2026-06-20', 'ends_on' => '2026-06-22'] + $read[0], "/jobs/{$job->id}")
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $job->refresh();
        $this->assertSame([45000, 'day', 3], [$job->rate_pence, $job->rate_unit, $job->days_agreed]);
    }

    public function test_the_demo_has_a_job_at_each_stage_the_client_and_job_screens_show(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'demo@example.com')->firstOrFail());
        $stage = function (string $title) {
            $job = WorkJob::query()->where('title', $title)->sole();

            return json_decode(json_encode($this->get("/jobs/{$job->id}")->assertOk()->viewData('page')['props']), true)['stage']['stage'];
        };

        $this->assertSame('done_due', $stage('Arena load-out'));
        $this->assertSame('upcoming', $stage('New Year party'));
        $this->assertSame('cancelled_to_invoice', $stage('Product launch rehearsal'), 'cancelled with a fee: its draft is to check');
        $this->assertSame('cancelled', $stage('Corporate away day'));
        $this->assertSame(15_000, (int) Invoice::query()->where('work_job_id', WorkJob::query()->where('title', 'Product launch rehearsal')->value('id'))->value('total_minor'));

        $acme = WorkJob::query()->where('title', 'Winter gala')->sole()->client_id;
        $props = json_decode(json_encode($this->get("/clients/{$acme}")->assertOk()->viewData('page')['props']), true);
        $this->assertSame('Acme Events Ltd', $props['client']['name']);
        $this->assertSame(1, $props['owed']['costs_to_charge']['count'], 'the cables bought for them, charged back');
        $this->assertSame(2_499, $props['owed']['costs_to_charge']['pence']);
        // Each cost says where it stands in words (the code behind it is for requests only).
        $this->assertContains('Acme Events Ltd has no arrangement for train yet', collect($props['costs'])->pluck('note')->all());
        $this->get('/clients')->assertInertia(fn (AssertableInertia $p) => $p->where('duplicates.0.text', 'Acme Events Ltd and Acme Events may be the same client'));
        $this->get('/jobs/calendar?m='.now()->format('Y-m'))->assertOk();
    }

    /**
     * The job sheet's rateOf (resources/js/components/job-sheet.tsx), run in node on each fact: what "Use in job" fills
     * in, or null when it isn't one plain rate in pounds.
     *
     * @param  list<array<string, string>>  $facts
     */
    private function rates(array $facts): array
    {
        $probe = new Process(['node', '-e', 'require.resolve("esbuild", { paths: [process.argv[1]] })', base_path()]);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('node and the installed node modules are needed to run the page code.');
        }
        $dir = sys_get_temp_dir().'/sheet-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents("$dir/facts.json", json_encode($facts));
        file_put_contents("$dir/run.mjs", <<<'JS'
            import fs from 'node:fs';
            import path from 'node:path';
            import { createRequire } from 'node:module';
            import { pathToFileURL } from 'node:url';

            const [, , root, dir] = process.argv;
            const { transformSync } = createRequire(path.join(root, 'package.json'))('esbuild');
            // Only rateOf is run: whatever the file imports is a stand-in.
            const stub = path.join(dir, 'stub.mjs');
            fs.writeFileSync(stub, 'export const toast = () => {}, router = {}, Button = "Button", AlertTriangle = "Icon", CheckCircle2 = "Icon", jsx = () => null, jsxs = () => null, Fragment = "Fragment";');
            const source = fs.readFileSync(path.join(root, 'resources/js/components/job-sheet.tsx'), 'utf8');
            const code = transformSync(source, { loader: 'tsx', format: 'esm', jsx: 'automatic' }).code.replace(/from\s+"([^"]+)"/g, () => `from ${JSON.stringify(pathToFileURL(stub).href)}`);
            fs.writeFileSync(path.join(dir, 'sheet.mjs'), code);
            const sheet = await import(pathToFileURL(path.join(dir, 'sheet.mjs')).href);
            const facts = JSON.parse(fs.readFileSync(path.join(dir, 'facts.json'), 'utf8'));
            process.stdout.write(JSON.stringify(facts.map((f) => sheet.rateOf(f))));
            JS);
        $run = new Process(['node', '--no-warnings', "$dir/run.mjs", base_path(), $dir]);
        $run->run();
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }
}
