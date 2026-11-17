<?php

namespace Tests\Feature\Guest;

use App\Services\Guest\GuestIdentityService;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Notifications\SMSService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;

/**
 * Session-bound resend of the guest tracking link (ticket #80, spec #73).
 *
 * Feature seam only: the owning guest re-issues a FRESH Tracking Token
 * (hash rotation, admin revocation never resurrected) and both channels
 * re-deliver through the EXISTING notifier. Identity binding matches the
 * order-detail endpoint; token-only callers have no route to it; the
 * fail-closed limiter holds the burst.
 *
 * Delivery determinism: the notifier schedules after-response delivery via
 * app()->terminating(), which the test runner never executes. Every fixture
 * checkout runs with link delivery DISABLED (no callback registered), and
 * each test flushes exactly once through the public app()->terminate()
 * lifecycle seam — so every captured delivery belongs to the code under
 * test, and "nothing scheduled" is provable.
 */
class GuestTrackingLinkResendTest extends GuestCheckoutTestCase
{
    /** @var array<int, array{to: string, message: string, metadata: array}> */
    protected array $smsSent = [];

    /** @var array<int, array{body: string, to: ?string, subject: ?string}> */
    protected array $emails = [];

    /** @var array<int, array{level: string, message: string, context: array}> */
    protected array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic delivery: the fixture checkout must not register a
        // terminating delivery; tests that prove delivery re-enable first.
        Config::set('guest_checkout.tracking_link.enabled', false);
    }

    /** Capture every SMS send through the container seam; the real provider never runs. */
    protected function fakeSms(bool $throws = false): void
    {
        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn('+201000000001');
        $sms->shouldReceive('validatePhone')->andReturn(true);

        if ($throws) {
            $sms->shouldReceive('send')->andReturnUsing(function () {
                throw new \RuntimeException('provider outage');
            });
        } else {
            $sms->shouldReceive('send')->andReturnUsing(function ($to, $message, $metadata = []) {
                $this->smsSent[] = ['to' => $to, 'message' => $message, 'metadata' => $metadata];

                return ['success' => true, 'message_id' => 'fake', 'response' => []];
            });
        }

        $this->app->instance(SMSService::class, $sms);
    }

    /**
     * Capture every raw email through the facade seam. Mail::fake() is a
     * no-op for Mail::raw(), so the channel is faked explicitly.
     */
    protected function fakeRawEmail(): void
    {
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            // The notifier passes fn ($mail) => $mail->to(...)->subject(...);
            // a recorder object mirrors that contract without the mail stack.
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

    /** Record every logged message (level + message + context) for negative assertions. */
    protected function spyLogs(): void
    {
        Log::listen(function ($event) {
            $this->logEvents[] = [
                'level' => $event->level,
                'message' => (string) $event->message,
                'context' => $event->context,
            ];
        });
    }

    /** Flush the after-response delivery exactly once (public lifecycle seam). */
    protected function flushDelivery(): void
    {
        $this->app->terminate();
    }

    /** Fixture: a guest order with its original tracking token, no pending delivery. */
    protected function createOrderWithLinkDeliveryDisabled(): array
    {
        $data = $this->createGuestOrder('cod');

        return [
            'order' => $data['order'],
            'token1' => (string) $data['tracking_token'],
        ];
    }

    /**
     * Blank the client cookie jar so the next request truly has no guest
     * credential (the test client merges cookies across requests).
     */
    protected function withoutGuestCookies(): void
    {
        $this->guestCookies = [];
        $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ]);
    }

    /** POST the resend action as the current guest (cookie transport + CSRF). */
    protected function resendAsOwner(int $orderId)
    {
        return $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $orderId . '/resend-tracking-link',
            [],
            $this->csrfHeaders()
        );
    }

    /** Extract the token query parameter from a delivered link. */
    private function tokenFromDeliveredLink(string $text): ?string
    {
        if (!preg_match('/https?:\/\/\S+/', $text, $matches)) {
            return null;
        }

        parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);

        return is_string($query['token'] ?? null) ? $query['token'] : null;
    }

    /** @test */
    public function the_owning_guest_reissues_a_fresh_token_and_both_channels_are_redelivered(): void    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $order = $fixture['order'];
        $hashBefore = $order->fresh()->guest_tracking_token_hash;

        // The fresh credential must reach both channels through the existing
        // notifier after the response.
        $this->fakeSms();
        $this->fakeRawEmail();
        Config::set('guest_checkout.tracking_link.enabled', true);

        $this->resendAsOwner($order->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id);

        $hashAfter = $order->fresh()->guest_tracking_token_hash;

        $this->assertNotSame(
            $hashBefore,
            $hashAfter,
            'Resend must rotate the stored tracking hash.'
        );

        $this->flushDelivery();

        // SMS channel: re-delivered with the FRESH credential only.
        $this->assertNotEmpty($this->smsSent, 'The SMS channel was not re-delivered.');
        $smsMessage = $this->smsSent[0]['message'];
        $this->assertSame('+201000000001', $this->smsSent[0]['to']);
        $this->assertStringContainsString($order->order_number, $smsMessage);
        $this->assertStringNotContainsString(
            $fixture['token1'],
            $smsMessage,
            'The re-delivered SMS must carry the FRESH token, not the original one.'
        );

        $smsToken = $this->tokenFromDeliveredLink($smsMessage);
        $this->assertNotNull($smsToken);
        $this->assertSame(
            $hashAfter,
            app(GuestOrderTokenService::class)->hash($smsToken),
            'The delivered SMS link must authenticate against the freshly rotated hash.'
        );

        // Email channel: re-delivered with the FRESH credential only.
        $this->assertNotEmpty($this->emails, 'The email channel was not re-delivered.');
        $email = $this->emails[0];
        $this->assertSame('guest.buyer@example.test', $email['to']);
        $this->assertStringContainsString($order->order_number, $email['subject']);
        $this->assertStringNotContainsString($fixture['token1'], $email['body']);

        $emailToken = $this->tokenFromDeliveredLink($email['body']);
        $this->assertNotNull($emailToken);
        $this->assertSame(
            $hashAfter,
            app(GuestOrderTokenService::class)->hash($emailToken),
            'The delivered email link must authenticate against the freshly rotated hash.'
        );
    }

    /** @test */
    public function a_foreign_guest_gets_not_found_and_nothing_rotates_or_sends(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $hashBefore = $fixture['order']->fresh()->guest_tracking_token_hash;

        $this->fakeSms();
        $this->fakeRawEmail();

        // A second browser (new Guest Identity) reaches for the first order.
        $this->startGuestSession(true);

        $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $fixture['order']->id . '/resend-tracking-link',
            [],
            $this->csrfHeaders()
        )->assertStatus(404)->assertJsonPath('success', false);

        $this->assertSame(
            $hashBefore,
            $fixture['order']->fresh()->guest_tracking_token_hash,
            'A foreign session must not rotate the stored hash.'
        );

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent, 'No SMS may be scheduled for a foreign session.');
        $this->assertCount(0, $this->emails, 'No email may be scheduled for a foreign session.');
    }

    /** @test */
    public function a_missing_session_is_rejected_before_any_credential_work(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $hashBefore = $fixture['order']->fresh()->guest_tracking_token_hash;

        $this->fakeSms();
        $this->fakeRawEmail();

        // A fresh browser: no guest credential at all.
        $this->withoutGuestCookies();

        $this->postJson(self::PREFIX . '/orders/' . $fixture['order']->id . '/resend-tracking-link')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertSame(
            $hashBefore,
            $fixture['order']->fresh()->guest_tracking_token_hash,
            'An unauthenticated request must not rotate the stored hash.'
        );

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent);
        $this->assertCount(0, $this->emails);
    }

    /** @test */
    public function a_token_only_caller_has_no_route_to_the_resend(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $hashBefore = $fixture['order']->fresh()->guest_tracking_token_hash;

        $this->fakeSms();
        $this->fakeRawEmail();

        // Possessing the VALID tracking token buys nothing: the resend is
        // unavailable without a verified Guest Identity (spam-safe).
        $this->withoutGuestCookies();

        $this->postJson(self::PREFIX . '/orders/' . $fixture['order']->id . '/resend-tracking-link', [
            'token' => $fixture['token1'],
        ])->assertStatus(401)->assertJsonPath('success', false);

        $this->assertSame($hashBefore, $fixture['order']->fresh()->guest_tracking_token_hash);

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent);
        $this->assertCount(0, $this->emails);
    }

    /** @test */
    public function admin_revoked_tracking_is_refused_and_never_resurrected(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $order = $fixture['order'];

        app(GuestOrderTokenService::class)->revokeTracking($order, null);
        $order->refresh();
        $revokedAt = $order->guest_tracking_revoked_at;
        $this->assertNotNull($revokedAt);

        $this->fakeSms();
        $this->fakeRawEmail();

        $this->resendAsOwner($order->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('guest_checkout.tracking_revoked'));

        $order->refresh();
        $this->assertNull(
            $order->guest_tracking_token_hash,
            'A refused resend must not resurrect admin-revoked tracking.'
        );
        $this->assertSame(
            $revokedAt->toIso8601String(),
            $order->guest_tracking_revoked_at->toIso8601String(),
            'Revocation must survive the refused resend untouched.'
        );

        // Repeat refusal stays stable (no partial resurrection on retry).
        $this->resendAsOwner($order->id)->assertStatus(409);

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent, 'No SMS may be scheduled for revoked tracking.');
        $this->assertCount(0, $this->emails, 'No email may be scheduled for revoked tracking.');
    }

    /** @test */
    public function disabled_tracking_link_config_honors_the_skip(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $order = $fixture['order'];
        $hashBefore = $order->fresh()->guest_tracking_token_hash;

        $this->fakeSms();
        $this->fakeRawEmail();

        // Re-issue semantics stay the same as the existing token re-issue,
        // while the notifier's enabled gate skips every channel.
        $this->resendAsOwner($order->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotSame(
            $hashBefore,
            $order->fresh()->guest_tracking_token_hash,
            'The skip is a delivery skip, not a re-issue skip.'
        );

        $this->flushDelivery();

        $this->assertCount(0, $this->smsSent, 'Disabled config must skip the SMS channel.');
        $this->assertCount(0, $this->emails, 'Disabled config must skip the email channel.');
    }

    /** @test */
    public function no_raw_tracking_token_reaches_any_log_context(): void
    {
        $fixture = $this->createOrderWithLinkDeliveryDisabled();
        $this->spyLogs();

        // SMS failure + email success: both log branches run, one channel
        // fails, the other still delivers (channel isolation).
        $this->fakeSms(true);
        $this->fakeRawEmail();
        Config::set('guest_checkout.tracking_link.enabled', true);

        $this->resendAsOwner($fixture['order']->id)->assertOk();
        $this->flushDelivery();

        $this->assertNotEmpty(
            $this->emails,
            'An SMS failure must not suppress the email channel.'
        );

        $freshToken = $this->tokenFromDeliveredLink($this->emails[0]['body']);
        $this->assertNotNull($freshToken, 'The email must carry the fresh credential.');

        foreach ($this->logEvents as $event) {
            $serialized = $event['message'] . json_encode($event['context']);

            $this->assertStringNotContainsString(
                $fixture['token1'],
                $serialized,
                "Raw tracking token leaked into a [{$event['level']}] log event."
            );
            $this->assertStringNotContainsString(
                $freshToken,
                $serialized,
                "Raw tracking token leaked into a [{$event['level']}] log event."
            );
        }

        $this->assertNotEmpty(
            $this->logEvents,
            'The negative assertion is only meaningful when the flow actually logged.'
        );
    }

    /** @test */
    public function a_burst_of_resend_requests_hits_the_fail_closed_limiter(): void
    {
        RateLimiter::for('guest_resend', function ($request) {
            return Limit::perMinute(2)->by('test:' . $request->ip());
        });

        $fixture = $this->createOrderWithLinkDeliveryDisabled();

        $this->resendAsOwner($fixture['order']->id)->assertOk();
        $this->resendAsOwner($fixture['order']->id)->assertOk();

        $third = $this->resendAsOwner($fixture['order']->id);

        $third->assertStatus(429);
        $third->assertJsonPath('success', false);
        $third->assertHeader('Retry-After');
    }
}
