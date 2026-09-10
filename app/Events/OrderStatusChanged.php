<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Order;

/**
 * Order status change — transactional + broadcast.
 * ShouldDispatchAfterCommit ensures listeners/queue only fire after DB commit;
 * ShouldBroadcast pushes real-time update via Pusher/WebSocket.
 */
class OrderStatusChanged implements ShouldDispatchAfterCommit, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;
    public string $oldStatus;
    public string $newStatus;
    public ?int $changedBy;
    public string $changedByType;

    public function __construct(
        Order $order,
        string $oldStatus = '',
        string $newStatus = '',
        ?int $changedBy = null,
        string $changedByType = 'system'
    ) {
        $this->order = $order;
        // Backward compat: if old code dispatches with only order, infer from model
        if ($oldStatus === '' && $newStatus === '' && isset($order->status)) {
            $this->oldStatus = $order->getOriginal('status') ?? $order->status;
            $this->newStatus = $order->status;
        } else {
            $this->oldStatus = $oldStatus;
            $this->newStatus = $newStatus;
        }
        $this->changedBy = $changedBy;
        $this->changedByType = $changedByType;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->order->user_id}.orders"),
            new PrivateChannel("order.{$this->order->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'payment_status' => $this->order->payment_status,
            'fulfillment_status' => $this->order->fulfillment_status,
            'changed_at' => now()->toIso8601String(),
            'changed_by' => $this->changedBy,
            'changed_by_type' => $this->changedByType,
        ];
    }
}
