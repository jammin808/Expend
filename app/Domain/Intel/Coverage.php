<?php

namespace App\Domain\Intel;

/**
 * How much a detector looked at this time (docs/intelligence-cycle.md section 2.2). Only a complete run can say a
 * finding has gone: one that was capped (a limit left some out) can't tell, so its missing findings are unseen; one
 * that failed found nothing, so its findings stay open.
 */
enum Coverage: string
{
    case Complete = 'complete';
    case Capped = 'capped';
    case Failed = 'failed';

    /** The less complete of the two: failed, then capped, then complete. */
    public function and(self $other): self
    {
        return match (true) {
            $this === self::Failed || $other === self::Failed => self::Failed,
            $this === self::Capped || $other === self::Capped => self::Capped,
            default => self::Complete,
        };
    }
}
