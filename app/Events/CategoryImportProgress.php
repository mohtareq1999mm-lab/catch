<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class CategoryImportProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public int $importId,
        public array $data = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.notifications'),
            new PrivateChannel('users.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'category.import.progress';
    }

    public function broadcastWith(): array
    {
        return array_merge($this->data, [
            'import_id' => $this->importId,
            'type' => 'category',
        ]);
    }
}