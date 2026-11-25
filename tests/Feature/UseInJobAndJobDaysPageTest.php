<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobDays;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The job page's own code, run in node with the props the server gives it: "Use in job" on the job sheet is only
 * offered where it can save, a one-day job moved to a later start date stays a one-day job, and every change to a
 * job's days says why when it isn't saved. What the page sends is then sent to the server as Inertia sends it.
 */
class UseInJobAndJobDaysPageTest extends TestCase
{
    use RefreshDatabase;

    /** The refusal the page code is handed, and the message it should show for it. */
    private const REFUSED = ['first' => 'The first problem.', 'second' => 'Another one.'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
    }

    private function job(array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => 'Product launch', 'client_id' => Client::query()->create(['name' => 'Acme Events'])->id,
            'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'start_time' => '08:00', 'end_time' => '18:00', 'location' => 'Old venue',
            'postcode' => 'M1 1AA', 'fee_pence' => 45000, 'status' => 'booked', 'po_number' => 'PO-4471']);
    }

    /** A call sheet linked to the job with one checked detail on it. */
    private function fact(WorkJob $job, string $field, string $value, array $extra = []): void
    {
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'call-sheet.pdf', 'mime' => 'application/pdf', 'size' => 1,
            'sha256' => hash('sha256', $field.$value.$job->id), 'stored_path' => 'x', 'kind' => 'call_sheet', 'status' => 'extracted',
            'received_at' => '2025-05-01 09:00:00', 'work_job_id' => $job->id]);
        DocumentFact::query()->create($extra + ['document_id' => $doc->id, 'work_job_id' => $job->id, 'field' => $field, 'label' => ucfirst($field),
            'value' => $value, 'quote' => $value, 'verified' => true, 'on_date' => null, 'at_time' => null]);
    }

    /** The job page's props, as the browser gets them. */
    private function props(WorkJob $job): array
    {
        $page = $this->get("/jobs/{$job->id}")->assertInertia(fn ($p) => $p->component('jobs/show', false))->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    /** Presses "Use in job" wherever the job sheet shows it. */
    private function sheet(WorkJob $job): array
    {
        $props = $this->props($job);

        return $this->press('job-sheet.tsx', 'JobSheet', ['job' => $props['job'], 'facts' => $props['facts'], 'documents' => $props['documents']]);
    }

    /** Sends what the page sent, as Inertia does: JSON, with the Inertia headers. */
    private function send(array $press)
    {
        return $this->json(strtoupper($press['method'] === 'form.post' ? 'post' : $press['method']), $press['url'], $press['body'] ?? [],
            ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);
    }

    public function test_use_in_job_is_only_offered_for_details_the_job_can_take(): void
    {
        $job = $this->job();
        $this->fact($job, 'call_time', '7am call'); // the AI read no time from it
        $this->fact($job, 'start_date', 'The weekend'); // nor a date from this
        $this->fact($job, 'finish_time', 'Wrap at 23:00', ['at_time' => '23:00']);
        $this->fact($job, 'venue', 'Leeds Arena');

        $presses = $this->sheet($job);

        $this->assertEquals([
            ['title' => 'Product launch', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'end_time' => '23:00'],
            ['title' => 'Product launch', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'location' => 'Leeds Arena'],
        ], array_column($presses, 'body'), 'No "Use in job" for a time or date that could never save');
        foreach ($presses as $press) {
            $this->assertSame([[self::REFUSED['first'], 'error']], $press['refusal'], 'A refused save shows the first reason');
            $this->send($press)->assertSessionHasNoErrors()->assertSessionHas('success');
        }
        $fresh = $job->fresh();
        $this->assertSame(['23:00', 'Leeds Arena', '08:00', 'PO-4471', 45000],
            [substr((string) $fresh->end_time, 0, 5), $fresh->location, substr((string) $fresh->start_time, 0, 5), $fresh->po_number, $fresh->fee_pence]);
    }

    public function test_a_later_start_date_moves_a_one_day_job_and_its_day_keeps_its_kind_times_and_note(): void
    {
        $job = $this->job();
        JobDay::query()->where('work_job_id', $job->id)->sole()->update(['kind' => 'show', 'start_time' => '10:00', 'end_time' => '23:30', 'note' => 'Doors at 7']);
        $this->fact($job, 'start_date', 'Monday 12 May', ['on_date' => '2025-05-12']);

        [$press] = $this->sheet($job);

        // A one-day job moves to the date: both of its dates go, so the whole job moves (JobDays::rangeChanged).
        $this->assertEquals(['title' => 'Product launch', 'starts_on' => '2025-05-12', 'ends_on' => '2025-05-12'], $press['body']);
        $this->send($press)->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $job->fresh();
        $this->assertSame(['2025-05-12', '2025-05-12'], [$fresh->starts_on->format('Y-m-d'), $fresh->ends_on->format('Y-m-d')]);
        $this->assertSame(['2025-05-12'], $fresh->workDates());
        $day = JobDay::query()->where('work_job_id', $job->id)->sole();
        $this->assertSame(['show', '10:00', '23:30', 'Doors at 7'], [$day->kind, substr((string) $day->start_time, 0, 5), substr((string) $day->end_time, 0, 5), $day->note]);
        // The job keeps everything else, and the history shows the day moving with its kind, times and note.
        $this->assertSame(['PO-4471', 45000, 'Old venue'], [$fresh->po_number, $fresh->fee_pence, $fresh->location]);
        $audit = AuditEvent::query()->where('action', 'job.days_changed')->where('subject_id', $job->id)->latest('id')->firstOrFail();
        $this->assertEquals([['date' => '2025-05-12', 'kind' => 'show', 'start_time' => '10:00', 'end_time' => '23:30', 'note' => 'Doors at 7']], $audit->after['days']);
        $this->assertEquals([['date' => '2025-05-10', 'kind' => 'show', 'start_time' => '10:00', 'end_time' => '23:30', 'note' => 'Doors at 7']], $audit->before['days']);
    }

    public function test_an_earlier_start_date_moves_a_one_day_job_too(): void
    {
        $job = $this->job();
        JobDay::query()->where('work_job_id', $job->id)->sole()->update(['kind' => 'rig', 'start_time' => '07:00', 'end_time' => '19:00']);
        $this->fact($job, 'start_date', 'Thursday 8 May', ['on_date' => '2025-05-08']);

        [$press] = $this->sheet($job);

        $this->assertEquals(['title' => 'Product launch', 'starts_on' => '2025-05-08', 'ends_on' => '2025-05-08'], $press['body']);
        $this->send($press)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(['2025-05-08'], $job->fresh()->workDates());
        $day = JobDay::query()->where('work_job_id', $job->id)->sole();
        $this->assertSame(['rig', '07:00', '19:00'], [$day->kind, substr((string) $day->start_time, 0, 5), substr((string) $day->end_time, 0, 5)]);
    }

    public function test_a_start_date_after_a_longer_job_ends_is_still_refused_plainly(): void
    {
        $job = $this->job(['ends_on' => '2025-05-11']);
        $this->fact($job, 'start_date', 'Wednesday 14 May', ['on_date' => '2025-05-14']);

        [$press] = $this->sheet($job);

        $this->assertEquals(['title' => 'Product launch', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-11'], $press['body'], 'A longer job keeps its end date');
        $this->send($press)->assertSessionHasErrors(['ends_on' => 'The job can’t end before it starts.']);
        $this->assertSame(['2025-05-10', '2025-05-11'], $job->fresh()->workDates());
    }

    public function test_every_change_to_a_jobs_days_says_why_when_it_is_not_saved(): void
    {
        $job = $this->job(['starts_on' => '2025-11-03', 'ends_on' => '2025-11-03']);
        JobDays::add($job, '2025-11-07', 'derig', null, '13:00');
        JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-03')->update(['kind' => 'rig', 'start_time' => '08:00']);
        $props = $this->props($job->fresh());

        $presses = $this->press('job-days.tsx', 'JobDays', ['jobId' => $job->id, 'days' => $props['days'], 'kinds' => $props['day_kinds']]);

        // Each day's kind, its start and finish (the de-rig's finish is left as it was), removing it, and adding a day.
        $this->assertSame(['patch', 'patch', 'patch', 'delete', 'patch', 'patch', 'delete', 'form.post'], array_column($presses, 'method'));
        foreach ($presses as $press) {
            $this->assertSame([[self::REFUSED['first'], 'error']], $press['refusal'], $press['control'].' shows why it wasn’t saved');
        }
        // A refused time leaves the box showing the time that's kept.
        $this->assertSame(['08:00', '', ''], array_values(array_filter(array_column($presses, 'box'), 'is_string')));

        // What the page sends saves.
        foreach ($presses as $press) {
            if ($press['method'] === 'patch') {
                $this->send($press)->assertSessionHasNoErrors()->assertSessionHas('success');
            }
        }
        $add = collect($presses)->firstWhere('method', 'form.post');
        $this->send(['body' => ['date' => '2025-11-05'] + $add['body']] + $add)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->send(collect($presses)->firstWhere('method', 'delete'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(['2025-11-05', '2025-11-07'], $job->fresh()->workDates());
    }

    public function test_a_day_change_the_server_refuses_comes_back_where_the_page_shows_it(): void
    {
        $job = $this->job();
        $day = JobDay::query()->where('work_job_id', $job->id)->sole();
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'];

        // A refused value is a validation error, which the page's onError shows.
        $this->json('PATCH', "/job-days/{$day->id}", ['kind' => 'work', 'start_time' => '7.30', 'end_time' => null, 'note' => null], $headers)
            ->assertSessionHasErrors('start_time');
        $this->assertNull($day->fresh()->start_time);

        // A day too far from the job is refused with a message of its own, which every page shows.
        $this->json('POST', "/jobs/{$job->id}/days", ['date' => '2026-01-10', 'kind' => 'work', 'start_time' => '', 'end_time' => ''], $headers)
            ->assertSessionHasNoErrors()->assertSessionHas('error', 'That’s more than four months from the job (or makes it longer than 400 days). Make it a separate job.');
        $this->assertSame(['2025-05-10'], $job->fresh()->workDates());
    }

    /**
     * Runs a component from resources/js/components in node with stand-ins for React, Inertia and the toaster, presses
     * every button, select, time box and form it draws, and returns what each one sends. A refusal is handed to each
     * request's onError, and the toasts it shows are returned with it.
     */
    private function press(string $component, string $export, array $props): array
    {
        $probe = new Process(['node', '-e', 'require.resolve("esbuild", { paths: [process.argv[1]] })', base_path()]);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('node and the installed node modules are needed to run the page code.');
        }
        $dir = sys_get_temp_dir().'/page-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents("$dir/run.mjs", self::HARNESS);
        file_put_contents("$dir/props.json", json_encode($props));
        file_put_contents("$dir/refused.json", json_encode(self::REFUSED));
        $run = new Process(['node', '--no-warnings', "$dir/run.mjs", base_path(), resource_path("js/components/$component"), $export, $dir]);
        $run->run();
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }

    private const HARNESS = <<<'JS'
        import fs from 'node:fs';
        import path from 'node:path';
        import { createRequire } from 'node:module';
        import { pathToFileURL } from 'node:url';

        const [, , root, file, exportName, dir] = process.argv;
        const { transformSync } = createRequire(path.join(root, 'package.json'))('esbuild');
        const stubs = {
            '@inertiajs/react': `const calls = globalThis.__calls;
                export const router = new Proxy({}, { get: (_, method) => (...args) => calls.push({ method, args }) });
                export function useForm(data) { return { data, errors: {}, processing: false, setData() {}, reset() {},
                    post: (url, options) => calls.push({ method: 'form.post', args: [url, data, options] }) }; }`,
            '@/components/toaster': 'export const toast = (...args) => globalThis.__toasts.push(args);',
            'react/jsx-runtime': 'export const jsx = (type, props, key) => ({ type, props, key }); export const jsxs = jsx; export const Fragment = "Fragment";',
        };
        let n = 0;
        const code = transformSync(fs.readFileSync(file, 'utf8'), { loader: 'tsx', format: 'esm', jsx: 'automatic' }).code.replace(/from\s+"([^"]+)"/g, (_, spec) => {
            // Anything else it imports (buttons, boxes, icons) is a plain element.
            const stub = path.join(dir, `stub${n++}.mjs`);
            fs.writeFileSync(stub, stubs[spec] ?? 'export const Button = "Button", Input = "Input", AlertTriangle = "Icon", CheckCircle2 = "Icon", CalendarDays = "Icon", X = "Icon";');
            return `from ${JSON.stringify(pathToFileURL(stub).href)}`;
        });
        fs.writeFileSync(path.join(dir, 'page.mjs'), code);
        globalThis.__calls = [];
        globalThis.__toasts = [];
        const mod = await import(pathToFileURL(path.join(dir, 'page.mjs')).href);

        const elements = [];
        const draw = (el) => {
            if (Array.isArray(el)) return el.forEach(draw);
            if (!el || typeof el !== 'object') return;
            if (typeof el.type === 'function') return draw(el.type(el.props));
            elements.push(el);
            draw(el.props?.children);
        };
        draw(mod[exportName](JSON.parse(fs.readFileSync(path.join(dir, 'props.json'), 'utf8'))));
        const refused = JSON.parse(fs.readFileSync(path.join(dir, 'refused.json'), 'utf8'));
        const text = (el) => [el.props?.children].flat(Infinity).filter((c) => typeof c === 'string').join('');
        const presses = [];
        for (const el of elements) {
            const p = el.props ?? {};
            const before = globalThis.__calls.length;
            const box = { value: '13:00' };
            if (p.onClick) p.onClick();
            else if (el.type === 'select' && p.onChange && p.value !== undefined) p.onChange({ target: { value: 'travel' } });
            else if (p.onBlur) p.onBlur({ target: box, currentTarget: box });
            else if (p.onSubmit) p.onSubmit({ preventDefault() {} });
            else continue;
            for (const call of globalThis.__calls.slice(before)) {
                // router.delete(url, options); router.patch(url, data, options); form.post(url, options) sends the form's data.
                const [url, body, options = {}] = call.method === 'delete' ? [call.args[0], null, call.args[1]] : call.args;
                const toasts = globalThis.__toasts.length;
                if (typeof options.onError === 'function') options.onError(refused);
                presses.push({ control: text(el) || p['aria-label'] || el.type, method: call.method, url, body, refusal: globalThis.__toasts.slice(toasts),
                    box: p.onBlur ? box.value : null });
            }
        }
        console.log(JSON.stringify(presses));
        JS;
}
