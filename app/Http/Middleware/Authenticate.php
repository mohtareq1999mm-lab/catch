<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Handle an unauthenticated user.
     *
     * This application is API-first and has no named "login" route, so
     * unauthenticated requests must always fail with a 401 response
     * instead of being redirected to a non-existent login page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards
     * @return never
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new AuthenticationException('Unauthenticated.', $guards);
    }
}
