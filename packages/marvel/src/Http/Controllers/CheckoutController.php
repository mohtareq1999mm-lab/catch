<?php

namespace Marvel\Http\Controllers;

use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CheckoutVerifyRequest;

class CheckoutController extends CoreController
{
    public $repository;

    public function __construct(CheckoutRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Post(
     *     path="/orders/checkout/verify",
     *     operationId="verifyCheckout",
     *     tags={"Checkout"},
     *     summary="Verify checkout data",
     *     description="Validates a cart's contents, applies taxes, and calculates shipping fees. This is typically the first step in the checkout process for both guests and customers.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "products"},
     *             @OA\Property(property="amount", type="number", format="float", example=100.00, description="Total cart amount before tax/shipping"),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="product_id", type="integer", example=1),
     *                     @OA\Property(property="variation_option_id", type="integer", nullable=true, example=5),
     *                     @OA\Property(property="unit_price", type="number", format="float", example=50.00),
     *                     @OA\Property(property="order_quantity", type="integer", example=2)
     *                 )
     *             ),
     *             @OA\Property(property="billing_address", type="object", description="Billing address details (optional for verification index)"),
     *             @OA\Property(property="shipping_address", type="object", description="Shipping address details (optional for verification index)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Checkout validation results",
     *         @OA\JsonContent(
     *             @OA\Property(property="total_tax", type="number", format="float", example=5.00),
     *             @OA\Property(property="shipping_charge", type="number", format="float", example=10.00),
     *             @OA\Property(property="available_wallet_points", type="number", example=0),
     *             @OA\Property(property="unavailable_products", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Something went wrong")
     * )
     */
    public function verify(CheckoutVerifyRequest $request)
    {
        try {
            return $this->repository->verify($request);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
