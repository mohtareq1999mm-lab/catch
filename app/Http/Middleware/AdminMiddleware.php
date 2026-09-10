<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->type !== UserType::ADMIN->value) {
            abort(403, __('message.' . ERROR.NOT_AUTHORIZED));
        }

        return $next($request);
    }
}
