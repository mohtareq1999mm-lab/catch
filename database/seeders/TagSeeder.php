<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Tag;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => ['en' => 'Organic', 'ar' => 'عضوي']],
            ['name' => ['en' => 'Vegan', 'ar' => 'نباتي']],
            ['name' => ['en' => 'Cruelty Free', 'ar' => 'خالٍ من القسوة']],
            ['name' => ['en' => 'Hypoallergenic', 'ar' => 'مضاد للحساسية']],
            ['name' => ['en' => 'Non Comedogenic', 'ar' => 'غير مسد للمسام']],
            ['name' => ['en' => 'Dermatologist Tested', 'ar' => 'مختبر من قبل أطباء الجلد']],
            ['name' => ['en' => 'SPF Protection', 'ar' => 'حماية من الشمس']],
            ['name' => ['en' => 'Anti Aging', 'ar' => 'مضاد للشيخوخة']],
            ['name' => ['en' => 'Matte Finish', 'ar' => 'لمسة نهائية غير لامعة']],
            ['name' => ['en' => 'Dewy Finish', 'ar' => 'لمسة نهائية ندية']],
            ['name' => ['en' => 'Long Lasting', 'ar' => 'طويل الأمد']],
            ['name' => ['en' => 'Waterproof', 'ar' => 'مقاوم للماء']],
            ['name' => ['en' => 'Fragrance Free', 'ar' => 'خالٍ من العطور']],
            ['name' => ['en' => 'Paraben Free', 'ar' => 'خالٍ من البارابين']],
            ['name' => ['en' => 'Sulfate Free', 'ar' => 'خالٍ من الكبريتات']],
            ['name' => ['en' => 'Oil Free', 'ar' => 'خالٍ من الزيوت']],
            ['name' => ['en' => 'Alcohol Free', 'ar' => 'خالٍ من الكحول']],
            ['name' => ['en' => 'Mineral Based', 'ar' => 'قائم على المعادن']],
            ['name' => ['en' => 'Professional', 'ar' => 'احترافي']],
            ['name' => ['en' => 'Travel Size', 'ar' => 'حجم السفر']],
            ['name' => ['en' => 'Gift Set', 'ar' => 'طقم هدايا']],
            ['name' => ['en' => 'Limited Edition', 'ar' => 'إصدار محدود']],
            ['name' => ['en' => 'New Arrival', 'ar' => 'وصل حديثاً']],
            ['name' => ['en' => 'Best Seller', 'ar' => 'الأكثر مبيعاً']],
            ['name' => ['en' => 'Sale', 'ar' => 'تخفيضات']],
            ['name' => ['en' => 'Men', 'ar' => 'رجالي']],
            ['name' => ['en' => 'Women', 'ar' => 'نسائي']],
            ['name' => ['en' => 'Unisex', 'ar' => 'للجنسين']],
            ['name' => ['en' => 'Natural Ingredients', 'ar' => 'مكونات طبيعية']],
            ['name' => ['en' => 'Sustainable', 'ar' => 'مستدام']],
        ];

        foreach ($tags as $tagData) {
            $name = $tagData['name'];
            $enName = $name['en'];

            Tag::firstOrCreate(
                ['name->en' => $enName],
                ['name' => $name]
            );
        }
    }
}
