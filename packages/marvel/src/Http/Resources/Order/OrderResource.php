<?php

namespace Marvel\Http\Resources\Order;

use App\Services\General\OrderService;
use Illuminate\Http\Request;
use Marvel\Http\Resources\Resource;

class OrderResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'shipping_method' => $this->shipping_method,
            'expected_delivery_at' => $this->expected_delivery_at?->toIso8601String(),
            'customer' => $this->when($this->relationLoaded('user') && $this->user, [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone_number,
            ]),
            $this->mergeWhen(request()->routeIs('orders.show', 'orders.update-status'), [
                'customer_name' => $this->name,
                'customer_phone' => $this->user_phone,
                'customer_email' => $this->user_email,
                'address' => $this->address,
                'notes' => $this->notes,
                'price' => $this->roundMoney($this->price),
                'shipping_price' => $this->roundMoney($this->shipping_price),
                'total_price' => $this->roundMoney($this->total_price),
                'coupon' => $this->coupon,
                'coupon_discount' => $this->roundMoney($this->coupon_discount),
                'promotion' => $this->promotion_id ? [
                    'id' => $this->promotion_id,
                    'code' => $this->promotion_code,
                    'type' => $this->promotion_type,
                    'discount' => $this->roundMoney($this->promotion_discount),
                ] : null,
                'order_items' => OrderItemResource::collection($this->whenLoaded('orderItems')),
                'transactions' => OrderTransactionResource::collection($this->whenLoaded('transactions')),
                'available_statuses' => OrderService::getAllowedOrderStatusTargets((string) $this->status),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'fast_shipping_fee' => $this->roundMoney($this->fast_shipping_fee),
            'pickup_location' => $this->when($this->fulfillment_type === 'pickup', fn() => $this->resolvePickupLocation()),
        ];
    }

    private function resolvePickupLocation(): ?array
    {
        if ($this->relationLoaded('pickupLocation') && $this->pickupLocation) {
            return [
                'id' => $this->pickupLocation->id,
                'store_name' => $this->pickupLocation->store_name,
                'address' => $this->pickupLocation->address,
                'phone' => $this->pickupLocation->phone,
                'email' => $this->pickupLocation->email,
                'working_hours' => $this->pickupLocation->working_hours,
                'latitude' => $this->pickupLocation->latitude,
                'longitude' => $this->pickupLocation->longitude,
                'status' => (bool) $this->pickupLocation->status,
            ];
        }

        if ($this->pickup_location_name) {
            $coordinates = $this->pickup_location_coordinates;
            $parts = $coordinates ? explode(',', $coordinates) : [];

            return [
                'id' => $this->pickup_location_id,
                'store_name' => $this->pickup_location_name,
                'address' => $this->pickup_location_address,
                'phone' => $this->pickup_location_phone,
                'email' => null,
                'working_hours' => null,
                'latitude' => $parts[0] ?? null,
                'longitude' => $parts[1] ?? null,
                'status' => true,
            ];
        }

        return null;
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
