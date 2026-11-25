<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Shipping\RepresentativeAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeliveryRepresentative
{
    public function __construct(private readonly RepresentativeAccessService $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $representative = $this->access->resolveActiveRepresentative($user);
        $request->attributes->set('deliveryRepresentative', $representative);

        return $next($request);
    }
}
