<?php

namespace App\Events;

use Marvel\Database\Models\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactMessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Contact $contact,
    ) {}
}
