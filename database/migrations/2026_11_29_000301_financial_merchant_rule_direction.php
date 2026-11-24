<?php

use App\Domain\Audit\AuditLog;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule learned from your decisions ("Always do this") applied to money in and money out alike. So a rule learned
 * from paying a crew-mate twice also confirmed the gig fee they paid you as a (negative) cost: turnover understated,
 * and confirmed, so it never showed in the Inbox.
 *
 * Rules now record which way the money went in the decisions they were learned from: 'out', 'in', or empty for both
 * ways (a rule you saved yourself, or one learned from decisions both ways).
 *
 * For each rule learned before this, the direction is worked out from your decisions that match it, and whatever it
 * decided the other way round is put back in the Inbox for you to decide, with the reason in the history. Your own
 * decisions, rules you saved yourself, and locked years are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('merchant_rules', 'direction')) {
            Schema::table('merchant_rules', function (Blueprint $table) {
                $table->string('direction', 3)->nullable()->after('business_pct');
            });
        }

        $learned = DB::table('audit_events')->where('action', 'rule.learned')->where('subject_type', 'MerchantRule')->pluck('subject_id');
        foreach (MerchantRule::query()->whereIn('id', $learned)->whereNull('direction')->get() as $rule) {
            $direction = $this->learnedDirection($rule);
            if ($direction === null) {
                continue;
            }
            $rule->forceFill(['direction' => $direction])->saveQuietly();
            $this->putBackTheOtherWay($rule, $direction);
        }
    }

    /** 'out' or 'in' when every decision of yours that matches the rule went that way; null when they went both ways. */
    private function learnedDirection(MerchantRule $rule): ?string
    {
        $directions = BankTransaction::query()->where('merchant_key', $rule->merchant_key)->where('review_status', 'confirmed')->with('allocations')->get()
            ->filter(fn (BankTransaction $t) => $t->allocations->isNotEmpty() && $t->allocations->every(fn (Allocation $a) => $a->decided_by === 'you'))
            ->filter(function (BankTransaction $t) use ($rule) {
                $main = $t->allocations->firstWhere('kind', '!=', 'personal') ?? $t->allocations->first();

                return $main->kind === $rule->kind && (int) $main->category_id === (int) $rule->category_id;
            })
            ->map(fn (BankTransaction $t) => $t->amount_pence < 0 ? 'out' : 'in')->unique();

        return $directions->count() === 1 ? $directions->first() : null;
    }

    private function putBackTheOtherWay(MerchantRule $rule, string $direction): void
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $ids = Allocation::query()->where('merchant_rule_id', $rule->id)->where('decided_by', 'rule')->distinct()->pluck('bank_transaction_id')->filter();
        $wrongWay = BankTransaction::query()->whereIn('id', $ids)->where('amount_pence', $direction === 'out' ? '>' : '<', 0)
            ->whereNotIn('tax_year', $locked)->with('allocations')->get(); // a locked year keeps its figures
        foreach ($wrongWay as $tx) {
            if (! $tx->allocations->every(fn (Allocation $a) => $a->decided_by === 'rule' && (int) $a->merchant_rule_id === (int) $rule->id)) {
                continue; // changed since by you or another matcher: left as it is
            }
            DB::transaction(function () use ($tx, $rule, $direction) {
                $before = $tx->allocations->map(fn (Allocation $a) => $a->only(['kind', 'category_id', 'work_job_id', 'amount_pence', 'business_use_pct', 'decided_by', 'is_manual', 'note'])
                    + ['confirmed' => $a->confirmed_at !== null])->all();
                $tx->allocations()->delete();
                $tx->forceFill(['review_status' => 'unreviewed'])->saveQuietly();
                AuditLog::record('allocation.cleared', $tx, $before, null, 'Your rule for '.($tx->counterparty ?: $rule->merchant_key).' was learned from '
                    .($direction === 'out' ? 'payments out' : 'money in').', so it shouldn’t have decided this. Back in the Inbox for you to decide.', 'system');
            });
        }
    }

    public function down(): void
    {
        // Decisions put back in the Inbox aren't re-applied: you may have decided them since.
        if (Schema::hasColumn('merchant_rules', 'direction')) {
            Schema::table('merchant_rules', function (Blueprint $table) {
                $table->dropColumn('direction');
            });
        }
    }
};
