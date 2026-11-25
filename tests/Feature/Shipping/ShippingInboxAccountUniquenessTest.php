<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\OrderStatusChanged;
use App\Events\PaymentSucceeded;
use App\Events\Shipment\ShipmentStatusChanged;
use App\Exceptions\ShippingInboxDuplicateException;
use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\ShippingInboxDuplicateAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * #182: inbox identity is scoped by provider + mode + server-configured
 * account + event key. The duplicate audit runs before constraint enforcement,
 * and ingest remains evidence-only.
 */
class ShippingInboxAccountUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/general/shipping/webhooks/null';

    private const SECRET = 'whsec_test_182';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.account_code' => 'account-a-182',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.sandbox.webhook.version' => 'v1',
            'shipping.webhook_rate_limit.max_attempts' => 60,
            'shipping.webhook_rate_limit.decay_seconds' => 60,
        ]);
    }

    public function test_same_event_key_is_unique_per_account_and_mode(): void
    {
        $payload = $this->payload(['id' => 'evt_scope_182']);

        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'duplicate');

        config(['shipping.providers.null.account_code' => 'account-b-182']);
        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        config([
            'shipping.providers.null.mode' => 'production',
            'shipping.providers.null.account_code' => 'account-a-182',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);
        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $rows = ShippingWebhookEvent::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['account-a-182', 'account-b-182', 'account-a-182'], $rows->pluck('account_code')->all());
        $this->assertSame(['sandbox', 'sandbox', 'production'], $rows->pluck('mode')->all());
    }

    public function test_account_code_is_non_secret_config_identity_stable_across_credential_rotation(): void
    {
        $payload = $this->payload([
            'id' => 'evt_rotation_182',
            // Untrusted payload identity must never influence the inbox scope.
            'account_code' => 'attacker-controlled',
        ]);

        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $this->assertSame('account-a-182', ShippingWebhookEvent::firstOrFail()->account_code);

        // Rotate only the credential; the account code stays stable.
        config(['shipping.providers.null.modes.sandbox.webhook.secret' => 'whsec_rotated_182']);
        $this->postWebhook($payload, 'whsec_rotated_182')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(1, ShippingWebhookEvent::count());

        // A different configured account is a distinct identity.
        config(['shipping.providers.null.account_code' => 'account-c-182']);
        $this->postWebhook($payload, 'whsec_rotated_182')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted');
        $this->assertSame(
            ['account-a-182', 'account-c-182'],
            ShippingWebhookEvent::query()->orderBy('id')->pluck('account_code')->all(),
        );
    }

    public function test_valid_ingest_writes_only_the_inbox_and_no_lifecycle_or_financial_state(): void
    {
        $shipment = $this->createShipment();
        $before = $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_statements', 'cod_settlement_lines',
        ]);

        Event::fake([OrderStatusChanged::class, PaymentSucceeded::class, ShipmentStatusChanged::class]);
        Bus::fake();
        Queue::fake();

        $this->postWebhook($this->payload([
            'id' => 'evt_no_mutation_182',
            'awb' => $shipment->tracking_number,
        ]))->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame(0, ShippingProviderEvent::count());
        $this->assertSame($before, $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_statements', 'cod_settlement_lines',
        ]));
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame('pending', Order::findOrFail($shipment->order_id)->status);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(ShipmentStatusChanged::class);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }

    public function test_legacy_duplicates_are_audited_and_block_constraint_enforcement(): void
    {
        DB::table('activity_log')->delete();
        Schema::table('shipping_provider_events', function ($table): void {
            $table->dropUnique('uq_shipping_provider_event_identity');
        });

        $webhookIds = [];
        $providerIds = [];

        try {
            foreach (['evt_dup_a_182', 'evt_dup_b_182'] as $inboxKey) {
                $webhookIds[] = ShippingWebhookEvent::create([
                    'provider_code' => 'null',
                    'mode' => 'sandbox',
                    'account_code' => 'account-a-182',
                    'webhook_version' => 'v1',
                    'event_key' => $inboxKey,
                    'event_type' => 'shipment.status_changed',
                    'redacted_payload' => ['status' => 'in_transit'],
                    'status' => 'received',
                    'received_at' => now(),
                ])->id;
            }

            foreach ($webhookIds as $webhookId) {
                $providerIds[] = ShippingProviderEvent::create([
                    'webhook_event_id' => $webhookId,
                    'provider_code' => 'null',
                    'mode' => 'sandbox',
                    'account_code' => 'account-a-182',
                    'provider_event_key' => 'evt_legacy_duplicate_182',
                    'payload_reference' => 'shipping_webhook_event:'.$webhookId,
                    'payload_hash' => str_repeat('a', 64),
                ])->id;
            }

            $audit = app(ShippingInboxDuplicateAudit::class);
            $report = $audit->audit('test');

            $this->assertSame(1, $report['total_duplicate_groups']);
            $this->assertSame(1, $report['total_duplicate_rows']);
            $this->assertSame('evt_legacy_duplicate_182', $report['provider_events'][0]['provider_event_id']);
            $this->assertArrayNotHasKey('provider_event_key', $report['provider_events'][0]);

            $logged = Activity::query()->where('log_name', 'shipping-inbox')
                ->where('event', 'shipping_inbox_duplicates_found')
                ->latest('id')
                ->firstOrFail();
            $this->assertStringContainsString(
                'evt_legacy_duplicate_182',
                json_encode($logged->properties, JSON_THROW_ON_ERROR),
            );

            try {
                $audit->assertClean($report);
                $this->fail('Duplicate legacy rows must block constraint enforcement.');
            } catch (ShippingInboxDuplicateException) {
                $this->assertTrue(true);
            }
        } finally {
            if ($providerIds !== []) {
                DB::table('shipping_provider_events')->whereIn('id', $providerIds)->delete();
            }
            if ($webhookIds !== []) {
                DB::table('shipping_webhook_events')->whereIn('id', $webhookIds)->delete();
            }
            Schema::table('shipping_provider_events', function ($table): void {
                $table->unique(
                    ['provider_code', 'mode', 'account_code', 'provider_event_key'],
                    'uq_shipping_provider_event_identity',
                );
            });
        }
    }

    /** @param list<string> $tables */
    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function createShipment(): Shipment
    {
        $user = User::create([
            'name' => 'Inbox Uniqueness User',
            'email' => 'inbox-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Inbox Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);

        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'tracking_number' => 'AWB-182-NO-MUTATION',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'id' => 'evt_'.Str::lower(Str::random(12)),
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'occurred_at' => '2026-11-25T10:00:00Z',
            'data' => ['shipment' => ['id' => 'carrier_'.Str::lower(Str::random(8))]],
        ], $overrides);
    }

    /** @param array<string,mixed> $payload */
    private function postWebhook(array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'v1='.hash_hmac('sha256', $raw, $secret ?? self::SECRET);

        return $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'X-Shipping-Signature' => $signature,
            ]),
            $raw,
        );
    }
}
