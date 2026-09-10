<?php

namespace App\Models\Scopes;

use App\Contexts\ChannelContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class FastShippingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!config('channel.enabled', true)) {
            return;
        }

        $context = app(ChannelContext::class);

        if ($context->isFastShipping()) {
            $builder->where('is_fast_shipping_available', true);
        }
    }
}
