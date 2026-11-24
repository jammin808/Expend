<?php

namespace App\Domain\Ai;

/** A model response, reduced to what the app needs. */
final class AiResult
{
    /**
     * @param  list<array{type:string, text?:string, citations?:list<string>, id?:string, name?:string, input?:array}>  $blocks
     * @param  list<array{url:string, title:string}>  $sources  pages the model searched or read on the web
     */
    public function __construct(
        public string $stopReason,
        public array $blocks,
        public mixed $assistantContent,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public array $sources = [],
        public int $webSearches = 0,
        public bool $webUnavailable = false,
    ) {}

    /**
     * The answer text. After a web search the answer is the text that follows the last search or page read
     * ("let me look that up" before it is dropped), and text split around citations is joined back together.
     */
    public function text(): string
    {
        $blocks = $this->blocks;
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if (in_array($blocks[$i]['type'], ['server_tool_use', 'web_search_tool_result', 'web_fetch_tool_result'], true)) {
                $after = array_slice($blocks, $i + 1);
                if (array_filter($after, fn ($b) => $b['type'] === 'text' && trim($b['text'] ?? '') !== '')) {
                    $blocks = $after;
                }
                break;
            }
        }

        $out = '';
        $cited = false;
        foreach ($blocks as $b) {
            if ($b['type'] !== 'text') {
                continue;
            }
            $hasCitations = ! empty($b['citations']);
            $out .= ($out === '' || $cited || $hasCitations ? '' : "\n").$b['text'];
            $cited = $hasCitations;
        }

        return trim($out);
    }

    /** @return list<array{id:string,name:string,input:array}> */
    public function toolUses(): array
    {
        return array_values(array_filter($this->blocks, fn ($b) => $b['type'] === 'tool_use'));
    }
}
