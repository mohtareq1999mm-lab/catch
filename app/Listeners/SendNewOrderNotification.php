<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Enums\UserType;
use App\Events\OrderCreated;
use App\Notifications\NewOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendNewOrderNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        $admins = $this->getAdminUsers();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewOrderNotification($order));
        }

        $description = __('activity.order_created') ?: 'Order created';

        ActivityAuditService::recordSubject(
            get_class($order),
            (int) $order->id,
            'order_created',
            'orders',
            $description,
            new: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ],
            context: ['source' => 'queue', 'job' => self::class],
            causerId: (int) $order->user_id,
            causerType: \Marvel\Database\Models\User::class,
        );
    }

    private function getAdminUsers()
    {
        $adminModel = config('auth.providers.users.model');

        return $adminModel::query()->where('type', UserType::ADMIN->value)
            ->where('is_active', true)
            ->get();
    }
}
