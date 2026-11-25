<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A refused save must say why, next to the form: no message on the page means the owner presses Save and nothing
 * seems to happen. And Settings › Integrations › Forward by email must save as it loads: the sending addresses once
 * came back longer than they went in, until an untouched save was refused.
 */
class IntegrationSettingsErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function props(): array
    {
        return json_decode(json_encode($this->get('/settings/integrations')->assertOk()->viewData('page')['props']), true);
    }

    /** Send the way the page's useForm does: JSON from Inertia, back to the page. */
    private function send(string $method, string $url, array $body): TestResponse
    {
        return $this->from('/settings/integrations')->call($method, $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** The Forward by email form, filled in as the page fills it. */
    private function emailForm(array $changes = []): array
    {
        $email = $this->props()['email'];

        return $changes + [
            'host' => $email['host'] ?? '', 'port' => (string) ($email['port'] ?? 993), 'username' => $email['username'] ?? '',
            'password' => '', 'allowed' => $email['allowed'] ?? '', 'test' => false,
        ];
    }

    private function aiForm(array $changes = []): array
    {
        $ai = $this->props()['ai'];

        return $changes + [
            'api_key' => '', 'model' => $ai['model'], 'effort' => $ai['effort'], 'cap_usd' => (string) ($ai['cap_usd'] ?? 40),
            'suggestions' => $ai['suggestions'], 'extraction' => $ai['extraction'] ?? 'on_demand', 'web_research' => $ai['web_research'],
        ];
    }

    private function businessForm(array $changes = []): array
    {
        $b = $this->props()['business'];

        return $changes + [
            'name' => $b['name'] ?? '', 'address' => $b['address'] ?? '', 'email' => $b['email'] ?? '', 'bank' => $b['bank'] ?? '',
            'prefix' => $b['prefix'] ?? 'INV-', 'terms' => (string) ($b['terms'] ?? 30),
        ];
    }

    public function test_sending_addresses_typed_without_spaces_save_again_untouched_near_the_limit(): void
    {
        $addresses = array_map(fn ($i) => "alex{$i}@example.test", range(1, 50));
        $typed = implode(',', $addresses);
        $this->assertLessThanOrEqual(1000, mb_strlen($typed));
        $this->assertGreaterThan(980, mb_strlen($typed));

        $this->send('PUT', '/settings/integrations/email', $this->emailForm([
            'host' => 'imap.example.test', 'username' => 'inbox@example.test', 'password' => 'mailbox-password', 'allowed' => $typed,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'Email settings saved.');
        $this->assertSame($addresses, Setting::get('allowed_senders'));

        $shown = $this->props()['email']['allowed'];
        $this->assertLessThanOrEqual(mb_strlen($typed), mb_strlen($shown), 'the list comes back no longer than it was typed');

        // Saved again exactly as the page shows it: accepted, and it shows the same again.
        $this->send('PUT', '/settings/integrations/email', $this->emailForm())
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Email settings saved.');
        $this->assertSame($addresses, Setting::get('allowed_senders'));
        $this->assertSame($shown, $this->props()['email']['allowed']);
        $this->assertSame('mailbox-password', Setting::get('imap_password'), 'an empty password box keeps the saved password');
    }

    public function test_sending_addresses_with_spaces_and_capitals_show_as_saved_and_never_change_on_resave(): void
    {
        $this->send('PUT', '/settings/integrations/email', $this->emailForm(['allowed' => ' Alex@Example.test,  alex.smith@gmail.test , ']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['alex@example.test', 'alex.smith@gmail.test'], Setting::get('allowed_senders'));
        $shown = $this->props()['email']['allowed'];

        foreach (range(1, 3) as $ignored) {
            $this->send('PUT', '/settings/integrations/email', $this->emailForm())->assertSessionHasNoErrors();
            $this->assertSame($shown, $this->props()['email']['allowed']);
        }
        $this->assertSame(['alex@example.test', 'alex.smith@gmail.test'], Setting::get('allowed_senders'));
    }

    public function test_a_refused_ai_save_says_which_box_in_plain_words_and_saves_nothing(): void
    {
        $effort = Setting::get('ai_effort') === 'high' ? 'low' : 'high';
        $this->send('PUT', '/settings/integrations/ai', $this->aiForm(['cap_usd' => '£40', 'effort' => $effort]))
            ->assertSessionHasErrors('cap_usd')->assertSessionMissing('success');

        $message = session('errors')->first('cap_usd');
        $this->assertStringContainsString('monthly spending cap', $message);
        $this->assertStringNotContainsString('cap usd', $message);
        $this->assertNotSame($effort, Setting::get('ai_effort'), 'nothing is saved');
    }

    public function test_a_refused_business_save_says_which_boxes_in_plain_words_and_saves_nothing(): void
    {
        $this->send('PUT', '/settings/integrations/business', $this->businessForm([
            'name' => 'Acme Events', 'prefix' => 'INV 2026-', 'terms' => '30 days', 'email' => 'alex at example.test',
        ]))->assertSessionHasErrors(['prefix', 'terms', 'email'])->assertSessionMissing('success');

        $errors = session('errors');
        $this->assertStringContainsString('invoice number prefix', $errors->first('prefix'));
        $this->assertStringContainsString('letters, numbers', $errors->first('prefix'));
        $this->assertStringContainsString('payment terms', $errors->first('terms'));
        $this->assertStringContainsString('whole number of days', $errors->first('terms'));
        $this->assertNull(Setting::get('business_name'), 'nothing is saved');
    }

    public function test_a_refused_save_and_check_now_says_why_and_does_not_check(): void
    {
        $this->send('PUT', '/settings/integrations/email', $this->emailForm([
            'host' => 'imap.example.test', 'port' => '993 (SSL)', 'username' => 'inbox@example.test', 'test' => true,
        ]))->assertSessionHasErrors('port')->assertSessionMissing('success')
            // A check would have said "Couldn't connect" or "Add the forwarding mailbox details".
            ->assertSessionMissing('error');

        $this->assertStringContainsString('993', session('errors')->first('port'));
        $this->assertNull(Setting::get('imap_host'), 'nothing is saved');
    }

    public function test_import_from_a_date_refuses_a_bad_date_in_plain_words(): void
    {
        foreach (['2099-01-01' => 'before today', 'not a date' => 'YYYY-MM-DD', '2010-01-01' => '2015'] as $from => $says) {
            $this->from('/settings/integrations')->post('/settings/starling/sync', ['from' => $from])
                ->assertSessionHasErrors('from')->assertSessionMissing('success');
            $message = session('errors')->first('from');
            $this->assertStringContainsString($says, $message, $from);
            $this->assertStringNotContainsString('from field', $message);
        }
    }

    /** Every call to $start in $source, from its name to its closing bracket. */
    private function calls(string $source, string $start): array
    {
        $found = [];
        $offset = 0;
        while (($at = strpos($source, $start, $offset)) !== false) {
            $open = strpos($source, '(', $at);
            $depth = 0;
            $quote = null;
            for ($i = $open; $i < strlen($source); $i++) {
                $c = $source[$i];
                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if (in_array($c, ["'", '"', '`'], true)) {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    break;
                }
            }
            $found[] = substr($source, $at, $i - $at + 1);
            $offset = $i;
        }

        return $found;
    }

    public function test_every_form_on_the_integrations_page_shows_what_was_refused(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/settings/integrations.tsx'));

        preg_match_all('/const (\w+) = useForm\(/', $source, $forms);
        $this->assertEqualsCanonicalizing(['starlingForm', 'aiForm', 'biz', 'mail', 'tg', 'send', 'gm'], $forms[1]);
        foreach ($forms[1] as $form) {
            $this->assertSame(1, preg_match('/errors=\{'.$form.'\.errors\}|Object\.values\('.$form.'\.errors\)/', $source), "{$form} shows its errors");
        }

        // "Save and check now" goes through the same form as Save, so what's refused shows under it too.
        $this->assertSame([], $this->calls($source, "router.put('/settings/integrations/email'"));

        // The Starling buttons aren't forms: a refusal (a date that isn't one, say) is shown as a message.
        $starling = collect([...$this->calls($source, 'router.post('), ...$this->calls($source, 'router.patch(')])
            ->filter(fn ($call) => str_contains($call, "'/settings/starling"));
        $this->assertCount(3, $starling, 'Import now, Import from a date and the account each Starling account goes into');
        foreach ($starling as $call) {
            $this->assertStringContainsString('onError', $call, $call);
        }
    }

    public function test_reason_prompts_on_the_year_and_import_pages_show_what_was_refused(): void
    {
        $year = (string) file_get_contents(resource_path('js/pages/years/show.tsx'));
        $lock = $this->calls($year, 'router.post(`/years/${year.label}/lock`');
        // Unlock, lock with failing checks (a reason is asked for), and lock: each says why when it's refused.
        $this->assertCount(3, $lock);
        foreach ($lock as $call) {
            $this->assertStringContainsString('onError', $call, $call);
        }

        $imports = (string) file_get_contents(resource_path('js/pages/imports/index.tsx'));
        $asks = $this->calls($imports, 'router.post(`/imports/${b.id}/${action}`');
        $this->assertCount(1, $asks);
        $this->assertStringContainsString('onError', $asks[0]);
        // A file that can't be read (not a CSV, or too big) says so rather than going quiet.
        $upload = collect($this->calls($imports, 'router.post('))->filter(fn ($c) => str_contains($c, 'forceFormData'));
        $this->assertCount(1, $upload);
        $this->assertStringContainsString('onError', $upload->first());
    }
}
