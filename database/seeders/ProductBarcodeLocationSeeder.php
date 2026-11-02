<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\ProductBarcode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

/**
 * Enterprise-grade seeder binding Products, Barcodes, and Warehouse Locations.
 *
 * Scope & Capabilities:
 * 1. Generates and assigns unique 13-digit GS1 EAN-13 compliant piece & carton barcodes
 *    to all catalog products in `product_barcodes`.
 * 2. Provisions organized, placeable picking bins in the primary active warehouse.
 * 3. Binds each product and variant to dedicated picking locations in `product_locations`
 *    with positive stock and zero allocated hint, ready for allocation.
 * 4. Ensures simple and variable products have adequate stock and active status
 *    to facilitate seamless end-to-end WMS workflows (Fulfillment -> Picking -> Packing -> Sealing).
 *
 * Fully idempotent and safe for repeated execution.
 */
class ProductBarcodeLocationSeeder extends Seeder
{
    /**
     * Standard carton packaging capacity.
     */
    public const DEFAULT_PIECES_PER_CARTON = 12;

    /**
     * Default baseline quantity allocated per bin placement.
     */
    public const DEFAULT_PLACEMENT_QUANTITY = 150.00;

    /**
     * High picking priority for fast-pick bin allocation.
     */
    public const PICKING_BIN_PRIORITY = 20;

    public function run(): void
    {
        $this->command?->info('====================================================');
        $this->command?->info('Starting Product, Barcode & Location Binding Seeder');
        $this->command?->info('====================================================');

        // 1. Resolve or provision primary active warehouse
        $warehouse = Warehouse::where('is_default', true)->where('status', 'active')->first()
            ?? Warehouse::where('status', 'active')->first();

        if (!$warehouse) {
            $warehouse = Warehouse::firstOrCreate(
                ['code' => 'WH-CAIRO-CENTRAL'],
                [
                    'name' => 'Central Hub - Cairo Distribution Center',
                    'is_default' => true,
                    'status' => 'active',
                ]
            );
        }

        $this->command?->info("Target Warehouse: [#{$warehouse->id}] {$warehouse->name} ({$warehouse->code})");

        // 2. Fetch all products with variants
        $products = Product::with('variations')->orderBy('id')->get();
        if ($products->isEmpty()) {
            $this->command?->warn('No products found in the database. Please seed products first.');
            return;
        }

        $totalProducts = $products->count();
        $barcodesSeeded = 0;
        $locationsSeeded = 0;
        $placementsSeeded = 0;

        DB::transaction(function () use (
            $products,
            $warehouse,
            &$barcodesSeeded,
            &$locationsSeeded,
            &$placementsSeeded
        ) {
            foreach ($products as $index => $product) {
                // A. Generate unique valid 13-digit EAN-13 barcodes
                // Piece prefix: '6221' (Egypt GS1 + piece indicator 1)
                // Carton prefix: '6222' (Egypt GS1 + carton indicator 2)
                $pieceBarcode = $this->generateEan13('6221' . sprintf('%08d', $product->id));
                $cartonBarcode = $this->generateEan13('6222' . sprintf('%08d', $product->id));

                ProductBarcode::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'piece_barcode' => $pieceBarcode,
                        'carton_barcode' => $cartonBarcode,
                        'pieces_per_carton' => self::DEFAULT_PIECES_PER_CARTON,
                    ]
                );
                $barcodesSeeded++;

                // B. Ensure product has sellable stock and active status
                $productStock = max((int) $product->stock_quantity, 150);
                $product->update([
                    'status' => true,
                    'in_stock' => true,
                    'stock_quantity' => $productStock,
                ]);

                // C. Provision Picking Location & Placements
                if ($product->variations->isNotEmpty()) {
                    // Variable product: each variant gets its own dedicated picking bin
                    // to uphold the "one SKU per bin" inventory rule.
                    foreach ($product->variations as $vIdx => $variant) {
                        $locCode = sprintf('PICK-P%03d-V%02d', $product->id, $vIdx + 1);
                        $locBarcode = sprintf('LOC-PICK-P%03d-V%02d', $product->id, $vIdx + 1);
                        $locName = "موقع التقاط - {$product->name} (" . ($variant->title ?: "خيار " . ($vIdx + 1)) . ")";

                        $location = Location::firstOrCreate(
                            [
                                'warehouse_id' => $warehouse->id,
                                'code' => $locCode,
                            ],
                            [
                                'barcode' => $locBarcode,
                                'name' => $locName,
                                'type' => Location::TYPE_PICKING,
                                'status' => Location::STATUS_ACTIVE,
                                'priority' => self::PICKING_BIN_PRIORITY,
                            ]
                        );

                        if (!$location->barcode) {
                            $location->update(['barcode' => $locBarcode]);
                        }
                        $locationsSeeded++;

                        // Ensure variant stock
                        $variant->update([
                            'stock_quantity' => max((int) $variant->stock_quantity, 150),
                        ]);

                        // Seed or refresh placement
                        $placement = ProductLocation::firstOrCreate(
                            [
                                'product_id' => $product->id,
                                'product_variant_id' => $variant->id,
                                'location_id' => $location->id,
                            ],
                            [
                                'warehouse_id' => $warehouse->id,
                                'quantity' => self::DEFAULT_PLACEMENT_QUANTITY,
                                'allocated_hint' => 0.00,
                            ]
                        );

                        if ($placement->quantity < 50.00) {
                            $placement->update([
                                'quantity' => self::DEFAULT_PLACEMENT_QUANTITY,
                                'allocated_hint' => 0.00,
                            ]);
                        }
                        $placementsSeeded++;
                    }
                } else {
                    // Simple product: dedicated picking bin
                    $locCode = sprintf('PICK-P%03d', $product->id);
                    $locBarcode = sprintf('LOC-PICK-P%03d', $product->id);
                    $locName = "موقع التقاط - {$product->name}";

                    $location = Location::firstOrCreate(
                        [
                            'warehouse_id' => $warehouse->id,
                            'code' => $locCode,
                        ],
                        [
                            'barcode' => $locBarcode,
                            'name' => $locName,
                            'type' => Location::TYPE_PICKING,
                            'status' => Location::STATUS_ACTIVE,
                            'priority' => self::PICKING_BIN_PRIORITY,
                        ]
                    );

                    if (!$location->barcode) {
                        $location->update(['barcode' => $locBarcode]);
                    }
                    $locationsSeeded++;

                    // Seed or refresh placement
                    $placement = ProductLocation::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'location_id' => $location->id,
                        ],
                        [
                            'warehouse_id' => $warehouse->id,
                            'quantity' => self::DEFAULT_PLACEMENT_QUANTITY,
                            'allocated_hint' => 0.00,
                        ]
                    );

                    if ($placement->quantity < 50.00) {
                        $placement->update([
                            'quantity' => self::DEFAULT_PLACEMENT_QUANTITY,
                            'allocated_hint' => 0.00,
                        ]);
                    }
                    $placementsSeeded++;
                }
            }
        });

        $this->command?->info("✓ Processed {$totalProducts} products successfully.");
        $this->command?->info("✓ Barcodes assigned/verified: {$barcodesSeeded}");
        $this->command?->info("✓ Picking locations created/verified: {$locationsSeeded}");
        $this->command?->info("✓ Product placements verified: {$placementsSeeded}");
        $this->command?->info('All products are now linked to barcodes and warehouse picking locations.');
    }

    /**
     * Generate standard 13-digit EAN-13 barcode from a 12-digit base.
     */
    protected function generateEan13(string $twelveDigits): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelveDigits[$i];
            $sum += ($i % 2 === 0) ? $digit * 1 : $digit * 3;
        }
        $checksum = (10 - ($sum % 10)) % 10;

        return $twelveDigits . $checksum;
    }
}

