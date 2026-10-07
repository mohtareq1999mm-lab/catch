<?php

namespace Marvel\Otp\Gateways;

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
        return new Result('local-message-sent');
    }

    /**
     * Accept only the configured static test code, and only when the
     * local OTP path is explicitly enabled (never in production by
     * default — see config/auth.php). Timing-safe comparison so the
     * code cannot be probed byte-by-byte.
     */
    private function isLocalCodeAccepted($code): bool
    {
        if (!config('auth.local_otp_enabled', false)) {
            return false;
        }

        $expected = (string) config('auth.local_otp_code', '123456');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $code);
    }
}
