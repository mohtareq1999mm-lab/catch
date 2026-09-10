<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\FrontendResource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FrontendCacheInvalidation
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly FrontendResource $resource,
    ) {}
}
