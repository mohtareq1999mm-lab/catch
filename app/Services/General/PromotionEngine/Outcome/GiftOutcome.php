<?php

namespace App\Services\General\PromotionEngine\Outcome;

use App\Services\General\PromotionEngine\DTOs\GiftItem;

final class GiftOutcome extends PromotionOutcome
{
    /** @param GiftItem[] $giftItems */
    public function __construct(public readonly array $giftItems) {}

    /** @return array<int, array> */
    public function toArray(): array
    {
        return array_map(fn(GiftItem $g) => $g->toArray(), $this->giftItems);
    }
}
