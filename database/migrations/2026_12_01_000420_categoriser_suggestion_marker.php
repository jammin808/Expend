<?php

use App\Domain\Audit\AuditLog;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which matcher made a suggestion. The Categoriser marks its own ("categoriser"), so learning from your decisions
 * only ever updates its own guesses: never an HMRC, fine, car-hire, travel or invoice suggestion that happens to be
 * worded the same way ("Looks like ...").
 *
 * The Categoriser's guesses waiting from before were made without your rules and open invoices (the nightly pass
 * ran it on its own), and with mistakes since fixed: a fee matching an invoice suggested as turnover without the
 * invoice, a phone bill as wholly business, a lunch at home as business because of one while working away. They
 * are put back to undecided, with the reason in the history, and the suggestion pass that runs straight after the
 * migration (and every night) suggests them again through the whole chain. Only unconfirmed guesses are touched,
 * known by wording only the Categoriser used; anything you decided or confirmed, any other matcher's suggestion,
 * and locked years stay exactly as they are.
 */
return new class extends Migration
{
    /** Reasons only the Categoriser wrote: what it learned from you, and the bank's category. */
    private const OWN_REASONS = ['Like %you decided%', 'You usually decide the bank%', 'The bank calls it%', 'Check: The bank calls it%'];

    /** Its list of common merchants, as "Looks like <what>" (the Suggester's HMRC, fine and car-hire reasons aren't here). */
    private const OWN_HINTS = [
        'kit, cable and consumables', 'software or a subscription', 'phone or internet', 'business insurance',
        'a trade body, licence or course', 'postage', 'a hotel', 'food or drink', 'fuel or a service station',
        'a supermarket', 'streaming or games', 'a gym', 'a bank or card charge',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('allocations', 'matcher')) {
            Schema::table('allocations', function (Blueprint $table) {
                $table->string('matcher', 32)->nullable()->after('decided_by');
            });
        }

        $patterns = self::OWN_REASONS;
        foreach (self::OWN_HINTS as $what) {
            $patterns[] = 'Looks like '.$what.'%';
            $patterns[] = 'Check: Looks like '.$what.'%';
        }
        $guess = fn ($q) => $q->where('decided_by', 'matcher')->whereNull('confirmed_at')->where('is_manual', false)->whereNull('matcher')
            ->where(function ($q) use ($patterns) {
                foreach ($patterns as $p) {
                    $q->orWhere('reason', 'like', $p);
                }
            });
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $ids = Allocation::query()->where($guess)->whereNotNull('bank_transaction_id')->distinct()->pluck('bank_transaction_id');

        foreach ($ids->chunk(200) as $chunk) {
            $txs = BankTransaction::query()->whereIn('id', $chunk)->where('review_status', '!=', 'confirmed')->whereNotIn('tax_year', $locked)->with('allocations')->get();
            foreach ($txs as $tx) {
                $mine = Allocation::query()->where('bank_transaction_id', $tx->id)->where($guess)->count();
                if ($mine === 0 || $mine !== $tx->allocations->count()) {
                    continue; // partly something else: left as it is
                }
                DB::transaction(function () use ($tx) {
                    $before = $tx->allocations->map(fn (Allocation $a) => $a->only(['kind', 'category_id', 'work_job_id', 'amount_pence', 'business_use_pct', 'decided_by', 'is_manual', 'note'])
                        + ['confirmed' => false])->all();
                    $tx->allocations()->delete();
                    $tx->forceFill(['review_status' => 'unreviewed'])->saveQuietly();
                    AuditLog::record('allocation.cleared', $tx, $before, null,
                        'An earlier guess, made without your rules and invoices: suggested again through the whole suggestion pass', 'system');
                });
            }
        }
    }

    public function down(): void
    {
        // Guesses put back to undecided aren't restored: they're suggested again by the nightly pass.
        if (Schema::hasColumn('allocations', 'matcher')) {
            Schema::table('allocations', function (Blueprint $table) {
                $table->dropColumn('matcher');
            });
        }
    }
};
