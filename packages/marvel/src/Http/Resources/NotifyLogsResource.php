<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class NotifyLogsResource extends Resource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'receiver' => $this->receiver,
            'notify_type' => $this->notify_type,
            'notify_receiver_type' => $this->notify_receiver_type,
            'is_read' => $this->is_read,
            'notify_tracker' => $this->notify_tracker,
            'notify_text' => $this->notify_text,
            'created_at' => $this->created_at?->toIso8601String(),
            'sender' => $this->sender,
            'sender_user' => $this->whenLoaded('sender_user', function () {
                return [
                    'id' => $this->sender_user->id,
                    'name' => $this->sender_user->name,
                ];
            }),
        ];
    }
}
