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

        $this->expectException(CarrierConfigurationException::class);
        app(ProviderPollingCapabilityResolver::class)
            ->forProvider('missing_polling', ProviderMode::SANDBOX->value);
    }

    #[Test]
    public function polling_capability_rejects_unknown_and_reserved_modes(): void
    {
        config(['shipping.providers.polling_test' => $this->providerConfig()]);
        $resolver = app(ProviderPollingCapabilityResolver::class);

        foreach (['unknown-mode', '', ProviderMode::INTERNAL->value] as $mode) {
            try {
                $resolver->forProvider('polling_test', $mode);
                $this->fail("Mode [{$mode}] must fail closed.");
            } catch (CarrierConfigurationException) {
                $this->addToAssertionCount(1);
            }
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
    public function factory_validates_declared_polling_but_tolerates_legacy_undeclared_providers(): void
    {
        config(['shipping.providers.polling_status' => $this->providerConfig(['polling' => null])]);

        try {
            app(CarrierGatewayFactory::class)->status('polling_status');
            $this->fail('A declared but invalid polling capability must fail closed.');
        } catch (CarrierConfigurationException) {
        }

        $legacy = $this->providerConfig();
        unset($legacy['polling']);
        config(['shipping.providers.legacy_provider' => $legacy]);

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
    public function label_assert_valid_fails_closed_for_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(LabelContentValidator::class)->assertValid('garbage', 'application/pdf');
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
    public function internal_mode_is_enforced_in_webhook_config_reader(): void
    {
        config(['shipping.providers.null.mode' => ProviderMode::INTERNAL->value]);
        $this->expectException(CarrierConfigurationException::class);
        app(CarrierWebhookVerifier::class)->signatureHeader('null');
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
    public function snapshot_guards_document_save_quietly_and_query_builder_bypass(): void
    {
        $webhook = $this->createWebhook('evt-175-bypass');
        $event = $this->createProviderEvent($webhook);

        $event->account_code = 'quiet-bypass';
        $event->saveQuietly();
        DB::table('shipping_webhook_events')
            ->where('id', $webhook->id)
            ->update(['provider_code' => 'query-bypass']);

        // Model events do not cover saveQuietly()/upsert/query-builder writes.
        // Full AC4 immutability would require database-level enforcement.
        $this->assertSame('quiet-bypass', $event->fresh()->account_code);
        $this->assertSame('query-bypass', DB::table('shipping_webhook_events')->where('id', $webhook->id)->value('provider_code'));
    }

    /**
     * @param  array<string,mixed>|null  $polling
     */
    private function assertInvalidPolling(?array $polling): void
    {
        config(['shipping.providers.polling_invalid' => $this->providerConfig(['polling' => $polling])]);

        try {
            app(ProviderPollingCapabilityResolver::class)
                ->forProvider('polling_invalid', ProviderMode::SANDBOX->value);
            $this->fail('Invalid polling capability must fail closed.');
        } catch (CarrierConfigurationException) {
            $this->addToAssertionCount(1);
        }
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
