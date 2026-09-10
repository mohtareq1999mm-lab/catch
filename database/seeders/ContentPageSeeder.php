<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use Marvel\Models\ContentPage;

class ContentPageSeeder extends Seeder
{
    public function run(): void
    {
        $page = ContentPage::firstOrCreate([
            'slug' => 'home',
        ], [
            'title' => 'home',
            'is_active' => true,
        ]);

        $firstBannerSlug = Banner::active()->value('slug');
        $activePromotions = Promotion::active()->valid()->get();
        $firstPromotionSlug = $activePromotions->first()?->slug ?? 'summer-special-20-off';
        $activeCategories = Category::active()->limit(20)->pluck('id')->toArray();
        $activeBrands = Brand::active()->limit(6)->pluck('id')->toArray();
        $validFlashSales = FlashSale::valid()->limit(5)->pluck('id')->toArray();
        $activeCoupons = Coupon::valid()->limit(5)->pluck('id')->toArray();
        $activeSliders = Slider::active()->limit(5)->pluck('id')->toArray();

        $today = now()->format('Y-m-d');
        $nextMonth = now()->addMonth()->format('Y-m-d');

        $items = [
            // 0 — Hero slider (swiper), first thing user sees
            [
                'type' => 'sliders',
                'title' => ['en' => 'Sliders', 'ar' => 'سلايدر'],
                'endpoint' => 'sliders',
                'order' => 0,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        "start_date" => $today,
                        "end_date" => $nextMonth,
                        "limit" => 10,
                        "slidersId" => [],
                        "order" => "desc",
                    ],
                ]
            ],
            // 1 — Banner strip right after hero
            [
                'type' => 'banners',
                'title' => ['en' => 'Banners', 'ar' => 'بنرات'],
                'endpoint' => 'banners',
                'order' => 1,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        "slug" => $firstBannerSlug,
                        "with_products" => true
                    ],
                ]
            ],
            // 2 — Promotional offers
            [
                'type' => 'promotions',
                'title' => ['en' => 'Promotions', 'ar' => 'عروض'],
                'endpoint' => 'promotions',
                'order' => 2,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        "start_date" => $today,
                        "end_date" => $nextMonth,
                        "limit" => 10,
                        "promotionsId" => [],
                        "order" => "desc",
                    ],
                ]
            ],
            // 2 — tags offers
            [
                'type' => 'tags',
                'title' => ['en' => 'Tags', 'ar' => 'العلامات'],
                'endpoint' => 'tags',
                'order' => 2,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        "limit" => 10,
                        "tagsId" => [1,2,3,4,5,6,7,8,9,10],
                        "order" => "desc",
                    ],
                ]
            ],
            // 3 — Browse categories grid
            [
                'type' => 'categories',
                'title' => ['en' => 'Best Category', 'ar' => 'أفضل التصنيفات'],
                'endpoint' => 'categories',
                'order' => 3,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        "pest_category" => false,
                        "parent" => false,
                        "limit" => 16,
                        "categoriesId" => $activeCategories,
                        "order" => "desc",
                    ],
                ]
            ],
            // 4 — Time-limited flash sales with timer
            [
                'type' => 'flash-sales',
                'title' => ['en' => 'Flash Sales', 'ar' => 'عروض فلاش'],
                'endpoint' => 'flash-sales',
                'order' => 4,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        'start_date' => $today,
                        'end_date' => $nextMonth,
                        'limit' => 10,
                        'flashSalesId' => $validFlashSales,
                        'order' => 'desc',
                    ],
                ]
            ],
            // 5 — Best selling products (social proof)
            [
                'type' => 'products',
                'title' => ['en' => 'Best Product Sales', 'ar' => 'أفضل مبيعات المنتجات'],
                'endpoint' => 'products',
                'order' => 5,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        'limit' => 20,
                        'order' => 'desc',
                        'order_price' => 'asc',
                        'type' => null,
                        'productsId' => null,
                        'categoriesId' => null,
                        'brandsId' => null,
                        'promotionsId' => null,
                        'flashSalesId' => null,
                        'bannersId' => null,
                        'couponsId' => null,
                    ],
                ]
            ],
            // 6 — New arrivals
            [
                'type' => 'products',
                'title' => ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً'],
                'endpoint' => 'products',
                'order' => 6,
                'setting' => [
                    'front' => ['columns_count' => 5, 'badge_text' => 'NEW'],
                    'back' => ['limit' => 10, 'type' => 'new_arrivals', 'productsId' => []]
                ]
            ],
            // 7 — Products in active flash sales
            [
                'type' => 'products',
                'title' => ['en' => 'Flash Sales Products', 'ar' => 'منتجات عروض الفلاش'],
                'endpoint' => 'products',
                'order' => 7,
                'setting' => [
                    'front' => ['columns_count' => 5, 'show_timer' => true, 'timer_end_at' => $nextMonth . ' 00:00:00'],
                    'back' => ['limit' => 10, 'type' => 'flash_sales_product', 'productsId' => []]
                ]
            ],
            // 8 — Flash sales ending today
            [
                'type' => 'products',
                'title' => ['en' => 'Flash Sale End Today', 'ar' => 'تنتهي اليوم'],
                'endpoint' => 'products',
                'order' => 8,
                'setting' => [
                    'front' => ['columns_count' => 5, 'show_timer' => true],
                    'back' => ['limit' => 10, 'type' => 'flash_sales_end_today', 'productsId' => []]
                ]
            ],
            // 9 — Flash sales ending this week
            [
                'type' => 'products',
                'title' => ['en' => 'Flash Sale End Week', 'ar' => 'تنتهي هذا الأسبوع'],
                'endpoint' => 'products',
                'order' => 9,
                'setting' => [
                    'front' => ['columns_count' => 5, 'show_timer' => true],
                    'back' => ['limit' => 10, 'type' => 'flash_sales_end_week', 'productsId' => []]
                ]
            ],
            // 10 — Products filtered by brand
            [
                'type' => 'products',
                'title' => ['en' => 'Brands Products', 'ar' => 'منتجات العلامات التجارية'],
                'endpoint' => 'products',
                'order' => 10,
                'setting' => [
                    'front' => ['columns_count' => 5],
                    'back' => ['limit' => 10, 'type' => 'brands_product', 'productsId' => []]
                ]
            ],
            // 11 — Brand logo strip
            [
                'type' => 'brands',
                'title' => ['en' => 'Brand', 'ar' => 'العلامة التجارية'],
                'endpoint' => 'brands',
                'order' => 11,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        'start_date' => $today,
                        'end_date' => $nextMonth,
                        'limit' => 10,
                        'brandsId' => $activeBrands,
                        'order' => 'desc',
                    ],
                ]
            ],
            // 12 — Low stock / ending soon products
            [
                'type' => 'products',
                'title' => ['en' => 'Product Discount Days', 'ar' => 'أيام خصم المنتجات'],
                'endpoint' => 'products',
                'order' => 12,
                'setting' => [
                    'front' => ['columns_count' => 5, 'badge_text' => 'LOW STOCK'],
                    'back' => ['limit' => 10, 'type' => 'product_discount_today_or_low_qty', 'productsId' => []]
                ]
            ],
            // 13 — All discounted products
            [
                'type' => 'products',
                'title' => ['en' => 'All Discount Product', 'ar' => 'كل المنتجات المخفضة'],
                'endpoint' => 'products',
                'order' => 13,
                'setting' => [
                    'front' => ['columns_count' => 5],
                    'back' => ['limit' => 10, 'type' => 'all_product_discounts', 'productsId' => []]
                ]
            ],
            // 14 — Products for parent categories
            [
                'type' => 'products',
                'title' => ['en' => 'Product For Parent', 'ar' => 'منتجات للتصنيف الرئيسي'],
                'endpoint' => 'products',
                'order' => 14,
                'setting' => [
                    'front' => ['columns_count' => 5],
                    'back' => ['limit' => 10, 'type' => 'product_for_parent_category', 'productsId' => []]
                ]
            ],
            // 15 — Parent-only category links
            [
                'type' => 'categories',
                'title' => ['en' => 'Parent Category', 'ar' => 'التصنيف الرئيسي'],
                'endpoint' => 'categories',
                'order' => 15,
                'setting' => [
                    'front' => ['layout' => 'list'],
                    'back' => ['parent_only' => true, 'categoriesId' => $activeCategories]
                ]
            ],
            // 16 — Coupons at the bottom
            [
                'type' => 'coupons',
                'title' => ['en' => 'Coupons', 'ar' => 'كوبونات'],
                'endpoint' => 'coupons',
                'order' => 16,
                'setting' => [
                    'front' => ['autoplay' => true, 'slider_speed' => 5000],
                    'back' => [
                        'start_date' => $today,
                        'end_date' => $nextMonth,
                        'limit' => 10,
                        'couponsId' => $activeCoupons,
                        'order' => 'desc',
                    ],
                ]
            ],
        ];

        $page->sections()->delete();

        $createdTypes = [];
        foreach ($items as $item) {
            $settingData = $item['setting'] ?? [];

            $page->sections()->create($item);

            if (!in_array($item['type'], $createdTypes)) {
                $sectionType = SectionType::firstOrCreate(['type' => $item['type']]);

                SectionTypeSetting::where('section_type_id', $sectionType->id)->delete();

                if ($front = $settingData['front'] ?? null) {
                    SectionTypeSetting::create([
                        'section_type_id' => $sectionType->id,
                        'setting_key' => 'front',
                        'value' => $front,
                    ]);
                }
                if ($back = $settingData['back'] ?? []) {
                    SectionTypeSetting::create([
                        'section_type_id' => $sectionType->id,
                        'setting_key' => 'back',
                        'value' => $back,
                    ]);
                }
                $createdTypes[] = $item['type'];
            }
        }
    }
}
