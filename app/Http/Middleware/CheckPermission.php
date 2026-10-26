<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            throw ApiException::unauthorized();
        }

        $required = array_filter(explode('|', $permission));

        foreach ($required as $slug) {
            if ($user->can(trim($slug))) {
                return $next($request);
            }
        }

        throw ApiException::forbidden('You do not have permission to perform this action ('.implode(', ', $required).')');
    }
}
