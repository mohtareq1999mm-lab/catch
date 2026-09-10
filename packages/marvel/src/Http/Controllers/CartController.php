<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\CartRepository;
use App\Services\General\CartInventoryService;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Http\Requests\CartCreateRequest;
use Marvel\Http\Requests\CartUpdateRequest;
use Marvel\Http\Resources\CartResource;
use Marvel\Traits\ApiResponse;

class CartController extends CoreController
{
    use ApiResponse;

    public $repository;
    public $inventoryService;

    public function __construct(CartRepository $repository, CartInventoryService $inventoryService)
    {
        $this->repository = $repository;
        $this->inventoryService = $inventoryService;
    }

    public function index(Request $request)
    {
        $limit = $request->limit ?? 15;
        $query = $this->repository->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute']);
        $user = $request->user();

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $carts = $query->paginate($limit)->withQueryString();
        $cartData = CartResource::collection($carts)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $cartData['data'] ?? [],
            "page" => $cartData['meta']['current_page'] ?? 0,
            "current_page" => $cartData['meta']['current_page'] ?? 0,
            "from" => $cartData['meta']['from'] ?? 0,
            "to" => $cartData['meta']['to'] ?? 0,
            "last_page" => $cartData['meta']['last_page'] ?? 0,
            "path" => $cartData['meta']['path'] ?? "",
            "per_page" => $cartData['meta']['per_page'] ?? 0,
            "total" => $cartData['meta']['total'] ?? 0,
            "next_page_url" => $cartData['links']['next'] ?? "",
            "prev_page_url" => $cartData['links']['prev'] ?? "",
            "last_page_url" => $cartData['links']['last'] ?? "",
            "first_page_url" => $cartData['links']['first'] ?? "",
        ]);
    }

    public function store(CartCreateRequest $request)
    {
        try {
            $cart = $this->repository->storeCart($request);
            $this->repository->revalidatePromotion($cart);
            return $this->apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, CartResource::make($cart));
        } catch (\Exception $e) {
            return $this->apiResponse($e->getMessage(), 400, false);
        }
    }

    public function show(Request $request, $id)
    {
        $cart = $this->repository->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])->findOrFail($id);
        $user = $request->user();

        if ($user && (int) $cart->user_id !== (int) $user->id) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CartResource::make($cart));
    }

    public function update(CartUpdateRequest $request)
    {
        try {
            $cart = $this->repository->updateCart($request);
            $this->repository->revalidatePromotion($cart);
            return $this->apiResponse(UPDATE_CART_SUCCESSFULLY, 200, true, CartResource::make($cart));
        } catch (\Exception $e) {
            return $this->apiResponse($e->getMessage(), 400, false);
        }
    }

    public function deleteItemFromCart(Request $request, $ItemId)
    {
        $user = $request->user();
        $cart = $user?->cart;
        if (!$cart) {
            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 400, false);
        }
        if ($user && (int) $cart->user_id !== (int) $user->id) {
            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 400, false);
        }

        $item = $cart->items()->where('id', $ItemId)->first();
        if (!$item) {
            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 400, false);
        }

        if (!$this->inventoryService->releaseItem($item, true)) {
            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 400, false);
        }

        $this->repository->revalidatePromotion($cart);
        $cart->update(['total_price' => round((float) $cart->items()->sum('total_price'), 2)]);
        return $this->apiResponse(DELETE_CART_ITEM_SUCCESSFULLY, 200, true);
    }

    public function destroy(Request $request)
    {
        $cart = auth()->user()?->cart;
        $user = $request->user();

        if (!$cart) {
            return $this->apiResponse(CART_NOT_FOUND, 404, false);
        }

        if ($user && (int) $cart->user_id !== (int) $user->id) {
            return $this->apiResponse(DELETE_CART_ITEM_FAILED, 400, false);
        }

        if ($cart->coupon && !$request->boolean('confirm')) {
            return $this->apiResponse(COUPON_DELETE_CART_WARNING, 200, true);
        }

        $this->inventoryService->releaseCart($cart, true);
        return $this->apiResponse(DELETE_CART_SUCCESSFULLY, 200, true);
    }

    public function pluckItemsToCart(Request $request)
    {
        if (!$request->has('items')) {
            $content = $request->getContent();
            if (!empty($content)) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['items'])) {
                    $request->merge($data);
                }
            }
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.shipping_method' => 'nullable|string|in:scheduled,fast,SCHEDULED,FAST',
        ]);

        $items = collect($request->items)->map(function ($item) {
            $item['shipping_method'] = strtoupper($item['shipping_method'] ?? 'scheduled');
            return $item;
        });

        $userId = $request->user()->id;
        $existingIds = Product::whereIn('id', $items->pluck('product_id'))
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        $validItems = $items->filter(
            fn($item) => in_array($item['product_id'], $existingIds)
        );

        $skippedIds = $items
            ->reject(fn($item) => in_array($item['product_id'], $existingIds))
            ->pluck('product_id')
            ->values();

        $failedItems = [];

        foreach ($validItems as $item) {
            try {
                $tempRequest = clone $request;
                $tempRequest->replace(['item' => $item]);
                $this->repository->storeCart($tempRequest);
            } catch (\Exception $e) {
                $failedItems[] = [
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $cart = Cart::query()->where('user_id', $userId)->first();
        $response = $cart
            ? CartResource::make(
                $cart->load(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
              )
            : null;

        return $this->apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, [
            'cart' => $response,
            'skipped_product_ids' => $skippedIds,
            'failed_items' => $failedItems,
        ]);
    }
}
