<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminLoggedIn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $admin,
        public string $ip,
        public string $userAgent,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'admin.logged.in';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->admin->id,
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'type' => $this->admin->type,
            'login_time' => now()->toIso8601String(),
        ];
    }
}
