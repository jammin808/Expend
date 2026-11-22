<?php

namespace App\Domain\Ai;

interface AiClient
{
    /** Send a Messages API request (named parameters as in the Anthropic PHP SDK). */
    public function send(array $params): AiResult;
}
