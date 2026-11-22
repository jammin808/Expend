<?php

namespace App\Domain\Matching;

/**
 * Turns a messy bank description into a stable merchant key, so every transaction from the same
 * merchant can be decided at once ("all TFL TRAVEL: travel, business").
 */
final class MerchantKey
{
    private const NOISE = [
        'CARD', 'PAYMENT', 'PAYMENTS', 'TO', 'FROM', 'PURCHASE', 'CONTACTLESS', 'VIS', 'VISA', 'POS', 'DEBIT',
        'CREDIT', 'ONLINE', 'FASTER', 'DIRECT', 'DD', 'SO', 'BGC', 'FPI', 'FPO', 'TFR', 'REF', 'THE', 'GB', 'GBR',
        'UK', 'LONDON', 'WWW', 'COM', 'CO', 'HTTPS', 'HTTP', 'APPLE', 'PAY', 'GOOGLE',
    ];

    public static function from(string $description, ?string $counterparty = null): string
    {
        $text = $counterparty !== null && trim($counterparty) !== '' ? $counterparty : $description;
        $text = strtoupper($text);
        $text = preg_replace('/[^A-Z0-9]+/', ' ', $text);

        $tokens = array_values(array_filter(explode(' ', $text), function (string $token) {
            return $token !== ''
                && ! preg_match('/\d/', $token)
                && ! in_array($token, self::NOISE, true)
                && strlen($token) > 1;
        }));

        $key = implode(' ', array_slice($tokens, 0, 3));

        return $key !== '' ? $key : 'UNKNOWN';
    }
}
