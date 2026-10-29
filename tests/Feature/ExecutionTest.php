<?php

namespace Tests\Feature;

use App\Exceptions\FactoryException;
use App\Models\Project;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\CiWorkflowGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_writes_files_and_mirrors_them_in_the_database(): void
    {
        $project = Project::create(['name' => 'W', 'language' => 'node']);
        $ws = app(Workspace::class);

        $ws->write($project, [['path' => 'src/a.js', 'content' => 'v1'], ['path' => 'tests/a.test.js', 'content' => 't']]);
        $ws->write($project, [['path' => 'src/a.js', 'content' => 'v2']]);

        $this->assertSame('v2', file_get_contents($ws->path($project).'/src/a.js'));
        $artifact = $project->artifacts()->where('path', 'src/a.js')->first();
        $this->assertSame(2, $artifact->revision);
        $this->assertSame('source', $artifact->kind);
        $this->assertSame('test', $project->artifacts()->where('path', 'tests/a.test.js')->value('kind'));
    }

    public function test_workspace_rejects_path_traversal(): void
    {
        $project = Project::create(['name' => 'W', 'language' => 'node']);

        foreach (['../evil.sh', 'a/../../evil', '.git/config', ''] as $bad) {
            try {
                app(Workspace::class)->write($project, [['path' => $bad, 'content' => 'x']]);
                $this->fail("Path '{$bad}' should have been rejected");
            } catch (FactoryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_snapshot_and_restore_roll_back_a_failed_refactor(): void
    {
        $project = Project::create(['name' => 'W', 'language' => 'node']);
        $ws = app(Workspace::class);
        $ws->write($project, [['path' => 'a.js', 'content' => 'green']]);

        $snap = $ws->snapshot($project, ['a.js', 'new.js']);
        $ws->write($project, [['path' => 'a.js', 'content' => 'broken'], ['path' => 'new.js', 'content' => 'x']]);
        $ws->restore($project, $snap);

        $this->assertSame('green', file_get_contents($ws->path($project).'/a.js'));
        $this->assertFileDoesNotExist($ws->path($project).'/new.js');
    }

    public function test_language_profile_is_overridable_per_project(): void
    {
        $project = Project::create(['name' => 'W', 'language' => 'python', 'config' => ['test' => 'pytest -x', 'image' => '']]);

        $profile = $project->profile();

        $this->assertSame('pytest -x', $profile['test']);
        $this->assertSame('python:3.13', $profile['image'], 'blank overrides are ignored');
    }

    public function test_ci_baseline_is_language_aware_valid_yaml(): void
    {
        foreach (['php', 'node', 'python', 'go', 'java', 'dotnet', 'ruby', 'rust'] as $lang) {
            $project = Project::create(['name' => "P {$lang}", 'language' => $lang]);
            $files = collect(app(CiWorkflowGenerator::class)->baseline($project))->keyBy('path');

            $ci = $files['.github/workflows/ci.yml']['content'];
            $this->assertStringContainsString($project->profile()['gha_test'], $ci);
            $this->assertStringContainsString('actions/checkout@v4', $ci);
            $this->assertStringContainsString('workflow_dispatch', $files['.github/workflows/deploy.yml']['content']);

            if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                foreach ($files as $file) {
                    $parsed = \Symfony\Component\Yaml\Yaml::parse($file['content']);
                    $this->assertArrayHasKey('jobs', $parsed, "{$lang} {$file['path']}");
                }
            }
        }
    }
}
