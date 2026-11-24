<?php

namespace App\Domain\Todo;

use InvalidArgumentException;

/**
 * A to-do press on something that has changed since the button was shown (its fingerprint no longer matches:
 * Fingerprints). Nothing was done; the message says so in plain words. This is the web's wording (you're in Expend
 * already); Telegram says to open it in Expend (Telegram::changedText()).
 */
final class ChangedSince extends InvalidArgumentException
{
    public function __construct(string $message = 'This changed since the page was shown, so nothing was done. Look at it again.')
    {
        parent::__construct($message);
    }
}
