<?php

namespace App\Services\Import;

use App\Exceptions\FactoryException;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Execution\Workspace;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Brings an existing repository into a project workspace and builds a compact,
 * factual inventory of it (what exists: docs, tests, CI, manifests, history).
 * The AI then judges *where the project stands* from these facts.
 */
class RepositoryImporter
{
    private const EXCLUDE_DIRS = ['node_modules', 'vendor', '.git', 'dist', 'build', 'target', '__pycache__', '.venv', 'venv', '.idea', '.next', 'coverage'];

    private const MANIFESTS = [
        'composer.json' => 'php', 'package.json' => 'node', 'requirements.txt' => 'python', 'pyproject.toml' => 'python',
        'setup.py' => 'python', 'go.mod' => 'go', 'pom.xml' => 'java', 'build.gradle' => 'java', 'build.gradle.kts' => 'java',
        'Gemfile' => 'ruby', 'Cargo.toml' => 'rust',
    ];

    private const EXTENSIONS = [
        'php' => 'php', 'js' => 'node', 'ts' => 'node', 'vue' => 'node', 'py' => 'python', 'go' => 'go', 'java' => 'java',
        'kt' => 'java', 'cs' => 'dotnet', 'rb' => 'ruby', 'rs' => 'rust',
    ];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Clone (first time only) and inventory the repository.
     *
     * @return array<string, mixed>|null null when there is nothing to import (no URL, or an empty repository)
     */
    public function import(Project $project): ?array
    {
        $source = trim((string) $project->repository_url);
        if ($source === '') {
            return null;
        }

        $dir = $this->workspace->path($project);

        if (! is_dir("{$dir}/.git")) {
            $this->clone($project, $source, $dir);
        }

        if (! $this->git($dir, ['rev-parse', '--verify', 'HEAD'])->isSuccessful()) {
            return null; // empty repository: treat as greenfield
        }

        $inventory = $this->inventory($dir);
        $this->mirrorArtifacts($project, $dir);
        $this->applyDetection($project, $inventory);

        return $inventory;
    }

    /** Accept only remote protocols git can fetch, or local paths under explicitly allowed roots. */
    public function assertSourceAllowed(string $source): void
    {
        if (str_starts_with($source, '/')) {
            $real = realpath($source);
            $allowed = array_filter(array_map('trim', (array) config('factory.import.local_roots')));

            foreach ($allowed as $root) {
                $rootReal = realpath($root);
                if ($real && $rootReal && ($real === $rootReal || str_starts_with($real, $rootReal.'/'))) {
                    return;
                }
            }

            throw new FactoryException('Local repository paths are only allowed under FACTORY_IMPORT_LOCAL_ROOTS.');
        }

        if (! preg_match('#^(https?://|ssh://|git://|git@[\w.\-]+:)#', $source)) {
            throw new FactoryException('Unsupported repository source. Use an https://, ssh:// or git@ URL.');
        }
    }

    private function clone(Project $project, string $source, string $dir): void
    {
        $this->assertSourceAllowed($source);

        if (count(File::files($dir, true)) > 0 || count(File::directories($dir)) > 0) {
            throw new FactoryException('The workspace already contains generated files; it cannot be replaced by an import.');
        }

        $url = $this->withToken($source);
        $cmd = ['git', 'clone', '--depth', '200', '--quiet'];
        if ($project->repository_branch) {
            array_push($cmd, '--branch', $project->repository_branch);
        }
        array_push($cmd, '--', $url, $dir);

        $process = new Process($cmd, null, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ALLOW_PROTOCOL' => 'https:http:ssh:git:file',
        ], null, (int) config('factory.import.clone_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            File::cleanDirectory($dir);

            // never echo a token that may be embedded in the URL
            throw new FactoryException('git clone failed: '.str_replace($url, $source, trim($process->getErrorOutput())));
        }

        // Don't leave a credential in .git/config.
        $this->git($dir, ['remote', 'set-url', 'origin', $source]);
    }

    private function withToken(string $source): string
    {
        $token = config('factory.import.github_token');

        if ($token && preg_match('#^https://github\.com/#', $source)) {
            return preg_replace('#^https://#', 'https://x-access-token:'.rawurlencode($token).'@', $source);
        }

        return $source;
    }

    private function git(string $dir, array $args): Process
    {
        $p = new Process(['git', ...$args], $dir, ['GIT_TERMINAL_PROMPT' => '0']);
        $p->run();

        return $p;
    }

    /** @return array<string, mixed> */
    private function inventory(string $dir): array
    {
        $finder = Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(true)->in($dir)
            ->exclude(self::EXCLUDE_DIRS)->size('< 200K');

        $total = 0;
        $exts = [];
        $manifests = [];
        $tests = [];
        $docs = [];
        $workflows = [];
        $todos = 0;
        $readme = null;

        foreach ($finder as $file) {
            if (++$total > 5000) {
                break;
            }

            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $ext = strtolower($file->getExtension());
            $exts[$ext] = ($exts[$ext] ?? 0) + 1;
            $base = $file->getFilename();

            if (isset(self::MANIFESTS[$base]) && substr_count($rel, '/') <= 1) {
                $manifests[] = $rel;
            }
            if (preg_match('#(^|/)(tests?|spec|__tests__)/|[._-](test|spec)\.\w+$|Test\.\w+$|_test\.\w+$#i', $rel)) {
                $tests[] = $rel;
            }
            if (str_starts_with($rel, '.github/workflows/')) {
                $workflows[] = $rel;
            }
            if (preg_match('#^README(\.\w+)?$#i', $base) && substr_count($rel, '/') === 0) {
                $readme = $rel;
            }
            if ((str_starts_with($rel, 'docs/') || str_starts_with($rel, 'doc/')) && in_array($ext, ['md', 'rst', 'txt'], true)) {
                $docs[] = $rel;
            }
            if (in_array($ext, ['php', 'js', 'ts', 'vue', 'py', 'go', 'java', 'kt', 'cs', 'rb', 'rs'], true) && $file->getSize() < 100_000) {
                $todos += preg_match_all('/\b(TODO|FIXME)\b/', (string) file_get_contents($file->getPathname()));
            }
        }

        arsort($exts);
        $top = array_map(fn ($p) => $p->getFilename().($p->isDir() ? '/' : ''), iterator_to_array(
            Finder::create()->in($dir)->depth(0)->ignoreDotFiles(false)->ignoreVCS(true)->sortByName()->getIterator(), false,
        ));

        $log = $this->git($dir, ['log', '-n', '10', '--date=short', '--pretty=%h %ad %s']);

        return [
            'file_count' => $total,
            'extensions' => array_slice($exts, 0, 12, true),
            'top_level' => array_slice($top, 0, 40),
            'manifests' => $manifests,
            'test_file_count' => count($tests),
            'test_files' => array_slice($tests, 0, 40),
            'has_readme' => $readme !== null,
            'readme_excerpt' => $readme ? mb_substr((string) file_get_contents("{$dir}/{$readme}"), 0, 4000) : null,
            'has_docs' => $docs !== [],
            'doc_files' => array_slice($docs, 0, 30),
            'doc_excerpts' => $this->excerpts($dir, array_slice($docs, 0, 4)),
            'ci_workflows' => $workflows,
            'todo_count' => $todos,
            'git' => [
                'branch' => trim($this->git($dir, ['rev-parse', '--abbrev-ref', 'HEAD'])->getOutput()),
                'head' => trim($this->git($dir, ['rev-parse', '--short', 'HEAD'])->getOutput()),
                'commits_in_clone' => (int) trim($this->git($dir, ['rev-list', '--count', 'HEAD'])->getOutput()),
                'recent' => array_values(array_filter(explode("\n", trim($log->getOutput())))),
            ],
            'detected' => $this->detect($dir, $manifests, $exts),
        ];
    }

    /** @return array<string, string> path => excerpt */
    private function excerpts(string $dir, array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $out[$p] = mb_substr((string) file_get_contents("{$dir}/{$p}"), 0, 2000);
        }

        return $out;
    }

    /** @return array{language: ?string, framework: ?string, test_hint: ?string} */
    private function detect(string $dir, array $manifests, array $exts): array
    {
        $language = null;
        foreach ($manifests as $m) {
            if (substr_count($m, '/') === 0 && isset(self::MANIFESTS[basename($m)])) {
                $language = self::MANIFESTS[basename($m)];
                break;
            }
        }
        if (! $language) {
            foreach ($exts as $ext => $n) {
                if (isset(self::EXTENSIONS[$ext])) {
                    $language = self::EXTENSIONS[$ext];
                    break;
                }
            }
        }
        if (! $language && (glob("{$dir}/*.csproj") || glob("{$dir}/*.sln"))) {
            $language = 'dotnet';
        }

        $framework = null;
        if (is_file("{$dir}/composer.json")) {
            $c = (string) file_get_contents("{$dir}/composer.json");
            $framework = match (true) {
                str_contains($c, 'laravel/framework') => 'Laravel',
                str_contains($c, 'symfony/framework-bundle') => 'Symfony',
                default => null,
            };
        } elseif (is_file("{$dir}/package.json")) {
            $c = (string) file_get_contents("{$dir}/package.json");
            foreach (['next' => 'Next.js', 'nuxt' => 'Nuxt', '"vue"' => 'Vue', '"react"' => 'React', 'express' => 'Express', '@nestjs/core' => 'NestJS'] as $needle => $name) {
                if (str_contains($c, $needle)) {
                    $framework = $name;
                    break;
                }
            }
        } elseif (is_file("{$dir}/requirements.txt")) {
            $c = strtolower((string) file_get_contents("{$dir}/requirements.txt"));
            $framework = collect(['django' => 'Django', 'fastapi' => 'FastAPI', 'flask' => 'Flask'])->first(fn ($n, $k) => str_contains($c, $k));
        }

        return ['language' => $language, 'framework' => $framework];
    }

    /** Mirror the most useful files into the DB so agents can read them (bounded). */
    private function mirrorArtifacts(Project $project, string $dir): void
    {
        $maxFiles = (int) config('factory.import.max_artifacts');
        $maxBytes = 1_500_000;

        $files = [];
        foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(true)->in($dir)->exclude(self::EXCLUDE_DIRS)->size('< 100K') as $f) {
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            $content = (string) file_get_contents($f->getPathname());
            if (str_contains(substr($content, 0, 8000), "\0")) {
                continue; // binary
            }
            $files[] = ['path' => $rel, 'content' => $content, 'score' => $this->priority($rel)];
        }

        usort($files, fn ($a, $b) => [$a['score'], $a['path']] <=> [$b['score'], $b['path']]);

        $bytes = 0;
        foreach (array_slice($files, 0, $maxFiles) as $f) {
            if (($bytes += strlen($f['content'])) > $maxBytes) {
                break;
            }
            ProjectArtifact::updateOrCreate(
                ['project_id' => $project->id, 'path' => $f['path']],
                ['kind' => $this->workspace->classify($f['path']), 'content' => $f['content'], 'revision' => 1],
            );
        }
    }

    /** Lower = more important to keep. */
    private function priority(string $rel): int
    {
        return match (true) {
            (bool) preg_match('#^(README|composer\.json|package\.json|go\.mod|pom\.xml|Cargo\.toml|pyproject\.toml|requirements\.txt|Gemfile)#i', $rel) => 0,
            str_starts_with($rel, 'docs/') => 1,
            str_starts_with($rel, '.github/') => 2,
            (bool) preg_match('#(^|/)(src|app|lib)/#', $rel) => 3,
            (bool) preg_match('#(^|/)(tests?|spec)/#', $rel) => 4,
            default => 5,
        };
    }

    /** Fill in what the user left to "auto" and pick tooling for stacks the default profile doesn't cover. */
    private function applyDetection(Project $project, array $inventory): void
    {
        $detected = $inventory['detected'];
        $updates = [];

        if (($project->language === 'auto' || ! $project->language) ) {
            $updates['language'] = $detected['language'] ?? 'node';
        }
        if (! $project->framework && $detected['framework']) {
            $updates['framework'] = $detected['framework'];
        }

        $manifests = array_map('basename', $inventory['manifests']);
        $config = $project->config ?? [];
        if (in_array('build.gradle', $manifests, true) || in_array('build.gradle.kts', $manifests, true)) {
            $config += ['image' => 'gradle:8-jdk21', 'install' => 'true', 'test' => 'gradle test --no-daemon'];
            $updates['config'] = $config;
        }

        if ($updates) {
            $project->update($updates);
        }
    }
}
