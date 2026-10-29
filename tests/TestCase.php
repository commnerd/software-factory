<?php

namespace Tests;

use Database\Seeders\FactorySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    /** Seed starter models/skills/routes (fake provider only: no API keys in tests). */
    protected function seedFactory(): void
    {
        $this->seed(FactorySeeder::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $base = storage_path('framework/testing/'.uniqid('run_', true));
        config([
            'factory.workspace_path' => $base.'/workspaces',
            'factory.self.staging_path' => $base.'/staging',
            'factory.self.staged_path' => $base.'/staged',
            'factory.executor' => 'fake',
        ]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($base));
    }
}
