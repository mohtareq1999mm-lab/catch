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
use App\Models\Shipment;
use App\Models\WarehouseWorkflowVersion;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;

class FulfillmentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('================================================================================');
        $this->command?->info('  Seeding Massive, Ultra-Accurate Fulfillment Lifecycle Dataset (240 Records)');
        $this->command?->info('================================================================================');

        // 1. Permissions & Roles
        $permissions = [
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'inventory-adjust', 'view-fulfillment', 'manage-fulfillment',
            'fulfillment.create', 'fulfillment.cancel', 'fulfillment-override',
            'picking-execute', 'picking.claim', 'picking.complete',
            'packing-execute', 'packing.complete', 'view-all-shipments',
            'view-shipments', 'create-shipment', 'update-shipment',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);

        // 2. Base Warehouses (Cairo Hub & Alexandria Logistics Hub)
        $hasDefaultWarehouse = Warehouse::where('is_default', true)->whereNull('deleted_at')->exists();

        $warehouse1 = Warehouse::firstOrCreate(
            ['code' => 'WH-CAIRO-CENTRAL'],
            [
                'name' => 'Central Hub - Cairo Distribution Center',
                'address' => 'Plot 12, Industrial Area, Badr City',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'active',
                'is_default' => !$hasDefaultWarehouse,
                'metadata' => ['zone' => 'Zone-A', 'total_capacity_sqm' => 15000, 'operating_hours' => '24/7'],
            ]
        );

        $warehouse2 = Warehouse::firstOrCreate(
            ['code' => 'WH-ALEX-LOGISTICS'],
            [
                'name' => 'Alexandria Coastal Logistics Hub',
                'address' => 'Borg El Arab Industrial Zone, Gate 3',
                'city' => 'Alexandria',
                'country' => 'Egypt',
                'status' => 'active',
                'is_default' => false,
                'metadata' => ['zone' => 'Zone-B', 'total_capacity_sqm' => 8000, 'operating_hours' => '08:00 - 20:00'],
            ]
        );

        $workflowVersion = WarehouseWorkflowVersion::firstOrCreate(
            ['id' => 1],
            [
                'picking_enabled' => true,
                'packing_enabled' => true,
                'shipping_enabled' => true,
                'order_received_enabled' => true,
                'notes' => 'Authoritative workflow version v1.2 Enterprise Production',
                'published_at' => now()->subMonths(2),
            ]
        );

        // 3. Packing Stations (Cairo & Alex)
        $stationsWh1 = [];
        $stationsWh1Defs = [
            'CAIRO-01' => 'Cairo Express Pack Station 1',
            'CAIRO-02' => 'Cairo Heavy & Bulky Pack Station 2',
            'CAIRO-03' => 'Cairo Fragile Electronics Station 3',
        ];
        foreach ($stationsWh1Defs as $code => $name) {
            $stationsWh1[$code] = PackingStation::firstOrCreate(
                ['code' => 'STATION-' . $code, 'warehouse_id' => $warehouse1->id],
                [
                    'name' => $name,
                    'status' => 'active',
                    'daily_capacity' => 350,
                    'metadata' => ['scanner' => 'Honeywell 1900G', 'printer' => 'Zebra ZD420', 'station_type' => 'automated'],
                ]
            );
        }

        $stationsWh2 = [];
        $stationsWh2Defs = [
            'ALEX-01' => 'Alexandria Coastal Pack Station 1',
            'ALEX-02' => 'Alexandria Express Pack Station 2',
        ];
        foreach ($stationsWh2Defs as $code => $name) {
            $stationsWh2[$code] = PackingStation::firstOrCreate(
                ['code' => 'STATION-' . $code, 'warehouse_id' => $warehouse2->id],
                [
                    'name' => $name,
                    'status' => 'active',
                    'daily_capacity' => 200,
                    'metadata' => ['scanner' => 'Datalogic Gryphon', 'printer' => 'TSC TE200', 'station_type' => 'standard'],
                ]
            );
        }

        // 4. Warehouse Bins and Shelf Locations
        $locationsWh1 = [];
        $locWh1Defs = [
            'A-01-01' => ['picking', 10], 'A-01-02' => ['picking', 10], 'A-01-03' => ['picking', 9],
            'A-02-01' => ['picking', 9],  'A-02-02' => ['picking', 8],  'A-02-03' => ['picking', 8],
            'B-01-01' => ['storage', 5],  'B-01-02' => ['storage', 5],  'B-02-01' => ['storage', 4],
            'C-01-01' => ['storage', 3],  'BULK-01' => ['storage', 2],
        ];
        foreach ($locWh1Defs as $code => [$type, $priority]) {
            $locationsWh1[$code] = Location::firstOrCreate(
                ['code' => 'LOC-' . $code, 'warehouse_id' => $warehouse1->id],
                [
                    'name' => 'Cairo Bin ' . $code,
                    'barcode' => 'BAR-LOC-CAI-' . $code,
                    'type' => $type,
                    'status' => 'active',
                    'priority' => $priority,
                ]
            );
        }

        $locationsWh2 = [];
        $locWh2Defs = [
            'AX-01-01' => ['picking', 10], 'AX-01-02' => ['picking', 10],
            'AX-02-01' => ['picking', 8],  'AX-02-02' => ['picking', 8],
            'AX-B-01'  => ['storage', 5],  'AX-BULK'  => ['storage', 2],
        ];
        foreach ($locWh2Defs as $code => [$type, $priority]) {
            $locationsWh2[$code] = Location::firstOrCreate(
                ['code' => 'LOC-' . $code, 'warehouse_id' => $warehouse2->id],
                [
                    'name' => 'Alex Bin ' . $code,
                    'barcode' => 'BAR-LOC-ALX-' . $code,
                    'type' => $type,
                    'status' => 'active',
                    'priority' => $priority,
                ]
            );
        }

        // 5. Staff Accounts (Cairo & Alexandria Operations)
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            ['name' => 'General Operations Supervisor', 'password' => Hash::make('password123'), 'is_active' => true, 'type' => 'admin', 'warehouse_id' => $warehouse1->id]
        );
        $admin->assignRole($superAdminRole);

        $pickersWh1 = [];
        foreach ([1 => 'Ahmed Tarek', 2 => 'Mohamed Youssef', 3 => 'Khaled Hassan'] as $idx => $name) {
            $pickersWh1[$idx] = User::firstOrCreate(
                ['email' => "picker{$idx}@wms.com"],
                ['name' => $name . ' (Cairo Picker)', 'password' => Hash::make('password123'), 'is_active' => true, 'type' => 'staff', 'warehouse_id' => $warehouse1->id]
            );
            $pickersWh1[$idx]->givePermissionTo(['picking-execute', 'picking.claim', 'picking.complete', 'view-fulfillment']);
        }

        $packersWh1 = [];
        foreach ([1 => 'Mahmoud Sayed', 2 => 'Ibrahim Adel', 3 => 'Tamer Fathy'] as $idx => $name) {
            $packersWh1[$idx] = User::firstOrCreate(
                ['email' => "packer{$idx}@wms.com"],
                ['name' => $name . ' (Cairo Packer)', 'password' => Hash::make('password123'), 'is_active' => true, 'type' => 'staff', 'warehouse_id' => $warehouse1->id]
            );
            $packersWh1[$idx]->givePermissionTo(['packing-execute', 'packing.complete', 'view-fulfillment']);
        }

        $pickersWh2 = [];
        foreach ([1 => 'Yasser Galal', 2 => 'Mostafa Amar'] as $idx => $name) {
            $pickersWh2[$idx] = User::firstOrCreate(
                ['email' => "picker.alex{$idx}@wms.com"],
                ['name' => $name . ' (Alex Picker)', 'password' => Hash::make('password123'), 'is_active' => true, 'type' => 'staff', 'warehouse_id' => $warehouse2->id]
            );
            $pickersWh2[$idx]->givePermissionTo(['picking-execute', 'picking.claim', 'picking.complete', 'view-fulfillment']);
        }

        $packersWh2 = [];
        foreach ([1 => 'Nader Hamdy', 2 => 'Karim Fahmy'] as $idx => $name) {
            $packersWh2[$idx] = User::firstOrCreate(
                ['email' => "packer.alex{$idx}@wms.com"],
                ['name' => $name . ' (Alex Packer)', 'password' => Hash::make('password123'), 'is_active' => true, 'type' => 'staff', 'warehouse_id' => $warehouse2->id]
            );
            $packersWh2[$idx]->givePermissionTo(['packing-execute', 'packing.complete', 'view-fulfillment']);
        }

        // 6. Comprehensive Egyptian Customer Profiles
        $customers = [
            User::firstOrCreate(['email' => 'cairo.client1@gmail.com'], ['name' => 'Kareem Abdelaziz', 'phone_number' => '+201011112222', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'alex.client2@gmail.com'], ['name' => 'Sara Mansour', 'phone_number' => '+201022223333', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'giza.client3@gmail.com'], ['name' => 'Hany Ramzy', 'phone_number' => '+201033334444', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'mansoura.client4@gmail.com'], ['name' => 'Nour El-Din', 'phone_number' => '+201044445555', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'tanta.client5@gmail.com'], ['name' => 'Mona Zaki', 'phone_number' => '+201055556666', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'portsaid.client6@gmail.com'], ['name' => 'Amr Diab', 'phone_number' => '+201066667777', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'ismailia.client7@gmail.com'], ['name' => 'Dina El Sherbiny', 'phone_number' => '+201077778888', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'assiut.client8@gmail.com'], ['name' => 'Tarek Lotfy', 'phone_number' => '+201088889999', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'aswan.client9@gmail.com'], ['name' => 'Salma Abu Deif', 'phone_number' => '+201099990000', 'type' => 'customer', 'password' => Hash::make('password123')]),
            User::firstOrCreate(['email' => 'zagazig.client10@gmail.com'], ['name' => 'Sherif Mounir', 'phone_number' => '+201012345678', 'type' => 'customer', 'password' => Hash::make('password123')]),
        ];

        $addresses = [
            ['street_address' => '15 Al-Ahram Street, Korba, Heliopolis', 'city' => 'Cairo', 'state' => 'Cairo', 'zip' => '11341', 'country' => 'Egypt'],
            ['street_address' => '88 Corniche Road, Camp Caesar', 'city' => 'Alexandria', 'state' => 'Alexandria', 'zip' => '21523', 'country' => 'Egypt'],
            ['street_address' => '42 Sudan Street, Mohandessin', 'city' => 'Giza', 'state' => 'Giza', 'zip' => '12611', 'country' => 'Egypt'],
            ['street_address' => '10 Gomhoria Street, Mashaya', 'city' => 'Mansoura', 'state' => 'Dakahlia', 'zip' => '35511', 'country' => 'Egypt'],
            ['street_address' => '25 El-Bahr Street, Near Stadium', 'city' => 'Tanta', 'state' => 'Gharbia', 'zip' => '31511', 'country' => 'Egypt'],
            ['street_address' => '5 Tarh El-Bahr Street', 'city' => 'Port Said', 'state' => 'Port Said', 'zip' => '42511', 'country' => 'Egypt'],
            ['street_address' => '12 Sultan Hussein Street', 'city' => 'Ismailia', 'state' => 'Ismailia', 'zip' => '41511', 'country' => 'Egypt'],
            ['street_address' => '70 Yosry Ragheb Street', 'city' => 'Assiut', 'state' => 'Assiut', 'zip' => '71511', 'country' => 'Egypt'],
            ['street_address' => '14 Abtal El-Tahrir Street', 'city' => 'Aswan', 'state' => 'Aswan', 'zip' => '81511', 'country' => 'Egypt'],
            ['street_address' => '33 Talaat Harb Street', 'city' => 'Zagazig', 'state' => 'Sharqia', 'zip' => '44511', 'country' => 'Egypt'],
        ];

        // 7. Products and Placements
        // Product 1: Simple Electronics (Headphones)
        $prodHeadset = Product::firstOrCreate(
            ['sku' => 'SKU-AUDIO-PRO'],
            [
                'name' => ['en' => 'Sony WH-1000XM5 Wireless Headphones', 'ar' => 'سماعات سوني اللاسلكية العازلة للضوضاء'],
                'slug' => 'sony-wh-1000xm5-wireless-headphones',
                'description' => ['en' => 'Industry-leading noise canceling headphones with dual processors', 'ar' => 'سماعات رأس رائدة في عزل الضوضاء بصوت استثنائي'],
                'price' => 450.00, 'product_type' => ProductType::SIMPLE, 'status' => true, 'in_stock' => true,
                'stock_quantity' => 2500, 'reserved_quantity' => 250,
            ]
        );
        $prodHeadset->update(['stock_quantity' => 2500]);
        $plHeadsetCairoA = ProductLocation::firstOrCreate(
            ['product_id' => $prodHeadset->id, 'product_variant_id' => null, 'location_id' => $locationsWh1['A-01-01']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 1250, 'allocated_hint' => 120]
        );
        $plHeadsetAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodHeadset->id, 'product_variant_id' => null, 'location_id' => $locationsWh2['AX-01-01']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 1250, 'allocated_hint' => 80]
        );

        // Product 2: Simple Tech (Keyboard)
        $prodKeyboard = Product::firstOrCreate(
            ['sku' => 'SKU-MECH-KEYBOARD'],
            [
                'name' => ['en' => 'Logitech MX Mechanical Wireless Keyboard', 'ar' => 'لوحة مفاتيح لوجيتيك ميكانيكية لاسلكية'],
                'slug' => 'logitech-mx-mechanical-wireless-keyboard',
                'description' => ['en' => 'Tactile quiet switches with smart illumination', 'ar' => 'مفاتيح ميكانيكية هادئة مع إضاءة ذكية'],
                'price' => 200.00, 'product_type' => ProductType::SIMPLE, 'status' => true, 'in_stock' => true,
                'stock_quantity' => 2000, 'reserved_quantity' => 200,
            ]
        );
        $prodKeyboard->update(['stock_quantity' => 2000]);
        $plKeyboardCairoA = ProductLocation::firstOrCreate(
            ['product_id' => $prodKeyboard->id, 'product_variant_id' => null, 'location_id' => $locationsWh1['A-01-02']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 1000, 'allocated_hint' => 100]
        );
        $plKeyboardAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodKeyboard->id, 'product_variant_id' => null, 'location_id' => $locationsWh2['AX-01-02']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 1000, 'allocated_hint' => 60]
        );

        // Product 3: Simple Flagship Smartphone
        $prodPhone = Product::firstOrCreate(
            ['sku' => 'SKU-SMARTPHONE-ULTRA'],
            [
                'name' => ['en' => 'Samsung Galaxy S24 Ultra 512GB Titanium', 'ar' => 'سامسونج جالكسي إس 24 ألترا 512 جيجا تيتانيوم'],
                'slug' => 'samsung-galaxy-s24-ultra-512gb',
                'description' => ['en' => 'Flagship smartphone with AI capabilities and Snapdragon 8 Gen 3', 'ar' => 'هاتف رائد مزود بقدرات الذكاء الاصطناعي ومعالج جبار'],
                'price' => 1300.00, 'product_type' => ProductType::SIMPLE, 'status' => true, 'in_stock' => true,
                'stock_quantity' => 1000, 'reserved_quantity' => 100,
            ]
        );
        $prodPhone->update(['stock_quantity' => 1000]);
        $plPhoneCairo = ProductLocation::firstOrCreate(
            ['product_id' => $prodPhone->id, 'product_variant_id' => null, 'location_id' => $locationsWh1['A-01-03']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 600, 'allocated_hint' => 50]
        );
        $plPhoneAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodPhone->id, 'product_variant_id' => null, 'location_id' => $locationsWh2['AX-02-01']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 400, 'allocated_hint' => 30]
        );

        // Product 4: Variable Apparel (Sizes M, L, XL)
        $prodHoodie = Product::firstOrCreate(
            ['sku' => 'SKU-HOODIE-VARSITY'],
            [
                'name' => ['en' => 'Heavyweight Fleece Winter Hoodie', 'ar' => 'هودي شتوي مبطن من الصوف الثقيل'],
                'slug' => 'heavyweight-fleece-winter-hoodie',
                'description' => ['en' => 'Warm double-stitched cotton blend hoodie', 'ar' => 'هودي مريح ودافئ بخامة قطنية ممتازة'],
                'price' => 120.00, 'product_type' => ProductType::VARIABLE, 'status' => true, 'in_stock' => true,
                'stock_quantity' => 1500, 'reserved_quantity' => 150,
            ]
        );

        $attrSize = Attribute::firstOrCreate(['name->en' => 'Size'], ['name' => ['en' => 'Size', 'ar' => 'المقاس']]);
        $valM = AttributeValue::firstOrCreate(['attribute_id' => $attrSize->id, 'value->en' => 'Medium'], ['value' => ['en' => 'Medium', 'ar' => 'متوسط']]);
        $valL = AttributeValue::firstOrCreate(['attribute_id' => $attrSize->id, 'value->en' => 'Large'], ['value' => ['en' => 'Large', 'ar' => 'كبير']]);
        $valXL = AttributeValue::firstOrCreate(['attribute_id' => $attrSize->id, 'value->en' => 'X-Large'], ['value' => ['en' => 'X-Large', 'ar' => 'كبير جداً']]);

        // Variant M
        $varM = ProductVariant::firstOrCreate(
            ['sku' => 'VAR-HOODIE-M'],
            ['product_id' => $prodHoodie->id, 'price' => 120.00, 'stock_quantity' => 500, 'reserved_quantity' => 50, 'in_stock' => true]
        );
        $varM->update(['stock_quantity' => 500]);
        AttributeProduct::firstOrCreate(['product_variant_id' => $varM->id, 'attribute_value_id' => $valM->id]);
        $plVarMCairo = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varM->id, 'location_id' => $locationsWh1['A-02-01']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 300, 'allocated_hint' => 30]
        );
        $plVarMAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varM->id, 'location_id' => $locationsWh2['AX-02-02']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 200, 'allocated_hint' => 15]
        );

        // Variant L
        $varL = ProductVariant::firstOrCreate(
            ['sku' => 'VAR-HOODIE-L'],
            ['product_id' => $prodHoodie->id, 'price' => 125.00, 'stock_quantity' => 500, 'reserved_quantity' => 50, 'in_stock' => true]
        );
        $varL->update(['stock_quantity' => 500]);
        AttributeProduct::firstOrCreate(['product_variant_id' => $varL->id, 'attribute_value_id' => $valL->id]);
        $plVarLCairo = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varL->id, 'location_id' => $locationsWh1['A-02-02']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 300, 'allocated_hint' => 30]
        );
        $plVarLAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varL->id, 'location_id' => $locationsWh2['AX-02-02']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 200, 'allocated_hint' => 15]
        );

        // Variant XL
        $varXL = ProductVariant::firstOrCreate(
            ['sku' => 'VAR-HOODIE-XL'],
            ['product_id' => $prodHoodie->id, 'price' => 130.00, 'stock_quantity' => 500, 'reserved_quantity' => 50, 'in_stock' => true]
        );
        $varXL->update(['stock_quantity' => 500]);
        AttributeProduct::firstOrCreate(['product_variant_id' => $varXL->id, 'attribute_value_id' => $valXL->id]);
        $plVarXLCairo = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varXL->id, 'location_id' => $locationsWh1['B-02-01']->id],
            ['warehouse_id' => $warehouse1->id, 'quantity' => 300, 'allocated_hint' => 30]
        );
        $plVarXLAlex = ProductLocation::firstOrCreate(
            ['product_id' => $prodHoodie->id, 'product_variant_id' => $varXL->id, 'location_id' => $locationsWh2['AX-B-01']->id],
            ['warehouse_id' => $warehouse2->id, 'quantity' => 200, 'allocated_hint' => 15]
        );

        // Order flows and statuses
        $flow = OrderFlow::first();
        $processingStatus = OrderStatus::where('code', 'processing')->first();
        $completedStatus = OrderStatus::where('code', 'completed')->first();

        // 8. Generate 240 Fulfillments: Exactly 30 per Status for All 8 Lifecycle Stages
        $stages = [
            'pending' => 30,
            'picking' => 30,
            'picked' => 30,
            'packing' => 30,
            'ready_to_ship' => 30,
            'shipped' => 30,
            'delivered' => 30,
            'cancelled' => 30,
        ];

        $couriers = ['aramex', 'bosta', 'mylerz', 'jt_express', 'dhl', 'fedex'];
        $boxTypes = ['BOX-SMALL', 'BOX-MEDIUM', 'BOX-LARGE', 'POLY-BAG-HEAVY', 'PADDED-ENVELOPE'];
        $priorities = ['urgent', 'high', 'normal', 'low', 'normal', 'high'];
        $cancelReasons = [
            'Customer requested cancellation before dispatch',
            'Out of stock at source location during picking inspection',
            'Delivery address outside courier service coverage',
            'Customer unreachable via phone confirmation',
            'Payment authorization flagged for anti-fraud review',
            'Duplicate order detected by automated fraud filter',
        ];

        $totalSeeded = 0;

        foreach ($stages as $stage => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $numStr = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $cust = $customers[($i - 1) % count($customers)];
                $addr = $addresses[($i - 1) % count($addresses)];

                // Alternate between Cairo Warehouse (70%) and Alexandria Warehouse (30%)
                $isAlexOrder = ($i % 3 === 0);
                $wh = $isAlexOrder ? $warehouse2 : $warehouse1;
                $assignedPicker = $isAlexOrder
                    ? $pickersWh2[(($i - 1) % count($pickersWh2)) + 1]
                    : $pickersWh1[(($i - 1) % count($pickersWh1)) + 1];
                $assignedPacker = $isAlexOrder
                    ? $packersWh2[(($i - 1) % count($packersWh2)) + 1]
                    : $packersWh1[(($i - 1) % count($packersWh1)) + 1];
                $stationList = $isAlexOrder ? $stationsWh2 : $stationsWh1;
                $station = $stationList[array_keys($stationList)[($i - 1) % count($stationList)]];

                $priority = $priorities[($i - 1) % count($priorities)];
                $courier = $couriers[($i - 1) % count($couriers)];
                $boxType = $boxTypes[($i - 1) % count($boxTypes)];

                // Order creation
                $orderStatus = match ($stage) {
                    'delivered' => 'completed',
                    'cancelled' => 'cancelled',
                    default => 'processing',
                };
                $orderStatusId = match ($stage) {
                    'delivered' => $completedStatus?->id,
                    default => $processingStatus?->id,
                };

                $orderNumber = "ORD-MASS-{$stage}-{$numStr}";
                $order = Order::firstOrCreate(
                    ['order_number' => $orderNumber],
                    [
                        'user_id' => $cust->id,
                        'name' => $cust->name,
                        'user_email' => $cust->email,
                        'user_phone' => $cust->phone_number,
                        'status' => $orderStatus,
                        'order_status' => 'order-' . $orderStatus,
                        'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                        'payment_method' => ($i % 3 === 0) ? 'cod' : (($i % 3 === 1) ? 'credit_card' : 'vodafone_cash'),
                        'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
                        'fulfillment_status' => $stage,
                        'shipment_status' => in_array($stage, ['shipped', 'delivered']) ? $stage : 'pending',
                        'flow_id' => $flow?->id,
                        'current_status_id' => $orderStatusId,
                        'price' => 775.00,
                        'total_price' => 775.00,
                        'currency_code' => 'EGP',
                        'base_currency_code' => 'EGP',
                        'address' => $addr,
                    ]
                );
                $order->update(['status' => $orderStatus, 'inventory_state' => Order::INVENTORY_STATE_COMMITTED]);

                // Order Items (Mix of Simple & Variants based on Warehouse Location)
                $plHeadset = $isAlexOrder ? $plHeadsetAlex : $plHeadsetCairoA;
                $plKeyboard = $isAlexOrder ? $plKeyboardAlex : $plKeyboardCairoA;
                $plVariant = $isAlexOrder ? $plVarLAlex : $plVarLCairo;

                $opHeadset = OrderProduct::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $prodHeadset->id, 'product_variant_id' => null],
                    ['product_name' => 'Sony WH-1000XM5 Wireless Headphones', 'product_sku' => $prodHeadset->sku, 'product_quantity' => 1, 'product_price' => 450.00, 'product_total_price' => 450.00]
                );
                $opKeyboard = OrderProduct::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $prodKeyboard->id, 'product_variant_id' => null],
                    ['product_name' => 'Logitech MX Mechanical Keyboard', 'product_sku' => $prodKeyboard->sku, 'product_quantity' => 1, 'product_price' => 200.00, 'product_total_price' => 200.00]
                );
                $opVariant = OrderProduct::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $prodHoodie->id, 'product_variant_id' => $varL->id],
                    ['product_name' => 'Heavyweight Fleece Winter Hoodie (L)', 'product_sku' => $varL->sku, 'product_quantity' => 1, 'product_price' => 125.00, 'product_total_price' => 125.00]
                );

                // Fulfillment Timestamps by Stage
                $now = Carbon::now();
                $pickStarted = null;
                $pickCompleted = null;
                $packStarted = null;
                $packCompleted = null;
                $readyToShipAt = null;
                $shippedAt = null;
                $deliveredAt = null;
                $cancelledAt = null;
                $cancelledBy = null;
                $cancelSource = null;

                if (in_array($stage, ['picking', 'picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $pickStarted = $now->copy()->subHours(12)->addMinutes($i * 4);
                }
                if (in_array($stage, ['picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $pickCompleted = $pickStarted->copy()->addMinutes(18);
                }
                if (in_array($stage, ['packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $packStarted = $pickCompleted->copy()->addMinutes(12);
                }
                if (in_array($stage, ['ready_to_ship', 'shipped', 'delivered'])) {
                    $packCompleted = $packStarted->copy()->addMinutes(15);
                    $readyToShipAt = $packCompleted->copy();
                }
                if (in_array($stage, ['shipped', 'delivered'])) {
                    $shippedAt = $readyToShipAt->copy()->addHours(2);
                }
                if ($stage === 'delivered') {
                    $deliveredAt = $shippedAt->copy()->addHours(16);
                }
                if ($stage === 'cancelled') {
                    $cancelledAt = $now->copy()->subHours(3)->subMinutes($i * 2);
                    $cancelledBy = ($i % 2 === 0) ? $admin->id : null;
                    $cancelSource = ($i % 2 === 0) ? 'admin' : 'customer';
                }

                $fulNumber = "FUL-MASS-{$stage}-{$numStr}";
                $fulfillment = Fulfillment::firstOrCreate(
                    ['fulfillment_number' => $fulNumber],
                    [
                        'order_id' => $order->id,
                        'warehouse_id' => $wh->id,
                        'warehouse_code' => $wh->code,
                        'warehouse_name' => $wh->name,
                        'warehouse_workflow_version_id' => $workflowVersion->id,
                        'status' => $stage,
                        'priority' => $priority,
                        'assigned_to' => in_array($stage, ['picking', 'picked']) ? $assignedPicker->id : (in_array($stage, ['packing', 'ready_to_ship', 'shipped', 'delivered']) ? $assignedPacker->id : null),
                        'picking_started_at' => $pickStarted,
                        'picking_completed_at' => $pickCompleted,
                        'packing_started_at' => $packStarted,
                        'packing_completed_at' => $packCompleted,
                        'ready_to_ship_at' => $readyToShipAt,
                        'shipped_at' => $shippedAt,
                        'delivered_at' => $deliveredAt,
                        'cancelled_at' => $cancelledAt,
                        'cancelled_by' => $cancelledBy,
                        'cancel_source' => $cancelSource,
                        'idempotency_key' => "IDEMP-FUL-MASS-{$stage}-{$numStr}",
                        'notes' => ($stage === 'cancelled')
                            ? $cancelReasons[($i - 1) % count($cancelReasons)]
                            : "Enterprise verified dataset for status: {$stage} (Record #{$numStr} at {$wh->code})",
                        'metadata' => [
                            'batch_group' => 'MASS_SEED_V2',
                            'stage_name' => $stage,
                            'customer_city' => $addr['city'],
                            'assigned_courier' => $courier,
                            'assigned_warehouse' => $wh->code,
                            'box_type' => $boxType,
                        ],
                    ]
                );
                $fulfillment->update(['status' => $stage]);

                // Fulfillment Items
                $itemStatus = match ($stage) {
                    'pending' => 'pending',
                    'picking' => ($i % 2 === 0) ? 'picked' : 'picking',
                    'picked' => 'picked',
                    'packing', 'ready_to_ship', 'shipped', 'delivered' => 'packed',
                    'cancelled' => 'cancelled',
                };
                $qtyPicked1 = in_array($itemStatus, ['picked', 'packed']) ? 1 : 0;
                $qtyPicked2 = in_array($itemStatus, ['picked', 'packed']) ? 1 : ($stage === 'picking' ? 0 : 0);
                $qtyPicked3 = in_array($itemStatus, ['picked', 'packed']) ? 1 : ($stage === 'picking' ? 1 : 0);

                $item1 = FulfillmentItem::firstOrCreate(
                    ['fulfillment_id' => $fulfillment->id, 'order_item_id' => $opHeadset->id],
                    [
                        'product_id' => $prodHeadset->id,
                        'product_variant_id' => null,
                        'product_location_id' => $plHeadset->id,
                        'quantity' => 1,
                        'quantity_picked' => $qtyPicked1,
                        'status' => $itemStatus,
                        'picked_at' => $qtyPicked1 ? $pickCompleted : null,
                        'packed_at' => in_array($stage, ['ready_to_ship', 'shipped', 'delivered']) ? $packCompleted : null,
                    ]
                );

                $item2 = FulfillmentItem::firstOrCreate(
                    ['fulfillment_id' => $fulfillment->id, 'order_item_id' => $opKeyboard->id],
                    [
                        'product_id' => $prodKeyboard->id,
                        'product_variant_id' => null,
                        'product_location_id' => $plKeyboard->id,
                        'quantity' => 1,
                        'quantity_picked' => $qtyPicked2,
                        'status' => $itemStatus,
                        'picked_at' => $qtyPicked2 ? $pickCompleted : null,
                        'packed_at' => in_array($stage, ['ready_to_ship', 'shipped', 'delivered']) ? $packCompleted : null,
                    ]
                );

                $item3 = FulfillmentItem::firstOrCreate(
                    ['fulfillment_id' => $fulfillment->id, 'order_item_id' => $opVariant->id],
                    [
                        'product_id' => $prodHoodie->id,
                        'product_variant_id' => $varL->id,
                        'product_location_id' => $plVariant->id,
                        'quantity' => 1,
                        'quantity_picked' => $qtyPicked3,
                        'status' => $itemStatus,
                        'picked_at' => $qtyPicked3 ? $pickCompleted : null,
                        'packed_at' => in_array($stage, ['ready_to_ship', 'shipped', 'delivered']) ? $packCompleted : null,
                    ]
                );

                // Picking Tasks (for picking and downstream stages)
                if (in_array($stage, ['picking', 'picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $ptStatus1 = ($qtyPicked1 === 1) ? 'picked' : 'picking';
                    $ptStatus2 = ($qtyPicked2 === 1) ? 'picked' : 'picking';
                    $ptStatus3 = ($qtyPicked3 === 1) ? 'picked' : 'picking';

                    PickingTask::firstOrCreate(
                        ['fulfillment_item_id' => $item1->id],
                        [
                            'product_location_id' => $plHeadset->id,
                            'order_id' => $order->id,
                            'order_item_id' => $opHeadset->id,
                            'claimed_by' => $assignedPicker->id,
                            'claimed_at' => $pickStarted,
                            'quantity_to_pick' => 1,
                            'quantity_picked' => $qtyPicked1,
                            'status' => $ptStatus1,
                            'picked_at' => $qtyPicked1 ? $pickCompleted : null,
                            'sequence' => 1,
                            'scan_log' => $qtyPicked1 ? [['barcode' => $prodHeadset->sku, 'timestamp' => $pickCompleted?->toIso8601String()]] : null,
                        ]
                    );

                    PickingTask::firstOrCreate(
                        ['fulfillment_item_id' => $item2->id],
                        [
                            'product_location_id' => $plKeyboard->id,
                            'order_id' => $order->id,
                            'order_item_id' => $opKeyboard->id,
                            'claimed_by' => $assignedPicker->id,
                            'claimed_at' => $pickStarted,
                            'quantity_to_pick' => 1,
                            'quantity_picked' => $qtyPicked2,
                            'status' => $ptStatus2,
                            'picked_at' => $qtyPicked2 ? $pickCompleted : null,
                            'sequence' => 2,
                            'scan_log' => $qtyPicked2 ? [['barcode' => $prodKeyboard->sku, 'timestamp' => $pickCompleted?->toIso8601String()]] : null,
                        ]
                    );

                    PickingTask::firstOrCreate(
                        ['fulfillment_item_id' => $item3->id],
                        [
                            'product_location_id' => $plVariant->id,
                            'order_id' => $order->id,
                            'order_item_id' => $opVariant->id,
                            'claimed_by' => $assignedPicker->id,
                            'claimed_at' => $pickStarted,
                            'quantity_to_pick' => 1,
                            'quantity_picked' => $qtyPicked3,
                            'status' => $ptStatus3,
                            'picked_at' => $qtyPicked3 ? $pickCompleted : null,
                            'sequence' => 3,
                            'scan_log' => $qtyPicked3 ? [['barcode' => $varL->sku, 'timestamp' => $pickCompleted?->toIso8601String()]] : null,
                        ]
                    );
                }

                // Packing Task (for packing and downstream stages)
                $packingTask = null;
                if (in_array($stage, ['packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $ptkStatus = match ($stage) {
                        'packing' => 'packing',
                        default => 'verified',
                    };
                    $packingTask = PackingTask::firstOrCreate(
                        ['fulfillment_id' => $fulfillment->id],
                        [
                            'packing_station_id' => $station->id,
                            'assigned_to' => $assignedPacker->id,
                            'status' => $ptkStatus,
                            'assigned_at' => $pickCompleted?->copy()->addMinutes(5),
                            'started_at' => $packStarted,
                            'packed_at' => $packCompleted,
                            'verified_at' => in_array($stage, ['ready_to_ship', 'shipped', 'delivered']) ? $packCompleted : null,
                            'weight' => 2.45,
                            'dimensions' => ['length' => 35, 'width' => 25, 'height' => 15],
                            'package_materials' => ['box_type' => $boxType, 'padding' => 'bubble_wrap_plus_air_pillows'],
                            'notes' => 'Checked for transit shock resistance and fragile electronic tags',
                        ]
                    );
                }

                // Packages & Package Items (for packing, ready_to_ship, shipped, delivered)
                if (in_array($stage, ['packing', 'ready_to_ship', 'shipped', 'delivered'])) {
                    $pkgStatus = match ($stage) {
                        'packing' => 'open',
                        'ready_to_ship' => 'sealed',
                        'shipped', 'delivered' => 'handed_off',
                    };
                    $package = Package::firstOrCreate(
                        ['fulfillment_id' => $fulfillment->id, 'package_number' => "PKG-{$stage}-{$numStr}"],
                        [
                            'order_id' => $order->id,
                            'packing_task_id' => $packingTask?->id,
                            'barcode' => "BAR-PKG-{$stage}-{$numStr}",
                            'status' => $pkgStatus,
                            'weight' => 2.45,
                            'dimensions' => ['length' => 35, 'width' => 25, 'height' => 15],
                            'sealed_at' => in_array($pkgStatus, ['sealed', 'handed_off']) ? $packCompleted : null,
                            'handed_off_at' => ($pkgStatus === 'handed_off') ? $shippedAt : null,
                            'notes' => 'Tamper-evident barcode secured package',
                        ]
                    );

                    PackageItem::firstOrCreate(['package_id' => $package->id, 'fulfillment_item_id' => $item1->id], ['order_item_id' => $opHeadset->id, 'quantity' => 1]);
                    PackageItem::firstOrCreate(['package_id' => $package->id, 'fulfillment_item_id' => $item2->id], ['order_item_id' => $opKeyboard->id, 'quantity' => 1]);
                    PackageItem::firstOrCreate(['package_id' => $package->id, 'fulfillment_item_id' => $item3->id], ['order_item_id' => $opVariant->id, 'quantity' => 1]);
                }

                // Shipments (for shipped and delivered stages)
                if (in_array($stage, ['shipped', 'delivered'])) {
                    $shipmentStatus = ($stage === 'delivered') ? 'delivered' : 'in_transit';
                    $trackingNumber = 'TRK-' . strtoupper($courier) . '-' . $numStr . '-' . strtoupper(substr($stage, 0, 3));

                    Shipment::firstOrCreate(
                        ['fulfillment_id' => $fulfillment->id],
                        [
                            'tracking_number' => $trackingNumber,
                            'order_id' => $order->id,
                            'courier' => $courier,
                            'status' => $shipmentStatus,
                            'shipping_method' => ($i % 2 === 0) ? 'express' : 'standard',
                            'shipping_cost' => 50.00,
                            'currency' => 'EGP',
                            'origin_address' => [
                                'street_address' => $wh->address,
                                'city' => $wh->city,
                                'country' => $wh->country,
                            ],
                            'destination_address' => $addr,
                            'items' => [
                                ['sku' => $prodHeadset->sku, 'quantity' => 1, 'name' => 'Sony WH-1000XM5 Wireless Headphones'],
                                ['sku' => $prodKeyboard->sku, 'quantity' => 1, 'name' => 'Logitech MX Mechanical Keyboard'],
                                ['sku' => $varL->sku, 'quantity' => 1, 'name' => 'Heavyweight Fleece Winter Hoodie (L)'],
                            ],
                            'total_weight' => 2.45,
                            'weight_unit' => 'kg',
                            'shipped_at' => $shippedAt,
                            'estimated_delivery_at' => $shippedAt?->copy()->addHours(24),
                            'delivered_at' => $deliveredAt,
                            'notes' => "Dispatched via {$courier} express van fleet from {$wh->name}",
                        ]
                    );
                }

                $totalSeeded++;
            }
        }

        $this->command?->info('');
        $this->command?->info("  [SUCCESS] Total {$totalSeeded} Fulfillments Seeded Across All 8 Lifecycle Stages!");
        $this->command?->info('--------------------------------------------------------------------------------');
        $this->command?->info('  SUMMARY OF SEEDED FULFILLMENTS (30 PER STATUS = 240 TOTAL):');
        $this->command?->info('  1. Pending:       30 Fulfillments (FUL-MASS-pending-001 ... FUL-MASS-pending-030)');
        $this->command?->info('  2. Picking:       30 Fulfillments (FUL-MASS-picking-001 ... FUL-MASS-picking-030)');
        $this->command?->info('  3. Picked:        30 Fulfillments (FUL-MASS-picked-001 ... FUL-MASS-picked-030)');
        $this->command?->info('  4. Packing:       30 Fulfillments (FUL-MASS-packing-001 ... FUL-MASS-packing-030)');
        $this->command?->info('  5. Ready to Ship: 30 Fulfillments (FUL-MASS-ready_to_ship-001 ... FUL-MASS-ready_to_ship-030)');
        $this->command?->info('  6. Shipped:       30 Fulfillments (FUL-MASS-shipped-001 ... FUL-MASS-shipped-030)');
        $this->command?->info('  7. Delivered:     30 Fulfillments (FUL-MASS-delivered-001 ... FUL-MASS-delivered-030)');
        $this->command?->info('  8. Cancelled:     30 Fulfillments (FUL-MASS-cancelled-001 ... FUL-MASS-cancelled-030)');
        $this->command?->info('--------------------------------------------------------------------------------');
        $this->command?->info('  WAREHOUSES INCLUDED:');
        $this->command?->info('  - WH-CAIRO-CENTRAL (Cairo Distribution Center) ~ 70% of fulfillments');
        $this->command?->info('  - WH-ALEX-LOGISTICS (Alexandria Coastal Hub)   ~ 30% of fulfillments');
        $this->command?->info('--------------------------------------------------------------------------------');
        $this->command?->info('  STAFF CREDENTIALS:');
        $this->command?->info('  Admin:         admin@gmail.com   / password123');
        $this->command?->info('  Cairo Pickers: picker1@wms.com, picker2@wms.com, picker3@wms.com / password123');
        $this->command?->info('  Cairo Packers: packer1@wms.com, packer2@wms.com, packer3@wms.com / password123');
        $this->command?->info('  Alex Pickers:  picker.alex1@wms.com, picker.alex2@wms.com / password123');
        $this->command?->info('  Alex Packers:  packer.alex1@wms.com, packer.alex2@wms.com / password123');
        $this->command?->info('================================================================================');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
