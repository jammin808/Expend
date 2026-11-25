<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The travel page's decide buttons and the accounts page's "Hide from checks" can be refused by the server. The
 * refusal comes back only as a validation error, which no toast or flash shows, so the page must show it.
 */
class TravelAndAccountsRefusalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    private function sendJson(string $from, string $method, string $url, array $body): TestResponse
    {
        return $this->from($from)->call($method, $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** Every call to $start in $source, from its name to its closing bracket (strings and comments skipped). */
    private function calls(string $source, string $start): array
    {
        $found = [];
        $offset = 0;
        while (($at = strpos($source, $start, $offset)) !== false) {
            $depth = 0;
            $quote = null;
            for ($i = strpos($source, '(', $at); $i < strlen($source); $i++) {
                $c = $source[$i];
                if ($quote === null && $c === '/' && in_array($source[$i + 1] ?? '', ['/', '*'], true)) {
                    $end = strpos($source, $source[$i + 1] === '/' ? "\n" : '*/', $i + 2);
                    $i = $end === false ? strlen($source) : $end;

                    continue;
                }
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

    public function test_a_refused_travel_decision_says_why(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-06-11', 'ends_on' => '2025-06-12']);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'P123', 'starts_on' => '2025-06-10',
            'ends_on' => '2025-06-13', 'amount_minor' => 27000, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data']);

        // What "Part business" sends with the nights box cleared (Number('') is 0) or typed as a half, and a long note.
        foreach ([['business_nights' => 0], ['business_nights' => 1.5], ['business_nights' => 2, 'note' => str_repeat('Early start. ', 40)]] as $sent) {
            $body = $sent + ['decision' => 'mixed', 'job_id' => $job->id, 'note' => null];
            $this->sendJson('/travel?year=2025-26', 'POST', "/travel/{$stay->id}/decide", $body)
                ->assertSessionHasErrors(array_key_last($sent))->assertSessionMissing('success')->assertSessionMissing('error');
            $this->assertNull($stay->fresh()->decided_at, 'nothing is decided');
        }

        $source = (string) file_get_contents(resource_path('js/pages/travel/index.tsx'));
        $posts = $this->calls($source, 'router.post(');
        $decide = array_values(array_filter($posts, fn ($call) => str_contains($call, '/decide`')));
        $this->assertCount(1, $decide);
        $this->assertStringContainsString('onError', $decide[0], 'a refused decision says so');
        $this->assertStringContainsString('as a whole number', $source, 'in plain words, not "business nights"');
        // Every other button on the page that sends something says so too when it's refused.
        $this->assertCount(5, $posts, 'decide, reopen, scan, check and read again');
        foreach ($posts as $call) {
            $this->assertStringContainsString('onError', $call, $call);
        }
    }

    public function test_hide_from_checks_works_for_a_long_bank_given_name_and_a_refusal_says_why(): void
    {
        // Starling names its accounts itself, and the app keeps the name as it comes, which can be longer than the
        // 80 characters an account's details once saved with.
        $account = Account::query()->create(['name' => 'Starling Alex Smith trading as Acme Events Lighting and Sound Hire Business Account', 'kind' => 'bank', 'provider' => 'starling']);
        $this->assertGreaterThan(80, mb_strlen($account->name));
        $row = collect($this->get('/accounts')->assertOk()->assertInertia(fn ($p) => $p->component('accounts/index', false))->viewData('page')['props']['accounts'])
            ->firstWhere('id', $account->id);

        // What the button sends: the row as the page has it, with last4 as '' and is_active flipped.
        $this->sendJson('/accounts', 'PATCH', "/accounts/{$account->id}", ['last4' => $row['last4'] ?? '', 'is_active' => ! $row['is_active']] + $row)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse($account->fresh()->is_active, 'hidden from checks');

        $source = (string) file_get_contents(resource_path('js/pages/accounts/index.tsx'));
        $calls = [...$this->calls($source, 'router.patch('), ...$this->calls($source, 'router.delete(')];
        $this->assertCount(2, $calls, 'Hide from checks and Remove');
        foreach ($calls as $call) {
            $this->assertStringContainsString('onError', $call, $call);
        }
        $this->assertStringContainsString('255 characters', $source, 'a name too long is said in plain words');
    }
}
