<?php

namespace Tests\Feature\Quick;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Ledger\DecisionService;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Quick\QuickWorker;
use App\Http\Controllers\TransactionController;
use App\Models\ExchangeRate;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What the phone reads and loads: the token (401 when signed out, a remember-me cookie signs you back in), the pocket
 * with its ETag, the category suggestion (read only), the service worker (no session, its version following the build,
 * the kill switch), the screen's cached copy (nothing of this request in it), Android's share fallback and the entry page.
 */
class QuickEndpointsTest extends TestCase
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

    public function test_ticking_remember_me_when_signing_in_keeps_the_phone_signed_in(): void
    {
        Auth::logout();
        $this->owner->forceFill(['password' => bcrypt('local-test-only')])->save();
        $recaller = Auth::guard('web')->getRecallerName();

        $this->post('/login', ['email' => 'alex@example.test', 'password' => 'local-test-only', 'remember' => true])->assertRedirect()->assertCookie($recaller);
        Auth::logout();
        $this->post('/login', ['email' => 'alex@example.test', 'password' => 'local-test-only', 'remember' => false])->assertRedirect()->assertCookieMissing($recaller);

        // The login form sends the box's state (it was never sent before, so Remember me did nothing).
        $page = (string) file_get_contents(resource_path('js/pages/auth/login.tsx'));
        $this->assertStringContainsString('checked={data.remember}', $page);
        $this->assertStringContainsString("onCheckedChange={(checked) => setData('remember', checked === true)}", $page);
    }

    public function test_signed_out_the_json_says_401_and_a_remember_me_cookie_signs_you_back_in(): void
    {
        Auth::logout();
        $this->getJson('/quick/token')->assertStatus(401)->assertJsonPath('message', 'Sign in to send');
        $this->getJson('/quick/pocket')->assertStatus(401);
        $this->getJson('/quick/suggest?what=NCP')->assertStatus(401);
        $this->postJson('/quick/entries', $this->fields())->assertStatus(401);

        $user = User::query()->firstOrFail();
        $user->forceFill(['remember_token' => 'remember-me-token'])->save();
        Auth::forgetGuards(); // a new visit: the guard hasn't been signed out in this one
        $this->withCredentials()->withCookie(Auth::guard('web')->getRecallerName(), $user->id.'|remember-me-token|'.$user->getAuthPassword())
            ->getJson('/quick/token')->assertOk()->assertJsonStructure(['csrf', 'max_upload_bytes', 'pocket_etag', 'now']);
    }

    public function test_the_pocket_has_the_days_data_and_answers_304_when_the_phone_has_it(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-24', 'ends_on' => '2026-11-26', 'status' => 'booked']);
        WorkJob::query()->create(['title' => 'Old show', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-01', 'status' => 'done']);
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        TaxYear::track('2025-26', 'filed');
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'source' => 'hmrc_monthly', 'units_per_gbp' => '1.2']);
        $this->send($this->fields())->assertCreated();

        $r = $this->getJson('/quick/pocket')->assertOk();
        $this->assertSame('2026-11-25', $r->json('today'));
        $this->assertSame(['2024-25'], $r->json('locked_years'));
        $this->assertSame(['2025-26'], $r->json('filed_years'));
        $this->assertSame([$job->id], array_column($r->json('jobs'), 'id'), 'Only jobs near today');
        $this->assertSame(['2026-11-24', '2026-11-25', '2026-11-26'], $r->json('jobs.0.days'));
        $this->assertSame('Starling', $r->json('accounts.0.name'));
        $this->assertCount(6, $r->json('chips'));
        $this->assertSame('Parking', $r->json('chips.0.label'), 'Your own use first');
        $this->assertSame('1.2', $r->json('rates.2026-11.EUR'));
        $this->assertSame(1250, $r->json('recent.0.amount_minor'));
        $this->assertSame(QuickWorker::maxUploadBytes(), $r->json('max_upload_bytes'));

        $this->getJson('/quick/pocket', ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
    }

    public function test_the_suggestion_follows_your_decisions_and_the_job_youre_on_and_only_reads(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        foreach (['2026-11-01', '2026-11-08'] as $d) {
            $l = $this->line($d, -900, 'NCP LEEDS', null, ['merchant_key' => MerchantKey::from('NCP Leeds')]);
            app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_parking')], collect([$l]));
            $t = $this->line($d, -1500, 'TESCO', null, ['merchant_key' => MerchantKey::from('Tesco')]);
            app(DecisionService::class)->apply(['kind' => 'personal'], collect([$t]));
        }

        $this->getJson('/quick/suggest?what=NCP+Leeds&amount=12.50&date=2026-11-25')->assertOk()
            ->assertJsonPath('category_id', $this->cat('travel_parking'))->assertJsonPath('job_id', $job->id)->assertJsonPath('personal_note', null);
        $this->getJson('/quick/suggest?what=Adobe&amount=20&date=2026-11-25')->assertOk()
            ->assertJsonPath('category_id', $this->cat('software'))->assertJsonPath('job_id', null);
        $this->getJson('/quick/suggest?what=Tesco&amount=15&date=2026-11-25')->assertOk()
            ->assertJsonPath('category_id', null)->assertJsonPath('personal_note', 'You usually mark Tesco as personal: add it anyway?');

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $c = app(Categoriser::class);
        $c->preview('NCP Leeds', 1250, CarbonImmutable::parse('2026-11-25'));
        $c->markOwn();
        $this->assertSame([], $writes, 'Nothing written, nothing proposed');
    }

    public function test_the_service_worker_has_no_session_follows_the_build_and_can_be_switched_off(): void
    {
        $manifest = tempnam(sys_get_temp_dir(), 'vite');
        file_put_contents($manifest, json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/app-1.js', 'css' => ['assets/app-1.css'], 'imports' => ['_shared-1.js']],
            'resources/js/pages/quick/index.tsx' => ['file' => 'assets/quick-1.js', 'imports' => ['resources/js/app.tsx', '_keypad-1.js']],
            '_shared-1.js' => ['file' => 'assets/shared-1.js'], '_keypad-1.js' => ['file' => 'assets/keypad-1.js', 'css' => ['assets/keypad-1.css']],
            'resources/js/pages/dashboard.tsx' => ['file' => 'assets/dashboard-1.js'],
        ]));
        config(['expend.vite_manifest' => $manifest]);
        Auth::logout();

        $r = $this->get('/sw.js')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertStringStartsWith('text/javascript', (string) $r->headers->get('Content-Type'));
        $this->assertSame([], $r->headers->getCookies(), 'No session, no cookie');
        $body = $r->getContent();
        foreach (['/build/assets/app-1.js', '/build/assets/app-1.css', '/build/assets/shared-1.js', '/build/assets/quick-1.js', '/build/assets/keypad-1.css'] as $f) {
            $this->assertStringContainsString($f, $body);
        }
        $this->assertStringNotContainsString('dashboard-1.js', $body);
        $before = QuickWorker::version();
        $this->assertStringContainsString(json_encode($before), $body);

        file_put_contents($manifest, json_encode(['resources/js/pages/quick/index.tsx' => ['file' => 'assets/quick-2.js']]));
        $this->assertNotSame($before, QuickWorker::version(), 'A new build, a new worker');

        Setting::put('quick_sw_off', true);
        $this->assertStringContainsString('unregister', $this->get('/sw.js')->assertOk()->getContent());
        @unlink($manifest);
    }

    public function test_the_cached_screen_carries_nothing_of_this_request(): void
    {
        $this->withSession(['success' => 'Saved your invoice'])->get('/quick/shell')->assertOk()
            ->assertHeader('X-Expend-Shell', '1')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('quick/index', false)->url('/quick')->where('shell', true)
                ->where('nav', null)->where('flash.success', null)->where('auth.user.email', 'alex@example.test')->missing('auth.user.id'));
        $this->get('/quick?job=14')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quick/index', false)->where('shell', false));

        Auth::logout();
        $this->get('/quick/shell')->assertRedirect('/login');
    }

    public function test_the_nightly_receipts_run_at_0409_unless_the_cycle_does_it_and_phone_reads_have_their_own_queue(): void
    {
        $events = collect(app(Schedule::class)->events());
        $receipts = $events->first(fn ($e) => str_contains((string) $e->command, 'ledger:receipts'));
        $this->assertNotNull($receipts);
        $this->assertSame(['9 4 * * *', 'Europe/London'], [$receipts->expression, $receipts->timezone]);
        $this->assertTrue($receipts->filtersPass($this->app), 'Runs while the cycle is off');
        // Without overlapping, and skipped exactly when the cycle does the nightly work (relate.receipts then).
        $rejects = (fn () => $this->rejects)->call($receipts);
        $this->assertCount(2, $rejects);
        $this->assertSame(CycleRunner::doesNightlyWork(), $rejects[1]());
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'queue:work --queue=default,reads')));
    }

    public function test_androids_share_fallback_stores_nothing_and_says_to_share_again(): void
    {
        Auth::logout();
        $r = $this->post('/quick/share', ['text' => '£12.50 parking'])->assertStatus(303)->assertRedirect('/quick?share_failed=1');

        $this->assertSame([], $r->headers->getCookies());
        $this->assertSame(0, QuickEntry::query()->count());
    }

    public function test_the_entry_page_shows_the_entry_its_lines_and_what_you_can_do(): void
    {
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $this->line('2026-11-27', -1250, 'Q-PARK');
        $f = $this->fields(['paid_with' => 'business']);
        $this->send($f)->assertCreated();
        $e = $this->entry($f['client_key']);

        $this->get('/quick/entries/'.$e->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quick/entry', false)
            ->where('entry.state', 'waiting')->where('entry.words', '£12.50 parking')->where('can_edit', true)
            ->where('lines.0.id', $line->id)->where('lines.0.fits', true)->has('actions', fn (AssertableInertia $a) => $a->etc()));

        $this->post('/quick/entries/'.$e->id.'/attach', ['line_id' => $line->id])->assertSessionHas('success');
        $this->assertSame(['attached', 'you'], [$e->fresh()->status, $e->fresh()->attached_by]);
    }

    public function test_a_waiting_receipt_can_be_changed_and_is_matched_again(): void
    {
        $line = $this->line('2026-11-26', -1530, 'NCP LEEDS');
        $f = $this->fields(['paid_with' => 'business']);
        $this->send($f)->assertCreated();
        $e = $this->entry($f['client_key']);

        $this->patch('/quick/entries/'.$e->id, ['amount' => '15.30'])->assertSessionHas('success');

        $this->assertSame([1530, $line->id], [$e->fresh()->amount_minor, (int) $e->fresh()->bank_transaction_id]);
    }

    public function test_an_expense_from_the_phone_lists_its_photo_as_its_receipt(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();

        $links = TransactionController::links(ManualExpense::query()->sole());

        $receipt = collect($links)->firstWhere('type', 'receipt');
        $this->assertSame(['Your receipt photo: Parking', '/quick/entries/'.$this->entry($f['client_key'])->id], [$receipt['label'], $receipt['href']]);
    }
}
