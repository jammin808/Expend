<?php

namespace App\Domain\Import;

use Carbon\CarbonImmutable;

/**
 * The details a statement line carries besides its date and amount: the time, the place, the card used and the kind
 * of payment, read from whichever columns the bank's export has (Amex's Town/City, Revolut's started time, a card
 * number column) or, for fixed-width card lines ("TESCO STORES 3021    LEEDS     GB"), from the line itself.
 */
final class LineDetails
{
    private const TIME_KEYS = ['time', 'transaction time', 'started date', 'completed date', 'date started (utc)', 'date completed (utc)', 'date started', 'date completed', 'transaction date', 'date', 'created', 'timestamp'];

    private const PLACE_KEYS = ['address', 'merchant address', 'town/city', 'town', 'city', 'merchant city', 'merchant town', 'location', 'merchant location', 'postcode', 'post code', 'country', 'merchant country'];

    private const CARD_KEYS = ['card no.', 'card number', 'card', 'card last four', 'card last 4', 'last 4 digits', 'card ending'];

    private const TYPE_KEYS = ['type', 'transaction type', 'payment type', 'method'];

    private const COUNTRIES = ['UNITED KINGDOM', 'GB', 'GBR', 'UK', 'GREAT BRITAIN'];

    /**
     * ISO 3166 country codes, two and three letters: the country a card payment abroad ends with. Not VAT (the
     * Vatican's), which on a bank line is the tax ("HMRC VAT").
     */
    private const ISO_COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS '
        .'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE '
        .'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM '
        .'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC '
        .'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA '
        .'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
        .'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO '
        .'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW ABW AFG AGO AIA '
        .'ALA ALB AND ARE ARG ARM ASM ATA ATF ATG AUS AUT AZE BDI BEL BEN BES BFA BGD BGR BHR BHS BIH BLM '
        .'BLR BLZ BMU BOL BRA BRB BRN BTN BVT BWA CAF CAN CCK CHE CHL CHN CIV CMR COD COG COK COL COM CPV '
        .'CRI CUB CUW CXR CYM CYP CZE DEU DJI DMA DNK DOM DZA ECU EGY ERI ESH ESP EST ETH FIN FJI FLK FRA '
        .'FRO FSM GAB GBR GEO GGY GHA GIB GIN GLP GMB GNB GNQ GRC GRD GRL GTM GUF GUM GUY HKG HMD HND HRV '
        .'HTI HUN IDN IMN IND IOT IRL IRN IRQ ISL ISR ITA JAM JEY JOR JPN KAZ KEN KGZ KHM KIR KNA KOR KWT '
        .'LAO LBN LBR LBY LCA LIE LKA LSO LTU LUX LVA MAC MAF MAR MCO MDA MDG MDV MEX MHL MKD MLI MLT MMR '
        .'MNE MNG MNP MOZ MRT MSR MTQ MUS MWI MYS MYT NAM NCL NER NFK NGA NIC NIU NLD NOR NPL NRU NZL OMN '
        .'PAK PAN PCN PER PHL PLW PNG POL PRI PRK PRT PRY PSE PYF QAT REU ROU RUS RWA SAU SDN SEN SGP SGS '
        .'SHN SJM SLB SLE SLV SMR SOM SPM SRB SSD STP SUR SVK SVN SWE SWZ SXM SYC SYR TCA TCD TGO THA TJK '
        .'TKL TKM TLS TON TTO TUN TUR TUV TWN TZA UGA UKR UMI URY USA UZB VCT VEN VGB VIR VNM VUT WLF '
        .'WSM XKK YEM ZAF ZMB ZWE';

    /**
     * The state or province a card payment in the US, Canada or Australia ends with instead of a country ("AMAZON WEB
     * SERVICES     SEATTLE     WA"): kept with the town, as the line has it.
     */
    private const REGIONS = 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND '
        .'OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY PR '  // US states, DC and Puerto Rico
        .'AB BC MB NB NL NS NT NU ON PE QC SK YT '              // Canadian provinces and territories
        .'NSW VIC QLD TAS ACT';                                // Australian states (WA and NT are above; SA is a country code too)

    /**
     * Codes UK banks end a line with to say what kind of payment it is (DD direct debit, SO standing order, BGC bank
     * giro credit, TFR transfer...). Two are also countries (SO Somalia, CR Costa Rica), and on a bank line they're
     * the payment's kind, so they're never read as a place.
     */
    private const PAYMENT_CODES = ['SO', 'CR', 'DR', 'DD', 'DDR', 'STO', 'SOR', 'BGC', 'BCC', 'BBP', 'BP', 'FT', 'FPI', 'FPO', 'FPS', 'TFR', 'CHG', 'CPT', 'DEB', 'OBP', 'POS', 'ATM', 'CHQ', 'INT', 'DPC', 'BAC', 'PAY'];

    /** Words that make a "town" a payment's description or reference instead (DIRECT DEBIT, RENT, BUSINESS TAX). */
    private const NOT_A_PLACE = '/\b(?:DEBIT|CREDIT|ORDER|PAYMENTS?|TRANSFER|TFR|REFUND|INVOICE|RENT|WAGES|SALARY|SAVINGS|INTEREST|CHARGES?|FEES?|CASH|CHEQUE|DEPOSIT|LOAN|MORTGAGE|TAX|VAT|HMRC|REF|REFERENCE|EXPENSES|FREELANCE)\b|\.(?:COM|CO|NET|ORG|UK|IO)\b/';

    /** @return array{time:?string, place:?string, card_last4:?string, payment_type:?string} */
    public static function from(array $raw, ?string $description = null, ?CarbonImmutable $date = null): array
    {
        $byKey = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $byKey[mb_strtolower(trim($k))] = trim((string) $v);
            }
        }

        return [
            'time' => self::time($byKey, $date),
            'place' => self::place($byKey) ?? self::placeFromText((string) $description),
            'card_last4' => self::card($byKey),
            'payment_type' => self::type($byKey),
        ];
    }

    /**
     * The time of day, as written. A moment given in UTC (the column says so, or the value ends in Z or +01:00) is
     * shown in UK time, but only when that falls on the line's own date: a payment at 23:30 UTC on 3 July was made at
     * 00:30 on 4 July, and a line kept under 3 July would otherwise show a time a day out. The time is left off then.
     */
    private static function time(array $row, ?CarbonImmutable $date): ?string
    {
        // Revolut Business adds local-time columns once you choose a time zone ("Date started (Europe/London)"):
        // read before the UTC ones, in their own zone, and given in UK time, as the line's date is.
        foreach (['started', 'completed'] as $which) {
            foreach ($row as $key => $v) {
                if (! preg_match('#^date '.$which.' \(([a-z_]+(?:/[a-z_+\-]+)+)\)$#', (string) $key, $z)
                    || ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{1,2}):(\d{2})/', trim((string) $v), $m) || (int) $m[2] > 23) {
                    continue;
                }
                try {
                    static $zones = null;
                    $zones ??= collect(\DateTimeZone::listIdentifiers())->keyBy(fn (string $id) => strtolower($id))->all();
                    $zone = new \DateTimeZone($zones[$z[1]] ?? throw new \InvalidArgumentException('unknown time zone'));
                    $uk = CarbonImmutable::parse("{$m[1]} {$m[2]}:{$m[3]}", $zone)->setTimezone('Europe/London');
                } catch (\Throwable) {
                    continue;
                }

                return $date && $uk->format('Y-m-d') !== $date->format('Y-m-d') ? null : $uk->format('H:i');
            }
        }
        foreach (self::TIME_KEYS as $key) {
            $v = $row[$key] ?? '';
            if (! preg_match('/(?:^|[ T])(\d{1,2}):(\d{2})(?::\d{2})?/', $v, $m) || (int) $m[1] > 23) {
                continue;
            }
            if ($m[1] === '00' && $m[2] === '00' && ! str_contains($key, 'time')) {
                continue; // a date written with a midnight time has no real time
            }
            $time = sprintf('%02d:%s', (int) $m[1], $m[2]);
            $moment = null;
            if (str_contains($key, 'utc')) {
                $day = preg_match('/\d{4}-\d{2}-\d{2}/', $v, $d) ? $d[0] : $date?->format('Y-m-d');
                $moment = $day ? CarbonImmutable::parse("{$day} {$time}", 'UTC') : null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(?:Z|[+-]\d{2}:?\d{2})$/i', $v)) {
                try {
                    $moment = CarbonImmutable::parse($v);
                } catch (\Throwable) {
                    $moment = null;
                }
            }
            if ($moment) {
                $uk = $moment->setTimezone('Europe/London');

                return $date && $uk->format('Y-m-d') !== $date->format('Y-m-d') ? null : $uk->format('H:i');
            }

            return $time;
        }

        return null;
    }

    private static function place(array $row): ?string
    {
        $parts = [];
        foreach (self::PLACE_KEYS as $key) {
            $v = preg_replace('/\s+/', ' ', $row[$key] ?? '');
            if ($v === '' || in_array(mb_strtoupper($v), array_map('mb_strtoupper', $parts), true)) {
                continue;
            }
            $parts[] = $v;
        }
        $parts = array_values(array_filter($parts, fn ($p) => ! in_array(mb_strtoupper($p), self::COUNTRIES, true) || count($parts) === 1));
        if (! $parts) {
            return null;
        }

        return mb_substr(implode(', ', array_map(fn ($p) => self::title($p), $parts)), 0, 200);
    }

    /**
     * "TESCO STORES 3021      LEEDS        GB": the town (and a country other than the UK, or a US state, Canadian
     * province or Australian state) at the end of a card line. Only a real country or state code counts, and not a
     * bank's code for the kind of payment, or a "town" that's really the payment's description ("BRITISH GAS
     * DIRECT DEBIT    DD" has no place).
     */
    private static function placeFromText(string $text): ?string
    {
        $m = self::textEnd($text);
        if (! $m || self::isPayment($m)) {
            return null;
        }
        [$town, $code] = $m;
        $uk = in_array($code, self::COUNTRIES, true);
        if (! $uk && ! in_array($code, explode(' ', self::ISO_COUNTRIES), true) && ! in_array($code, explode(' ', self::REGIONS), true)) {
            return null;
        }

        return self::title($town).($uk ? '' : ', '.$code);
    }

    /**
     * Whether a fixed-width line ends with a payment's kind or description where a card line has its town and
     * country ("BRITISH GAS     DIRECT DEBIT    DD"): such a line has no place in its text.
     */
    public static function isPaymentText(string $text): bool
    {
        $m = self::textEnd($text);

        return $m !== null && self::isPayment($m);
    }

    /** @return array{0:string, 1:string}|null the "town" and the code a fixed-width line ends with */
    private static function textEnd(string $text): ?array
    {
        if (! preg_match('/\S\s{2,}([A-Z][A-Z \'.-]{2,28}?)\s{2,}([A-Z]{2,3})\s*$/', $text, $m)) {
            return null;
        }

        return [trim($m[1]), $m[2]];
    }

    /** @param  array{0:string, 1:string}  $end */
    private static function isPayment(array $end): bool
    {
        return in_array($end[1], self::PAYMENT_CODES, true) || (bool) preg_match(self::NOT_A_PLACE, $end[0]);
    }

    private static function card(array $row): ?string
    {
        foreach (self::CARD_KEYS as $key) {
            if (preg_match('/(\d{4})\D*$/', $row[$key] ?? '', $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function type(array $row): ?string
    {
        foreach (self::TYPE_KEYS as $key) {
            $v = $row[$key] ?? '';
            if ($v !== '' && mb_strlen($v) <= 40 && ! preg_match('/^\d/', $v)) {
                return self::title(str_replace('_', ' ', $v));
            }
        }

        return null;
    }

    private static function title(string $s): string
    {
        // Postcodes and short codes stay as they are; words become "Title Case".
        if (preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', $s) || mb_strlen($s) <= 3) {
            return mb_strtoupper($s);
        }

        return mb_strtoupper($s) === $s ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
    }
}
