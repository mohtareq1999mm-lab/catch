<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\FrontendCachePayload;
use App\Support\WebhookResponse;

interface FrontendWebhookDispatcher
{
    public function dispatch(FrontendCachePayload $payload): WebhookResponse;
}
