<?php

declare(strict_types=1);

namespace Tests\Feature\Guest;

use App\Contracts\GuestPhoneVerificationContract;
use App\Enums\GuestCredentialDomain;
use App\Models\GuestIdentity;
use App\Models\GuestOrderClaimToken;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Guest\GuestCredentialService;
use App\Services\Guest\GuestIdentityService;
use App\Services\Guest\GuestOrderClaimService;
use App\ValueObjects\VerifiedPhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class GuestOrderClaimRecipientIdentityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_claim_flow_links_guest_recipient_identity_without_resend_or_state_mutation(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        $user = User::create(['name' => 'Claim', 'email' => 'claim@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $guest = GuestIdentity::query()->create(['token_hash' => hash('sha256', 'guest-token'), 'expires_at' => now()->addDay()]);
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $recipient->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64),
        ]);

        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-176-REAL',
            'guest_id' => $guest->id,
            'user_id' => null,
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
            'total_price' => 10,
            'price' => 10,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'shipping_method' => 'SCHEDULED',
            'address' => [],
            'name' => 'G',
            'user_phone' => '+201000000777',
            'guest_cancellation_token_hash' => hash('sha256', 'cancel'),
            'guest_tracking_token_hash' => hash('sha256', 'track'),
        ]);

        $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_tracking_events')->insert([
            'order_id' => $order->id, 'event_type' => 'shipment.status_changed', 'old_status' => 'pending',
            'new_status' => 'picked_up', 'source' => 'direct', 'actor_type' => 'system', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $timelineId = (int) DB::table('order_tracking_events')->value('id');
        ShipmentNotification::query()->create([
            'shipment_id' => $shipmentId, 'timeline_event_id' => $timelineId, 'channel' => 'email',
            'recipient_type' => 'guest_contact', 'recipient_id' => (string) $recipient->id,
            'status' => 'sent', 'dedupe_key' => 'dedupe-176',
        ]);

        $credentials = app(GuestCredentialService::class);
        $rawToken = $credentials->mint();
        GuestOrderClaimToken::query()->create([
            'guest_id' => $guest->id,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'token_hash' => $credentials->hash($rawToken, GuestCredentialDomain::Legacy),
            'expires_at' => now()->addHour(),
        ]);

        $beforeShipment = (array) DB::table('shipments')->where('id', $shipmentId)->first();
        $beforeNotificationCount = ShipmentNotification::query()->count();
        $beforePaymentStatus = $order->payment_status;
        $beforeShipmentStatus = $order->shipment_status;

        $this->app->instance(GuestPhoneVerificationContract::class, new class($user, $order->user_phone) implements GuestPhoneVerificationContract {
            public function __construct(private User $u, private string $p) {}
            public function verify(User $user, string $verificationReference, string $phone): ?VerifiedPhoneIdentity
            {
                return new VerifiedPhoneIdentity((int) $this->u->id, $this->p, $verificationReference, now());
            }
        });

        $request = Request::create('/claim', 'POST');
        $this->mock(GuestIdentityService::class, function ($m) use ($guest) {
            $m->shouldReceive('current')->andReturn($guest);
        });

        $result = app(GuestOrderClaimService::class)->claim($user, $request, $rawToken, 'ref', $order->user_phone);

        $this->assertSame('claimed', $result['status']);
        $order->refresh();
        $recipient->refresh();
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($user->id, $recipient->user_id);
        $this->assertSame($guest->id, $recipient->guest_id);
        $this->assertSame($beforePaymentStatus, $order->payment_status);
        $this->assertSame($beforeShipmentStatus, $order->shipment_status);
        $this->assertEquals($beforeShipment, (array) DB::table('shipments')->where('id', $shipmentId)->first());
        $this->assertSame($beforeNotificationCount, ShipmentNotification::query()->count());
        $this->assertSame(1, ShipmentNotification::query()->where('dedupe_key', 'dedupe-176')->where('recipient_id', (string) $recipient->id)->count());
        $this->assertNotNull(NotificationRecipientFingerprint::query()->where('recipient_id', $recipient->id)->first());
    }
}
