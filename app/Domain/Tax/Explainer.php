<?php

namespace App\Domain\Tax;

use App\Domain\Calendar\CalendarSync;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The "?" next to a payment: what it is, how it's been treated, whether HMRC normally allows it and on what
 * conditions, what evidence to keep, and notes from this payment's own context (a job that day, a hotel booking, your
 * vehicle's mileage method, a non-allowable category). Rules come from the tax guide with their sources; the context
 * comes from your own records. No AI: every statement can be traced.
 */
final class Explainer
{
    public static function payment(BankTransaction|ManualExpense $item): array
    {
        $item->loadMissing(['allocations.category', 'allocations.job']);
        $isBank = $item instanceof BankTransaction;
        $date = $isBank ? $item->effectiveDate()->format('Y-m-d') : $item->spent_on->format('Y-m-d');
        $amount = (int) $item->amount_pence;
        $name = $isBank ? ($item->counterparty ?: $item->description) : $item->description;
        $allocs = $item->allocations;
        $main = $allocs->firstWhere('kind', '!=', 'personal') ?? $allocs->first();
        $category = $main?->category;
        $guide = TaxGuide::category($category?->code);
        $notes = [];

        // What it is and how it's been treated.
        $what = sprintf('%s %s on %s%s.', $amount < 0 ? 'A payment of' : 'Money in:', Money::format(abs($amount)), CarbonImmutable::parse($date)->format('j F Y'),
            $isBank ? ' to/from '.$name.($item->account ? ' ('.$item->account->name.')' : '') : ': '.$name);
        $status = match (true) {
            $allocs->isEmpty() => 'Not decided yet.',
            default => ($main->confirmed_at ? 'Decided' : 'Suggested ('.($main->decided_by === 'you' ? 'by you' : 'by '.$main->decided_by).')').': '
                .$allocs->map(fn (Allocation $a) => (Allocation::KINDS[$a->kind] ?? $a->kind).($a->category ? ' · '.$a->category->name : '').($a->job ? ' · '.$a->job->title : '')
                    .($allocs->count() > 1 ? ' ('.Money::format(abs($a->amount_pence)).')' : ''))->implode('; ').'.',
        };

        // Another currency: how its sterling amount was worked out.
        if ($isBank && $item->original_currency && $item->original_currency !== 'GBP' && $item->original_amount_minor) {
            $paid = Money::format(abs($item->original_amount_minor), $item->original_currency, false);
            $rate = $item->fx_units_per_gbp ? rtrim(rtrim((string) $item->fx_units_per_gbp, '0'), '.') : null;
            $notes[] = 'Paid '.$paid.'; it counts as '.Money::format(abs($amount)).' for tax, '.match ($item->fx_source) {
                'exchange' => 'the pounds the currency was bought with in the exchange it came from',
                'hmrc_monthly' => 'at HMRC’s monthly rate for '.($item->fx_rate_date?->format('F Y') ?? 'that month').($rate ? " ({$rate} per £1)" : ''),
                'manual' => 'at the rate you set'.($rate ? " ({$rate} per £1)" : ''),
                default => 'the sterling amount the bank charged'.($rate ? " ({$rate} per £1)" : ''),
            }.'. HMRC accepts a bank’s rate or its own published rates, used consistently.';
        }

        // Context from your own records.
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', CarbonImmutable::parse($date)->addDay()->format('Y-m-d'))
            ->whereDate('ends_on', '>=', CarbonImmutable::parse($date)->subDay()->format('Y-m-d'))->get();
        $isTravel = $category && (str_starts_with($category->code, 'travel') || $category->code === 'travel');
        if ($jobs->isNotEmpty()) {
            $notes[] = 'You had work then: '.$jobs->pluck('title')->implode(', ').'. '
                .($isTravel || ! $category ? 'Travel to a temporary workplace like a venue, and meals and hotels when you’re away overnight for it, are normally allowable.' : 'If this was for that job, link it so it counts as the job’s cost.');
        } elseif ($isTravel) {
            $notes[] = 'There’s no job on or around this date. Travel is only allowable for business journeys (to a venue, client or temporary workplace, not ordinary commuting to a regular base). If it was for work, add the job; otherwise it’s personal.';
        }
        if ($main?->job && $jobs->isEmpty()) {
            $notes[] = 'It’s linked to '.$main->job->title.', which wasn’t on or next to this date. Check the link is right.';
        }
        if ($category && ! $category->is_allowable) {
            $notes[] = 'The category “'.$category->name.'” isn’t allowable for tax: it’s recorded, but it doesn’t reduce your profit.';
        }
        if ($category?->is_capital) {
            $notes[] = 'Equipment is a capital item. Under the cash basis (the default since 2024-25) most equipment is deducted in full in the year you pay for it; cars are the exception. See capital allowances below.';
        }
        if ($category && in_array($category->code, ['travel_fuel', 'repairs'], true)) {
            $simplified = Vehicle::query()->where('method', 'simplified')->get();
            if ($simplified->isNotEmpty() && $category->code === 'travel_fuel') {
                $notes[] = 'You claim simplified mileage for '.$simplified->pluck('name')->implode(', ').'. Fuel for a vehicle on simplified mileage can’t be claimed as well (the mileage rate covers it). Fuel for a hire car is fine.';
            }
        }
        if ($main?->business_use_pct && $main->business_use_pct < 100) {
            $notes[] = "Split {$main->business_use_pct}% business: only that part counts. Keep a note of how you worked out the split.";
        }
        if ($isBank && ($stay = TravelItem::query()->where('bank_transaction_id', $item->id)->first())) {
            $notes[] = 'This is the payment for '.(TravelItem::KINDS[$stay->kind] ?? 'a booking').' with '.$stay->provider.' from '.$stay->starts_on->format('j M Y')
                .($stay->decision ? ', which you decided as '.mb_strtolower(TravelItem::DECISIONS[$stay->decision] ?? $stay->decision) : '').'.';
        }
        if ($main?->kind === 'tax_payment') {
            $notes[] = 'Payments to HMRC for your own Income Tax and National Insurance aren’t a business expense; they’re recorded against the tax year they’re for.';
        }
        if ($main?->kind === 'drawings') {
            $notes[] = 'Money you take out for yourself isn’t an expense and doesn’t change your profit.';
        }
        $events = CalendarSync::eventsOn($date);
        if ($events && ! $jobs->isNotEmpty()) {
            $notes[] = 'Your calendar that day: '.collect($events)->pluck('summary')->filter()->take(3)->implode('; ').'.';
        }

        $topics = [];
        if ($isTravel) {
            $topics[] = 'travel_rules';
        }
        if ($category?->code === 'travel_mileage' || $category?->code === 'travel_fuel') {
            $topics[] = 'mileage_simplified';
        }
        if ($category?->is_capital) {
            $topics[] = 'capital_allowances';
        }
        if ($category?->code === 'entertainment') {
            $topics[] = 'entertainment_and_gifts';
        }
        if ($category?->code === 'clothing') {
            $topics[] = 'clothing';
        }
        if (in_array($category?->code, ['phone_internet'], true)) {
            $topics[] = 'phone_and_broadband';
        }
        if ($category?->code === 'training') {
            $topics[] = 'training';
        }
        if ($category?->code === 'use_of_home') {
            $topics[] = 'use_of_home';
        }
        if (! $category && $amount < 0) {
            $topics[] = 'wholly_and_exclusively';
        }

        return [
            'title' => $name,
            'what' => $what,
            'status' => $status,
            'notes' => $notes,
            'category' => $guide ? self::trim($guide) : null,
            'topics' => array_values(array_filter(array_map(fn ($t) => ($g = TaxGuide::topic($t)) ? self::trim($g) : null, array_unique($topics)))),
            'researched_on' => TaxGuide::researchedOn(),
        ];
    }

    public static function category(Category $category): array
    {
        $guide = TaxGuide::category($category->code);

        return ['title' => $category->name, 'box' => $category->hmrc_box, 'allowable' => $category->is_allowable, 'category' => $guide ? self::trim($guide) : null, 'researched_on' => TaxGuide::researchedOn()];
    }

    private static function trim(array $entry): array
    {
        return array_intersect_key($entry, array_flip(['code', 'slug', 'title', 'allowable', 'summary', 'rules', 'examples_allowed', 'examples_not_allowed', 'evidence_to_keep', 'watch_out', 'sources', 'by_year']));
    }
}
