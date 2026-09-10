<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Jobs\LogActivityJob;
use App\Services\General\HomeService;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Category;

class CategoryObserver
{
    use HasCache;

    public function created(Category $category): void
    {
        HomeService::clearCache();
        $this->flushTag(FrontendResource::CATEGORIES->value);

        LogActivityJob::dispatch(
            get_class($category),
            $category->id,
            Auth::id(),
            'created',
            'categories',
            __('activity.category_created'),
        );
    }

    public function updated(Category $category): void
    {
        $dirty = $category->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        HomeService::clearCache();
        $this->flushTag(FrontendResource::CATEGORIES->value);

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $category->getOriginal('status');
            $newStatus = $category->status;
            $description = $newStatus
                ? __('activity.category_activated')
                : __('activity.category_deactivated');
            $description = $description ?: ($newStatus ? 'Category activated' : 'Category deactivated');

            LogActivityJob::dispatch(
                get_class($category),
                $category->id,
                Auth::id(),
                'statusChanged',
                'categories',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $category->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($category),
                $category->id,
                Auth::id(),
                'updated',
                'categories',
                __('activity.category_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(Category $category): void
    {
        HomeService::clearCache();
        $this->flushTag(FrontendResource::CATEGORIES->value);

        LogActivityJob::dispatch(
            get_class($category),
            $category->id,
            Auth::id(),
            'deleted',
            'categories',
            __('activity.category_deleted'),
        );
    }

    public function restored(Category $category): void
    {
        HomeService::clearCache();
        $this->flushTag(FrontendResource::CATEGORIES->value);
    }
}
