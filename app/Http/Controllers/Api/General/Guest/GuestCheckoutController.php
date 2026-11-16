<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Exceptions\CartEmptyException;
use App\Exceptions\FlowInputValidationException;
use App\Http\Controllers\Controller;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestCheckoutService;
use Illuminate\Http\JsonResponse;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Traits\ApiResponse;

/**
 * Guest order creation. Delegates to the canonical OrderService path through
 * GuestCheckoutService — no parallel order authority.
 */
class GuestCheckoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GuestCheckoutService $checkoutService,
    ) {
    }

    public function checkout(OrderCreateRequest $request, GuestIdentity $identity): JsonResponse
    {
        try {
            $payload = $this->checkoutService->checkout($request, $identity);

            return $this->apiResponse(CHECKOUT_SUCCESSFUL, 201, true, $payload);
        } catch (CartEmptyException $e) {
            return $this->apiResponse(CART_NOT_FOUND, 400, false);
        } catch (FlowInputValidationException $e) {
            return $this->apiResponse($e->getMessage(), 422, false, ['errors' => $e->errors()]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }
    }
}
