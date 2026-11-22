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
        ExchangeRate::query()->updateOrCreate(
            ['currency' => strtoupper($currency), 'period_start' => $month->startOfMonth()->format('Y-m-d'), 'source' => 'manual'],
            ['period_end' => $month->endOfMonth()->format('Y-m-d'), 'units_per_gbp' => $unitsPerGbp],
        );
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
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            $code = strtoupper(trim($cells[$codeIdx] ?? ''));
            $units = trim($cells[$rateIdx] ?? '');
            if (strlen($code) !== 3 || ! is_numeric($units)) {
                continue;
            }
            ExchangeRate::query()->updateOrCreate(
                ['currency' => $code, 'period_start' => $month->format('Y-m-d'), 'source' => 'hmrc_monthly'],
                ['period_end' => $month->endOfMonth()->format('Y-m-d'), 'units_per_gbp' => $units],
            );
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
