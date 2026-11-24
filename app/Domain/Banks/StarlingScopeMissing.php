<?php

namespace App\Domain\Banks;

use RuntimeException;

/** Starling refused to show your Spaces: the token was made without the permission to read them. */
final class StarlingScopeMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Starling’s token can’t read your Spaces. To show the tax pot, make a new token with '.StarlingSpaces::SCOPES
            .' ticked as well (Settings › Integrations): everything else carries on without it.');
    }
}
