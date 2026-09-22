<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Warehouse;
use App\Models\Fulfillment\Location;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Seed default warehouse and sample locations
     */
    public function run(): void
    {
        // Create main warehouse
        $warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => '123 Industrial Zone',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'status' => 'active',
            'is_default' => true,
        ]);

        // Create storage locations with priority hierarchy
        $locations = [
            // High-priority picking zone
            ['code' => 'A-01', 'name' => 'Shelf A-01', 'type' => 'shelf', 'priority' => 10],
            ['code' => 'A-02', 'name' => 'Shelf A-02', 'type' => 'shelf', 'priority' => 9],
            ['code' => 'A-03', 'name' => 'Shelf A-03', 'type' => 'shelf', 'priority' => 9],

            // Medium-priority zone
            ['code' => 'B-01', 'name' => 'Shelf B-01', 'type' => 'shelf', 'priority' => 5],
            ['code' => 'B-02', 'name' => 'Shelf B-02', 'type' => 'shelf', 'priority' => 5],

            // Low-priority bulk storage
            ['code' => 'C-01', 'name' => 'Bulk Storage C-01', 'type' => 'bin', 'priority' => 1],
            ['code' => 'C-02', 'name' => 'Bulk Storage C-02', 'type' => 'bin', 'priority' => 1],
        ];

        foreach ($locations as $location) {
            Location::create([
                'warehouse_id' => $warehouse->id,
                'code' => $location['code'],
                'name' => $location['name'],
                'type' => $location['type'],
                'status' => 'active',
                'priority' => $location['priority'],
            ]);
        }

        $this->command->info('✅ Default warehouse and 7 locations created');
    }
}
