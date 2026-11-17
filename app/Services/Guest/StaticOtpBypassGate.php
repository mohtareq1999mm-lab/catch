<?php

namespace App\Services\Guest;

/**
 * Single decision point for static-code acceptance during OTP verification.
 *
 * This class owns the entire static-bypass policy (ADR-0003, design record
 * decision D4). The Master OTP is a VALUE override of the Local OTP code —
 * never an enable switch:
 *
 * - Accepted value: the Master OTP when non-empty, else the Local OTP code.
 * - Acceptance is gated ONLY on the Local OTP flag, regardless of which
 *   OTP gateway is selected.
 * - Production hard-blocks the bypass regardless of any environment value,
 *   so a leaked or example-default static code stays harmless there.
 * - An empty resolved expected value refuses everything.
 * - Comparison is timing-safe so the code cannot be probed byte-by-byte.
 */
class StaticOtpBypassGate
{
    /**
     * Whether the provided code matches the resolved static code.
     */
    public function accepts(string $code): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        if (!config('auth.local_otp_enabled', false)) {
            return false;
        }

        $expected = $this->acceptedValue();

        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, $code);
    }

    /**
     * The accepted static value: the Master OTP if non-empty, else the
     * Local OTP code. Null when the resolved value is empty.
     */
    private function acceptedValue(): ?string
    {
        $master = config('auth.master_otp');

        if (is_string($master) && $master !== '') {
            return $master;
        }

        $local = config('auth.local_otp_code');

        return is_string($local) && $local !== '' ? $local : null;
    }
}
