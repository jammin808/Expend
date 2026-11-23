<?php

namespace App\Domain\Travel;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TaxYear;
use App\Models\TravelItem;
use Carbon\CarbonImmutable;

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
            'work_job_id' => $item->work_job_id ?? $jobId,
            'suggestion' => [
                'decision' => $decision, 'job_id' => $jobId, 'business_nights' => $business + $review, 'nights' => count($verdicts),
                'reasons' => $reasons, 'at' => now()->toIso8601String(),
            ],
        ]);

        if ($payment && in_array($decision, ['business', 'personal', 'mixed', 'review'], true)) {
            $kind = $decision === 'personal' || ($decision === 'review' && ! $jobId) ? 'personal' : ($decision === 'mixed' ? 'mixed' : 'business');
            $lines = $this->lines($item, $payment->amount_pence, $kind, $jobId, $business + $review);
            $this->writer->decide($payment, $lines, 'matcher', false, $this->summary($item).': '.implode('; ', array_slice($reasons, 0, 3)));
        }
    }

    /**
     * Your decision on a stay or journey. It's applied to its payment too, as your own confirmed decision.
     */
    public function decide(TravelItem $item, string $decision, ?int $jobId, ?int $businessNights, ?string $note): void
    {
        $nights = count($item->nights());
        $businessNights = $decision === 'mixed' ? max(1, min($nights - 1, (int) $businessNights)) : null;
        $item->update([
            'decision' => $decision, 'work_job_id' => in_array($decision, ['personal', 'client_booked'], true) && ! $jobId ? $item->work_job_id : $jobId,
            'business_nights' => $businessNights, 'decided_at' => now(), 'note' => $note,
        ]);
        AuditLog::record('travel.decided', $item, null, ['decision' => $decision, 'job_id' => $jobId, 'business_nights' => $businessNights, 'note' => $note]);

        $payment = $item->transaction;
        if (! $payment || $decision === 'client_booked') {
            return;
        }
        $kind = match ($decision) {
            'personal' => 'personal', 'mixed' => 'mixed', default => 'business',
        };
        $lines = $this->lines($item, $payment->amount_pence, $kind, $jobId, $businessNights ?? $nights,
            $decision === 'recharge' ? 'Recharged to the client' : $note);
        $this->writer->decide($payment, $lines, 'you', true, $this->summary($item).': '.(TravelItem::DECISIONS[$decision] ?? $decision));
    }

    /** The payment for a stay or journey: same amount (when in pounds), a statement description naming the provider, and a sensible date. */
    public function findPayment(TravelItem $item): ?BankTransaction
    {
        $booked = CarbonImmutable::parse($item->document?->received_at ?? $item->starts_on);
        $from = ($booked->lt($item->starts_on) ? $booked : CarbonImmutable::parse($item->starts_on))->subDays(3);
        $to = CarbonImmutable::parse($item->ends_on ?? $item->starts_on)->addDays(7);
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label');
        $taken = TravelItem::query()->whereNotNull('bank_transaction_id')->where('id', '!=', $item->id)->pluck('bank_transaction_id');

        $candidates = BankTransaction::query()->where('amount_pence', '<', 0)
            ->whereBetween('posted_on', [$from->format('Y-m-d'), $to->addDays(3)->format('Y-m-d')])
            ->whereNotIn('id', $taken)->whereNotIn('tax_year', $locked)
            ->when($item->currency === 'GBP' && $item->amount_minor, fn ($q) => $q->where('amount_pence', -$item->amount_minor))
            ->orderBy('posted_on')->limit(50)->get();

        $words = self::MERCHANT_WORDS[$item->provider] ?? [];
        if ($item->place) {
            $words[] = strtoupper(implode(' ', array_slice(preg_split('/[\s,]+/', $item->place), 0, 2)));
        }
        $named = $candidates->filter(function (BankTransaction $t) use ($words) {
            $text = strtoupper($t->description.' '.$t->counterparty);
            if (str_contains($text, 'UBER') && str_contains($text, 'EATS')) {
                return false;
            }
            foreach ($words as $w) {
                if ($w !== '' && str_contains($text, $w)) {
                    return true;
                }
            }

            return false;
        });

        if ($named->isNotEmpty()) {
            $target = CarbonImmutable::parse($item->payment === 'pay_at_property' ? ($item->ends_on ?? $item->starts_on) : $booked);

            return $named->sortBy(fn (BankTransaction $t) => abs($t->effectiveDate()->diffInDays($target)))->first();
        }

        // Same amount but the statement doesn't name the provider: only a single, exact match counts.
        return $item->currency === 'GBP' && $item->amount_minor && $candidates->count() === 1 ? $candidates->first() : null;
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
