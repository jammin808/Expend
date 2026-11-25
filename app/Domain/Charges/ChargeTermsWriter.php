<?php

namespace App\Domain\Charges;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Tax\TaxParameters;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saving how a client (their usual) or a job (where it differs) charges travel and expenses. Yours only (a tap in the
 * app or on Telegram; never automation), audited as charge_terms.updated with the rows before and after. It isn't
 * ledger data: no tax figure reads it, so a locked year doesn't block it.
 *
 * A save gives the owner's whole arrangement: kinds left out are removed (a job then follows its client's usual for
 * them). When driving is charged per mile and fuel isn't named, fuel isn't charged ("Per-mile covers fuel").
 *
 * The presets are ordinary rows: 'Everything at cost', 'Mileage at 55p + fares at cost' (HMRC's car rate for this tax
 * year; fuel not charged), 'It's in my rate' (nothing charged) and 'Fixed travel fee' (fares not charged).
 */
final class ChargeTermsWriter
{
    public const PRESETS = ['at_cost', 'mileage', 'in_rate', 'travel_fee'];

    /** Why fuel isn't charged when driving is charged per mile. */
    public const PER_MILE_NOTE = 'Per-mile covers fuel';

    /**
     * Make the owner's arrangement exactly these rows.
     *
     * @param  array<string, string|array{charge:string, rate_minor?:?int, currency?:?string}|null>  $rows  by kind of
     *                                                                                                      cost; a bare charge ('at_cost') or the row; null leaves that kind out
     * @param  string|null  $fp  fingerprint() of the rows you were shown: refused when they changed since
     * @return array<string, array{charge:string, rate_minor:?int, currency:string}> the rows now
     *
     * @throws InvalidArgumentException a kind or charge that doesn't fit, a missing rate, a merged client, or a stale save
     */
    public function set(Client|WorkJob $owner, array $rows, ?string $reason = null, ?string $fp = null): array
    {
        self::yours();
        $rows = self::normalise($rows);

        return DB::transaction(function () use ($owner, $rows, $reason, $fp) {
            AuditLog::lockHead();
            $owner = $owner::query()->lockForUpdate()->find($owner->getKey()) ?? throw new InvalidArgumentException('That '.($owner instanceof Client ? 'client' : 'job').' no longer exists.');
            if ($owner instanceof Client && $owner->merged_into_id !== null) {
                $into = Client::query()->find($owner->merged_into_id);
                throw new InvalidArgumentException("{$owner->name} is merged into ".($into?->name ?? 'another client').': change '.($into?->name ?? 'that client').'’s arrangement instead.');
            }
            $existing = ChargeTerm::query()->ownedBy($owner)->orderBy('id')->lockForUpdate()->get()->keyBy('class');
            $before = ChargeTerms::own($owner);
            if ($fp !== null && ! hash_equals(self::fingerprintOf($before), $fp)) {
                throw new InvalidArgumentException('This changed since you opened it: look again. Nothing was saved.');
            }
            if ($rows === $before) {
                return $before;
            }
            foreach ($existing as $class => $term) {
                if (! isset($rows[$class])) {
                    $term->delete();
                }
            }
            $key = $owner instanceof Client ? ['client_id' => $owner->id, 'work_job_id' => null] : ['client_id' => null, 'work_job_id' => $owner->id];
            foreach ($rows as $class => $row) {
                $term = $existing[$class] ?? new ChargeTerm(['owner_key' => ChargeTerm::ownerKey($owner), 'class' => $class] + $key);
                $term->fill($row)->save();
            }
            $after = ChargeTerms::own($owner);
            AuditLog::record('charge_terms.updated', $owner, ['terms' => $before], ['terms' => $after],
                $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 250) : null);

            return $after;
        });
    }

    /**
     * Save a preset as the owner's arrangement.
     *
     * @param  int|null  $rateMinor  the rate a mile ('mileage': HMRC's car rate when not given) or the travel fee ('travel_fee')
     * @param  string  $per  for a travel fee: per_job or per_day
     */
    public function applyPreset(Client|WorkJob $owner, string $preset, ?int $rateMinor = null, string $per = 'per_job', ?string $reason = null, ?string $fp = null): array
    {
        return $this->set($owner, self::preset($preset, $rateMinor, $per), $reason ?? self::label($preset, $rateMinor), $fp);
    }

    /**
     * A preset's rows, every kind of cost named.
     *
     * @return array<string, array{charge:string, rate_minor:?int, currency:string}>
     */
    public static function preset(string $preset, ?int $rateMinor = null, string $per = 'per_job'): array
    {
        if (! in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException("There's no preset '{$preset}'.");
        }
        $rows = [];
        foreach (CostClass::CLASSES as $class => $charges) {
            // Fares and stays at cost; never a fine (it can be charged, but isn't by default).
            $rows[$class] = in_array($preset, ['at_cost', 'mileage'], true) && in_array('at_cost', $charges, true) && $class !== 'fines' ? 'at_cost' : 'none';
        }
        if ($preset === 'mileage') {
            $rows['mileage'] = ['charge' => 'per_mile', 'rate_minor' => $rateMinor ?? self::mileageRate()];
            $rows['fuel'] = 'none';
        }
        if ($preset === 'travel_fee') {
            if (! in_array($per, ['per_job', 'per_day'], true)) {
                throw new InvalidArgumentException('A travel fee is per job or per day.');
            }
            if ($rateMinor === null || $rateMinor <= 0) {
                throw new InvalidArgumentException('Say how much the travel fee is.');
            }
            $rows['travel_fee'] = ['charge' => $per, 'rate_minor' => $rateMinor];
        }

        return self::normalise($rows);
    }

    /** What a preset is called on the button: "Mileage at 55p + fares at cost". */
    public static function label(string $preset, ?int $rateMinor = null): string
    {
        return match ($preset) {
            'at_cost' => 'Everything at cost',
            'mileage' => 'Mileage at '.CostWords::rate($rateMinor ?? self::mileageRate()).' + fares at cost',
            'in_rate' => 'It’s in my rate',
            'travel_fee' => 'Fixed travel fee',
            default => throw new InvalidArgumentException("There's no preset '{$preset}'."),
        };
    }

    /** HMRC's simplified mileage rate for a car (pence a mile, first 10,000 miles) in a tax year (this one by default). */
    public static function mileageRate(?string $taxYear = null): int
    {
        $params = TaxParameters::for($taxYear ?? UkTaxYear::current()->label());

        return (int) ($params['mileage']['car_van_first'] ?? 45);
    }

    /** The owner's rows as the editor loads them, so a save can be tied to them. */
    public static function fingerprint(Client|WorkJob $owner): string
    {
        return self::fingerprintOf(ChargeTerms::own($owner));
    }

    private static function fingerprintOf(array $rows): string
    {
        ksort($rows);

        return substr(hash('sha256', json_encode($rows)), 0, 16);
    }

    /**
     * Rows as stored: each kind checked, a bare charge made a row, the rate kept only where it's used, and fuel not
     * charged when driving is per mile and fuel isn't named.
     *
     * @return array<string, array{charge:string, rate_minor:?int, currency:string}>
     */
    private static function normalise(array $rows): array
    {
        $out = [];
        foreach ($rows as $class => $row) {
            if ($row === null) {
                continue;
            }
            if (! isset(CostClass::CLASSES[$class])) {
                throw new InvalidArgumentException("'{$class}' isn't a kind of cost.");
            }
            $row = is_string($row) ? ['charge' => $row] : $row;
            $charge = (string) ($row['charge'] ?? '');
            if (! CostClass::allows($class, $charge)) {
                throw new InvalidArgumentException(CostClass::label($class).' can’t be charged that way.');
            }
            $rate = in_array($charge, ['none', 'at_cost'], true) ? null : (isset($row['rate_minor']) ? (int) $row['rate_minor'] : null);
            if (ChargeTerm::needsRate($class, $charge) && ($rate === null || $rate <= 0)) {
                throw new InvalidArgumentException(CostClass::label($class).': say the rate.');
            }
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'GBP'))) ?: 'GBP';
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvalidArgumentException("'{$currency}' isn't a currency.");
            }
            $out[$class] = ['charge' => $charge, 'rate_minor' => $rate, 'currency' => $currency];
        }
        if (($out['mileage']['charge'] ?? null) === 'per_mile' && ! isset($out['fuel'])) {
            $out['fuel'] = ['charge' => 'none', 'rate_minor' => null, 'currency' => 'GBP'];
        }
        ksort($out);

        return $out;
    }

    /** Arrangements are yours: never automation's. A Telegram tap counts as you. */
    private static function yours(): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException('Only you can change how travel and expenses are charged.');
        }
    }
}
