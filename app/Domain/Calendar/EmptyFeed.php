<?php

namespace App\Domain\Calendar;

use RuntimeException;

/**
 * The calendar feed came back with no entries at all while some are stored. Feeds do this when Google has a bad
 * moment, so the stored entries are kept until a good feed says otherwise.
 */
final class EmptyFeed extends RuntimeException
{
    public function __construct(public readonly int $kept)
    {
        parent::__construct("The calendar feed came back with no entries, so the {$kept} ".($kept === 1 ? 'entry' : 'entries')
            .' already here '.($kept === 1 ? 'was' : 'were').' kept. If it happens again, check the feed address in Settings.');
    }
}
