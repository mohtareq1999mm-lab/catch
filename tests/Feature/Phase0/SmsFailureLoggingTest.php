<?php

namespace Tests\Feature\Phase0;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Marvel\Otp\Gateways\OtpGateway;
use Marvel\Traits\SmsTrait;
use Tests\TestCase;

/**
 * #17 regression: SMS dispatch failures must be observable through the log
 * without leaking recipients or message bodies.
 */
class SmsFailureLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! \Schema::hasTable('users')) {
            \Schema::create('users', function ($t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->timestamps();
            });
        }
    }

    /** @test */
    public function refund_sms_failure_is_logged_with_context_and_without_pii()
    {
        Log::spy();

        $gateway = Mockery::mock(OtpGateway::class);
        $gateway->shouldReceive('sendSms')
            ->andThrow(new \RuntimeException('gateway down'));

        $harness = new class($gateway)
        {
            use SmsTrait;

            public function __construct(private $gateway) {}

            public function getOtpGateway()
            {
                return $this->gateway;
            }

            public function adminList(): \Illuminate\Support\Collection
            {
                // Bypass the DB permission lookup in the trait helper.
                $admin = new \stdClass();
                $admin->profile = (object) ['contact' => '+201000000XXX'];

                return collect([$admin]);
            }
        };

        $order = new \stdClass();
        $order->customer_contact = '+201100000YYY';
        $order->id = 42;

        ob_start();
        $harness->sendSmsOnRefund([
            'order' => $order,
            'smsEventName' => 'refund_approved',
            'language' => 'en',
            'customerMessage' => 'secret-customer-body',
            'adminMessage' => 'secret-admin-body',
        ]);
        ob_end_clean();

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $channel, array $context) {
            // Observable: failure context present.
            if (($context['sms_event'] ?? null) !== 'refund_approved') {
                return false;
            }
            if (($context['exception'] ?? null) !== \RuntimeException::class) {
                return false;
            }
            if (($context['error'] ?? null) !== 'gateway down') {
                return false;
            }

            // PII guard: recipients and bodies must never appear in the log entry.
            $serialized = json_encode($context);
            foreach (['+201000000', '+201100000', 'secret-customer-body', 'secret-admin-body'] as $forbidden) {
                if (str_contains($serialized, $forbidden)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @test */
    public function order_event_sms_failure_is_logged_with_order_reference()
    {
        Log::spy();

        $gateway = Mockery::mock(OtpGateway::class);
        $gateway->shouldReceive('sendSms')->andThrow(new \RuntimeException('timeout'));

        $harness = new class($gateway)
        {
            use SmsTrait;

            public function __construct(private $gateway) {}

            public function getOtpGateway()
            {
                return $this->gateway;
            }

            public function adminList(): \Illuminate\Support\Collection
            {
                return collect();
            }
        };

        $order = new \stdClass();
        $order->customer_contact = '+201100000YYY';
        $order->id = 43;
        $order->parent_id = null;
        $order->children = collect();

        ob_start();
        $harness->sendSmsOnOrderEvent([
            'order' => $order,
            'smsEventName' => 'order_created',
            'language' => 'en',
            'customerMessage' => 'body',
            'adminMessage' => 'body',
            'storeOwnerMessage' => 'body',
        ]);
        ob_end_clean();

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $channel, array $context) {
            return ($context['order_id'] ?? null) === 43
                && ($context['error'] ?? null) === 'timeout';
        });
    }
}
