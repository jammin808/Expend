<?php

namespace App\Domain\Ai;

/** A model response, reduced to what the app needs. */
final class AiResult
{
    /** Blocks the server's web tools add: a search or page read and its result. */
    public const SERVER_BLOCKS = ['server_tool_use', 'web_search_tool_result', 'web_fetch_tool_result'];

    /** Words that announce a look-up: "Let me check the Act.", "I’ll search GOV.UK for the current rate." */
    private const ANNOUNCES_LOOKUP = '/\b(?:let\s+me|let[\'’]s|i[\'’]ll|i\s+will|i[\'’]m\s+going\s+to|i\s+am\s+going\s+to|i\s+need\s+to|i\s+want\s+to|i[\'’]d\s+like\s+to)'
        .'\s+(?:(?:just|also|now|first|quickly|then|briefly)\s+)?(?:check|double[-\s]check|confirm|verify|look|search|find|read|fetch|see|open|pull|do\s+a\s+(?:quick\s+)?(?:search|check))\b'
        .'|^(?:checking|searching|fetching|looking\s+(?:up|for|into))\b/iu';

    /**
     * A text block's "cited" says it has a citation of any kind (a search result, a page read, a document); its
     * "citations" lists the web pages it cites.
     *
     * @param  list<array{type:string, text?:string, cited?:bool, citations?:list<string>, id?:string, name?:string, input?:array}>  $blocks
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
     * The answer text. The text is split into parts wherever a web search or page read sits between them, and every
     * part is kept except a "let me look that up" that comes right before a search and is followed by more text: one
     * short, uncited sentence that announces the look-up, with no money figure or link. Anything longer is kept even
     * when nothing cites it, as it may be advice from the law library or a page read. Text split around citations is
     * joined back together; the parts are joined with a blank line.
     */
    public function text(): string
    {
        // Runs of text between server-tool blocks, each noting whether a search or page read comes right after it.
        $parts = [];
        $current = [];
        foreach ($this->blocks as $b) {
            if ($b['type'] === 'text') {
                $current[] = $b;
            } elseif (in_array($b['type'], self::SERVER_BLOCKS, true) && $current) {
                $parts[] = ['blocks' => $current, 'beforeSearch' => $b['type'] === 'server_tool_use'];
                $current = [];
            }
        }
        if ($current) {
            $parts[] = ['blocks' => $current, 'beforeSearch' => false];
        }
        $parts = array_values(array_filter($parts, fn ($p) => trim(implode('', array_column($p['blocks'], 'text'))) !== ''));

        $out = [];
        foreach ($parts as $i => $part) {
            $text = self::join($part['blocks']);
            $bridging = $part['beforeSearch'] && $i < count($parts) - 1
                && ! array_filter($part['blocks'], fn ($b) => self::isCited($b)) && self::announcesLookup($text);
            if (! $bridging) {
                $out[] = $text;
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * Whether text is only a "let me look that up": one sentence of at most 150 characters that announces a look-up,
     * with no money figure or link. Errs towards no, as dropping advice is worse than keeping a stray line.
     */
    private static function announcesLookup(string $text): bool
    {
        return mb_strlen($text) <= 150
            && ! preg_match('/[.!?…]["”’)\]]*\s+\S|\n/u', $text)
            && ! preg_match('#[£$€]\s?\d|https?://#iu', $text)
            && (bool) preg_match(self::ANNOUNCES_LOOKUP, $text);
    }

    /** Whether a text block cites anything: a web page, a page read or a document. */
    private static function isCited(array $block): bool
    {
        return ! empty($block['citations']) || ! empty($block['cited']);
    }

    /** Text blocks joined back together: text split around a citation runs on, other blocks start a new line. */
    private static function join(array $blocks): string
    {
        $out = '';
        $cited = false;
        foreach ($blocks as $b) {
            $hasCitations = self::isCited($b);
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
