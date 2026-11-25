<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allowlisted unified-history row. No internal ids, recipient identifiers,
 * provider responses, raw payloads or exception text.
 */
final class NotificationHistoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->resource;

        return [
            'source' => $row['source'],
            'event_type' => $row['event_type'],
            'channel' => $row['channel'],
            'status' => $row['status'],
            'subject' => $row['subject'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
            'sent_at' => $row['sent_at'],
            'delivered_at' => $row['delivered_at'],
            'failed_at' => $row['failed_at'],
        ];
    }
}
