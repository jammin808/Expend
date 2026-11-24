<?php

namespace App\Domain\Matching;

use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Travel\TravelDays;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    public function __construct(private AllocationWriter $writer, private TravelDays $days, private Categoriser $categoriser) {}

    /**
     * Suggest for each line nobody has decided. $match runs your rules and the specific matchers (an invoice of the
     * same amount, HMRC, fines, car hire, travel); $guess runs the Categoriser (your past decisions, the bank's
     * category, common merchants). An import runs them apart, with transfer pairing in between, so your rules and
     * invoice matches come before pairing and pairing comes before a guess.
     *
     * Money the line itself shows is your own moving (your name as the payer or payee, a savings pot or exchange, a
     * top-up from your own card, a card's bill payment, another account of yours named on it) is never matched to an
     * invoice as income or to HMRC, travel and the like: the transfer passes pair it or suggest it as a transfer, and
     * the invoice is left for the client's payment. A line naming somebody else is never your own money, so a
     * client's payment is still matched. Your rules still apply to it.
     *
     * @param  Collection<int, BankTransaction>  $transactions
     */
    public function suggestFor(Collection $transactions, bool $match = true, bool $guess = true): int
    {
        $rules = MerchantRule::query()->get()->keyBy('merchant_key');
        $penalty = Category::query()->where('code', 'travel_penalties')->value('id');
        $carHire = Category::query()->where('code', 'travel_car_hire')->value('id');
        $made = 0;

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();

        $turnover = Category::query()->where('code', 'turnover')->value('id');
        $openInvoices = Invoice::query()->where('status', 'issued')->where('currency', 'GBP')->get();
        $received = null; // worked out the first time money comes in, below
        OwnAccounts::flush();
        if ($match && $transactions instanceof EloquentCollection) {
            $transactions->loadMissing('account'); // OwnAccounts::reason() reads each line's account
        }

        foreach ($transactions as $tx) {
            if (in_array($tx->tax_year, $locked, true) || $tx->allocations()->exists() || (! $match && $tx->transfer_pair_id)) {
                continue;
            }

            $text = $tx->description.' '.$tx->counterparty;

            if ($match) {
                $own = OwnAccounts::reason($tx) !== null;
                if (! $own && $tx->amount_pence > 0 && $turnover) {
                    // Only an invoice nothing has come in for yet (counted as everywhere else: payments linked to it,
                    // and income recorded for its job).
                    $received ??= InvoicePayments::received($openInvoices);
                    $invoice = $openInvoices->first(fn ($i) => $i->total_minor === $tx->amount_pence && ($received[$i->id]['paid'] ?? 0) === 0
                        && (! $i->issue_date || $tx->posted_on->gte($i->issue_date->copy()->subDays(3))));
                    if ($invoice) {
                        $lines = AllocationWriter::lines($tx->amount_pence, 'business_income', $turnover, $invoice->work_job_id);
                        $lines[0]['invoice_id'] = $invoice->id;
                        $made += (int) $this->writer->decide($tx, $lines, 'matcher', false, "Matches invoice {$invoice->number} for ".Money::format($invoice->total_minor));

                        continue;
                    }
                }

                if (($rule = $rules->get($tx->merchant_key)) && RuleLearner::appliesTo($rule, $tx->amount_pence)) {
                    $lines = AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, $rule->work_job_id, $rule->business_pct);
                    $made += (int) $this->writer->decide($tx, $lines, 'rule', $rule->auto_confirm, "Your rule for {$rule->merchant_key}", $rule->id);

                    continue;
                }
                if (! $own) {
                    $found = null;
                    if ($tx->amount_pence < 0 && preg_match(self::HMRC_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'tax_payment'), 'Looks like a payment to HMRC (personal tax, not a business expense)'];
                    } elseif ($tx->amount_pence < 0 && $penalty && preg_match(self::PENALTY_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'business_expense', $penalty), 'Looks like a fine or penalty charge, which is never allowable'];
                    } elseif ($tx->amount_pence < 0 && $carHire && preg_match(self::CAR_HIRE_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'business_expense', $carHire), 'Looks like car hire, usually a business expense. Check it was for work, and link the job'];
                    } elseif ($tx->amount_pence < 0 && ($travel = $this->travelSuggestion($tx, $text))) {
                        $found = [$travel['lines'], $travel['reason']];
                    }
                    if ($found) {
                        $made += (int) $this->writer->decide($tx, $found[0], 'matcher', false, $found[1]);

                        continue;
                    }
                }
            }

            if ($guess && ($learned = $this->categoriser->propose($tx))) {
                // Learned from your decisions, the bank's category, or a list of common merchants. Marked as the
                // Categoriser's own, so your later decisions can update it (Categoriser::learnFrom).
                try {
                    $made += (int) $this->categoriser->suggest($tx, $learned);
                } catch (\InvalidArgumentException) {
                    // doesn't fit this payment
                }
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
