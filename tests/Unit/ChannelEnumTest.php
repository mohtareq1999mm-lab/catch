<?php

namespace Tests\Unit;

use App\Enums\Channel;
use Tests\TestCase;

class ChannelEnumTest extends TestCase
{
    /** @test */
    public function it_has_all_expected_channels()
    {
        $values = Channel::values();

        $this->assertContains('home', $values);
        $this->assertContains('fast-shipping', $values);
    }

    /** @test */
    public function it_validates_known_values()
    {
        $this->assertTrue(Channel::isValid('home'));
        $this->assertTrue(Channel::isValid('fast-shipping'));
    }

    /** @test */
    public function it_rejects_unknown_values()
    {
        $this->assertFalse(Channel::isValid('b2b'));
        $this->assertFalse(Channel::isValid('wholesale'));
        $this->assertFalse(Channel::isValid('marketplace'));
    }

    /** @test */
    public function it_accepts_null_as_valid()
    {
        $this->assertTrue(Channel::isValid(null));
    }
}
