<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only, source-discriminated notification history.
 *
 * Lifecycle writes use insertOrIgnore with a replay identity, so an identical
 * replay is ignored rather than duplicated. Eloquent updates and deletes are
 * refused by the boot hooks below; application code must not bypass them with
 * query-builder mutations.
 */
class ShipmentNotificationHistory extends Model
{
    protected $table = 'shipment_notification_history';

    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'timeline_event_id',
        'channel',
        'recipient_type',
        'recipient_id',
        'notification_recipient_id',
        'source',
        'event',
        'status',
        'attempt',
        'detail',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Stable read surface: ordered by timeline identity, then insertion id.
     * An optional source filter keeps mixed-source histories distinguishable.
     */
    public function scopeForTimeline(Builder $query, int $timelineEventId, ?string $source = null): Builder
    {
        $query->where('timeline_event_id', $timelineEventId)
            ->orderBy('timeline_event_id')
            ->orderBy('id');

        if ($source !== null && $source !== '') {
            $query->where('source', $source);
        }

        return $query;
    }

    /** Append one lifecycle row; duplicates (replays) are ignored, never merged. */
    public static function appendRow(array $attributes): bool
    {
        return (bool) static::query()->insertOrIgnore(
            array_merge($attributes, ['created_at' => now()])
        );
    }

    public static function appendFor(
        ShipmentNotification $notification,
        string $event,
        int $attempt = 0,
        ?string $detail = null,
    ): bool {
        return static::appendRow([
            'notification_id' => (int) $notification->id,
            'timeline_event_id' => (int) $notification->timeline_event_id,
            'channel' => (string) $notification->channel,
            'recipient_type' => (string) $notification->recipient_type,
            'recipient_id' => (string) $notification->recipient_id,
            'notification_recipient_id' => $notification->notification_recipient_id === null
                ? null
                : (int) $notification->notification_recipient_id,
            'source' => (string) ($notification->source ?: 'direct'),
            'event' => $event,
            'status' => (string) $notification->status,
            'attempt' => max(0, $attempt),
            'detail' => $detail,
            'occurred_at' => now(),
        ]);
    }

    /** Same immutable-history convention as OrderStatusHistory. */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
