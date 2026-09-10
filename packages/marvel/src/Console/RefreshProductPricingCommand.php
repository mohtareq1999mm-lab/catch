<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Product;
use Marvel\Services\Pricing\ProductPricingService;

class RefreshProductPricingCommand extends Command
{
    protected $signature = 'marvel:refresh-product-pricing {--chunk=100 : Number of products to process per batch}';

    protected $description = 'Refresh product, discount, and flash sale pricing for all products';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $pricingService = app(ProductPricingService::class);
        $updatedProducts = 0;
        $updatedVariants = 0;
        $failedProducts = 0;

        Product::query()
            ->with(['flash_sales', 'variations'])
            ->chunkById($chunkSize, function ($products) use ($pricingService, &$updatedProducts, &$updatedVariants, &$failedProducts) {
                foreach ($products as $product) {
                    try {
                        $flashSale = $product->getActiveFlashSale();
                        $pricing = $pricingService->calculateProductPricing($product, $flashSale);

                        $product->updateQuietly([
                            'price_after_discount' => $pricing['price_after_discount'],
                            'price_after_flash_sale' => $pricing['price_after_flash_sale'],
                        ]);

                        $updatedProducts++;

                        foreach ($product->variations as $variation) {
                            $variationSalePrice = $pricingService->calculateVariantSalePrice($product, $variation, $flashSale);

                            $variation->updateQuietly([
                                'sale_price' => $variationSalePrice,
                            ]);

                            $updatedVariants++;
                        }
                    } catch (\Throwable $throwable) {
                        $failedProducts++;
                        report($throwable);
                        $this->warn("Failed to refresh pricing for product ID {$product->id}: {$throwable->getMessage()}");
                    }
                }
            });

        $this->info("Pricing refresh completed: {$updatedProducts} products, {$updatedVariants} variants updated, {$failedProducts} products failed.");

        return self::SUCCESS;
    }
}
