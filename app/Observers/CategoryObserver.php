<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use App\Enums\FrontendResource;
use App\Services\General\HomeService;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Category;

class CategoryObserver
{
    use HasCache;

    public function created(Category $category): void
    {
        $this->flushCaches();

        ActivityAuditService::recordModel(
            $category,
            'created',
            'categories',
            __('activity.category_created'),
            new: $category->getAttributes(),
        );
    }

    public function updated(Category $category): void
    {
        $dirty = $category->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $this->flushCaches();

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $category->getOriginal('status');
            $newStatus = $category->status;
            $description = $newStatus
                ? __('activity.category_activated')
                : __('activity.category_deactivated');
            $description = $description ?: ($newStatus ? 'Category activated' : 'Category deactivated');

            ActivityAuditService::recordModel(
                $category,
                'statusChanged',
                'categories',
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
                $oldValues[$key] = $category->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $category,
                'updated',
                'categories',
                __('activity.category_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(Category $category): void
    {
        $this->flushCaches();

        ActivityAuditService::recordModel(
            $category,
            'deleted',
            'categories',
            __('activity.category_deleted'),
            old: $category->getAttributes(),
        );
    }

    public function restored(Category $category): void
    {
        $this->flushCaches();

        ActivityAuditService::recordModel(
            $category,
            'restored',
            'categories',
            __('activity.category_restored'),
            new: $category->getAttributes(),
        );
    }

    public function forceDeleted(Category $category): void
    {
        $this->flushCaches();

        ActivityAuditService::recordModel(
            $category,
            'forceDeleted',
            'categories',
            'Category permanently deleted',
            old: $category->getAttributes(),
        );
    }

    private function flushCaches(): void
    {
        HomeService::clearCache();
        $this->flushTagWithFallback(FrontendResource::CATEGORIES->value);
        $this->flushTagWithFallback(FrontendResource::PRODUCTS->value);
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
