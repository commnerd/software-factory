<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\SdlcStage;
use App\Models\Project;
use App\Models\ProjectArtifact;

/** Builds the variable bag that skill templates receive. */
class ProjectContext
{
    public static function basic(Project $project): array
    {
        $profile = $project->profile();

        return [
            'name' => $project->name,
            'description' => $project->description,
            'language' => $project->language,
            'language_label' => $profile['label'] ?? $project->language,
            'framework' => $project->framework ?? 'none specified',
            'test_command' => $profile['test'],
            'test_dir' => $profile['test_dir'],
            'container_image' => $profile['image'],
        ];
    }

    public static function stageOutput(Project $project, SdlcStage $stage): string
    {
        return $project->knowledge($stage);
    }

    /**
     * What the factory learned when it assessed an existing repository ('' for greenfield projects).
     * Skills splice this in so stages extend the real codebase instead of designing from scratch.
     */
    public static function existingState(Project $project): string
    {
        if (! $project->existing_code || ! $project->assessment) {
            return '';
        }

        $a = $project->assessment;
        $inv = $a['inventory'] ?? [];

        $lines = [
            'This project was imported from an existing repository. Do NOT start from scratch: keep what exists, build on it.',
            'Assessment summary: '.($a['summary'] ?? ''),
            'Stack: '.($a['detected']['language'] ?? '?').' / '.($a['detected']['framework'] ?? '?'),
            'Top-level layout: '.implode(', ', $inv['top_level'] ?? []),
            'Existing tests: '.($inv['test_file_count'] ?? 0).' file(s); CI workflows: '.implode(', ', $inv['ci_workflows'] ?? []),
        ];

        if (! empty($a['baseline'])) {
            $lines[] = "Baseline test run before any change: {$a['baseline']['status']} ({$a['baseline']['passed']}/{$a['baseline']['total']} passed)";
        }
        foreach ($a['stages'] ?? [] as $key => $s) {
            $lines[] = "- {$key}: {$s['status']}".(($s['evidence'] ?? '') !== '' ? " — {$s['evidence']}" : '');
        }
        foreach ($a['risks'] ?? [] as $risk) {
            $lines[] = "Risk: {$risk}";
        }

        return implode("\n", $lines);
    }

    /**
     * Current workspace files, capped so prompts stay bounded. $hints (suggested paths, keywords)
     * float the most relevant files to the front — essential once a repo has hundreds of files.
     *
     * @param  list<string>  $hints
     * @return array<string, string> path => content
     */
    public static function files(Project $project, int $maxFiles = 40, int $maxBytes = 80_000, array $hints = []): array
    {
        $out = [];
        $bytes = 0;

        $artifacts = ProjectArtifact::where('project_id', $project->id)->where('kind', '!=', 'workflow')->orderBy('path')->get();

        if ($hints) {
            $tokens = array_values(array_filter(array_map('strtolower', $hints), fn ($h) => strlen($h) > 2));
            $score = function (ProjectArtifact $a) use ($tokens) {
                $path = strtolower($a->path);
                $best = 0;
                foreach ($tokens as $t) {
                    $best = max($best, match (true) {
                        $path === $t => 4,
                        basename($path) === basename($t) => 3,
                        str_contains($path, $t) => 2,
                        default => 0,
                    });
                }

                return $best;
            };
            $artifacts = $artifacts->sortByDesc($score)->values();
        }

        foreach ($artifacts as $artifact) {
            $bytes += strlen($artifact->content);
            if (count($out) >= $maxFiles || $bytes > $maxBytes) {
                break;
            }
            $out[$artifact->path] = $artifact->content;
        }

        return $out;
    }
}
