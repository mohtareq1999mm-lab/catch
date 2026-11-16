<?php

namespace App\Exceptions;

use App\Contracts\ApiRejection;
use RuntimeException;

/**
 * A product exists but cannot enter a cart under the current Eligibility rules
 * (inactive, soft-deleted, out of stock, or not offered in the active channel).
 *
 * Carries the rejected product id and a raw translation key. Rendering happens
 * at the HTTP seam so the vendored cart kernel never has to localize.
 */
class ProductNotPurchasableException extends RuntimeException implements ApiRejection
{
    private const MESSAGE_KEY = 'message.ERROR.PRODUCT_NOT_PURCHASABLE';

    public function __construct(
        private readonly int $productId
    ) {
        parent::__construct(self::MESSAGE_KEY);
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function message(): string
    {
        return __($this->getMessage());
    }

    public function errors(): array
    {
        return [
            'item.product_id' => [$this->message()],
        ];
    }

    public function data(): array
    {
        return [];
    }
}
