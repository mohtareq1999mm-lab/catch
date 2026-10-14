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

        try {
            $socialUser = Socialite::driver($provider)
                ->stateless()
                ->redirectUrl($this->callbackUrl($provider))
                ->user();

            $email = $socialUser->getEmail();
            $providerId = $socialUser->getId();
            $providerName = $provider;

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
                        ]
                    );
                } else {
                    $user = User::create([
                        'email' => null,
                        'email_verified_at' => null,
                        'name' => $socialUser->getName(),
                        'password' => Hash::make(Str::random(32)),
                        'type' => 'user',
                        'phone_number' => null,
                    ]);
                }

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
                'expires_at' => now()->addMinutes((int) config('shop.social_login.code_ttl_minutes', 5)),
                'used' => false,
            ]);

            if ($type === 'mobile') {
                return response()->json([
                    'success' => true,
                    'code' => $authorizationCode->code,
                ], 200);
            }

            return redirect()->away($this->frontendUrl() . '/?code=' . $authorizationCode->code);
        } catch (\Throwable $e) {
            Log::error('Social login callback failed', [
                'provider' => $provider,
                'type' => $type,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($type === 'mobile') {
                return response()->json([
                    'success' => false,
                    'message' => __('message.' . SOCIAL_LOGIN_FAILED),
                ], 400);
            }

            return redirect()->away($this->frontendUrl() . '/auth?error=social_login_failed');
        }
    }

    /**
     * Exchange a single-use authorization code for a Sanctum token.
     */
    public function exchange(SocialLoginExchangeRequest $request): JsonResponse
    {
        $claimed = SocialLoginCode::query()
            ->where('code', $request->validated('code'))
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->update(['used' => true, 'used_at' => now()]);

        if ($claimed !== 1) {
            return $this->invalidCodeResponse();
        }

        $code = SocialLoginCode::where('code', $request->validated('code'))->first();

        if (!$code) {
            return $this->invalidCodeResponse();
        }

        $code->delete();

        $user = User::where('id', $code->user_id)->where('is_active', true)->first();

        if (!$user) {
            return $this->invalidCodeResponse();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => __('message.MESSAGE.SOCIAL_LOGIN_SUCCESSFUL'),
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('roles')),
        ], 200);
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

    protected function callbackUrl(string $provider): string
    {
        return url("api/v1/social/{$provider}/callback");
    }

    protected function frontendUrl(): string
    {
        return rtrim((string) config('shop.social_login.frontend_url', ''), '/');
    }

    protected function invalidCodeResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('message.ERROR.INVALID_SOCIAL_LOGIN_CODE'),
        ], 400);
    }
}
