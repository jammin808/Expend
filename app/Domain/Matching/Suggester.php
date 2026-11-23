<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Travel\TravelDays;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Models\TravelItem;
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

    /** Travel that usually has no email, only a statement line: matched to job days by date. */
    public const TRAVEL_PATTERNS = [
        'travel_local' => '/\bTFL\b|TRANSPORT FOR LONDON/i',
        'travel_taxi' => '/\bUBER\b|\bBOLT\b|FREENOW|FREE NOW|ADDISON LEE|\bTAXIS?\b|MINICAB|\bCABS?\b|\bGETT\b/i',
        'travel_rail' => '/TRAINLINE|\bLNER\b|\bGWR\b|AVANTI|THAMESLINK|SOUTHERN RAIL|NORTHERN TRAINS|CROSSCOUNTRY|TRANSPENNINE|SCOTRAIL|\bC2C\b|CHILTERN|GREATER ANGLIA|SOUTHEASTERN|SOUTH WESTERN RAIL|NATIONAL RAIL/i',
        'travel_parking' => '/\bNCP\b|JUSTPARK|RINGGO|PAYBYPHONE|APCOA|Q-PARK|EURO CAR PARKS|DARTFORD CROSSING|CONGESTION CHARGE|\bULEZ\b/i',
    ];

    public function __construct(private AllocationWriter $writer, private TravelDays $days) {}

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
            } elseif ($tx->amount_pence < 0 && ($travel = $this->travelSuggestion($tx, $text))) {
                $made += (int) $this->writer->decide($tx, $travel['lines'], 'matcher', false, $travel['reason']);
            }
        }

        return $made;
    }

    /** TfL, taxis, trains and parking from the statement alone: business on a job's travel days, personal otherwise. */
    private function travelSuggestion(BankTransaction $tx, string $text): ?array
    {
        $code = null;
        foreach (self::TRAVEL_PATTERNS as $category => $pattern) {
            if (preg_match($pattern, $text) && ! preg_match('/UBER\s*\*?\s*EATS|DELIVEROO|JUST EAT/i', $text)) {
                $code = $category;
                break;
            }
        }
        if (! $code || TravelItem::query()->where('bank_transaction_id', $tx->id)->exists()) {
            return null;
        }

        // TfL adds up a day's contactless journeys and charges them the next day, so the travel day is the day before.
        $date = $code === 'travel_local' ? $tx->effectiveDate()->copy()->subDay() : $tx->effectiveDate();
        $day = $this->days->day($date->format('Y-m-d'));
        $what = ['travel_local' => 'TfL', 'travel_taxi' => 'Taxi or ride', 'travel_rail' => 'Train ticket', 'travel_parking' => 'Parking or road charge'][$code];
        $when = $date->format('D j M Y');

        if ($day['verdict'] === 'personal') {
            return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => "{$what} on {$when}: personal, {$day['reason']}"];
        }
        $category = Category::query()->where('code', $code)->value('id');
        $check = $day['verdict'] === 'review' ? 'Check: ' : '';
        $extra = $code === 'travel_rail' ? '. Tickets are often bought in advance, so check the travel date' : '';

        return [
            'lines' => AllocationWriter::lines($tx->amount_pence, 'business_expense', $category, $day['job']?->id),
            'reason' => "{$check}{$what} on {$when}, {$day['reason']}{$extra}",
        ];
    }
}
