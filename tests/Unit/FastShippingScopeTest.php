<?php

namespace Tests\Unit;

use App\Contexts\ChannelContext;
use App\Enums\Channel;
use App\Models\Scopes\FastShippingScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class FastShippingScopeTest extends TestCase
{
    private FastShippingScope $scope;

    /** @var Builder&MockObject */
    private $builder;

    /** @var Model&MockObject */
    private $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scope = new FastShippingScope();
        $this->builder = $this->createMock(Builder::class);
        $this->model = $this->createMock(Model::class);
    }

    /** @test */
    public function it_applies_where_clause_when_channel_is_fast_shipping()
    {
        config(['channel.enabled' => true]);

        $context = app(ChannelContext::class);
        $context->setChannel(Channel::FAST_SHIPPING);

        $this->builder->expects($this->once())
            ->method('where')
            ->with('is_fast_shipping_available', true);

        $this->scope->apply($this->builder, $this->model);
    }

    /** @test */
    public function it_does_not_apply_where_clause_when_channel_is_home()
    {
        config(['channel.enabled' => true]);

        $context = app(ChannelContext::class);
        $context->setChannel(Channel::HOME);

        $this->builder->expects($this->never())
            ->method('where');

        $this->scope->apply($this->builder, $this->model);
    }

    /** @test */
    public function it_does_not_apply_where_clause_when_channel_is_disabled()
    {
        config(['channel.enabled' => false]);

        $context = app(ChannelContext::class);
        $context->setChannel(Channel::FAST_SHIPPING);

        $this->builder->expects($this->never())
            ->method('where');

        $this->scope->apply($this->builder, $this->model);
    }

    /** @test */
    public function it_does_not_apply_when_context_not_set()
    {
        config(['channel.enabled' => true]);

        $this->builder->expects($this->never())
            ->method('where');

        $this->scope->apply($this->builder, $this->model);
    }
}
