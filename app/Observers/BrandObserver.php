<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Jobs\LogActivityJob;
use App\Services\General\HomeService;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Brand;

class BrandObserver
{
    use HasCache;

    public function created(Brand $brand): void
    {
        $this->flushBrandCaches();

        LogActivityJob::dispatch(
            get_class($brand),
            $brand->id,
            Auth::id(),
            'created',
            'brands',
            __('activity.brand_created'),
        );
    }

    public function updated(Brand $brand): void
    {
        $dirty = $brand->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $this->flushBrandCaches();

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $brand->getOriginal('status');
            $newStatus = $brand->status;
            $description = $newStatus
                ? __('activity.brand_activated')
                : __('activity.brand_deactivated');
            $description = $description ?: ($newStatus ? 'Brand activated' : 'Brand deactivated');

            LogActivityJob::dispatch(
                get_class($brand),
                $brand->id,
                Auth::id(),
                'statusChanged',
                'brands',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $brand->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($brand),
                $brand->id,
                Auth::id(),
                'updated',
                'brands',
                __('activity.brand_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(Brand $brand): void
    {
        $this->flushBrandCaches();

        LogActivityJob::dispatch(
            get_class($brand),
            $brand->id,
            Auth::id(),
            'deleted',
            'brands',
            __('activity.brand_deleted'),
        );
    }

    public function restored(Brand $brand): void
    {
        $this->flushBrandCaches();
    }

    /**
     * Invalidate brand-related frontend caches and product listings that may embed brand data.
     */
    private function flushBrandCaches(): void
    {
        $this->flushTagWithFallback(FrontendResource::BRANDS->value);
        $this->flushTagWithFallback(FrontendResource::BRANDS_PRODUCTS->value);
        // Brand changes affect product listings filtered by brand and home brand section
        $this->flushTagWithFallback(FrontendResource::PRODUCTS->value);
        HomeService::clearCache();
        // Generic API cache version bump for CacheApiResponse middleware
        try {
            \Illuminate\Support\Facades\Cache::increment('api_cache_version');
        } catch (\Throwable $e) {
        }
    }

    private function flushTagWithFallback(string $tag): void
    {
        try {
            $this->flushTag($tag);
            // Fallback for non-taggable stores (array/file during tests/seed): HasCache::flushTag no-ops,
            // so ensure stale prefixed keys are also cleared by flushing entire cache.
            if (! \Illuminate\Support\Facades\Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
                \Illuminate\Support\Facades\Cache::flush();
            }
        } catch (\BadMethodCallException) {
            \Illuminate\Support\Facades\Cache::flush();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
