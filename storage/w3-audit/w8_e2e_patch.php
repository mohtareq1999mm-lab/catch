<?php
$f = realpath('tests/Feature/Digital/DigitalClosureBatteryTest.php');
$t = file_get_contents($f);
$start = strpos($t, 'public function test_e2e_purchase_fulfillment_notification_download_limit_expiry_restore');
$end = strpos($t, '/* ===============================================================', $start + 10);
if ($start === false || $end === false) { echo "anchors missing\n"; exit(1); }

$new = <<<'METHOD'
public function test_e2e_purchase_fulfillment_notification_download_limit_expiry_restore()
    {
        // Notification recipients are UserType::USER accounts (W1 contract).
        $recipient = User::create([
            'name' => 'Closure User', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'user',
        ]);

        $asset = $this->asset();

        $entitlement = $this->entitle($recipient, limit: 2);

        // Fulfill through the REAL event pipeline.
        $order = $entitlement->order()->first();
        \Illuminate\Support\Facades\Event::dispatch(new \App\Events\PaymentSucceeded($order->fresh()));

        $entitlement = $entitlement->fresh();
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->status);

        // Delivery notification persisted for the recipient (database channel).
        $notification = DatabaseNotification::query()
            ->where('notifiable_type', \Marvel\Database\Models\User::class)
            ->where('notifiable_id', $recipient->id)
            ->where('type', \App\Notifications\UserDigitalProductsAvailableNotification::class)
            ->first();
        $this->assertNotNull($notification, 'delivery-available notification must be persisted');

        // Download twice -> cap reached.
        $signed = fn () => URL::temporarySignedRoute('general.digital.download', now()->addMinutes(5), [
            'entitlement' => $entitlement->uuid, 'asset' => $asset->uuid,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(403);

        // Admin lifts cap to unlimited.
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/limit')
            ->assertStatus(200)
            ->assertJsonPath('data.unlimited', true);

        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
        $this->assertSame(3, (int) $entitlement->refresh()->download_count);

        // Revoke blocks; restore re-allows.
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/revoke')->assertStatus(200);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(403);

        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/restore')->assertStatus(200);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
    }

METHOD;

$t = substr_replace($t, $new, $start, $end - $start - 4); // keep closing newline of block
file_put_contents($f, $t);
echo "E2E method rewritten\n";
