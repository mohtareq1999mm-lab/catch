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


            $sections = array (
  0 => 
  array (
    'id' => 25,
    'type' => 'hero',
    'title' => 
    array (
      'en' => 'Hero',
      'ar' => 'الهيرو',
    ),
    'title_visible' => false,
    'is_active' => true,
    'endpoint' => 'general/hero?',
    'order' => 1,
    'setting' => 
    array (
      'front' => 
      array (
        'media_type' => 'video',
        'video_desktop' => 'https://meem.mohammedtareq.me/storage/heroes/10132/hailuo-03_A_vibrant_high-energy_commercial_hero_video_for_a_modern_Middle_Eastern_family_s-0.mp4',
        'video_mobile' => 'https://meem.mohammedtareq.me/storage/heroes/10132/hailuo-03_A_vibrant_high-energy_commercial_hero_video_for_a_modern_Middle_Eastern_family_s-0.mp4',
        'poster' => 'https://meem.mohammedtareq.me/storage/heroes/9894/1FujlKpl9HsCrwBfAcXv06krzJrJwzPQOZHs3LsGcO8Q8U8fY9J_2XOFLg1xYSu8b9VKvALKF2W2S0TkA-mDMeWUU2aeP9R9Hevl1_kS8Sab0NHZH9NUmWNE98SLpsa_bXmR3l0qglWUbh3nPyZ5BS-j4FxgZKfvAj5bAsj-mrk4XEMcukHuYF9W7ZZ0X_a',
        'eyebrow' => 
        array (
          'en' => 'Discover Meem',
          'ar' => 'اكتشف عروض ميم',
        ),
        'heading' => 
        array (
          'en' => NULL,
          'ar' => NULL,
        ),
        'cta_label' => 
        array (
          'en' => 'Shop Now',
          'ar' => 'تسوق الآن',
        ),
        'cta_href' => 'Consequatur Nostrud',
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
      ),
      'back' => 
      array (
        'slug' => NULL,
      ),
    ),
  ),
  1 => 
  array (
    'id' => 5,
    'type' => 'categories',
    'title' => 
    array (
      'en' => 'Shop by Category',
      'ar' => 'تسوق حسب الفئات',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/categories?limit=8&categoriesId%5B0%5D=26&categoriesId%5B1%5D=21&categoriesId%5B2%5D=22&categoriesId%5B3%5D=69&categoriesId%5B4%5D=42&categoriesId%5B5%5D=13&categoriesId%5B6%5D=27&categoriesId%5B7%5D=2&order=desc',
    'order' => 2,
    'setting' => 
    array (
      'front' => 
      array (
        'subtitle' => 
        array (
          'en' => 'Explore our curated edit',
          'ar' => 'اكتشفي تشكيلتنا المختارة',
        ),
        'show_view_all' => true,
        'action_label' => 
        array (
          'en' => 'View all',
          'ar' => 'عرض الكل',
        ),
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
      ),
      'back' => 
      array (
        'parent_only' => false,
        'limit' => 8,
        'categoriesId' => 
        array (
          0 => 26,
          1 => 21,
          2 => 22,
          3 => 69,
          4 => 42,
          5 => 13,
          6 => 27,
          7 => 2,
        ),
        'order' => 'desc',
        'pest_category' => false,
        'parent' => false,
      ),
    ),
  ),
  2 => 
  array (
    'id' => 21,
    'type' => 'banners',
    'title' => 
    array (
      'en' => 'Black Friday Deals',
      'ar' => 'عروض الجمعة السوداء',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/banners?slug=back-to-school',
    'order' => 3,
    'setting' => 
    array (
      'front' => 
      array (
        'columns_count' => 5,
      ),
      'back' => 
      array (
        'slug' => 'back-to-school',
        'with_products' => false,
      ),
    ),
  ),
  3 => 
  array (
    'id' => 9,
    'type' => 'products',
    'title' => 
    array (
      'en' => 'Black Friday Flash Sale',
      'ar' => 'عروض الجمعة السوداء السريعة',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/products?limit=10&order=desc&flashSalesId%5B0%5D=1',
    'order' => 4,
    'setting' => 
    array (
      'front' => 
      array (
        'columns_count' => 5,
        'badge_text' => 
        array (
          'en' => 'FLASH SALE',
          'ar' => 'عرض سريع',
        ),
        'show_title' => true,
        'show_timer' => true,
        'timer_end_at' => '2026-12-05T00:00',
      ),
      'back' => 
      array (
        'limit' => 10,
        'productsId' => 
        array (
        ),
        'order' => 'desc',
        'type' => NULL,
        'flashSalesId' => 
        array (
          0 => 1,
        ),
      ),
    ),
  ),
  4 => 
  array (
    'id' => 37,
    'type' => 'banners',
    'title' => 
    array (
      'en' => 'Winter Clearance',
      'ar' => 'تخفيضات الشتاء',
    ),
    'title_visible' => false,
    'is_active' => true,
    'endpoint' => 'general/banners?slug=winter-clearance',
    'order' => 5,
    'setting' => 
    array (
      'front' => 
      array (
      ),
      'back' => 
      array (
        'slug' => 'winter-clearance',
      ),
    ),
  ),
  5 => 
  array (
    'id' => 36,
    'type' => 'flash-sales',
    'title' => 
    array (
      'en' => 'Flash Sales',
      'ar' => 'عروض الفلاش',
    ),
    'title_visible' => false,
    'is_active' => true,
    'endpoint' => 'general/flash-sales?flashSalesId%5B0%5D=1&flashSalesId%5B1%5D=2&flashSalesId%5B2%5D=3&flashSalesId%5B3%5D=4',
    'order' => 6,
    'setting' => 
    array (
      'front' => 
      array (
      ),
      'back' => 
      array (
        'flashSalesId' => 
        array (
          0 => 1,
          1 => 2,
          2 => 3,
          3 => 4,
        ),
      ),
    ),
  ),
  6 => 
  array (
    'id' => 34,
    'type' => 'banners',
    'title' => 
    array (
      'en' => 'Summer Sale',
      'ar' => 'تخفيضات الصيف',
    ),
    'title_visible' => false,
    'is_active' => true,
    'endpoint' => 'general/banners?slug=summer-sale',
    'order' => 7,
    'setting' => 
    array (
      'front' => 
      array (
      ),
      'back' => 
      array (
        'slug' => 'summer-sale',
      ),
    ),
  ),
  7 => 
  array (
    'id' => 7,
    'type' => 'products',
    'title' => 
    array (
      'en' => 'Best Sellers',
      'ar' => 'الأكثر مبيعاً',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/products?limit=10&type=best_product_sales',
    'order' => 8,
    'setting' => 
    array (
      'front' => 
      array (
        'columns_count' => 5,
        'show_title' => true,
      ),
      'back' => 
      array (
        'limit' => 10,
        'type' => 'best_product_sales',
        'productsId' => 
        array (
        ),
      ),
    ),
  ),
  8 => 
  array (
    'id' => 35,
    'type' => 'banners',
    'title' => 
    array (
      'en' => 'New Collection',
      'ar' => 'تشكيلة جديدة',
    ),
    'title_visible' => false,
    'is_active' => true,
    'endpoint' => 'general/banners?slug=new-collection',
    'order' => 9,
    'setting' => 
    array (
      'front' => 
      array (
      ),
      'back' => 
      array (
        'slug' => 'new-collection',
      ),
    ),
  ),
  9 => 
  array (
    'id' => 12,
    'type' => 'products',
    'title' => 
    array (
      'en' => 'Fragrance',
      'ar' => 'عطور',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/products?limit=10&categoriesId%5B0%5D=155',
    'order' => 10,
    'setting' => 
    array (
      'front' => 
      array (
        'columns_count' => 5,
        'badge_text' => 
        array (
          'en' => 'FRAGRANCE',
          'ar' => 'عطور',
        ),
        'subtitle' => 
        array (
          'en' => 'Signature scents',
          'ar' => 'عطور مميزة',
        ),
        'show_title' => true,
        'show_timer' => false,
      ),
      'back' => 
      array (
        'limit' => 10,
        'type' => NULL,
        'productsId' => 
        array (
        ),
        'categoriesId' => 
        array (
          0 => 155,
        ),
      ),
    ),
  ),
  10 => 
  array (
    'id' => 13,
    'type' => 'brands',
    'title' => 
    array (
      'en' => 'Featured Brands',
      'ar' => 'ماركات مميزة',
    ),
    'title_visible' => true,
    'is_active' => true,
    'endpoint' => 'general/brands?limit=8&brandsId%5B0%5D=1&brandsId%5B1%5D=3&brandsId%5B2%5D=6&brandsId%5B3%5D=10&brandsId%5B4%5D=220&brandsId%5B5%5D=247&brandsId%5B6%5D=2&brandsId%5B7%5D=4&brandsId%5B8%5D=5&order=asc',
    'order' => 11,
    'setting' => 
    array (
      'front' => 
      array (
        'subtitle' => 
        array (
          'en' => 'Beauty brands to discover',
          'ar' => 'علامات جمالية للاكتشاف',
        ),
        'show_view_all' => true,
        'action_label' => 
        array (
          'en' => 'View all',
          'ar' => 'عرض الكل',
        ),
        'action_href' => '/brands',
        'layout' => 'grid',
        'columns_count' => 8,
      ),
      'back' => 
      array (
        'limit' => 8,
        'brandsId' => 
        array (
          0 => 1,
          1 => 3,
          2 => 6,
          3 => 10,
          4 => 220,
          5 => 247,
          6 => 2,
          7 => 4,
          8 => 5,
        ),
        'order' => 'asc',
        'start_date' => NULL,
        'end_date' => NULL,
      ),
    ),
  ),
);

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
