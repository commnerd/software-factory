<?php

namespace Tests\Feature;

use App\Exceptions\FactoryException;
use App\Models\Project;
use App\Services\Execution\TestRunner;
use App\Support\SandboxCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SandboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_host_only_tooling(): void
    {
        foreach ([
            'vendor/bin/sail artisan test',
            'LARAVEL_SAIL=1 vendor/bin/sail composer install --no-interaction',
            './vendor/bin/sail test',
            'docker compose run app phpunit',
            'composer install && docker run x',
            'docker-compose up',
            'podman build .',
        ] as $bad) {
            $this->assertNotNull(SandboxCommand::problem($bad), $bad);
        }

        foreach (['php artisan test', 'vendor/bin/phpunit --testdox', 'composer install --no-interaction --prefer-dist', 'npm test', 'go test ./...', 'cargo test', 'make dockerfile-lint', null, ''] as $ok) {
            $this->assertNull(SandboxCommand::problem($ok), (string) $ok);
        }
    }

    public function test_the_runner_fails_fast_with_an_actionable_message_instead_of_running_sail_in_a_container(): void
    {
        $project = Project::create(['name' => 'P', 'language' => 'php', 'brief' => 'x', 'config' => ['test' => 'LARAVEL_SAIL=1 vendor/bin/sail artisan test']]);

        try {
            app(TestRunner::class)->run($project, 'green');
            $this->fail('expected a FactoryException');
        } catch (FactoryException $e) {
            $this->assertStringContainsString('Project setting "test"', $e->getMessage());
            $this->assertStringContainsString('php artisan test', $e->getMessage());
        }

        $this->assertSame(0, $project->testSuites()->count());
    }

    public function test_settings_form_rejects_such_commands(): void
    {
        $project = Project::create(['name' => 'P', 'language' => 'php', 'brief' => 'x']);

        $this->put("/projects/{$project->id}/settings", ['config' => ['install' => 'vendor/bin/sail composer install']])
            ->assertSessionHasErrors('config.install');

        $this->put("/projects/{$project->id}/settings", ['config' => ['test' => 'php artisan test']])->assertSessionHasNoErrors();
        $this->assertSame('php artisan test', $project->fresh()->config['test']);
    }

    public function test_importing_a_laravel_repo_picks_a_sandbox_safe_test_command(): void
    {
        $repo = $this->makeRepo([
            'composer.json' => '{"require":{"laravel/framework":"^12"},"require-dev":{"laravel/sail":"^1"}}',
            'compose.yaml' => 'services: {}',
            'app/Foo.php' => '<?php',
        ]);
        $project = Project::create(['name' => 'L', 'language' => 'auto', 'repository_url' => $repo]);

        app(\App\Services\Import\RepositoryImporter::class)->import($project);

        $test = $project->fresh()->profile()['test'];
        $this->assertStringContainsString('key:generate', $test);
        $this->assertStringContainsString('php artisan test', $test);
        $this->assertNull(SandboxCommand::problem($test));
    }
}
