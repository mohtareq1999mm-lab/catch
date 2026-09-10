<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

class SectionTypeSettingSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'type' => 'banners',
                'front' => [],
                'back'  => [
                    "slug" => "",
                ],
            ],
            [
                'type' => 'sliders',
                'front' => ['autoplay' => true, 'slider_speed' => 5000],
                'back'  => [
                    'limit' => 10,
                    'slidersId' => [],
                    'order' => 'desc',
                ],
            ],
            [
                'type' => 'promotions',
                'front' => ['autoplay' => true, 'slider_speed' => 5000],
                'back'  => [
                    'slug' => null,
                    'with_product' => true,
                    'order' => 'desc',
                ],
            ],
            [
                'type' => 'categories',
                'front' => [],
                'back'  => [
                    'parent' => true,
                    'limit' => 10,
                    'categoriesId' => [],
                    'order' => 'desc',
                ],
            ],
            [
                'type' => 'products',
                'front' =>[],
                'back'  => [
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
            ],
            [
                'type' => 'flash-sales',
                'front' => ['autoplay' => true, 'slider_speed' => 5000],
                'back'  => [
                    'start_date' => '2026-06-01',
                    'end_date' => '2026-06-20',
                    'limit' => 10,
                    'flashSalesId' => [],
                    'order' => 'desc',
                ],
            ],
            [
                'type' => 'brands',
                'front' => ['autoplay' => true, 'slider_speed' => 5000],
                'back'  => [
                    'start_date' => '2026-06-01',
                    'end_date' => '2026-06-20',
                    'limit' => 10,
                    'brandsId' => [],
                    'order' => 'desc',
                ],
            ],
            [
                'type' => 'coupons',
                'front' => ['autoplay' => true, 'slider_speed' => 5000],
                'back'  => [
                    'start_date' => '2026-06-01',
                    'end_date' => '2026-06-20',
                    'limit' => 10,
                    'couponsId' => [],
                    'order' => 'desc',
                ],
            ],
        ];

        foreach ($types as $item) {
            $sectionType = SectionType::firstOrCreate(['type' => $item['type']]);

            SectionTypeSetting::updateOrCreate(
                ['section_type_id' => $sectionType->id, 'setting_key' => 'front'],
                ['value' => $item['front']]
            );

            SectionTypeSetting::updateOrCreate(
                ['section_type_id' => $sectionType->id, 'setting_key' => 'back'],
                ['value' => $item['back']]
            );
        }
    }
}
