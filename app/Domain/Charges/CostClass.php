<?php

namespace App\Domain\Charges;

use App\Models\ChargeTerm;
use App\Models\TravelItem;

/**
 * The kinds of cost a client can be charged for, how each may be charged, and which kind a ledger category or a
 * booking is. One list (ChargeTerm::CHARGES), so the arrangement editor, the checks on a saved arrangement and the
 * draft lines never disagree.
 *
 * What never counts as a cost to charge on: finance-type spending (bank charges, interest, bad debts, depreciation:
 * income v3's deductions are booked as finance, so a commission taken off a payment is never recharged to the agency
 * that took it) and travel_mileage (driving is charged only from your mileage trips; a payment booked as mileage is a
 * thing to check, never a cost).
 */
final class CostClass
{
    /** Each kind of cost and how it may be charged: ChargeTerm::CHARGES, never a second list. */
    public const CLASSES = ChargeTerm::CHARGES;

    /** Kinds of cost that are arrangements for the job (lines of their own), never a payment or a booking. */
    public const JOB_LEVEL = ['travel_fee', 'travel_days'];

    /** The kind of cost each ledger category is. Every other category is 'other'. */
    public const BY_CATEGORY = [
        'travel_rail' => 'rail',
        'travel_local' => 'bus',
        'travel_flights' => 'flight',
        'travel_taxi' => 'taxi',
        'travel_car_hire' => 'car_hire',
        'travel_fuel' => 'fuel',
        'travel_parking' => 'parking',
        'travel_accommodation' => 'hotel',
        'travel_subsistence' => 'meals',
        'travel_penalties' => 'fines',
    ];

    /** Categories (or their parent) that never make a cost to charge on. */
    public const NEVER = ['finance', 'interest', 'bad_debts', 'depreciation'];

    /** The category for mileage booked as a payment: never a cost, a thing to check. */
    public const MILEAGE_CATEGORY = 'travel_mileage';

    /** Charges that mean "expected on the invoice": at what it cost, or per mile. */
    public const EXPECTED = ['at_cost', 'per_mile'];

    /** What each kind of cost is called on an invoice line and in the app. */
    public const LABELS = [
        'mileage' => 'Mileage', 'rail' => 'Train', 'bus' => 'Bus or coach', 'flight' => 'Flight', 'taxi' => 'Taxi',
        'car_hire' => 'Car hire', 'parking' => 'Parking', 'hotel' => 'Hotel', 'other' => 'Expense', 'fuel' => 'Fuel',
        'meals' => 'Meals', 'fines' => 'Fine', 'travel_fee' => 'Travel fee', 'travel_days' => 'Travel days',
    ];

    /** The kind of cost a ledger category is (by its code): 'other' for anything that isn't a travel kind. */
    public static function of(?string $categoryCode): string
    {
        return self::BY_CATEGORY[(string) $categoryCode] ?? 'other';
    }

    /** The kind of cost a booking is, through the category it's booked to (a coach is local transport: bus). */
    public static function ofBooking(TravelItem|string $kind): string
    {
        $kind = $kind instanceof TravelItem ? (string) $kind->kind : $kind;

        return self::of(TravelItem::CATEGORY[$kind] ?? null);
    }

    /**
     * Whether a ledger category can make a cost to charge: not finance-type spending (nor a child of it), and not
     * mileage booked as a payment.
     */
    public static function counts(?string $categoryCode, ?string $parentCode = null): bool
    {
        return $categoryCode !== self::MILEAGE_CATEGORY && ! in_array($categoryCode, self::NEVER, true) && ! in_array($parentCode, self::NEVER, true);
    }

    /** Whether a kind of cost may be charged this way. */
    public static function allows(string $class, string $charge): bool
    {
        return in_array($charge, self::CLASSES[$class] ?? [], true);
    }

    /** Whether this way of charging means the cost goes on the invoice (at cost, or per mile). */
    public static function expected(?string $charge): bool
    {
        return in_array($charge, self::EXPECTED, true);
    }

    public static function label(?string $class): string
    {
        return self::LABELS[(string) $class] ?? 'Expense';
    }
}
