<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use App\Enums\FrontendResource;
use App\Events\FlashSaleActivated;
use App\Traits\HasCache;
use Marvel\Database\Models\FlashSale;

class FlashSaleObserver
{
    use HasCache;

    public function created(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        ActivityAuditService::recordModel(
            $flashSale,
            'created',
            'flash_sales',
            __('activity.flash_sale_created'),
            new: $flashSale->getAttributes(),
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

            ActivityAuditService::recordModel(
                $flashSale,
                'statusChanged',
                'flash_sales',
                $description,
                old: ['status' => $oldStatus],
                new: ['status' => $newStatus],
            );

            if ($oldStatus == false && $newStatus == true) {
                event(new FlashSaleActivated($flashSale));
            }
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') {
                    continue;
                }
                $oldValues[$key] = $flashSale->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $flashSale,
                'updated',
                'flash_sales',
                __('activity.flash_sale_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        ActivityAuditService::recordModel(
            $flashSale,
            'deleted',
            'flash_sales',
            __('activity.flash_sale_deleted'),
            old: $flashSale->getAttributes(),
        );
    }

    public function restored(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        ActivityAuditService::recordModel(
            $flashSale,
            'restored',
            'flash_sales',
            __('activity.flash_sale_restored'),
            new: $flashSale->getAttributes(),
        );
    }

    public function forceDeleted(FlashSale $flashSale): void
    {
        $this->flushFlashSaleCache();

        ActivityAuditService::recordModel(
            $flashSale,
            'forceDeleted',
            'flash_sales',
            __('activity.flash_sale_force_deleted'),
            old: $flashSale->getAttributes(),
        );
    }

    private function flushFlashSaleCache(): void
    {
        $this->flushTag(FrontendResource::FLASH_SALES->value);
    }
}
