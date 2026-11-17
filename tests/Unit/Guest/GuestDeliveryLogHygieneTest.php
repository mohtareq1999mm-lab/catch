<?php

namespace Tests\Unit\Guest;

use App\Services\Guest\GuestTrackingLinkNotifier;
use App\Services\Notifications\SMSService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Order;
use Mockery;
use Tests\TestCase;

/**
 * Delivery-path log hygiene (ticket #75 / spec #73 F2, F5, F6).
 *
 * Seam: SMSService::send() / Log facade fake — proves what reaches logs
 * (and therefore Telescope), not how the service is structured.
 */
class GuestDeliveryLogHygieneTest extends TestCase
{
    private const RECIPIENT = '+201012345678';

    private const SECRET_LINK = 'https://meem.com/track-order?token=trk_secret_token_123';

    /** Every log call made under the test, as [level, message, context]. */
    private array $logged = [];

    private function recordLogs(): void
    {
        foreach (['info', 'warning', 'error'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(
                function ($message, $context = []) use ($level) {
                    $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
                }
            );
        }
    }

    /**
     * Negative assertion over every captured log entry: neither the message
     * string nor any key/value of the context may contain a secret.
     */
    private function assertNothingLoggedContains(array $secrets): void
    {
        $this->assertNotEmpty($this->logged, 'expected the delivery path to log something');

        foreach ($this->logged as $entry) {
            $dump = $entry['message'] . ' ' . json_encode($entry['context']);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $dump,
                    "A log entry ({$entry['level']}) leaked a secret: {$dump}"
                );
            }
        }
    }

    private function order(array $attrs = []): Order
    {
        return (new Order())->forceFill(array_merge([
            'id' => 10,
            'order_number' => 'MEEM-10',
            'guest_id' => 5,
            'user_id' => null,
            'user_phone' => self::RECIPIENT,
            'user_email' => null,
        ], $attrs));
    }

    /** @test */
    public function simulated_send_logs_identifiers_only_never_body_link_or_recipient(): void
    {
        config(['services.twilio.enabled' => false]);

        $this->recordLogs();

        $message = 'Hi, track your MEEM-10 order: ' . self::SECRET_LINK;
        $result = app(SMSService::class)->send(self::RECIPIENT, $message, [
            'order_id' => 10,
            'type' => 'guest_tracking_link',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNothingLoggedContains([
            self::SECRET_LINK,        // link body carries the raw token
            'trk_secret_token_123',   // raw tracking token
            self::RECIPIENT,          // recipient number in clear
            'track your MEEM-10',     // message body prose
        ]);
    }

    /** @test */
    public function failed_send_masks_recipient_and_keeps_failure_observable(): void
    {
        // Twilio SDK is installed: with enabled=true and no credentials the
        // client constructor throws ConfigurationException deterministically,
        // driving the real failure branch (no network).
        config(['services.twilio.enabled' => true, 'services.twilio.sid' => null, 'services.twilio.token' => null]);

        $this->recordLogs();

        try {
            app(SMSService::class)->send(self::RECIPIENT, 'body withheld', ['order_id' => 10]);
            $this->fail('expected the send failure to surface');
        } catch (\RuntimeException $failure) {
            // Delivery behavior unchanged: the failure still surfaces.
        }

        $this->assertNothingLoggedContains([self::RECIPIENT, 'trk_secret_token_123']);

        $entry = collect($this->logged)->first(fn ($e) => $e['level'] === 'error');
        $this->assertNotNull($entry, 'failure must stay observable via an error log');
        $this->assertStringContainsString('***', (string) $entry['context']['to'], 'recipient must be masked');
    }

    /** @test */
    public function notifier_sms_failure_logs_exception_identity_not_provider_error_text(): void
    {
        $this->recordLogs();

        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn(self::RECIPIENT);
        $sms->shouldReceive('validatePhone')->andReturn(true);
        $sms->shouldReceive('send')->andThrow(new \RuntimeException(
            'Twilio: The To number ' . self::RECIPIENT . ' is not valid',
            400
        ));

        (new GuestTrackingLinkNotifier($sms))->notify($this->order(), 'trk_secret_token_123', 'en');

        // The provider error text echoes the recipient; neither it, the
        // number, nor the token may reach the log.
        $this->assertNothingLoggedContains([
            'The To number ' . self::RECIPIENT . ' is not valid',
            self::RECIPIENT,
            'trk_secret_token_123',
        ]);

        $entry = collect($this->logged)->first(fn ($e) => $e['level'] === 'warning');
        $this->assertNotNull($entry, 'the failure must stay observable via a warning log');
        $this->assertSame(\RuntimeException::class, $entry['context']['exception']);
        $this->assertSame(400, $entry['context']['code']);
    }

    /** @test */
    public function notifier_email_failure_logs_exception_identity_not_transport_error_text(): void
    {
        $this->recordLogs();

        Mail::shouldReceive('raw')->andThrow(new \RuntimeException(
            'SMTP error: recipient@example.com refused',
            554
        ));

        (new GuestTrackingLinkNotifier(app(SMSService::class)))
            ->notify($this->order(['user_phone' => null, 'user_email' => 'recipient@example.com']), 'trk_secret_token_123', 'en');

        $this->assertNothingLoggedContains([
            'SMTP error: recipient@example.com refused',
            'recipient@example.com',
            'trk_secret_token_123',
        ]);

        $entry = collect($this->logged)->first(fn ($e) => $e['level'] === 'warning');
        $this->assertNotNull($entry, 'the failure must stay observable via a warning log');
        $this->assertSame(\RuntimeException::class, $entry['context']['exception']);
        $this->assertSame(554, $entry['context']['code']);
    }

    /** @test */
    public function simulated_send_still_logs_identifiers_for_traceability(): void
    {
        config(['services.twilio.enabled' => false]);

        $this->recordLogs();

        app(SMSService::class)->send(self::RECIPIENT, 'body withheld', [
            'order_id' => 10,
            'type' => 'guest_tracking_link',
        ]);

        $entry = collect($this->logged)->first(fn ($e) => $e['level'] === 'info');
        $this->assertNotNull($entry, 'simulated send must stay observable via an info log');
        $this->assertSame(10, $entry['context']['metadata']['order_id']);
        $this->assertSame('guest_tracking_link', $entry['context']['metadata']['type']);
    }
}
