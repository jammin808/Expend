<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use Illuminate\Support\Collection;

/**
 * Pre-fills suggestions for items you haven't decided. Never touches anything you've set or confirmed
 * (AllocationWriter enforces that), and only confirms on its own when you switched a rule to auto-confirm.
 */
final class Suggester
{
    private const HMRC_PATTERN = '/\bHMRC\b|H\s?M\s?R\s?C|SELF ASSESSMENT|INLAND REVENUE/i';

    private const PENALTY_PATTERN = '/\bPCN\b|PENALTY CHARGE|PARKING FINE/i';

    public function __construct(private AllocationWriter $writer) {}

    /** @param Collection<int, BankTransaction> $transactions */
    public function suggestFor(Collection $transactions): int
    {
        $rules = MerchantRule::query()->get()->keyBy('merchant_key');
        $penalty = Category::query()->where('code', 'travel_penalties')->value('id');
        $made = 0;

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();

        foreach ($transactions as $tx) {
            if (in_array($tx->tax_year, $locked, true) || $tx->allocations()->exists()) {
                continue;
            }

            $text = $tx->description.' '.$tx->counterparty;

            if ($rule = $rules->get($tx->merchant_key)) {
                $lines = AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, $rule->work_job_id, $rule->business_pct);
                $made += (int) $this->writer->decide($tx, $lines, 'rule', $rule->auto_confirm, "Your rule for {$rule->merchant_key}", $rule->id);
            } elseif ($tx->amount_pence < 0 && preg_match(self::HMRC_PATTERN, $text)) {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');
            } elseif ($tx->amount_pence < 0 && $penalty && preg_match(self::PENALTY_PATTERN, $text)) {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $penalty), 'matcher', false, 'Looks like a fine or penalty charge, which is never allowable');
            }
        }

        return $made;
    }
}
