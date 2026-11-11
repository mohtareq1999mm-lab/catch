<?php

namespace App\Http\Controllers\Api\General\Guest;

use App\Http\Controllers\Controller;
use App\Services\Guest\GuestCartService;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['cart' => null]);
        }

        $cart = $this->cartService->findCart($identity);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'cart' => $cart ? CartResource::make($cart) : null,
        ]);
    }

    public function store(CartCreateRequest $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            [$identity] = $this->identities->create($request);
        }

        try {
            $cart = $this->cartService->addItem($request, $identity, (array) $request->input('item', []));

            return $this->apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, [
                'cart' => CartResource::make($cart),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(__('guest_checkout.product_unavailable'), 422, false);
        } catch (\Throwable $e) {
            report($e);

            return $this->apiResponse(INVALID_ITEM_DATA, 422, false);
        }
    }

    public function update(CartUpdateRequest $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

        try {
            $cart = $this->cartService->updateItem($request, $identity, (array) $request->input('item', []));

            return $this->apiResponse(UPDATE_CART_SUCCESSFULLY, 200, true, [
                'cart' => CartResource::make($cart),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(__('guest_checkout.product_unavailable'), 422, false);
        } catch (\Throwable $e) {
            report($e);

            return $this->apiResponse(INVALID_ITEM_DATA, 422, false);
        }
    }

    public function destroyItem(Request $request, int $itemId): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

        try {
            $cart = $this->cartService->removeItem($request, $identity, $itemId);

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

    public function destroy(Request $request): JsonResponse
    {
        $identity = $this->identities->current($request);

        if (!$identity) {
            return $this->apiResponse(__('guest_checkout.session_required'), 401, false);
        }

        try {
            $cart = $this->cartService->clear($request, $identity);

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
