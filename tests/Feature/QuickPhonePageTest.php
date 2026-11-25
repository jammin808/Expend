<?php

namespace Tests\Feature;

use App\Domain\Quick\QuickWorker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone's page as the server sends it: the Add expense screen in its light layout, drawn under the notch only on the
 * quick screens, loading the phone's store (public/quick-store.js) before the app with the ?v= the worker precaches,
 * and keeping the address it was opened with. Its cached copy (/quick/shell) and the worker (/sw.js) carry the quick
 * page's built files.
 */
class QuickPhonePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_add_expense_screen_draws_under_the_notch_and_loads_the_store_first(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
        $html = (string) $this->get('/quick?job=14&source=home')->assertOk()->assertInertia(fn ($p) => $p->component('quick/index', false))->getContent();

        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('<script src="/quick-store.js?v='.QuickWorker::storeVersion().'" defer></script>', $html, 'the same ?v= the worker precaches');
        $this->assertStringContainsString('window.__quickLaunch', $html);
        $this->assertLessThan(strpos($html, '/build/'), strpos($html, '/quick-store.js'), 'the store runs before the app');
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Expend">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-status-bar-style" content="default">', $html);
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
    }

    public function test_other_pages_keep_their_header_clear_of_the_notch(): void
    {
        $this->actingAs(User::factory()->create());
        $html = (string) $this->get('/settings/profile')->assertOk()->getContent();

        $this->assertStringNotContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('/quick-store.js?v=', $html, 'every page sends what waits on the phone');
        $this->assertStringContainsString('ExpendQuick.boot()', $html);
    }

    public function test_the_cached_screen_and_the_worker_carry_the_quick_page(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest) || ! str_contains((string) file_get_contents($manifest), 'resources/js/pages/quick/index.tsx')) {
            $this->markTestSkipped('The front end isn’t built (npm run build).');
        }
        $this->actingAs(User::factory()->create());
        $shell = $this->get('/quick/shell')->assertOk()->assertHeader('X-Expend-Shell', '1');
        $this->assertStringContainsString('viewport-fit=cover', (string) $shell->getContent());

        $built = json_decode((string) file_get_contents($manifest), true);
        $sw = (string) $this->get('/sw.js')->assertOk()->getContent();
        $this->assertStringContainsString('/build/'.$built['resources/js/pages/quick/index.tsx']['file'], $sw);
        $this->assertStringContainsString('/build/'.$built['resources/js/app.tsx']['file'], $sw);
        $this->assertStringContainsString('"/quick-store.js?v='.QuickWorker::storeVersion().'"', $sw);
        $this->assertStringNotContainsString('__VERSION__', $sw);
        $this->assertStringNotContainsString('__PRECACHE__', $sw);
        $this->assertStringContainsString("addEventListener('fetch'", $sw);
    }
}
