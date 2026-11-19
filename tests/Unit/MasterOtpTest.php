<?php

namespace Tests\Unit;

use Marvel\Http\Controllers\UserController;
use PHPUnit\Framework\TestCase;

class MasterOtpTest extends TestCase
{
    private UserController $controller;

    /** The process-wide container present before this test class swapped it. */
    private ?\Illuminate\Container\Container $originalContainer = null;

    /** The facade application present before this test class swapped it (may be a plain container). */
    private $originalFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Remember the process-wide container and facade application so
        // tearDown can restore them: no global swap is ever left behind
        // for the tests that run after this class in the same process.
        $this->originalContainer = \Illuminate\Container\Container::getInstance();
        $this->originalFacadeApplication = \Illuminate\Support\Facades\Facade::getFacadeApplication();

        $app = new \Illuminate\Container\Container();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $config = new \Illuminate\Config\Repository();
        $app->instance('config', $config);
        \Illuminate\Container\Container::setInstance($app);

        // Instantiate UserController without triggering database
        $this->controller = (new \ReflectionClass(UserController::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        if ($this->originalContainer !== null) {
            \Illuminate\Container\Container::setInstance($this->originalContainer);
        }
        if ($this->originalFacadeApplication !== null) {
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->originalFacadeApplication);
        }

        parent::tearDown();
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
        // The Local OTP switch must be EXPLICIT: #94 removed the implicit
        // non-production default, so an unset switch disables acceptance.
        config(['auth.master_otp' => '999888', 'auth.local_otp_enabled' => true]);
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
    public function it_never_accepts_the_static_code_in_production()
    {
        // Simulate a production environment on the bare container: app()
        // must expose environment('production') === true.
        $app = new class extends \Illuminate\Container\Container {
            public function environment(...$environments)
            {
                return 'production';
            }
        };
        $app->instance('config', new \Illuminate\Config\Repository());
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        \Illuminate\Container\Container::setInstance($app);

        // Even a misconfigured environment (master OTP set, local bypass
        // enabled) must never accept the static code in production.
        config([
            'auth.master_otp' => '123456',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertFalse($this->checkCode('123456'));
        $this->assertFalse($this->checkCode('999888'));
    }

    /** @test */
    public function verify_otp_succeeds_with_master_otp_without_otp_id()
    {
        config([
            'auth.master_otp' => '123456',
            'auth.local_otp_enabled' => true,
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
