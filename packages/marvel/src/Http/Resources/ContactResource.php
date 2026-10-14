<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ContactResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'is_read' => (bool) $this->is_read,
            'is_replay' => (bool) $this->is_replay,
            'created_at' => $this->created_at,
        ];
    }
}
