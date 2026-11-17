<?php

namespace Tests\Feature\Guest;

use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

/**
 * Recording OTP gateway fake, bound at the OtpInterface seam.
 *
 * Records startVerification() dispatches so tests can assert that an SMS
 * was (or was not) sent, without a network provider. Verification and raw
 * SMS are out of scope for the request step.
 */
final class RecordingCancellationOtpGateway implements OtpInterface
{
    /** Phones passed to startVerification(), in dispatch order. */
    public array $started = [];

    /** When true, startVerification() reports a provider failure. */
    public bool $dispatchFails = false;

    /** The verification id handed to the client on a successful start. */
    public string $verificationId = 'otp-verification-id-1';

    public function startVerification($phone_number)
    {
        $this->started[] = $phone_number;

        if ($this->dispatchFails) {
            return new Result(['Dispatch failed (fake)']);
        }

        return new Result($this->verificationId);
    }

    public function checkVerification($id, $code, $phone_number)
    {
        return new Result(['checkVerification is out of scope for the request step']);
    }

    public function sendSms($phone_number, $messageBody)
    {
        return new Result(['sendSms is out of scope for the request step']);
    }
}
