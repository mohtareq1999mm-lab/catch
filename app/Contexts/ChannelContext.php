<?php

namespace App\Contexts;

use App\Enums\Channel;

class ChannelContext
{
    private Channel $channel;

    public function __construct()
    {
        $this->channel = Channel::HOME;
    }

    public function setChannel(Channel $channel): void
    {
        $this->channel = $channel;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function isFastShipping(): bool
    {
        return $this->channel === Channel::FAST_SHIPPING;
    }

    public function isHome(): bool
    {
        return $this->channel === Channel::HOME;
    }
}
