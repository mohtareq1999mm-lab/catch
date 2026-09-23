<?php

namespace Tests\Feature\CouponDistribution;

/**
 * Broker-less FCM stand-in: records payloads, performs no network I/O.
 * Database-channel writes still happen synchronously, proving channel
 * independence (a dead push service never deletes the durable row).
 */
class NullFcmChannel
{
    /** @var list<array> */
    public static array $sent = [];

    public function send($notifiable, $notification): void
    {
        self::$sent[] = [
            'notifiable_id' => $notifiable instanceof \Illuminate\Database\Eloquent\Model
                ? $notifiable->getKey()
                : null,
            'notification' => get_class($notification),
        ];
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}
