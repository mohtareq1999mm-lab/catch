<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\General\PromotionEngine\PromotionEligibilityResolver;
use Marvel\Enums\PromotionMountType;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ProductType;

class PromotionEligibilityResolverTest extends TestCase
{
    public function test_specific_products_discount_applies_to_matched_subtotal()
    {
        $resolver = new PromotionEligibilityResolver();

        // Build cart with mixed items
        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 1000, 'is_gift' => false],
            (object) ['product_id' => 2, 'quantity' => 2, 'total_price' => 400, 'is_gift' => false],
            (object) ['product_id' => 3, 'quantity' => 3, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        // Create promotion that applies only to products 1 and 2
        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::PERCENTAGE;
        $promotion->discount = 20; // 20%
        $promotion->apply_to = 'specific_products';
        $promotion->status = true;
        // set related products
        $promotion->setRelation('products', collect([(object)['id' => 1], (object)['id' => 2]]));
        $promotion->setRelation('giftProducts', collect());

        $subtotal = 1500.0;

        $result = $resolver->resolve($cart, $promotion, $subtotal);

        $this->assertNotNull($result);
        $this->assertEquals(280.0, $result->discount);
    }

    public function test_apply_to_all_applies_to_full_subtotal()
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 1000, 'is_gift' => false],
            (object) ['product_id' => 2, 'quantity' => 2, 'total_price' => 400, 'is_gift' => false],
            (object) ['product_id' => 3, 'quantity' => 3, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::PERCENTAGE;
        $promotion->discount = 20; // 20%
        $promotion->apply_to = 'all_products';
        $promotion->status = true;
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect());

        $subtotal = 1500.0;

        $result = $resolver->resolve($cart, $promotion, $subtotal);

        $this->assertNotNull($result);
        $this->assertEquals(3.0, $result->discount);
    }

    public function test_gift_promotion_returns_gift_items_and_no_discount()
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::GIFT;
        $promotion->discount = 0;
        $promotion->apply_to = 'all_products';
        $promotion->status = true;
        // giftProducts pivot entries
        $giftProduct = (object) ['id' => 99, 'name' => 'Free Pen', 'sku' => 'PEN99', 'available_stock' => 4, 'pivot' => (object) ['quantity' => 1]];
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect([$giftProduct]));

        $subtotal = 100.0;

        $result = $resolver->resolve($cart, $promotion, $subtotal);

        $this->assertNotNull($result);
        $this->assertEquals(0.0, $result->discount);
        $this->assertNotEmpty($result->giftItems);
        $this->assertEquals(99, $result->giftItems[0]['product_id']);
    }

    public function test_gift_promotion_returns_only_available_gift_items()
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::GIFT;
        $promotion->discount = 0;
        $promotion->apply_to = 'all_products';
        $promotion->status = true;

        $firstGift = (object) ['id' => 101, 'name' => 'Gift One', 'sku' => 'GIFT-1', 'available_stock' => 3, 'pivot' => (object) ['quantity' => 1]];
        $secondGift = (object) ['id' => 102, 'name' => 'Gift Two', 'sku' => 'GIFT-2', 'available_stock' => 0, 'pivot' => (object) ['quantity' => 1]];
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect([$firstGift, $secondGift]));

        $result = $resolver->resolve($cart, $promotion, 10000);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->giftItems);
        $this->assertEquals(101, $result->giftItems[0]['product_id']);
    }

    public function test_promotion_math_uses_original_line_total_not_discounted_total_price()
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) [
                'product_id' => 1,
                'quantity' => 2,
                'price' => 1000,
                'total_price' => 1700,
                'is_gift' => false,
            ],
        ]);
        $cart->setRelation('items', $cartItems);

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::PERCENTAGE;
        $promotion->discount = 10;
        $promotion->apply_to = 'all_products';
        $promotion->status = true;
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect());

        $evaluation = $resolver->matchedEligibility($cart, $promotion, 200000);

        $this->assertEquals(200000, $evaluation->matchedSubtotalCents);

        $result = $resolver->resolve($cart, $promotion, 200000);

        $this->assertNotNull($result);
        $this->assertEquals(200.0, $result->discount);
    }

    public function test_gift_promotion_includes_variant_payload_when_configured(): void
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        $product = new Product();
        $product->id = 200;
        $product->name = 'Gift Product';
        $product->sku = 'GIFT-200';
        $product->product_type = ProductType::VARIABLE;
        $product->stock_quantity = 0;
        $product->reserved_quantity = 0;

        $variant = new ProductVariant();
        $variant->id = 7;
        $variant->product_id = 200;
        $variant->stock_quantity = 5;
        $variant->reserved_quantity = 0;
        $variant->price = 120.0;
        $variant->sale_price = null;
        $variant->height = '10';
        $variant->width = '20';
        $variant->length = '30';
        $variant->weight = '2';
        $variant->setRelation('attributeProducts', collect());
        $variant->setRelation('product', $product);

        $product->setRelation('variations', collect([$variant]));
        $product->pivot = (object) ['quantity' => 1, 'product_variant_id' => 7];

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::GIFT;
        $promotion->discount = 0;
        $promotion->apply_to = 'all_products';
        $promotion->status = true;
        $promotion->minimum_order_amount = 0;
        $promotion->required_quantity_type = null;
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect([$product]));

        $result = $resolver->resolve($cart, $promotion, 10000);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result->giftItems);
        $this->assertEquals(7, $result->giftItems[0]['product_variant_id']);
        $this->assertEquals(7, $result->giftItems[0]['product_variant']['id']);
    }

    public function test_promotion_not_eligible_when_minimum_order_not_met(): void
    {
        $resolver = new PromotionEligibilityResolver();

        $cart = new Cart();
        $cartItems = collect([
            (object) ['product_id' => 1, 'quantity' => 1, 'total_price' => 100, 'is_gift' => false],
        ]);
        $cart->setRelation('items', $cartItems);

        $promotion = new Promotion();
        $promotion->type_amount = PromotionMountType::PERCENTAGE;
        $promotion->discount = 10;
        $promotion->apply_to = 'all_products';
        $promotion->status = true;
        $promotion->minimum_order_amount = 200; // above subtotal
        $promotion->required_quantity_type = null;
        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect());

        $result = $resolver->resolve($cart, $promotion, 10000);

        $this->assertNull($result);
    }
}
