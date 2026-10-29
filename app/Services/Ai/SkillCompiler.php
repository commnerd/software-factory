<?php

namespace App\Services\Ai;

use App\Models\AiSkill;
use App\Models\AiSkillVersion;
use Illuminate\Support\Arr;

/**
 * Compiles a database-stored skill version into the final system + user prompts.
 *
 *   {{ name }}        variable (dot paths allowed: {{ project.language }})
 *   {{> slug }}       include another skill (kind=snippet) — its user_template body
 */
class SkillCompiler
{
    private const MAX_INCLUDE_DEPTH = 3;

    public function compile(AiSkillVersion $version, array $variables = []): CompiledPrompt
    {
        $missing = [];

        $system = $version->system_prompt !== null
            ? $this->render($version->system_prompt, $variables, $missing)
            : null;
        $user = $this->render($version->user_template, $variables, $missing);

        return new CompiledPrompt($system, $user, array_values(array_unique($missing)));
    }

    /** Variable names referenced by a template (for the editor's "detected variables" hint). */
    public function detectVariables(string ...$templates): array
    {
        $found = [];
        foreach ($templates as $template) {
            if (preg_match_all('/\{\{\s*([\w.\-]+)\s*\}\}/', $template, $m)) {
                array_push($found, ...$m[1]);
            }
        }

        return array_values(array_unique($found));
    }

    private function render(string $template, array $variables, array &$missing, int $depth = 0): string
    {
        if ($depth < self::MAX_INCLUDE_DEPTH) {
            $template = preg_replace_callback('/\{\{>\s*([\w\-]+)\s*\}\}/', function ($m) use ($variables, &$missing, $depth) {
                $snippet = AiSkill::with('currentVersion')->where('slug', $m[1])->where('is_active', true)->first();

                if (! $snippet?->currentVersion) {
                    $missing[] = '>'.$m[1];

                    return '';
                }

                return $this->render($snippet->currentVersion->user_template, $variables, $missing, $depth + 1);
            }, $template);
        }

        return preg_replace_callback('/\{\{\s*([\w.\-]+)\s*\}\}/', function ($m) use ($variables, &$missing) {
            if (! Arr::has($variables, $m[1])) {
                $missing[] = $m[1];

                return '';
            }

            return $this->stringify(Arr::get($variables, $m[1]));
        }, $template);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) || $value === null => (string) $value,
            default => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
