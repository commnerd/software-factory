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
        return (string) $project->stage($stage)->output;
    }

    /**
     * Current workspace files, capped so prompts stay bounded.
     *
     * @return array<string, string> path => content
     */
    public static function files(Project $project, int $maxFiles = 40, int $maxBytes = 80_000): array
    {
        $out = [];
        $bytes = 0;

        foreach (ProjectArtifact::where('project_id', $project->id)->where('kind', '!=', 'workflow')->orderBy('path')->get() as $artifact) {
            $bytes += strlen($artifact->content);
            if (count($out) >= $maxFiles || $bytes > $maxBytes) {
                break;
            }
            $out[$artifact->path] = $artifact->content;
        }

        return $out;
    }
}
