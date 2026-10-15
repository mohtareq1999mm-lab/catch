<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;

class AdminOrderOperationsSeeder extends Seeder
{
    public function run(): void
    {
        // Fail-closed: this seeder resets admin@gmail.com to a public known
        // password, mints bearer tokens and creates ORD-TEST-* orders. It
        // must never run against production data (same contract as
        // WmsOperationsSeeder, which runs after it in DatabaseSeeder).
        abort_if(app()->isProduction(), 403, 'AdminOrderOperationsSeeder is blocked in production.');

        $this->command?->info('================================================================');
        $this->command?->info('  Starting Admin Order Operations & Flows Seeder');
        $this->command?->info('================================================================');

        // 1. Ensure OrderFlow and FlowInput base seeders have run
        (new OrderFlowSeeder())->run();
        (new FlowInputSeeder())->run();

        // 2. Ensure Permissions exist and assign to Admin
        $permissions = [
            // Shipment
            'view-shipment',
            'view-shipments',
            'create-shipment',
            'update-shipment',
            // Orders
            'view-orders',
            'view-order',
            'update-order-status',
            // WMS & Cancellation
            'order.cancel-during-fulfillment',
            'manage-warehouse',
            'view-warehouse',
            'view-location',
            'manage-location',
            'view-fulfillment',
            'manage-fulfillment',
            // Order Flows & Status Catalog
            'view-order-flows',
            'create-order-flows',
            'update-order-flows',
            'manage-order-flow-inputs',
            // Dashboard & Analytics
            'view-analytics',
            'export-analytics',
        ];

        foreach ($permissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'api']);
        }

        // 3. Create or update Admin User: admin@gmail.com
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password123'),
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        $admin->update([
            'password' => Hash::make('password123'),
            'type' => 'admin',
            'is_active' => true,
        ]);

        // Also update admin@demo.com if it exists
        $demoAdmin = User::where('email', 'admin@demo.com')->first();
        if ($demoAdmin) {
            $demoAdmin->givePermissionTo($permissions);
        }

        $admin->givePermissionTo($permissions);

        // Also assign all change-order-status.* permissions to admin
        $statusPerms = Permission::where('name', 'like', 'change-order-status.%')->pluck('name')->all();
        if (!empty($statusPerms)) {
            $admin->givePermissionTo($statusPerms);
        }

        // Generate Sanctum Plaintext Token
        $adminToken = $admin->createToken('AdminOrderOperationsToken')->plainTextToken;

        // 4. Warehouse & Locations
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Distribution Center',
                'status' => 'active',
                'is_default' => true,
                'address' => '123 Logistics Boulevard',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'metadata' => ['capacity' => 10000],
            ]
        );

        // 5. Test Customer
        $customer = User::firstOrCreate(
            ['email' => 'customer.test@example.com'],
            [
                'name' => 'Test Customer',
                'password' => Hash::make('password123'),
                'type' => 'customer',
                'is_active' => true,
            ]
        );

        // Resolve Flow and Status IDs
        $localFlow = OrderFlow::where('code', 'local')->first() ?? OrderFlow::first();
        $processingStatus = OrderStatus::where('code', 'processing')->first();
        $pendingStatus = OrderStatus::where('code', 'pending')->first();

        // 6. Test Orders Setup

        // Order 1: Ready for GET admin/orders/{orderId}/shipment
        $orderShipmentView = Order::updateOrCreate(
            ['order_number' => 'ORD-TEST-SHIP-VIEW-01'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => '+201000000001',
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'fulfillment_status' => 'processing',
                'shipment_status' => Order::SHIPMENT_STATUS_IN_TRANSIT,
                'tracking_number' => 'TRK-DHL-9928172',
                'courier_name' => 'DHL Express',
                'estimated_delivery_at' => now()->addDays(3),
                'actual_delivery_at' => null,
                'flow_id' => $localFlow?->id,
                'current_status_id' => $processingStatus?->id,
                'shipping_type' => 'local',
                'shipping_method' => 'Express Courier',
                'price' => 450.00,
                'shipping_price' => 50.00,
                'total_price' => 500.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => [
                    'street_address' => '15 Al-Tahrir Square',
                    'city' => 'Cairo',
                    'country' => 'Egypt',
                ],
                'notes' => 'Handle with care - fragile items',
            ]
        );

        // Order 2: Ready for POST admin/orders/{orderId}/shipment/update-status
        $orderShipmentUpdate = Order::updateOrCreate(
            ['order_number' => 'ORD-TEST-SHIP-UPDATE-02'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => '+201000000002',
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'fulfillment_status' => 'pending',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'tracking_number' => null,
                'courier_name' => null,
                'estimated_delivery_at' => null,
                'actual_delivery_at' => null,
                'flow_id' => $localFlow?->id,
                'current_status_id' => $processingStatus?->id,
                'shipping_type' => 'local',
                'shipping_method' => 'Standard Shipping',
                'price' => 200.00,
                'shipping_price' => 30.00,
                'total_price' => 230.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => [
                    'street_address' => '22 Corniche El Nil',
                    'city' => 'Alexandria',
                    'country' => 'Egypt',
                ],
                'notes' => 'Ready for shipment status updates',
            ]
        );

        // Order 3: Ready for POST admin/orders/{orderId}/cancel (WMS fulfillment cancellation)
        $orderCancel = Order::updateOrCreate(
            ['order_number' => 'ORD-TEST-WMS-CANCEL-03'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => '+201000000003',
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_PENDING,
                'fulfillment_status' => 'pending',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'flow_id' => $localFlow?->id,
                'current_status_id' => $processingStatus?->id,
                'shipping_type' => 'local',
                'shipping_method' => 'Standard Courier',
                'price' => 350.00,
                'shipping_price' => 25.00,
                'total_price' => 375.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => [
                    'street_address' => '45 Al-Galaa St',
                    'city' => 'Giza',
                    'country' => 'Egypt',
                ],
                'notes' => 'Target order for admin during-fulfillment cancellation',
            ]
        );

        // Create an open Fulfillment row for Order 3
        $fulfillment = Fulfillment::updateOrCreate(
            ['fulfillment_number' => 'FUL-TEST-CANCEL-003'],
            [
                'order_id' => $orderCancel->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'status' => 'pending',
                'priority' => 'normal',
                'notes' => 'Seeded fulfillment waiting to be cancelled with order',
            ]
        );

        // Retrieve existing IDs for testing
        $internationalFlow = OrderFlow::where('code', 'international')->first();
        $sampleFlowInput = FlowInput::first();
        $sampleStatus = OrderStatus::first();

        $this->command?->info('');
        $this->command?->info('  [OK] Admin Order Operations Seeding Completed Successfully!');
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Admin Email:        admin@gmail.com");
        $this->command?->info("  Admin Password:     password123");
        $this->command?->info("  Admin Bearer Token: {$adminToken}");
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Order for Shipment SHOW:   ID: {$orderShipmentView->id} (Order Number: {$orderShipmentView->order_number})");
        $this->command?->info("  Order for Shipment UPDATE: ID: {$orderShipmentUpdate->id} (Order Number: {$orderShipmentUpdate->order_number})");
        $this->command?->info("  Order for WMS CANCEL:      ID: {$orderCancel->id} (Order Number: {$orderCancel->order_number}, Fulfillment: {$fulfillment->id})");
        $this->command?->info("  Sample Order Status ID:    {$sampleStatus?->id} ({$sampleStatus?->code})");
        $this->command?->info("  Sample Order Flow ID:      Local: {$localFlow?->id}, International: {$internationalFlow?->id}");
        $this->command?->info("  Sample Flow Input ID:      {$sampleFlowInput?->id} ({$sampleFlowInput?->key})");
        $this->command?->info('================================================================');
    }
}
