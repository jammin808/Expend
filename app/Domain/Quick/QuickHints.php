<?php

namespace App\Domain\Quick;

use App\Models\Category;

/**
 * The words that say what a cost was ("parking", "NCP", "train", "Pret"), and the category each usually is: one list,
 * served to the phone in the pocket (QuickPocket) for its offline suggestions, and read here for a text sent to the
 * Telegram bot. Your own past entries come first wherever they're used; these are the fallback.
 */
final class QuickHints
{
    /** [words (lower case, whole words), category code]. The first that matches wins. */
    public const HINTS = [
        [['parking', 'car park', 'ncp', 'q-park', 'qpark', 'apcoa', 'justpark', 'ringgo', 'paybyphone', 'toll', 'tolls', 'congestion', 'ulez', 'dart charge', 'm6 toll'], 'travel_parking'],
        [['fuel', 'petrol', 'diesel', 'shell', 'bp', 'esso', 'texaco', 'jet', 'gulf'], 'travel_fuel'],
        [['train', 'rail', 'lner', 'avanti', 'gwr', 'crosscountry', 'northern', 'tpe', 'trainline', 'eurostar', 'thameslink', 'southeastern', 'c2c', 'merseyrail', 'scotrail'], 'travel_rail'],
        [['taxi', 'cab', 'uber', 'bolt', 'addison lee', 'freenow', 'free now', 'ola', 'gett'], 'travel_taxi'],
        [['tube', 'bus', 'tfl', 'oyster', 'tram', 'metro', 'underground', 'ferry'], 'travel_local'],
        [['flight', 'flights', 'easyjet', 'ryanair', 'jet2', 'british airways', 'loganair', 'wizz'], 'travel_flights'],
        [['hotel', 'premier inn', 'travelodge', 'ibis', 'holiday inn', 'airbnb', 'b&b', 'guest house'], 'travel_accommodation'],
        [['car hire', 'van hire', 'enterprise', 'hertz', 'avis', 'europcar', 'sixt', 'zipcar'], 'travel_car_hire'],
        [['food', 'lunch', 'dinner', 'breakfast', 'meal', 'sandwich', 'coffee', 'pret', 'greggs', 'costa', 'starbucks', 'subway', 'wasabi', 'leon', 'itsu', 'mcdonalds', 'nandos'], 'travel_subsistence'],
        [['kit', 'cable', 'cables', 'gaffa', 'gaff', 'tape', 'batteries', 'battery', 'screwfix', 'toolstation', 'cpc', 'thomann', 'canford', 'studiospares', 'consumables', 'gels'], 'materials'],
        [['stamps', 'postage', 'post office', 'royal mail', 'stationery', 'printing'], 'stationery'],
        [['ppe', 'boots', 'gloves', 'hi-vis', 'hi vis', 'workwear', 'blacks'], 'clothing'],
    ];

    /** The six chips the phone shows before you have a history of your own: [label, category code]. */
    public const DEFAULT_CHIPS = [['Parking', 'travel_parking'], ['Fuel', 'travel_fuel'], ['Food', 'travel_subsistence'], ['Taxi', 'travel_taxi'], ['Train', 'travel_rail'], ['Kit', 'materials']];

    /** The category code the words usually mean, or null. */
    public static function codeFor(string $text): ?string
    {
        $text = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}&\- ]+/u', ' ', $text) ?? $text).' ';
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        foreach (self::HINTS as [$words, $code]) {
            foreach ($words as $w) {
                if (str_contains($text, ' '.$w.' ')) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** The category the words usually mean, or null. */
    public static function categoryFor(string $text): ?Category
    {
        $code = self::codeFor($text);

        return $code ? Category::query()->where('code', $code)->where('kind', 'expense')->first() : null;
    }

    /**
     * The list as the phone takes it: [{words, category_id, code}].
     *
     * @return list<array{words:list<string>, category_id:?int, code:string}>
     */
    public static function forPhone(): array
    {
        $ids = Category::query()->whereIn('code', array_column(self::HINTS, 1))->pluck('id', 'code');

        return array_map(fn (array $h) => ['words' => $h[0], 'category_id' => isset($ids[$h[1]]) ? (int) $ids[$h[1]] : null, 'code' => $h[1]], self::HINTS);
    }
}
