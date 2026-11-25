<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ProviderMode;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\CarrierStatusMapper;
use App\Services\Shipment\CarrierWebhookVerifier;
use App\Services\Shipment\LabelContentValidator;
use App\Services\Shipment\Providers\NullCarrierGateway;
use App\Services\Shipment\ProviderPollingCapabilityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderPollingCapabilityAndLabelValidationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function polling_capability_is_explicit_per_provider(): void
    {
        config([
            'shipping.providers.polling_test' => $this->providerConfig([
                'polling' => [
                    'supported' => true,
                    'cursor_type' => 'timestamp',
                    'pagination' => 'page',
                    'overlap_window_seconds' => 300,
                ],
            ]),
        ]);

        $capability = app(ProviderPollingCapabilityResolver::class)
            ->forProvider('polling_test', ProviderMode::SANDBOX->value);

        $this->assertSame([
            'supported' => true,
            'cursor_type' => 'timestamp',
            'pagination' => 'page',
            'overlap_window_seconds' => 300,
        ], $capability);
    }

    #[Test]
    public function polling_capability_fails_closed_when_not_explicitly_declared(): void
    {
        $config = $this->providerConfig();
        unset($config['polling']);
        config(['shipping.providers.missing_polling' => $config]);

        $this->assertSame(
            CarrierConfigurationException::invalidPollingCapability('missing_polling')->getMessage(),
            $this->exceptionMessage(fn () => app(ProviderPollingCapabilityResolver::class)
                ->forProvider('missing_polling', ProviderMode::SANDBOX->value)),
        );
    }

    #[Test]
    public function polling_capability_rejects_unknown_and_reserved_modes(): void
    {
        config(['shipping.providers.polling_test' => $this->providerConfig()]);
        $resolver = app(ProviderPollingCapabilityResolver::class);

        foreach (['unknown-mode', '', ProviderMode::INTERNAL->value] as $mode) {
            $expected = $mode === ProviderMode::INTERNAL->value
                ? CarrierConfigurationException::internalPollingNotResolved('polling_test')
                : CarrierConfigurationException::invalidMode('polling_test', $mode);

            $this->assertSame(
                $expected->getMessage(),
                $this->exceptionMessage(fn () => $resolver->forProvider('polling_test', $mode)),
            );
        }
    }

    #[Test]
    public function polling_capability_rejects_coerced_negative_and_overflow_overlap_values(): void
    {
        foreach (['300', 300.0, -1, 86401, null] as $overlap) {
            $this->assertInvalidPolling([
                'supported' => true,
                'cursor_type' => 'timestamp',
                'pagination' => 'page',
                'overlap_window_seconds' => $overlap,
            ]);
        }
    }

    #[Test]
    public function polling_capability_rejects_invalid_supported_false_and_type_shapes(): void
    {
        $this->assertInvalidPolling(['supported' => 'false']);
        $this->assertInvalidPolling(null);
        $this->assertInvalidPolling([
            'supported' => false,
            'cursor_type' => 'timestamp',
            'pagination' => null,
            'overlap_window_seconds' => 0,
        ]);
        $this->assertInvalidPolling([
            'supported' => true,
            'cursor_type' => 'bad',
            'pagination' => 'page',
            'overlap_window_seconds' => 0,
        ]);
    }

    #[Test]
    public function polling_capability_rejects_none_cursor_without_a_recovery_contract(): void
    {
        $this->assertInvalidPolling([
            'supported' => true,
            'cursor_type' => 'none',
            'pagination' => 'none',
            'overlap_window_seconds' => 0,
        ]);
        $this->assertInvalidPolling([
            'supported' => true,
            'cursor_type' => 'none',
            'pagination' => 'page',
            'overlap_window_seconds' => 0,
        ]);
        $this->assertInvalidPolling([
            'supported' => true,
            'cursor_type' => 'none',
            'pagination' => 'none',
            'overlap_window_seconds' => 300,
        ]);

        $config = $this->providerConfig([
            'polling' => [
                'supported' => true,
                'cursor_type' => 'none',
                'pagination' => 'page',
                'overlap_window_seconds' => 300,
            ],
        ]);
        config(['shipping.providers.recovery_contract' => $config]);

        $this->assertTrue(app(ProviderPollingCapabilityResolver::class)
            ->forProvider('recovery_contract', ProviderMode::SANDBOX->value)['supported']);
    }

    #[Test]
    public function polling_capability_mode_level_override_is_authoritative(): void
    {
        $config = $this->providerConfig([
            'polling' => [
                'supported' => true,
                'cursor_type' => 'timestamp',
                'pagination' => 'page',
                'overlap_window_seconds' => 300,
            ],
        ]);
        $config['modes'][ProviderMode::SANDBOX->value]['polling'] = [
            'supported' => false,
            'cursor_type' => null,
            'pagination' => null,
            'overlap_window_seconds' => 0,
        ];
        config(['shipping.providers.mode_override' => $config]);

        $this->assertSame([
            'supported' => false,
            'cursor_type' => null,
            'pagination' => null,
            'overlap_window_seconds' => 0,
        ], app(ProviderPollingCapabilityResolver::class)
            ->forProvider('mode_override', ProviderMode::SANDBOX->value));
    }

    #[Test]
    public function runtime_config_readers_fail_closed_without_an_explicit_polling_declaration(): void
    {
        $missing = $this->providerConfig();
        unset($missing['polling']);
        config(['shipping.providers.missing_polling' => $missing]);

        $this->assertSame(
            CarrierConfigurationException::invalidPollingCapability('missing_polling')->getMessage(),
            $this->exceptionMessage(fn () => app(CarrierGatewayFactory::class)->status('missing_polling')),
        );

        config(['shipping.providers.polling_status' => $this->providerConfig(['polling' => null])]);
        $this->assertSame(
            CarrierConfigurationException::invalidPollingCapability('polling_status')->getMessage(),
            $this->exceptionMessage(fn () => app(CarrierGatewayFactory::class)->status('polling_status')),
        );

        // Explicit supported=false is the only approved legacy compatibility.
        config(['shipping.providers.legacy_provider' => $this->providerConfig()]);
        $this->assertTrue(app(CarrierGatewayFactory::class)->status('legacy_provider')['enabled']);
    }

    #[Test]
    public function label_validation_accepts_well_formed_pdf_and_png_content(): void
    {
        $validator = app(LabelContentValidator::class);

        $this->assertTrue($validator->validate($this->pdf(), 'application/pdf')['valid']);
        $this->assertTrue($validator->validate($this->png(), 'image/png')['valid']);
    }

    #[Test]
    public function label_validation_rejects_empty_oversized_disallowed_and_mismatched_content(): void
    {
        $validator = app(LabelContentValidator::class);

        $this->assertSame('empty_content', $validator->validate('', 'application/pdf')['reason']);
        $this->assertSame('content_too_large', $validator->validate($this->pdf(), 'application/pdf', ['application/pdf'], 10)['reason']);
        $this->assertSame('declared_mime_not_allowed', $validator->validate($this->pdf(), 'text/html')['reason']);
        $this->assertSame('declared_mime_not_allowed', $validator->validate($this->png(), 'image/png', ['image/jpeg'])['reason']);
        $this->assertSame('mime_mismatch', $validator->validate($this->pdf(), 'image/png')['reason']);
    }

    #[Test]
    public function label_validation_rejects_spoofed_and_structurally_incomplete_content(): void
    {
        $validator = app(LabelContentValidator::class);

        $this->assertSame('unknown_format', $validator->validate('<script>alert(1)</script>%PDF-1.4', 'application/pdf')['reason']);
        $this->assertSame('malformed_content', $validator->validate("%PDF-1.4\nno-eof", 'application/pdf')['reason']);
        $this->assertSame('malformed_content', $validator->validate("%PDF-1.9\n1 0 obj\nendobj\n%%EOF", 'application/pdf')['reason']);
        $this->assertSame('malformed_content', $validator->validate(substr($this->png(), 0, -12), 'image/png')['reason']);
    }

    #[Test]
    public function label_validation_rejects_non_positive_size_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(LabelContentValidator::class)->validate($this->pdf(), 'application/pdf', ['application/pdf'], 0);
    }

    #[Test]
    public function label_assert_valid_returns_typed_success_metadata(): void
    {
        $this->assertSame(
            ['valid' => true, 'detected_mime' => 'application/pdf', 'reason' => null],
            app(LabelContentValidator::class)->assertValid($this->pdf(), 'application/pdf'),
        );
    }

    #[Test]
    public function label_assert_valid_fails_closed_for_callers(): void
    {
        try {
            app(LabelContentValidator::class)->assertValid('garbage', 'application/pdf');
            $this->fail('assertValid() must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid label content: unknown_format', $exception->getMessage());
        }
    }

    #[Test]
    public function internal_mode_is_rejected_outside_the_internal_provider(): void
    {
        $config = $this->providerConfig();
        $config['mode'] = ProviderMode::INTERNAL->value;
        $config['modes'] = [
            ProviderMode::INTERNAL->value => [
                'enabled' => true,
                'credentials' => ['local' => 'internal'],
                'status_map' => ['version' => 1, 'statuses' => []],
                'capability_verification' => [],
            ],
        ];
        config(['shipping.providers.bad_internal' => $config]);

        $factory = app(CarrierGatewayFactory::class);
        foreach (['resolveMode', 'status', 'make'] as $method) {
            try {
                $factory->{$method}('bad_internal');
                $this->fail("{$method} must reject internal mode for an external provider.");
            } catch (CarrierConfigurationException) {
            }
        }

        $this->assertSame(
            CarrierConfigurationException::internalModeReserved('bad_internal')->getMessage(),
            $this->exceptionMessage(fn () => $factory->status('bad_internal')),
        );
    }

    #[Test]
    public function internal_provider_cannot_use_a_non_internal_mode_across_config_consumers(): void
    {
        config(['shipping.providers.internal.mode' => ProviderMode::SANDBOX->value]);
        $factory = app(CarrierGatewayFactory::class);

        foreach (['resolveMode', 'status', 'make'] as $method) {
            try {
                $factory->{$method}('internal');
                $this->fail("{$method} must reject a non-internal mode for the internal provider.");
            } catch (CarrierConfigurationException) {
            }
        }

        $this->assertNull(app(CarrierStatusMapper::class)->mappingVersion('internal', ProviderMode::SANDBOX->value));
    }

    #[Test]
    public function webhook_verifier_keeps_one_argument_construction_from_number_169(): void
    {
        config([
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => 'whsec-169',
        ]);

        $verifier = new CarrierWebhookVerifier(config());

        $this->assertSame('X-Shipping-Signature', $verifier->signatureHeader('null'));
    }

    #[Test]
    public function internal_mode_never_resolves_webhooks_without_relying_on_schemes(): void
    {
        config([
            'shipping.providers.null.mode' => ProviderMode::INTERNAL->value,
            'shipping.providers.internal.mode' => ProviderMode::INTERNAL->value,
        ]);

        // 'null' has a scheme; 'internal' does not. Both must fail with the
        // explicit internal-mode guard before any allowlist decision.
        foreach (['null', 'internal'] as $providerCode) {
            $this->assertSame(
                CarrierConfigurationException::internalWebhookNotResolved($providerCode)->getMessage(),
                $this->exceptionMessage(fn () => app(CarrierWebhookVerifier::class)
                    ->signatureHeader($providerCode)),
            );
        }
    }

    #[Test]
    public function internal_mode_never_resolves_polling(): void
    {
        $this->assertSame(
            CarrierConfigurationException::internalPollingNotResolved('internal')->getMessage(),
            $this->exceptionMessage(fn () => app(ProviderPollingCapabilityResolver::class)
                ->forProvider('internal', ProviderMode::INTERNAL->value)),
        );
    }

    #[Test]
    public function invalid_polling_capability_fails_webhook_config_reader(): void
    {
        $config = $this->providerConfig(['polling' => null]);
        $config['modes'][ProviderMode::SANDBOX->value]['webhook'] = [
            'secret' => 'whsec-175',
            'version' => 'v1',
        ];
        config(['shipping.providers.null' => $config]);

        $this->assertSame(
            CarrierConfigurationException::invalidPollingCapability('null')->getMessage(),
            $this->exceptionMessage(fn () => app(CarrierWebhookVerifier::class)->signatureHeader('null')),
        );
    }

    #[Test]
    public function status_mapping_fails_closed_for_internal_mode_on_an_external_provider(): void
    {
        $config = $this->providerConfig();
        $config['mode'] = ProviderMode::INTERNAL->value;
        $config['modes'] = [
            ProviderMode::INTERNAL->value => [
                'enabled' => true,
                'status_map' => ['version' => 1, 'statuses' => ['created' => 'pending']],
            ],
        ];
        config(['shipping.providers.bad_internal_map' => $config]);

        $this->assertNull(app(CarrierStatusMapper::class)->mappingVersion('bad_internal_map', 'internal'));
    }

    #[Test]
    public function historical_provider_and_webhook_snapshots_survive_eloquent_mutation(): void
    {
        $webhook = $this->createWebhook('evt-175-1');
        $event = $this->createProviderEvent($webhook);

        $webhook->provider_code = 'other';
        $webhook->mode = ProviderMode::PRODUCTION->value;
        $webhook->account_code = 'other-account';
        $webhook->event_key = 'forged-key';
        $webhook->redacted_payload = ['id' => 'forged'];
        $webhook->save();

        $event->provider_code = 'other';
        $event->mode = ProviderMode::PRODUCTION->value;
        $event->account_code = 'forged';
        $event->mapping_version = '2';
        $event->payload_hash = str_repeat('b', 64);
        $event->save();

        $freshWebhook = $webhook->fresh();
        $this->assertSame('internal', $freshWebhook->provider_code);
        $this->assertSame(ProviderMode::INTERNAL->value, $freshWebhook->mode);
        $this->assertSame('internal-local', $freshWebhook->account_code);
        $this->assertSame('evt-175-1', $freshWebhook->event_key);
        $this->assertSame(['id' => 'evt-175-1'], $freshWebhook->redacted_payload);

        $freshEvent = $event->fresh();
        $this->assertSame('internal', $freshEvent->provider_code);
        $this->assertSame(ProviderMode::INTERNAL->value, $freshEvent->mode);
        $this->assertSame('1', $freshEvent->mapping_version);
        $this->assertSame(str_repeat('a', 64), $freshEvent->payload_hash);
    }

    #[Test]
    public function database_triggers_reject_snapshot_writes_that_bypass_model_events(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Snapshot immutability triggers are MySQL-only.');
        }

        $webhook = $this->createWebhook('evt-175-bypass');
        $event = $this->createProviderEvent($webhook);

        $event->account_code = 'quiet-bypass';
        try {
            $event->saveQuietly();
            $this->fail('saveQuietly() must not rewrite a provider-event snapshot.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'shipping_provider_events snapshot fields are immutable (#175)',
                $exception->getMessage(),
            );
        }

        try {
            DB::table('shipping_webhook_events')
                ->where('id', $webhook->id)
                ->update(['provider_code' => 'query-bypass']);
            $this->fail('Query-builder writes must not rewrite a webhook snapshot.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'shipping_webhook_events snapshot fields are immutable (#175)',
                $exception->getMessage(),
            );
        }

        $this->assertSame('internal-local', $event->fresh()->account_code);
        $this->assertSame('internal', DB::table('shipping_webhook_events')->where('id', $webhook->id)->value('provider_code'));
    }

    #[Test]
    public function operational_fields_remain_mutable_under_database_triggers(): void
    {
        $webhook = $this->createWebhook('evt-175-operational');
        $event = $this->createProviderEvent($webhook);

        DB::table('shipping_webhook_events')
            ->where('id', $webhook->id)
            ->update(['status' => 'processed', 'last_error' => null]);

        $event->mapped_status = 'in_transit';
        $event->applied_at = now();
        $event->saveQuietly();

        $this->assertSame('processed', $webhook->fresh()->status);
        $this->assertSame('in_transit', $event->fresh()->mapped_status);
        $this->assertNotNull($event->fresh()->applied_at);
    }

    /**
     * @param  array<string,mixed>|null  $polling
     */
    private function assertInvalidPolling(?array $polling): void
    {
        config(['shipping.providers.polling_invalid' => $this->providerConfig(['polling' => $polling])]);

        $this->assertSame(
            CarrierConfigurationException::invalidPollingCapability('polling_invalid')->getMessage(),
            $this->exceptionMessage(fn () => app(ProviderPollingCapabilityResolver::class)
                ->forProvider('polling_invalid', ProviderMode::SANDBOX->value)),
        );
    }

    private function createWebhook(string $eventKey): ShippingWebhookEvent
    {
        return ShippingWebhookEvent::query()->create([
            'provider_code' => 'internal',
            'mode' => ProviderMode::INTERNAL->value,
            'account_code' => 'internal-local',
            'webhook_version' => 'v1',
            'event_key' => $eventKey,
            'event_type' => 'shipment.updated',
            'redacted_payload' => ['id' => $eventKey],
            'raw_status' => 'created',
            'status' => 'received',
            'received_at' => now(),
        ]);
    }

    private function createProviderEvent(ShippingWebhookEvent $webhook): ShippingProviderEvent
    {
        return ShippingProviderEvent::query()->create([
            'webhook_event_id' => $webhook->id,
            'provider_code' => 'internal',
            'mode' => ProviderMode::INTERNAL->value,
            'account_code' => 'internal-local',
            'provider_event_key' => $webhook->event_key,
            'raw_status' => 'created',
            'received_at' => now(),
            'mapping_version' => '1',
            'payload_reference' => 'shipping_webhook_event:'.$webhook->id,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function exceptionMessage(callable $callback): string
    {
        try {
            $callback();
        } catch (CarrierConfigurationException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected a CarrierConfigurationException.');
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function png(): string
    {
        $ihdr = 'IHDR'
            .pack('N', 1).pack('N', 1)
            .chr(8).chr(6).chr(0).chr(0).chr(0)
            .pack('N', 0);

        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13).$ihdr.pack('N', 0)
            .pack('N', 0).'IEND'.pack('N', 0xAE426082);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function providerConfig(array $overrides = []): array
    {
        $config = [
            'adapter' => NullCarrierGateway::class,
            'mode' => ProviderMode::SANDBOX->value,
            'account_code' => 'polling-test',
            'service_level' => 'standard',
            'channels' => ['web'],
            'geographies' => ['EG'],
            'polling' => [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ],
            'modes' => [
                ProviderMode::SANDBOX->value => [
                    'enabled' => true,
                    'credentials' => ['api_key' => 'sandbox-key'],
                    'status_map' => ['version' => 1, 'statuses' => []],
                    'capability_verification' => [],
                ],
            ],
        ];

        return array_replace_recursive($config, $overrides);
    }
}
