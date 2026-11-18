<?php

namespace Marvel\Otp\Gateways;

use Illuminate\Support\Facades\Log;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

class LocalGateway implements OtpInterface
{
    public function __construct()
    {
        // Local gateway requires no external credentials
    }

    public function startVerification($phone_number)
    {
        // ADR-0003: the Local gateway is a credential-free dev/test fake. In
        // production it must be inert — no static ids, nothing a client could
        // treat as a delivered code — so a misconfigured environment surfaces
        // as a failed dispatch instead of silently arming a static bypass.
        if ($this->inertInProduction()) {
            return new Result(['Local OTP gateway is disabled in production']);
        }

        // Return a static id for local/testing
        return new Result('local-verification-id');
    }

    public function checkVerification($id, $code, $phone_number)
    {
        if ($id === 'local-verification-id' && $this->isLocalCodeAccepted($code)) {
            return new Result('local-verification-id');
        }

        return new Result(['Invalid code for local verification']);
    }

    public function sendSms($phone_number, $messageBody)
    {
        if ($this->inertInProduction()) {
            return new Result(['Local OTP gateway is disabled in production']);
        }

        return new Result('local-message-sent');
    }

    /**
     * ADR-0003: the Local gateway is a credential-free dev/test fake whose
     * every answer is a static value. In production it must be INERT — no
     * static ids, no accepted codes, no fake message receipts — so a
     * misconfigured environment surfaces as a failed dispatch instead of
     * arming a static bypass through the provider path. The refusal is
     * logged once per call: deterministic, but ops must be able to see
     * why production OTP flows are failing.
     */
    private function inertInProduction(): bool
    {
        if (!app()->isProduction()) {
            return false;
        }

        Log::warning('The Local OTP gateway is inert in production (ADR-0003): a misconfigured environment is surfacing as failed OTP operations.');

        return true;
    }

    /**
     * Accept only the configured static test code, and only when the
     * local OTP path is explicitly enabled (never in production by
     * default — see config/auth.php). Timing-safe comparison so the
     * code cannot be probed byte-by-byte.
     */
    private function isLocalCodeAccepted($code): bool
    {
        // ADR-0003 hard block: production can never accept the static code,
        // even when the environment is misconfigured (enabled flag left on,
        // example code left in place). The app-side StaticBypass applies the
        // same rule to the bypass path; this closes the provider path so
        // selecting the local gateway in production cannot become the
        // bypass loophole.
        if ($this->inertInProduction()) {
            return false;
        }

        if (!config('auth.local_otp_enabled', false)) {
            return false;
        }

        // No fallback default: the committed config owns the value; an unset
        // code disables acceptance instead of silently meaning '123456'.
        $expected = (string) config('auth.local_otp_code', '');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $code);
    }
}
