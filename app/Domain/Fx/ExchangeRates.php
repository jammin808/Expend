<?php

namespace App\Domain\Fx;

use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Converts other currencies to pounds using HMRC's monthly exchange rates (cached), unless you've set a rate.
 * Only used when there's no sterling amount already (a bank's sterling figure is always the real cost).
 */
final class ExchangeRates
{
    public const SOURCE_URL = 'https://www.trade-tariff.service.gov.uk/api/v2/exchange_rates/files/monthly_csv_%s.csv';

    /** @return array{units_per_gbp:string, period_start:string, source:string} */
    public function rateFor(string $currency, CarbonImmutable $date): array
    {
        $currency = strtoupper($currency);
        if ($currency === 'GBP') {
            return ['units_per_gbp' => '1', 'period_start' => $date->format('Y-m-d'), 'source' => 'none'];
        }

        $month = $date->startOfMonth();
        $rate = $this->stored($currency, $month, 'manual') ?? $this->stored($currency, $month, 'hmrc_monthly');

        if (! $rate) {
            $this->fetchMonth($month);
            $rate = $this->stored($currency, $month, 'hmrc_monthly');
        }

        if (! $rate) {
            throw new RateUnavailable("HMRC has no {$currency} rate for {$month->format('F Y')}. Enter the rate yourself.");
        }

        return [
            'units_per_gbp' => (string) BigDecimal::of($rate->units_per_gbp)->stripTrailingZeros(),
            'period_start' => $rate->period_start->format('Y-m-d'),
            'source' => $rate->source,
        ];
    }

    /**
     * @return array{pence:int, units_per_gbp:string, rate_date:string, source:string}
     */
    public function toPence(int $originalMinor, string $currency, CarbonImmutable $date): array
    {
        $rate = $this->rateFor($currency, $date);
        $amount = Money::ofMinor($originalMinor, Currency::of(strtoupper($currency)))->getAmount();
        $pounds = $amount->dividedBy($rate['units_per_gbp'], 2, RoundingMode::HALF_UP);

        return [
            'pence' => $pounds->getUnscaledValue()->toInt(),
            'units_per_gbp' => $rate['units_per_gbp'],
            'rate_date' => $rate['period_start'],
            'source' => $rate['source'],
        ];
    }

    public function setManual(string $currency, CarbonImmutable $month, string $unitsPerGbp): void
    {
        $this->save(strtoupper($currency), $month->startOfMonth(), 'manual', $unitsPerGbp, overwrite: true);
    }

    /** Store a rate. Matches on the calendar date, so the stored time part never causes a duplicate. */
    private function save(string $currency, CarbonImmutable $month, string $source, string $units, bool $overwrite): void
    {
        $existing = $this->stored($currency, $month, $source);
        if ($existing) {
            if ($overwrite) {
                $existing->update(['units_per_gbp' => $units]);
            }

            return;
        }

        ExchangeRate::query()->create([
            'currency' => $currency, 'period_start' => $month->format('Y-m-d'), 'period_end' => $month->endOfMonth()->format('Y-m-d'),
            'source' => $source, 'units_per_gbp' => $units,
        ]);
    }

    public function fetchMonth(CarbonImmutable $month): int
    {
        $response = Http::timeout(15)->get(sprintf(self::SOURCE_URL, $month->format('Y-m')));
        if (! $response->successful()) {
            return 0;
        }

        $lines = preg_split('/\r?\n/', trim($response->body()));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $codeIdx = array_search('Currency Code', $headers, true);
        $rateIdx = array_search('Currency Units per £1', $headers, true);
        if ($codeIdx === false || $rateIdx === false) {
            return 0;
        }

        $count = 0;
        $seen = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            $code = strtoupper(trim($cells[$codeIdx] ?? ''));
            $units = trim($cells[$rateIdx] ?? '');
            // Some currencies appear once per country that uses them (for example XOF); the first entry wins.
            if (strlen($code) !== 3 || ! is_numeric($units) || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $this->save($code, $month, 'hmrc_monthly', $units, overwrite: true);
            $count++;
        }

        return $count;
    }

    private function stored(string $currency, CarbonImmutable $month, string $source): ?ExchangeRate
    {
        return ExchangeRate::query()->where('currency', $currency)->where('source', $source)
            ->whereDate('period_start', $month->format('Y-m-d'))->first();
    }
}
