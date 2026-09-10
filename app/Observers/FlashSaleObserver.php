<?php

namespace App\Observers;

use App\Enums\FrontendResource;
use App\Events\FlashSaleActivated;
use App\Jobs\LogActivityJob;
use App\Traits\HasCache;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\FlashSale;

class FlashSaleObserver
{
    use HasCache;

    public function created(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        LogActivityJob::dispatch(
            get_class($flashSale),
            $flashSale->id,
            Auth::id(),
            'created',
            'flash_sales',
            __('activity.flash_sale_created'),
        );

        if ($flashSale->status === true) {
            event(new FlashSaleActivated($flashSale));
        }
    }

    public function updated(FlashSale $flashSale): void
    {
        $dirty = $flashSale->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $this->flushFlashSaleCache();

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $flashSale->getOriginal('status');
            $newStatus = $flashSale->status;
            $description = $newStatus
                ? __('activity.flash_sale_activated')
                : __('activity.flash_sale_deactivated');
            $description = $description ?: ($newStatus ? 'Flash sale activated' : 'Flash sale deactivated');

            LogActivityJob::dispatch(
                get_class($flashSale),
                $flashSale->id,
                Auth::id(),
                'statusChanged',
                'flash_sales',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );

            if ($oldStatus == false && $newStatus == true) {
                event(new FlashSaleActivated($flashSale));
            }
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $flashSale->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($flashSale),
                $flashSale->id,
                Auth::id(),
                'updated',
                'flash_sales',
                __('activity.flash_sale_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        LogActivityJob::dispatch(
            get_class($flashSale),
            $flashSale->id,
            Auth::id(),
            'deleted',
            'flash_sales',
            __('activity.flash_sale_deleted'),
        );
    }

    public function restored(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        LogActivityJob::dispatch(
            get_class($flashSale),
            $flashSale->id,
            Auth::id(),
            'restored',
            'flash_sales',
            __('activity.flash_sale_restored'),
        );
    }

    public function forceDeleted(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        LogActivityJob::dispatch(
            get_class($flashSale),
            $flashSale->id,
            Auth::id(),
            'forceDeleted',
            'flash_sales',
            __('activity.flash_sale_force_deleted'),
        );
    }

    /**
     * Invalidate the frontend flash sales listing cache so the next request
     * rebuilds from the database.
     */
    private function flushFlashSaleCache(): void
    {
        $this->flushTag(FrontendResource::FLASH_SALES->value);
    }
}
