<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\FactoryException;
use App\Services\Ai\CompletionRequest;
use App\Services\Ai\CompletionResponse;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProvider
{
    public function __construct(private readonly array $config) {}

    public function complete(CompletionRequest $request): CompletionResponse
    {
        if (empty($this->config['key'])) {
            throw new FactoryException('ANTHROPIC_API_KEY is not configured.');
        }

        $thinkingBudget = (int) ($request->parameters['thinking_budget'] ?? 0);
        $maxTokens = (int) ($request->parameters['max_tokens'] ?? 8192);

        $body = [
            'model' => $request->model,
            'max_tokens' => max($maxTokens, $thinkingBudget + 1024),
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];

        if ($request->system) {
            $body['system'] = $request->system;
        }

        if ($thinkingBudget > 0) {
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $thinkingBudget];
        } elseif (isset($request->parameters['temperature'])) {
            $body['temperature'] = (float) $request->parameters['temperature'];
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->config['key'],
            'anthropic-version' => $this->config['version'] ?? '2023-06-01',
        ])->timeout(300)->post(rtrim($this->config['base_url'], '/').'/v1/messages', $body);

        if ($response->failed()) {
            throw new FactoryException('Anthropic error '.$response->status().': '.$response->body());
        }

        $text = '';
        $thinking = '';
        foreach ($response->json('content', []) as $block) {
            match ($block['type'] ?? null) {
                'text' => $text .= $block['text'],
                'thinking' => $thinking .= $block['thinking'],
                default => null,
            };
        }

        return new CompletionResponse(
            $text,
            $thinking !== '' ? $thinking : null,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
        );
    }
}
