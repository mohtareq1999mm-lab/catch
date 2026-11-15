<?php

namespace Database\Seeders;

use App\Enums\FrontendResource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\SectionType;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;

class MeemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Find or create the 'home' content page with ID 1 if possible
            $homePage = ContentPage::where('slug', 'home')->first();

            if (! $homePage) {
                $homePage = ContentPage::find(1);
                if ($homePage) {
                    $homePage->update([
                        'slug' => 'home',
                        'title' => ['en' => '', 'ar' => ''],
                        'is_active' => true,
                    ]);
                } else {
                    $homePage = ContentPage::create([
                        'id' => 1,
                        'slug' => 'home',
                        'title' => ['en' => '', 'ar' => ''],
                        'is_active' => true,
                    ]);
                }
            } else {
                $homePage->update([
                    'title' => ['en' => '', 'ar' => ''],
                    'is_active' => true,
                ]);
            }

            $sections = [
                [
                    'id' => 25,
                    'type' => 'hero',
                    'title' => ['en' => 'Hero', 'ar' => 'الهيرو'],
                    'title_visible' => false,
                    'is_active' => true,
                    'endpoint' => 'general/hero?',
                    'order' => 1,
                    'setting' => [
                        'front' => [
                            'media_type' => 'video',
                            'video_desktop' => 'https://catch.mohammedtareq.me/storage/heroes/13897/8558303-hd_1080_1920_30fps.mp4',
                            'video_mobile' => 'https://catch.mohammedtareq.me/storage/heroes/13897/8558303-hd_1080_1920_30fps.mp4',
                            'poster' => '/hero/catch-hero-poster.jpg',
                            'eyebrow' => [
                                'en' => 'Catch Beauty',
                                'ar' => 'كاتش بيوتي',
                            ],
                            'heading' => [
                                'en' => 'New Collection',
                                'ar' => 'المجموعة الجديدة',
                            ],
                            'cta_label' => [
                                'en' => 'Shop the collection',
                                'ar' => 'تسوقي المجموعة',
                            ],
                            'cta_href' => '/sliders/new-collection-s2',
                            'show_cta' => true,
                            'show_overlay' => true,
                            'content_align' => 'center',
                            'content_position' => 'center',
                            'height' => 'standard',
                            'show_mobile_content' => false,
                            'tone' => 'light',
                            'autoplay' => true,
                            'loop' => true,
                            'muted' => true,
                        ],
                        'back' => [
                            'slug' => null,
                        ],
                    ],
                ],
                [
                    'id' => 21,
                    'type' => 'banners',
                    'title' => ['en' => 'Black Friday Deals', 'ar' => 'عروض الجمعة السوداء'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/banners?slug=black-friday-deals-s2',
                    'order' => 2,
                    'setting' => [
                        'front' => [
                            'columns_count' => 5,
                        ],
                        'back' => [
                            'slug' => 'black-friday-deals-s2',
                            'with_products' => false,
                        ],
                    ],
                ],
                [
                    'id' => 5,
                    'type' => 'categories',
                    'title' => ['en' => 'Shop by Category', 'ar' => 'تسوق حسب الفئات'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/categories?limit=8&categoriesId%5B0%5D=155&categoriesId%5B1%5D=120&categoriesId%5B2%5D=61&categoriesId%5B3%5D=13&categoriesId%5B4%5D=82&categoriesId%5B5%5D=113&categoriesId%5B6%5D=57&categoriesId%5B7%5D=139&categoriesId%5B8%5D=1&categoriesId%5B9%5D=2&order=desc',
                    'order' => 3,
                    'setting' => [
                        'front' => [
                            'subtitle' => [
                                'en' => 'Explore our curated edit',
                                'ar' => 'اكتشفي تشكيلتنا المختارة',
                            ],
                            'show_view_all' => true,
                            'action_label' => [
                                'en' => 'View all',
                                'ar' => 'عرض الكل',
                            ],
                            'action_href' => '/categories',
                            'show_product_count' => true,
                            'shape' => 'circle',
                            'layout' => 'grid',
                            'columns_count' => 5,
                            'rows_count' => 2,
                            'tablet_columns_count' => 5,
                            'tablet_rows_count' => 2,
                            'mobile_columns_count' => 4,
                            'mobile_rows_count' => 2,
                            'balance_last_row' => true,
                        ],
                        'back' => [
                            'parent_only' => false,
                            'limit' => 8,
                            'categoriesId' => [
                                155,
                                120,
                                61,
                                13,
                                82,
                                113,
                                57,
                                139,
                                1,
                                2,
                            ],
                            'order' => 'desc',
                            'pest_category' => false,
                            'parent' => false,
                        ],
                    ],
                ],
                [
                    'id' => 9,
                    'type' => 'products',
                    'title' => ['en' => 'Black Friday Flash Sale', 'ar' => 'عروض الجمعة السوداء السريعة'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/products?limit=10&order=desc&flashSalesId%5B0%5D=1',
                    'order' => 4,
                    'setting' => [
                        'front' => [
                            'columns_count' => 5,
                            'badge_text' => [
                                'en' => 'FLASH SALE',
                                'ar' => 'عرض سريع',
                            ],
                            'show_title' => true,
                            'show_timer' => true,
                            'timer_end_at' => '2026-12-05T00:00',
                        ],
                        'back' => [
                            'limit' => 10,
                            'productsId' => [],
                            'order' => 'desc',
                            'type' => null,
                            'flashSalesId' => [
                                1,
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 7,
                    'type' => 'products',
                    'title' => ['en' => 'Best Sellers', 'ar' => 'الأكثر مبيعاً'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/products?limit=10&type=best_product_sales',
                    'order' => 5,
                    'setting' => [
                        'front' => [
                            'columns_count' => 5,
                            'show_title' => true,
                        ],
                        'back' => [
                            'limit' => 10,
                            'type' => 'best_product_sales',
                            'productsId' => [],
                        ],
                    ],
                ],
                [
                    'id' => 12,
                    'type' => 'products',
                    'title' => ['en' => 'Fragrance', 'ar' => 'عطور'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/products?limit=10&categoriesId%5B0%5D=155',
                    'order' => 6,
                    'setting' => [
                        'front' => [
                            'columns_count' => 5,
                            'badge_text' => [
                                'en' => 'FRAGRANCE',
                                'ar' => 'عطور',
                            ],
                            'subtitle' => [
                                'en' => 'Signature scents',
                                'ar' => 'عطور مميزة',
                            ],
                            'show_title' => true,
                            'show_timer' => false,
                        ],
                        'back' => [
                            'limit' => 10,
                            'type' => null,
                            'productsId' => [],
                            'categoriesId' => [
                                155,
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 13,
                    'type' => 'brands',
                    'title' => ['en' => 'Featured Brands', 'ar' => 'ماركات مميزة'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/brands?limit=8&brandsId%5B0%5D=1&brandsId%5B1%5D=3&brandsId%5B2%5D=6&brandsId%5B3%5D=10&brandsId%5B4%5D=220&brandsId%5B5%5D=247&brandsId%5B6%5D=2&brandsId%5B7%5D=4&brandsId%5B8%5D=5&order=asc',
                    'order' => 7,
                    'setting' => [
                        'front' => [
                            'subtitle' => [
                                'en' => 'Beauty brands to discover',
                                'ar' => 'علامات جمالية للاكتشاف',
                            ],
                            'show_view_all' => true,
                            'action_label' => [
                                'en' => 'View all',
                                'ar' => 'عرض الكل',
                            ],
                            'action_href' => '/brands',
                            'layout' => 'grid',
                            'columns_count' => 8,
                        ],
                        'back' => [
                            'limit' => 8,
                            'brandsId' => [
                                1,
                                3,
                                6,
                                10,
                                220,
                                247,
                                2,
                                4,
                                5,
                            ],
                            'order' => 'asc',
                            'start_date' => null,
                            'end_date' => null,
                        ],
                    ],
                ],
                [
                    'id' => 18,
                    'type' => 'coupons',
                    'title' => ['en' => 'Coupons & Vouchers', 'ar' => 'كوبونات وقسائم'],
                    'title_visible' => true,
                    'is_active' => true,
                    'endpoint' => 'general/coupons?limit=3&couponsId%5B0%5D=17&couponsId%5B1%5D=19&couponsId%5B2%5D=20&order=desc',
                    'order' => 8,
                    'setting' => [
                        'front' => [
                            'subtitle' => [
                                'en' => 'Available offers',
                                'ar' => 'عروض متاحة',
                            ],
                            'show_title' => true,
                        ],
                        'back' => [
                            'limit' => 3,
                            'couponsId' => [
                                17,
                                19,
                                20,
                            ],
                            'order' => 'desc',
                        ],
                    ],
                ],
            ];

            // Ensure required section types exist
            $types = collect($sections)->pluck('type')->unique();
            foreach ($types as $type) {
                SectionType::firstOrCreate(['type' => $type]);
            }

            foreach ($sections as $data) {
                Section::updateOrCreate(
                    ['id' => $data['id']],
                    [
                        'type' => $data['type'],
                        'title' => $data['title'],
                        'is_active' => $data['is_active'],
                        'endpoint' => $data['endpoint'],
                        'order' => $data['order'],
                        'content_page_id' => $homePage->id,
                        'title_visible' => $data['title_visible'],
                        'setting' => $data['setting'],
                    ]
                );

                // Enforce exact order and page relation (bypassing SortableTrait auto-increment)
                DB::table('sections')->where('id', $data['id'])->update([
                    'order' => $data['order'],
                    'content_page_id' => $homePage->id,
                ]);
            }

            // Remove any other sections attached to home page to maintain this exact layout
            $expectedIds = collect($sections)->pluck('id')->all();
            Section::where('content_page_id', $homePage->id)
                ->whereNotIn('id', $expectedIds)
                ->delete();
        });

        // Invalidate content pages cache
        try {
            Cache::tags([FrontendResource::CONTENT_PAGES->value])->flush();
        } catch (\Throwable $e) {
            Cache::flush();
        }
    }
}
