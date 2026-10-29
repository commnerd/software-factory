<?php

namespace App\Services\Pipeline;

use App\Enums\FactoryStep;
use App\Models\Project;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Stages\ProjectContext;
use Throwable;

/**
 * GitHub Actions generation: a deterministic, language-aware baseline that is always
 * valid, optionally refined by the `ci_workflow` AI skill. Bad AI output falls back to baseline.
 */
class CiWorkflowGenerator
{
    public function __construct(private readonly AgentRunner $agents) {}

    /** @return list<array{path: string, content: string}> */
    public function generate(Project $project): array
    {
        $files = collect($this->baseline($project))->keyBy('path');

        try {
            $run = $this->agents->run(FactoryStep::CiWorkflow, [
                'project' => ProjectContext::basic($project),
                'baseline' => $files->values()->all(),
            ], $project);

            foreach ($run->json()['files'] ?? [] as $file) {
                $path = $file['path'] ?? '';
                $content = $file['content'] ?? '';

                if (preg_match('#^\.github/workflows/[\w.-]+\.ya?ml$#', $path) && str_contains($content, 'jobs:') && str_contains($content, 'runs-on')) {
                    $files[$path] = ['path' => $path, 'content' => rtrim($content)."\n"];
                }
            }
        } catch (Throwable $e) {
            report($e); // keep the baseline
        }

        return $files->values()->all();
    }

    /** @return list<array{path: string, content: string}> */
    public function baseline(Project $project): array
    {
        $p = $project->profile();
        $setup = $this->steps($p['gha_setup']);

        $ci = <<<YAML
        name: CI

        on:
          push:
            branches: [main]
          pull_request:

        jobs:
          test:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v4
        {$setup}
              - name: Run tests
                run: {$p['gha_test']}

          build:
            runs-on: ubuntu-latest
            needs: test
            steps:
              - uses: actions/checkout@v4
        {$setup}
              - name: Build
                run: {$p['gha_build']}
        YAML;

        $deploy = <<<YAML
        name: Deploy

        on:
          workflow_dispatch:
            inputs:
              environment:
                description: Target environment
                required: true
                default: staging
                type: choice
                options: [staging, production]

        jobs:
          deploy:
            runs-on: ubuntu-latest
            environment: \${{ inputs.environment }}
            steps:
              - uses: actions/checkout@v4
        {$setup}
              - name: Test
                run: {$p['gha_test']}
              - name: Build
                run: {$p['gha_build']}
              - name: Deploy
                run: echo "Replace with your deployment command (secrets come from the GitHub environment)."
        YAML;

        return [
            ['path' => '.github/workflows/ci.yml', 'content' => rtrim($ci)."\n"],
            ['path' => '.github/workflows/deploy.yml', 'content' => rtrim($deploy)."\n"],
        ];
    }

    /** Render setup steps at a 6-space indent. */
    private function steps(array $steps): string
    {
        $lines = [];
        foreach ($steps as $step) {
            $first = true;
            $emit = function (string $line) use (&$lines, &$first) {
                $lines[] = ($first ? '      - ' : '        ').$line;
                $first = false;
            };

            foreach ($step as $key => $value) {
                if ($key === 'with') {
                    $emit('with:');
                    foreach ($value as $k => $v) {
                        $lines[] = "          {$k}: ".(is_bool($v) ? ($v ? 'true' : 'false') : "'{$v}'");
                    }
                } else {
                    $emit("{$key}: {$value}");
                }
            }
        }

        return implode("\n", $lines);
    }
}
