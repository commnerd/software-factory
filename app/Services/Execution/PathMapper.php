<?php

namespace App\Services\Execution;

/**
 * Translates a path inside this app (e.g. /var/www/html/storage/app/workspaces/x)
 * into the path the Docker daemon sees on the host, so `docker run -v` works
 * when the factory itself runs inside a Sail container.
 */
class PathMapper
{
    public function toHost(string $path): string
    {
        $hostRoot = config('factory.host_project_path');

        if (! $hostRoot) {
            return $path;
        }

        $root = rtrim(base_path(), '/');
        $real = realpath($path) ?: $path;

        return str_starts_with($real, $root)
            ? rtrim($hostRoot, '/').substr($real, strlen($root))
            : $real;
    }
}
