<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use App\Enums\FrontendResource;
use App\Services\General\HomeService;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Brand;

class BrandObserver
{
    use HasCache;

    public function created(Brand $brand): void
    {
        $this->flushBrandCaches();

        ActivityAuditService::recordModel(
            $brand,
            'created',
            'brands',
            __('activity.brand_created'),
            new: $brand->getAttributes(),
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

            ActivityAuditService::recordModel(
                $brand,
                'statusChanged',
                'brands',
                $description,
                old: ['status' => $oldStatus],
                new: ['status' => $newStatus],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') {
                    continue;
                }
                $oldValues[$key] = $brand->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $brand,
                'updated',
                'brands',
                __('activity.brand_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(Brand $brand): void
    {
        $this->flushBrandCaches();

        ActivityAuditService::recordModel(
            $brand,
            'deleted',
            'brands',
            __('activity.brand_deleted'),
            old: $brand->getAttributes(),
        );
    }

    public function restored(Brand $brand): void
    {
        $this->flushBrandCaches();

        ActivityAuditService::recordModel(
            $brand,
            'restored',
            'brands',
            __('activity.brand_restored'),
            new: $brand->getAttributes(),
        );
    }

    public function forceDeleted(Brand $brand): void
    {
        $this->flushBrandCaches();

        ActivityAuditService::recordModel(
            $brand,
            'forceDeleted',
            'brands',
            'Brand permanently deleted',
            old: $brand->getAttributes(),
        );
    }

    private function flushBrandCaches(): void
    {
        $this->flushTagWithFallback(FrontendResource::BRANDS->value);
        $this->flushTagWithFallback(FrontendResource::BRANDS_PRODUCTS->value);
        $this->flushTagWithFallback(FrontendResource::PRODUCTS->value);
        HomeService::clearCache();
        try {
            Cache::increment('api_cache_version');
        } catch (\Throwable $e) {
        }
    }

    private function flushTagWithFallback(string $tag): void
    {
        try {
            $this->flushTag($tag);
            Cache::flush();
            HomeService::clearCache();
        } catch (\BadMethodCallException) {
            Cache::flush();
        } catch (\Throwable $e) {
            report($e);
            Cache::flush();
        }
    }
}
