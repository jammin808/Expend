<?php

namespace App\Domain\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\BadRequestException;
use App\Domain\Documents\AiConfig;
use RuntimeException;

/**
 * Calls Claude through the official PHP SDK. Uses the server-side fallback so a request the chosen model
 * declines for policy reasons is retried on its default fallback model instead of failing.
 *
 * When a request includes the web search and fetch tools, the pages Claude looked at come back as sources
 * (URL and title), so the app can check every link in an answer against what was actually read.
 *
 * The SDK's response objects throw on `->prop ?? default` when their class doesn't declare that property (a fetch
 * error has no url, a document citation has no title), so everything that depends on the block's variant is read
 * through field(), which uses array access and gives null for a missing key.
 */
final class AnthropicAiClient implements AiClient
{
    /** @param  array<string, mixed>|null  $requestOptions  for tests: a fake HTTP transporter */
    public function __construct(private ?array $requestOptions = null) {}

    public function send(array $params): AiResult
    {
        $key = AiConfig::apiKey();
        if (! $key) {
            throw new RuntimeException('Add your Claude API key in Settings › AI first.');
        }

        AiUsage::guard();
        $client = new Client(apiKey: $key, requestOptions: $this->requestOptions);
        $request = $params + [
            'model' => AiConfig::model(),
            'betas' => ['server-side-fallback-2026-07-01'],
            'fallbacks' => 'default',
        ];

        $webOff = false;
        try {
            $message = $client->beta->messages->create(...$request);
        } catch (BadRequestException $e) {
            if (! self::hasServerTools($request['tools'] ?? [])) {
                throw $e;
            }
            $apiMessage = trim((string) ($e->getAPIMessage() ?? $e->getMessage()));
            if (! self::webToolsSwitchedOff($apiMessage, self::domainsIn($request['tools']))) {
                if (preg_match('/web[_ ]?(search|fetch)|server tool|allowed_domains|blocked_domains/i', $apiMessage)) {
                    // A real problem with the web tools (a domain the organisation blocks, a bad setting): say what the API said.
                    throw new RuntimeException('Web search couldn’t run: '.rtrim($apiMessage, '.').'. Check the web search settings on the Claude Console (Settings › Capabilities), or turn off “Let the assistant check the law online” in Settings › Integrations.', 0, $e);
                }

                throw $e;
            }
            // An admin can switch web search or fetch off for the organisation (Claude Console › Settings › Capabilities).
            // Answer from the law library instead of failing; Assistant stops offering the web tools for the rest of the question.
            $request['tools'] = array_values(array_filter($request['tools'], fn ($t) => ! self::isServerTool($t)));
            $message = $client->beta->messages->create(...$request);
            $webOff = true;
        }

        // Count the call first, so a response the app can't read is still added to the monthly cap.
        $model = self::str($message->model);
        $usage = self::field($message, 'usage');
        $in = (int) (self::field($usage, 'inputTokens') ?? 0);
        $out = (int) (self::field($usage, 'outputTokens') ?? 0);
        $searches = (int) (self::field(self::field($usage, 'serverToolUse'), 'webSearchRequests') ?? 0);
        AiUsage::record($model, $in, $out, $searches);

        $blocks = [];
        $sources = [];
        foreach ($message->content as $block) {
            $type = self::typeOf($block);
            if ($type === 'text') {
                // Any citation marks the text as drawn from a source (a search result, a page read, a document);
                // only web search citations point at a page, so only they give a URL for the sources.
                $anyCitation = false;
                $cited = [];
                foreach (self::field($block, 'citations') ?? [] as $c) {
                    $anyCitation = true;
                    if (self::typeOf($c) !== 'web_search_result_location') {
                        continue;
                    }
                    $url = self::field($c, 'url');
                    if (is_string($url) && $url !== '') {
                        $cited[] = $url;
                        $sources[$url] ??= ['url' => $url, 'title' => (string) (self::field($c, 'title') ?? '')];
                    }
                }
                $blocks[] = ['type' => 'text', 'text' => (string) self::field($block, 'text')]
                    + ($anyCitation ? ['cited' => true] : [])
                    + ($cited ? ['citations' => array_values(array_unique($cited))] : []);
            } elseif ($type === 'tool_use') {
                $blocks[] = ['type' => 'tool_use', 'id' => (string) self::field($block, 'id'), 'name' => (string) self::field($block, 'name'), 'input' => (array) (self::field($block, 'input') ?? [])];
            } elseif ($type === 'server_tool_use') {
                $blocks[] = ['type' => 'server_tool_use', 'name' => (string) self::field($block, 'name'), 'input' => (array) (self::field($block, 'input') ?? [])];
            } elseif ($type === 'web_search_tool_result') {
                // Either a list of results or an error object (too many requests, max uses reached, ...).
                $content = self::field($block, 'content');
                $found = 0;
                foreach (is_array($content) ? $content : [] as $r) {
                    $url = self::typeOf($r) === 'web_search_result' ? self::field($r, 'url') : null;
                    if (is_string($url) && $url !== '') {
                        $found++;
                        $sources[$url] ??= ['url' => $url, 'title' => (string) (self::field($r, 'title') ?? '')];
                    }
                }
                $blocks[] = ['type' => 'web_search_tool_result', 'count' => $found] + (is_array($content) ? [] : ['error' => self::errorCode($content)]);
            } elseif ($type === 'web_fetch_tool_result') {
                // Either the page that was read or an error (not allowed, not accessible, max uses reached, ...).
                $content = self::field($block, 'content');
                $url = null;
                if (self::typeOf($content) === 'web_fetch_result') {
                    $url = self::field($content, 'url');
                    $url = is_string($url) && $url !== '' ? $url : null;
                }
                if ($url) {
                    $title = (string) (self::field(self::field($content, 'content'), 'title') ?? '');
                    $sources[$url] = ['url' => $url, 'title' => $title !== '' ? $title : ($sources[$url]['title'] ?? '')];
                }
                $blocks[] = ['type' => 'web_fetch_tool_result', 'url' => $url] + ($url ? [] : ['error' => self::errorCode($content)]);
            }
        }

        $stop = self::str($message->stopReason);
        if ($stop === 'refusal') {
            throw new RuntimeException('The model declined this request. Try again, or read this one yourself.');
        }

        return new AiResult($stop, $blocks, $message->content, $model, $in, $out, array_values($sources), $searches, $webOff);
    }

    public static function isServerTool(array $tool): bool
    {
        return (bool) preg_match('/^web_(search|fetch)_\d{8}$/', (string) ($tool['type'] ?? ''));
    }

    /**
     * Whether a 400 says web search or fetch is switched off for the organisation or the key (the API's wording is
     * "web search is not enabled"), rather than rejecting one of the tools' settings such as a domain.
     *
     * The tool may be named ("web search", "web_fetch") or given by type ("web_search_20260209"), after a field path
     * and a colon ("tools.1: web_search_20260209 is not enabled"). The sentence that says it's off must not name a
     * domain (the word, or one of $domains): a domain the organisation blocks is a setting to fix, not a switch-off.
     * Other sentences in the message don't matter ("… not enabled for your organization. An admin can set allowed
     * domains in the Console." is still a switch-off).
     *
     * @param  list<string>  $domains  the domains the request's web tools named
     */
    public static function webToolsSwitchedOff(string $apiMessage, array $domains = []): bool
    {
        $tool = '\bweb[_ ]?(?:search|fetch)(?:_\d{8})?\b.{0,40}?\b';
        $forTheAccount = (bool) preg_match('/organi[sz]ation|api key|workspace|account/i', $apiMessage);

        // A full stop or semicolon ends a sentence only before a space or the end: "bailii.org" is one word.
        foreach (preg_split('/[.;](?=\s|$)/u', $apiMessage) ?: [] as $sentence) {
            $lower = mb_strtolower($sentence);
            $namesDomain = str_contains($lower, 'domain')
                || array_filter($domains, fn ($d) => $d !== '' && str_contains($lower, preg_replace('/^www\./', '', mb_strtolower($d))));
            if ($namesDomain) {
                continue;
            }
            if (preg_match('/'.$tool.'(?:not\s+enabled|disabled|switched\s+off|turned\s+off)\b/iu', $sentence)) {
                return true;
            }
            if ($forTheAccount && preg_match('/'.$tool.'not\s+(?:allowed|available|permitted)\b/iu', $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The domains a request's web tools allow or block.
     *
     * @return list<string>
     */
    private static function domainsIn(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            if (is_array($t) && self::isServerTool($t)) {
                foreach (['allowedDomains', 'allowed_domains', 'blockedDomains', 'blocked_domains'] as $key) {
                    foreach ((array) ($t[$key] ?? []) as $d) {
                        $out[] = (string) $d;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function hasServerTools(array $tools): bool
    {
        foreach ($tools as $t) {
            if (is_array($t) && self::isServerTool($t)) {
                return true;
            }
        }

        return false;
    }

    /** One field of an SDK object or a plain array, or null when this variant doesn't have it. */
    private static function field(mixed $from, string $key): mixed
    {
        return is_array($from) || $from instanceof \ArrayAccess ? ($from[$key] ?? null) : null;
    }

    private static function typeOf(mixed $block): string
    {
        return self::str(self::field($block, 'type'));
    }

    private static function errorCode(mixed $error): string
    {
        return self::str(self::field($error, 'errorCode') ?? self::field($error, 'error_code')) ?: 'error';
    }

    private static function str(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_scalar($value) ? (string) $value : '');
    }
}
