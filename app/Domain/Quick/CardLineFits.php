<?php

namespace App\Domain\Quick;

use App\Models\BankTransaction;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Cash or a personal card was chosen for a receipt that a card payment fits, and you haven't said it isn't that one:
 * nothing is added until you say ("Is it this one?"), so the cost is never counted twice.
 */
final class CardLineFits extends InvalidArgumentException
{
    public function __construct(public readonly BankTransaction $payment)
    {
        parent::__construct('A card payment fits this receipt: '.self::describe($payment).'. Is it this one?');
    }

    /** "Starling, 26 Nov, £12.50" */
    public static function describe(BankTransaction $line): string
    {
        $line->loadMissing('account');

        return ($line->account?->name ?? 'Your card').', '.$line->effectiveDate()->format('j M').', '.Money::format(abs((int) $line->amount_pence), 'GBP', false);
    }
}
