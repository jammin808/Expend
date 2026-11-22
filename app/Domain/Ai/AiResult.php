<?php

namespace App\Domain\Ai;

/** A model response, reduced to what the app needs. */
final class AiResult
{
    /**
     * @param  list<array{type:string, text?:string, id?:string, name?:string, input?:array}>  $blocks
     */
    public function __construct(
        public string $stopReason,
        public array $blocks,
        public mixed $assistantContent,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function text(): string
    {
        return trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter($this->blocks, fn ($b) => $b['type'] === 'text'))));
    }

    /** @return list<array{id:string,name:string,input:array}> */
    public function toolUses(): array
    {
        return array_values(array_filter($this->blocks, fn ($b) => $b['type'] === 'tool_use'));
    }
}
