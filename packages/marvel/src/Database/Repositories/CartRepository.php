<?php

namespace Marvel\Database\Repositories;

use App\Services\General\CartInventoryService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
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
                // F-04: the one-cart-per-user invariant is backed by
                // UNIQUE(carts.user_id). Two app instances racing first-cart
                // creation can both pass the SELECT above; the loser hits a
                // duplicate-key error here and must resolve the winner's row
                // instead of surfacing a generic 500/400.
                try {
                    $cart = Cart::create([
                        'user_id' => $userId,
                        'status' => 'active',
                    ]);
                } catch (QueryException $e) {
                    if (!self::isDuplicateKeyError($e)) {
                        throw $e;
                    }

                    $cart = Cart::query()
                        ->where('user_id', $userId)
                        ->lockForUpdate()
                        ->first();

                    if (!$cart) {
                        throw $e;
                    }
                }
            }
            
            $cart->update(['status' => 'active']);

            if ($request->filled('item')) {
                // Single item-validation/mutation path shared by authenticated
                // and guest carts. applyItemToCart locks the cart row and
                // recalculates totals from server-side pricing only.
                $cart = $this->applyItemToCart($cart, (array) ($request->item ?? []), $mode);
            } else {
                $cart->update([
                    'total_price' => $cart->items()->sum('total_price'),
                ]);
            }

            DB::commit();

            return $cart;
        } catch (AuthorizationException $e) {
            DB::rollBack();
            throw new HttpException(401, $e->getMessage());
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(400, $e->getMessage());
        }
    }

    /**
     * Apply one validated cart line to an already-owned cart.
     *
     * Reused by the guest cart path so product availability, variant
     * resolution, channel/shipping eligibility, quantity bounds and
     * authoritative pricing stay in ONE place. Never trusts client prices.
     */
    public function applyItemToCart(Cart $cart, array $item, string $mode): Cart
    {
        return DB::transaction(function () use ($cart, $item, $mode) {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $cart->update(['status' => 'active']);

            if (!$this->syncItems($cart, $item, $mode)) {
                throw new Exception(INVALID_ITEM_DATA);
            }

            $cart->update([
                'total_price' => round((float) $cart->items()->sum('total_price'), 2),
            ]);

            return $cart->load(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute']);
        });
    }

    /**
     * F-04: true only for unique-constraint violations (MySQL 1062 /
     * SQLSTATE 23000). Any other database error must keep propagating.
     */
    private static function isDuplicateKeyError(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[1] ?? null) === 1062
            || $e->getCode() === '23000'
            || (string) $e->getCode() === '1062';
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

        $product = Product::query()->active()->findOrFail($productId);
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
