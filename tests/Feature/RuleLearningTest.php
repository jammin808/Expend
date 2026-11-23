<?php

namespace Tests\Feature;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RuleLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_identical_decisions_offer_a_rule_that_applies_automatically_and_can_be_undone(): void
    {
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling_repeats.csv');
        app(Importer::class)->commit($account, $path, 'r.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $adobe = BankTransaction::query()->orderBy('posted_on')->get();
        $software = Category::query()->where('code', 'software')->value('id');
        $decide = fn (BankTransaction $t) => $this->post("/items/bank/{$t->id}/decide", ['kind' => 'business_expense', 'category_id' => $software]);

        $decide($adobe[0])->assertSessionMissing('rule_offer');
        $decide($adobe[1])->assertSessionHas('rule_offer', fn ($o) => str_contains($o['text'], 'Adobe') && str_contains($o['text'], 'the 2 waiting'));
        $this->assertContains('rules', array_keys(TodoList::build()['counts']));

        $this->post('/rules/learn', ['merchant_key' => $adobe[0]->merchant_key])->assertSessionHas('rule_undo', fn ($u) => $u['applied'] === 2);
        $rule = MerchantRule::query()->sole();
        $this->assertTrue($rule->auto_confirm);
        foreach ([$adobe[2], $adobe[3]] as $t) {
            $a = $t->fresh()->allocations()->sole();
            $this->assertSame(['business_expense', $software, 'rule', true], [$a->kind, $a->category_id, $a->decided_by, $a->confirmed_at !== null], 'Applied as a decision by your rule');
        }
        $this->assertNotContains('rules', array_keys(TodoList::build()['counts']), 'Not offered again');

        // Undo: the rule goes, and what it decided is back in the Inbox; your own decisions stay.
        $this->post("/rules/{$rule->id}/undo")->assertSessionHas('success', fn ($m) => str_contains($m, '2 payments'));
        $this->assertSame(0, MerchantRule::query()->count());
        $this->assertSame('unreviewed', $adobe[3]->fresh()->review_status);
        $this->assertSame('you', $adobe[0]->fresh()->allocations()->sole()->decided_by);

        // Declined: never offered again.
        $this->post('/rules/learn/decline', ['merchant_key' => $adobe[0]->merchant_key]);
        $this->assertNotContains('rules', array_keys(TodoList::build()['counts']));
    }
}
