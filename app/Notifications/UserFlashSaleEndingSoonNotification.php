<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserFlashSaleEndingSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $flashSale,
    ) {
        $this->onQueue('meem-medium');
    }

    public function via($notifiable): array
    {
        return ['database',
            'fcm', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => [
                'en' => __('notifications.flash_sale.ending_soon.title', [], 'en'),
                'ar' => __('notifications.flash_sale.ending_soon.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.flash_sale.ending_soon.body', ['flash_sale_title' => $this->flashSale->title ?? ''], 'en'),
                'ar' => __('notifications.flash_sale.ending_soon.body', ['flash_sale_title' => $this->flashSale->title ?? ''], 'ar'),
            ],
            'icon' => 'hourglass',
            'resource_type' => 'flash_sale',
            'resource_id' => $this->flashSale->id,
            'action_url' => '/flash-sales/' . $this->flashSale->id,
            'flash_sale_id' => $this->flashSale->id,
            'end_date' => $this->flashSale->end_date?->toIso8601String(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'flash_sale.ending_soon';
    }

    public function broadcastAs(): string
    {
        return $this->broadcastType();
    }

    public function databaseType($notifiable): string
    {
        return $this->broadcastType();
    }
}

