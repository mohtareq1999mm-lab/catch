<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default warehouse
        $warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => '123 Warehouse St',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'status' => 'active',
            'is_default' => true,
            'metadata' => [
                'capacity' => 10000,
                'contact_phone' => '+20-xxx-xxxx',
            ],
        ]);

        // Create sample locations with different priorities. Types MUST be
        // placeable (Location::PLACEABLE_TYPES) or allocation will ignore
        // them via scopePlaceable.
        $locations = [
            ['code' => 'A-01', 'name' => 'Aisle A - Shelf 01', 'type' => 'storage', 'priority' => 10],
            ['code' => 'A-02', 'name' => 'Aisle A - Shelf 02', 'type' => 'storage', 'priority' => 9],
            ['code' => 'A-03', 'name' => 'Aisle A - Shelf 03', 'type' => 'storage', 'priority' => 8],
            ['code' => 'B-01', 'name' => 'Aisle B - Shelf 01', 'type' => 'storage', 'priority' => 7],
            ['code' => 'B-02', 'name' => 'Aisle B - Shelf 02', 'type' => 'storage', 'priority' => 6],
            ['code' => 'BULK-01', 'name' => 'Bulk Storage Zone 01', 'type' => 'storage', 'priority' => 5],
            ['code' => 'PICK-01', 'name' => 'Fast Pick Zone 01', 'type' => 'picking', 'priority' => 15],
        ];

        foreach ($locations as $locationData) {
            Location::create([
                'warehouse_id' => $warehouse->id,
                'code' => $locationData['code'],
                'name' => $locationData['name'],
                'type' => $locationData['type'],
                'status' => 'active',
                'priority' => $locationData['priority'],
                'metadata' => [
                    'max_weight' => 1000,
                    'dimensions' => '2m x 1m x 3m',
                ],
            ]);
        }

        $this->command->info('✓ Created Main Warehouse with ' . count($locations) . ' locations');
    }
}
