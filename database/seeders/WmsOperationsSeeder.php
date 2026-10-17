<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Fulfillment;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\RefundPolicy;
use Marvel\Database\Models\RefundReason;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;

class WmsOperationsSeeder extends Seeder
{
    public function run(): void
    {
        // Operator explicitly requested full seeding including production
        // (`db:seed` runs every seeder in all environments).

        $this->command?->info('================================================================');
        $this->command?->info('  Setting up Complete WMS, Order Flow & Operations Dataset');
        $this->command?->info('================================================================');

        // 1. All Required Permissions
        $allPermissions = [
            // Warehouse & Locations
            'view-warehouse',
            'manage-warehouse',
            'view-location',
            'manage-location',
            'inventory-adjust',

            // Fulfillments
            'view-fulfillment',
            'manage-fulfillment',
            'fulfillment.create',
            'fulfillment.cancel',
            'fulfillment-override',

            // Picking & Batches
            'picking-execute',
            'picking.claim',
            'picking.complete',
            'batch.manage',
            'batch.operate',

            // Packing & Packages
            'packing-execute',
            'packing.complete',

            // Shipments
            'view-all-shipments',
            'view-shipments',
            'view-shipment',
            'create-shipment',
            'update-shipment',

            // Orders & Flows
            'view-orders',
            'view-order',
            'update-order-status',
            'view-order-flows',
            'create-order-flows',
            'update-order-flows',
            'manage-order-flow-inputs',
            'order.cancel-during-fulfillment',

            // Refunds
            'view-refunds',
            'payments.refund',
        ];

        foreach ($allPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        // 2. Roles — ADDITIVE grants only (2026-10-13 fix).
        // This seeder runs LAST in DatabaseSeeder (after PermissionSeeder which
        // grants super_admin ~270 permissions). Using syncPermissions() here
        // would REPLACE super_admin's full grant with this 25-perm WMS subset
        // and lock every admin out of non-WMS endpoints. givePermissionTo()
        // preserves the existing full grant while ensuring the WMS subset.
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
        $superAdminRole->givePermissionTo($allPermissions);

        $managerRole = Role::firstOrCreate(
            ['name' => 'warehouse-manager', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Warehouse Manager', 'ar' => 'مدير مستودع']]
        );
        $managerRole->givePermissionTo($allPermissions);

        $pickerRole = Role::firstOrCreate(
            ['name' => 'picker', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Picker', 'ar' => 'عامل التقاط']]
        );
        $pickerRole->syncPermissions([
            'view-warehouse', 'view-location', 'view-fulfillment', 'picking-execute', 'picking.claim',
        ]);

        // 3. Default Warehouse
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'المخزن الرئيسي (Cairo Hub)',
                'status' => 'active',
                'is_default' => true,
                'address' => 'شارع اللوجستيات 10، مدينة نصر',
                'city' => 'Cairo',
                'country' => 'EG',
                'metadata' => ['capacity' => 20000, 'contact' => '+201000000000'],
            ]
        );
        $warehouse->update(['is_default' => true, 'status' => 'active']);

        // 4. Standard Active Locations
        $locationsSetup = [
            [
                'code' => 'REC-01',
                'barcode' => 'LOC-REC-01',
                'name' => 'منطقة الاستلام (Receiving)',
                'type' => Location::TYPE_RECEIVING,
                'priority' => 20,
            ],
            [
                'code' => 'A-01-01',
                'barcode' => 'LOC-A-01-01',
                'name' => 'ممر A - رف 1 - خانة 1',
                'type' => Location::TYPE_STORAGE,
                'priority' => 10,
            ],
            [
                'code' => 'A-01-02',
                'barcode' => 'LOC-A-01-02',
                'name' => 'ممر A - رف 1 - خانة 2',
                'type' => Location::TYPE_STORAGE,
                'priority' => 9,
            ],
            [
                'code' => 'PICK-01',
                'barcode' => 'LOC-PICK-01',
                'name' => 'منطقة اللم السريع (Fast Pick)',
                'type' => Location::TYPE_PICKING,
                'priority' => 15,
            ],
            [
                'code' => 'PACK-01',
                'barcode' => 'LOC-PACK-01',
                'name' => 'منطقة تجهيز التغليف (Packing)',
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
                ]
            );
            $locations[$locData['code']]->update(['status' => Location::STATUS_ACTIVE]);
        }

        // 5. Packing Station
        $station = PackingStation::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'code' => 'PACK-STATION-01'],
            ['name' => 'طاولة التغليف رقم 1', 'status' => 'active']
        );
        $station->update(['status' => 'active']);

        // 6. Users: Admin, Picker, Customer
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
        $admin->syncRoles(['super_admin', 'warehouse-manager']);
        // Additive (see §2): syncPermissions() would wipe the direct
        // change-order-status.* grants given by AdminOrderOperationsSeeder and
        // any other direct grants. givePermissionTo() preserves them.
        $admin->givePermissionTo($allPermissions);
        // Idempotent token: re-seeding must not leak a new bearer token row
        // on every run. The printed token is a test credential (see output).
        $adminToken = $admin->tokens()->where('name', 'AdminWmsToken')->exists()
            ? '[existing AdminWmsToken kept — no new token issued]'
            : $admin->createToken('AdminWmsToken')->plainTextToken;

        $picker = User::firstOrCreate(
            ['email' => 'picker@wms.com'],
            [
                'name' => 'أحمد (عامل اللم بالمخزن)',
                'password' => Hash::make('password123'),
                'type' => 'staff',
                'is_active' => true,
                'warehouse_id' => $warehouse->id,
            ]
        );
        $picker->syncRoles(['picker']);

        $customer = User::firstOrCreate(
            ['email' => 'customer@wms.com'],
            [
                'name' => 'محمد العميل التجريبي',
                'password' => Hash::make('password123'),
                'type' => 'customer',
                'is_active' => true,
                'phone_number' => '+201099887766',
            ]
        );

        // 7. Products (Physical)
        $productAlpha = Product::firstOrCreate(
            ['sku' => 'WMS-ALPHA-101'],
            [
                'name' => ['en' => 'WMS Wireless Headset Alpha', 'ar' => 'سماعة وايرلس ألفا'],
                'slug' => 'wms-wireless-headset-alpha',
                'price' => 150.00,
                'stock_quantity' => 100,
                'quantity' => 100,
                'product_type' => ProductType::SIMPLE,
                'item_type' => 'PHYSICAL',
                'status' => 1,
                'in_stock' => 1,
            ]
        );

        $productBeta = Product::firstOrCreate(
            ['sku' => 'WMS-BETA-102'],
            [
                'name' => ['en' => 'WMS Smart Mouse Beta', 'ar' => 'ماوس ذكي بيتا'],
                'slug' => 'wms-smart-mouse-beta',
                'price' => 75.00,
                'stock_quantity' => 100,
                'quantity' => 100,
                'product_type' => ProductType::SIMPLE,
                'item_type' => 'PHYSICAL',
                'status' => 1,
                'in_stock' => 1,
            ]
        );

        $productGamma = Product::firstOrCreate(
            ['sku' => 'WMS-GAMMA-103'],
            [
                'name' => ['en' => 'WMS Mechanical Keyboard Gamma', 'ar' => 'كيبورد ميكانيكي جاما'],
                'slug' => 'wms-mechanical-keyboard-gamma',
                'price' => 200.00,
                'stock_quantity' => 50,
                'quantity' => 50,
                'product_type' => ProductType::SIMPLE,
                'item_type' => 'PHYSICAL',
                'status' => 1,
                'in_stock' => 1,
            ]
        );

        // 8. Product Placements (Shelves stock in ProductLocations)
        // Alpha in PICK-01 (50 units) & A-01-01 (50 units)
        $placementAlphaPick = ProductLocation::firstOrCreate(
            ['product_id' => $productAlpha->id, 'location_id' => $locations['PICK-01']->id],
            ['warehouse_id' => $warehouse->id, 'quantity' => 50, 'allocated_hint' => 0]
        );

        $placementAlphaStorage = ProductLocation::firstOrCreate(
            ['product_id' => $productAlpha->id, 'location_id' => $locations['A-01-01']->id],
            ['warehouse_id' => $warehouse->id, 'quantity' => 50, 'allocated_hint' => 0]
        );

        // Beta in A-01-02 (60 units)
        $placementBetaStorage = ProductLocation::firstOrCreate(
            ['product_id' => $productBeta->id, 'location_id' => $locations['A-01-02']->id],
            ['warehouse_id' => $warehouse->id, 'quantity' => 60, 'allocated_hint' => 0]
        );

        // Gamma placement on A-01-01 (20 units) -> available for manual assign-placement!
        $placementGamma = ProductLocation::firstOrCreate(
            ['product_id' => $productGamma->id, 'location_id' => $locations['A-01-01']->id],
            ['warehouse_id' => $warehouse->id, 'quantity' => 20, 'allocated_hint' => 0]
        );

        // Order Flow & Statuses
        $flow = OrderFlow::first();
        $processingStatus = OrderStatus::where('code', 'processing')->first();
        $completedStatus = OrderStatus::where('code', 'completed')->first();

        // ==============================================================
        //  SCENARIOS FOR TESTING EVERY WMS ENDPOINT
        // ==============================================================

        // ── SCENARIO 1: Order Paid Online Ready for Release ───────────
        $orderPaid = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-PAID-001'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'payment_method' => 'online',
                'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
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
        $orderPaid->update([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        OrderProduct::firstOrCreate(
            ['order_id' => $orderPaid->id, 'product_id' => $productAlpha->id],
            [
                'product_name' => 'WMS Wireless Headset Alpha',
                'product_sku' => $productAlpha->sku,
                'product_quantity' => 2,
                'product_price' => 150.00,
                'product_total_price' => 300.00,
            ]
        );

        // ── SCENARIO 2: Order Cash On Delivery (COD) Ready for Release ──
        $orderCod = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-COD-002'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_PENDING,
                'payment_method' => 'cod',
                'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
                'fulfillment_status' => 'pending',
                'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 75.00,
                'total_price' => 75.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '25 Tahrir St', 'city' => 'Cairo', 'country' => 'Egypt'],
            ]
        );
        $orderCod->update([
            'payment_method' => 'cod',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);
        OrderProduct::firstOrCreate(
            ['order_id' => $orderCod->id, 'product_id' => $productBeta->id],
            [
                'product_name' => 'WMS Smart Mouse Beta',
                'product_sku' => $productBeta->sku,
                'product_quantity' => 1,
                'product_price' => 75.00,
                'product_total_price' => 75.00,
            ]
        );

        // ── SCENARIO 3: Fulfillment Item With NO Placement (To Test assign-placement) ─
        $orderNeedPlacement = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-NEED-PLACEMENT-003'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 200.00,
                'total_price' => 200.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '15 Dokki St', 'city' => 'Giza', 'country' => 'Egypt'],
            ]
        );
        $orderProductGamma = OrderProduct::firstOrCreate(
            ['order_id' => $orderNeedPlacement->id, 'product_id' => $productGamma->id],
            [
                'product_name' => 'WMS Mechanical Keyboard Gamma',
                'product_sku' => $productGamma->sku,
                'product_quantity' => 1,
                'product_price' => 200.00,
                'product_total_price' => 200.00,
            ]
        );
        $fulNeedPlacement = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-NEED-PLACEMENT-003'],
            [
                'order_id' => $orderNeedPlacement->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'status' => 'pending',
            ]
        );
        $itemNeedPlacement = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulNeedPlacement->id, 'order_item_id' => $orderProductGamma->id],
            [
                'product_id' => $productGamma->id,
                'quantity' => 1.00,
                'quantity_picked' => 0.00,
                'status' => 'pending',
                'product_location_id' => null, // 👈 Explicitly NULL for assign-placement test!
                'notes' => 'Location allocation pending manual placement',
            ]
        );

        // ── SCENARIO 4: Fulfillment Ready For Picking Execution (Scanner Test) ────
        $orderReadyPick = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-READY-PICK-004'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'user_phone' => $customer->phone_number,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 150.00,
                'total_price' => 150.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => '30 Ramses St', 'city' => 'Cairo', 'country' => 'Egypt'],
            ]
        );
        $orderProductPick = OrderProduct::firstOrCreate(
            ['order_id' => $orderReadyPick->id, 'product_id' => $productAlpha->id],
            [
                'product_name' => 'WMS Wireless Headset Alpha',
                'product_sku' => $productAlpha->sku,
                'product_quantity' => 1,
                'product_price' => 150.00,
                'product_total_price' => 150.00,
            ]
        );
        $fulReadyPick = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-READY-PICK-004'],
            [
                'order_id' => $orderReadyPick->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'status' => 'pending',
            ]
        );
        $itemReadyPick = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulReadyPick->id, 'order_item_id' => $orderProductPick->id],
            [
                'product_id' => $productAlpha->id,
                'quantity' => 1.00,
                'quantity_picked' => 0.00,
                'status' => 'pending',
                'product_location_id' => $placementAlphaPick->id,
            ]
        );

        // ── SCENARIO 5 & 6: Two Fulfillments Ready for Batch (Wave Picking) ────
        $orderBatchA = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-BATCH-A-005'],
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
                'address' => ['street_address' => 'Batch Area 1', 'city' => 'Cairo'],
            ]
        );
        $opBatchA = OrderProduct::firstOrCreate(
            ['order_id' => $orderBatchA->id, 'product_id' => $productAlpha->id],
            ['product_name' => 'Alpha', 'product_sku' => $productAlpha->sku, 'product_quantity' => 1, 'product_price' => 150.00, 'product_total_price' => 150.00]
        );
        $fulBatchA = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-BATCH-A-005'],
            ['order_id' => $orderBatchA->id, 'warehouse_id' => $warehouse->id, 'warehouse_code' => $warehouse->code, 'warehouse_name' => $warehouse->name, 'status' => 'pending']
        );
        FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulBatchA->id, 'order_item_id' => $opBatchA->id],
            ['product_id' => $productAlpha->id, 'quantity' => 1, 'quantity_picked' => 0, 'status' => 'pending', 'product_location_id' => $placementAlphaPick->id]
        );

        $orderBatchB = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-BATCH-B-006'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'status' => 'processing',
                'order_status' => 'order-processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 75.00,
                'total_price' => 75.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => 'Batch Area 2', 'city' => 'Cairo'],
            ]
        );
        $opBatchB = OrderProduct::firstOrCreate(
            ['order_id' => $orderBatchB->id, 'product_id' => $productBeta->id],
            ['product_name' => 'Beta', 'product_sku' => $productBeta->sku, 'product_quantity' => 1, 'product_price' => 75.00, 'product_total_price' => 75.00]
        );
        $fulBatchB = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-BATCH-B-006'],
            ['order_id' => $orderBatchB->id, 'warehouse_id' => $warehouse->id, 'warehouse_code' => $warehouse->code, 'warehouse_name' => $warehouse->name, 'status' => 'pending']
        );
        FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulBatchB->id, 'order_item_id' => $opBatchB->id],
            ['product_id' => $productBeta->id, 'quantity' => 1, 'quantity_picked' => 0, 'status' => 'pending', 'product_location_id' => $placementBetaStorage->id]
        );

        // ── SCENARIO 7: Fulfillment Picked & Ready For Packing & Packages ────
        $orderReadyPack = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-READY-PACK-007'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'status' => 'processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 150.00,
                'total_price' => 150.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => 'Packing Zone 7', 'city' => 'Cairo'],
            ]
        );
        $opPack = OrderProduct::firstOrCreate(
            ['order_id' => $orderReadyPack->id, 'product_id' => $productAlpha->id],
            ['product_name' => 'Alpha', 'product_sku' => $productAlpha->sku, 'product_quantity' => 1, 'product_price' => 150.00, 'product_total_price' => 150.00]
        );
        $fulReadyPack = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-READY-PACK-007'],
            ['order_id' => $orderReadyPack->id, 'warehouse_id' => $warehouse->id, 'warehouse_code' => $warehouse->code, 'warehouse_name' => $warehouse->name, 'status' => 'picked']
        );
        $fulReadyPack->update(['status' => 'picked']);
        $itemReadyPack = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulReadyPack->id, 'order_item_id' => $opPack->id],
            ['product_id' => $productAlpha->id, 'quantity' => 1, 'quantity_picked' => 1, 'status' => 'picked', 'product_location_id' => $placementAlphaPick->id]
        );
        $itemReadyPack->update(['quantity_picked' => 1, 'status' => 'picked']);

        // ── SCENARIO 8: Fulfillment Ready to Ship (Ready for Shipment create & dispatch) ─
        $orderReadyShip = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-READY-SHIP-008'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'status' => 'processing',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $processingStatus?->id,
                'price' => 75.00,
                'total_price' => 75.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
                'address' => ['street_address' => 'Shipping St', 'city' => 'Cairo'],
            ]
        );
        $opShip = OrderProduct::firstOrCreate(
            ['order_id' => $orderReadyShip->id, 'product_id' => $productBeta->id],
            ['product_name' => 'Beta', 'product_sku' => $productBeta->sku, 'product_quantity' => 1, 'product_price' => 75.00, 'product_total_price' => 75.00]
        );
        $fulReadyShip = Fulfillment::firstOrCreate(
            ['fulfillment_number' => 'FUL-READY-SHIP-008'],
            ['order_id' => $orderReadyShip->id, 'warehouse_id' => $warehouse->id, 'warehouse_code' => $warehouse->code, 'warehouse_name' => $warehouse->name, 'status' => 'ready_to_ship']
        );
        $fulReadyShip->update(['status' => 'ready_to_ship']);
        $itemReadyShip = FulfillmentItem::firstOrCreate(
            ['fulfillment_id' => $fulReadyShip->id, 'order_item_id' => $opShip->id],
            ['product_id' => $productBeta->id, 'quantity' => 1, 'quantity_picked' => 1, 'status' => 'packed', 'product_location_id' => $placementBetaStorage->id]
        );

        // Package for Ready-To-Ship fulfillment
        $packageReadyShip = Package::firstOrCreate(
            ['fulfillment_id' => $fulReadyShip->id, 'package_number' => 'PKG-READY-SHIP-008'],
            ['status' => 'sealed', 'weight' => 0.5, 'barcode' => 'PKG-READY-SHIP-008']
        );
        PackageItem::firstOrCreate(
            ['package_id' => $packageReadyShip->id, 'fulfillment_item_id' => $itemReadyShip->id],
            ['quantity' => 1]
        );

        // ── SCENARIO 9: Refund Request for Testing Approve / Reject ───
        $orderRefund = Order::firstOrCreate(
            ['order_number' => 'ORD-WMS-REFUND-009'],
            [
                'user_id' => $customer->id,
                'name' => $customer->name,
                'user_email' => $customer->email,
                'status' => 'completed',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'flow_id' => $flow?->id,
                'current_status_id' => $completedStatus?->id,
                'price' => 150.00,
                'total_price' => 150.00,
                'currency_code' => 'EGP',
                'base_currency_code' => 'EGP',
            ]
        );
        $refundPolicy = RefundPolicy::firstOrCreate(
            ['slug' => 'standard-policy'],
            ['title' => 'Standard 14 Days Policy', 'description' => 'Standard refund policy', 'status' => 1]
        );
        $refundReason = RefundReason::firstOrCreate(
            ['slug' => 'damaged-item'],
            ['name' => 'المنتج تالف أو به عيب صناعة']
        );
        $refund = Refund::firstOrCreate(
            ['order_id' => $orderRefund->id],
            [
                'user_id' => $customer->id,
                'amount' => 150.00,
                'currency' => 'EGP',
                'title' => 'طلب استرجاع سماعة ألفا',
                'description' => 'السماعة لا تعمل بالشكل المطلوب',
                'status' => 'pending',
                'refund_policy_id' => $refundPolicy->id,
                'refund_reason_id' => $refundReason->id,
            ]
        );

        // Commit guard at END of run (covers scenarios 1–9: later scenarios
        // are created after the release scenarios above, and firstOrCreate
        // never repairs pre-existing rows). Scoped to this seeder's own
        // ORD-WMS-* numbers — never blanket primary keys, so a re-run cannot
        // rewrite unrelated real orders.
        Order::where('order_number', 'like', 'ORD-WMS-%')->update([
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);

        // ==============================================================
        //  CLI SUMMARY REPORT FOR DEVELOPER & FRONTEND TEAM
        // ==============================================================
        $this->command?->info('');
        $this->command?->info('  [SUCCESS] All Testing Scenarios Seeded Successfully!');
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info("  Admin Login:              admin@gmail.com / password123");
        if (str_starts_with((string) $adminToken, '[existing')) {
            $this->command?->info('  Admin Bearer Token:       (existing AdminWmsToken kept — mint a new one via Sanctum if needed)');
        } else {
            $this->command?->info("  Admin Bearer Token:       {$adminToken}");
        }
        $this->command?->info("  Default Warehouse ID:     {$warehouse->id} ({$warehouse->code})");
        $this->command?->info("  Packing Station ID:       {$station->id} ({$station->code})");
        $this->command?->info("  Location Picking (Barcode): LOC-PICK-01 (ID: {$locations['PICK-01']->id})");
        $this->command?->info("  Location Storage (Barcode): LOC-A-01-01 (ID: {$locations['A-01-01']->id})");
        $this->command?->info("  Product Alpha SKU:        {$productAlpha->sku} (ID: {$productAlpha->id})");
        $this->command?->info("  Product Gamma Placement:  Placement ID: {$placementGamma->id} (Product ID: {$productGamma->id})");
        $this->command?->info('----------------------------------------------------------------');
        $this->command?->info('  READY TEST SCENARIOS:');
        $this->command?->info("  1. Release (Online Paid): Order ID: {$orderPaid->id} (POST admin/fulfillments/release)");
        $this->command?->info("  2. Release (COD Cash):    Order ID: {$orderCod->id} (POST admin/fulfillments/release)");
        $this->command?->info("  3. Assign-Placement:      Fulfillment ID: {$fulNeedPlacement->id} | Item ID: {$itemNeedPlacement->id} -> assign to placement_id: {$placementGamma->id}");
        $this->command?->info("  4. Picking Scanner Test:  Fulfillment ID: {$fulReadyPick->id} | Item ID: {$itemReadyPick->id}");
        $this->command?->info("  5. Batch (Wave) Test:     Fulfillment IDs: [{$fulBatchA->id}, {$fulBatchB->id}] -> (POST admin/batches)");
        $this->command?->info("  6. Packing & Package:     Fulfillment ID: {$fulReadyPack->id} (Status: picked) -> (create-packing-task, packages, seal, verify)");
        $this->command?->info("  7. Shipment & Dispatch:   Fulfillment ID: {$fulReadyShip->id} (Status: ready_to_ship) -> (POST fulfillments/{$fulReadyShip->id}/shipments, dispatch, deliver)");
        $this->command?->info("  8. Refund Approve/Reject: Refund ID: {$refund->id} (POST admin/refunds/{$refund->id}/approve)");
        $this->command?->info('================================================================');

        // M3: bust Spatie's permission cache so the additive grants above take
        // effect immediately. PermissionSeeder busts after its own sync, but
        // this seeder runs LAST in DatabaseSeeder — without this, the new
        // grants stay invisible until a manual cache clear and gates 403.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
