<?php

namespace Tests\Unit\Guest;

use App\Enums\GuestCancellationLinkState;
use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Guest\GuestCancellationGate;
use App\Services\Guest\GuestCancellationLinkService;
use App\Services\Guest\GuestCancellationOtpService;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Notifications\SMSService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use App\ValueObjects\GuestCancellationLink;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Order;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;
use Marvel\Otp\StaticBypass;
use Mockery;
use Tests\TestCase;

/**
 * Provider-outage classification (#88) re-homed onto the possession-proof
 * service (#81): an upstream outage is a failed dispatch (502) that must
 * never consume the attempt budget or the single-use link; a rejected code
 * keeps 422 and burns exactly one attempt; a verified code clears the
 * budget.
 */
class GuestCancellationOtpTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    private const ATTEMPTS_PREFIX = 'guest_cancel_otp:attempts:';

    private const STATE_PREFIX = 'guest_cancel_otp:state:';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * The issuance state is deliberately NOT fillable (one-way credential);
     * production binds it through a query update, and so does this fixture.
     */
    private function order(string $hash): Order
    {
        $order = Order::create([
            'name' => 'Outage Test',
            'user_phone' => '+201000000088',
            'address' => ['street' => '1 Test Street', 'city' => 'Test City'],
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        Order::query()->whereKey($order->id)->update(['guest_cancellation_token_hash' => $hash]);

        return $order->refresh();
    }

    private function service(
        Order $order,
        \Closure $outcome,
        GuestCancellationLinkState $state = GuestCancellationLinkState::Live,
    ): GuestCancellationOtpService {
        $tokens = Mockery::mock(GuestOrderTokenService::class);
        $tokens->shouldReceive('hash')->andReturnUsing(fn (string $raw) => 'hash-' . $raw);

        // Unknown never carries an order; used/expired still expose the
        // matched row, exactly like the real resolver.
        $matchedOrder = $state === GuestCancellationLinkState::Unknown ? null : $order;

        $links = Mockery::mock(GuestCancellationLinkService::class);
        $links->shouldReceive('resolve')->andReturnUsing(
            fn (string $raw) => new GuestCancellationLink(
                $state,
                $matchedOrder,
                $matchedOrder === null ? null : 'hash-' . $raw,
            )
        );

        $gate = Mockery::mock(GuestCancellationGate::class);
        if ($state === GuestCancellationLinkState::Live) {
            $gate->shouldReceive('assertCancellable');
        } else {
            $gate->shouldNotReceive('assertCancellable');
        }

        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturnUsing(fn (string $phone) => $phone);

        // The universal bypass stays closed: these tests exercise the
        // provider path only (StaticBypassTest owns the bypass semantics).
        $bypass = Mockery::mock(StaticBypass::class);
        $bypass->shouldReceive('accepts')->andReturn(false);

        return new GuestCancellationOtpService(
            $tokens,
            $links,
            $gate,
            $sms,
            $bypass,
            $this->fakeGateway($outcome),
            app(RateLimiter::class),
        );
    }

    /** A stubbed gateway returning a canned Result for both OTP calls. */
    private function fakeGateway(\Closure $outcomes): OtpInterface
    {
        return new class($outcomes) implements OtpInterface {
            public function __construct(private \Closure $outcomes)
            {
            }

            public function startVerification($phone_number)
            {
                return ($this->outcomes)();
            }

            public function checkVerification($id, $code, $phone_number)
            {
                return ($this->outcomes)();
            }

            public function sendSms($phone_number, $messageBody)
            {
                return new Result(['not used by this fake']);
            }
        };
    }

    /**
     * Dead links never reach the provider or the policy gate: unknown, used
     * and expired answer the same no-oracle 404 and write no verification
     * state, so no SMS budget can be spent on them.
     */
    public function test_dead_links_answer_the_no_oracle_404_without_side_effects(): void
    {
        $deadStates = [
            GuestCancellationLinkState::Unknown,
            GuestCancellationLinkState::Used,
            GuestCancellationLinkState::Expired,
        ];

        foreach ($deadStates as $state) {
            $raw = 'dead-' . $state->value;
            $order = $this->order('hash-' . $raw);

            $providerCalls = 0;
            $service = $this->service($order, function () use (&$providerCalls) {
                $providerCalls++;

                return Result::providerError(['must never be called']);
            }, $state);

            try {
                $service->request($raw);
                $this->fail("Expected the no-oracle 404 for a {$state->value} link.");
            } catch (GuestCheckoutConflictException $e) {
                $this->assertSame(404, $e->httpStatus());
                $this->assertSame(__('guest_checkout.cancel_link_invalid'), $e->getMessage());
            }

            $this->assertSame(0, $providerCalls, "A {$state->value} link must never contact the provider.");
            $this->assertNull(
                Cache::get(self::STATE_PREFIX . 'hash-' . $raw),
                "A {$state->value} link must never write a verification state."
            );
        }
    }

    /**
     * The same classification at the REQUEST step: a provider outage is a
     * failed dispatch (502), not a client error, and consumes nothing.
     */
    public function test_a_provider_outage_at_request_is_a_502_and_consumes_nothing(): void
    {
        $order = $this->order('hash-outage-request');

        $service = $this->service($order, fn () => Result::providerError(['Upstream outage.']));

        try {
            $service->request('outage-request');
            $this->fail('Expected a 502 for the provider outage.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(502, $e->httpStatus());
            $this->assertSame(__('guest_checkout.cancel_otp_failed'), $e->getMessage());
        }

        $this->assertNull($order->guest_cancellation_token_used_at, 'The outage must not consume the link.');
        $this->assertNull(Cache::get(self::STATE_PREFIX . 'hash-outage-request'), 'The outage must not write a verification state.');
        $this->assertSame(0, app(RateLimiter::class)->attempts(self::ATTEMPTS_PREFIX . 'hash-outage-request'), 'The outage must not consume an attempt.');
    }

    /**
     * Provider-outage classification at the CONFIRM step: 502, and the
     * attempt budget stays untouched — a legitimate user must not be locked
     * out for an outage they cannot control.
     */
    public function test_a_provider_outage_at_confirm_is_a_502_and_consumes_no_attempt(): void
    {
        $order = $this->order('hash-outage-confirm');
        Cache::put(self::STATE_PREFIX . 'hash-outage-confirm', ['verification_id' => 'vid-1'], 300);

        $service = $this->service($order, fn () => Result::providerError(['Upstream outage.']));

        try {
            $service->verify('outage-confirm', '445566', $order);
            $this->fail('Expected a 502 for the provider outage.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(502, $e->httpStatus());
            $this->assertSame(__('guest_checkout.cancel_otp_failed'), $e->getMessage());
        }

        $this->assertNull($order->fresh()->guest_cancellation_token_used_at, 'The outage must not consume the link.');
        $this->assertSame(0, app(RateLimiter::class)->attempts(self::ATTEMPTS_PREFIX . 'hash-outage-confirm'), 'The outage must not consume an attempt.');
    }

    /**
     * A REJECTED code (the gateway answering "wrong code") keeps the
     * failure semantics: 422 and exactly one consumed attempt.
     */
    public function test_a_rejected_code_still_consumes_an_attempt(): void
    {
        $order = $this->order('hash-reject');
        Cache::put(self::STATE_PREFIX . 'hash-reject', ['verification_id' => 'vid-2'], 300);

        $service = $this->service($order, fn () => new Result(['Verification check failed: Invalid code.']));

        try {
            $service->verify('reject', '000000', $order);
            $this->fail('Expected a 422 for the rejected code.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(__('guest_checkout.cancel_otp_invalid'), $e->getMessage());
        }

        $this->assertSame(1, app(RateLimiter::class)->attempts(self::ATTEMPTS_PREFIX . 'hash-reject'), 'A wrong code consumes an attempt.');
        $this->assertNull($order->fresh()->guest_cancellation_token_used_at);
    }

    /**
     * A verified code clears the attempt budget, so an earlier mistake
     * cannot compound into a lockout for a legitimate user.
     */
    public function test_a_verified_code_clears_the_attempt_budget(): void
    {
        $order = $this->order('hash-valid');
        Cache::put(self::STATE_PREFIX . 'hash-valid', ['verification_id' => 'vid-3'], 300);
        app(RateLimiter::class)->hit(self::ATTEMPTS_PREFIX . 'hash-valid', 300);

        $service = $this->service($order, fn () => new Result('vid-3'));

        $this->assertSame('otp', $service->verify('valid', '445566', $order));
        $this->assertSame(0, app(RateLimiter::class)->attempts(self::ATTEMPTS_PREFIX . 'hash-valid'), 'A verified code clears the attempt budget.');
    }

    /**
     * A gateway that THROWS (transport down) is the same failed dispatch:
     * 502, nothing consumed.
     */
    public function test_a_gateway_exception_at_request_is_a_502(): void
    {
        $order = $this->order('hash-exception');

        $service = $this->service($order, function (): void {
            throw new \RuntimeException('Twilio 500');
        });

        try {
            $service->request('exception');
            $this->fail('Expected a 502 for the gateway exception.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(502, $e->httpStatus());
        }

        $this->assertNull(Cache::get(self::STATE_PREFIX . 'hash-exception'));
    }
}
