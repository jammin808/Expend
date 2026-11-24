<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Learns from your decisions. Once you've decided the same merchant the same way at least twice (and never another
 * way), it offers "always do this". Accepting saves a rule that applies automatically, as a decision by your rule
 * (not as one of yours), to what's waiting from that merchant and to each new statement; it can be undone in one
 * go. The job is never part of a rule, since that changes from payment to payment.
 *
 * A rule keeps to the direction it was learned from: one learned from payments to a crew-mate is never applied to
 * money they pay you (that's turnover, not a cost). And it only takes over what's undecided or guessed in general:
 * a payment matched to an invoice, a job, a stay or journey, or a transfer between your accounts is left for you.
 */
final class RuleLearner
{
    /** How many of a merchant's latest decided payments are looked at. */
    private const LOOK_BACK = 12;

    public function __construct(private AllocationWriter $writer) {}

    /** Which way the money went in the decisions a rule was learned from. A rule with no direction applies both ways. */
    public const DIRECTIONS = ['out' => 'money out', 'in' => 'money in'];

    /** Whether a rule may decide a payment of this amount: a learned rule only applies the way it was learned. */
    public static function appliesTo(MerchantRule $rule, int $amountPence): bool
    {
        return self::goesWay($rule->getAttribute('direction'), $amountPence);
    }

    /**
     * @return array{merchant_key:string, name:string, count:int, waiting:int, kind:string, category_id:?int, business_pct:int, direction:?string, text:string, example_id:int}|null
     */
    public function offer(string $merchantKey): ?array
    {
        if ($merchantKey === '' || $merchantKey === 'UNKNOWN' || MerchantRule::query()->where('merchant_key', $merchantKey)->exists()
            || in_array($merchantKey, (array) Setting::get('rule_offers_declined'), true)) {
            return null;
        }
        $latest = BankTransaction::query()->where('merchant_key', $merchantKey)->where('review_status', 'confirmed')
            ->with('allocations')->latest('posted_on')->latest('id')->limit(self::LOOK_BACK)->get();

        return $this->offerFrom($merchantKey, $latest, fn (?string $direction, string $kind) => $this->waiting($merchantKey, $direction, $kind)->count(),
            fn (int $id) => Category::query()->find($id)?->name);
    }

    /**
     * Merchants ready for a rule, most decided first. Worked out for all of them together (the to-do list asks for
     * every one), in a handful of queries however many merchants there are.
     *
     * @return list<array> as offer() gives
     */
    public function offers(int $limit = 10): array
    {
        $declined = (array) Setting::get('rule_offers_declined');
        $keys = BankTransaction::query()->where('review_status', 'confirmed')
            ->whereNotIn('merchant_key', MerchantRule::query()->select('merchant_key'))->whereNotIn('merchant_key', [...$declined, '', 'UNKNOWN'])
            ->groupBy('merchant_key')->havingRaw('COUNT(*) >= 2')->orderByRaw('COUNT(*) DESC')->limit(60)->pluck('merchant_key')
            ->filter(fn ($k) => is_string($k) && $k !== '')->values();
        if ($keys->isEmpty() || $limit <= 0) {
            return [];
        }

        // The latest few decided payments of each merchant, as offer() looks at: one query, then their lines.
        $ids = $keys->map(fn (string $k) => DB::query()->fromSub(
            BankTransaction::query()->where('merchant_key', $k)->where('review_status', 'confirmed')->orderByDesc('posted_on')->orderByDesc('id')->limit(self::LOOK_BACK)->select('id'),
            'latest'
        )->select('id'))->reduce(fn ($union, $q) => $union ? $union->unionAll($q) : $q);
        $latest = BankTransaction::query()->whereIn('id', $ids->pluck('id'))->with('allocations')
            ->orderByDesc('posted_on')->orderByDesc('id')->get()->groupBy('merchant_key');

        // What's waiting from each, with whether a transfer was suggested for it (left out unless the rule is one).
        $waiting = $this->waitingAny()->whereIn('merchant_key', $keys)->select(['id', 'merchant_key', 'amount_pence'])
            ->withExists(['allocations as has_transfer' => fn ($a) => $a->where('kind', 'transfer')])->get()->groupBy('merchant_key');
        $categories = Category::query()->pluck('name', 'id');

        $offers = [];
        foreach ($keys as $key) {
            $offer = $this->offerFrom($key, $latest[$key] ?? collect(), fn (?string $direction, string $kind) => ($waiting[$key] ?? collect())
                ->filter(fn (BankTransaction $t) => self::goesWay($direction, $t->amount_pence) && ($kind === 'transfer' || ! $t->has_transfer))->count(),
                fn (int $id) => $categories[$id] ?? null);
            if ($offer) {
                $offers[] = $offer;
                if (count($offers) >= $limit) {
                    break;
                }
            }
        }

        return $offers;
    }

    /**
     * The offer for a merchant from its latest decided payments, if they were all decided by you the same way.
     *
     * @param  Collection<int, BankTransaction>  $latest  its latest confirmed payments, newest first, with their lines
     * @param  callable(?string, string): int  $waitingCount  how many are waiting that a rule this way, of this kind, would decide
     * @param  callable(int): ?string  $categoryName
     */
    private function offerFrom(string $merchantKey, Collection $latest, callable $waitingCount, callable $categoryName): ?array
    {
        // Decided by you, or a suggestion you accepted (as the Categoriser learns): never what a rule decided.
        $decided = $latest->filter(fn (BankTransaction $t) => $t->allocations->isNotEmpty() && $t->allocations->every(fn (Allocation $a) => $a->decided_by === 'you'
            || ($a->confirmed_at !== null && in_array($a->decided_by, ['matcher', 'ai'], true))))->values();
        if ($decided->count() < 2) {
            return null;
        }
        $shapes = $decided->map(fn (BankTransaction $t) => self::shape($t))->unique(fn ($s) => json_encode($s));
        if ($shapes->count() !== 1 || $shapes->first() === null) {
            return null; // decided differently at least once: no rule to learn
        }
        $shape = $shapes->first();
        $example = $decided->first();
        $directions = $decided->map(fn (BankTransaction $t) => $t->amount_pence < 0 ? 'out' : 'in')->unique();
        $direction = $directions->count() === 1 ? $directions->first() : null;
        $waiting = $waitingCount($direction, $shape['kind']);
        $category = $shape['category_id'] ? $categoryName((int) $shape['category_id']) : null;
        $what = (Allocation::KINDS[$shape['kind']] ?? $shape['kind']).($category ? ': '.$category : '').($shape['business_pct'] < 100 ? " ({$shape['business_pct']}% business)" : '');
        $name = $example->counterparty ?: $merchantKey;
        $whose = match ($direction) {
            'out' => "payments to {$name}",
            'in' => "money from {$name}",
            default => $name,
        };

        return $shape + [
            'merchant_key' => $merchantKey, 'name' => $name, 'count' => $decided->count(), 'waiting' => $waiting, 'example_id' => $example->id,
            'direction' => $direction,
            'text' => "You've marked {$whose} as {$what} {$decided->count()} times. Always do this".($waiting ? " (and apply it to the {$waiting} waiting)" : '').'?',
        ];
    }

    /** Whether a payment of this amount goes the way given ('out', 'in', or either). */
    private static function goesWay(?string $direction, int $amountPence): bool
    {
        return match ($direction) {
            'out' => $amountPence < 0,
            'in' => $amountPence > 0,
            default => true,
        };
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
            $rule = MerchantRule::query()->forceCreate([
                'merchant_key' => $merchantKey, 'kind' => $offer['kind'], 'category_id' => $offer['category_id'],
                'work_job_id' => null, 'business_pct' => $offer['business_pct'], 'auto_confirm' => true, 'direction' => $offer['direction'],
            ]);
            AuditLog::record('rule.learned', $rule, null, $rule->toArray(), "Learned from {$offer['count']} of your decisions"
                .($offer['direction'] ? ' ('.self::DIRECTIONS[$offer['direction']].' only)' : ''));
            $applied = 0;
            foreach ($this->waiting($merchantKey, $offer['direction'], $offer['kind'])->get() as $tx) {
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

    /**
     * What's waiting from a merchant that a new rule may decide: undecided, or only guessed in general (by the bank's
     * category, a list of common merchants, the AI...), the same way round as the rule, in an open year. Left for you:
     * anything matched to an invoice or a job, a stay's or journey's payment (split night by night), and money moving
     * between your own accounts.
     */
    private function waiting(string $merchantKey, ?string $direction, string $kind): Builder
    {
        return $this->waitingAny()->where('merchant_key', $merchantKey)
            ->when($direction === 'out', fn ($q) => $q->where('amount_pence', '<', 0))
            ->when($direction === 'in', fn ($q) => $q->where('amount_pence', '>', 0))
            ->when($kind !== 'transfer', fn ($q) => $q->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer')));
    }

    /** What's waiting that a new rule may decide, from any merchant, whichever way round and of whatever kind (see waiting()). */
    private function waitingAny(): Builder
    {
        return BankTransaction::query()->whereIn('review_status', ['unreviewed', 'suggested'])
            ->whereNull('transfer_pair_id')
            ->whereNotIn('tax_year', TaxYear::query()->whereNotNull('locked_at')->select('label'))
            ->whereNotIn('id', TravelItem::query()->whereNotNull('bank_transaction_id')->select('bank_transaction_id'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)
                ->orWhereNotNull('invoice_id')->orWhereNotNull('work_job_id')));
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

        // A business and personal split typed by amounts may carry no share (or 100%): its share is then the
        // business amount's part of the whole.
        $pct = (int) ($main->business_use_pct ?? 100);
        $total = (int) $t->allocations->sum('amount_pence');
        if (count($kinds) === 2 && $main->kind !== 'personal' && $total !== 0 && ($pct <= 0 || $pct >= 100)) {
            $pct = max(1, min(99, (int) round(100 * (int) $main->amount_pence / $total)));
        }

        return ['kind' => $main->kind, 'category_id' => $main->category_id, 'business_pct' => $pct];
    }
}
