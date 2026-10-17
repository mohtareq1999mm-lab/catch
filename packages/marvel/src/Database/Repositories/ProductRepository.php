<?php


namespace Marvel\Database\Repositories;

use App\Audit\ActivityAuditService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\Availability;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\OrderProduct;
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

    /**
     * Whitelist of product columns writable through the REST/GraphQL API.
     *
     * Inventory/accounting columns (sku, stock_quantity, reserved_quantity,
     * sold_quantity) are intentionally excluded: they are mutated only through
     * inventory services, imports, or explicit domain flows.
     */
    private const WRITABLE_FIELDS = [
        'name',
        'description',
        'price',
        'product_type',
        'item_type',
        'type_id',
        'shop_id',
        'quantity',
        'pieces',
        'status',
        'height',
        'length',
        'width',
        'weight',
        'in_stock',
        'has_discount',
        'has_flash_sale',
        'is_fast_shipping_available',
        'flash_sale_id',
        'discount_type',
        'discount_amount',
        'discount_status',
        'start_date',
        'end_date',
        'tax_enabled',
        'tax_rate',
    ];



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

            $data = $request->only(self::WRITABLE_FIELDS);

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

            // Synchronize WMS placements (multiple locations or single location).
            $this->syncProductPlacements($product->fresh(), $request, $data);

            DB::commit();
            Cache::forget('dashboard_product_analytics');
            return $product->load('variations', 'categories', 'tags', 'brands', 'banners', 'sliders', 'flash_sales');
        } catch (HttpException $e) {
            DB::rollBack();
            throw $e;
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
            $data = $request->only(self::WRITABLE_FIELDS);

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
                $incomingVariantIds = collect($variants)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

                if (!empty($incomingVariantIds)) {
                    $existingVariants = ProductVariant::where('product_id', $product->id)->lockForUpdate()->get();
                    $toDelete = $existingVariants->filter(fn ($v) => !in_array((int) $v->id, $incomingVariantIds, true));

                    if ($toDelete->isNotEmpty()) {
                        $toDeleteIds = $toDelete->pluck('id');
                        $protected = \App\Models\Fulfillment\FulfillmentItem::whereIn('product_variant_id', $toDeleteIds)->exists()
                            || OrderProduct::whereIn('product_variant_id', $toDeleteIds)->exists()
                            || \App\Models\Fulfillment\ProductLocation::whereIn('product_variant_id', $toDeleteIds)->exists()
                            || ProductVariant::whereIn('id', $toDeleteIds)->where('reserved_quantity', '>', 0)->exists();

                        if ($protected) {
                            throw new HttpException(422, self::VARIANTS_PROTECTED_MESSAGE);
                        }

                        ProductVariant::whereIn('id', $toDeleteIds)->delete();
                    }

                    foreach ($variants as $variantData) {
                        if (!empty($variantData['id']) && $existing = $existingVariants->firstWhere('id', (int) $variantData['id'])) {
                            $variantData['sale_price'] = app(ProductPricingService::class)->calculateVariantSalePrice($product, $variantData, $flashSale);
                            $existing->update($variantData);

                            if (isset($variantData['attribute_values']) && is_array($variantData['attribute_values'])) {
                                AttributeProduct::where('product_variant_id', $existing->id)->delete();
                                foreach ($variantData['attribute_values'] as $attrId) {
                                    AttributeProduct::create([
                                        'product_variant_id' => $existing->id,
                                        'attribute_value_id' => $attrId,
                                    ]);
                                }
                            }

                            $this->syncVariantPlacements($product, $existing, $variantData);
                        } else {
                            $this->addVariants($product, [$variantData], $flashSale);
                        }
                    }

                    $totalVariantStock = (int) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
                    $product->forceFill([
                        'stock_quantity' => $totalVariantStock,
                        'in_stock' => $totalVariantStock > 0,
                    ])->save();
                } else {
                    $this->assertVariantsReplaceable($product);

                    try {
                        ProductVariant::where('product_id', $product->id)->delete();
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Concurrency backstop: a protected reference (e.g. a new
                        // fulfillment/order row) pinned between the locked checks
                        // above and this delete trips the RESTRICT FK (MySQL
                        // 1451). Fail closed with the same actionable 422 instead
                        // of leaking an SQL exception as HTTP 500. Anything else
                        // is rethrown untouched.
                        if (($e->errorInfo[1] ?? null) !== 1451) {
                            throw $e;
                        }

                        throw new HttpException(422, self::VARIANTS_PROTECTED_MESSAGE);
                    }

                    $this->addVariants(
                        $product,
                        $variants,
                        $flashSale
                    );
                }
            }

            if ($request->has('images')) {
                if (!$this->updateImages($request, 'images', $product, 'products', 'products')) {
                    throw new HttpException(422, 'Images Products upload failed, please check the file format or size.');
                }
            }

            $this->syncRelation($product, $request, $data);
            $this->syncProductPlacements($product->fresh(), $request, $data);
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
     * Stable client-facing refusal when current variants cannot be replaced.
     * Plain string by repository convention (cf. image-upload 422 below):
     * never leaks SQLSTATE/table names to API clients.
     */
    private const VARIANTS_PROTECTED_MESSAGE = 'Cannot replace variants: existing variants are referenced by orders, fulfillments, or placements.';

    /**
     * Option C guard: refuse destructive variant replacement while current
     * variant rows carry protected references.
     *
     * Runs inside the caller's update transaction BEFORE any delete, holding
     * row locks on the current variant rows so concurrent placement intake
     * for those SKUs serializes behind this transaction instead of slipping
     * in between the checks and the delete. New fulfillment/order rows that
     * still win the race trip the RESTRICT FK, which the caller converts to
     * the same 422 (fail closed, never HTTP 500).
     *
     * Protection policy (evidence in FK dump 2026-10-1x, fresh-install
     * `catch_gate1_fresh`):
     * - fulfillment_items.product_variant_id RESTRICT → refuse (history).
     * - order_products.product_variant_id SET NULL → refuse (silent
     *   unattribution of paid order lines).
     * - product_locations.product_variant_id CASCADE, any row (live or
     *   zero-qty history) → refuse (silent placement/history destruction).
     * - variant reserved_quantity > 0 → refuse (active inventory state).
     * - inventory_operations.product_variant_id nullOnDelete → allowed
     *   (explicit schema-accepted unattribution); cart/wishlist/promotion
     *   refs (transient shopper state) → allowed.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (422)
     */
    private function assertVariantsReplaceable(Product $product): void
    {
        $variantIds = ProductVariant::where('product_id', $product->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($variantIds->isEmpty()) {
            return;
        }

        $protected = \App\Models\Fulfillment\FulfillmentItem::whereIn('product_variant_id', $variantIds)->exists()
            || OrderProduct::whereIn('product_variant_id', $variantIds)->exists()
            || \App\Models\Fulfillment\ProductLocation::whereIn('product_variant_id', $variantIds)->exists()
            || ProductVariant::whereIn('id', $variantIds)->where('reserved_quantity', '>', 0)->exists();

        if ($protected) {
            throw new HttpException(422, self::VARIANTS_PROTECTED_MESSAGE);
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
            $this->syncRelationAudited($product, 'categories', $request['categories'], 'product_categories_synced', 'activity.product_categories_synced');
        }

        if ($request->has('brands')) {
            $this->syncRelationAudited($product, 'brands', $request->input('brands'), 'product_brands_synced', 'activity.product_brands_synced');
        }

        if ($request->has('banners')) {
            $this->syncRelationAudited($product, 'banners', $request->input('banners'), 'product_banners_synced', 'activity.product_banners_synced');
        }

        if ($request->has('sliders')) {
            $this->syncRelationAudited($product, 'sliders', $request->input('sliders'), 'product_sliders_synced', 'activity.product_sliders_synced');
        }

        if ($request->has('tags')) {
            $this->syncRelationAudited($product, 'tags', $request->input('tags'), 'product_tags_synced', 'activity.product_tags_synced');
        }

        if (!empty($data['has_flash_sale']) && $data['has_flash_sale'] === true) {
            $flashSaleId = $data['flash_sale_id'] ?? null;

            $this->syncRelationAudited($product, 'flash_sales', $flashSaleId ? [$flashSaleId] : [], 'product_flash_sales_synced', 'activity.product_flash_sales_synced');
        }
    }

    /**
     * Sync a belongsToMany relation and emit a semantic audit event carrying
     * the old/new related IDs when they actually changed.
     */
    private function syncRelationAudited(Product $product, string $relation, ?array $newIds, string $event, string $descriptionKey): void
    {
        $relationQuery = $product->{$relation}();
        $related = $relationQuery->getRelated();

        $oldIds = $relationQuery->pluck($related->getQualifiedKeyName())
            ->map(fn ($id) => (int) $id)
            ->all();
        sort($oldIds);

        $sortedNew = array_values(array_map('intval', is_array($newIds) ? $newIds : []));
        sort($sortedNew);

        $relationQuery->sync($sortedNew);

        if ($oldIds === $sortedNew) {
            return;
        }

        ActivityAuditService::recordModel(
            $product,
            $event,
            'products',
            __($descriptionKey) ?: $event,
            old: ['ids' => $oldIds],
            new: ['ids' => $sortedNew],
        );
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

            if (!empty($variant['locations']) && is_array($variant['locations'])) {
                $locSum = 0;
                foreach ($variant['locations'] as $loc) {
                    $locSum += (float) ($loc['quantity'] ?? 0);
                }
                if (!isset($variant['quantity']) || $variant['quantity'] === null) {
                    $variant['quantity'] = (int) $locSum;
                }
                $variant['stock_quantity'] = (int) $variant['quantity'];
                $variant['in_stock'] = (int) $variant['quantity'] > 0;
            }

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

            $this->syncVariantPlacements($product, $productVariant, $variant);
        }

        $totalVariantStock = (int) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
        $product->forceFill([
            'stock_quantity' => $totalVariantStock,
            'in_stock' => $totalVariantStock > 0,
        ])->save();
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

    /**
     * Synchronize product locations and ensure total quantities match.
     * Extracts warehouse_id solely from the specified location(s).
     * Supports both single location_id and multiple locations array.
     */
    private function syncProductPlacements(Product $product, Request $request, array &$data): void
    {
        if ($product->product_type === ProductType::VARIABLE && $product->variations()->exists()) {
            return;
        }

        $hasLocations = $request->has('locations') && is_array($request->input('locations'));
        $hasSingleLocation = $request->filled('location_id');

        if (!$hasLocations && !$hasSingleLocation) {
            return;
        }

        $entries = [];
        if ($hasLocations) {
            foreach ($request->input('locations') as $entry) {
                if (isset($entry['location_id'])) {
                    $entries[] = [
                        'location_id' => (int) $entry['location_id'],
                        'quantity' => (float) ($entry['quantity'] ?? 0),
                        'warehouse_id' => $entry['warehouse_id'] ?? null,
                    ];
                }
            }
        } elseif ($hasSingleLocation) {
            $entries[] = [
                'location_id' => (int) $request->input('location_id'),
                'quantity' => (float) ($request->input('quantity') ?? $data['quantity'] ?? $product->stock_quantity ?? 0),
                'warehouse_id' => $request->input('warehouse_id'),
            ];
        }

        if (empty($entries)) {
            return;
        }

        $locIds = array_column($entries, 'location_id');
        if (count($locIds) !== count(array_unique($locIds))) {
            throw new HttpException(422, "Duplicate locations are not allowed in placements.");
        }

        $sumQuantity = 0.0;
        foreach ($entries as $e) {
            if ($e['quantity'] < 0) {
                throw new HttpException(422, __('validation.min.numeric', ['attribute' => 'location quantity', 'min' => 0]));
            }
            $sumQuantity += $e['quantity'];
        }

        // Verify that sum of quantities in locations equals total quantity
        $expectedTotal = null;
        if ($request->has('quantity') && $request->input('quantity') !== null) {
            $expectedTotal = (float) $request->input('quantity');
        } elseif (isset($data['quantity']) && $data['quantity'] !== null) {
            $expectedTotal = (float) $data['quantity'];
        }

        if ($expectedTotal !== null && abs($sumQuantity - $expectedTotal) > 0.0001) {
            throw new HttpException(422, "The sum of location quantities ({$sumQuantity}) must equal the total product quantity ({$expectedTotal}).");
        }

        // Sync total stock quantity on the product
        $data['quantity'] = (int) $sumQuantity;
        $product->forceFill([
            'stock_quantity' => (int) $sumQuantity,
            'in_stock' => $sumQuantity > 0,
        ])->save();

        // Check if existing placements have open picking tasks that prevent overwrite
        $existingPlacements = \App\Models\Fulfillment\ProductLocation::where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->get();

        $activeLocationIds = array_column($entries, 'location_id');

        foreach ($existingPlacements as $existing) {
            if (!in_array($existing->location_id, $activeLocationIds, true)) {
                $hasOpenTask = \App\Models\Fulfillment\PickingTask::where('product_location_id', $existing->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->exists();

                $hasAllocatedStock = (float) $existing->allocated_hint > 0
                    || \App\Models\Fulfillment\FulfillmentItem::where('product_location_id', $existing->id)
                        ->whereIn('status', ['pending', 'allocated', 'picking'])
                        ->exists();

                if ($hasOpenTask || $hasAllocatedStock) {
                    throw new HttpException(422, "Cannot overwrite location #{$existing->location_id}: active picking tasks exist.");
                }

                $existing->delete();
            }
        }

        // Create or update each placement entry with warehouse resolved solely from location
        $touchedPlacements = [];
        foreach ($entries as $entry) {
            $location = \App\Models\Fulfillment\Location::whereKey($entry['location_id'])->first();
            if (!$location) {
                throw new HttpException(422, "Location #{$entry['location_id']} not found.");
            }
            if ($location->status !== 'active') {
                throw new HttpException(422, "Location {$location->code} is not active.");
            }

            // Strict location & warehouse binding check:
            $expectedWarehouse = $entry['warehouse_id'] ?? $request->input('warehouse_id');
            if ($expectedWarehouse !== null && (int) $expectedWarehouse !== (int) $location->warehouse_id) {
                throw new HttpException(422, "Location #{$location->id} ({$location->code}) belongs to warehouse #{$location->warehouse_id}, not warehouse #{$expectedWarehouse}.");
            }

            try {
                app(\App\Services\Fulfillment\ProductLocationService::class)
                    ->assertLocationVacantFor($location->id, $product->id, null);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }

            $placement = \App\Models\Fulfillment\ProductLocation::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'location_id' => $location->id,
                ],
                [
                    'warehouse_id' => $location->warehouse_id,
                    'quantity' => $entry['quantity'],
                    'allocated_hint' => 0,
                ]
            );

            $touchedPlacements[] = $placement;
        }

        // Auto-allocate any pending fulfillment items for this product in these warehouses
        $stockOpService = app(\App\Services\Inventory\StockOperationService::class);
        foreach ($touchedPlacements as $placement) {
            $stockOpService->autoAllocatePendingFulfillments($placement);
        }
    }

    /**
     * Synchronize WMS placements for a specific product variant across multiple locations.
     *
     * @param Product $product
     * @param ProductVariant $variant
     * @param array $variantData
     * @return void
     * @throws HttpException
     */
    private function syncVariantPlacements(Product $product, ProductVariant $variant, array &$variantData): void
    {
        $hasLocations = isset($variantData['locations']) && is_array($variantData['locations']);
        $hasSingleLocation = isset($variantData['location_id']);

        if (!$hasLocations && !$hasSingleLocation) {
            return;
        }

        $entries = [];
        if ($hasLocations) {
            foreach ($variantData['locations'] as $entry) {
                if (isset($entry['location_id'])) {
                    $entries[] = [
                        'location_id' => (int) $entry['location_id'],
                        'quantity' => (float) ($entry['quantity'] ?? 0),
                        'warehouse_id' => $entry['warehouse_id'] ?? null,
                    ];
                }
            }
        } elseif ($hasSingleLocation) {
            $entries[] = [
                'location_id' => (int) $variantData['location_id'],
                'quantity' => (float) ($variantData['quantity'] ?? $variant->stock_quantity ?? 0),
                'warehouse_id' => $variantData['warehouse_id'] ?? null,
            ];
        }

        if (empty($entries)) {
            return;
        }

        $locIds = array_column($entries, 'location_id');
        if (count($locIds) !== count(array_unique($locIds))) {
            throw new HttpException(422, "Duplicate locations are not allowed in placements.");
        }

        $sumQuantity = 0.0;
        foreach ($entries as $e) {
            if ($e['quantity'] < 0) {
                throw new HttpException(422, __('validation.min.numeric', ['attribute' => 'variant location quantity', 'min' => 0]));
            }
            $sumQuantity += $e['quantity'];
        }

        // Verify that sum of quantities in locations equals total variant quantity
        $expectedTotal = null;
        if (isset($variantData['quantity']) && $variantData['quantity'] !== null) {
            $expectedTotal = (float) $variantData['quantity'];
        }

        if ($expectedTotal !== null && abs($sumQuantity - $expectedTotal) > 0.0001) {
            throw new HttpException(422, "The sum of location quantities ({$sumQuantity}) must equal the total variant quantity ({$expectedTotal}).");
        }

        // Sync total stock quantity on the variant
        $variantData['quantity'] = (int) $sumQuantity;
        $variantData['stock_quantity'] = (int) $sumQuantity;
        $variant->forceFill([
            'stock_quantity' => (int) $sumQuantity,
            'quantity' => (int) $sumQuantity,
            'in_stock' => $sumQuantity > 0,
        ])->save();

        // Check if existing placements have open picking tasks that prevent overwrite
        $existingPlacements = \App\Models\Fulfillment\ProductLocation::where('product_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->get();

        $activeLocationIds = array_column($entries, 'location_id');

        foreach ($existingPlacements as $existing) {
            if (!in_array($existing->location_id, $activeLocationIds, true)) {
                $hasOpenTask = \App\Models\Fulfillment\PickingTask::where('product_location_id', $existing->id)
                    ->whereIn('status', ['pending', 'assigned', 'picking'])
                    ->exists();

                $hasAllocatedStock = (float) $existing->allocated_hint > 0
                    || \App\Models\Fulfillment\FulfillmentItem::where('product_location_id', $existing->id)
                        ->whereIn('status', ['pending', 'allocated', 'picking'])
                        ->exists();

                if ($hasOpenTask || $hasAllocatedStock) {
                    throw new HttpException(422, "Cannot overwrite location #{$existing->location_id}: active picking tasks exist.");
                }

                $existing->delete();
            }
        }

        // Create or update each placement entry with warehouse resolved solely from location
        $touchedPlacements = [];
        foreach ($entries as $entry) {
            $location = \App\Models\Fulfillment\Location::whereKey($entry['location_id'])->first();
            if (!$location) {
                throw new HttpException(422, "Location #{$entry['location_id']} not found.");
            }
            if ($location->status !== 'active') {
                throw new HttpException(422, "Location {$location->code} is not active.");
            }

            // Strict location & warehouse binding check:
            $expectedWarehouse = $entry['warehouse_id'] ?? null;
            if ($expectedWarehouse !== null && (int) $expectedWarehouse !== (int) $location->warehouse_id) {
                throw new HttpException(422, "Location #{$location->id} ({$location->code}) belongs to warehouse #{$location->warehouse_id}, not warehouse #{$expectedWarehouse}.");
            }

            try {
                app(\App\Services\Fulfillment\ProductLocationService::class)
                    ->assertLocationVacantFor($location->id, $product->id, $variant->id);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }

            $placement = \App\Models\Fulfillment\ProductLocation::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'location_id' => $location->id,
                ],
                [
                    'warehouse_id' => $location->warehouse_id,
                    'quantity' => $entry['quantity'],
                    'allocated_hint' => 0,
                ]
            );

            $touchedPlacements[] = $placement;
        }

        // Auto-allocate any pending fulfillment items for this variant in these warehouses
        $stockOpService = app(\App\Services\Inventory\StockOperationService::class);
        foreach ($touchedPlacements as $placement) {
            $stockOpService->autoAllocatePendingFulfillments($placement);
        }
    }
}
