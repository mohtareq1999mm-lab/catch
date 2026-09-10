<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\OrderCreated;
use App\Jobs\LogActivityJob;
use App\Notifications\NewOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendNewOrderNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        $admins = $this->getAdminUsers();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewOrderNotification($order));
        }

        $description = __('activity.order_created') ?: 'Order created';

        LogActivityJob::dispatch(
            get_class($order),
            $order->id,
            $order->user_id,
            'order_created',
            'orders',
            $description,
            [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ],
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
