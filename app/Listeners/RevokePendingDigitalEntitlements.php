<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use App\Models\DigitalEntitlement;
use App\Services\Digital\DigitalFulfillmentService;
use Illuminate\Contracts\Queue\ShouldQueue;

class RevokePendingDigitalEntitlements implements ShouldQueue
{
    public $queue = 'meem-medium';
    public $afterCommit = true;

    public function __construct(private DigitalFulfillmentService $fulfillmentService) {}

    public function handle(RefundApproved $event): void
    {
        $orderId = $event->refund?->order_id;

        if (!$orderId) {
            return;
        }

        $entitlements = DigitalEntitlement::query()
            ->where('order_id', $orderId)
            ->where('status', DigitalEntitlement::STATUS_PENDING)
            ->get();

        foreach ($entitlements as $entitlement) {
            $this->fulfillmentService->revoke($entitlement);
        }
    }
}
