<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\SectionType;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $homePage = ContentPage::firstOrCreate(
            ['slug' => 'home'],
            ['title' => ['en' => 'Home', 'ar' => 'الرئيسية'], 'is_active' => true]
        );

        $sections = [
            [
                'id' => 1,
                'type' => 'sliders',
                'title' => 'New Collection',
                'is_active' => true,
                'endpoint' => 'general/sliders?slug=new-collection',
                'order' => 1,
                'setting' => [
                    'front' => [
                        'autoplay' => true,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'new-collection',
                    ],
                ],
            ],
            [
                'id' => 31,
                'type' => 'categories',
                'title' => 'Shop by Category',
                'is_active' => true,
                'endpoint' => 'general/categories?limit=79&categoriesId%5B0%5D=1&categoriesId%5B1%5D=2&categoriesId%5B2%5D=3&categoriesId%5B3%5D=4&categoriesId%5B4%5D=5&categoriesId%5B5%5D=6&categoriesId%5B6%5D=7&categoriesId%5B7%5D=8&categoriesId%5B8%5D=9&categoriesId%5B9%5D=10&categoriesId%5B10%5D=11&categoriesId%5B11%5D=12&categoriesId%5B12%5D=13&categoriesId%5B13%5D=14&categoriesId%5B14%5D=15&categoriesId%5B15%5D=16&categoriesId%5B16%5D=17&categoriesId%5B17%5D=18&categoriesId%5B18%5D=19&categoriesId%5B19%5D=20&categoriesId%5B20%5D=21&categoriesId%5B21%5D=22&categoriesId%5B22%5D=23&categoriesId%5B23%5D=24&categoriesId%5B24%5D=25&categoriesId%5B25%5D=26&categoriesId%5B26%5D=27&categoriesId%5B27%5D=28&categoriesId%5B28%5D=29&categoriesId%5B29%5D=30&categoriesId%5B30%5D=31&categoriesId%5B31%5D=32&categoriesId%5B32%5D=33&categoriesId%5B33%5D=34&categoriesId%5B34%5D=35&categoriesId%5B35%5D=36&categoriesId%5B36%5D=37&categoriesId%5B37%5D=38&categoriesId%5B38%5D=39&categoriesId%5B39%5D=40&categoriesId%5B40%5D=41&categoriesId%5B41%5D=42&categoriesId%5B42%5D=43&categoriesId%5B43%5D=44&categoriesId%5B44%5D=45&categoriesId%5B45%5D=46&categoriesId%5B46%5D=47&categoriesId%5B47%5D=48&categoriesId%5B48%5D=49&categoriesId%5B49%5D=50&categoriesId%5B50%5D=51&categoriesId%5B51%5D=52&categoriesId%5B52%5D=53&categoriesId%5B53%5D=54&categoriesId%5B54%5D=55&categoriesId%5B55%5D=56&categoriesId%5B56%5D=57&categoriesId%5B57%5D=58&categoriesId%5B58%5D=59&categoriesId%5B59%5D=60&categoriesId%5B60%5D=61&categoriesId%5B61%5D=62&categoriesId%5B62%5D=63&categoriesId%5B63%5D=64&categoriesId%5B64%5D=65&categoriesId%5B65%5D=66&categoriesId%5B66%5D=67&categoriesId%5B67%5D=68&categoriesId%5B68%5D=69&categoriesId%5B69%5D=70&categoriesId%5B70%5D=71&categoriesId%5B71%5D=72&categoriesId%5B72%5D=73&categoriesId%5B73%5D=74&categoriesId%5B74%5D=75&categoriesId%5B75%5D=76&categoriesId%5B76%5D=77&categoriesId%5B77%5D=78&categoriesId%5B78%5D=79&order=desc',
                'order' => 2,
                'setting' => [
                    'front' => [
                        'columns_count' => 8,
                        'shape' => 'square',
                        'layout' => 'grid',
                    ],
                    'back' => [
                        'parent_only' => false,
                        'limit' => 79,
                        'categoriesId' => [
                            1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79,
                        ],
                        'order' => 'desc',
                    ],
                ],
            ],
            [
                'id' => 25,
                'type' => 'tags',
                'title' => 'Popular Tags',
                'is_active' => true,
                'endpoint' => 'general/tags?limit=10&tagsId%5B0%5D=1&tagsId%5B1%5D=2&tagsId%5B2%5D=3&tagsId%5B3%5D=6&tagsId%5B4%5D=7&tagsId%5B5%5D=8&tagsId%5B6%5D=13&tagsId%5B7%5D=14&order=desc',
                'order' => 3,
                'setting' => [
                    'front' => [
                        'autoplay' => true,
                        'slider_speed' => 3000,
                    ],
                    'back' => [
                        'limit' => 10,
                        'tagsId' => [1, 2, 3, 6, 7, 8, 13, 14],
                        'order' => 'desc',
                    ],
                ],
            ],
            [
                'id' => 32,
                'type' => 'banners',
                'title' => "Valentine's Day",
                'is_active' => true,
                'endpoint' => 'general/banners?slug=valentines-day&with_products=1',
                'order' => 4,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'valentines-day',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 6,
                'type' => 'flash-sales',
                'title' => 'Flash Deals',
                'is_active' => true,
                'endpoint' => 'general/flash-sales?limit=10&flashSalesId%5B0%5D=2&flashSalesId%5B1%5D=3&flashSalesId%5B2%5D=4&flashSalesId%5B3%5D=5&order=desc',
                'order' => 5,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'layout' => 'grid',
                        'show_timer' => true,
                        'timer_end_at' => '2026-09-04T00:00',
                        'theme' => 'colorful',
                        'autoplay' => true,
                        'slider_speed' => 4000,
                    ],
                    'back' => [
                        'limit' => 10,
                        'flashSalesId' => [2, 3, 4, 5],
                        'order' => 'desc',
                    ],
                ],
            ],
            [
                'id' => 26,
                'type' => 'products',
                'title' => 'Flash Sale Products',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=flash_sales_product',
                'order' => 6,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'badge_text' => 'FLASH SALE',
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'flash_sales_product',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 8,
                'type' => 'products',
                'title' => 'New Arrivals',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=new_arrivals',
                'order' => 7,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'badge_text' => 'NEW',
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'new_arrivals',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 2,
                'type' => 'banners',
                'title' => 'New Collection',
                'is_active' => true,
                'endpoint' => 'general/banners?slug=new-collection&with_products=1',
                'order' => 8,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'new-collection',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 7,
                'type' => 'products',
                'title' => 'Best Sellers',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=best_product_sales',
                'order' => 9,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'best_product_sales',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 3,
                'type' => 'promotions',
                'title' => 'Special Offers',
                'is_active' => true,
                'endpoint' => 'general/promotions?limit=6&promotionsId%5B0%5D=7&promotionsId%5B1%5D=1&promotionsId%5B2%5D=3&promotionsId%5B3%5D=13&promotionsId%5B4%5D=11&order=desc',
                'order' => 10,
                'setting' => [
                    'front' => [
                        'columns_count' => 3,
                        'layout' => 'grid',
                    ],
                    'back' => [
                        'limit' => 6,
                        'promotionsId' => [7, 1, 3, 13, 11],
                        'order' => 'desc',
                    ],
                ],
            ],
            [
                'id' => 23,
                'type' => 'banners',
                'title' => 'Back to School',
                'is_active' => true,
                'endpoint' => 'general/banners?slug=back-to-school&with_products=1',
                'order' => 11,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'back-to-school',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 27,
                'type' => 'products',
                'title' => 'Deals Ending This Week',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=flash_sales_end_week',
                'order' => 12,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'show_timer' => true,
                        'timer_end_at' => '2026-09-06T00:00',
                        'badge_text' => 'ENDING SOON',
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'flash_sales_end_week',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 15,
                'type' => 'products',
                'title' => 'Deals & Discounts',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=all_product_discounts',
                'order' => 13,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'badge_text' => 'SALE',
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'all_product_discounts',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 28,
                'type' => 'banners',
                'title' => 'Black Friday Deals',
                'is_active' => true,
                'endpoint' => 'general/banners?slug=black-friday-deals&with_products=1',
                'order' => 14,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'black-friday-deals',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 29,
                'type' => 'products',
                'title' => 'Last Chance',
                'is_active' => true,
                'endpoint' => 'general/products?limit=10&type=product_discount_today_or_low_qty',
                'order' => 15,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'badge_text' => 'LOW STOCK',
                    ],
                    'back' => [
                        'limit' => 10,
                        'type' => 'product_discount_today_or_low_qty',
                        'productsId' => [],
                    ],
                ],
            ],
            [
                'id' => 13,
                'type' => 'brands',
                'title' => 'Top Brands',
                'is_active' => true,
                'endpoint' => 'general/brands?limit=8&brandsId%5B0%5D=12&brandsId%5B1%5D=14&brandsId%5B2%5D=1&brandsId%5B3%5D=3&brandsId%5B4%5D=4&brandsId%5B5%5D=5&brandsId%5B6%5D=6&brandsId%5B7%5D=10&order=desc',
                'order' => 16,
                'setting' => [
                    'front' => [
                        'columns_count' => 8,
                        'layout' => 'grid',
                    ],
                    'back' => [
                        'limit' => 8,
                        'brandsId' => [12, 14, 1, 3, 4, 5, 6, 10],
                        'order' => 'desc',
                    ],
                ],
            ],
            [
                'id' => 30,
                'type' => 'banners',
                'title' => 'Cyber Monday',
                'is_active' => true,
                'endpoint' => 'general/banners?slug=cyber-monday&with_products=1',
                'order' => 17,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'cyber-monday',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 24,
                'type' => 'banners',
                'title' => 'Summer Sale',
                'is_active' => true,
                'endpoint' => 'general/banners?slug=summer-sale&with_products=1',
                'order' => 18,
                'setting' => [
                    'front' => [
                        'columns_count' => 5,
                        'autoplay' => false,
                        'slider_speed' => 5000,
                    ],
                    'back' => [
                        'slug' => 'summer-sale',
                        'with_products' => true,
                    ],
                ],
            ],
            [
                'id' => 18,
                'type' => 'coupons',
                'title' => 'Coupons & Vouchers',
                'is_active' => true,
                'endpoint' => 'general/coupons?limit=8&couponsId%5B0%5D=1&couponsId%5B1%5D=2&couponsId%5B2%5D=3&couponsId%5B3%5D=7&couponsId%5B4%5D=8&couponsId%5B5%5D=12&couponsId%5B6%5D=9&couponsId%5B7%5D=14&order=desc',
                'order' => 19,
                'setting' => [
                    'front' => [
                        'columns_count' => 4,
                        'layout' => 'grid',
                    ],
                    'back' => [
                        'limit' => 8,
                        'couponsId' => [1, 2, 3, 7, 8, 12, 9, 14],
                        'order' => 'desc',
                    ],
                ],
            ],
        ];

        $uniqueTypes = collect($sections)->pluck('type')->unique();
        foreach ($uniqueTypes as $type) {
            SectionType::firstOrCreate(['type' => $type]);
        }

        foreach ($sections as $data) {
            Section::updateOrCreate(
                ['id' => $data['id']],
                [
                    'type' => $data['type'],
                    'title' => ['en' => $data['title'], 'ar' => $data['title']],
                    'is_active' => $data['is_active'],
                    'endpoint' => $data['endpoint'],
                    'order' => $data['order'],
                    'content_page_id' => $homePage->id,
                    'title_visible' => true,
                    'setting' => $data['setting'],
                ]
            );

            DB::table('sections')->where('id', $data['id'])->update([
                'order' => $data['order'],
                'content_page_id' => $homePage->id,
            ]);
        }

        // Ensure exactly 19 sections for home page – remove any stale sections created by ContentPageSeeder
        // or previous runs that are not part of the canonical 19.
        $expectedIds = collect($sections)->pluck('id')->all();
        Section::where('content_page_id', $homePage->id)
            ->whereNotIn('id', $expectedIds)
            ->delete();
    }
}
