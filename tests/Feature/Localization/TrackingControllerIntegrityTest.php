<?php

namespace Tests\Feature\Localization;

use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * Guards the tracking-endpoint fatal: OrderTrackingController previously
 * called undefined getStatusLabel()/getStatusDescription()/getStatusIcon()/
 * getStatusColor()/getProgressPercentage() on every successful tracking
 * response (HTTP 500 before any localized text could render).
 *
 * DB-free (reflection + catalogue assertions only).
 */
class TrackingControllerIntegrityTest extends TestCase
{
    public function test_required_status_helpers_exist(): void
    {
        $ref = new \ReflectionClass(\App\Http\Controllers\Api\General\OrderTrackingController::class);
        foreach ([
            'getStatusLabel',
            'getStatusDescription',
            'getStatusIcon',
            'getStatusColor',
            'getProgressPercentage',
            'getEstimatedDelivery',
            'getCurrentStatusInfo',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), "OrderTrackingController::{$method} must exist");
        }
    }

    /** @dataProvider locales */
    public function test_status_labels_resolve_for_every_order_status(string $locale): void
    {
        app()->setLocale($locale);
        $controller = app(\App\Http\Controllers\Api\General\OrderTrackingController::class);
        $label = new \ReflectionMethod($controller, 'getStatusLabel');
        $label->setAccessible(true);

        foreach ([
            Order::ORDER_STATUS_PENDING,
            Order::ORDER_STATUS_PROCESSING,
            Order::ORDER_STATUS_COMPLETED,
            Order::ORDER_STATUS_CANCELLED,
            Order::ORDER_STATUS_DELIVERED,
        ] as $status) {
            $resolved = $label->invoke($controller, $status);
            $this->assertNotEmpty($resolved, "[{$locale}] label for {$status}");
            $this->assertStringNotContainsString('tracking.', $resolved, "[{$locale}] no raw key for {$status}");
        }
    }

    /** @dataProvider locales */
    public function test_status_icons_colors_progress_are_stable_codes(string $locale): void
    {
        app()->setLocale($locale);
        $controller = app(\App\Http\Controllers\Api\General\OrderTrackingController::class);

        $icon = new \ReflectionMethod($controller, 'getStatusIcon');
        $color = new \ReflectionMethod($controller, 'getStatusColor');
        $progress = new \ReflectionMethod($controller, 'getProgressPercentage');

        $order = fn (string $status) => (new Order())->forceFill(['status' => $status]);

        // Presentational codes must be identical in both locales.
        $this->assertSame('clock', $icon->invoke($controller, Order::ORDER_STATUS_PENDING));
        $this->assertSame(100, $progress->invoke($controller, $order(Order::ORDER_STATUS_DELIVERED)));
        $this->assertSame(0, $progress->invoke($controller, $order(Order::ORDER_STATUS_CANCELLED)));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color->invoke($controller, Order::ORDER_STATUS_PENDING));
    }

    public static function locales(): array
    {
        return ['arabic' => ['ar'], 'english' => ['en']];
    }
}
