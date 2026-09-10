<?php

namespace Marvel\Listeners;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductType;
use Marvel\Services\Pricing\ProductPricingService;
use Marvel\Events\FlashSaleProcessed;


class FlashSaleProductProcess implements ShouldQueue
{
    public $queue = 'meem-medium';
    public function handle(FlashSaleProcessed $event)
    {
        $flash_sales_action = $event->action;

        if ($flash_sales_action === 'append_attached_products') {
            $this->processNewlyAddedProductInFlashSale($event->optional_data);
        }

        if ($flash_sales_action === 'remove_attached_products') {
            if (isset($event->optional_data['detached_product_ids'])) {
                // $requested_flash_sale = $event->optional_data['requested_flash_sale'];
                // $requested_flash_sale->products()->detach($event->optional_data['detached_product_ids']);
                // $requested_flash_sale->save();
                $this->unsetProductFromFlashSale($event->optional_data['detached_product_ids']);
            }
        }

        if ($flash_sales_action === 'delete_vendor_request') {
            $this->unsetProductFromFlashSale($event->optional_data['detached_products']);
        }
    }


    /**
     * processNewlyAddedProductInFlashSale
     *
     * @param  mixed $data
     * @return void
     */
    public function processNewlyAddedProductInFlashSale($data)
    {
        $pricingService = app(ProductPricingService::class);

        if (isset($data['attached_product_ids'])) {
            $current_date = date("Y-m-d");
            $start_date = Carbon::parse($data['requested_flash_sale']->start_date)->toDateString();
            $flash_sale = $data['requested_flash_sale'];

            foreach ($data['attached_product_ids'] as $key => $product_id) {
                $product = Product::where('id', '=', $product_id)->with(['variations'])->first();

                if ($current_date === $start_date) {
                    switch ($flash_sale->type) {
                        case 'percentage':
                            if ($product->product_type === ProductType::VARIABLE) {
                                foreach ($product->variations as $key => $variation) {
                                    $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
                                    Variation::where('id', $variation->id)->update(['sale_price' => $sale_price]);
                                }
                            }

                            if ($product->product_type === ProductType::VARIABLE) {
                                foreach ($product->variations as $key => $variation) {
                                    $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
                                    $variation->sale_price = $sale_price;
                                    $variation->save();
                                }
                            }

                            break;

                        case 'fixed_rate':
                            if ($product->product_type === ProductType::VARIABLE) {
                                foreach ($product->variations as $key => $variation) {
                                    $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
                                    $variation->sale_price = $sale_price;
                                    $variation->save();
                                }
                            }

                            break;
                    }
                }

                $product->has_flash_sale = true;
                $product->price_after_flash_sale = $pricingService->calculateFlashSalePrice($flash_sale, $product->price);
                $product->in_flash_sale = true;
                $product->save();
            }
        }
    }


    /**
     * unsetProductFromFlashSale
     *
     * @param  mixed $product_ids
     * @return void
     */
    public function unsetProductFromFlashSale($product_ids)
    {
        foreach ($product_ids as $key => $product_id) {
            $product = Product::where('id', '=', $product_id)->with(['variations'])->first();

            if ($product->product_type === ProductType::VARIABLE) {
                foreach ($product->variations as $key => $variation) {
                    $variation->sale_price = null;
                    $variation->save();
                }
            }

            $product->has_flash_sale = false;
            $product->price_after_flash_sale = null;
            $product->in_flash_sale = false;
            $product->save();
        }
    }
}
