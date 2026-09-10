<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmNotificationJob;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

class FcmChannel
{
    public function send($notifiable, Notification $notification): void
    {
        // Single authoritative payload source — reuse database payload.
        $payload = method_exists($notification, 'toFcm')
            ? $notification->toFcm($notifiable)
            : $notification->toDatabase($notifiable);

        $title = $this->resolveLocaleString($payload['title'] ?? null);
        $body = $this->resolveLocaleString(
            $payload['message'] ?? $payload['body'] ?? null
        );

        if (!$title || !$body) {
            \Illuminate\Support\Facades\Log::warning('FCM skipped', [
                'notification' => class_basename($notification),
                'title' => $title, 'body' => $body,
                'keys' => array_keys($payload),
            ]);
            return;
        }

        dispatch(new SendFcmNotificationJob(
            (string) $title,
            (string) $body,
            collect($payload)->except(['title', 'message', 'body'])->all(),
            // Security fix: scope the push to this notifiable's tokens only.
            $notifiable instanceof \Illuminate\Database\Eloquent\Model ? (int) $notifiable->getKey() : null,
        ));
    }

    /**
     * FCM native notifications require plain strings. Database payloads may
     * carry localized maps ({en, ar}) — resolve using the application locale
     * at dispatch time, matching the existing localization convention.
     */
    private function resolveLocaleString(mixed $value): ?string
    {
        if (is_array($value)) {
            $locale = App::getLocale();

            return (string) ($value[$locale] ?? $value['en'] ?? reset($value));
        }

        return $value === null ? null : (string) $value;
    }
}