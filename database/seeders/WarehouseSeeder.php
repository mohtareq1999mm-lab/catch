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
        // Operator explicitly requested full seeding including production
        // (demo warehouse/layout fixture using non-idempotent ::create).

        $existingDefault = Warehouse::where('is_default', true)->whereNull('deleted_at')->first();
        $isDefault = ($existingDefault === null || $existingDefault->code === 'MAIN');

        // Create default warehouse
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Warehouse',
                'address' => '123 Warehouse St',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'active',
                'is_default' => $isDefault,
                'metadata' => [
                    'capacity' => 10000,
                    'contact_phone' => '+20-xxx-xxxx',
                ],
            ]
        );

        foreach ($locations as $locationData) {
            Location::firstOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'code' => $locationData['code'],
                ],
                [
                    'name' => $locationData['name'],
                    'type' => $locationData['type'],
                    'status' => 'active',
                    'priority' => $locationData['priority'],
                    'metadata' => [
                        'max_weight' => 1000,
                        'dimensions' => '2m x 1m x 3m',
                    ],
                ]
            );
        }

        $this->command?->info('✓ Created Main Warehouse with ' . count($locations) . ' locations');
    }
}
