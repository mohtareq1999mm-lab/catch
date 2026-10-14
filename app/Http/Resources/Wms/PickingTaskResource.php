<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignedTo = null;
        $assignedUser = null;

        if ($this->batch_id !== null && $this->relationLoaded('batch')) {
            $assignedTo = $this->batch?->assigned_to;
            $assignedUser = $this->batch?->relationLoaded('assignedUser') ? $this->batch?->assignedUser : null;
        } elseif ($this->relationLoaded('fulfillmentItem') && $this->fulfillmentItem?->relationLoaded('fulfillment')) {
            $assignedTo = $this->fulfillmentItem?->fulfillment?->assigned_to;
            $assignedUser = $this->fulfillmentItem?->fulfillment?->relationLoaded('assignedUser')
                ? $this->fulfillmentItem?->fulfillment?->assignedUser
                : null;
        } else {
            if ($this->batch_id !== null) {
                $batch = $this->batch;
                $assignedTo = $batch?->assigned_to;
                $assignedUser = $batch?->assignedUser;
            } elseif ($this->fulfillment_item_id !== null) {
                $fulfillment = $this->fulfillmentItem?->fulfillment;
                $assignedTo = $fulfillment?->assigned_to;
                $assignedUser = $fulfillment?->assignedUser;
            }
        }

        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'fulfillment_item_id' => $this->fulfillment_item_id,
            'order_id' => $this->order_id,
            'product_location_id' => $this->product_location_id,
            'quantity_to_pick' => $this->quantity_to_pick,
            'quantity_picked' => $this->quantity_picked,
            'remaining' => (float) $this->quantity_to_pick - (float) $this->quantity_picked,
            'status' => $this->status,
            'sequence' => $this->sequence !== null ? (int) $this->sequence : null,
            'assigned_to' => $assignedTo !== null ? (int) $assignedTo : null,
            'assigned_user' => $assignedUser ? [
                'id' => (int) $assignedUser->id,
                'name' => (string) $assignedUser->name,
                'email' => (string) ($assignedUser->email ?? ''),
            ] : null,
            'claimed_by' => $this->claimed_by,
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'claim_expires_at' => $this->claim_expires_at?->toIso8601String(),
            'picked_at' => $this->picked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
