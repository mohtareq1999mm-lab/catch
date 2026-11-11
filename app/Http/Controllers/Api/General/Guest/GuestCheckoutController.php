<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Exceptions\CartEmptyException;
use App\Exceptions\FlowInputValidationException;
use App\Exceptions\GuestCheckoutConflictException;
use App\Http\Controllers\Controller;
use App\Services\Guest\GuestCheckoutService;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private GuestIdentityService $identities,
    ) {
    }

    public function checkout(OrderCreateRequest $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

        try {
            $payload = $this->checkoutService->checkout($request, $identity);

            return $this->apiResponse(CHECKOUT_SUCCESSFUL, 201, true, $payload);
        } catch (GuestCheckoutConflictException $e) {
            return $this->apiResponse($e->getMessage(), $e->httpStatus(), false);
        } catch (CartEmptyException $e) {
            return $this->apiResponse(CART_NOT_FOUND, 400, false);
        } catch (FlowInputValidationException $e) {
            return $this->apiResponse($e->getMessage(), 422, false, ['errors' => $e->errors()]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (\Throwable $e) {
            report($e);

            return $this->apiResponse(ERROR_ADDING_ITEMS_TO_ORDER, 500, false);
        }
    }
}
