<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The factory can run containers and propose changes to itself, so it must not be
 * left open. When FACTORY_ACCESS_PASSWORD is set, HTTP Basic auth is required.
 */
class FactoryAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('factory.access_password');

        if (! $password) {
            return $next($request);
        }

        if (! hash_equals((string) $password, (string) $request->getPassword())) {
            return response('Authentication required.', 401, ['WWW-Authenticate' => 'Basic realm="Software Factory"']);
        }

        return $next($request);
    }
}
