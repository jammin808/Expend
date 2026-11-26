<?php

namespace Tests\Feature\Todo;

use App\Domain\Todo\ChangedSince;
use App\Models\Invoice;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;
use Tests\Feature\Income\HttpFixtures;
use Tests\TestCase;

/**
 * Build slice F4 (the to-do page and toasts): what the page and the toaster read after a press. A press comes back
 * with the server's message, its Undo (flash.undo, posted with its fingerprint) and what to do next (flash.follow_up:
 * links, and the choices a question asks for). "Is <job> done?" → Done → Undo → Done ends on the draft; a stale press
 * says why in the server's words; Cancelled… only asks, and its answers come with the page; "Not finished yet" is a
 * snooze reason for "Is it done?" only; links decide nothing.
 */
class TodoScreensTest extends TestCase
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

    public function test_is_it_done_then_done_undo_and_done_again_ends_on_the_draft(): void
    {
        $this->signIn();
        $job = $this->job('Arena load-in', $this->client(), '2026-06-08', ['status' => 'booked', 'fee_pence' => 65000]);

        $item = $this->item('job_done:'.$job->id);
        $this->assertSame('Is Arena load-in done?', $item['title']);
        $this->assertSame(['Done', 'Not yet', 'Cancelled…'], array_column($item['actions'], 'label'));

        // Done: the message, its Undo (back to booked) and the link to the draft it made, for the toast.
        $this->visit('POST', "/todo/job_done/{$job->id}", ['fp' => $this->fp($item, 'job_done')], '/todo')->assertRedirect('/todo')
            ->assertSessionHas('success', 'Marked done. Its invoice is ready to check.');
        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();
        $flash = $this->props('/todo')['flash'];
        $this->assertSame(['code' => 'job_reopen', 'id' => (string) $job->id, 'label' => 'Undo'], Arr::except($flash['undo'], 'fp'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $flash['undo']['fp']);
        $this->assertSame([['label' => 'Check the invoice', 'href' => url('/invoices/'.$draft->id)], $flash['undo']], $flash['follow_up']);
        // Shown once: the next page has no message, no Undo.
        $this->assertSame([null, null, null], array_values(Arr::only($this->props('/todo')['flash'], ['success', 'undo', 'follow_up'])));
        // The list moves on with the job: its invoice to check, with no "Is it done?".
        $this->assertNull($this->item('job_done:'.$job->id, false));
        $this->assertSame('/invoices/'.$draft->id, $this->item('invoice:'.$job->id)['href']);

        // Undo, as the toast posts it.
        $this->visit('POST', "/todo/job_reopen/{$job->id}", ['fp' => $flash['undo']['fp']], '/todo')->assertSessionHas('success', 'Back to booked.');
        $this->assertSame('booked', $job->fresh()->status);
        $this->assertNull(Invoice::query()->find($draft->id), 'the draft Done made, untouched, is gone');
        $after = $this->props('/todo')['flash'];
        $this->assertSame([null, []], [$after['undo'], $after['follow_up']], 'nothing to undo after an Undo');

        // Asked again; Done again ends on the new draft, which opens.
        $item = $this->item('job_done:'.$job->id);
        $this->visit('POST', "/todo/job_done/{$job->id}", ['fp' => $this->fp($item, 'job_done')], '/todo')->assertSessionHas('success');
        $again = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();
        $this->assertSame(url('/invoices/'.$again->id), $this->props('/todo')['flash']['follow_up'][0]['href']);
        $this->get('/invoices/'.$again->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('invoices/show', false)->where('invoice.status', 'draft'));
    }

    public function test_a_stale_press_is_refused_in_the_servers_words_and_changes_nothing(): void
    {
        $this->signIn();
        $job = $this->job('Arena load-in', $this->client(), '2026-06-08', ['status' => 'booked', 'fee_pence' => 65000]);
        $item = $this->item('job_done:'.$job->id);

        // Marked done on the job's page after the list was shown: the list's Not yet is refused, in words.
        $this->visit('POST', "/jobs/{$job->id}/done", ['stay' => true], "/jobs/{$job->id}")->assertSessionHas('success');
        $words = (new ChangedSince)->getMessage();
        $this->visit('POST', "/todo/job_not_yet/{$job->id}", ['fp' => $this->fp($item, 'job_not_yet')], '/todo')->assertRedirect('/todo')->assertSessionHas('error', $words);
        $this->assertSame(0, TodoDismissal::query()->count());
        $flash = $this->props('/todo')['flash'];
        $this->assertSame([$words, null, null], [$flash['error'], $flash['undo'], $flash['follow_up']]);

        // A deciding press without its fingerprint is refused the same way.
        $this->visit('POST', "/todo/job_cancel/{$job->id}", [], '/todo')->assertSessionHas('error', $words);
        $this->assertSame('done', $job->fresh()->status);
    }

    public function test_cancelled_only_asks_and_its_answers_come_with_the_page(): void
    {
        $this->signIn();
        $job = $this->job('Arena load-in', $this->client(), '2026-06-08', ['status' => 'booked', 'fee_pence' => 65000]);
        $item = $this->item('job_done:'.$job->id);

        $this->visit('POST', "/todo/job_cancel/{$job->id}", ['fp' => $this->fp($item, 'job_cancel')], '/todo')
            ->assertSessionHas('success', 'Do they owe you anything for Arena load-in? For a cancellation fee, give it on the job’s page: '.url("/jobs/{$job->id}#cancel"));
        $this->assertSame('booked', $job->fresh()->status, 'it only asks');
        $flash = $this->props('/todo')['flash'];
        $this->assertNull($flash['undo']);
        $this->assertSame([
            ['code' => 'job_cancel', 'id' => "{$job->id}:none", 'label' => 'No'],
            ['code' => 'job_cancel', 'id' => "{$job->id}:costs", 'label' => 'Just my costs'],
            ['label' => 'A cancellation fee…', 'href' => url("/jobs/{$job->id}#cancel")],
        ], array_map(fn (array $b) => Arr::except($b, 'fp'), $flash['follow_up']));
        [$no] = $flash['follow_up'];

        // No: cancelled, with Undo.
        $this->visit('POST', "/todo/job_cancel/{$job->id}:none", ['fp' => $no['fp']], '/todo')->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Cancelled'));
        $this->assertSame('cancelled', $job->fresh()->status);
        $undo = $this->props('/todo')['flash']['undo'];
        $this->assertSame(['job_uncancel', (string) $job->id], [$undo['code'], $undo['id']]);

        // Undo puts it back; the answer pressed again (shown before) is refused in words.
        $this->visit('POST', "/todo/job_uncancel/{$job->id}", ['fp' => $undo['fp']], '/todo')->assertSessionHas('success', 'Back to booked.');
        $this->assertSame('booked', $job->fresh()->status);
        $this->get("/jobs/{$job->id}")->assertOk();
    }

    public function test_not_finished_yet_is_a_snooze_reason_for_is_it_done_only(): void
    {
        $this->signIn();
        $job = $this->job('Arena load-in', $this->client(), '2026-06-08', ['status' => 'booked', 'fee_pence' => 65000]);
        $this->job('Old gala', $this->client('Beta Productions'), '2026-05-01', ['status' => 'done', 'fee_pence' => 40000]);

        $done = $this->item('job_done:'.$job->id);
        $this->assertSame(['later' => 'Later', 'waiting' => 'Waiting to hear back', 'not_finished' => 'Not finished yet', 'other' => 'Other'], $done['snooze_reasons']);
        $other = collect($this->props('/todo')['items'])->first(fn (array $i) => str_starts_with($i['key'], 'invoice:'));
        $this->assertArrayNotHasKey('not_finished', $other['snooze_reasons']);

        $this->visit('POST', '/todo/dismiss', ['key' => $other['key'], 'days' => 7, 'reason' => 'not_finished'], '/todo')->assertSessionHasErrors(['reason' => 'Choose one of the reasons offered.']);
        $this->visit('POST', '/todo/dismiss', ['key' => $done['key'], 'days' => 7, 'reason' => 'not_finished'], '/todo')->assertSessionHas('success', 'Snoozed for 7 days.');
        $this->assertSame('Not finished yet', TodoDismissal::query()->where('key', 'job_done:'.$job->id)->value('note'));
    }

    public function test_the_demo_has_a_link_among_a_to_do_items_buttons(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'demo@example.com')->firstOrFail());
        $invoice = Invoice::query()->where('work_job_id', WorkJob::query()->where('title', 'Harbour Arts open day')->value('id'))->sole();

        $item = $this->item('unsent:'.$invoice->id);
        $this->assertStringContainsString('the Gmail draft was deleted without being sent', $item['detail']);
        $link = collect($item['actions'])->firstWhere('code', 'link');
        $this->assertSame(['code' => 'link', 'id' => '', 'label' => 'Pick a date', 'style' => 'ghost', 'href' => "/invoices/{$invoice->id}#sent", 'fp' => null], $link);
        $this->assertSame('I sent it another way', collect($item['actions'])->firstWhere('code', 'inv_sent_self')['label']);
        $this->assertNotNull($this->item('job_done:'.WorkJob::query()->where('title', 'Arena load-out')->value('id')));
    }

    /** The page code: no browser prompt anywhere in it, and the toaster's own helpers, run in node. */
    public function test_the_page_and_toaster_show_the_servers_words(): void
    {
        $page = file_get_contents(resource_path('js/pages/todo.tsx'));
        $toaster = file_get_contents(resource_path('js/components/toaster.tsx'));
        foreach ([$page, $toaster] as $code) {
            $this->assertDoesNotMatchRegularExpression('/window\.(confirm|prompt|alert)|[^.\w](confirm|prompt|alert)\(/', $code);
        }
        // Every press on the page goes through pressTodo or postQuietly, which always end in words.
        $this->assertStringNotContainsString('router.post(', $page);
        $this->assertStringContainsString('flash: { ...(props.flash as object), success: null, error: null, rule_offer: null, rule_undo: null, undo: null, follow_up: null }', preg_replace('/\s+/', ' ', $toaster));

        $out = $this->node(<<<'JS'
            globalThis.window = { location: new URL('http://127.0.0.1:8624/todo') };
            out({
                cancel: todo.withoutLinks('Do they owe you anything for Arena load-in? For a cancellation fee, give it on the job’s page: http://127.0.0.1:8624/jobs/5#cancel'),
                wait: todo.withoutLinks('OK: Expend won’t ask about INV-0002 for a week. Link the payment when it’s in: http://127.0.0.1:8624/invoices/2#payments'),
                inbox: todo.withoutLinks('That doesn’t fit Acme any more: open it in the Inbox. http://127.0.0.1:8624/inbox'),
                plain: todo.withoutLinks('Marked done. Its invoice is ready to check.'),
                words: ['http://127.0.0.1:8624/invoices/2#payments', 'http://127.0.0.1:8624/invoices/9', 'http://127.0.0.1:8624/jobs/5', 'http://127.0.0.1:8624/inbox', 'http://127.0.0.1:8624/opportunities/3'].map(todo.openWords),
                path: [todo.localPath('http://127.0.0.1:8624/jobs/5#days'), todo.localPath('https://example.test/jobs/5')],
                here: [todo.isHere('http://127.0.0.1:8624/todo'), todo.isHere('http://127.0.0.1:8624/todo#x'), todo.isHere('http://127.0.0.1:8624/invoices/2')],
                url: todo.todoUrl({ code: 'cost_no_charge', id: 'tx:181:j12' }),
                none: [0, 419, 429, 500, 404].map(todo.noAnswerWords),
            });
            JS);

        $this->assertSame(['text' => 'Do they owe you anything for Arena load-in? For a cancellation fee, give it on the job’s page.', 'hrefs' => ['http://127.0.0.1:8624/jobs/5#cancel']], $out['cancel']);
        $this->assertSame('OK: Expend won’t ask about INV-0002 for a week. Link the payment when it’s in.', $out['wait']['text']);
        $this->assertSame(['text' => 'That doesn’t fit Acme any more: open it in the Inbox.', 'hrefs' => ['http://127.0.0.1:8624/inbox']], $out['inbox']);
        $this->assertSame(['text' => 'Marked done. Its invoice is ready to check.', 'hrefs' => []], $out['plain']);
        $this->assertSame(['Link a payment', 'Open the invoice', 'Open the job', 'Open the Inbox', 'Open it'], $out['words']);
        $this->assertSame(['/jobs/5#days', null], $out['path']);
        $this->assertSame([true, false, false], $out['here']);
        $this->assertSame('/todo/cost_no_charge/tx%3A181%3Aj12', $out['url']);
        foreach ($out['none'] as $words) {
            $this->assertNotSame('', $words);
            $this->assertDoesNotMatchRegularExpression('/fingerprint|fp|419|500|error/i', $words);
        }
    }

    /** The to-do page's props, as the page gets them. */
    private function props(string $uri): array
    {
        return json_decode(json_encode($this->get($uri)->assertOk()->viewData('page')['props']), true);
    }

    /** One item on the to-do page by its key (null when it isn't there and $must is false). */
    private function item(string $key, bool $must = true): ?array
    {
        $item = collect($this->props('/todo')['items'])->firstWhere('key', $key);
        if ($must) {
            $this->assertNotNull($item, "no to-do item {$key}");
        }

        return $item;
    }

    /** The fingerprint an item's button carries. */
    private function fp(array $item, string $code): string
    {
        return collect($item['actions'])->firstWhere('code', $code)['fp'];
    }

    /** Runs $script in node with `todo` (resources/js/lib/todo.ts, bundled) and `out(value)`; returns what it gave out. */
    private function node(string $script): mixed
    {
        $probe = new Process(['node', '-e', 'require.resolve("esbuild", { paths: [process.argv[1]] })', base_path()]);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('node and the installed node modules are needed to run the page code.');
        }
        $dir = sys_get_temp_dir().'/todo-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents("$dir/run.mjs", <<<'JS'
            import fs from 'node:fs';
            import path from 'node:path';
            import { createRequire } from 'node:module';
            import { pathToFileURL } from 'node:url';

            const [, , root, dir] = process.argv;
            const { buildSync } = createRequire(path.join(root, 'package.json'))('esbuild');
            // Inertia's router is the browser's: these helpers only need it to be there.
            fs.writeFileSync(path.join(dir, 'inertia.mjs'), 'export const router = { on: () => () => {}, post() {}, visit() {} };');
            buildSync({ entryPoints: [path.join(root, 'resources/js/lib/todo.ts')], bundle: true, format: 'esm', platform: 'node',
                alias: { '@inertiajs/react': path.join(dir, 'inertia.mjs') },
                outfile: path.join(dir, 'todo.mjs'), tsconfig: path.join(root, 'tsconfig.json'), logLevel: 'silent' });
            const todo = await import(pathToFileURL(path.join(dir, 'todo.mjs')).href);
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
