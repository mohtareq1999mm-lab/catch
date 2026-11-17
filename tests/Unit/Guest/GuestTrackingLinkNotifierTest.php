<?php

namespace Tests\Unit\Guest;

use App\Services\Guest\GuestTrackingLinkNotifier;
use App\Services\Notifications\SMSService;
use Marvel\Database\Models\Order;
use Mockery;
use Tests\TestCase;

class GuestTrackingLinkNotifierTest extends TestCase
{
    private function order(array $attrs = []): Order
    {
        return (new Order())->forceFill(array_merge([
            'id' => 10,
            'order_number' => 'MEEM-10',
            'guest_id' => 5,
            'user_id' => null,
            'user_phone' => '01012345678',
            'user_email' => null,
        ], $attrs));
    }

    public function test_url_targets_frontend_with_encoded_token(): void
    {
        config(['guest_checkout.tracking_link' => ['enabled' => true, 'base_url' => 'https://meem.com/', 'path' => 'track-order']]);

        $url = app(GuestTrackingLinkNotifier::class)->trackingUrl('abc_DEF-123');

        $this->assertSame('https://meem.com/track-order?token=abc_DEF-123', $url);
    }

    public function test_sms_contains_tracking_link_only(): void
    {
        config(['guest_checkout.tracking_link' => ['enabled' => true, 'base_url' => 'https://meem.com', 'path' => '/track-order']]);

        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn('+201012345678');
        $sms->shouldReceive('validatePhone')->andReturn(true);
        $sms->shouldReceive('send')->once()->withArgs(function ($to, $message) {
            return $to === '+201012345678'
                && str_contains($message, 'https://meem.com/track-order?token=trk_token')
                && str_contains($message, 'MEEM-10');
        })->andReturn(['success' => true]);

        (new GuestTrackingLinkNotifier($sms))->notify($this->order(), 'trk_token', 'en');
    }

    public function test_sms_failure_never_throws(): void
    {
        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn('+201012345678');
        $sms->shouldReceive('validatePhone')->andReturn(true);
        $sms->shouldReceive('send')->andThrow(new \RuntimeException('gateway down'));

        (new GuestTrackingLinkNotifier($sms))->notify($this->order(), 'trk_token', 'en');

        $this->assertTrue(true);
    }

    public function test_registered_user_order_is_not_notified(): void
    {
        $sms = Mockery::mock(SMSService::class);
        $sms->shouldNotReceive('send');

        (new GuestTrackingLinkNotifier($sms))
            ->notifyAfterResponse($this->order(['user_id' => 3]), 'trk_token');

        $this->assertTrue(true);
    }

    public function test_disabled_by_config(): void
    {
        config(['guest_checkout.tracking_link.enabled' => false]);

        $sms = Mockery::mock(SMSService::class);
        $sms->shouldNotReceive('send');

        (new GuestTrackingLinkNotifier($sms))->notifyAfterResponse($this->order(), 'trk_token');

        $this->assertTrue(true);
    }
}
