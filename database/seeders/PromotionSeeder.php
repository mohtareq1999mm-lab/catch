<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;

class PromotionSeeder extends Seeder
{
    private const PROMOTION_COUNT = 20;

    public function run(): void
    {
        $productIds = Product::query()->pluck('id')->all();
        $promotionImages = File::exists(public_path('images/flash'))
            ? collect(File::files(public_path('images/flash')))
            : collect();

        $promotions = [
            ['type' => 'percentage', 'name' => ['en' => 'Summer Special 20% Off', 'ar' => 'عرض الصيف خصم 20%'], 'discount' => 20, 'max' => 100],
            ['type' => 'fixed', 'name' => ['en' => '50 EGP Off Electronics', 'ar' => 'خصم 50 ج على الإلكترونيات'], 'discount' => 50, 'max' => null],
            ['type' => 'gift', 'name' => ['en' => 'Buy 2 Get 1 Free', 'ar' => 'اشتر 2 واحصل على 1 مجاناً'], 'discount' => 0, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Fresh Food 15% Cashback', 'ar' => '15% كاش باك على الأطعمة الطازجة'], 'discount' => 15, 'max' => 75],
            ['type' => 'fixed', 'name' => ['en' => '100 EGP Off First Grocery Order', 'ar' => 'خصم 100 ج على أول طلب بقالة'], 'discount' => 100, 'max' => null],
            ['type' => 'gift', 'name' => ['en' => 'Free Dessert with Every Meal', 'ar' => 'حلوى مجانية مع كل وجبة'], 'discount' => 0, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Beauty Products 25% Off', 'ar' => 'خصم 25% على منتجات التجميل'], 'discount' => 25, 'max' => 120],
            ['type' => 'fixed', 'name' => ['en' => '75 EGP Off Home Appliances', 'ar' => 'خصم 75 ج على الأجهزة المنزلية'], 'discount' => 75, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Winter Clearance 40% Off', 'ar' => 'تخفيضات الشتاء 40%'], 'discount' => 40, 'max' => 200],
            ['type' => 'fixed', 'name' => ['en' => '200 EGP Off TVs & Audio', 'ar' => 'خصم 200 ج على التلفزيونات'], 'discount' => 200, 'max' => null],
            ['type' => 'gift', 'name' => ['en' => 'Free Gift Wrap with Purchase', 'ar' => 'تغليف هدايا مجاني مع المشتريات'], 'discount' => 0, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Kids Fashion 30% Off', 'ar' => 'خصم 30% على أزياء الأطفال'], 'discount' => 30, 'max' => 150],
            ['type' => 'fixed', 'name' => ['en' => 'Free Shipping on Orders Over 500', 'ar' => 'شحن مجاني للطلبات فوق 500 ج'], 'discount' => 40, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Sports Gear 20% Off', 'ar' => 'خصم 20% على المعدات الرياضية'], 'discount' => 20, 'max' => 100],
            ['type' => 'fixed', 'name' => ['en' => '150 EGP Off Smartphones', 'ar' => 'خصم 150 ج على الهواتف الذكية'], 'discount' => 150, 'max' => null],
            ['type' => 'gift', 'name' => ['en' => 'Free Sample with Beauty Purchase', 'ar' => 'عينة مجانية مع مشتريات التجميل'], 'discount' => 0, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Baby Products 15% Off', 'ar' => 'خصم 15% على منتجات الأطفال'], 'discount' => 15, 'max' => 60],
            ['type' => 'fixed', 'name' => ['en' => '30 EGP Off Beverages 6-Pack', 'ar' => 'خصم 30 ج على المشروبات 6 حبات'], 'discount' => 30, 'max' => null],
            ['type' => 'percentage', 'name' => ['en' => 'Furniture & Home 35% Off', 'ar' => 'خصم 35% على الأثاث المنزلي'], 'discount' => 35, 'max' => 300],
            ['type' => 'gift', 'name' => ['en' => 'Free Tote Bag with Orders Over 1000', 'ar' => 'حقيبة مجانية للطلبات فوق 1000 ج'], 'discount' => 0, 'max' => null],
        ];

        foreach ($promotions as $index => $promoData) {
            $promotion = match ($promoData['type']) {
                'gift' => $this->createGiftPromotion($index + 1, $productIds, $promoData),
                'percentage' => $this->createPercentagePromotion($index + 1, $productIds, $promoData),
                default => $this->createFixedPromotion($index + 1, $productIds, $promoData),
            };

            $this->attachPromotionImage($promotion, $promotionImages, $index);
        }
    }

    private function createPercentagePromotion(int $index, array $productIds, array $promoData): Promotion
    {
        $requiredProductIds = $this->randomProductIds($productIds, rand(0, 1) === 1 ? rand(1, 3) : 0);
        $discount = $promoData['discount'];

        $promotion = $this->createPromotion([
            'name' => $promoData['name'],
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => $discount,
            'discount' => $discount,
            'max_discount_amount' => $promoData['max'],
            'required_quantity_type' => rand(1, 3),
            'minimum_order_amount' => collect([0, 250, 500, 750, 1000])->random(),
            'apply_to' => empty($requiredProductIds) ? 'all_products' : 'specific_products',
        ]);

        $promotion->products()->sync($requiredProductIds);

        return $promotion;
    }

    private function createFixedPromotion(int $index, array $productIds, array $promoData): Promotion
    {
        $requiredProductIds = $this->randomProductIds($productIds, rand(0, 1) === 1 ? rand(1, 3) : 0);
        $discount = $promoData['discount'];

        $promotion = $this->createPromotion([
            'name' => $promoData['name'],
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => $discount,
            'discount' => $discount,
            'max_discount_amount' => null,
            'required_quantity_type' => rand(1, 3),
            'minimum_order_amount' => collect([0, 300, 600, 900])->random(),
            'apply_to' => empty($requiredProductIds) ? 'all_products' : 'specific_products',
        ]);

        $promotion->products()->sync($requiredProductIds);

        return $promotion;
    }

    private function createGiftPromotion(int $index, array $productIds, array $promoData): Promotion
    {
        $requiredProductIds = $this->randomProductIds($productIds, rand(1, 3));
        $giftProductIds = $this->randomProductIds($productIds, rand(1, 2), $requiredProductIds);

        if (empty($giftProductIds) && !empty($productIds)) {
            $giftProductIds = $this->randomProductIds($productIds, 1);
        }

        $promotion = $this->createPromotion([
            'name' => $promoData['name'],
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'max_discount_amount' => null,
            'required_quantity_type' => rand(2, 5),
            'minimum_order_amount' => collect([0, 250, 500])->random(),
            'apply_to' => empty($requiredProductIds) ? 'all_products' : 'specific_products',
        ]);

        $promotion->products()->sync($requiredProductIds);

        $giftProducts = [];

        foreach ($giftProductIds as $productId) {
            $variant = ProductVariant::where('product_id', $productId)->first();

            if (!$variant) {
                $variant = ProductVariant::inRandomOrder()->first();
            }

            if (!$variant) {
                continue;
            }

            $giftProducts[(int) $productId] = [
                'quantity' => rand(1, 2),
                'product_variant_id' => $variant->id,
            ];
        }

        if (empty($giftProducts)) {
            $fallback = ProductVariant::inRandomOrder()->first();
            if ($fallback) {
                $giftProducts[(int) $fallback->product_id] = ['quantity' => 1, 'product_variant_id' => $fallback->id];
            }
        }

        $promotion->giftProducts()->sync($giftProducts);

        return $promotion;
    }

    private function createPromotion(array $attributes): Promotion
    {
        $applyTo = $attributes['apply_to'] ?? 'specific_products';

        $titleOrName = $attributes['name'] ?? $attributes['title'] ?? null;
        if ($titleOrName && is_array($titleOrName)) {
            $attributes['slug'] = $this->makeUniqueSlug($titleOrName['en'] ?? '');
        }

        $model = Promotion::create(array_merge([
            'code' => $this->generatePromotionCode($applyTo),
            'limiter' => rand(25, 250),
            'usage' => 0,
            'start_at' => Carbon::now()->subDays(rand(0, 10)),
            'end_at' => Carbon::now()->addDays(rand(10, 60)),
            'status' => true,
        ], $attributes));

        return $model;
    }

    private function makeUniqueSlug(string $name): string
    {
        $base = Str::slug($name ?: 'item');
        $candidate = $base;
        $i = 1;
        while (Promotion::where('slug', $candidate)->exists()) {
            $i++;
            $candidate = $base . '-' . $i;
        }
        return $candidate;
    }

    private function generatePromotionCode(string $applyTo, int $length = 8): string
    {
        $prefix = match ($applyTo) {
            'all_products' => 'ALL',
            'specific_products' => 'PRO',
            'specific_categories' => 'CAT',
            default => 'PRO',
        };

        return $prefix . strtoupper(Str::random($length));
    }

    private function randomProductIds(array $productIds, int $count, array $exclude = []): array
    {
        $availableProductIds = array_values(array_diff($productIds, $exclude));

        if ($count <= 0 || empty($availableProductIds)) {
            return [];
        }

        return collect($availableProductIds)
            ->shuffle()
            ->take(min($count, count($availableProductIds)))
            ->values()
            ->all();
    }

    private function attachPromotionImage(Promotion $promotion, $promotionImages, int $index): void
    {
        try {
            $imagesToAttach = 2;

            if ($promotionImages->isNotEmpty()) {
                $total = $promotionImages->count();
                for ($i = 0; $i < $imagesToAttach; $i++) {
                    $image = $promotionImages[($index + $i) % $total];
                    $collection = $i % 2 === 0 ? 'promotions-desktop' : 'promotions-mobile';
                    $promotion->addMedia($image->getPathname())
                        ->preservingOriginal()
                        ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                        ->toMediaCollection($collection, 'promotions');
                }

                return;
            }

            for ($i = 0; $i < $imagesToAttach; $i++) {
                $seed = $index . '-' . $i;
                $collection = $i % 2 === 0 ? 'promotions-desktop' : 'promotions-mobile';
                $promotion->addMediaFromUrl('https://picsum.photos/seed/promotion' . $seed . '/1200/400')
                    ->toMediaCollection($collection, 'promotions');
            }
        } catch (\Exception $e) {
            // Image attachment should not block demo data creation.
        }
    }
}
