<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestCartService;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Http\Requests\CartCreateRequest;
use Marvel\Http\Requests\CartUpdateRequest;
use Marvel\Http\Resources\CartResource;
use Marvel\Traits\ApiResponse;

/**
 * Persistent guest cart API.
 *
 * Ownership is resolved ONLY from the verified guest credential; a cart id is
 * never accepted as authorization. All prices/totals are recalculated by the
 * canonical cart/pricing services.
 */
class GuestCartController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GuestCartService $cartService,
        private GuestIdentityService $identities,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            if ($this->identities->hasPresentedCredential($request)) {
                return response()->json([
                    'status' => 401,
                    'message' => __('guest_checkout.session_required'),
                    'success' => false,
                ], 401);
            }

            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['cart' => null]);
        }

        $cart = $this->cartService->findCart($identity);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'cart' => $cart ? CartResource::make($cart) : null,
        ]);
    }

    public function store(CartCreateRequest $request, GuestIdentity $identity): JsonResponse
    {
        // Writes REQUIRE an existing verified guest session (guest.session
        // middleware); the session is created only by POST /guest/session —
        // a write must never mint one.
        $cart = $this->cartService->addItem($identity, (array) $request->input('item', []));

        return $this->apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, [
            'cart' => CartResource::make($cart),
        ]);
    }

    public function update(CartUpdateRequest $request, GuestIdentity $identity): JsonResponse
    {
        $cart = $this->cartService->updateItem($identity, (array) $request->input('item', []));

        return $this->apiResponse(UPDATE_CART_SUCCESSFULLY, 200, true, [
            'cart' => CartResource::make($cart),
        ]);
    }

    public function destroyItem(GuestIdentity $identity, int $itemId): JsonResponse
    {
        try {
            $cart = $this->cartService->removeItem($identity, $itemId);

            if (!$cart) {
                return $this->apiResponse(DELETE_CART_ITEM_FAILED, 404, false);
            }

            return $this->apiResponse(DELETE_CART_ITEM_SUCCESSFULLY, 200, true, [
                'cart' => CartResource::make($cart),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 500, false);
        }
    }

    public function destroy(GuestIdentity $identity): JsonResponse
    {
        try {
            $cart = $this->cartService->clear($identity);

            if (!$cart) {
                return $this->apiResponse(CART_NOT_FOUND, 404, false);
            }

            return $this->apiResponse(DELETE_CART_SUCCESSFULLY, 200, true, [
                'cart' => CartResource::make($cart),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 500, false);
        }
    }
}
