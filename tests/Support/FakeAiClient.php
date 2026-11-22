<?php

namespace Tests\Support;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;

/** Replays scripted model responses and records every request. */
class FakeAiClient implements AiClient
{
    public array $requests = [];

    /** @param list<AiResult> $responses */
    public function __construct(public array $responses = []) {}

    public static function json(array $data): AiResult
    {
        return new AiResult('end_turn', [['type' => 'text', 'text' => json_encode($data)]], [], 'claude-opus-5-5', 100, 50);
    }

    public static function text(string $text): AiResult
    {
        return new AiResult('end_turn', [['type' => 'text', 'text' => $text]], [['type' => 'text', 'text' => $text]], 'claude-opus-5-5', 100, 50);
    }

    public static function tool(string $name, array $input, string $id = 'tu_1'): AiResult
    {
        $block = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];

        return new AiResult('tool_use', [$block], [$block], 'claude-opus-5-5', 100, 50);
    }

    public function send(array $params): AiResult
    {
        $this->requests[] = $params;

        return array_shift($this->responses) ?? self::text('(no scripted response)');
    }
}
