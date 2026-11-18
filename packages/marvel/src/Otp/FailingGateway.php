<?php

namespace Marvel\Otp;

/**
 * The production fail-loud gateway placeholder (ADR 0003 companion, #89
 * review).
 *
 * When the configured OTP gateway is missing or cannot be constructed in
 * PRODUCTION, the container binds THIS instead of silently falling back to
 * the LocalGateway (whose answer would look like a successful send while
 * nothing was ever sent). Every call throws at call time, so the endpoint's
 * exception handling converts it into the translated failure response and an
 * identity-only report — never a silent no-op.
 *
 * Never bound outside production: dev machines keep the committed
 * LocalGateway fallback.
 */
class FailingGateway implements OtpInterface
{
    public function __construct(private string $reason)
    {
    }

    public function startVerification($phone_number)
    {
        throw new \RuntimeException($this->reason);
    }

    public function checkVerification($id, $code, $phone_number)
    {
        throw new \RuntimeException($this->reason);
    }

    public function sendSms($phone_number, $messageBody)
    {
        throw new \RuntimeException($this->reason);
    }
}
