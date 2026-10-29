<?php

namespace App\Providers;

use App\Services\Ai\ProviderManager;
use App\Services\Execution\Contracts\Executor;
use App\Services\Execution\DockerExecutor;
use App\Services\Execution\FakeExecutor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class);

        $this->app->bind(Executor::class, fn ($app) => config('factory.executor') === 'fake'
            ? $app->make(FakeExecutor::class)
            : $app->make(DockerExecutor::class));
    }

    public function boot(): void
    {
        //
    }
}
