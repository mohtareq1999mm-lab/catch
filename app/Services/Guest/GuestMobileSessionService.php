<?php

namespace App\Services\Guest;

use App\Enums\GuestErrorCode;
use App\Exceptions\GuestSessionRejection;
use App\Models\GuestIdentity;
use Illuminate\Http\Request;

/**
 * Mobile Guest Session lifecycle policy (D1/D6/D9, A3/A4).
 *
 * The controller stays thin: this module owns "may a session be opened?",
 * "must the caller be Bearer-authenticated?", rotation and revocation. The
 * raw token is returned exactly once, at open/rotate.
 */
class GuestMobileSessionService
{
    public function __construct(private GuestIdentityService $identities)
    {
    }

    /**
     * A4: a usable credential already presented is NOT a resume — it is a
     * 409. Never create a second identity.
     *
     * @return array{identity: GuestIdentity, token: string}
     */
    public function open(Request $request): array
    {
        if ($this->identities->current($request) !== null) {
            throw new GuestSessionRejection(
                GuestErrorCode::MOBILE_SESSION_EXISTS,
                409,
                'guest_checkout.mobile_session_exists'
            );
        }

        [$identity, $token] = $this->identities->createForMobile($request);

        return ['identity' => $identity, 'token' => $token];
    }

    /**
     * A3: rotation requires the live Bearer credential; the old token dies
     * immediately.
     *
     * @return array{identity: GuestIdentity, token: string}
     */
    public function rotate(Request $request): array
    {
        $identity = $this->requireBearer($request);

        return [
            'identity' => $identity,
            'token' => $this->identities->rotateMobileToken($request, $identity),
        ];
    }

    /**
     * A3: revocation requires the live Bearer credential. Cookies are cleared
     * only when the request actually carried them (S5).
     */
    public function revoke(Request $request): void
    {
        $identity = $this->requireBearer($request);

        $this->identities->revokeMobile($identity);

        if ($request->cookie(GuestIdentityService::cookieName()) !== null) {
            $this->identities->clearCookies();
        }
    }

    private function requireBearer(Request $request): GuestIdentity
    {
        $identity = $this->identities->current($request);

        if (!$identity || $this->identities->authMode($request) !== GuestIdentityService::MODE_BEARER) {
            throw new GuestSessionRejection(
                null,
                401,
                'guest_checkout.session_required'
            );
        }

        return $identity;
    }
}
