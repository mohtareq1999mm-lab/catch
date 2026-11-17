<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Marvel\Database\Models\SocialLoginCode;
use Marvel\Database\Models\User;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\SocialLoginExchangeRequest;
use Marvel\Http\Resources\UserResource;

class SocialController extends CoreController
{
    /**
     * Redirect the browser to the OAuth provider (Google / Facebook).
     *
     * Accepts an optional `type` query parameter (`web` | `mobile`, default `web`).
     * The type is carried through the OAuth flow via the `state` parameter so the
     * callback knows whether to redirect (web) or return a JSON response (mobile).
     */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        $this->validateProvider($provider);

        $url = Socialite::driver($provider)
            ->stateless()
            ->redirectUrl($this->callbackUrl($provider))
            ->with(['state' => $this->clientType($request)])
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'success' => true,
            'url' => $url,
        ], 200);
    }

    /**
     * Backwards-compatible alias: GET /api/v1/social/redirect?provider=google
     */
    public function redirectFromQuery(Request $request): JsonResponse
    {
        return $this->redirect($request, (string) $request->query('provider'));
    }

    /**
     * Handle the OAuth provider callback.
     *
     * On success it issues a single-use authorization code. For web clients the
     * browser is redirected to the frontend; for mobile clients a JSON response
     * with the code is returned instead. The API token is never placed in the URL.
     */
    public function callback(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        $this->validateProvider($provider);

        $type = $this->clientType($request);

        // Captured before the local `$provider` variable is reused for the
        // Provider model below, so failure logs always name the provider.
        $providerName = $provider;

        try {
            $socialUser = Socialite::driver($provider)
                ->stateless()
                ->redirectUrl($this->callbackUrl($provider))
                ->user();

            $email = $socialUser->getEmail();
            $providerId = $socialUser->getId();

            // Find existing user by provider identity first
            $provider = \Marvel\Database\Models\Provider::where('provider', $providerName)
                ->where('provider_user_id', $providerId)
                ->first();

            if ($provider) {
                $user = $provider->user;
            } else {
                // Link by email only when email is non-null, else create fresh
                if ($email) {
                    $user = User::firstOrCreate(
                        ['email' => $email],
                        [
                            'email_verified_at' => now(),
                            'name' => $socialUser->getName(),
                            'password' => Hash::make(Str::random(32)),
                            'type' => 'user',
                            'is_active' => true,
                        ]
                    );
                } else {
                    $user = User::create([
                        'email' => null,
                        'email_verified_at' => null,
                        'name' => $socialUser->getName(),
                        'password' => Hash::make(Str::random(32)),
                        'type' => 'user',
                        'is_active' => true,
                        'phone_number' => null,
                    ]);
                }
            }

            // Mobile Authentication tokens are restricted to active customers.
            // Reject before linking a provider identity or creating a code.
            // The single eligibility decision lives in the post-auth
            // authentication module (#53).
            if (!app(\App\Services\Customer\PostAuthenticationService::class)->isEligibleCustomer($user)) {
                throw new MarvelException(SOCIAL_LOGIN_FAILED);
            }

            if (!$provider) {
                $user->providers()->updateOrCreate(
                    [
                        'provider' => $providerName,
                        'provider_user_id' => $providerId,
                    ]
                );
            }

            $authorizationCode = SocialLoginCode::create([
                'user_id' => $user->id,
                'code' => bin2hex(random_bytes(32)),
                'expires_at' => now()->addMinutes((int) config('shop.social_login.code_ttl_minutes', 10)),
                'used' => false,
            ]);

            if ($type === 'mobile') {
                return response()->json([
                    'success' => true,
                    'code' => $authorizationCode->code,
                ], 200);
            }

            return redirect()->away($this->frontendUrl($providerName) . '/?code=' . $authorizationCode->code);
        } catch (\Throwable $e) {
            // Context key `exception` must never hold a string: Laravel's test
            // LoggedExceptionCollection pushes `context['exception']` and
            // later calls ->getMessage() on it.
            Log::error('Social login callback failed', [
                'provider' => $providerName,
                'type' => $type,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($type === 'mobile') {
                return response()->json([
                    'success' => false,
                    'message' => __('message.' . SOCIAL_LOGIN_FAILED),
                ], 400);
            }

            return redirect()->away($this->frontendUrl($providerName) . '/auth?error=social_login_failed');
        }
    }

    /**
     * Exchange a reusable authorization code for a Sanctum token.
     *
     * The code stays valid — and reusable — until its original `expires_at`.
     * It is never consumed, mutated, or deleted here, so multiple exchanges
     * (including concurrent ones) each succeed with their own distinct token,
     * without any locks or transactions. The legacy `used`/`used_at` columns
     * are intentionally ignored for backward compatibility.
     */
    public function exchange(SocialLoginExchangeRequest $request): JsonResponse
    {
        $code = SocialLoginCode::query()
            ->where('code', $request->validated('code'))
            ->where('expires_at', '>', now())
            ->first();

        if (!$code) {
            return $this->invalidCodeResponse();
        }

        $user = User::where('id', $code->user_id)
            ->where('type', 'user')
            ->where('is_active', true)
            ->first();

        if (!$user || !app(\App\Services\Customer\PostAuthenticationService::class)->isEligibleCustomer($user)) {
            return $this->invalidCodeResponse();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $data = [
            'success' => true,
            'message' => __('message.MESSAGE.SOCIAL_LOGIN_SUCCESSFUL'),
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('roles')),
        ];

        // Credential issued: the authentication moment (both web and mobile
        // clients exchange this code). One post-authentication module (#53)
        // runs currency adoption and the guest cart Login Merge exactly once,
        // non-fatal; additive response key only when something merged.
        $merge = app(\App\Services\Customer\PostAuthenticationService::class)
            ->afterAuthentication($user, $request);
        if ($merge !== null) {
            $data['guest_cart_merge'] = $merge;
        }

        return response()->json($data, 200);
    }

    protected function validateProvider(string $provider): void
    {
        if (!in_array($provider, ['facebook', 'google'], true)) {
            throw new MarvelException(PLEASE_LOGIN_USING_FACEBOOK_OR_GOOGLE);
        }
    }

    /**
     * Resolve the client type (web|mobile). Defaults to web.
     *
     * On the redirect request the type is sent as `type`; on the callback it is
     * echoed back by the OAuth provider as `state`. Both are optional and any
     * unknown value falls back to `web` so existing clients are unaffected.
     */
    protected function clientType(Request $request): string
    {
        $type = strtolower((string) $request->query('type', $request->query('state', 'web')));

        return in_array($type, ['web', 'mobile'], true) ? $type : 'web';
    }

    /**
     * The canonical OAuth callback URL for the provider.
     *
     * Reads the explicitly configured callback from Laravel configuration
     * (config/services.php → services.{provider}.redirect, fed by the
     * GOOGLE_REDIRECT_URI / FACEBOOK_REDIRECT_URI environment variables).
     *
     * The value must be independent of the incoming request Host header so
     * that the exact same URI is used when initiating the flow (redirect())
     * and when completing it (callback()), regardless of whether the request
     * arrives via the backend domain or a frontend proxy. Google requires an
     * exact match of this URI against the client's "Authorized redirect
     * URIs", so a request-derived value produces redirect_uri_mismatch.
     *
     * Missing configuration fails safely: the client receives the generic
     * social-login failure (500 via the exception handler on initiation; the
     * existing failure redirect/JSON on the callback path) and the operator
     * receives a precise log entry. No request-derived fallback exists.
     */
    protected function callbackUrl(string $provider): string
    {
        $configured = config("services.{$provider}.redirect");

        // A non-string (e.g. an array) misconfiguration must fail-safe too.
        if (!is_string($configured)) {
            Log::error('Social login callback URL is misconfigured: {PROVIDER}_REDIRECT_URI must be a string URL.', [
                'provider' => $provider,
            ]);

            throw new MarvelException(SOCIAL_LOGIN_FAILED);
        }

        $redirectUri = trim($configured);

        if ($redirectUri === '') {
            Log::error('Social login callback URL is not configured: set {PROVIDER}_REDIRECT_URI (services.{provider}.redirect).', [
                'provider' => $provider,
            ]);

            throw new MarvelException(SOCIAL_LOGIN_FAILED);
        }

        if (config('app.env') === 'production' && !str_starts_with($redirectUri, 'https://')) {
            Log::error('Social login callback URL must use HTTPS in production.', [
                'provider' => $provider,
            ]);

            throw new MarvelException(SOCIAL_LOGIN_FAILED);
        }

        return $redirectUri;
    }

    /**
     * Trusted frontend redirect base for the post-login redirect.
     *
     * Supports an optional provider-specific override via configuration
     * (shop.social_login.{provider}_frontend_url ← GOOGLE_FRONTEND_REDIRECT_URL
     * / FACEBOOK_FRONTEND_REDIRECT_URL). When the override is unset the
     * pre-existing generic social_login.frontend_url behavior is preserved.
     * Request-supplied URLs are never used.
     */
    protected function frontendUrl(string $provider): string
    {
        $url = (string) (
            config("shop.social_login.{$provider}_frontend_url")
            ?: config('shop.social_login.frontend_url', '')
        );

        return rtrim($url, '/');
    }

    protected function invalidCodeResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('message.ERROR.INVALID_SOCIAL_LOGIN_CODE'),
        ], 400);
    }
}
