<?php

namespace App\Domain\Ledger;

use App\Models\Category;

/**
 * HMRC self-employment categories (SA103F boxes 17-30 / MTD) with friendly sub-categories.
 */
final class Categories
{
    /** @return list<array{code:string,name:string,box:?string,allowable?:bool,children?:list<array{code:string,name:string,allowable?:bool,capital?:bool}>}> */
    public static function expenseTree(): array
    {
        return [
            ['code' => 'cost_of_goods', 'name' => 'Cost of goods bought for resale or goods used', 'box' => '17', 'children' => [
                ['code' => 'materials', 'name' => 'Materials and consumables'],
            ]],
            ['code' => 'subcontractors', 'name' => 'Construction industry: payments to subcontractors', 'box' => '18'],
            ['code' => 'staff', 'name' => 'Wages, salaries and other staff costs', 'box' => '19', 'children' => [
                ['code' => 'freelancers', 'name' => 'Freelancers and crew'],
            ]],
            ['code' => 'travel', 'name' => 'Car, van and travel expenses', 'box' => '20', 'children' => [
                ['code' => 'travel_accommodation', 'name' => 'Accommodation'],
                ['code' => 'travel_rail', 'name' => 'Rail'],
                ['code' => 'travel_taxi', 'name' => 'Taxi and ride-hailing'],
                ['code' => 'travel_local', 'name' => 'Local transport (TfL, bus)'],
                ['code' => 'travel_flights', 'name' => 'Flights'],
                ['code' => 'travel_parking', 'name' => 'Parking, tolls and road charges'],
                ['code' => 'travel_mileage', 'name' => 'Mileage (simplified rate)'],
                ['code' => 'travel_fuel', 'name' => 'Fuel (actual-cost vehicles only)'],
                ['code' => 'travel_subsistence', 'name' => 'Subsistence (meals on business trips)'],
                ['code' => 'travel_penalties', 'name' => 'Parking fines and penalty charges', 'allowable' => false],
            ]],
            ['code' => 'premises', 'name' => 'Rent, rates, power and insurance costs', 'box' => '21', 'children' => [
                ['code' => 'insurance', 'name' => 'Business insurance'],
                ['code' => 'use_of_home', 'name' => 'Use of home'],
            ]],
            ['code' => 'repairs', 'name' => 'Repairs and maintenance of property and equipment', 'box' => '22'],
            ['code' => 'office', 'name' => 'Phone, fax, stationery and other office costs', 'box' => '23', 'children' => [
                ['code' => 'phone_internet', 'name' => 'Phone and internet'],
                ['code' => 'software', 'name' => 'Software and subscriptions'],
                ['code' => 'stationery', 'name' => 'Stationery and postage'],
            ]],
            ['code' => 'advertising', 'name' => 'Advertising and business entertainment costs', 'box' => '24', 'children' => [
                ['code' => 'marketing', 'name' => 'Advertising and marketing'],
                ['code' => 'entertainment', 'name' => 'Business entertainment', 'allowable' => false],
            ]],
            ['code' => 'interest', 'name' => 'Interest on bank and other loans', 'box' => '25'],
            ['code' => 'finance', 'name' => 'Bank, credit card and other financial charges', 'box' => '26'],
            ['code' => 'bad_debts', 'name' => 'Irrecoverable debts written off', 'box' => '27'],
            ['code' => 'professional', 'name' => 'Accountancy, legal and other professional fees', 'box' => '28'],
            ['code' => 'depreciation', 'name' => 'Depreciation and loss/profit on sale of assets', 'box' => '29', 'allowable' => false],
            ['code' => 'other', 'name' => 'Other business expenses', 'box' => '30', 'children' => [
                ['code' => 'equipment', 'name' => 'Equipment (capital item)', 'capital' => true],
                ['code' => 'training', 'name' => 'Training and subscriptions'],
                ['code' => 'clothing', 'name' => 'Protective clothing and uniforms'],
            ]],
        ];
    }

    public static function seed(): void
    {
        $sort = 0;
        foreach ([['code' => 'turnover', 'name' => 'Turnover (sales and fees)', 'box' => '15'], ['code' => 'other_income', 'name' => 'Other business income', 'box' => '16']] as $income) {
            Category::query()->updateOrCreate(['code' => $income['code']], [
                'name' => $income['name'], 'kind' => 'income', 'hmrc_box' => $income['box'], 'sort' => $sort++,
            ]);
        }

        foreach (self::expenseTree() as $top) {
            $parent = Category::query()->updateOrCreate(['code' => $top['code']], [
                'name' => $top['name'], 'kind' => 'expense', 'hmrc_box' => $top['box'],
                'is_allowable' => $top['allowable'] ?? true, 'parent_id' => null, 'sort' => $sort++,
            ]);
            foreach ($top['children'] ?? [] as $child) {
                Category::query()->updateOrCreate(['code' => $child['code']], [
                    'name' => $child['name'], 'kind' => 'expense', 'hmrc_box' => $top['box'], 'parent_id' => $parent->id,
                    'is_allowable' => $child['allowable'] ?? ($top['allowable'] ?? true),
                    'is_capital' => $child['capital'] ?? false, 'sort' => $sort++,
                ]);
            }
        }
    }
}
