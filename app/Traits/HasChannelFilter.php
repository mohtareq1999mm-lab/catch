<?php

namespace App\Traits;

use App\Contexts\ChannelContext;

trait HasChannelFilter
{
    private function applyChannelHomeFilter($query): void
    {
        if (!config('channel.enabled', true)) {
            return;
        }

        $context = app(ChannelContext::class);

        if ($context->isHome()) {
            $query->where('is_fast_shipping_available', false);
        }
    }
}
