<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case PENDING = 'pending';
    case LABEL_CREATED = 'label_created';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED_DELIVERY = 'failed_delivery';
    case RETURNED = 'returned';
    case DELAYED = 'delayed';
    case CANCELLED = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::LABEL_CREATED, self::CANCELLED],
            self::LABEL_CREATED => [self::PICKED_UP, self::CANCELLED],
            self::PICKED_UP => [self::IN_TRANSIT, self::CANCELLED],
            self::IN_TRANSIT => [self::OUT_FOR_DELIVERY, self::DELAYED],
            self::OUT_FOR_DELIVERY => [self::DELIVERED, self::FAILED_DELIVERY],
            self::DELIVERED => [],
            self::FAILED_DELIVERY => [self::OUT_FOR_DELIVERY, self::RETURNED],
            self::RETURNED => [],
            self::DELAYED => [self::IN_TRANSIT, self::OUT_FOR_DELIVERY],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
