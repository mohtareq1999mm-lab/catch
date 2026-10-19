<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackageItem;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use App\Models\Shipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;

class WarehouseDashboardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('================================================================');
        $this->command?->info('  Seeding Warehouse Dashboard & WMS Complete Dummy Dataset');
        $this->command?->info('================================================================');

        // -------------------------------------------------------------
        // 1. Roles & Permissions Baseline Setup
        // -------------------------------------------------------------
        $wmsPermissions = [
            MarvelPermission::WAREHOUSES_INDEX,
            MarvelPermission::WAREHOUSES_SHOW,
            MarvelPermission::WAREHOUSES_CREATE,
            MarvelPermission::WAREHOUSES_UPDATE,
            MarvelPermission::WAREHOUSES_SET_DEFAULT,
            MarvelPermission::WAREHOUSES_ACTIVATE,
            MarvelPermission::WAREHOUSES_DEACTIVATE,
            MarvelPermission::WAREHOUSES_DELETE,
            MarvelPermission::WAREHOUSES_USERS_VIEW,
            MarvelPermission::WAREHOUSES_USERS_ASSIGN,
            MarvelPermission::WAREHOUSES_USERS_REMOVE_BULK,
            MarvelPermission::WAREHOUSES_USERS_REMOVE,
            'view-warehouse',
            'manage-warehouse',

            MarvelPermission::LOCATIONS_INDEX,
            MarvelPermission::LOCATIONS_SHOW,
            MarvelPermission::LOCATIONS_CHECK_OCCUPANCY,
            MarvelPermission::LOCATIONS_CREATE,
            MarvelPermission::LOCATIONS_UPDATE,
            MarvelPermission::LOCATIONS_ACTIVATE,
            MarvelPermission::LOCATIONS_DEACTIVATE,
            MarvelPermission::LOCATIONS_DELETE,
            MarvelPermission::LOCATIONS_EMPTY_PRODUCTS,
            'view-location',
            'manage-location',

            MarvelPermission::FULFILLMENTS_INDEX,
            MarvelPermission::FULFILLMENTS_SHOW,
            MarvelPermission::FULFILLMENTS_ASSIGN,
            MarvelPermission::FULFILLMENTS_CREATE_TASKS,
            MarvelPermission::FULFILLMENTS_COMPLETE_PICKING,
            MarvelPermission::FULFILLMENT_ITEMS_ASSIGN_PLACEMENT,
            MarvelPermission::FULFILLMENTS_CREATE_PACKING_TASKS,
            MarvelPermission::FULFILLMENTS_CREATE_PACKING_TASK,
            MarvelPermission::FULFILLMENT_CREATE,
            MarvelPermission::FULFILLMENT_CANCEL,
            'view-fulfillment',
            'manage-fulfillment',
            'fulfillment-override',

            MarvelPermission::BATCH_INDEX,
            MarvelPermission::BATCH_SHOW,
            MarvelPermission::BATCH_PENDING_FULFILLMENTS,
            MarvelPermission::BATCH_NEXT_TASK,
            MarvelPermission::BATCH_CREATE,
            MarvelPermission::BATCH_ASSIGN,
            MarvelPermission::BATCH_START,
            MarvelPermission::BATCH_REFRESH_PROGRESS,
            MarvelPermission::BATCH_COMPLETE,
            MarvelPermission::BATCH_CONFIRM_LOCATION,
            MarvelPermission::BATCH_CANCEL,
            MarvelPermission::BATCH_RETRY,

            MarvelPermission::PICKING_TASKS_INDEX,
            MarvelPermission::PICKING_TASKS_SHOW,
            MarvelPermission::PICKING_TASKS_CLAIM,
            MarvelPermission::PICKING_TASKS_RELEASE,
            MarvelPermission::PICKING_TASKS_CONFIRM,
            MarvelPermission::PICKING_TASKS_RECORD_PICK,
            MarvelPermission::PICKING_TASKS_SKIP,
            MarvelPermission::PICKING_TASKS_REALLOCATE,
            'picking-execute',
            'picking.claim',
            'picking.complete',
            'batch.manage',
            'batch.operate',

            MarvelPermission::PACKING_TASKS_INDEX,
            MarvelPermission::PACKING_TASKS_SHOW,
            MarvelPermission::PACKING_TASKS_ASSIGN,
            MarvelPermission::PACKING_TASKS_START,
            MarvelPermission::PACKING_TASKS_PACK,
            MarvelPermission::PACKING_TASKS_VERIFY,
            MarvelPermission::PACKING_TASKS_CANCEL,
            MarvelPermission::PACKING_TASKS_STATIONS,

            MarvelPermission::PACKING_STATIONS_INDEX,
            MarvelPermission::PACKING_STATIONS_SHOW,
            MarvelPermission::PACKING_STATIONS_CREATE,
            MarvelPermission::PACKING_STATIONS_UPDATE,
            MarvelPermission::PACKING_STATIONS_DELETE,

            MarvelPermission::PACKAGES_INDEX,
            MarvelPermission::PACKAGES_SHOW,
            MarvelPermission::PACKAGES_CREATE,
            MarvelPermission::PACKAGES_ADD_ITEM,
            MarvelPermission::PACKAGES_SEAL,
            MarvelPermission::PACKAGES_VOID,
            'packing-execute',
            'packing.complete',

            MarvelPermission::WMS_SHIPMENTS_INDEX,
            MarvelPermission::WMS_SHIPMENTS_SHOW,
            MarvelPermission::WMS_SHIPMENTS_CREATE,
            MarvelPermission::WMS_SHIPMENTS_BULK_CREATE,
            MarvelPermission::WMS_SHIPMENTS_BULK_DISPATCH,
            MarvelPermission::WMS_SHIPMENTS_BULK_DELIVER,
            MarvelPermission::WMS_SHIPMENTS_DISPATCH,
            MarvelPermission::WMS_SHIPMENTS_DELIVER,
            MarvelPermission::WMS_SHIPMENTS_CANCEL,
            'view-shipment',
            'view-all-shipments',

            MarvelPermission::INVENTORY_RECEIVE,
            MarvelPermission::INVENTORY_TRANSFER,
            MarvelPermission::INVENTORY_PRODUCT_PLACEMENTS,
            MarvelPermission::INVENTORY_OVERVIEW,
            MarvelPermission::INVENTORY_OPERATIONS_INDEX,
            MarvelPermission::INVENTORY_OPERATIONS_SHOW,
            'inventory-adjust',

            MarvelPermission::BARCODE_SCAN,
        ];

        foreach ($wmsPermissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'api']);
        }

        // Standard WMS Roles
        $roleSuperAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
        $roleManager = Role::firstOrCreate(['name' => 'warehouse-manager', 'guard_name' => 'api']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'warehouse-supervisor', 'guard_name' => 'api']);
        $rolePicker = Role::firstOrCreate(['name' => 'picker', 'guard_name' => 'api']);
        $rolePacker = Role::firstOrCreate(['name' => 'packer', 'guard_name' => 'api']);

        $roleManager->syncPermissions($wmsPermissions);
        $roleSupervisor->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'packing-execute', 'fulfillment-override',
            'fulfillment.create', 'fulfillment.cancel', 'picking.claim', 'picking.complete',
            'packing.complete', 'batch.manage', 'batch.operate', MarvelPermission::BARCODE_SCAN,
            MarvelPermission::WAREHOUSES_SHOW, MarvelPermission::LOCATIONS_SHOW
        ]);
        $rolePicker->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment',
            'picking-execute', 'picking.claim', MarvelPermission::BARCODE_SCAN,
            MarvelPermission::WAREHOUSES_SHOW, MarvelPermission::LOCATIONS_SHOW
        ]);
        $rolePacker->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment',
            'packing-execute', 'packing.complete', MarvelPermission::BARCODE_SCAN,
            MarvelPermission::WAREHOUSES_SHOW, MarvelPermission::LOCATIONS_SHOW
        ]);

        // -------------------------------------------------------------
        // 2. Warehouses (Main Default + Regional)
        // -------------------------------------------------------------
        $warehouseMain = Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN'],
            [
                'name' => 'Main Fulfillment Hub - Cairo',
                'address' => 'Industrial Zone 1, Logistics Park',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'active',
                'is_default' => true,
                'metadata' => [
                    'capacity' => 50000,
                    'contact' => '+20-100-000-1111',
                ],
            ]
        );
        $warehouseMain->update(['is_default' => true, 'status' => 'active']);

        $warehouseAlx = Warehouse::firstOrCreate(
            ['code' => 'WH-ALX'],
            [
                'name' => 'Alexandria Regional Depot',
                'address' => 'Borg El Arab Logistics Zone',
                'city' => 'Alexandria',
                'country' => 'Egypt',
                'status' => 'active',
                'is_default' => false,
                'metadata' => [
                    'capacity' => 15000,
                    'contact' => '+20-100-000-2222',
                ],
            ]
        );

        // -------------------------------------------------------------
        // 3. WMS Test Users & Tokens
        // -------------------------------------------------------------
        $admin = User::firstOrCreate(
            ['email' => 'wms.admin@example.com'],
            [
                'name' => 'WMS General Admin',
                'password' => Hash::make('password123'),
                'type' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles(['super_admin', 'warehouse-manager']);
        $admin->update(['warehouse_id' => $warehouseMain->id]);

        $picker = User::firstOrCreate(
            ['email' => 'wms.picker@example.com'],
            [
                'name' => 'Mostafa Picker',
                'password' => Hash::make('password123'),
                'type' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $picker->syncRoles(['picker']);
        $picker->update(['warehouse_id' => $warehouseMain->id]);

        $packer = User::firstOrCreate(
            ['email' => 'wms.packer@example.com'],
            [
                'name' => 'Khaled Packer',
                'password' => Hash::make('password123'),
                'type' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $packer->syncRoles(['packer']);
        $packer->update(['warehouse_id' => $warehouseMain->id]);

        $customer = User::firstOrCreate(
            ['email' => 'wms.customer@example.com'],
            [
                'name' => 'Customer Demo',
                'password' => Hash::make('password123'),
                'type' => 'user',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Assign users to warehouse pivot table
        $warehouseMain->users()->syncWithoutDetaching([$admin->id, $picker->id, $packer->id]);

        // Mint Sanctum Bearer tokens for immediate API testing
        $adminToken = $admin->tokens()->first()?->token
            ? '[already generated]'
            : $admin->createToken('wms_admin_token', ['*'], now()->addDays(30))->plainTextToken;

        $pickerToken = $picker->tokens()->first()?->token
            ? '[already generated]'
            : $picker->createToken('wms_picker_token', ['*'], now()->addDays(30))->plainTextToken;

        // -------------------------------------------------------------
        // 4. Locations & Bins (Storage, Picking, Staging, Inactive)
        // -------------------------------------------------------------
        $locationsData = [
            // Active Picking Bins
            ['code' => 'LOC-PICK-01', 'name' => 'Pick Zone Fast Shelf 1', 'type' => 'picking', 'status' => 'active', 'priority' => 10],
            ['code' => 'LOC-PICK-02', 'name' => 'Pick Zone Fast Shelf 2', 'type' => 'picking', 'status' => 'active', 'priority' => 10],
            ['code' => 'LOC-PICK-03', 'name' => 'Pick Zone Fast Shelf 3', 'type' => 'picking', 'status' => 'active', 'priority' => 10],
            ['code' => 'LOC-PICK-04', 'name' => 'Pick Zone Fast Shelf 4', 'type' => 'picking', 'status' => 'active', 'priority' => 10],
            ['code' => 'LOC-PICK-05', 'name' => 'Pick Zone Fast Shelf 5', 'type' => 'picking', 'status' => 'active', 'priority' => 10],

            // Active Storage Shelves (Aisle A)
            ['code' => 'LOC-A-01-01', 'name' => 'Aisle A Bay 1 Shelf 1', 'type' => 'storage', 'status' => 'active', 'priority' => 5],
            ['code' => 'LOC-A-01-02', 'name' => 'Aisle A Bay 1 Shelf 2', 'type' => 'storage', 'status' => 'active', 'priority' => 5],
            ['code' => 'LOC-A-01-03', 'name' => 'Aisle A Bay 1 Shelf 3', 'type' => 'storage', 'status' => 'active', 'priority' => 5],
            ['code' => 'LOC-A-01-04', 'name' => 'Aisle A Bay 1 Shelf 4', 'type' => 'storage', 'status' => 'active', 'priority' => 5],
            ['code' => 'LOC-A-02-01', 'name' => 'Aisle A Bay 2 Shelf 1', 'type' => 'storage', 'status' => 'active', 'priority' => 5],
            ['code' => 'LOC-A-02-02', 'name' => 'Aisle A Bay 2 Shelf 2', 'type' => 'storage', 'status' => 'active', 'priority' => 5],

            // Active Receiving & Staging
            ['code' => 'LOC-RCV-01', 'name' => 'Inbound Receiving Dock', 'type' => 'receiving', 'status' => 'active', 'priority' => 1],
            ['code' => 'LOC-STG-01', 'name' => 'Outbound Staging Area', 'type' => 'staging', 'status' => 'active', 'priority' => 1],

            // Inactive / Maintenance Bins (for breakdown testing)
            ['code' => 'LOC-MAINT-01', 'name' => 'Damaged Bin Under Repair', 'type' => 'storage', 'status' => 'inactive', 'priority' => 0],
            ['code' => 'LOC-MAINT-02', 'name' => 'Reserved Expansion Bin', 'type' => 'storage', 'status' => 'inactive', 'priority' => 0],
        ];

        $locations = [];
        foreach ($locationsData as $loc) {
            $locations[$loc['code']] = Location::firstOrCreate(
                ['warehouse_id' => $warehouseMain->id, 'code' => $loc['code']],
                [
                    'name' => $loc['name'],
                    'barcode' => $loc['code'],
                    'type' => $loc['type'],
                    'status' => $loc['status'],
                    'priority' => $loc['priority'],
                    'metadata' => ['capacity' => 100],
                ]
            );
        }

        // -------------------------------------------------------------
        // 5. Products & Inventory Placements
        // -------------------------------------------------------------
        $productsData = [
            ['sku' => 'DEMO-PROD-LAPTOP', 'name' => 'Flagship Business Laptop Pro', 'price' => 1200.00, 'stock' => 50],
            ['sku' => 'DEMO-PROD-HEADSET', 'name' => 'Noise Cancelling Wireless Headset', 'price' => 150.00, 'stock' => 120],
            ['sku' => 'DEMO-PROD-KEYBOARD', 'name' => 'RGB Mechanical Gaming Keyboard', 'price' => 90.00, 'stock' => 80],
            ['sku' => 'DEMO-PROD-HUB', 'name' => 'USB-C Multiport Docking Station', 'price' => 60.00, 'stock' => 200],
        ];

        $products = [];
        foreach ($productsData as $prod) {
            $products[$prod['sku']] = Product::firstOrCreate(
                ['sku' => $prod['sku']],
                [
                    'name' => ['en' => $prod['name'], 'ar' => $prod['name']],
                    'slug' => \Illuminate\Support\Str::slug($prod['name']),
                    'price' => $prod['price'],
                    'stock_quantity' => $prod['stock'],
                    'quantity' => $prod['stock'],
                    'product_type' => ProductType::SIMPLE,
                    'item_type' => 'PHYSICAL',
                    'status' => 1,
                    'in_stock' => 1,
                ]
            );
        }

        // Placements (Stock stored in physical bins)
        $placements = [
            ProductLocation::updateOrCreate(
                ['warehouse_id' => $warehouseMain->id, 'product_id' => $products['DEMO-PROD-LAPTOP']->id, 'location_id' => $locations['LOC-PICK-01']->id],
                ['quantity' => 20, 'allocated_hint' => 0]
            ),
            ProductLocation::updateOrCreate(
                ['warehouse_id' => $warehouseMain->id, 'product_id' => $products['DEMO-PROD-HEADSET']->id, 'location_id' => $locations['LOC-PICK-02']->id],
                ['quantity' => 45, 'allocated_hint' => 0]
            ),
            ProductLocation::updateOrCreate(
                ['warehouse_id' => $warehouseMain->id, 'product_id' => $products['DEMO-PROD-KEYBOARD']->id, 'location_id' => $locations['LOC-A-01-01']->id],
                ['quantity' => 30, 'allocated_hint' => 0]
            ),
            ProductLocation::updateOrCreate(
                ['warehouse_id' => $warehouseMain->id, 'product_id' => $products['DEMO-PROD-HUB']->id, 'location_id' => $locations['LOC-A-01-02']->id],
                ['quantity' => 60, 'allocated_hint' => 0]
            ),
        ];

        // -------------------------------------------------------------
        // 6. Orders & Fulfillments Across All Lifecycle Statuses
        // -------------------------------------------------------------
        $flow = OrderFlow::first();
        $processingStatus = OrderStatus::where('code', 'processing')->first();

        $scenarios = [
            ['num' => 'ORD-WMS-001', 'ful' => 'FUL-WMS-001', 'status' => 'pending'],
            ['num' => 'ORD-WMS-002', 'ful' => 'FUL-WMS-002', 'status' => 'picking'],
            ['num' => 'ORD-WMS-003', 'ful' => 'FUL-WMS-003', 'status' => 'packing'],
            ['num' => 'ORD-WMS-004', 'ful' => 'FUL-WMS-004', 'status' => 'ready_to_ship'],
            ['num' => 'ORD-WMS-005', 'ful' => 'FUL-WMS-005', 'status' => 'shipped'],
            ['num' => 'ORD-WMS-006', 'ful' => 'FUL-WMS-006', 'status' => 'cancelled'],
        ];

        $fulfillments = [];
        foreach ($scenarios as $sc) {
            $order = Order::firstOrCreate(
                ['order_number' => $sc['num']],
                [
                    'user_id' => $customer->id,
                    'name' => $customer->name,
                    'user_email' => $customer->email,
                    'status' => 'processing',
                    'order_status' => 'order-processing',
                    'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                    'flow_id' => $flow?->id,
                    'current_status_id' => $processingStatus?->id,
                    'price' => 150.00,
                    'total_price' => 150.00,
                    'currency_code' => 'EGP',
                    'base_currency_code' => 'EGP',
                    'address' => ['street_address' => '10 Ramses St', 'city' => 'Cairo', 'country' => 'Egypt'],
                ]
            );

            $orderProduct = OrderProduct::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $products['DEMO-PROD-HEADSET']->id],
                [
                    'product_name' => 'Demo Headset',
                    'product_sku' => 'DEMO-PROD-HEADSET',
                    'product_quantity' => 1,
                    'product_price' => 150.00,
                    'product_total_price' => 150.00,
                ]
            );

            $ful = Fulfillment::firstOrCreate(
                ['fulfillment_number' => $sc['ful']],
                [
                    'order_id' => $order->id,
                    'warehouse_id' => $warehouseMain->id,
                    'warehouse_code' => $warehouseMain->code,
                    'warehouse_name' => $warehouseMain->name,
                    'status' => $sc['status'],
                ]
            );
            $ful->update(['status' => $sc['status']]);

            FulfillmentItem::firstOrCreate(
                ['fulfillment_id' => $ful->id, 'order_item_id' => $orderProduct->id],
                [
                    'product_id' => $products['DEMO-PROD-HEADSET']->id,
                    'quantity' => 1,
                    'quantity_picked' => in_array($sc['status'], ['packing', 'ready_to_ship', 'shipped']) ? 1 : 0,
                    'status' => in_array($sc['status'], ['packing', 'ready_to_ship', 'shipped']) ? 'picked' : 'pending',
                    'product_location_id' => $placements[1]->id,
                ]
            );

            $fulfillments[$sc['status']] = $ful;
        }

        // -------------------------------------------------------------
        // 7. Batches & Picking Tasks (Populating Picking Progress & Worker Context)
        // -------------------------------------------------------------
        $batchInProgress = FulfillmentBatch::firstOrCreate(
            ['batch_number' => 'BATCH-DEMO-INPROGRESS'],
            [
                'warehouse_id' => $warehouseMain->id,
                'status' => 'in_progress',
                'type' => 'standard',
                'assigned_to' => $picker->id,
                'started_at' => now()->subHour(),
                'total_items' => 15,
                'picked_items' => 6,
            ]
        );

        $batchCompleted = FulfillmentBatch::firstOrCreate(
            ['batch_number' => 'BATCH-DEMO-COMPLETED'],
            [
                'warehouse_id' => $warehouseMain->id,
                'status' => 'completed',
                'type' => 'standard',
                'assigned_to' => $picker->id,
                'started_at' => now()->subHours(3),
                'completed_at' => now()->subHours(1),
                'total_items' => 10,
                'picked_items' => 10,
            ]
        );

        // Active Picking Task assigned to $picker (Displays in worker_context!)
        PickingTask::updateOrCreate(
            ['batch_id' => $batchInProgress->id, 'sequence' => 1],
            [
                'order_id' => $fulfillments['picking']->order_id,
                'product_location_id' => $placements[0]->id,
                'quantity_to_pick' => 5,
                'quantity_picked' => 2,
                'status' => 'picking',
                'claimed_by' => $picker->id,
                'claimed_at' => now()->subMinutes(20),
            ]
        );

        // Claimed task
        PickingTask::updateOrCreate(
            ['batch_id' => $batchInProgress->id, 'sequence' => 2],
            [
                'order_id' => $fulfillments['picking']->order_id,
                'product_location_id' => $placements[1]->id,
                'quantity_to_pick' => 4,
                'quantity_picked' => 0,
                'status' => 'claimed',
                'claimed_by' => $picker->id,
                'claimed_at' => now()->subMinutes(10),
            ]
        );

        // Completed task
        PickingTask::updateOrCreate(
            ['batch_id' => $batchCompleted->id, 'sequence' => 1],
            [
                'order_id' => $fulfillments['ready_to_ship']->order_id,
                'product_location_id' => $placements[2]->id,
                'quantity_to_pick' => 10,
                'quantity_picked' => 10,
                'status' => 'completed',
                'claimed_by' => $picker->id,
                'picked_at' => now()->subHours(1),
            ]
        );

        // -------------------------------------------------------------
        // 8. Packing Stations, Tasks & Packages
        // -------------------------------------------------------------
        $station1 = PackingStation::firstOrCreate(
            ['code' => 'STATION-01'],
            [
                'warehouse_id' => $warehouseMain->id,
                'name' => 'Packing Station Alpha (Main)',
                'status' => 'active',
                'daily_capacity' => 200,
            ]
        );

        $station2 = PackingStation::firstOrCreate(
            ['code' => 'STATION-02'],
            [
                'warehouse_id' => $warehouseMain->id,
                'name' => 'Packing Station Beta (Backup)',
                'status' => 'inactive',
                'daily_capacity' => 100,
            ]
        );

        // Active Packing Task assigned to $packer (Displays in worker_context!)
        PackingTask::updateOrCreate(
            ['fulfillment_id' => $fulfillments['packing']->id],
            [
                'packing_station_id' => $station1->id,
                'assigned_to' => $packer->id,
                'status' => 'in_progress',
                'started_at' => now()->subMinutes(15),
            ]
        );

        // Packages (Sealed & Open)
        $pkg1 = Package::firstOrCreate(
            ['package_number' => 'PKG-DEMO-SEALED-001'],
            [
                'fulfillment_id' => $fulfillments['ready_to_ship']->id,
                'barcode' => 'PKG-DEMO-SEALED-001',
                'status' => Package::STATUS_SEALED,
                'weight' => 1.5,
                'sealed_at' => now()->subMinutes(30),
            ]
        );

        $pkg2 = Package::firstOrCreate(
            ['package_number' => 'PKG-DEMO-OPEN-002'],
            [
                'fulfillment_id' => $fulfillments['packing']->id,
                'barcode' => 'PKG-DEMO-OPEN-002',
                'status' => Package::STATUS_OPEN,
                'weight' => 0.8,
            ]
        );

        // -------------------------------------------------------------
        // 9. Shipments (Created, Dispatched, Delivered)
        // -------------------------------------------------------------
        Shipment::updateOrCreate(
            ['tracking_number' => 'TRK-DEMO-READY-001'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'fulfillment_id' => $fulfillments['ready_to_ship']->id,
                'order_id' => $fulfillments['ready_to_ship']->order_id,
                'status' => 'created',
                'courier' => 'Bosta Express',
                'shipping_cost' => 45.00,
                'currency' => 'EGP',
            ]
        );

        Shipment::updateOrCreate(
            ['tracking_number' => 'TRK-DEMO-DISPATCH-002'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'fulfillment_id' => $fulfillments['shipped']->id,
                'order_id' => $fulfillments['shipped']->order_id,
                'status' => 'dispatched',
                'courier' => 'Aramex Domestic',
                'shipping_cost' => 50.00,
                'currency' => 'EGP',
                'shipped_at' => now()->subHours(2),
            ]
        );

        Shipment::updateOrCreate(
            ['tracking_number' => 'TRK-DEMO-DELIVER-003'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => 'delivered',
                'courier' => 'FedEx Local',
                'shipping_cost' => 60.00,
                'currency' => 'EGP',
                'delivered_at' => now()->subDay(),
            ]
        );

        // Forget cached permissions so Spatie loads everything fresh
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // -------------------------------------------------------------
        // 10. Summary CLI Output
        // -------------------------------------------------------------
        $this->command?->info('');
        $this->command?->info('  ✓ [SUCCESS] Warehouse Dashboard Dummy Dataset Seeded Successfully!');
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Warehouse Default:       {$warehouseMain->name} (ID: {$warehouseMain->id}, Code: {$warehouseMain->code})");
        $this->command?->info("  Warehouse Secondary:     {$warehouseAlx->name} (ID: {$warehouseAlx->id}, Code: {$warehouseAlx->code})");
        $this->command?->info("  Locations Created:       " . count($locationsData) . " bins (Storage, Picking, Staging, Inactive)");
        $this->command?->info("  Occupied Bins:           " . count($placements) . " bins with active stock");
        $this->command?->info("  Fulfillments:            6 orders across all statuses (pending -> shipped)");
        $this->command?->info("  Batches & Tasks:         2 batches, 3 picking tasks, 1 packing task");
        $this->command?->info("  Packing Stations:        2 stations (1 active, 1 inactive)");
        $this->command?->info("  Shipments:               3 shipments (created, dispatched, delivered)");
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Admin Login:             wms.admin@example.com / password123");
        $this->command?->info("  Picker Login:            wms.picker@example.com / password123");
        $this->command?->info("  Packer Login:            wms.packer@example.com / password123");
        if (!str_starts_with($adminToken, '[')) {
            $this->command?->info("  Admin Bearer Token:      {$adminToken}");
        }
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info('  Test Endpoints Ready:');
        $this->command?->info("  GET /api/v1/admin/warehouses/dashboard");
        $this->command?->info("  GET /api/v1/admin/warehouses/{$warehouseMain->id}/dashboard");
        $this->command?->info("  GET /api/v1/admin/warehouses/permissions");
        $this->command?->info('================================================================');
    }
}
