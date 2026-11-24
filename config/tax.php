<?php

/*
|--------------------------------------------------------------------------
| UK tax parameters, per tax year (England, Wales and Northern Ireland)
|--------------------------------------------------------------------------
|
| Every rate, threshold and rule the app uses comes from here, never from
| code. Each year lists its sources. Years that aren't listed still get
| return figures, but no tax estimate. Amounts are in pence; rates are
| percentages as strings so they stay exact.
|
| Add a new year by copying the latest block and checking every figure
| against the GOV.UK pages in `sources`.
|
*/

$sources = [
    'income_tax' => 'https://www.gov.uk/government/publications/rates-and-allowances-income-tax',
    'nics' => 'https://www.gov.uk/government/publications/rates-and-allowances-national-insurance-contributions',
    'mileage' => 'https://www.gov.uk/simpler-income-tax-simplified-expenses/vehicles',
    'use_of_home' => 'https://www.gov.uk/simpler-income-tax-simplified-expenses/working-from-home',
    'payments_on_account' => 'https://www.gov.uk/understand-self-assessment-bill/payments-on-account',
    'vat' => 'https://www.gov.uk/vat-registration/when-to-register',
    'mtd' => 'https://www.gov.uk/government/collections/making-tax-digital-for-income-tax',
];

$base = [
    'personal_allowance' => 1_257_000,
    'personal_allowance_taper_from' => 10_000_000,
    'basic_rate_limit' => 3_770_000,
    'additional_rate_threshold' => 12_514_000,
    'rates' => ['basic' => '20', 'higher' => '40', 'additional' => '45'],
    'class4' => ['lower' => 1_257_000, 'upper' => 5_027_000, 'main_rate' => '6', 'upper_rate' => '2'],
    'class2' => null,
    'class2_voluntary' => null,
    'mileage' => [
        'car_van_first' => 45, 'car_van_after' => 25, 'threshold_miles' => 10_000,
        'motorcycle' => 24,
        // Bicycles aren't covered by simplified expenses for the self-employed (20p is the employee rate).
    ],
    'use_of_home' => [
        ['min_hours' => 25, 'max_hours' => 50, 'monthly' => 1_000],
        ['min_hours' => 51, 'max_hours' => 100, 'monthly' => 1_800],
        ['min_hours' => 101, 'max_hours' => null, 'monthly' => 2_600],
    ],
    'trading_allowance' => 100_000,
    'vat_threshold' => 9_000_000,
    'payments_on_account_threshold' => 100_000,
    'cash_basis_default' => true,
    'sources' => $sources,
];

return [

    'years' => [

        '2021-22' => array_replace_recursive($base, [
            'additional_rate_threshold' => 15_000_000,
            'class4' => ['lower' => 956_800, 'upper' => 5_027_000, 'main_rate' => '9', 'upper_rate' => '2'],
            'class2' => ['weekly' => 305, 'payable_from' => 651_500],
            'vat_threshold' => 8_500_000,
            'cash_basis_default' => false,
        ]),

        '2022-23' => array_replace_recursive($base, [
            'additional_rate_threshold' => 15_000_000,
            // Blended rates and threshold for 2022-23 (Health and Social Care Levy reversal).
            'class4' => ['lower' => 1_190_800, 'upper' => 5_027_000, 'main_rate' => '9.73', 'upper_rate' => '2.73'],
            // Payable from the lower profits limit; treated as paid between the small profits threshold and it.
            'class2' => ['weekly' => 315, 'payable_from' => 1_190_800],
            'vat_threshold' => 8_500_000,
            'cash_basis_default' => false,
        ]),

        '2023-24' => array_replace_recursive($base, [
            'class4' => ['lower' => 1_257_000, 'upper' => 5_027_000, 'main_rate' => '9', 'upper_rate' => '2'],
            'class2' => ['weekly' => 345, 'payable_from' => 1_257_000],
            'vat_threshold' => 8_500_000,
            'cash_basis_default' => false,
        ]),

        // From 2024-25: Class 2 no longer compulsory, Class 4 main rate 6%, cash basis the default.
        // Voluntary Class 2 (profits under the small profits threshold): it keeps the year counting for the state pension.
        '2024-25' => array_replace_recursive($base, [
            'class2_voluntary' => ['weekly' => 345, 'small_profits_threshold' => 672_500],
        ]),

        '2025-26' => array_replace_recursive($base, [
            'class2_voluntary' => ['weekly' => 350, 'small_profits_threshold' => 684_500],
        ]),

        // Simplified mileage for cars and goods vehicles rose to 55p for the first 10,000 miles.
        '2026-27' => array_replace_recursive($base, [
            'mileage' => ['car_van_first' => 55],
            'class2_voluntary' => ['weekly' => 365, 'small_profits_threshold' => 710_500],
        ]),

    ],

    // Making Tax Digital for Income Tax: start date by qualifying income (turnover) in the year two years earlier.
    'mtd_thresholds' => [
        ['starts' => '2026-04-06', 'based_on' => '2024-25', 'turnover_over' => 5_000_000],
        ['starts' => '2027-04-06', 'based_on' => '2025-26', 'turnover_over' => 3_000_000],
        ['starts' => '2028-04-06', 'based_on' => '2026-27', 'turnover_over' => 2_000_000],
    ],

];
