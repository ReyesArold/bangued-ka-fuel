<?php

namespace App\Http\Middleware;

use App\Http\HttpError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to full administrators.
 *
 * RequireAdmin admits admins AND station managers, because a manager has to be
 * able to change fuel prices. This is the second gate, applied only to the
 * routes that create, delete or re-point stations - abilities StationPolicy
 * grants to administrators alone.
 *
 * It sits in front of those routes so a manager gets a flat 403 for
 * POST /api/admin/stations without the controller ever loading a body, rather
 * than a 403 from deep inside validation. The policy check still runs behind
 * it: this middleware cannot know which station a route parameter refers to, so
 * it answers "managers never get here" and authorize() answers "and not this
 * particular station either".
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('auth_user');

        if (! $user->isAdmin()) {
            throw HttpError::forbidden('Administrator access required.');
        }

        return $next($request);
    }
}
