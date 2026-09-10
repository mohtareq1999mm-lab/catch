<?php

namespace Marvel\Services\Pricing;

use Carbon\Carbon;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use App\Services\Coupon\CouponCalculator;

/**
 * Service responsible for all product pricing calculations including discounts, flash sales, and coupon pricing.
 *
 * All monetary arithmetic uses integer cents to avoid floating-point precision errors.
 */
class ProductPricingService
{
    /**
     * Calculate full pricing for a product including discount and flash sale adjustments.
     *
     * @param  Product       $product
     * @param  FlashSale|null $flashSale
     * @return array
     */
    public function calculateProductPricing(Product $product, ?FlashSale $flashSale = null): array
    {
        return $this->runSafely(function () use ($product, $flashSale): array {
            $basePrice = $this->normalizeMoney($product->price);
            $resolvedFlashSale = $flashSale ?? $this->resolveActiveFlashSale($product);
            $flashSalePrice = $this->calculateFlashSalePrice($resolvedFlashSale, $basePrice);
            $discountPrice = $flashSalePrice === null && $this->isDiscountActive($product)
                ? $this->calculateDiscountedPrice($basePrice, $product->discount_type ?? DiscountType::PERCENTAGE, $product->discount_amount ?? 0)
                : null;

            return [
                'base_price' => $basePrice,
                'price_after_discount' => $discountPrice,
                'price_after_flash_sale' => $flashSalePrice,
                'final_price' => $flashSalePrice ?? $discountPrice ?? $basePrice,
            ];
        }, [
            'base_price' => $this->normalizeMoney($product->price),
            'price_after_discount' => null,
            'price_after_flash_sale' => null,
            'final_price' => $this->normalizeMoney($product->price),
        ]);
    }

    /**
     * Calculate product pricing from raw array data (used during create/update before model persistence).
     *
     * @param  array         $data
     * @param  FlashSale|null $flashSale
     * @return array
     */
    public function calculateProductPricingFromData(array $data, ?FlashSale $flashSale = null): array
    {
        return $this->runSafely(function () use ($data, $flashSale): array {
            $basePrice = $this->normalizeMoney($data['price'] ?? null);
            $flashSalePrice = $this->calculateFlashSalePrice($flashSale, $basePrice);
            $discountPrice = $flashSalePrice === null && $this->isDiscountActiveFromData($data)
                ? $this->calculateDiscountedPrice($basePrice, $data['discount_type'] ?? DiscountType::PERCENTAGE, $data['discount_amount'] ?? 0)
                : null;

            return [
                'base_price' => $basePrice,
                'price_after_discount' => $discountPrice,
                'price_after_flash_sale' => $flashSalePrice,
                'final_price' => $flashSalePrice ?? $discountPrice ?? $basePrice,
            ];
        }, [
            'base_price' => $this->normalizeMoney($data['price'] ?? null),
            'price_after_discount' => null,
            'price_after_flash_sale' => null,
            'final_price' => $this->normalizeMoney($data['price'] ?? null),
        ]);
    }

    /**
     * Calculate the sale price for a product variant considering parent product discount and flash sale.
     *
     * @param  Product             $product
     * @param  ProductVariant|array $variation
     * @param  FlashSale|null      $flashSale
     * @return float|null
     */
    public function calculateVariantSalePrice(Product $product, ProductVariant |array $variation, ?FlashSale $flashSale = null): ?float
    {
        return $this->runSafely(function () use ($product, $variation, $flashSale): ?float {
            $basePrice = $this->normalizeMoney($variation instanceof ProductVariant ? $variation->price : ($variation['price'] ?? null));

            if ($basePrice === null) {
                return null;
            }

            $pricing = $this->calculateVariantPricingFromBase(
                $basePrice,
                $product->has_discount,
                $product->discount_type ?? DiscountType::PERCENTAGE,
                $product->discount_amount ?? 0,
                $this->isDiscountActive($product),
                $flashSale ?? $this->resolveActiveFlashSale($product)
            );

            return $pricing['final_price'];
        }, null);
    }

    /**
     * Calculate variant pricing from raw array data for both the variant and its parent product.
     *
     * @param  array         $variant
     * @param  array         $productData
     * @param  FlashSale|null $flashSale
     * @return array
     */
    public function calculateVariantPricingFromData(array $variant, array $productData, ?FlashSale $flashSale = null): array
    {
        return $this->runSafely(function () use ($variant, $productData, $flashSale): array {
            $basePrice = $this->normalizeMoney($variant['price'] ?? null);

            if ($basePrice === null) {
                return [
                    'base_price' => null,
                    'price_after_discount' => null,
                    'price_after_flash_sale' => null,
                    'final_price' => null,
                ];
            }

            return $this->calculateVariantPricingFromBase(
                $basePrice,
                !empty($productData['has_discount']),
                $productData['discount_type'] ?? DiscountType::PERCENTAGE,
                $productData['discount_amount'] ?? 0,
                $this->isDiscountActiveFromData($productData),
                $flashSale
            );
        }, [
            'base_price' => null,
            'price_after_discount' => null,
            'price_after_flash_sale' => null,
            'final_price' => null,
        ]);
    }

    /**
     * Get the current effective price for a product.
     *
     * @param  Product $product
     * @return float|null
     */
    public function calculateProductCurrentPrice(Product $product): ?float
    {
        return $this->runSafely(fn(): ?float => $this->calculateProductPricing($product)['final_price'], $this->normalizeMoney($product->price));
    }

    /**
     * Calculate the discounted price for a given coupon and base price.
     *
     * @param  Coupon $coupon
     * @param  mixed  $basePrice
     * @return float|null
     */
    public function calculateCouponPrice(Coupon $coupon, $basePrice): ?float
    {
        return $this->runSafely(function () use ($coupon, $basePrice): ?float {
            $normalizedBasePrice = $this->normalizeMoney($basePrice);

            if ($normalizedBasePrice === null) {
                return null;
            }

            $result = CouponCalculator::calculate($coupon, $normalizedBasePrice);
            return $result['finalPrice'];
        }, null);
    }

    /**
     * Find a coupon by code and calculate its discount on the given base price.
     *
     * @param  string $code
     * @param  mixed  $basePrice
     * @return float|null
     */
    public function calculateCouponPriceByCode(string $code, $basePrice): ?float
    {
        return $this->runSafely(function () use ($code, $basePrice): ?float {
            $coupon = Coupon::valid()->where('code', $code)->first();

            if (!$coupon) {
                return null;
            }

            return $this->calculateCouponPrice($coupon, $basePrice);
        }, null);
    }

    /**
     * Calculate the current price for a product variant considering active discounts and flash sales.
     *
     * @param  Product             $product
     * @param  ProductVariant|array $variation
     * @param  FlashSale|null      $flashSale
     * @return float|null
     */
    public function calculateVariantCurrentPrice(Product $product, ProductVariant|array $variation, ?FlashSale $flashSale = null): ?float
    {
        return $this->runSafely(fn(): ?float => $this->calculateVariantSalePrice($product, $variation, $flashSale), null);
    }

    /**
     * Resolve the active flash sale for a product, optionally filtering by a specific flash sale ID.
     *
     * @param  Product   $product
     * @param  int|null  $flashSaleId
     * @return FlashSale|null
     */
    public function resolveActiveFlashSale(Product $product, ?int $flashSaleId = null): ?FlashSale
    {
        return $this->runSafely(function () use ($product, $flashSaleId): ?FlashSale {
            if ($flashSaleId) {
                return FlashSale::query()
                    ->whereKey($flashSaleId)
                    ->valid()
                    ->first();
            }

            if ($product->relationLoaded('flash_sales')) {
                return $product->flash_sales
                    ->filter(fn(FlashSale $flashSale) => $flashSale->isValid())
                    ->sortByDesc('start_date')
                    ->first();
            }

            return $product->flash_sales()
                ->valid()
                ->orderBy('start_date', 'desc')
                ->first();
        }, null);
    }

    /**
     * Calculate the discounted price given a base price, discount type, and amount.
     *
     * @param  mixed  $price
     * @param  string $discountType
     * @param  float  $amount
     * @return float|null
     */
    public function calculateDiscountedPrice($price, $discountType, $amount): ?float
    {
        return $this->runSafely(function () use ($price, $discountType, $amount): ?float {
            $normalizedPrice = $this->normalizeMoney($price);

            if ($normalizedPrice === null) {
                return null;
            }

            $discountType = $discountType ?: DiscountType::PERCENTAGE;
            $amount = max(0, (float) $amount);
            $priceCents = $this->toCents($normalizedPrice);

            if ($discountType === DiscountType::PERCENTAGE) {
                $amount = min($amount, 100);
                $discountCents = (int) round($priceCents * $amount / 100);

                return $this->fromCents(max(0, $priceCents - $discountCents));
            }

            if ($discountType === DiscountType::FIXED_RATE || $discountType === 'fixed') {
                $discountCents = $this->toCents($amount);

                return $this->fromCents(max(0, $priceCents - $discountCents));
            }

            return $this->fromCents($priceCents);
        }, null);
    }

    /**
     * Calculate the flash sale discounted price for a given base price.
     *
     * @param  FlashSale|null $flashSale
     * @param  mixed          $basePrice
     * @return float|null
     */
    public function calculateFlashSalePrice(?FlashSale $flashSale, $basePrice): ?float
    {
        return $this->runSafely(function () use ($flashSale, $basePrice): ?float {
            $normalizedBasePrice = $this->normalizeMoney($basePrice);

            if ($flashSale === null || $normalizedBasePrice === null || !$this->isFlashSaleActive($flashSale)) {
                return null;
            }

            $baseCents = $this->toCents($normalizedBasePrice);
            $discountCents = $this->resolveFlashSaleDiscountCents($flashSale, $baseCents);

            if ($discountCents === null) {
                return $this->fromCents($baseCents);
            }

            return $this->fromCents(max(0, $baseCents - $discountCents));
        }, null);
    }

    /**
     * Calculate variant pricing from a base price using product-level discount and flash sale information.
     *
     * @param  float        $basePrice
     * @param  mixed        $hasDiscount
     * @param  mixed        $discountType
     * @param  mixed        $discountAmount
     * @param  bool         $discountActive
     * @param  FlashSale|null $flashSale
     * @return array
     */
    private function calculateVariantPricingFromBase(
        float $basePrice,
        $hasDiscount,
        $discountType,
        $discountAmount,
        bool $discountActive,
        ?FlashSale $flashSale
    ): array {
        $flashSalePrice = $this->calculateFlashSalePrice($flashSale, $basePrice);
        $discountPrice = $flashSalePrice === null && $discountActive && $hasDiscount
            ? $this->calculateDiscountedPrice($basePrice, $discountType, $discountAmount)
            : null;

        return [
            'base_price' => $basePrice,
            'price_after_discount' => $discountPrice,
            'price_after_flash_sale' => $flashSalePrice,
            'final_price' => $flashSalePrice ?? $discountPrice ?? $basePrice,
        ];
    }

    /**
     * Resolve the discount amount in cents for a flash sale based on its type.
     *
     * @param  FlashSale $flashSale
     * @param  int       $baseCents
     * @return int|null
     */
    private function resolveFlashSaleDiscountCents(FlashSale $flashSale, int $baseCents): ?int
    {
        $discountValue = max(0, (float) ($flashSale->discount ?? 0));
        $maxDiscountCents = $flashSale->max_discount_amount !== null
            ? $this->toCents($flashSale->max_discount_amount)
            : null;

        if ($flashSale->type === FlashSaleType::PERCENTAGE) {
            $percentDiscountCents = (int) round($baseCents * $discountValue / 100);

            return $maxDiscountCents === null
                ? $percentDiscountCents
                : min($percentDiscountCents, $maxDiscountCents);
        }

        if ($flashSale->type === FlashSaleType::FIXED_RATE) {
            return $this->toCents($discountValue);
        }

        if ($flashSale->type === FlashSaleType::FINAL_PRICE) {
            $finalPriceCents = $this->toCents($discountValue);

            return max(0, $baseCents - $finalPriceCents);
        }

        return null;
    }

    /**
     * Check whether a flash sale is currently active based on its status and date range.
     *
     * @param  FlashSale|null $flashSale
     * @return bool
     */
    private function isFlashSaleActive(?FlashSale $flashSale): bool
    {
        if (!$flashSale || !$flashSale->status) {
            return false;
        }

        $today = Carbon::today();

        if ($flashSale->start_date && Carbon::parse($flashSale->start_date)->gt($today)) {
            return false;
        }

        if ($flashSale->end_date && Carbon::parse($flashSale->end_date)->lt($today)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a product's discount is currently active based on its status and date range.
     *
     * @param  Product $product
     * @return bool
     */
    public function isDiscountActive($product): bool
    {
        if (!$product || empty($product->has_discount)) {
            return false;
        }

        if (isset($product->discount_status) && $product->discount_status === false) {
            return false;
        }

        $today = Carbon::now();

        if (!empty($product->start_date) && Carbon::parse($product->start_date)->gt($today)) {
            return false;
        }

        if (!empty($product->end_date) && Carbon::parse($product->end_date)->lt($today)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a discount is active based on raw array data (used before model persistence).
     *
     * @param  array $data
     * @return bool
     */
    private function isDiscountActiveFromData(array $data): bool
    {
        if (empty($data['has_discount'])) {
            return false;
        }

        if (array_key_exists('discount_status', $data)) {
            $discountStatus = $data['discount_status'];
            if ($discountStatus === null) {
                // null means not explicitly set - proceed normally
            } elseif (is_string($discountStatus)) {
                $discountStatus = filter_var($discountStatus, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif (is_numeric($discountStatus)) {
                $discountStatus = (bool) $discountStatus;
            }
            if ($discountStatus === false) {
                return false;
            }
        }

        $today = Carbon::now();

        if (!empty($data['start_date']) && Carbon::parse($data['start_date'])->gt($today)) {
            return false;
        }

        if (!empty($data['end_date']) && Carbon::parse($data['end_date'])->lt($today)) {
            return false;
        }

        return true;
    }

    /**
     * Normalize a monetary value to a float with 2 decimal places, returning null for empty values.
     *
     * @param  mixed $amount
     * @return float|null
     */
    private function normalizeMoney($amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return round((float) $amount, 2);
    }

    /**
     * Execute a callback safely, returning a fallback value on exception.
     *
     * @param  callable $callback
     * @param  mixed    $fallback
     * @return mixed
     */
    private function runSafely(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $throwable) {
            report($throwable);

            return $fallback;
        }
    }

    /**
     * Convert a monetary value to its cent (integer) representation.
     *
     * @param  mixed $amount
     * @return int
     */
    private function toCents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Convert cents back to a float dollar representation rounded to 2 decimal places.
     *
     * @param  int $cents
     * @return float
     */
    private function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
