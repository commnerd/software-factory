<?php

namespace Tests;

use Database\Seeders\FactorySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

abstract class TestCase extends BaseTestCase
{
    /** Per-test scratch directory (workspaces, staging, throw-away git repos). */
    protected string $scratch;

    /** Seed starter models/skills/routes (fake provider only: no API keys in tests). */
    protected function seedFactory(): void
    {
        $this->seed(FactorySeeder::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->scratch = storage_path('framework/testing/'.uniqid('run_', true));
        File::ensureDirectoryExists($this->scratch);

        config([
            'factory.workspace_path' => $this->scratch.'/workspaces',
            'factory.self.staging_path' => $this->scratch.'/staging',
            'factory.self.staged_path' => $this->scratch.'/staged',
            'factory.import.local_roots' => [$this->scratch.'/repos'],
            'factory.executor' => 'fake',
        ]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->scratch));
    }

    /**
     * Create a real git repository with one commit.
     *
     * @param  array<string, string>  $files  path => content (empty array = repository with no commits)
     */
    protected function makeRepo(array $files, string $name = 'repo'): string
    {
        $dir = "{$this->scratch}/repos/{$name}";
        File::ensureDirectoryExists($dir);

        $env = ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@t', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@t'];
        $git = function (array $args) use ($dir, $env) {
            $p = new Process(['git', ...$args], $dir, $env);
            $p->mustRun();
        };

        $git(['init', '-q', '-b', 'main']);

        if ($files) {
            foreach ($files as $path => $content) {
                File::ensureDirectoryExists(dirname("{$dir}/{$path}"));
                File::put("{$dir}/{$path}", $content);
            }
            $git(['add', '-A']);
            $git(['commit', '-q', '-m', 'Initial commit']);
        }

        return $dir;
    }
}
