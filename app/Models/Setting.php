<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Simple key/value settings. Keys listed in ENCRYPTED are stored encrypted (credentials, private feed URLs).
 */
class Setting extends Model
{
    public const ENCRYPTED = ['calendar_ics_url', 'anthropic_api_key', 'imap_password', 'telegram_bot_token', 'telegram_webhook_secret', 'business_bank_details', 'google_client_secret', 'gmail_refresh_token', 'gmail_access_token', 'smtp_password', 'starling_token'];

    public const DEFAULTS = [
        'home_postcode' => null,
        'scottish_taxpayer' => false,
        // Making Tax Digital for Income Tax: the tax year HMRC accepted you as a volunteer for the new penalties from
        // (their confirmation letter), the tax year an exemption from MTD runs from, and whether HMRC treat the earlier
        // requirements as never imposed. They decide which penalties apply. mtd_exempt is the old tick with no year
        // (read as exempt from 2026-27); saving Settings replaces it.
        'mtd_volunteer_from' => null,
        'mtd_exempt_from' => null,
        'mtd_exempt_never_required' => false,
        'mtd_exempt' => false,
        // Weeks you were self-employed (and aged 16 to State Pension age) in each year with compulsory Class 2 National
        // Insurance, e.g. ['2022-23' => 30]. A year not listed counts as 52 weeks, a full year.
        'class2_weeks' => [],
        'calendar_ics_url' => null,
        'overnight_near_miles' => 12,
        'overnight_far_miles' => 40,
        'early_start' => '08:00',
        'late_finish' => '22:00',
        'ai_suggestions' => true,
        'job_finder_auto' => true,
        'ai_extraction' => 'on_demand',
        'mileage_threshold_scope' => 'all_vehicles',
        'ai_model' => 'claude-opus-5-5',
        'ai_effort' => 'medium',
        'ai_monthly_cap_usd' => 40,
        'ai_web_research' => true,
        'own_names' => [],
        'own_other_accounts' => [],
        'business_name' => null,
        'business_address' => null,
        'business_email' => null,
        'business_bank_details' => null,
        'invoice_prefix' => 'INV-',
        'invoice_terms_days' => 30,
        'imap_host' => null,
        'imap_port' => 993,
        'imap_username' => null,
        'imap_password' => null,
        'allowed_senders' => [],
        'telegram_bot_token' => null,
        'telegram_webhook_secret' => null,
        'telegram_chat_id' => null,
        'telegram_pairing_code' => null,
        'anthropic_api_key' => null,
        // The nightly cycle (docs/intelligence-cycle.md 3.3 and 6): off (the old nightly schedule only), shadow (a
        // trial: it only looks and records what it would do) or on. Switched with php artisan ledger:cycle mode.
        'intel_cycle' => 'off',
        // Its AI: at most this much a night (USD), and the share of the monthly cap it never uses (the assistant's).
        'intel_ai_night_usd' => 1.5,
        'intel_ai_reserve_pct' => 30,
        // Critical findings sent to Telegram at once: at most this many a day.
        'intel_alerts_per_day' => 3,
        // The morning report: when it's sent (London time), and full (titles and amounts) or counts (counts and a link).
        'intel_report_time' => '07:30',
        'intel_report_detail' => 'full',
        // A business cost over this (in pence) with no receipt or document is a finding (cost.no_receipt).
        'intel_receipt_threshold_pence' => 10000,
        // How prominent each kind of finding is: {code: {level: raised|lowered|demoted, at, reason?}}. Your raise and
        // lower on the to-do page, and kinds demoted by their false alarms (Intel\DetectorLevels).
        'intel_detector_levels' => [],
        // How the forwarding mailbox last went, {ok, at, error?}: written by ledger:fetch-email when it changes, read by
        // the health check (health.mailbox), which never repeats the error.
        'mailbox_last_result' => null,
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** @var array<string, ?string>|null  stored values read inside remembering(), by key (null: not set) */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$memo !== null && array_key_exists($key, self::$memo)) {
            $value = self::$memo[$key];
        } else {
            $value = static::query()->find($key)?->value;
            if (self::$memo !== null) {
                self::$memo[$key] = $value;
            }
        }
        if ($value === null) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        $raw = in_array($key, self::ENCRYPTED, true) ? Crypt::decryptString($value) : $value;

        return json_decode($raw, true);
    }

    public static function put(string $key, mixed $value): void
    {
        $json = json_encode($value);
        $stored = $value === null ? null : (in_array($key, self::ENCRYPTED, true) ? Crypt::encryptString($json) : $json);
        static::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        if (self::$memo !== null) {
            unset(self::$memo[$key]);
        }
    }

    /**
     * Run $work reading each setting from the database once (a setting saved meanwhile is read afresh). For work that
     * reads the same settings many times, such as the penalty estimate, which asks about Making Tax Digital for every
     * year. Only for the length of the call, so a long-running process never keeps a stale value.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public static function remembering(callable $work): mixed
    {
        $outer = self::$memo === null;
        self::$memo ??= [];
        try {
            return $work();
        } finally {
            if ($outer) {
                self::$memo = null;
            }
        }
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
