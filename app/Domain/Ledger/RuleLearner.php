<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Learns from your decisions. Once you've decided the same merchant the same way at least twice (and never another
 * way), it offers "always do this". Accepting saves a rule that applies automatically, as a decision by your rule
 * (not as one of yours), to everything waiting from that merchant and to each new statement; it can be undone in one
 * go. The job is never part of a rule, since that changes from payment to payment.
 */
final class RuleLearner
{
    public function __construct(private AllocationWriter $writer) {}

    /**
     * @return array{merchant_key:string, name:string, count:int, waiting:int, kind:string, category_id:?int, business_pct:int, text:string, example_id:int}|null
     */
    public function offer(string $merchantKey): ?array
    {
        if ($merchantKey === '' || $merchantKey === 'UNKNOWN' || MerchantRule::query()->where('merchant_key', $merchantKey)->exists()
            || in_array($merchantKey, (array) Setting::get('rule_offers_declined'), true)) {
            return null;
        }
        $decided = BankTransaction::query()->where('merchant_key', $merchantKey)->where('review_status', 'confirmed')
            ->with('allocations')->latest('posted_on')->limit(12)->get()
            ->filter(fn (BankTransaction $t) => $t->allocations->isNotEmpty() && $t->allocations->every(fn (Allocation $a) => $a->decided_by === 'you'));
        if ($decided->count() < 2) {
            return null;
        }
        $shapes = $decided->map(fn (BankTransaction $t) => self::shape($t))->unique(fn ($s) => json_encode($s));
        if ($shapes->count() !== 1 || $shapes->first() === null) {
            return null; // decided differently at least once: no rule to learn
        }
        $shape = $shapes->first();
        $example = $decided->first();
        $waiting = BankTransaction::query()->where('merchant_key', $merchantKey)->whereIn('review_status', ['unreviewed', 'suggested'])->count();
        $category = $shape['category_id'] ? Category::query()->find($shape['category_id'])?->name : null;
        $what = (Allocation::KINDS[$shape['kind']] ?? $shape['kind']).($category ? ': '.$category : '').($shape['business_pct'] < 100 ? " ({$shape['business_pct']}% business)" : '');
        $name = $example->counterparty ?: $merchantKey;

        return $shape + [
            'merchant_key' => $merchantKey, 'name' => $name, 'count' => $decided->count(), 'waiting' => $waiting, 'example_id' => $example->id,
            'text' => "You've marked {$name} as {$what} {$decided->count()} times. Always do this".($waiting ? " (and apply it to the {$waiting} waiting)" : '').'?',
        ];
    }

    /** @return list<array> merchants ready for a rule, most decided first */
    public function offers(int $limit = 10): array
    {
        $declined = (array) Setting::get('rule_offers_declined');
        $keys = BankTransaction::query()->where('review_status', 'confirmed')
            ->whereNotIn('merchant_key', MerchantRule::query()->pluck('merchant_key'))->whereNotIn('merchant_key', $declined ?: [''])
            ->groupBy('merchant_key')->havingRaw('COUNT(*) >= 2')->orderByRaw('COUNT(*) DESC')->limit(60)->pluck('merchant_key');

        return $keys->map(fn ($k) => $this->offer($k))->filter()->take($limit)->values()->all();
    }

    /**
     * Save the rule and apply it to what's waiting from that merchant.
     *
     * @return array{rule:MerchantRule, applied:int}
     */
    public function accept(string $merchantKey): array
    {
        $offer = $this->offer($merchantKey) ?? throw new InvalidArgumentException('There’s no consistent decision to learn from for this merchant yet.');

        return DB::transaction(function () use ($offer, $merchantKey) {
            $rule = MerchantRule::query()->create([
                'merchant_key' => $merchantKey, 'kind' => $offer['kind'], 'category_id' => $offer['category_id'],
                'work_job_id' => null, 'business_pct' => $offer['business_pct'], 'auto_confirm' => true,
            ]);
            AuditLog::record('rule.learned', $rule, null, $rule->toArray(), "Learned from {$offer['count']} of your decisions");
            $applied = 0;
            foreach (BankTransaction::query()->where('merchant_key', $merchantKey)->whereIn('review_status', ['unreviewed', 'suggested'])->get() as $tx) {
                $lines = AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, null, $rule->business_pct);
                try {
                    $applied += (int) $this->writer->decide($tx, $lines, 'rule', true, "Your rule for {$offer['name']}", $rule->id);
                } catch (InvalidArgumentException) {
                    // a locked year, or lines that don't fit this payment: left for you
                }
            }

            return ['rule' => $rule, 'applied' => $applied];
        });
    }

    public function decline(string $merchantKey): void
    {
        Setting::put('rule_offers_declined', array_values(array_unique([...(array) Setting::get('rule_offers_declined'), $merchantKey])));
    }

    /** Remove a rule and put everything it decided (and you haven't changed since) back in the Inbox. */
    public function undo(MerchantRule $rule): int
    {
        return DB::transaction(function () use ($rule) {
            $undone = 0;
            $txIds = Allocation::query()->where('merchant_rule_id', $rule->id)->where('decided_by', 'rule')->distinct()->pluck('bank_transaction_id')->filter();
            foreach (BankTransaction::query()->whereIn('id', $txIds)->with('allocations')->get() as $tx) {
                if ($tx->allocations->every(fn (Allocation $a) => $a->decided_by === 'rule' && $a->merchant_rule_id === $rule->id)) {
                    try {
                        $this->writer->clear($tx, 'Rule undone');
                        $undone++;
                    } catch (InvalidArgumentException) {
                        // a locked year keeps its figures
                    }
                }
            }
            AuditLog::record('rule.undone', $rule, $rule->toArray(), null, "Undid {$undone} decisions");
            Allocation::query()->where('merchant_rule_id', $rule->id)->update(['merchant_rule_id' => null]);
            $rule->delete();

            return $undone;
        });
    }

    /** @return array{kind:string, category_id:?int, business_pct:int}|null the decision, without amounts or job */
    private static function shape(BankTransaction $t): ?array
    {
        $main = $t->allocations->firstWhere('kind', '!=', 'personal') ?? $t->allocations->first();
        $kinds = $t->allocations->pluck('kind')->sort()->values()->all();
        if (count($kinds) > 2 || (count($kinds) === 2 && ! in_array('personal', $kinds, true))) {
            return null; // a split across jobs or kinds: too specific for a rule
        }

        return ['kind' => $main->kind, 'category_id' => $main->category_id, 'business_pct' => (int) ($main->business_use_pct ?? 100)];
    }
}
