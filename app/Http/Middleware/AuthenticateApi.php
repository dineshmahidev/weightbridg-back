<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            throw ApiException::unauthorized('Missing authentication token');
        }

        $token = trim(substr($header, 7));

        $user = $this->auth->resolveToken($token);

        if (! $user) {
            throw ApiException::unauthorized('Authentication token is invalid or expired');
        }

        $request->setUserResolver(fn () => $user);

        Auth::guard('api')->setUser($user);
        Auth::shouldUse('api');

        $request->attributes->set('bearer_token', $token);

        return $next($request);
    }
}
