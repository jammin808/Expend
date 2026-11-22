<?php

namespace App\Domain\Ai;

use Anthropic\Client;
use App\Domain\Documents\AiConfig;
use RuntimeException;

/**
 * Calls Claude through the official PHP SDK. Uses the server-side fallback so a request the chosen model
 * declines for policy reasons is retried on its default fallback model instead of failing.
 */
final class AnthropicAiClient implements AiClient
{
    public function send(array $params): AiResult
    {
        $key = AiConfig::apiKey();
        if (! $key) {
            throw new RuntimeException('Add your Claude API key in Settings › AI first.');
        }

        AiUsage::guard();
        $client = new Client(apiKey: $key);

        $message = $client->beta->messages->create(...($params + [
            'model' => AiConfig::model(),
            'betas' => ['server-side-fallback-2026-07-01'],
            'fallbacks' => 'default',
        ]));

        $blocks = [];
        foreach ($message->content as $block) {
            $type = is_object($block->type ?? null) ? $block->type->value : ($block->type ?? '');
            if ($type === 'text') {
                $blocks[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($type === 'tool_use') {
                $blocks[] = ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        $stop = $message->stopReason;
        $stop = is_object($stop) ? $stop->value : (string) $stop;
        $model = is_object($message->model) ? $message->model->value : (string) $message->model;
        $in = (int) ($message->usage->inputTokens ?? 0);
        $out = (int) ($message->usage->outputTokens ?? 0);
        AiUsage::record($model, $in, $out);

        if ($stop === 'refusal') {
            throw new RuntimeException('The model declined this request. Try again, or read this one yourself.');
        }

        return new AiResult($stop, $blocks, $message->content, $model, $in, $out);
    }
}
