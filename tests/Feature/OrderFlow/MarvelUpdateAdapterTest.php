<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\OrderStatus as LegacyOrderStatus;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P3-2 (decisions D4/D5): Marvel OrderRepository::updateOrder is a thin
 * adapter over the canonical writer — no independent guard logic.
 *
 * - Mapped legacy codes (pending/processing/completed/cancelled/
 *   out_for_delivery/ready_for_pickup) flow through
 *   OrderService::changeOrderStatus (flow authority, granular permission,
 *   history, side effects) with the status mirror kept in sync.
 * - The canonical writer re-syncs the legacy `order_status` column (D4)
 *   so Marvel readers never drift.
 * - Legacy-only codes (refunded/failed/at_local_facility/...) and unknown
 *   strings are frozen with an explicit reason (D5).
 * - Flow-invalid mapped codes are rejected by the canonical guard.
 */
class MarvelUpdateAdapterTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Adapter Customer',
            'email' => 'adapter-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Adapter Admin',
            'email' => 'adapter-admin-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if (Schema::hasTable('roles')) {
            $role = \Marvel\Database\Models\Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'api'],
                ['display_name' => ['en' => 'Super Admin', 'ar' => 'ادمن']]
            );
            $this->admin->assignRole($role);
        }
        if (Schema::hasTable('permissions')) {
            $general = \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => 'update-order-status', 'guard_name' => 'api']
            );
            $this->admin->givePermissionTo($general);
            // Production parity: general holders inherit granular targets.
            (new \Database\Seeders\OrderStatusPermissionSeeder)->run();
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function pendingOrder(): Order
    {
        // The model creation backstop assigns the local flow; the status
        // mirror is arranged for the pending start explicitly. A fresh
        // customer per order: the pending-reuse unique index allows only
        // one pending order per user.
        $customer = User::create([
            'name' => 'Adapter Customer',
            'email' => 'adapter-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'Adapter Order',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);
    }

    private function legacyUpdateRequest(Order $order, string $legacyCode): Request
    {
        $request = Request::create(
            "/api/v1/orders/{$order->id}",
            'PUT',
            ['id' => $order->id, 'order_status' => $legacyCode]
        );
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }

    /** @test */
    public function mapped_legacy_code_routes_through_canonical_writer(): void
    {
        $order = $this->pendingOrder();

        $result = app(\Marvel\Database\Repositories\OrderRepository::class)
            ->updateOrder($this->legacyUpdateRequest($order, LegacyOrderStatus::PROCESSING));

        $this->assertNotFalse($result);

        $fresh = $order->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertSame($this->statusId('processing'), (int) $fresh->current_status_id);

        // D4: the legacy column is re-synced by the canonical writer.
        if (Schema::hasColumn('orders', 'order_status')) {
            $this->assertSame('order-processing', $fresh->order_status);
        }
    }

    /** @test */
    public function legacy_only_codes_are_frozen_with_explicit_reason(): void
    {
        $frozen = [
            LegacyOrderStatus::REFUNDED,
            LegacyOrderStatus::FAILED,
            LegacyOrderStatus::AT_LOCAL_FACILITY,
            'order-xyz-bogus',
        ];

        foreach ($frozen as $code) {
            $order = $this->pendingOrder();

            try {
                app(\Marvel\Database\Repositories\OrderRepository::class)
                    ->updateOrder($this->legacyUpdateRequest($order, $code));
                $this->fail("Legacy code [{$code}] must be frozen (decision D5).");
            } catch (\Marvel\Exceptions\MarvelBadRequestException $e) {
                $this->assertStringContainsString($code, $e->getMessage());
            }

            $this->assertSame('pending', $order->fresh()->status);
        }
    }

    /** @test */
    public function flow_invalid_mapped_code_is_rejected_by_canonical_guard(): void
    {
        $order = $this->pendingOrder();

        // out_for_delivery is a mapped legacy code but NOT a valid
        // pending exit (linear successor is processing; supervised exits
        // are completed/cancelled) — the canonical guard must reject it.
        try {
            app(\Marvel\Database\Repositories\OrderRepository::class)
                ->updateOrder($this->legacyUpdateRequest($order, LegacyOrderStatus::OUT_FOR_DELIVERY));
            $this->fail('Flow-invalid mapped code must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('out_for_delivery', $e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function granular_target_permission_is_enforced(): void
    {
        $order = $this->pendingOrder();

        $stranger = User::create([
            'name' => 'Adapter Stranger',
            'email' => 'adapter-stranger-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $request = Request::create(
            "/api/v1/orders/{$order->id}",
            'PUT',
            ['id' => $order->id, 'order_status' => LegacyOrderStatus::PROCESSING]
        );
        $request->setUserResolver(fn () => $stranger);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            app(\Marvel\Database\Repositories\OrderRepository::class)->updateOrder($request);
            $this->fail('Missing granular permission must be rejected.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
    }
}
