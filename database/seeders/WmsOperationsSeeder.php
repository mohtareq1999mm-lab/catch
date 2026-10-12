<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;

class WmsOperationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('================================================================');
        $this->command?->info('  Starting WMS Fulfillment & Inventory Operations Seeder');
        $this->command?->info('================================================================');

        // 1. Seed & Ensure all WMS Permissions
        $wmsPermissions = [
            'view-warehouse',
            'manage-warehouse',
            'view-location',
            'manage-location',
            'view-fulfillment',
            'manage-fulfillment',
            'fulfillment.create',
            'fulfillment.cancel',
            'picking-execute',
            'picking.claim',
            'picking.complete',
            'packing-execute',
            'packing.complete',
            'batch.manage',
            'batch.operate',
            'fulfillment-override',
            'inventory-adjust',
            'order.cancel-during-fulfillment',
            'view-shipment',
            'create-shipment',
            'update-shipment',
            'view-orders',
            'view-order',
            'update-order-status',
        ];

        foreach ($wmsPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // 2. Ensure Warehouse Roles
        $managerRole = Role::firstOrCreate(
            ['name' => 'warehouse-manager', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Manager', 'ar' => 'مدير مستودع']]
        );
        $managerRole->syncPermissions($wmsPermissions);

        $pickerRole = Role::firstOrCreate(
            ['name' => 'picker', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Picker', 'ar' => 'عامل التقاط']]
        );
        $pickerRole->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment', 'picking-execute', 'picking.claim'
        ]);

        $superAdminRole = Role::where('name', 'super_admin')->first();
        if ($superAdminRole) {
            $superAdminRole->givePermissionTo($wmsPermissions);
        }

        // 3. Create or Update Default Warehouse
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Distribution Hub',
                'status' => 'active',
                'is_default' => true,
                'address' => '123 Logistics Way',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'metadata' => ['capacity' => 20000, 'contact' => '+201000000000'],
            ]
        );
        $warehouse->update(['is_default' => true, 'status' => 'active']);

        // 4. Create Standard Placeable Locations
        $locationsSetup = [
            [
                'code' => 'REC-01',
                'barcode' => 'LOC-REC-01',
                'name' => 'Receiving Dock 01',
                'type' => Location::TYPE_RECEIVING,
                'priority' => 20,
            ],
            [
                'code' => 'A-01-01',
                'barcode' => 'LOC-A-01-01',
                'name' => 'Aisle A - Rack 01 - Shelf 01',
                'type' => Location::TYPE_STORAGE,
                'priority' => 10,
            ],
            [
                'code' => 'A-01-02',
                'barcode' => 'LOC-A-01-02',
                'name' => 'Aisle A - Rack 01 - Shelf 02',
                'type' => Location::TYPE_STORAGE,
                'priority' => 9,
            ],
            [
                'code' => 'PICK-01',
                'barcode' => 'LOC-PICK-01',
                'name' => 'Fast Picking Bay 01',
                'type' => Location::TYPE_PICKING,
                'priority' => 15,
            ],
            [
                'code' => 'PACK-01',
                'barcode' => 'LOC-PACK-01',
                'name' => 'Packing Staging 01',
                'type' => Location::TYPE_PACKING,
                'priority' => 5,
            ],
        ];

        $locations = [];
        foreach ($locationsSetup as $locData) {
            $locations[$locData['code']] = Location::firstOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'code' => $locData['code'],
                ],
                [
                    'barcode' => $locData['barcode'],
                    'name' => $locData['name'],
                    'type' => $locData['type'],
                    'status' => Location::STATUS_ACTIVE,
                    'priority' => $locData['priority'],
                    'metadata' => ['dimensions' => '2m x 1m x 1m'],
                ]
            );
        }

        // 5. Packing Station
        PackingStation::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'code' => 'PACK-STATION-01'],
            ['name' => 'Main Packing Station 1', 'status' => 'active']
        );

        // 6. Setup Admin Users & assign to Warehouse
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
            'warehouse_id' => $warehouse->id,
        ]);
        $admin->givePermissionTo($wmsPermissions);
        $adminToken = $admin->createToken('AdminWmsToken')->plainTextToken;

        // Demo admin
        $demoAdmin = User::where('email', 'admin@demo.com')->first();
        if ($demoAdmin) {
            $demoAdmin->update(['warehouse_id' => $warehouse->id]);
            $demoAdmin->givePermissionTo($wmsPermissions);
        }

        // Dedicated Picker User
        $picker = User::firstOrCreate(
            ['email' => 'picker@wms.com'],
            [
                'name' => 'Ahmed WMS Picker',
                'password' => Hash::make('password123'),
                'type' => 'staff',
                'is_active' => true,
            ]
        );
        $picker->update(['warehouse_id' => $warehouse->id]);
        $picker->assignRole('picker');

        // Customer for orders
        $customer = User::firstOrCreate(
            ['email' => 'wms.customer@example.com'],
            [
                'name' => 'WMS Test Customer',
                'password' => Hash::make('password123'),
                'type' => 'customer',
                'is_active' => true,
                'phone_number' => '+201011112222',
            ]
        );

        // 7. Physical Simple Products for WMS
        $productAlpha = Product::firstOrCreate(
            ['sku' => 'WMS-ALPHA-101'],
            [
                'name' => ['en' => 'WMS Premium Headset Alpha', 'ar' => 'سماعة ميم بريميوم ألفا'],
                'slug' => 'wms-premium-headset-alpha',
                'description' => ['en' => 'High-fidelity audio headset for warehouse testing', 'ar' => 'سماعة عالية الدقة لتجارب المستودع'],
                'price' => 150.00,
                'stock_quantity' => 100,
                'quantity' => 100,
                'product_type' => ProductType::SIMPLE,
                'item_type' => 'PHYSICAL',
                'status' => 1,
                'in_stock' => 1,
                'has_discount' => 0,
                'has_flash_sale' => 0,
            ]
        );

        $productBeta = Product::firstOrCreate(
            ['sku' => 'WMS-BETA-102'],
            [
                'name' => ['en' => 'WMS Wireless Mouse Beta', 'ar' => 'ماوس لاسلكي بيتا'],
                'slug' => 'wms-wireless-mouse-beta',
                'description' => ['en' => 'Ergonomic wireless mouse for warehouse testing', 'ar' => 'ماوس لاسلكي مريح لتجارب المستودع'],
                'price' => 75.00,
                'stock_quantity' => 60,
                'quantity' => 60,
                'product_type' => ProductType::SIMPLE,
                'item_type' => 'PHYSICAL',
                'status' => 1,
                'in_stock' => 1,
                'has_discount' => 0,
                'has_flash_sale' => 0,
            ]
        );

        // 8. Initial Stock Placements in Locations
        // Placement 1: Product Alpha in Storage A-01-01
        $placementAlphaStorage = ProductLocation::firstOrCreate(
            [
                'product_id' => $productAlpha->id,
                'location_id' => $locations['A-01-01']->id,
            ],
            [
                'warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'allocated_hint' => 0,
            ]
        );

        // Placement 2: Product Alpha in Fast Pick Bay PICK-01
        $placementAlphaPick = ProductLocation::firstOrCreate(
            [
                'product_id' => $productAlpha->id,
                'location_id' => $locations['PICK-01']->id,
            ],
            [
                'warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'allocated_hint' => 0,
            ]
        );

        // Placement 3: Product Beta in Storage A-01-02
        $placementBetaStorage = ProductLocation::firstOrCreate(
            [
                'product_id' => $productBeta->id,
                'location_id' => $locations['A-01-02']->id,
            ],
            [
                'warehouse_id' => $warehouse->id,
                'quantity' => 60,
                'allocated_hint' => 0,
            ]
        );

        // 9. Orders and Fulfillments Setup
        $flow = OrderFlow::first();
        $processingStatus = OrderStatus::where('code', 'processing')->first();

        // ── Order 1: Ready for POST admin/fulfillments/release ──────────
        $orderRelease = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-RELEASE-001'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'fulfillment_status' => 'pending',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 300.00,
                'total_price' => 300.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '10 Nile Corniche', 'city' => 'Cairo', 'country' => 'Egypt'],
            ]
        );

        OrderProduct::firstOrCreate(
            ['order_id' => $orderRelease->id, 'product_id' => $productAlpha->id],
            [
                'product_name' => 'WMS Premium Headset Alpha',
                'product_sku' => $productAlpha->sku,
                'product_quantity' => 2,
                'product_price' => 150.00,
                'product_total_price' => 300.00,
            ]
        );

        // ── Order 2: Fulfillment in Pending status (Ready for Assign, Create-Tasks, Cancel) ──
        $orderFulfillmentPending = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-FUL-PENDING-002'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'fulfillment_status' => 'pending',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 150.00,
                'total_price' => 150.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '20 Tahrir St', 'city' => 'Cairo', 'country' => 'Egypt'],
            ]
        );

        $orderProduct2 = OrderProduct::firstOrCreate(
            ['order_id' => $orderFulfillmentPending->id, 'product_id' => $productAlpha->id],
            [
                'product_name' => 'WMS Premium Headset Alpha',
                'product_sku' => $productAlpha->sku,
                'product_quantity' => 1,
                'product_price' => 150.00,
                'product_total_price' => 150.00,
            ]
        );

        $fulfillmentPending = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-PENDING-002'],
            [
                'order_id' => $orderFulfillmentPending->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'status' => 'pending',
                'priority' => 0,
            ]
        );

        $fulfillmentItemPending = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulfillmentPending->id, 'order_item_id' => $orderProduct2->id],
            [
                'product_id' => $productAlpha->id,
                'quantity' => 1.00,
                'quantity_picked' => 0.00,
                'status' => 'pending',
                'product_location_id' => $placementAlphaPick->id,
            ]
        );

        // ── Order 3: Fulfillment in Picking status with active Picking Task ──────────
        $orderFulfillmentPicking = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-FUL-PICKING-003'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'fulfillment_status' => 'processing',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 75.00,
                'total_price' => 75.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '50 Abbasia Sq', 'city' => 'Cairo', 'country' => 'Egypt'],
            ]
        );

        $orderProduct3 = OrderProduct::firstOrCreate(
            ['order_id' => $orderFulfillmentPicking->id, 'product_id' => $productBeta->id],
            [
                'product_name' => 'WMS Wireless Mouse Beta',
                'product_sku' => $productBeta->sku,
                'product_quantity' => 1,
                'product_price' => 75.00,
                'product_total_price' => 75.00,
            ]
        );

        $fulfillmentPicking = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-PICKING-003'],
            [
                'order_id' => $orderFulfillmentPicking->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'status' => 'picking',
                'priority' => 1,
                'assigned_to' => $picker->id,
                'picking_started_at' => now(),
            ]
        );

        $fulfillmentItemPicking = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulfillmentPicking->id, 'order_item_id' => $orderProduct3->id],
            [
                'product_id' => $productBeta->id,
                'quantity' => 1.00,
                'quantity_picked' => 0.00,
                'status' => 'picking',
                'product_location_id' => $placementBetaStorage->id,
            ]
        );

        $pickingTask = PickingTask::firstOrCreate(
            [
                'fulfillment_item_id' => $fulfillmentItemPicking->id,
                'product_location_id' => $placementBetaStorage->id,
            ],
            [
                'status' => 'pending',
                'quantity_to_pick' => 1.00,
                'quantity_picked' => 0.00,
                'sequence' => 1,
            ]
        );

        $this->command?->info('');
        $this->command?->info('  [OK] WMS Seeding Completed Successfully!');
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Admin Credentials:        admin@gmail.com / password123");
        $this->command?->info("  Admin Bearer Token:       {$adminToken}");
        $this->command?->info("  Warehouse ID:             {$warehouse->id} ({$warehouse->code})");
        $this->command?->info("  Receiving Location ID:    {$locations['REC-01']->id} ({$locations['REC-01']->code})");
        $this->command?->info("  Storage Location A ID:    {$locations['A-01-01']->id} ({$locations['A-01-01']->code})");
        $this->command?->info("  Storage Location B ID:    {$locations['A-01-02']->id} ({$locations['A-01-02']->code})");
        $this->command?->info("  Fast Pick Location ID:    {$locations['PICK-01']->id} ({$locations['PICK-01']->code})");
        $this->command?->info("  Product Alpha ID:         {$productAlpha->id} ({$productAlpha->sku})");
        $this->command?->info("  Product Beta ID:          {$productBeta->id} ({$productBeta->sku})");
        $this->command?->info("  Order to Release:         ID: {$orderRelease->id} (Order Number: {$orderRelease->order_number})");
        $this->command?->info("  Fulfillment Pending ID:   {$fulfillmentPending->id} (Item: {$fulfillmentItemPending->id})");
        $this->command?->info("  Fulfillment Picking ID:   {$fulfillmentPicking->id} (Item: {$fulfillmentItemPicking->id}, Task: {$pickingTask->id})");
        $this->command?->info('================================================================');
    }
}
