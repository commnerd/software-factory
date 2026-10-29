<?php

namespace App\Services\Ai;

use App\Models\ModelConfiguration;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\FakeProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use Illuminate\Support\Manager;

/**
 * Adapter layer: one driver per vendor protocol. Add a driver (e.g. wrapping the
 * Laravel AI SDK) by adding a create{Name}Driver() method and a config entry.
 */
class ProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'fake';
    }

    public function forModel(ModelConfiguration $model): AiProvider
    {
        return $this->driver($model->provider);
    }

    protected function createAnthropicDriver(): AiProvider
    {
        return new AnthropicProvider($this->config->get('factory.providers.anthropic'));
    }

    protected function createOpenaiDriver(): AiProvider
    {
        return new OpenAiProvider($this->config->get('factory.providers.openai'));
    }

    protected function createOllamaDriver(): AiProvider
    {
        return new OpenAiProvider($this->config->get('factory.providers.ollama'), requiresKey: false);
    }

    protected function createFakeDriver(): AiProvider
    {
        return new FakeProvider;
    }
}
