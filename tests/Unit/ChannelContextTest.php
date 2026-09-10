<?php

namespace Tests\Unit;

use App\Contexts\ChannelContext;
use App\Enums\Channel;
use Tests\TestCase;

class ChannelContextTest extends TestCase
{
    /** @test */
    public function it_defaults_to_home_channel()
    {
        $context = new ChannelContext();

        $this->assertTrue($context->isHome());
        $this->assertFalse($context->isFastShipping());
        $this->assertEquals(Channel::HOME, $context->getChannel());
    }

    /** @test */
    public function it_can_be_set_to_fast_shipping()
    {
        $context = new ChannelContext();
        $context->setChannel(Channel::FAST_SHIPPING);

        $this->assertTrue($context->isFastShipping());
        $this->assertFalse($context->isHome());
        $this->assertEquals(Channel::FAST_SHIPPING, $context->getChannel());
    }

    /** @test */
    public function it_can_be_switched_back_to_home()
    {
        $context = new ChannelContext();
        $context->setChannel(Channel::FAST_SHIPPING);
        $context->setChannel(Channel::HOME);

        $this->assertTrue($context->isHome());
        $this->assertFalse($context->isFastShipping());
    }

    /** @test */
    public function it_is_immutable_after_setting_channel_via_accessor()
    {
        $context = new ChannelContext();
        $context->setChannel(Channel::FAST_SHIPPING);

        $this->assertEquals(Channel::FAST_SHIPPING, $context->getChannel());
    }
}
