<?php

namespace App\Domain\Documents;

use App\Models\Setting;

final class AiConfig
{
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (recommended)',
        'claude-fable-5-2' => 'Claude Fable 5.2 (most capable, about twice the price; needs 30-day data retention on your API account)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (faster, cheaper)',
    ];

    public static function apiKey(): ?string
    {
        return config('services.anthropic.key') ?: Setting::get('anthropic_api_key');
    }

    public static function ready(): bool
    {
        return (bool) self::apiKey();
    }

    public static function model(): string
    {
        $model = Setting::get('ai_model');

        return isset(self::MODELS[$model]) ? $model : 'claude-opus-5-5';
    }

    public static function effort(): string
    {
        return in_array(Setting::get('ai_effort'), ['low', 'medium', 'high', 'xhigh'], true) ? Setting::get('ai_effort') : 'medium';
    }
}
