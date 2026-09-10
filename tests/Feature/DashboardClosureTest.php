<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DigitalEntitlement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FINAL CLOSURE TESTS â€” dashboard analytics correctness gates:
 *  1. processing status counted (no hardcoded zeros)
 *  2. legacy status keys preserved
 *  3. unknown/future statuses flow through
 *  4. digital products excluded from physical inventory metrics
 *  5. digital analytics block (entitlements/downloads/licenses, no secrets)
 *  6. multi-currency revenue never mixed across currencies
 *  7. scheduler consistency incl. payments:reconcile
 */
class DashboardClosureTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function actingAsSuperAdmin(): User
    {
        $perm = Permission::findOrCreate('view-analytics', self::GUARD);
        Permission::findOrCreate('super-admin', self::GUARD);

        $role = Role::create([
            'name' => 'superadmin',
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'Ù…Ø¯ÙŠØ±']),
        ]);
        $role->givePermissionTo([$perm->name, 'super-admin']);

        $user = User::create([
            'name' => 'Closure Admin',
            'email' => 'closure-' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '+1-555-0199',
        ]);
        $user->assignRole($role);
        $user->givePermissionTo([$perm->name, 'super-admin']);

        Sanctum::actingAs($user);

        return $user;
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Product ' . Str::random(6)],
            'slug' => 'p-' . Str::random(8),
            'price' => 100.00,
            'quantity' => 50,
            'stock_quantity' => 50,
            'sold_quantity' => 10,
            'in_stock' => true,
            'status' => true,
            'product_type' => ProductType::SIMPLE,
        ], $overrides));
    }

    private function makeOrder(array $overrides = []): Order
    {
        $customer = User::factory()->create(['type' => 'user']);

        return Order::create(array_merge([
            'user_id' => $customer->id,
            'user_email' => $customer->email,
            'name' => 'Customer',
            'user_phone' => '+1-555-0000',
            'total_price' => 200.00,
            'price' => 180.00,
            'shipping_price' => 15.00,
            'fast_shipping_fee' => 5.00,
            'status' => 'completed',
            'shipping_method' => 'SCHEDULED',
            'address' => json_encode(['street' => '1 Main St']),
        ], $overrides));
    }

    private function makeOrderProduct(Order $order, Product $product, int $qty = 1, float $price = null)
    {
        return DB::table('order_products')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => is_array($product->name) ? ($product->name['en'] ?? 'P') : (string) $product->name,
            'product_quantity' => $qty,
            'product_price' => $price ?? (float) $product->price,
            'product_total_price' => ($price ?? (float) $product->price) * $qty,
            'item_type' => $product->item_type ?? 'PHYSICAL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAsset(Product $product): int
    {
        return DB::table('digital_assets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'type' => 'FILE',
            'disk' => 'private',
            'path' => 'digital/test.bin',
            'original_name' => 'test.bin',
            'mime' => 'application/octet-stream',
            'size' => 128,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEntitlement(Order $order, int $orderProductId, User $user, array $overrides = []): DigitalEntitlement
    {
        return DigitalEntitlement::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'order_product_id' => $orderProductId,
            'user_id' => $user->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
            'download_limit' => 5,
            'download_count' => 0,
        ], $overrides));
    }

    private function fetch(string $endpoint): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::PREFIX . '/dashboard/' . $endpoint);
    }

    private function dataOf(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertOk();
        $json = $response->json();

        return $json['data'] ?? [];
    }

    // =====================================================================
    // 1. ORDER STATUS ANALYTICS
    // =====================================================================

    public function test_processing_orders_are_counted_not_hardcoded_zero(): void
    {
        $this->actingAsSuperAdmin();

        $this->makeOrder(['status' => 'processing']);

        foreach (['today', 'weekly', 'monthly', 'yearly'] as $bucket) {
            $data = $this->dataOf($this->fetch('order-stats'));
            $this->assertSame(
                1,
                $data[$bucket]['processing'],
                "processing must be derived from real data in {$bucket} bucket"
            );
        }
    }

    public function test_existing_statuses_remain_correct_and_no_double_counting(): void
    {
        $this->actingAsSuperAdmin();

        $completed = $this->makeOrder(['status' => 'completed']);
        $cancelled = $this->makeOrder(['status' => 'cancelled']);
        $pending = $this->makeOrder(['status' => 'pending']);
        $delivered = $this->makeOrder(['status' => 'delivered']);

        $data = $this->dataOf($this->fetch('order-stats'));

        $this->assertSame(1, $data['yearly']['completed']);
        $this->assertSame(1, $data['yearly']['cancelled']);
        $this->assertSame(1, $data['yearly']['pending']);
        $this->assertSame(1, $data['yearly']['delivered']);

        $sumKnown = $data['yearly']['completed']
            + $data['yearly']['cancelled']
            + $data['yearly']['pending']
            + $data['yearly']['delivered']
            + $data['yearly']['processing'];

        $dbCount = Order::whereIn('status', ['completed', 'cancelled', 'pending', 'delivered', 'processing'])->count();
        $this->assertSame($dbCount, $sumKnown, 'Status buckets must not double count.');
    }

    public function test_legacy_status_keys_are_preserved_for_clients(): void
    {
        $this->actingAsSuperAdmin();

        $data = $this->dataOf($this->fetch('order-stats'));

        foreach (['refunded', 'failed', 'local_facility', 'out_for_delivery'] as $legacyKey) {
            $this->assertArrayHasKey($legacyKey, $data['today'], "Legacy key {$legacyKey} lost.");
        }
    }

    public function test_zero_buckets_and_bucket_nesting_stay_consistent(): void
    {
        $this->actingAsSuperAdmin();

        // Only an old order exists => today/weekly/monthly must be all-zero
        // while yearly reflects it; no bucket may error or invent counts.
        $old = $this->makeOrder(['status' => 'completed']);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subDays(200)]);

        $data = $this->dataOf($this->fetch('order-stats'));

        foreach (['pending', 'processing', 'cancelled', 'delivered'] as $status) {
            $this->assertSame(0, $data['today'][$status]);
            $this->assertSame(0, $data['weekly'][$status]);
            $this->assertSame(0, $data['monthly'][$status]);
        }

        $this->assertSame(1, $data['yearly']['completed'] + $data['monthly']['completed']);

        // Bucket nesting: anything counted today must also be counted in
        // every wider window.
        $this->makeOrder(['status' => 'processing']);
        $data = $this->dataOf($this->fetch('order-stats'));
        $this->assertSame($data['today']['processing'], $data['weekly']['processing']);
        $this->assertSame($data['today']['processing'], $data['monthly']['processing']);
    }

    public function test_order_stats_response_contract_is_backward_compatible(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->fetch('order-stats');
        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'today' => ['pending', 'processing', 'completed', 'cancelled'],
                'weekly',
                'monthly',
                'yearly',
            ],
        ]);
    }

    // =====================================================================
    // 2/3. DIGITAL vs PHYSICAL PRODUCT ANALYTICS
    // =====================================================================

    public function test_digital_products_excluded_from_physical_inventory_value(): void
    {
        $this->actingAsSuperAdmin();

        // Physical: price 100 x stock 10 = 1000 inventory value.
        $this->makeProduct(['price' => 100, 'stock_quantity' => 10]);
        // Digital: huge price/stock that would poison a mixed sum.
        $this->makeProduct([
            'item_type' => 'DIGITAL',
            'price' => 99999,
            'stock_quantity' => 9999,
        ]);

        $data = $this->dataOf($this->fetch('products'));

        $this->assertSame(1000.0, (float) $data['inventory_value'], 'inventory_value must count PHYSICAL stock only.');
    }

    public function test_out_of_stock_and_low_stock_exclude_digital(): void
    {
        $this->actingAsSuperAdmin();

        $physicalOOS = $this->makeProduct(['stock_quantity' => 0, 'quantity' => 0]);
        $this->makeProduct(['item_type' => 'DIGITAL', 'stock_quantity' => 0, 'quantity' => 0]);

        $productsData = $this->dataOf($this->fetch('products'));
        $oosIds = collect($productsData['out_of_stock'])->pluck('id')->all();
        $this->assertContains($physicalOOS->id, $oosIds);
        $this->assertCount(1, $oosIds, 'out_of_stock must be PHYSICAL-only.');

        $lowStockIds = collect($this->dataOf($this->fetch('low-stock')))->pluck('id')->all();
        $this->assertContains($physicalOOS->id, $lowStockIds);
        $this->assertCount(1, $lowStockIds, 'low-stock must be PHYSICAL-only.');
    }

    public function test_digital_block_reports_products_entitlements_downloads_licenses(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $digital = $this->makeProduct(['item_type' => 'DIGITAL', 'sold_quantity' => 7]);
        $assetId = $this->makeAsset($digital);

        $order = $this->makeOrder(['status' => 'completed']);
        $opId = $this->makeOrderProduct($order, $digital);

        $active = $this->makeEntitlement($order, $opId, $admin, ['download_count' => 3]);
        // Revoked sibling on its own order product row.
        $op2 = $this->makeOrderProduct($order, $digital);
        $this->makeEntitlement($order, $op2, $admin, [
            'status' => DigitalEntitlement::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        DB::table('digital_download_logs')->insert([
            'entitlement_id' => $active->id,
            'asset_id' => $assetId,
            'downloaded_at' => now(),
        ]);

        DB::table('digital_license_keys')->insert([
            ['uuid' => (string) Str::uuid(), 'asset_id' => $assetId, 'encrypted_key' => 'SECRET-XYZ', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'asset_id' => $assetId, 'encrypted_key' => 'SECRET-ABC', 'status' => 'consumed', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $digitalBlock = $this->dataOf($this->fetch('products'))['digital'];

        $this->assertSame(1, $digitalBlock['digital_products']);
        $this->assertSame(7, $digitalBlock['digital_units_sold']);
        $this->assertSame(1, $digitalBlock['entitlements']['active']);
        $this->assertSame(1, $digitalBlock['entitlements']['revoked']);
        $this->assertSame(1, $digitalBlock['downloads']['total']);
        $this->assertSame(1, $digitalBlock['downloads']['last_30_days']);
        $this->assertSame(1, $digitalBlock['licenses']['available'] ?? 0);
        $this->assertSame(1, $digitalBlock['licenses']['consumed'] ?? 0);
    }

    public function test_dashboard_payload_never_contains_license_secrets(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $digital = $this->makeProduct(['item_type' => 'DIGITAL']);
        $assetId = $this->makeAsset($digital);
        $order = $this->makeOrder(['status' => 'completed']);
        $opId = $this->makeOrderProduct($order, $digital);
        $this->makeEntitlement($order, $opId, $admin);

        DB::table('digital_license_keys')->insert([
            'uuid' => (string) Str::uuid(),
            'asset_id' => $assetId,
            'encrypted_key' => 'TOPSECRET-DO-NOT-LEAK',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->fetch('products');

        $this->assertStringNotContainsString(
            'TOPSECRET-DO-NOT-LEAK',
            $response->getContent(),
            'License secrets must never reach the dashboard payload.'
        );
        $this->assertStringNotContainsString('encrypted_key', $response->getContent());
    }

    // =====================================================================
    // 5. MULTI-CURRENCY ANALYTICS
    // =====================================================================

    public function test_multi_currency_revenue_is_never_raw_mixed(): void
    {
        $this->actingAsSuperAdmin();

        // Legacy/base order: EGP 1000 (converted NULL -> already base).
        $this->makeOrder([
            'total_price' => 1000,
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
        ]);

        // USD order: total 100 at rate 50 => 5000 base.
        $this->makeOrder([
            'total_price' => 100,
            'currency_code' => 'USD',
            'base_currency_code' => 'EGP',
            'currency_rate' => 50,
            'converted_total_price' => 5000,
        ]);

        $finance = $this->dataOf($this->fetch('finance'));

        // Raw mixing would yield 1100; base-correct is 6000.
        $this->assertSame(6000.0, (float) $finance['gross_revenue'], 'gross_revenue must use converted base amounts.');

        $byCurrency = $finance['gross_by_currency'];
        $this->assertSame(1000.0, (float) $byCurrency['EGP']);
        $this->assertSame(100.0, (float) $byCurrency['USD']);

        $revenue = $this->dataOf($this->fetch('revenue'));
        $this->assertSame(6000.0, (float) $revenue['total_revenue']);
        $this->assertSame(100.0, (float) $revenue['revenue_by_currency']['USD']);
    }

    public function test_sales_daily_revenue_respects_conversion(): void
    {
        $this->actingAsSuperAdmin();

        $this->makeOrder(['total_price' => 200]); // legacy base

        $sales = $this->dataOf($this->fetch('sales'));
        $this->assertSame(200.0, (float) $sales['daily_revenue']['today']);
    }

    // =====================================================================
    // 9. RECONCILIATION CONTRACT
    // =====================================================================

    public function test_reconciliation_endpoint_contract(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->fetch('reconciliation');
        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => ['total_checked', 'total_mismatches', 'pending_mismatches', 'resolved_mismatches'],
        ]);

        $data = $response->json('data');
        $this->assertSame(0, $data['total_checked']);
        $this->assertNull($data['last_run'] ?? null);
    }

    // =====================================================================
    // 7. SCHEDULER CONSISTENCY
    // =====================================================================

    public function test_scheduler_registers_all_expected_jobs(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => ltrim(str_replace(["php", "artisan"], '', $event->command ?? ''), ' '))
            ->all();

        $expectations = [
            'orders:cancel-unpaid',
            'carts:expire',
            'cart:notify-abandoned',
            'promotions:notify-ending-soon',
            'flash-sales:notify-ending-soon',
            'products:purge-old-deleted',
            'payments:reconcile',
        ];

        foreach ($expectations as $needle) {
            $match = collect($schedule->events())->first(
                fn ($e) => str_contains($e->command ?? '', $needle)
            );
            $this->assertNotNull($match, "Scheduler is missing [{$needle}].");
        }

        $reconcile = collect($schedule->events())->first(fn ($e) => str_contains($e->command ?? '', 'payments:reconcile'));
        $this->assertTrue($reconcile->withoutOverlapping, 'payments:reconcile must not overlap.');
        $this->assertSame('0 * * * *', $reconcile->expression, 'payments:reconcile must run hourly.');
    }
}
