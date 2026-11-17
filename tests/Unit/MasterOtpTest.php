<?php

namespace Tests\Unit;

use Marvel\Http\Controllers\UserController;
use PHPUnit\Framework\TestCase;

class MasterOtpTest extends TestCase
{
    private UserController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new \Illuminate\Container\Container();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $config = new \Illuminate\Config\Repository();
        $app->instance('config', $config);
        \Illuminate\Container\Container::setInstance($app);

        // Instantiate UserController without triggering database
        $this->controller = (new \ReflectionClass(UserController::class))->newInstanceWithoutConstructor();
    }

    private function checkCode($code): bool
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('isMasterOtpAccepted');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $code);
    }

    /** @test */
    public function it_rejects_empty_code()
    {
        $this->assertFalse($this->checkCode(''));
        $this->assertFalse($this->checkCode(null));
    }

    /** @test */
    public function it_accepts_configured_master_otp()
    {
        config(['auth.master_otp' => '999888']);
        $this->assertTrue($this->checkCode('999888'));
        $this->assertFalse($this->checkCode('000000'));
    }

    /** @test */
    public function it_accepts_local_static_code_when_enabled()
    {
        config([
            'auth.master_otp' => '',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertTrue($this->checkCode('123456'));
        $this->assertFalse($this->checkCode('654321'));
    }

    /** @test */
    public function it_rejects_local_static_code_when_disabled()
    {
        config([
            'auth.master_otp' => '',
            'auth.local_otp_enabled' => false,
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertFalse($this->checkCode('123456'));
    }

    /** @test */
    public function verify_otp_succeeds_with_master_otp_without_otp_id()
    {
        config([
            'auth.master_otp' => '123456',
        ]);

        $request = new \Illuminate\Http\Request();
        $request->merge([
            'phone_number' => '+2011185151',
            'code' => '123456',
        ]);

        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('verifyOtp');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, $request);

        $this->assertTrue($result);
    }
}
