<?php

namespace App\Support;

/**
 * Install/test commands run inside a throw-away container that has no Docker, no Compose and no Sail.
 * Commands copied from a developer's host workflow (`vendor/bin/sail artisan test`, `docker compose …`)
 * can never work there, so they are rejected up front with an explanation instead of failing mid-pipeline.
 */
class SandboxCommand
{
    public static function problem(?string $command): ?string
    {
        if ($command === null || $command === '') {
            return null;
        }

        if (preg_match('/(?:^|[\s;&|(\/=])(sail|docker-compose|docker|podman)(?=\s|$)/i', $command, $m)) {
            return sprintf(
                'The command uses "%s", which is not available inside the isolated test container (it has no Docker). '
                .'Use the direct command instead — e.g. "php artisan test" rather than "vendor/bin/sail artisan test", '
                .'and "composer install" rather than "vendor/bin/sail composer install".',
                strtolower($m[1]),
            );
        }

        return null;
    }
}
