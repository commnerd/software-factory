<?php

namespace App\Services\Execution;

use App\Exceptions\FactoryException;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** On-disk project workspace + a DB mirror of every file the factory writes. */
class Workspace
{
    public function path(Project $project): string
    {
        $dir = rtrim(config('factory.workspace_path'), '/').'/'.$project->slug;
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  list<array{path: string, content: string}>  $files
     * @return list<string> written relative paths
     */
    public function write(Project $project, array $files, ?ProjectTask $task = null): array
    {
        $root = $this->path($project);
        $written = [];

        foreach ($files as $file) {
            $relative = $this->safeRelative((string) ($file['path'] ?? ''));
            $content = (string) ($file['content'] ?? '');

            File::ensureDirectoryExists(dirname("{$root}/{$relative}"));
            File::put("{$root}/{$relative}", $content);

            $artifact = ProjectArtifact::firstOrNew(['project_id' => $project->id, 'path' => $relative]);
            $artifact->fill([
                'project_task_id' => $task?->id ?? $artifact->project_task_id,
                'kind' => $this->classify($relative),
                'content' => $content,
                'revision' => $artifact->exists ? $artifact->revision + 1 : 1,
            ])->save();

            $written[] = $relative;
        }

        return $written;
    }

    /** @param list<string> $paths @return array<string, ?string> path => previous content (null = did not exist) */
    public function snapshot(Project $project, array $paths): array
    {
        $root = $this->path($project);
        $snap = [];

        foreach ($paths as $path) {
            $relative = $this->safeRelative($path);
            $snap[$relative] = File::exists("{$root}/{$relative}") ? File::get("{$root}/{$relative}") : null;
        }

        return $snap;
    }

    /** @param array<string, ?string> $snapshot */
    public function restore(Project $project, array $snapshot): void
    {
        $root = $this->path($project);

        foreach ($snapshot as $relative => $content) {
            if ($content === null) {
                File::delete("{$root}/{$relative}");
                ProjectArtifact::where('project_id', $project->id)->where('path', $relative)->delete();

                continue;
            }

            $this->write($project, [['path' => $relative, 'content' => $content]]);
        }
    }

    public function initGit(Project $project): void
    {
        $root = $this->path($project);

        if (! is_dir("{$root}/.git")) {
            (new Process(['git', 'init', '-q'], $root))->run();
        }
    }

    /** Commit everything in the workspace locally (used by the deployment kick). */
    public function commit(Project $project, string $message): bool
    {
        $root = $this->path($project);
        $this->initGit($project);

        $env = [
            'GIT_AUTHOR_NAME' => 'Software Factory', 'GIT_AUTHOR_EMAIL' => 'factory@localhost',
            'GIT_COMMITTER_NAME' => 'Software Factory', 'GIT_COMMITTER_EMAIL' => 'factory@localhost',
        ];

        (new Process(['git', 'add', '-A'], $root, $env))->run();
        $commit = new Process(['git', 'commit', '-q', '-m', $message], $root, $env);
        $commit->run();

        return $commit->isSuccessful();
    }

    public function safeRelative(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) || str_starts_with($path, '.git/')) {
            throw new FactoryException("Refusing unsafe file path '{$path}'.");
        }

        return $path;
    }

    public function classify(string $path): string
    {
        return match (true) {
            str_starts_with($path, '.github/') => 'workflow',
            (bool) preg_match('#(^|/)(tests?|spec|__tests__)/|[._-](test|spec)\.|Test\.\w+$|_test\.\w+$#i', $path) => 'test',
            str_ends_with($path, '.md') => 'doc',
            default => 'source',
        };
    }
}
