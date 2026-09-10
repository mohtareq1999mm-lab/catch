<?php

namespace App\Http\Middleware;

use App\Contexts\ChannelContext;
use App\Enums\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ChannelMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ChannelContext::class);
        $header = config('channel.header', 'X-Channel');
        $value = $request->header($header);
        $strict = config('channel.strict', false);

        if ($value === null || $value === '') {
            $channel = Channel::tryFrom(config('channel.default', 'home')) ?? Channel::HOME;
            $context->setChannel($channel);

            return $next($request);
        }

        $normalized = strtolower(trim($value));

        if (!Channel::isValid($normalized)) {
            if ($strict) {
                throw new BadRequestHttpException(sprintf(
                    'Invalid channel "%s". Accepted values: %s.',
                    $value,
                    implode(', ', Channel::values())
                ));
            }

            $channel = Channel::tryFrom(config('channel.default', 'home')) ?? Channel::HOME;
            $context->setChannel($channel);

            return $next($request);
        }

        $channel = Channel::tryFrom($normalized) ?? Channel::HOME;
        $context->setChannel($channel);

        return $next($request);
    }
}
