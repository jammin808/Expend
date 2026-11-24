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
            // Web search can be switched off for the account (Console › Privacy); answer without it rather than fail.
            if (! self::hasServerTools($request['tools'] ?? []) || ! preg_match('/web[_ ]?(search|fetch)|server tool|allowed_domains/i', $e->getMessage())) {
                throw $e;
            }
            $request['tools'] = array_values(array_filter($request['tools'], fn ($t) => ! self::isServerTool($t)));
            $message = $client->beta->messages->create(...$request);
            $webOff = true;
        }

        $blocks = [];
        $sources = [];
        foreach ($message->content as $block) {
            $type = self::typeOf($block);
            if ($type === 'text') {
                $cited = [];
                foreach ($block->citations ?? [] as $c) {
                    if (! empty($c->url)) {
                        $cited[] = $c->url;
                        $sources[$c->url] ??= ['url' => $c->url, 'title' => (string) ($c->title ?? '')];
                    }
                }
                $blocks[] = ['type' => 'text', 'text' => $block->text] + ($cited ? ['citations' => array_values(array_unique($cited))] : []);
            } elseif ($type === 'tool_use') {
                $blocks[] = ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            } elseif ($type === 'server_tool_use') {
                $blocks[] = ['type' => 'server_tool_use', 'name' => (string) $block->name, 'input' => (array) ($block->input ?? [])];
            } elseif ($type === 'web_search_tool_result') {
                $found = is_array($block->content) ? $block->content : [];
                foreach ($found as $r) {
                    if (! empty($r->url)) {
                        $sources[$r->url] ??= ['url' => $r->url, 'title' => (string) ($r->title ?? '')];
                    }
                }
                $blocks[] = ['type' => 'web_search_tool_result', 'count' => count($found)];
            } elseif ($type === 'web_fetch_tool_result') {
                $url = $block->content->url ?? null;
                if ($url) {
                    $title = $block->content->content->title ?? '';
                    $sources[$url] = ['url' => $url, 'title' => (string) ($title ?: ($sources[$url]['title'] ?? ''))];
                }
                $blocks[] = ['type' => 'web_fetch_tool_result', 'url' => $url];
            }
        }

        $stop = $message->stopReason;
        $stop = is_object($stop) ? $stop->value : (string) $stop;
        $model = is_object($message->model) ? $message->model->value : (string) $message->model;
        $in = (int) ($message->usage->inputTokens ?? 0);
        $out = (int) ($message->usage->outputTokens ?? 0);
        $searches = (int) ($message->usage->serverToolUse->webSearchRequests ?? 0);
        AiUsage::record($model, $in, $out, $searches);

        if ($stop === 'refusal') {
            throw new RuntimeException('The model declined this request. Try again, or read this one yourself.');
        }

        return new AiResult($stop, $blocks, $message->content, $model, $in, $out, array_values($sources), $searches, $webOff);
    }

    public static function isServerTool(array $tool): bool
    {
        return (bool) preg_match('/^web_(search|fetch)_\d{8}$/', (string) ($tool['type'] ?? ''));
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

    private static function typeOf(mixed $block): string
    {
        $type = $block->type ?? '';

        return is_object($type) ? (string) $type->value : (string) $type;
    }
}
