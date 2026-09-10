<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
class VirifiyEmailMiddleware
{
    use ApiResponse;
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->email !== null && $request->user()->email_verified_at === null) {
            return $this->apiResponse(PLEASE_VERIFY_YOUR_EMAIL, 401,false);
        }

        return $next($request);
    }
}