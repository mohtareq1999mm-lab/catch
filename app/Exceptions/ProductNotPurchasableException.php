<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A product exists but cannot enter a cart under the current Eligibility rules
 * (inactive, soft-deleted, out of stock, or not offered in the active channel).
 *
 * Carries the rejected product id so callers can log or extend the response
 * without exposing framework internals. Rendered as a 422 field-scoped error on
 * the cart line, using the same envelope as request validation failures.
 */
class ProductNotPurchasableException extends RuntimeException
{
    public function __construct(
        private readonly int $productId
    ) {
        parent::__construct(__('message.ERROR.PRODUCT_NOT_PURCHASABLE'));
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function render(Request $request): JsonResponse
    {
        $message = $this->getMessage();

        return response()->json([
            'message' => $message,
            'status' => false,
            'success' => false,
            'errors' => [
                'item.product_id' => [$message],
            ],
        ], 422);
    }
}
