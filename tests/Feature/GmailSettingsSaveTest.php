<?php

namespace Tests\Feature;

use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Saving Settings › Integrations › Gmail. The page sends every search box, changed or not, so whatever the page
 * shows must save as it is: a built-in search once outgrew the length limit and blocked every save.
 */
class GmailSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** The searches exactly as the Settings page fills its boxes. */
    private function searchesOnThePage(): array
    {
        $searches = $this->get('/settings/integrations')->assertOk()->viewData('page')['props']['gmail']['searches'];

        return collect($searches)->mapWithKeys(fn ($s) => [$s['key'] => $s['query']])->all();
    }

    /** Send the form the way the page does: JSON from Inertia, back to the page. */
    private function save(array $queries, array $more = []): TestResponse
    {
        $body = $more + [
            'client_id' => '123-abc.apps.googleusercontent.com',
            'client_secret' => 'new-client-secret',
            'queries' => $queries,
            'label_invoiced' => true,
        ];

        return $this->from('/settings/integrations')->call('PUT', '/settings/integrations/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
        ], json_encode($body));
    }

    public function test_the_page_saves_as_it_loads_with_every_built_in_search_untouched(): void
    {
        $queries = $this->searchesOnThePage();
        $this->assertSame(array_keys(GmailScanner::SEARCHES), array_keys($queries));

        $this->save($queries)->assertSessionHasNoErrors()->assertRedirect('/settings/integrations');

        $this->assertSame('123-abc.apps.googleusercontent.com', Setting::get('google_client_id'));
        $this->assertSame('new-client-secret', Setting::get('google_client_secret'));
        foreach (array_keys(GmailScanner::SEARCHES) as $key) {
            $this->assertNull(Setting::get('gmail_query_'.$key), "{$key} is stored as the built-in search");
            $this->assertSame(GmailScanner::SEARCHES[$key]['query'], GmailScanner::query($key));
        }
    }

    public function test_every_built_in_search_leaves_room_to_add_to_it(): void
    {
        foreach (GmailScanner::SEARCHES as $key => $search) {
            $this->assertLessThanOrEqual(GmailScanner::MAX_QUERY_LENGTH - 500, mb_strlen($search['query']), "{$key} is near the limit");
        }
    }

    public function test_a_built_in_search_with_a_term_added_is_saved(): void
    {
        $queries = $this->searchesOnThePage();
        $queries['opportunities'] .= ' OR "standby day"';
        $this->assertGreaterThan(2000, mb_strlen($queries['opportunities']));

        $this->save($queries)->assertSessionHasNoErrors();

        $this->assertSame($queries['opportunities'], Setting::get('gmail_query_opportunities'));
        $this->assertSame($queries['opportunities'], GmailScanner::query('opportunities'));
    }

    public function test_a_saved_edit_shows_on_the_page_and_saves_again(): void
    {
        $queries = $this->searchesOnThePage();
        $queries['hotels'] = 'from:booking.com';
        $this->save($queries)->assertSessionHasNoErrors();

        $again = $this->searchesOnThePage();
        $this->assertSame('from:booking.com', $again['hotels']);
        $this->save($again, ['client_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame('from:booking.com', Setting::get('gmail_query_hotels'));
        $this->assertSame('new-client-secret', Setting::get('google_client_secret'), 'an empty secret box keeps the saved secret');
    }

    public function test_an_emptied_box_goes_back_to_the_built_in_search(): void
    {
        Setting::put('gmail_query_opportunities', 'subject:avail');
        $queries = $this->searchesOnThePage();
        $queries['opportunities'] = '';

        $this->save($queries)->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('gmail_query_opportunities'));
        $this->assertSame(GmailScanner::SEARCHES['opportunities']['query'], GmailScanner::query('opportunities'));
    }

    public function test_a_search_over_the_limit_is_refused_by_name_and_nothing_is_saved(): void
    {
        $queries = $this->searchesOnThePage();
        $queries['opportunities'] = str_repeat('subject:x OR ', 300);

        $this->save($queries)->assertSessionHasErrors('queries.opportunities');

        $message = session('errors')->first('queries.opportunities');
        $this->assertStringContainsString('Work offers', $message);
        $this->assertStringContainsString('3,000', $message);
        $this->assertNull(Setting::get('google_client_id'));
        $this->assertNull(Setting::get('gmail_query_opportunities'));
    }

    public function test_a_search_that_isnt_text_is_refused_without_an_error_page(): void
    {
        $queries = $this->searchesOnThePage();
        $queries['hotels'] = ['from:booking.com'];

        $this->save($queries)->assertSessionHasErrors('queries.hotels')->assertRedirect('/settings/integrations');
    }

    private function connectedWith(string $clientId): void
    {
        Setting::put('google_client_id', $clientId);
        Setting::put('google_client_secret', 'old-client-secret');
        Setting::put('gmail_refresh_token', 'old-refresh-token');
        Setting::put('gmail_access_token', 'old-access-token');
        Setting::put('gmail_account', 'alex@example.test');
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);
        Setting::put('gmail_last_result', ['ok' => true, 'message' => 'Gmail connected for alex@example.test.', 'at' => now()->toIso8601String()]);
    }

    public function test_saving_a_new_google_client_removes_the_old_connection_so_connect_gmail_shows(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 200)]);
        $this->connectedWith('999-old.apps.googleusercontent.com');

        $this->save($this->searchesOnThePage())->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'press Connect Gmail'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === 'old-refresh-token');
        $this->assertSame('123-abc.apps.googleusercontent.com', Setting::get('google_client_id'));
        $this->assertSame('new-client-secret', Setting::get('google_client_secret'));
        foreach (['gmail_refresh_token', 'gmail_access_token', 'gmail_account', 'gmail_scopes', 'gmail_last_result'] as $key) {
            $this->assertNull(Setting::get($key), $key);
        }
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.gmail_disconnected')->exists());

        // What the page needs to show Connect Gmail: set up, and not connected.
        $gmail = $this->get('/settings/integrations')->viewData('page')['props']['gmail'];
        $this->assertTrue($gmail['configured']);
        $this->assertFalse($gmail['connected']);
    }

    public function test_saving_the_same_google_client_keeps_the_connection(): void
    {
        Http::fake();
        $this->connectedWith('123-abc.apps.googleusercontent.com');

        $this->save($this->searchesOnThePage(), ['client_secret' => ''])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Gmail settings saved.');

        Http::assertNothingSent();
        $this->assertSame('old-refresh-token', Setting::get('gmail_refresh_token'));
        $this->assertSame('alex@example.test', Setting::get('gmail_account'));
        $this->assertSame('old-client-secret', Setting::get('google_client_secret'));
    }

    public function test_a_refused_save_leaves_the_connection_alone(): void
    {
        Http::fake();
        $this->connectedWith('999-old.apps.googleusercontent.com');
        $queries = $this->searchesOnThePage();
        $queries['opportunities'] = str_repeat('subject:x OR ', 300);

        $this->save($queries)->assertSessionHasErrors('queries.opportunities');

        Http::assertNothingSent();
        $this->assertSame('old-refresh-token', Setting::get('gmail_refresh_token'));
        $this->assertSame('999-old.apps.googleusercontent.com', Setting::get('google_client_id'));
    }

    public function test_a_new_client_before_any_connection_just_saves(): void
    {
        Http::fake();
        Setting::put('google_client_id', '999-old.apps.googleusercontent.com');

        $this->save($this->searchesOnThePage())->assertSessionHasNoErrors()->assertSessionHas('success', 'Gmail settings saved.');

        Http::assertNothingSent();
        $this->assertSame('123-abc.apps.googleusercontent.com', Setting::get('google_client_id'));
    }

    public function test_a_new_client_id_without_its_secret_is_refused_and_changes_nothing(): void
    {
        Http::fake();
        $this->connectedWith('999-old.apps.googleusercontent.com');

        $this->save($this->searchesOnThePage(), ['client_secret' => ''])->assertSessionHasErrors('client_secret');

        $this->assertStringContainsString('needs its own client secret', session('errors')->first('client_secret'));
        Http::assertNothingSent();
        $this->assertSame('999-old.apps.googleusercontent.com', Setting::get('google_client_id'));
        $this->assertSame('old-client-secret', Setting::get('google_client_secret'));
        $this->assertSame('old-refresh-token', Setting::get('gmail_refresh_token'));
    }

    public function test_the_first_client_id_needs_its_secret_too(): void
    {
        $this->save($this->searchesOnThePage(), ['client_secret' => ''])->assertSessionHasErrors('client_secret');

        $this->assertNull(Setting::get('google_client_id'));
    }

    public function test_clearing_the_client_id_removes_the_connection_and_says_so(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 200)]);
        $this->connectedWith('999-old.apps.googleusercontent.com');

        $this->save($this->searchesOnThePage(), ['client_id' => '', 'client_secret' => ''])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Gmail settings saved. With no client ID, the Gmail connection has been removed.');

        $this->assertNull(Setting::get('google_client_id'));
        $this->assertNull(Setting::get('gmail_refresh_token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_the_limit_counts_bytes_so_a_long_non_english_search_stays_a_short_request(): void
    {
        $queries = $this->searchesOnThePage();
        $queries['hotels'] = str_repeat('é', 1600); // 1,600 characters, 3,200 bytes

        $this->save($queries)->assertSessionHasErrors('queries.hotels');
    }
}
