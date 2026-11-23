<?php

namespace App\Domain\Travel;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TaxYear;
use App\Models\TravelItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Proposes what each stay or journey is: lines it up with your jobs night by night (TravelDays), finds the
 * payment in your statements, and spots client-booked stays. The proposal is saved on the stay and, when a
 * payment is found, as an unconfirmed suggestion on that payment, which never overwrites anything you've
 * decided. Your own decision is applied by decide().
 */
final class TravelMatcher
{
    /** How each provider usually appears on statements. */
    private const MERCHANT_WORDS = [
        'Booking.com' => ['BOOKING.COM', 'BOOKING COM'], 'Hotels.com' => ['HOTELS.COM', 'HOTELSCOM', 'EXPEDIA'], 'Expedia' => ['EXPEDIA'],
        'Premier Inn' => ['PREMIER INN', 'PREMIERINN', 'WHITBREAD'], 'Travelodge' => ['TRAVELODGE'],
        'IHG' => ['IHG', 'HOLIDAY INN', 'CROWNE PLAZA', 'HOTEL INDIGO', 'INTERCONTINENTAL'], 'Airbnb' => ['AIRBNB'],
        'Uber' => ['UBER'], 'Bolt' => ['BOLT'], 'FREENOW' => ['FREENOW', 'FREE NOW', 'MYTAXI'], 'Addison Lee' => ['ADDISON LEE'],
        'Trainline' => ['TRAINLINE'], 'LNER' => ['LNER'], 'GWR' => ['GWR', 'GREAT WESTERN'], 'Avanti West Coast' => ['AVANTI'],
        'National Express' => ['NATIONAL EXPRESS'], 'easyJet' => ['EASYJET'], 'British Airways' => ['BRITISH A', 'BA.COM'], 'Ryanair' => ['RYANAIR'],
        'Enterprise' => ['ENTERPRISE'], 'Hertz' => ['HERTZ'], 'Avis' => ['AVIS'], 'Europcar' => ['EUROPCAR'], 'Sixt' => ['SIXT'], 'Zipcar' => ['ZIPCAR'],
        'JustPark' => ['JUSTPARK'], 'RingGo' => ['RINGGO'], 'PayByPhone' => ['PAYBYPHONE'], 'NCP' => ['NCP'],
    ];

    public function __construct(private TravelDays $days, private AllocationWriter $writer) {}

    public function suggest(TravelItem $item): void
    {
        if ($item->decided_at) {
            return;
        }
        if ($item->status === 'cancelled') {
            $item->update(['suggestion' => ['decision' => 'cancelled', 'reasons' => ['Cancelled. If you paid, a refund should follow; anything kept as a cancellation charge goes to your Inbox.']]]);

            return;
        }

        $payment = $item->transaction ?? $this->findPayment($item);
        $verdicts = $item->kind === 'hotel'
            ? array_map(fn ($night) => ['date' => $night] + $this->days->night($night), $item->nights())
            : [['date' => $item->starts_on->format('Y-m-d')] + $this->days->day($item->starts_on->format('Y-m-d'))];

        $business = count(array_filter($verdicts, fn ($v) => $v['verdict'] === 'business'));
        $review = count(array_filter($verdicts, fn ($v) => $v['verdict'] === 'review'));
        $personal = count($verdicts) - $business - $review;
        $jobCounts = array_count_values(array_filter(array_map(fn ($v) => $v['job']?->id, $verdicts)));
        arsort($jobCounts);
        $jobId = array_key_first($jobCounts);
        $decision = match (true) {
            $review > 0 => 'review',
            $business === count($verdicts) => 'business',
            $business === 0 => 'personal',
            default => 'mixed',
        };
        $reasons = array_map(fn ($v) => ($item->kind === 'hotel' ? 'Night of '.CarbonImmutable::parse($v['date'])->format('D j M').': ' : '')
            .ucfirst($v['verdict'] === 'review' ? 'check' : $v['verdict']).', '.$v['reason'], $verdicts);

        // Client-booked: the email says someone else pays, or nothing was paid long after the stay or flight.
        $over = ($item->ends_on ?? $item->starts_on)->copy()->addDays(7)->isPast();
        if (! $payment && $decision !== 'personal' && ($item->payment === 'paid_by_other' || ($over && in_array($item->kind, ['hotel', 'flight'], true) && $item->payment !== 'paid'))) {
            $decision = 'client_booked';
            $reasons[] = $item->payment === 'paid_by_other'
                ? 'The booking says it’s paid by '.($item->booker ?: 'someone else').', so there’s no cost to you'
                : 'No payment found in your statements a week after '.($item->kind === 'hotel' ? 'check-out' : 'the flight').': probably booked by the client. If you paid, import that card’s statement';
        } elseif (! $payment) {
            $reasons[] = $item->payment === 'pay_at_property' && ! $over ? 'Paid at the hotel: the payment should appear after check-out' : 'No matching payment found in your statements yet';
        }
        if ($item->unverified_count > 0) {
            $reasons[] = $item->unverified_count.' detail(s) couldn’t be checked against the email: look at it before deciding';
        }

        $item->update([
            'bank_transaction_id' => $payment?->id,
            // A job you linked (or unlinked) stays as you set it.
            'work_job_id' => $item->job_linked_by === 'you' ? $item->work_job_id : ($item->work_job_id ?? $jobId),
            'job_linked_by' => $item->job_linked_by === 'you' || $item->work_job_id ? $item->job_linked_by : ($jobId ? 'rules' : null),
            'suggestion' => [
                'decision' => $decision, 'job_id' => $jobId, 'business_nights' => $business + $review, 'nights' => count($verdicts),
                'reasons' => $reasons, 'at' => now()->toIso8601String(),
            ],
        ]);

        // The same proposal goes on the payment, unconfirmed. "Check" nights are proposed as business, the rest
        // as personal, so a stay with any personal night is split. Locked years and your own decisions are left alone.
        if ($payment && in_array($decision, ['business', 'personal', 'mixed', 'review'], true) && ! $this->isLocked($payment)) {
            $kind = match (true) {
                $decision === 'personal', $decision === 'review' && ! $jobId => 'personal',
                $decision === 'mixed', $decision === 'review' && $personal > 0 => 'mixed',
                default => 'business',
            };
            $lines = $this->lines($item, $payment->amount_pence, $kind, $jobId, $business + $review);
            $this->writer->decide($payment, $lines, 'matcher', false, $this->summary($item).': '.implode('; ', array_slice($reasons, 0, 3)));
        }
    }

    /**
     * Your decision on a stay or journey. It's applied to its payment too, as your own confirmed decision,
     * unless you've already decided that payment yourself (then it's left as you set it).
     *
     * @return string what happened to the payment, for the message you see
     */
    public function decide(TravelItem $item, string $decision, ?int $jobId, ?int $businessNights, ?string $note): string
    {
        $nights = count($item->nights());
        $businessNights = $decision === 'mixed' ? max(1, min($nights - 1, (int) $businessNights)) : null;
        $payment = $item->transaction;
        if ($payment && $decision !== 'client_booked' && $this->isLocked($payment)) {
            throw new \RuntimeException("Its payment is in a locked tax year ({$payment->tax_year}). Unlock the year to change it.");
        }

        return DB::transaction(function () use ($item, $decision, $jobId, $businessNights, $note, $nights, $payment) {
            $outcome = 'none';
            if ($payment && $decision === 'client_booked') {
                // Booked and paid by the client: the payment isn't this stay's. Take back any automatic suggestion.
                if ($payment->allocations()->get()->every(fn ($a) => ! $a->is_manual && $a->confirmed_at === null) && ! $this->isLocked($payment)) {
                    $this->writer->clear($payment, 'Not this stay’s payment: booked by the client');
                }
                $item->bank_transaction_id = null;
                $outcome = 'unlinked';
            } elseif ($payment && $payment->allocations()->get()->contains(fn ($a) => $a->is_manual || $a->confirmed_at !== null)) {
                $outcome = 'kept';
            } elseif ($payment) {
                $kind = match ($decision) {
                    'personal' => 'personal', 'mixed' => 'mixed', default => 'business',
                };
                $lines = $this->lines($item, $payment->amount_pence, $kind, $jobId, $businessNights ?? $nights,
                    $decision === 'recharge' ? 'Recharged to the client' : $note);
                $this->writer->decide($payment, $lines, 'you', true, $this->summary($item).': '.(TravelItem::DECISIONS[$decision] ?? $decision));
                $outcome = 'applied';
            }

            $item->fill([
                'decision' => $decision, 'work_job_id' => $decision === 'personal' ? $jobId : ($jobId ?? $item->work_job_id), 'job_linked_by' => 'you',
                'business_nights' => $businessNights, 'decided_at' => now(), 'note' => $note,
            ])->save();
            AuditLog::record('travel.decided', $item, null, ['decision' => $decision, 'job_id' => $jobId, 'business_nights' => $businessNights, 'note' => $note, 'payment' => $outcome]);

            return $outcome;
        });
    }

    private function isLocked(BankTransaction $payment): bool
    {
        return TaxYear::query()->where('label', $payment->tax_year)->whereNotNull('locked_at')->exists();
    }

    /**
     * The payment for a stay or journey: a statement line naming the provider (hotel brand for pay-at-hotel
     * stays) at the same amount, or, abroad, the same original amount and currency; and a sensible date.
     * Without an amount to compare, a match only counts when it's the only one.
     */
    public function findPayment(TravelItem $item): ?BankTransaction
    {
        $booked = CarbonImmutable::parse($item->document?->received_at ?? $item->starts_on);
        $from = ($booked->lt($item->starts_on) ? $booked : CarbonImmutable::parse($item->starts_on))->subDays(3);
        $to = CarbonImmutable::parse($item->ends_on ?? $item->starts_on)->addDays(10);
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label');
        $taken = TravelItem::query()->whereNotNull('bank_transaction_id')->where('id', '!=', $item->id)->pluck('bank_transaction_id');

        $base = fn () => BankTransaction::query()->where('amount_pence', '<', 0)
            ->whereBetween('posted_on', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereNotIn('id', $taken)->whereNotIn('tax_year', $locked)
            ->where('description', 'not like', '%UBER%EATS%');
        $sameAmount = function ($q) use ($item) {
            if (! $item->amount_minor) {
                return $q;
            }

            return $item->currency === 'GBP'
                ? $q->where('amount_pence', -$item->amount_minor)
                : $q->where('original_currency', $item->currency)->whereIn('original_amount_minor', [$item->amount_minor, -$item->amount_minor]);
        };
        $named = function ($q, array $words) {
            return $q->where(function ($q) use ($words) {
                foreach (array_filter($words) as $word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    $q->orWhere('description', 'like', $like)->orWhere('counterparty', 'like', $like);
                }
            });
        };
        $target = CarbonImmutable::parse($item->payment === 'pay_at_property' ? ($item->ends_on ?? $item->starts_on) : $booked);
        $closest = fn ($candidates) => $candidates->sortBy(fn (BankTransaction $t) => abs($t->effectiveDate()->diffInDays($target)))->first();

        // 1. Named after the provider (or, for a pay-at-hotel stay, the hotel's own name) at the same amount.
        $words = self::MERCHANT_WORDS[$item->provider] ?? [strtoupper($item->provider)];
        if ($item->kind === 'hotel' && $item->place) {
            $words[] = strtoupper(implode(' ', array_slice(preg_split('/[\s,]+/', $item->place), 0, 2)));
        }
        if ($item->amount_minor) {
            $match = $named($sameAmount($base()), $words)->orderBy('posted_on')->limit(20)->get();
            if ($match->isNotEmpty()) {
                return $closest($match);
            }
            // 2. The same amount, but the statement doesn't name the provider: only a single exact match counts.
            $match = $sameAmount($base())->limit(2)->get();

            return $match->count() === 1 ? $match->first() : null;
        }

        // 3. No amount to compare: only when exactly one payment names the provider.
        $match = $named($base(), self::MERCHANT_WORDS[$item->provider] ?? [strtoupper($item->provider)])->limit(2)->get();

        return $match->count() === 1 ? $match->first() : null;
    }

    private function lines(TravelItem $item, int $amount, string $kind, ?int $jobId, int $businessNights, ?string $note = null): array
    {
        $category = Category::query()->where('code', TravelItem::CATEGORY[$item->kind] ?? 'travel_local')->value('id');
        if ($kind === 'personal') {
            return AllocationWriter::lines($amount, 'personal', null, null, 100, $note);
        }
        $nights = count($item->nights());
        if ($kind !== 'mixed' || $item->kind !== 'hotel' || $businessNights >= $nights) {
            return AllocationWriter::lines($amount, 'business_expense', $category, $jobId, 100, $note);
        }

        // Split by nights; any odd penny goes to the personal part.
        $businessPart = -intdiv(abs($amount) * max(0, $businessNights), $nights);
        $lines = [];
        if ($businessPart !== 0) {
            $lines[] = ['kind' => 'business_expense', 'category_id' => $category, 'work_job_id' => $jobId, 'amount_pence' => $businessPart, 'business_use_pct' => 100,
                'note' => trim("{$businessNights} of {$nights} nights for work. ".$note)];
        }
        $lines[] = ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => $amount - $businessPart, 'business_use_pct' => null, 'note' => $note];

        return $lines;
    }

    private function summary(TravelItem $item): string
    {
        $when = $item->kind === 'hotel'
            ? count($item->nights()).' night'.(count($item->nights()) === 1 ? '' : 's').' from '.$item->starts_on->format('j M Y')
            : $item->starts_on->format('j M Y');

        return trim(($item->place ?: $item->provider).' ('.(TravelItem::KINDS[$item->kind] ?? $item->kind).', '.$when.')');
    }
}
