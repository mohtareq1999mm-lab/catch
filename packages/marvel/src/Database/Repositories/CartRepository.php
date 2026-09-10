<?php

namespace Marvel\Database\Repositories;

use App\Services\General\CartInventoryService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Enums\CartOperation;
use Marvel\Enums\ShippingMethod;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CartRepository extends BaseRepository
{
    public function revalidatePromotion(Cart $cart): void
    {
        $hasPromotionItems = $cart->items()
            ->where(function ($q) {
                $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
            })
            ->exists();

        if (!$hasPromotionItems) {
            return;
        }

$affected = $cart->items()
            ->where(function ($q) {
                $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
            })
            ->where('is_gift', false)
            ->update([
                'promotion_id' => null,
                'discount_amount' => 0,
                'total_price' => DB::raw('ROUND(price * quantity, 2)'),
            ]);

        if ($affected > 0) {
            $cart->update([
                'total_price' => $cart->items()->sum('total_price'),
            ]);
        }
    }

    protected $fieldSearchable = [
        'user_id',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    public function model()
    {
        return Cart::class;
    }

    public function storeCart(Request $request)
    {

        return $this->persistCart($request, 'add');
    }

    public function updateCart(Request $request)
    {
        return $this->persistCart($request, 'set');
    }

    private function persistCart(Request $request, string $mode)
    {
        try {
            DB::beginTransaction();

            $userId = $request->user()?->id;
            if (!$userId) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            
            $cart = Cart::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
            
            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $userId,
                    'status' => 'active',
                ]);
            }
            
            $cart->update(['status' => 'active']);

            if ($request->filled('item')) {
                if (!$this->syncItems($cart, $request->item ?? [], $mode)) {
                    throw new Exception(INVALID_ITEM_DATA);
                }
            }

            $cart->update([
                'total_price' => $cart->items()->sum('total_price'),
            ]);

            DB::commit();

            return $cart->load(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute']);
        } catch (AuthorizationException $e) {
            DB::rollBack();
            throw new HttpException(401, $e->getMessage());
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(400, $e->getMessage());
        }
    }

    private function syncItems(Cart $cart, array $item, string $mode): bool
    {
        $productId = $item['product_id'] ?? null;
        $quantity = (int) ($item['quantity'] ?? 0);
        $variantId = $item['product_variant_id'] ?? null;
        $attributes = $item['attributes'] ?? [];
        $shippingMethod = strtoupper($item['shipping_method'] ?? ShippingMethod::SCHEDULED);
        $operation = $item['operation'] ?? CartOperation::INCREMENT;

        $existingItem = $cart->items()
            ->where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('product_variant_id', $variantId), fn($q) => $q->whereNull('product_variant_id'))
            ->where('is_gift', false)
            ->first();

        if ($mode === 'set' && !isset($item['shipping_method']) && $existingItem) {
            $shippingMethod = $existingItem->shipping_method;
        }

        if (!$productId || $quantity < 1) {
            return false;
        }

        $product = Product::findOrFail($productId);
        $productName = is_array($product->name) ? ($product->name[app()->getLocale()] ?? $product->name['en'] ?? '') : $product->name;

        if ($shippingMethod === ShippingMethod::FAST && !$product->is_fast_shipping_available) {
            throw new Exception(__('message.MESSAGE.FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE', ['product_name' => $productName]));
        }

        if ($variantId) {
            $variant = $product->variations()->whereKey($variantId)->first();
            if (!$variant) {
                throw new Exception(__('message.ERROR.INVALID_ITEM_DATA', ['product_name' => $productName]));
            }
        }

        if ($product->product_type === 'variable' && !$variantId) {
            throw new Exception(__('message.ERROR.INVALID_ITEM_DATA', ['product_name' => $productName]));
        }

        $inventoryService = app(CartInventoryService::class);

        if ($operation === CartOperation::INCREMENT) {
            $inventoryService->incrementItem($cart, $product, $variantId ? $variant : null, $quantity, $attributes, $shippingMethod);
        } elseif ($operation === CartOperation::DECREMENT) {
            $inventoryService->decrementItem($cart, $product, $variantId ? $variant : null, $quantity, $shippingMethod);
        }

        return true;
    }
}
