<?php


namespace Marvel\Database\Repositories;

use App\Services\Coupon\CouponCalculator;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shipping;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Variation;
use Marvel\Enums\DiscountType;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckoutRepository
{
    use WalletsTrait;

    public function verify($request)
    {
        if ($request['customer_id']) {
            try {
                $user = User::findOrFail($request->customer_id);
            } catch (\Throwable $th) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
        } else {
            $user = $request->user() ?? null;
        }
        $wallet = $user->wallet ?? null;
        $settings = Settings::getData();
        $minimumOrderAmount = (float) ($settings['minimum_order_amount'] ?? 0);
        $unavailable_products = $this->checkStock($request['products']);
        $amount = $this->getOrderAmount($request, $unavailable_products);
        $shipping_charge = !empty($settings['options']['freeShipping']) && $settings['options']['freeShippingAmount'] <= $amount ? 0 : $this->calculateShippingCharge($request, $amount);
        $tax = $this->calculateTax($request, $shipping_charge, $amount);
        $total = $amount + $tax + $shipping_charge;

        $couponCode = null;
        $couponDiscount = 0.0;
        $cart = $user ? Cart::where('user_id', $user->id)->where('status', 'active')->first() : null;
        if ($cart && $cart->coupon) {
            $couponCode = $cart->coupon;
            $couponModel = Coupon::where('code', $couponCode)->first();
            if ($couponModel) {
                $calculation = CouponCalculator::calculate($couponModel, $amount);
                $couponDiscount = $calculation['discountAmount'];
                if ($calculation['freeShipping']) {
                    $shipping_charge = 0;
                }
            }
        }

        if ($total < $minimumOrderAmount) {
            throw new HttpException(400, 'Minimum order amount is ' . $minimumOrderAmount);
        }
        return [
            'total_tax'            => $tax,
            'shipping_charge'      => $shipping_charge,
            'unavailable_products' => $unavailable_products,
            'wallet_amount' => isset($wallet->available_points) ? $wallet->available_points : 0,
            'wallet_currency' => isset($wallet->available_points) ? $this->walletPointsToCurrency($wallet->available_points) : 0,
            'coupon_code'    => $couponCode,
            'coupon_discount' => $couponDiscount,
        ];
    }

    public function getOrderAmount($request, $unavailable_products)
    {
        if (count($unavailable_products)) {
            return $this->calculateAmountWithAvailable($request['products'], $unavailable_products);
        }
        return  $request['amount'];
    }

    public function calculateTax($request, $shipping_charge, $amount)
    {
        // Tax is now product-owned / settings-owned direct rates; legacy
        // TaxClass path always returns 0. Kept as a no-op for the legacy
        // CheckoutRepository::verify() route (if still referenced).
        return 0;
    }

    public function calculateAmountWithAvailable($products, $unavailable_products)
    {
        $amount = 0;
        foreach ($products as $product) {
            if (!in_array($product['product_id'], $unavailable_products)) {
                $amount += $product['subtotal'];
            }
        }
        return $amount;
    }

    public function calculateShippingCharge($request, $amount)
    {
        try {
            $ordered_products = $request['products'];
            $physical_products = Product::whereIn('id', Arr::pluck($ordered_products, 'product_id'))->get();
            if (!count($physical_products)) {
                return 0;
            }
            $settings = Settings::getData();
            $class_id = $settings['options']['shippingClass'];
            if ($class_id) {
                $shipping_class = Shipping::find($class_id);
                return $this->getShippingCharge($shipping_class, $amount);
            } else {
                return $this->calculateShippingChargeByProduct($request['products']);
            }
        } catch (\Throwable $th) {
            return 0;
        }
    }

    protected function calculateShippingChargeByProduct($products)
    {
        $total_charge = 0;
        foreach ($products as $product) {
            $total_charge += $this->calculateEachProductCharge($product['product_id'], $product['subtotal']);
        }
        return $total_charge;
    }

    protected function calculateEachProductCharge($id, $amount)
    {
        $product = Product::with('shipping')->findOrFail($id);
        if (isset($product->shipping)) {
            return $this->getShippingCharge($product->shipping, $amount);
        }
        return 0;
    }

    public function checkStock($products)
    {
        $unavailable_products = [];
        foreach ($products as $product) {
            if (isset($product['variation_option_id'])) {
                $is_not_in_stock = $this->isVariationInStock($product['variation_option_id'], $product['order_quantity']);
            } else {
                $is_not_in_stock = $this->isInStock($product['product_id'], $product['order_quantity']);
            }
            if ($is_not_in_stock) {
                $unavailable_products[] = $is_not_in_stock;
            }
        }
        return $unavailable_products;
    }

    protected function isInStock($id, $order_quantity)
    {
        try {
            $product = Product::findOrFail($id);
            if ($order_quantity > $product->quantity) {
                return $id;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function isVariationInStock($variation_id, $order_quantity)
    {
        try {
            $variationOption = Variation::findOrFail($variation_id);
            if ($order_quantity > $variationOption->quantity) {
                return $variationOption->product_id;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function getShippingCharge($shipping_class, $amount)
    {
        switch ($shipping_class->type) {
            case 'fixed':
                return $shipping_class->amount;
                break;
            case 'percentage':
                return ($shipping_class->amount * $amount) / 100;
                break;
            default:
                return 0;
        }
    }


}
