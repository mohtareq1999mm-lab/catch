<?php


namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\Availability;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Resource;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\Variation;
use Marvel\Enums\ProductType;
use Marvel\Traits\MediaManager;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Marvel\Services\Pricing\ProductPricingService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Exceptions\MarvelException;

/**
 * Repository for Product model operations including CRUD, pricing, availability, and CSV import/export.
 */
class ProductRepository extends BaseRepository
{

    use MediaManager;



    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Product::class;
    }




    /**
     * storeProduct
     *
     * @param  mixed $request
     * @return mixed
     */
    public function storeProduct(Request $request)
    {
        try {
            DB::beginTransaction();
            $variants = $request->input('variants', []);

            $request->merge([
                'product_type' => !empty($variants)
                    ? ProductType::VARIABLE
                    : ProductType::SIMPLE
            ]);

            $data = $request->except(['images', 'categories', 'variants', 'brands', 'banners', 'sliders']);

            $data['slug'] = $this->makeSlug($request);
            $hasFlashSale = !empty($data['has_flash_sale']);
            $flashSaleId = $data['flash_sale_id'] ?? null;
            $flashSale = $this->resolveFlashSale($flashSaleId, null, $hasFlashSale);
            $pricing = app(ProductPricingService::class)->calculateProductPricingFromData($data, $flashSale);
            $data['price_after_discount'] = $pricing['price_after_discount'];
            $data['price_after_flash_sale'] = $pricing['price_after_flash_sale'];

            $product = $this->create($data);

            if (!empty($variants)) {
                $this->addVariants(
                    $product,
                    $variants,
                    $flashSale
                );
            }

            if ($request->has('images')) {
                if (!$this->uploadImages($request, 'images', $product, 'products', 'products')) {
                    throw new HttpException(422, 'Images Products upload failed, please check the file format or size.');
                }
            }

            $this->syncRelation($product, $request, $data);
            DB::commit();
            Cache::forget('dashboard_product_analytics');
            return $product->load('variations', 'categories', 'tags', 'brands', 'banners', 'sliders', 'flash_sales');
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }


    public function updateProduct(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $product = Product::findOrFail($id);
            $variants = $request->input('variants', []);



            $request->merge([
                'product_type' => !empty($variants)
                    ? ProductType::VARIABLE
                    : ProductType::SIMPLE
            ]);
            $data = $request->except(['images', 'categories', 'variants', 'brands', 'banners', 'sliders']);

            // D5 — item_type immutability once commercial history exists.
            if (array_key_exists('item_type', $data)) {
                app(\App\Services\Digital\ItemTypePolicy::class)->assertChangeable($product, $data['item_type']);
            }

            $data['slug'] = $this->makeSlug($request, 'slug', $product->id);
            $hasFlashSale = array_key_exists('has_flash_sale', $data) ? (bool) $data['has_flash_sale'] : $product->has_flash_sale;
            $flashSaleId = $data['flash_sale_id'] ?? null;
            $flashSale = $this->resolveFlashSale($flashSaleId, $product, $hasFlashSale);
            $pricing = app(ProductPricingService::class)->calculateProductPricingFromData($data + $product->only([
                'price',
                'has_discount',
                'discount_type',
                'discount_amount',
                'discount_status',
                'start_date',
                'end_date',
            ]), $flashSale);
            $data['price_after_discount'] = $pricing['price_after_discount'];
            $data['price_after_flash_sale'] = $pricing['price_after_flash_sale'];

            $product->update($data);

            if (!empty($variants)) {
                ProductVariant::where('product_id', $product->id)->delete();

                $this->addVariants(
                    $product,
                    $variants,
                    $flashSale
                );
            }

            if ($request->has('images')) {
                if (!$this->updateImages($request, 'images', $product, 'products', 'products')) {
                    throw new HttpException(422, 'Images Products upload failed, please check the file format or size.');
                }
            }

            $this->syncRelation($product, $request, $data);
            DB::commit();

            return $product->load('variations', 'categories', 'tags', 'brands', 'banners', 'sliders', 'flash_sales');
        } catch (HttpException $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }

    /**
     * Sync product relations such as categories, brands, banners, sliders and flash sales.
     *
     * @param  Product $product
     * @param  Request $request
     * @param  array   $data
     * @return void
     */
    private function syncRelation($product, $request, $data)
    {
        if (isset($request['categories'])) {
            $product->categories()->sync($request['categories']);
        }

        if ($request->has('brands')) {
            $product->brands()->sync($request->input('brands'));
        }

        if ($request->has('banners')) {
            $product->banners()->sync($request->input('banners'));
        }

        if ($request->has('sliders')) {
            $product->sliders()->sync($request->input('sliders'));
        }

        if ($request->has('tags')) {
            $product->tags()->sync($request->input('tags'));
        }

        if (!empty($data['has_flash_sale']) && $data['has_flash_sale'] === true) {
            $flashSaleId = $data['flash_sale_id'] ?? null;

            if ($flashSaleId) {
                $product->flash_sales()->sync([$flashSaleId]);
            } else {
                $product->flash_sales()->detach();
            }
        }
    }

    /**
     * Create product variants and their attribute value associations.
     *
     * @param  Product      $product
     * @param  array        $variants
     * @param  FlashSale|null $flashSale
     * @return bool|void
     */
    private function addVariants(
        $product,
        $variants,
        $flashSale
    ): void {
        foreach ($variants as $variant) {
            $variant['product_id'] = $product->id;
            $variant['sale_price'] = app(ProductPricingService::class)->calculateVariantSalePrice($product, $variant, $flashSale);
            $productVariant = ProductVariant::create($variant);
            if (!$productVariant) {
                throw new HttpException(500, 'Failed to create product variant');
            }

            if (!empty($variant['attribute_values'])) {
                foreach ($variant['attribute_values'] as $attributeValueId) {
                    $created = AttributeProduct::create([
                        'product_variant_id' => $productVariant->id,
                        'attribute_value_id' => $attributeValueId,
                    ]);
                    if (!$created) {
                        throw new HttpException(500, 'Failed to create attribute product association');
                    }
                }
            }
        }
    }


    public function getBestSellingProducts($request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined' ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (ModelNotFoundException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }

        $products_query = Product::leftJoin('order_product', 'order_product.product_id', 'products.id')
            ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
            ->with(['type', 'shop'])
            ->selectRaw('products.*, sum(order_product.order_quantity) total_sales')
            ->where('orders.parent_id', null)
            ->where('orders.order_status', 'order-completed')
            ->where('orders.language', $language)
            ->groupBy('order_product.product_id')
            ->orderBy('total_sales', 'desc');

        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }
        if ($range) {
            $range = (int) $range;
            $products_query = $products_query->whereDate('created_at', '>', Carbon::now()->subDays($range));
        }
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        return $products_query->take($limit)->get();
    }

    public function fetchRelated($id, $limit = 10)
    {
        try {
            $product = $this->findOrFail($id);
            $categories = $product->categories->pluck('id');

            return $this->whereHas('categories', function ($query) use ($categories) {
                $query->whereIn('categories.id', $categories);
            })
                ->where('id', '!=', $id)
                ->limit($limit)->get() ?? collect();
        } catch (Exception $e) {
            return [];
        }
    }

    public function getUnavailableProducts($from, $to)
    {
        $_blockedDates = Availability::whereDate('from', '<=', $from)
            ->whereDate('to', '>=', $to)
            ->get()->groupBy('product_id');

        $unavailableProducts = [];

        foreach ($_blockedDates as $productId => $date) {
            if (!$this->isProductAvailableAt($from, $to, $productId, $date)) {
                $unavailableProducts[] = $productId;
            }
        }
        return $unavailableProducts;
    }

    public function isProductAvailableAt($from, $to, $productId, $_blockedDates, $requestedQuantity = 1)
    {
        $quantity = 0;
        try {
            $product = Product::findOrFail($productId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY(), Boundaries::EXCLUDE_END());
            $range = Period::make($from, $to, Precision::DAY(), Boundaries::EXCLUDE_END());
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return (int) $product->quantity - (int) $quantity >= (int) $requestedQuantity;
    }


    public function fetchBlockedDatesForAProductInRange($from, $to, $productId)
    {
        return Availability::where('product_id', $productId)->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function fetchBlockedDatesForAVariationInRange($from, $to, $variation_id)
    {
        return Availability::where('bookable_id', $variation_id)->where('bookable_type', 'Marvel\Database\Models\Variation')->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function isVariationAvailableAt($from, $to, $variationId, $_blockedDates, $requestedQuantity)
    {
        $quantity = 0;
        try {
            $variation = Variation::findOrFail($variationId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY(), Boundaries::EXCLUDE_END());
            $range = Period::make($from, $to, Precision::DAY(), Boundaries::EXCLUDE_END());
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return $variation->quantity - $quantity >= $requestedQuantity;
    }


    public function calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features)
    {
        $price = 0;
        $person_price = 0;
        $deposit_price = 0;
        $feature_price = 0;
        $dropoff_location_price = 0;
        $pickup_location_price = 0;

        if ($variation_id) {
            $variation_price = $this->calculateVariationPrice($variation_id);
            $price += $variation_price * $bookedDay * $quantity;
        } else {
            $product_price = $this->calculateProductPrice($product_id);
            $price += $product_price * $bookedDay * $quantity;
        }
        if ($dropoff_location_id) {
            $dropoff_location_price = $this->calculateLocationPrice($dropoff_location_id);
        }
        if ($pickup_location_id) {
            $pickup_location_price = $this->calculateLocationPrice($pickup_location_id);
        }
        if ($features) {
            $feature_price = $this->calculateResourcePrice($features);
        }
        if ($persons) {
            $person_price = $this->calculateResourcePrice($persons);
        }
        if ($deposits) {
            $deposit_price = $this->calculateResourcePrice($deposits);
        }

        return [
            'totalPrice' => $price + $person_price + $deposit_price + $feature_price + $dropoff_location_price + $pickup_location_price,
            'personPrice' => $person_price,
            'depositPrice' => $deposit_price,
            'featurePrice' => $feature_price,
            'dropoffLocationPrice' => $dropoff_location_price,
            'pickupLocationPrice' => $pickup_location_price
        ];
    }

    public function calculateProductPrice($product_id)
    {
        try {
            $product = Product::with('flash_sales')->findOrFail($product_id);
        } catch (\Throwable $th) {
            throw $th;
        }

        return app(ProductPricingService::class)->calculateProductCurrentPrice($product);
    }

    public function calculateVariationPrice($variation_id)
    {
        try {
            $variation = Variation::with(['product.flash_sales'])->findOrFail($variation_id);
        } catch (\Throwable $th) {
            throw $th;
        }

        return app(ProductPricingService::class)->calculateVariantCurrentPrice($variation->product, $variation);
    }

    public function calculateLocationPrice($location_id)
    {
        try {
            $location = Resource::findOrFail($location_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $location->price;
    }

    public function calculateResourcePrice($resources)
    {
        $price = 0;
        foreach ($resources as $resource_id) {
            try {
                $resource = Resource::findOrFail($resource_id);
            } catch (\Throwable $th) {
                throw $th;
            }
            if ($resource->price !== null) {
                $price += $resource->price;
            }
        }
        return $price;
    }

    public function customSlugify($text, string $divider = '-')
    {
        $slug = preg_replace('~[^\pL\d]+~u', $divider, $text);
        $slugCount = Product::where('slug', $slug)->orWhere('slug', 'like', $slug . '%')->count();

        if (empty($slugCount)) {
            return $slug;
        }

        return $slug . $divider . $slugCount;
    }

    /**
     * Calculate the discounted price using the ProductPricingService.
     *
     * @param  mixed  $price
     * @param  string $discountType
     * @param  float  $amount
     * @return float|null
     */
    private function calculateDiscountedPrice($price, $discountType, $amount)
    {
        return app(ProductPricingService::class)->calculateDiscountedPrice($price, $discountType, $amount);
    }

    /**
     * Resolve the active flash sale for a product by ID or from its relations.
     *
     * @param  mixed       $flashSaleId
     * @param  Product|null $product
     * @param  bool        $hasFlashSale
     * @return FlashSale|null
     */
    private function resolveFlashSale($flashSaleId, $product, $hasFlashSale)
    {
        if (!$hasFlashSale) {
            return null;
        }

        if (!empty($flashSaleId)) {
            return FlashSale::query()->whereKey($flashSaleId)->valid()->first();
        }

        if ($product instanceof Product) {
            return $product->flash_sales()->valid()->orderBy('start_date', 'desc')->first();
        }

        return null;
    }

    /**
     * Calculate the flash sale price using the ProductPricingService.
     *
     * @param  FlashSale $flashSale
     * @param  mixed     $basePrice
     * @return float|null
     */
    private function calculateFlashSalePrice($flashSale, $basePrice)
    {
        return app(ProductPricingService::class)->calculateFlashSalePrice($flashSale, $basePrice);
    }
}
