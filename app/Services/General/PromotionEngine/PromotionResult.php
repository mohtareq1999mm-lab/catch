<?php

namespace App\Services\General\PromotionEngine;

use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\DTOs\GiftItem;

class PromotionResult
{
    /** @param GiftItem[] $giftItems */
    public function __construct(
        public readonly Promotion $promotion,
        public readonly float $discount,
        public readonly array $giftItems = [],
        public readonly int $matchedSubtotalCents = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->promotion->id,
            'type' => $this->promotion->type_amount,
            'title' => $this->title(),
            'code' => $this->promotion->code,
            'discount' => round($this->discount, 2),
            'gift_items' => array_map(fn(GiftItem $g) => $g->toArray(), $this->giftItems),
        ];
    }

    private function title(): string
    {
        if (method_exists($this->promotion, 'getTranslation')) {
            $title = $this->promotion->getTranslation('name', app()->getLocale(), false);

            if ($title) {
                return (string) $title;
            }
        }

        return (string) ($this->promotion->name ?: $this->promotion->code);
    }
}
