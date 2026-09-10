<?php

namespace Tests\Unit;

use Tests\TestCase;
use Marvel\Enums\PromotionMountType;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Cart;
use App\Services\General\PromotionEngine\PromotionEvaluation;
use App\Services\General\PromotionEngine\Strategies\PercentagePromotionStrategy;
use App\Services\General\PromotionEngine\Strategies\FixedPromotionStrategy;
use App\Services\General\PromotionEngine\Strategies\GiftPromotionStrategy;
use App\Services\General\PromotionEngine\Outcome\DiscountOutcome;
use App\Services\General\PromotionEngine\Outcome\GiftOutcome;
use Illuminate\Support\Collection;

class PromotionStrategyTest extends TestCase
{
    private Cart $cart;
    private PromotionEvaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = new Cart();
        $this->cart->setRelation('items', collect([
            (object) ['product_id' => 1, 'quantity' => 2, 'total_price' => 10000, 'is_gift' => false],
        ]));

        $this->evaluation = new PromotionEvaluation(
            $this->cart->items,
            10000,
            2
        );
    }

    private function makePromotion(array $overrides = []): Promotion
    {
        $defaults = [
            'type_amount' => PromotionMountType::PERCENTAGE,
            'discount' => 20,
            'value' => null,
            'status' => true,
            'start_at' => null,
            'end_at' => null,
            'limiter' => null,
            'usage' => 0,
            'minimum_order_amount' => null,
            'required_quantity_type' => null,
            'max_discount_amount' => null,
            'apply_to' => 'all_products',
        ];

        $attrs = array_merge($defaults, $overrides);
        $promotion = new Promotion();

        foreach ($attrs as $key => $value) {
            $promotion->$key = $value;
        }

        $promotion->setRelation('products', collect());
        $promotion->setRelation('giftProducts', collect());

        return $promotion;
    }

    /** PercentagePromotionStrategy */
    public function test_percentage_eligible_returns_true_when_valid()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion();

        $this->assertTrue($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_percentage_eligible_returns_false_when_invalid()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['status' => false]);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_percentage_compute_outcome_returns_correct_discount()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['discount' => 20]);

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertInstanceOf(DiscountOutcome::class, $outcome);
        $this->assertSame(2000, $outcome->amountCents);
        $this->assertSame(10000, $outcome->baseAmountCents);
    }

    public function test_percentage_compute_outcome_respects_max_discount_amount()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion([
            'discount' => 50,
            'max_discount_amount' => 30.0,
        ]);

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertSame(3000, $outcome->amountCents);
    }

    /** FixedPromotionStrategy */
    public function test_fixed_eligible_returns_true_when_valid()
    {
        $strategy = new FixedPromotionStrategy();
        $promotion = $this->makePromotion(['type_amount' => PromotionMountType::FIXED_RATE]);

        $this->assertTrue($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_fixed_compute_outcome_returns_fixed_discount()
    {
        $strategy = new FixedPromotionStrategy();
        $promotion = $this->makePromotion([
            'type_amount' => PromotionMountType::FIXED_RATE,
            'discount' => 15,
        ]);

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertInstanceOf(DiscountOutcome::class, $outcome);
        $this->assertSame(1500, $outcome->amountCents);
    }

    public function test_fixed_compute_outcome_caps_at_subtotal()
    {
        $strategy = new FixedPromotionStrategy();
        $promotion = $this->makePromotion([
            'type_amount' => PromotionMountType::FIXED_RATE,
            'discount' => 200,
        ]);

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertSame(10000, $outcome->amountCents);
    }

    /** GiftPromotionStrategy */
    public function test_gift_eligible_returns_false_when_no_gift_products()
    {
        $strategy = new GiftPromotionStrategy();
        $promotion = $this->makePromotion(['type_amount' => PromotionMountType::GIFT]);
        $promotion->setRelation('giftProducts', collect());

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_gift_eligible_returns_true_when_gift_products_exist()
    {
        $strategy = new GiftPromotionStrategy();
        $promotion = $this->makePromotion(['type_amount' => PromotionMountType::GIFT]);
        $giftProduct = (object) ['id' => 99, 'name' => 'Free Gift', 'sku' => 'GIFT-99', 'available_stock' => 5];
        $promotion->setRelation('giftProducts', collect([$giftProduct]));

        $this->assertTrue($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_gift_compute_outcome_returns_gift_outcome()
    {
        $strategy = new GiftPromotionStrategy();
        $promotion = $this->makePromotion(['type_amount' => PromotionMountType::GIFT]);
        $giftProduct = (object) [
            'id' => 99,
            'name' => 'Free Pen',
            'sku' => 'PEN-99',
            'available_stock' => 10,
            'pivot' => (object) ['quantity' => 2, 'product_variant_id' => null],
        ];
        $promotion->setRelation('giftProducts', collect([$giftProduct]));

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertInstanceOf(GiftOutcome::class, $outcome);
        $this->assertCount(1, $outcome->giftItems);
        $this->assertSame(99, $outcome->giftItems[0]->productId);
        $this->assertSame('Free Pen', $outcome->giftItems[0]->productName);
        $this->assertSame(2, $outcome->giftItems[0]->quantity);
        $this->assertTrue($outcome->giftItems[0]->isGift);
    }

    public function test_gift_compute_outcome_filters_out_of_stock_gifts()
    {
        $strategy = new GiftPromotionStrategy();
        $promotion = $this->makePromotion(['type_amount' => PromotionMountType::GIFT]);
        $inStock = (object) [
            'id' => 101, 'name' => 'In Stock', 'sku' => 'STK-101',
            'available_stock' => 3, 'pivot' => (object) ['quantity' => 1, 'product_variant_id' => null],
        ];
        $outOfStock = (object) [
            'id' => 102, 'name' => 'Out Of Stock', 'sku' => 'OOS-102',
            'available_stock' => 0, 'pivot' => (object) ['quantity' => 1, 'product_variant_id' => null],
        ];
        $promotion->setRelation('giftProducts', collect([$inStock, $outOfStock]));

        $outcome = $strategy->computeOutcome($promotion, $this->cart, 10000, $this->evaluation);

        $this->assertCount(1, $outcome->giftItems);
        $this->assertSame(101, $outcome->giftItems[0]->productId);
    }

    /** AbstractPromotionStrategy (eligible edge cases) */
    public function test_eligible_returns_false_when_minimum_order_not_met()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['minimum_order_amount' => 200]);

        $evaluation = new PromotionEvaluation($this->cart->items, 5000, 2);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 5000, $evaluation));
    }

    public function test_eligible_returns_false_when_required_quantity_not_met()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['required_quantity_type' => 5]);

        $evaluation = new PromotionEvaluation($this->cart->items, 10000, 2);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $evaluation));
    }

    public function test_eligible_returns_false_when_promotion_usage_exceeded()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['limiter' => 10, 'usage' => 10]);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_eligible_returns_false_when_promotion_not_started()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['start_at' => now()->addDay()]);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }

    public function test_eligible_returns_false_when_promotion_expired()
    {
        $strategy = new PercentagePromotionStrategy();
        $promotion = $this->makePromotion(['end_at' => now()->subDay()]);

        $this->assertFalse($strategy->eligible($promotion, $this->cart, 10000, $this->evaluation));
    }
}
