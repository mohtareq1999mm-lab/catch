<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

class SMSService
{
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('services.twilio.enabled', false);
    }

    /**
     * Send SMS - simulated when disabled, Twilio when enabled.
     *
     * @param string $to Phone in E.164
     * @param string $message
     * @param array $metadata
     * @return array ['success'=>bool, 'message_id'=>?string, 'response'=>array]
     */
    public function send(string $to, string $message, array $metadata = []): array
    {
        if (!$this->enabled || !class_exists(\Twilio\Rest\Client::class)) {
            // Never log the message body or the recipient in clear: the body
            // carries the guest tracking link (raw token) and the number is PII.
            Log::info('SMS sending disabled/simulated', [
                'to' => $this->maskRecipient($to),
                'message' => '[redacted]',
                'metadata' => $metadata,
            ]);

            return [
                'success' => true,
                'message_id' => 'test_' . uniqid(),
                'response' => ['status' => 'simulated', 'to' => $to],
            ];
        }

        try {
            $client = new \Twilio\Rest\Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );

            $twilioMessage = $client->messages->create(
                $to,
                [
                    'from' => config('services.twilio.from'),
                    'body' => $message,
                ]
            );

            Log::info('SMS sent successfully', [
                'to' => $to,
                'message_sid' => $twilioMessage->sid ?? null,
                'status' => $twilioMessage->status ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $twilioMessage->sid ?? null,
                'response' => [
                    'status' => $twilioMessage->status ?? 'sent',
                    'price' => $twilioMessage->price ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            // The provider error text can echo the recipient number; log the
            // exception identity instead, and mask the recipient. The rethrow
            // carries NO provider text (spec #73: no clear phone in any log).
            Log::error('SMS sending failed', [
                'to' => $this->maskRecipient($to),
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
            throw new \RuntimeException('SMS sending failed');
        }
    }

    /**
     * Recipient numbers are PII: keep only the prefix and the last two digits
     * so logs and Telescope never hold a usable phone number.
     */
    private function maskRecipient(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 5) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 3) . str_repeat('*', $length - 5) . substr($phone, -2);
    }

    public function validatePhone(string $phone): bool
    {
        return preg_match('/^\+[1-9]\d{1,14}$/', $phone) === 1;
    }

    public function formatPhone(string $phone, string $defaultCountryCode = '+20'): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }
        return $defaultCountryCode . $digits;
    }
}
