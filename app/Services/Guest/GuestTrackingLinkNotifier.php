<?php

namespace App\Services\Guest;

use App\Services\Notifications\SMSService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Order;

/**
 * Sends the guest their private tracking link (frontend URL + token) by SMS
 * and/or email after checkout.
 *
 * Security notes:
 *  - Only the TRACKING token is sent. The cancellation token is never placed
 *    in a shareable link.
 *  - The raw token is never persisted or queued: delivery runs after the HTTP
 *    response is sent (terminating callback), in-process.
 *  - Delivery failure must never fail or roll back the order: every channel
 *    is isolated and errors are logged without the token.
 */
class GuestTrackingLinkNotifier
{
    public function __construct(private SMSService $sms)
    {
    }

    /**
     * Schedule delivery after the response is sent. No-op for non-guest
     * orders, a missing token, or when disabled by config.
     */
    public function notifyAfterResponse(Order $order, ?string $trackingToken): void
    {
        if (!$this->shouldNotify($order, $trackingToken)) {
            return;
        }

        $locale = app()->getLocale();

        app()->terminating(function () use ($order, $trackingToken, $locale) {
            $this->notify($order, (string) $trackingToken, $locale);
        });
    }

    public function notify(Order $order, string $trackingToken, ?string $locale = null): void
    {
        $url = $this->trackingUrl($trackingToken);
        $replace = ['order_number' => $order->order_number, 'url' => $url];
        $locale = $locale ?: app()->getLocale();

        $this->sendSms($order, __('guest_checkout.tracking_sms', $replace, $locale));
        $this->sendEmail($order, $replace, $locale);
    }

    public function trackingUrl(string $trackingToken): string
    {
        $base = rtrim((string) config('guest_checkout.tracking_link.base_url'), '/');
        $path = '/' . ltrim((string) config('guest_checkout.tracking_link.path', '/track-order'), '/');

        return $base . $path . '?' . http_build_query(['token' => $trackingToken]);
    }

    private function shouldNotify(Order $order, ?string $trackingToken): bool
    {
        return config('guest_checkout.tracking_link.enabled', true)
            && $trackingToken !== null
            && $order->guest_id !== null
            && $order->user_id === null;
    }

    private function sendSms(Order $order, string $message): void
    {
        if (empty($order->user_phone)) {
            return;
        }

        try {
            $phone = $this->sms->formatPhone((string) $order->user_phone);

            if (!$this->sms->validatePhone($phone)) {
                return;
            }

            // Metadata deliberately excludes the message body/link.
            $this->sms->send($phone, $message, ['order_id' => $order->id, 'type' => 'guest_tracking_link']);
        } catch (\Throwable $e) {
            Log::warning('Guest tracking link SMS failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function sendEmail(Order $order, array $replace, string $locale): void
    {
        if (empty($order->user_email)) {
            return;
        }

        try {
            $subject = __('guest_checkout.tracking_email_subject', ['order_number' => $order->order_number], $locale);
            $body = __('guest_checkout.tracking_email_body', $replace, $locale);

            Mail::raw($body, fn ($mail) => $mail->to($order->user_email)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('Guest tracking link email failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
