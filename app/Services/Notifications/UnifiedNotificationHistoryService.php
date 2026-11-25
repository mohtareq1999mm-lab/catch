<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Http\Resources\Notifications\NotificationHistoryItemResource;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, recipient-scoped notification history.
 *
 * `order` and `shipment` rows stay source-discriminated. Dedupe happens in
 * the database before pagination: only the latest duplicate
 * (created_at DESC, id DESC) survives. Both sources share the deterministic
 * created_at DESC, id DESC ordering. Only allowlisted columns are selected;
 * provider responses, payloads, recipient ids and failure text never enter
 * the projection.
 *
 * Residual index risk: order rows use the existing (user_id, created_at)
 * index. Shipment rows are filtered by (recipient_type, recipient_id), whose
 * existing unique index starts with timeline_event_id, so MySQL may scan
 * shipment notifications. No migration is amended here; a recipient-scoped
 * composite index is the follow-up if history volume grows.
 */
final class UnifiedNotificationHistoryService
{
    /** Cap the offset so an absurd page cannot overflow or scan unbounded. */
    private const MAX_PAGE = 10000;

    public function forUser(int $userId, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);
        $page = min(max($page ?? 1, 1), self::MAX_PAGE);

        $orderRows = DB::table('order_notifications as onot')
            ->where('onot.user_id', $userId)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('order_notifications as newer')
                    ->whereColumn('newer.order_id', 'onot.order_id')
                    ->whereColumn('newer.event_type', 'onot.event_type')
                    ->whereColumn('newer.channel', 'onot.channel')
                    ->where(function ($query): void {
                        $query->whereColumn('newer.created_at', '>', 'onot.created_at')
                            ->orWhere(function ($query): void {
                                $query->whereRaw('newer.created_at <=> onot.created_at')
                                    ->whereColumn('newer.id', '>', 'onot.id');
                            });
                    });
            })
            ->select([
                'onot.id',
                DB::raw("'order' as source"),
                'onot.event_type',
                'onot.channel',
                'onot.status',
                'onot.subject',
                'onot.message',
                'onot.created_at',
                'onot.sent_at',
                'onot.delivered_at',
                'onot.failed_at',
            ]);

        $shipmentRows = DB::table('shipment_notifications as snot')
            ->where('snot.recipient_type', 'user')
            ->where('snot.recipient_id', (string) $userId)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('shipment_notifications as newer')
                    ->where('newer.recipient_type', 'user')
                    ->whereRaw('newer.timeline_event_id <=> snot.timeline_event_id')
                    ->whereColumn('newer.channel', 'snot.channel')
                    ->whereColumn('newer.recipient_id', 'snot.recipient_id')
                    ->where(function ($query): void {
                        $query->whereColumn('newer.created_at', '>', 'snot.created_at')
                            ->orWhere(function ($query): void {
                                $query->whereRaw('newer.created_at <=> snot.created_at')
                                    ->whereColumn('newer.id', '>', 'snot.id');
                            });
                    });
            })
            ->select([
                'snot.id',
                DB::raw("'shipment' as source"),
                DB::raw("'shipment.status_changed' as event_type"),
                'snot.channel',
                'snot.status',
                DB::raw('NULL as subject'),
                DB::raw('NULL as message'),
                'snot.created_at',
                'snot.sent_at',
                DB::raw('NULL as delivered_at'),
                'snot.failed_at',
            ]);

        $paginator = $orderRows
            ->unionAll($shipmentRows)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection($paginator->getCollection()->map(
            fn ($row): NotificationHistoryItemResource => new NotificationHistoryItemResource([
                'source' => (string) $row->source,
                'event_type' => (string) $row->event_type,
                'channel' => (string) $row->channel,
                'status' => (string) $row->status,
                'subject' => $row->subject,
                'message' => $row->message,
                'created_at' => $this->iso($row->created_at),
                'sent_at' => $this->iso($row->sent_at),
                'delivered_at' => $this->iso($row->delivered_at),
                'failed_at' => $this->iso($row->failed_at),
            ])
        ));

        return $paginator;
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
