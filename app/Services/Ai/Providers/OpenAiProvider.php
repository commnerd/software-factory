<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\FactoryException;
use App\Services\Ai\CompletionRequest;
use App\Services\Ai\CompletionResponse;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

/** OpenAI chat-completions protocol: OpenAI, OpenRouter, Ollama, vLLM, LM Studio... */
class OpenAiProvider implements AiProvider
{
    public function __construct(private readonly array $config, private readonly bool $requiresKey = true) {}

    public function complete(CompletionRequest $request): CompletionResponse
    {
        if ($this->requiresKey && empty($this->config['key'])) {
            throw new FactoryException('OPENAI_API_KEY is not configured.');
        }

        $messages = [];
        if ($request->system) {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        $body = ['model' => $request->model, 'messages' => $messages];

        if (isset($request->parameters['temperature'])) {
            $body['temperature'] = (float) $request->parameters['temperature'];
        }
        if (isset($request->parameters['max_tokens'])) {
            $body['max_tokens'] = (int) $request->parameters['max_tokens'];
        }

        $http = Http::timeout(300);
        if (! empty($this->config['key'])) {
            $http = $http->withToken($this->config['key']);
        }

        $response = $http->post(rtrim($this->config['base_url'], '/').'/chat/completions', $body);

        if ($response->failed()) {
            throw new FactoryException('Provider error '.$response->status().': '.$response->body());
        }

        $message = $response->json('choices.0.message', []);

        return new CompletionResponse(
            (string) ($message['content'] ?? ''),
            $message['reasoning_content'] ?? $message['reasoning'] ?? null,
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
        );
    }
}
