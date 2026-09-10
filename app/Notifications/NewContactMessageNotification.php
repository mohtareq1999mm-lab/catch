<?php

namespace App\Notifications;

use Marvel\Database\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewContactMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Contact $contact,
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
                'en' => __('notifications.admin.contact_message.title', [], 'en'),
                'ar' => __('notifications.admin.contact_message.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.admin.contact_message.body', ['customer_name' => $this->contact->name], 'en'),
                'ar' => __('notifications.admin.contact_message.body', ['customer_name' => $this->contact->name], 'ar'),
            ],
            'icon' => 'mail',
            'resource_type' => 'contact',
            'resource_id' => $this->contact->id,
            'action_url' => "/admin/contacts/{$this->contact->id}",
            'contact_id' => $this->contact->id,
            'customer_name' => $this->contact->name,
            'customer_email' => $this->contact->email,
            'subject' => $this->contact->subject,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'contact.message';
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

