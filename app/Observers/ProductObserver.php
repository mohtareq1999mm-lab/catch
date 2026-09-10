<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Events\ProductBackInStock;
use App\Events\ProductDiscountChanged;
use App\Events\ProductPriceDrop;
use App\Jobs\LogActivityJob;
use App\Services\General\ProductEngine\ProductStrategyResolver;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Product;

class ProductObserver
{
    use HasCache;

    public function created(Product $product): void
    {
        $this->flushProductCaches();

        LogActivityJob::dispatch(
            get_class($product),
            $product->id,
            Auth::id(),
            'created',
            'products',
            __('activity.product_created'),
        );
    }

    public function updated(Product $product): void
    {
        $dirty = $product->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $this->flushProductCaches();

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $product->getOriginal('status');
            $newStatus = $product->status;
            $description = $newStatus
                ? __('activity.product_activated')
                : __('activity.product_deactivated');
            $description = $description ?: ($newStatus ? 'Product activated' : 'Product deactivated');

            LogActivityJob::dispatch(
                get_class($product),
                $product->id,
                Auth::id(),
                'statusChanged',
                'products',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $product->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($product),
                $product->id,
                Auth::id(),
                'updated',
                'products',
                __('activity.product_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }

        $this->notifyDiscountChanged($product);
        $this->notifyPriceDrop($product);
        $this->notifyBackInStock($product);
    }

    private function notifyDiscountChanged(Product $product): void
    {
        $discountFields = [
            'has_discount',
            'discount_type',
            'discount_amount',
            'discount_status',
            'price_after_discount',
        ];

        if (!$product->isDirty($discountFields)) {
            return;
        }

        $oldValues = [];
        $newValues = [];

        foreach ($discountFields as $field) {
            if ($product->isDirty($field)) {
                $oldValues[$field] = $product->getOriginal($field);
                $newValues[$field] = $product->{$field};
            }
        }

        event(new ProductDiscountChanged($product, $oldValues, $newValues));
    }

    private function notifyPriceDrop(Product $product): void
    {
        $oldPrice = $product->getOriginal('price');

        if (is_null($oldPrice) || $oldPrice <= $product->price) {
            return;
        }

        event(new ProductPriceDrop($product, $oldPrice, $product->price));
    }

    private function notifyBackInStock(Product $product): void
    {
        $oldStock = $product->getOriginal('stock_quantity');

        if (is_null($oldStock) || $oldStock > 0 || $product->stock_quantity <= 0) {
            return;
        }

        event(new ProductBackInStock($product));
    }

    public function deleted(Product $product): void
    {
        $this->flushProductCaches();

        LogActivityJob::dispatch(
            get_class($product),
            $product->id,
            Auth::id(),
            'deleted',
            'products',
            __('activity.product_deleted'),
        );
    }

    public function restored(Product $product): void
    {
        $this->flushProductCaches();

        LogActivityJob::dispatch(
            get_class($product),
            $product->id,
            Auth::id(),
            'restored',
            'products',
            __('activity.product_restored'),
        );
    }

    public function forceDeleted(Product $product): void
    {
        $this->flushProductCaches();

        LogActivityJob::dispatch(
            get_class($product),
            $product->id,
            Auth::id(),
            'forceDeleted',
            'products',
            __('activity.product_force_deleted'),
        );
    }

    /**
     * Invalidate every product listing cache variant so the next request
     * rebuilds from the database.
     */
    private function flushProductCaches(): void
    {
        $this->flushTag(FrontendResource::PRODUCTS->value);

        foreach (app(ProductStrategyResolver::class)->supportedTypes() as $type) {
            $this->flushTag(FrontendResource::PRODUCTS->value . '_' . $type);
        }
    }
}
