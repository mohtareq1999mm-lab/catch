<?php

namespace Tests\Feature\Guest;

use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

/**
 * Recording OTP gateway fake, bound at the OtpInterface seam.
 *
 * Records startVerification() dispatches and checkVerification() outcomes
 * so tests can assert what reached the provider without a network
 * provider. Raw SMS is out of scope for the cancellation flow.
 */
final class RecordingCancellationOtpGateway implements OtpInterface
{
    /** Phones passed to startVerification(), in dispatch order. */
    public array $started = [];

    /** When true, startVerification() reports a provider failure. */
    public bool $dispatchFails = false;

    /** The verification id handed to the client on a successful start. */
    public string $verificationId = 'otp-verification-id-1';

    /** Verification checks recorded as [id, code, phone] tuples, in order. */
    public array $checked = [];

    /** When true, checkVerification() reports a provider rejection. */
    public bool $verificationFails = false;

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
        $this->checked[] = [$id, $code, $phone_number];

        if ($this->verificationFails) {
            return new Result(['Verification check failed (fake)']);
        }

        return new Result($this->verificationId);
    }

    public function sendSms($phone_number, $messageBody)
    {
        return new Result(['sendSms is out of scope for the cancellation flow']);
    }
}
