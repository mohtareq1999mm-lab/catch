<?php

namespace Tests\Feature\Guest;

use App\Services\Guest\GuestOrderTokenService;
use App\Services\Notifications\SMSService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Order;
use Mockery;

/**
 * Token/cancel regression coverage under the post-OTP semantics (#82, spec #73).
 *
 * Pins behaviour that the earlier suites leave implicit:
 *  - an expired link never cancels and never touches the single-use marker;
 *  - a cancellation from the processing state persists BOTH the marker and
 *    the cancelled timestamp, and restocks the committed inventory exactly once;
 *  - re-issuing public credentials rotates the stored hashes, proves the
 *    returned tokens authenticate, sends nothing, and never resurrects an
 *    admin-revoked tracking credential;
 *  - order detail is ownership-scoped and reports the cancellability flag.
 */
class GuestTokenAndCancelRegressionTest extends GuestCheckoutTestCase
{
    /** @var array<int, array{to: string, message: string}> */
    private array $smsSent = [];

    /** @var array<int, array{body: string, to: ?string, subject: ?string}> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Possession proof through the LOCAL OTP path (ADR 0003): the static
        // bypass is accepted and no SMS provider is contacted.
        config([
            'auth.active_otp_gateway' => 'local',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '654321',
            'auth.master_otp' => '999999',
        ]);

        // Deterministic delivery: fixtures must not register after-response
        // delivery; tests that prove "nothing is sent" enable it explicitly.
        Config::set('guest_checkout.tracking_link.enabled', false);
    }

    private function bypassCode(): string
    {
        return '999999';
    }

    private function cancelWithCode(string $token, string $code)
    {
        return $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $token,
            'code' => $code,
        ]);
    }

    private function reissueTokens(int $orderId)
    {
        return $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $orderId . '/access-tokens',
            [],
            $this->csrfHeaders()
        );
    }

    private function fakeSms(): void
    {
        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn('+201000000001');
        $sms->shouldReceive('validatePhone')->andReturn(true);
        $sms->shouldReceive('send')->andReturnUsing(function ($to, $message, $metadata = []) {
            $this->smsSent[] = ['to' => $to, 'message' => $message];

            return ['success' => true, 'message_id' => 'fake', 'response' => []];
        });

        $this->app->instance(SMSService::class, $sms);
    }

    private function fakeRawEmail(): void
    {
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            $recorder = new class {
                public ?string $to = null;
                public ?string $subject = null;

                public function to($address): static
                {
                    $this->to = $address;

                    return $this;
                }

                public function subject($subject): static
                {
                    $this->subject = $subject;

                    return $this;
                }
            };

            $callback($recorder);

            $this->emails[] = [
                'body' => $body,
                'to' => $recorder->to,
                'subject' => $recorder->subject,
            ];
        });
    }

    private function flushDelivery(): void
    {
        $this->app->terminate();
    }

    /** @test */
    public function an_expired_cancellation_link_is_rejected_and_leaves_no_marker(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];
        $token = $data['cancellation_token'];

        $order->forceFill([
            'guest_cancellation_token_expires_at' => now()->subMinute(),
        ])->save();

        $expiresAt = $order->fresh()->guest_cancellation_token_expires_at;
        $statusBefore = $order->fresh()->status;

        $this->cancelWithCode($token, $this->bypassCode())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_expired'));

        $fresh = $order->fresh();

        $this->assertSame(
            $statusBefore,
            $fresh->status,
            'A rejected expired link must not change the order status.'
        );
        $this->assertNull(
            $fresh->guest_cancellation_token_used_at,
            'A rejected expired link must not consume the single-use marker.'
        );
        $this->assertNull(
            $fresh->cancelled_at,
            'A rejected expired link must not stamp the cancellation timestamp.'
        );
        $this->assertSame(
            $expiresAt->toIso8601String(),
            $fresh->guest_cancellation_token_expires_at->toIso8601String(),
            'The expiry instant must survive the rejected attempt untouched.'
        );
    }

    /** @test */
    public function a_used_cancellation_link_is_rejected_with_its_own_reason(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];
        $token = $data['cancellation_token'];

        $usedAt = now()->subMinutes(5);
        $order->forceFill(['guest_cancellation_token_used_at' => $usedAt])->save();

        $statusBefore = $order->fresh()->status;

        $this->cancelWithCode($token, $this->bypassCode())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_used'));

        $fresh = $order->fresh();

        $this->assertSame(
            $statusBefore,
            $fresh->status,
            'A rejected used link must not change the order status.'
        );
        $this->assertSame(
            $usedAt->toIso8601String(),
            $fresh->guest_cancellation_token_used_at->toIso8601String(),
            'The original consumption instant must survive the rejected attempt untouched.'
        );
        $this->assertNull(
            $fresh->cancelled_at,
            'A rejected used link must not stamp the cancellation timestamp.'
        );
    }

    /** @test */
    public function a_processing_state_cancellation_persists_marker_and_timestamp(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];
        $token = $data['cancellation_token'];

        // COD secures inventory at creation; the order sits in processing.
        $this->assertSame(Order::ORDER_STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);

        $stockBefore = (int) $this->product->fresh()->stock_quantity;
        $soldBefore = (int) $this->product->fresh()->sold_quantity;

        $this->cancelWithCode($token, $this->bypassCode())
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $fresh = $order->fresh();

        $this->assertSame(Order::ORDER_STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull(
            $fresh->guest_cancellation_token_used_at,
            'The single-use marker must be persisted on the success path.'
        );
        $this->assertNotNull(
            $fresh->cancelled_at,
            'The cancellation timestamp must be persisted on the success path.'
        );

        // Committed inventory is restored exactly once by the canonical writer.
        $this->assertSame(Order::INVENTORY_STATE_RESTORED, $fresh->inventory_state);
        $this->assertNotNull($fresh->inventory_state_restored_at);
        $this->assertSame($stockBefore + 1, (int) $this->product->fresh()->stock_quantity);
        $this->assertSame(max(0, $soldBefore - 1), (int) $this->product->fresh()->sold_quantity);
    }

    /** @test */
    public function reissuing_access_tokens_rotates_both_hashes_without_delivery(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];

        $trackingHashBefore = $order->fresh()->guest_tracking_token_hash;
        $cancelHashBefore = $order->fresh()->guest_cancellation_token_hash;

        $this->fakeSms();
        $this->fakeRawEmail();

        // Delivery is ENABLED: anything the endpoint scheduled would be
        // captured by the fakes above (a resend in this state delivers).
        Config::set('guest_checkout.tracking_link.enabled', true);

        $response = $this->reissueTokens($order->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.tracking_revoked', false);

        $fresh = $order->fresh();

        $this->assertNotSame(
            $trackingHashBefore,
            $fresh->guest_tracking_token_hash,
            'Re-issue must rotate the stored tracking hash.'
        );
        $this->assertNotSame(
            $cancelHashBefore,
            $fresh->guest_cancellation_token_hash,
            'Re-issue must rotate the stored cancellation hash.'
        );

        // The returned credentials are the ones that authenticate.
        $tokens = app(GuestOrderTokenService::class);
        $this->assertSame(
            $fresh->guest_tracking_token_hash,
            $tokens->hash((string) $response->json('data.tracking_token'))
        );
        $this->assertSame(
            $fresh->guest_cancellation_token_hash,
            $tokens->hash((string) $response->json('data.cancellation_token'))
        );

        // The freshly rotated cancellation link rotates the marker/expiry too.
        $this->assertNull($fresh->guest_cancellation_token_used_at);
        $this->assertFalse($fresh->guest_cancellation_token_expires_at->isPast());

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent, 'Re-issuing credentials must not send an SMS.');
        $this->assertCount(0, $this->emails, 'Re-issuing credentials must not send an email.');
    }

    /** @test */
    public function reissuing_access_tokens_never_resurrects_admin_revoked_tracking(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];

        app(GuestOrderTokenService::class)->revokeTracking($order, null);
        $revokedAt = $order->fresh()->guest_tracking_revoked_at;
        $this->assertNotNull($revokedAt);

        $this->fakeSms();
        $this->fakeRawEmail();
        Config::set('guest_checkout.tracking_link.enabled', true);

        $response = $this->reissueTokens($order->id)
            ->assertOk()
            ->assertJsonPath('data.tracking_token', null)
            ->assertJsonPath('data.tracking_revoked', true);

        $fresh = $order->fresh();

        $this->assertNull(
            $fresh->guest_tracking_token_hash,
            'Admin-revoked tracking must never be resurrected by re-issue.'
        );
        $this->assertSame(
            $revokedAt->toIso8601String(),
            $fresh->guest_tracking_revoked_at->toIso8601String(),
            'The revocation instant must survive re-issue untouched.'
        );

        // Cancellation is an independent credential and stays available.
        $this->assertNotNull($fresh->guest_cancellation_token_hash);
        $this->assertNotNull($response->json('data.cancellation_token'));

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent);
        $this->assertCount(0, $this->emails);
    }

    /** @test */
    public function order_detail_is_ownership_scoped_and_reports_cancellability(): void
    {
        $data = $this->createGuestOrder('cod');
        $order = $data['order'];

        // Owner: 200 with the cancellability flag ON for a processing order.
        $this->asGuest()->getJson(self::PREFIX . '/orders/' . $order->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.order.id', $order->id);

        // Foreign browser: the same 404 as an unknown order (no IDOR oracle).
        $this->startGuestSession(true);

        $this->asGuest()->getJson(self::PREFIX . '/orders/' . $order->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        // Still owned by the first guest, untouched by the foreign probe.
        $this->assertSame(Order::ORDER_STATUS_PROCESSING, $order->fresh()->status);

        // A paid order owned by the calling guest is not cancellable.
        $data2 = $this->createGuestOrder('cod');
        $data2['order']->forceFill(['payment_status' => Order::PAYMENT_STATUS_SUCCESS])->save();

        $this->asGuest()->getJson(self::PREFIX . '/orders/' . $data2['order']->id)
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false);
    }
}
