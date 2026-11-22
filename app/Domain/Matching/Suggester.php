<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Pre-fills suggestions for items you haven't decided. Never touches anything you've set or confirmed
 * (AllocationWriter enforces that), and only confirms on its own when you switched a rule to auto-confirm.
 */
final class Suggester
{
    private const HMRC_PATTERN = '/\bHMRC\b|H\s?M\s?R\s?C|SELF ASSESSMENT|INLAND REVENUE/i';

    private const PENALTY_PATTERN = '/\bPCN\b|PENALTY CHARGE|PARKING FINE/i';

    private const CAR_HIRE_PATTERN = '/\b(ENTERPRISE RENT|ENTERPRISE CAR|HERTZ|EUROPCAR|AVIS|SIXT|BUDGET RENT|THRIFTY|ZIPCAR|ENTERPRISE CLUB|GREEN MOTION|ARNOLD CLARK RENTAL|NATIONAL CAR|ALAMO|DOLLAR RENT|CAR HIRE|CAR RENTAL|RENTALCARS)\b/i';

    public function __construct(private AllocationWriter $writer) {}

    /** @param Collection<int, BankTransaction> $transactions */
    public function suggestFor(Collection $transactions): int
    {
        $rules = MerchantRule::query()->get()->keyBy('merchant_key');
        $penalty = Category::query()->where('code', 'travel_penalties')->value('id');
        $carHire = Category::query()->where('code', 'travel_car_hire')->value('id');
        $made = 0;

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();

        $turnover = Category::query()->where('code', 'turnover')->value('id');
        $openInvoices = Invoice::query()->where('status', 'issued')->where('currency', 'GBP')->get();

        foreach ($transactions as $tx) {
            if (in_array($tx->tax_year, $locked, true) || $tx->allocations()->exists()) {
                continue;
            }

            $text = $tx->description.' '.$tx->counterparty;

            if ($tx->amount_pence > 0 && $turnover) {
                $invoice = $openInvoices->first(fn ($i) => $i->total_minor === $tx->amount_pence && $i->paidMinor() === 0
                    && (! $i->issue_date || $tx->posted_on->gte($i->issue_date->copy()->subDays(3))));
                if ($invoice) {
                    $lines = AllocationWriter::lines($tx->amount_pence, 'business_income', $turnover, $invoice->work_job_id);
                    $lines[0]['invoice_id'] = $invoice->id;
                    $made += (int) $this->writer->decide($tx, $lines, 'matcher', false, "Matches invoice {$invoice->number} for ".Money::format($invoice->total_minor));

                    continue;
                }
            }

            if ($rule = $rules->get($tx->merchant_key)) {
                $lines = AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, $rule->work_job_id, $rule->business_pct);
                $made += (int) $this->writer->decide($tx, $lines, 'rule', $rule->auto_confirm, "Your rule for {$rule->merchant_key}", $rule->id);
            } elseif ($tx->amount_pence < 0 && preg_match(self::HMRC_PATTERN, $text)) {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');
            } elseif ($tx->amount_pence < 0 && $penalty && preg_match(self::PENALTY_PATTERN, $text)) {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $penalty), 'matcher', false, 'Looks like a fine or penalty charge, which is never allowable');
            } elseif ($tx->amount_pence < 0 && $carHire && preg_match(self::CAR_HIRE_PATTERN, $text)) {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $carHire), 'matcher', false, 'Looks like car hire, usually a business expense. Check it was for work, and link the job');
            }
        }

        return $made;
    }
}
