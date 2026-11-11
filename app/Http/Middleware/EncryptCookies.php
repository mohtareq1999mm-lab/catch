<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [
        // Guest credential + double-submit CSRF token.
        //
        // Both are excluded from transport encryption ON PURPOSE:
        //  - the API middleware group is applied twice on the v1/general
        //    routes, so EncryptCookies would decrypt (and strip the value
        //    prefix) twice, invalidating the credential;
        //  - the CSRF token must remain readable for double-submit.
        //
        // Confidentiality is preserved by HttpOnly + Secure + SameSite; the
        // database stores only a keyed hash of the guest token, never the raw
        // value.
        'meem_guest',
        'meem_guest_csrf',
    ];
}
