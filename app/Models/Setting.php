<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Simple key/value settings. Keys listed in ENCRYPTED are stored encrypted (credentials, private feed URLs).
 */
class Setting extends Model
{
    public const ENCRYPTED = ['calendar_ics_url', 'anthropic_api_key'];

    public const DEFAULTS = [
        'home_postcode' => null,
        'scottish_taxpayer' => false,
        'calendar_ics_url' => null,
        'overnight_near_miles' => 12,
        'overnight_far_miles' => 40,
        'early_start' => '08:00',
        'late_finish' => '22:00',
        'ai_suggestions' => true,
        'ai_extraction' => 'on_demand',
        'mileage_threshold_scope' => 'all_vehicles',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);
        if (! $row || $row->value === null) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        $raw = in_array($key, self::ENCRYPTED, true) ? Crypt::decryptString($row->value) : $row->value;

        return json_decode($raw, true);
    }

    public static function put(string $key, mixed $value): void
    {
        $json = json_encode($value);
        $stored = $value === null ? null : (in_array($key, self::ENCRYPTED, true) ? Crypt::encryptString($json) : $json);
        static::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
    }

    /** @return array<string, mixed> all settings except encrypted ones (those report only whether they're set). */
    public static function publicAll(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = in_array($key, self::ENCRYPTED, true) ? (static::get($key) !== null) : static::get($key, $default);
        }

        return $out;
    }
}
