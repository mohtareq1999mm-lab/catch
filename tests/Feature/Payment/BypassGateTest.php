<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\General\OrderController;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #8: the test-gateway bypass reads the canonical `payment` tree
 * first (config/payment.php gateways.myfatoorah.base_url, as the registry
 * documents) with the legacy `services` tree as fallback. When both are set,
 * BOTH must indicate apitest — plus the explicit flag and a local/testing
 * environment.
 */
class BypassGateTest extends CurrencyTestCase
{
    private function gate(): bool
    {
        $controller = app(OrderController::class);
        $method = new \ReflectionMethod($controller, 'isTestGatewayBypassAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller);
    }

    private function baseline(): void
    {
        config(['payment.gateways.myfatoorah.base_url' => 'https://apitest.myfatoorah.com/v2/']);
        config(['services.myfatoorah.base_url' => 'https://apitest.myfatoorah.com/v2/']);
        config(['payment.test_gateway_bypass_enabled' => true]);
    }

    /** @test */
    public function allows_when_both_trees_point_at_apitest_with_flag(): void
    {
        $this->baseline();

        $this->assertTrue($this->gate());
    }

    /** @test */
    public function allows_via_payment_tree_when_services_tree_empty(): void
    {
        $this->baseline();
        config(['services.myfatoorah.base_url' => '']);

        $this->assertTrue($this->gate());
    }

    /** @test */
    public function allows_via_services_tree_when_payment_tree_empty(): void
    {
        $this->baseline();
        config(['payment.gateways.myfatoorah.base_url' => '']);

        $this->assertTrue($this->gate());
    }

    /** @test */
    public function denies_split_brain_payment_live_services_apitest(): void
    {
        $this->baseline();
        config(['payment.gateways.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);

        $this->assertFalse($this->gate());
    }

    /** @test */
    public function denies_split_brain_payment_apitest_services_live(): void
    {
        $this->baseline();
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);

        $this->assertFalse($this->gate());
    }

    /** @test */
    public function denies_without_explicit_flag(): void
    {
        $this->baseline();
        config(['payment.test_gateway_bypass_enabled' => false]);

        $this->assertFalse($this->gate());
    }

    /** @test */
    public function denies_with_no_url_configured(): void
    {
        $this->baseline();
        config(['payment.gateways.myfatoorah.base_url' => '']);
        config(['services.myfatoorah.base_url' => '']);

        $this->assertFalse($this->gate());
    }

    /** @test */
    public function denies_outside_local_testing_environment(): void
    {
        $this->baseline();
        $this->app['env'] = 'production';

        $this->assertFalse($this->gate());
    }
}
