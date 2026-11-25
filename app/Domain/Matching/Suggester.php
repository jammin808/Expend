<?php

namespace App\Domain\Matching;

use App\Domain\Income\InvoiceMatcher;
use App\Domain\Income\PayerAliases;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Travel\TravelDays;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Pre-fills suggestions for items you haven't decided. Never touches anything you've set or confirmed
 * (AllocationWriter enforces that), never proposes what you said no to (Intel\Vetoes, also in AllocationWriter), and
 * only confirms on its own when you switched a rule to auto-confirm. A rule's job is used only within that job's dates
 * (ruleJobFor()).
 *
 * Money in (income design v3): which invoice it's for is Income\InvoiceMatcher's (what's still to come, a set of the
 * payer's client's invoices, or with a fee taken off; a tie is never guessed). A rule's or a guess's turnover line gets
 * the payer's client when the payer has only ever paid for one (Income\PayerAliases::enrich). Either way it's only a
 * suggestion: automation never confirms an income link, even for a rule that confirms on its own (owner answer 3).
 */
final class Suggester
{
    /**
     * A payment to HMRC, by its words ("HMRC", "H M R C", "HM Revenue & Customs", "Self Assessment", "Inland Revenue").
     * The to-do list also uses it, to find payments to HMRC not recorded as tax.
     */
    public const HMRC_PATTERN = '/\bHMRC\b|H\s?M\s?R\s?C|\bHM\s?REVENUE|SELF ASSESSMENT|INLAND REVENUE/i';

    /**
     * SQL LIKE patterns that every match of HMRC_PATTERN contains (each "_" stands for the space HMRC_PATTERN allows
     * between the letters), so the database can pick out the few lines worth testing with it. Change them together.
     */
    public const HMRC_LIKE = ['%HMRC%', '%H_MRC%', '%HM_RC%', '%HMR_C%', '%H_M_RC%', '%H_MR_C%', '%HM_R_C%', '%H_M_R_C%', '%HMREVENUE%', '%HM_REVENUE%', '%SELF ASSESSMENT%', '%INLAND REVENUE%'];

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
        // The jobs rules carry, with their days: a rule's job goes only on payments on or beside its days (ruleJobFor()).
        $ruleJobs = WorkJob::query()->whereIn('id', $rules->pluck('work_job_id')->filter()->unique()->values()->all() ?: [0])->with('days')->get()->keyBy('id');
        $penalty = Category::query()->where('code', 'travel_penalties')->value('id');
        $carHire = Category::query()->where('code', 'travel_car_hire')->value('id');
        $made = 0;
        $ruleMethods = [];

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();

        $turnover = Category::query()->where('code', 'turnover')->value('id');
        OwnAccounts::flush();
        if ($match && $transactions instanceof EloquentCollection) {
            $transactions->loadMissing('account'); // OwnAccounts::reason() reads each line's account
        }
        // Money in, read once the first time some comes in: the invoice matcher for this run (open invoices, what
        // they've received, the payers and what you said no to, with one tally so two payments never take the same
        // amount), and who each payer pays for (one query for the run's payers).
        $incoming = fn () => $transactions->filter(fn ($t) => $t instanceof BankTransaction && $t->amount_pence > 0)->values();
        $matcher = null;
        $aliases = null;

        foreach ($transactions as $tx) {
            if (in_array($tx->tax_year, $locked, true) || $tx->allocations()->exists() || (! $match && $tx->transfer_pair_id)) {
                continue;
            }
            if ($tx->amount_pence > 0) {
                $aliases ??= PayerAliases::forKeys($incoming()->pluck('merchant_key'));
            }

            $text = $tx->description.' '.$tx->counterparty;

            if ($match) {
                $own = OwnAccounts::reason($tx) !== null;
                if (! $own && $tx->amount_pence > 0 && $turnover) {
                    // What's still to come on an invoice (or a set of the payer's client's, or with a fee taken off):
                    // refused, never thrown, over what you said no to or a stronger suggestion, and nothing on a tie.
                    // When it writes nothing your rule, the other matchers and the guess have their turn.
                    $matcher ??= app(InvoiceMatcher::class)->begin($incoming());
                    if ($matcher->write($tx)) {
                        $made++;

                        continue;
                    }
                }

                if (($rule = $rules->get($tx->merchant_key)) && RuleLearner::appliesTo($rule, $tx->amount_pence)) {
                    $jobId = self::ruleJobFor($rule, $tx, $ruleJobs->get((int) $rule->work_job_id));
                    $lines = AllocationWriter::lines($tx->amount_pence, $rule->kind, $rule->category_id, $jobId, $rule->business_pct);
                    // Turnover with no job gets the payer's client, when it pays for only one: then it's a suggestion
                    // for you to accept, even from a rule that confirms on its own (the writer never confirms it).
                    $client = PayerAliases::enrich($tx, $lines, $aliases);
                    if ($this->writer->decide($tx, $client['lines'], 'rule', $rule->auto_confirm, "Your rule for {$rule->merchant_key}".($client['note'] ?? ''), $rule->id,
                        new Provenance($ruleMethods[$rule->id] ??= Methods::forRule($rule), ['rule:'.$rule->id, $jobId ? 'job:'.$jobId : null, ...$client['evidence']]))) {
                        $made++;
                    }

                    continue; // your rule covers it: refused (you said no to it), it's left for you, never guessed
                }
                if (! $own) {
                    $found = null;
                    if ($tx->amount_pence < 0 && preg_match(self::HMRC_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'tax_payment'), 'Looks like a payment to HMRC (personal tax, not a business expense)', new Provenance('suggest.hmrc')];
                    } elseif ($tx->amount_pence < 0 && $penalty && preg_match(self::PENALTY_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'business_expense', $penalty), 'Looks like a fine or penalty charge, which is never allowable', new Provenance('suggest.penalty')];
                    } elseif ($tx->amount_pence < 0 && $carHire && preg_match(self::CAR_HIRE_PATTERN, $text)) {
                        $found = [AllocationWriter::lines($tx->amount_pence, 'business_expense', $carHire), 'Looks like car hire, usually a business expense. Check it was for work, and link the job', new Provenance('suggest.car_hire')];
                    } elseif ($tx->amount_pence < 0 && ($travel = $this->travelSuggestion($tx, $text))) {
                        $found = [$travel['lines'], $travel['reason'], new Provenance('suggest.travel', $travel['evidence'])];
                    }
                    if ($found) {
                        $made += (int) $this->writer->decide($tx, $found[0], 'matcher', false, $found[1], null, $found[2]);

                        continue;
                    }
                }
            }

            if ($guess && ($learned = $this->categoriser->propose($tx))) {
                // Learned from your decisions, the bank's category, or a list of common merchants. Marked as the
                // Categoriser's own, so your later decisions can update it (Categoriser::learnFrom). Turnover gets the
                // payer's client there too (Categoriser::suggest).
                try {
                    $made += (int) $this->categoriser->suggest($tx, $learned, $aliases);
                } catch (\InvalidArgumentException) {
                    // doesn't fit this payment
                }
            }
        }

        return $made;
    }

    /**
     * The job a rule puts a payment on: the rule's job, only for a payment on one of that job's days or the day either
     * side (travel there and back), and only when no other job is worked that day. A rule saved with a job (Inbox "Always
     * do this" with a job chosen) would otherwise put every later payment from that merchant on that one job. The days
     * between a rig and a de-rig weeks later aren't near it (WorkJob::isNear()); a day you worked another job is that
     * job's or neither's, never the rule's (as the Categoriser's onlyJob()). Otherwise, or once the job is cancelled,
     * the rule still applies, with no job.
     *
     * @param  WorkJob|null  $job  the rule's job, when already loaded (with its days)
     */
    public static function ruleJobFor(MerchantRule $rule, BankTransaction $tx, ?WorkJob $job = null): ?int
    {
        if (! $rule->work_job_id) {
            return null;
        }
        $job ??= WorkJob::query()->with('days')->find($rule->work_job_id);
        if (! $job || ! $job->starts_on || ! $job->ends_on || $job->status === 'cancelled') {
            return null;
        }
        $day = $tx->effectiveDate()->format('Y-m-d');
        if (! $job->isNear($day, 1)) {
            return null;
        }
        $others = WorkJob::query()->whereKeyNot($job->id)->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)
            ->with('days')->get()->contains(fn (WorkJob $j) => $j->worksOn($day));

        return $others ? null : (int) $job->id;
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
            return ['lines' => AllocationWriter::lines($tx->amount_pence, 'personal'), 'reason' => "{$what} on {$when}: personal, {$day['reason']}", 'evidence' => []];
        }
        $category = Category::query()->where('code', $code)->value('id');
        $check = $day['verdict'] === 'review' ? 'Check: ' : '';
        $extra = $code === 'travel_rail' ? '. Tickets are often bought in advance, so check the travel date' : '';

        return [
            'lines' => AllocationWriter::lines($tx->amount_pence, 'business_expense', $category, $day['job']?->id),
            'reason' => "{$check}{$what} on {$when}, {$day['reason']}{$extra}",
            'evidence' => $day['job'] ? ['job:'.$day['job']->id] : [],
        ];
    }
}
