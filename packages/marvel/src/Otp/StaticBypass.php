<?php

namespace Marvel\Otp;

use Illuminate\Support\Facades\Log;

/**
 * The ONE universal static-bypass check (ADR 0003).
 *
 * Permanent home beside the OTP gateway stack: every OTP-consuming flow
 * (the login/contact-verification paths today, the Cancellation OTP
 * service next) adopts this class unchanged. It is deliberately
 * flow-agnostic — it knows nothing about login specifics; the caller
 * supplies the actor identifier that the audit line carries.
 *
 * Semantics (ADR 0003):
 * - Hard-blocked in production regardless of environment values, checked
 *   before any config value is consulted.
 * - Gated only on the Local OTP switch (`auth.local_otp_enabled`); a
 *   configured master value is never its own enable switch.
 * - The accepted value resolves to Master OTP when explicitly set, else
 *   the Local OTP code; an unset master value is disabled by design.
 * - Timing-safe comparison so the code cannot be probed byte-by-byte.
 * - Every successful acceptance writes one audit line with the actor
 *   identifier only — never the code or any credential.
 */
class StaticBypass
{
    /**
     * Decide whether the submitted code is accepted as a static bypass and,
     * when it is, leave the audit line.
     *
     * @param  mixed  $code  the submitted verification code
     * @param  string  $actorIdentifier  flow-supplied identity the bypass was
     *                                 exercised against (an attempted email or
     *                                 phone number, or any caller-defined
     *                                 actor reference)
     */
    public function accepts($code, string $actorIdentifier): bool
    {
        if (empty($code)) {
            return false;
        }

        // ADR 0003: production can never bypass, even when the environment
        // is misconfigured — the static bypass is hard-gated here, before
        // any config value is consulted.
        $isProduction = function_exists('app') && method_exists(app(), 'environment') ? app()->environment('production') : false;
        if ($isProduction) {
            return false;
        }

        $code = (string) $code;

        // Sole gate for the whole bypass: the Local OTP switch.
        $localEnabled = (bool) config('auth.local_otp_enabled', env('LOCAL_OTP_ENABLED', !$isProduction));
        if (!$localEnabled) {
            return false;
        }

        // Value resolution: Master OTP when explicitly set (a value override
        // of the local code), else the local code. An unset or emptied master
        // value falls through to the local code, never to acceptance.
        $masterOtp = (string) config('auth.master_otp', env('MASTER_OTP', ''));
        $localCode = (string) config('auth.local_otp_code', env('LOCAL_OTP_CODE', '123456'));
        $expected = $masterOtp !== '' ? $masterOtp : $localCode;

        if ($expected === '' || !hash_equals($expected, $code)) {
            return false;
        }

        $this->audit($actorIdentifier);

        return true;
    }

    /**
     * One audit line per successful acceptance: the actor identifier only.
     * Never the code, never any credential.
     */
    private function audit(string $actorIdentifier): void
    {
        // The bare-container unit tests resolve config without the logging
        // stack; in a real application the logger is always bound.
        if (!app()->bound('log')) {
            return;
        }

        Log::warning('Static OTP bypass accepted', ['actor' => $actorIdentifier]);
    }
}
