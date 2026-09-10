<?php

namespace App\Services\Digital;

use App\Events\DigitalProductsDelivered;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Throwable;

class DigitalFulfillmentService
{
    /**
     * Fulfil every DIGITAL order line of an order exactly once.
     *
     * Idempotency: UNIQUE(order_product_id) + firstOrCreate — running this
     * twice (event replay, queue retry) can never duplicate entitlements.
     */
    public function fulfillOrder(Order $order): void
    {
        if (!config('digital.enabled', true)) {
            return;
        }

        $order->loadMissing('orderItems.product');

        $digitalItems = $order->orderItems->filter(
            fn ($item) => ($item->item_type ?? \Marvel\Enums\ItemType::PHYSICAL) === \Marvel\Enums\ItemType::DIGITAL
        );

        if ($digitalItems->isEmpty()) {
            return;
        }

        $deliveredEntitlements = collect();

        DB::transaction(function () use ($digitalItems, $order, &$deliveredEntitlements) {
            foreach ($digitalItems as $item) {
                $entitlement = DigitalEntitlement::firstOrCreate(
                    ['order_product_id' => $item->id],
                    [
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'order_id' => $order->id,
                        'user_id' => $order->user_id,
                        'status' => DigitalEntitlement::STATUS_PENDING,
                        'download_limit' => max(1, (int) config('digital.download_limit', 5)),
                    ]
                );

                // Grant every active asset of the purchased product.
                $assetIds = DigitalAsset::query()
                    ->where('product_id', $item->product_id)
                    ->pluck('id');

                $entitlement->assets()->syncWithoutDetaching($assetIds);

                // W5: allocate one pool key per LICENSE asset of this line.
                // Runs inside the same transaction; idempotent via the
                // existing-allocation guard + the UNIQUE(order_product_id)
                // entitlement anchor.
                $this->allocateLicenseKeys($entitlement, $item->product_id);

                if ($entitlement->status === DigitalEntitlement::STATUS_PENDING) {
                    $entitlement->update([
                        'status' => DigitalEntitlement::STATUS_DELIVERED,
                        'delivered_at' => now(),
                    ]);
                }

                $deliveredEntitlements->push($entitlement);
            }
        });

        try {
            DigitalProductsDelivered::dispatch($order->fresh(), $deliveredEntitlements);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Revoke access for a delivered digital entitlement (D7). The next
     * signed download attempt fails the status check immediately.
     */
    public function revoke(DigitalEntitlement $entitlement): void
    {
        if ($entitlement->status !== DigitalEntitlement::STATUS_REVOKED) {
            $entitlement->update([
                'status' => DigitalEntitlement::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
        }
    }

    /**
     * W5 — allocate one available pool key per LICENSE asset of the
     * purchased product to this entitlement.
     *
     * Concurrency: rows are selected FOR UPDATE inside the fulfillment
     * transaction, so two concurrent fulfillments can never grab the same
     * key; a loser simply finds no available row (pool exhaustion is a
     * documented, non-fatal outcome). Idempotency: an existing allocation
     * for this entitlement+asset short-circuits before any selection, and
     * the UNIQUE(order_product_id) anchor prevents entitlement duplication
     * in the first place.
     */
    private function allocateLicenseKeys(DigitalEntitlement $entitlement, int $productId): void
    {
        $licenseAssetIds = DigitalAsset::query()
            ->where('product_id', $productId)
            ->where('type', \App\Enums\DigitalAssetType::LICENSE->value)
            ->where('status', \App\Models\DigitalAsset::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($licenseAssetIds as $assetId) {
            $alreadyAllocated = DigitalLicenseKey::query()
                ->where('asset_id', $assetId)
                ->where('allocated_entitlement_id', $entitlement->id)
                ->exists();

            if ($alreadyAllocated) {
                continue;
            }

            $key = DigitalLicenseKey::query()
                ->where('asset_id', $assetId)
                ->where('status', DigitalLicenseKey::STATUS_AVAILABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$key) {
                // Pool exhausted/empty: entitlement still delivers; reveal
                // endpoint reports the missing credential. Documented W5
                // behavior — fulfillment must never fail because ops have
                // not topped up a pool yet.
                continue;
            }

            $key->forceFill([
                'status' => DigitalLicenseKey::STATUS_ASSIGNED,
                'allocated_entitlement_id' => $entitlement->id,
                'assigned_at' => now(),
            ])->save();
        }
    }
}
