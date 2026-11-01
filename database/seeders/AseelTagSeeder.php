<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Tag;

class AseelTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => ['en' => 'Whole Spices', 'ar' => 'توابل صحيحة']],
            ['name' => ['en' => 'Ground Spices', 'ar' => 'توابل مطحونة']],
            ['name' => ['en' => 'Spice Blends', 'ar' => 'خلطات بهارات']],
            ['name' => ['en' => 'Arabic Spices', 'ar' => 'بهارات عربية']],
            ['name' => ['en' => 'Gulf Spices', 'ar' => 'بهارات خليجية']],
            ['name' => ['en' => 'Kabsa Spices', 'ar' => 'بهارات كبسة']],
            ['name' => ['en' => 'Biryani Masala', 'ar' => 'بهارات برياني']],
            ['name' => ['en' => 'Shawarma Spices', 'ar' => 'بهارات شاورما']],
            ['name' => ['en' => 'BBQ & Grill Seasoning', 'ar' => 'تتبيلة مشويات']],
            ['name' => ['en' => 'Poultry Seasoning', 'ar' => 'بهارات دواجن']],
            ['name' => ['en' => 'Meat Seasoning', 'ar' => 'بهارات لحوم']],
            ['name' => ['en' => 'Fish & Seafood Spices', 'ar' => 'بهارات سمك ومأكولات بحرية']],
            ['name' => ['en' => 'Seven Spices', 'ar' => 'السبع بهارات']],
            ['name' => ['en' => 'Garam Masala', 'ar' => 'جارام ماسالا']],
            ['name' => ['en' => 'Curry Powder', 'ar' => 'بودرة كاري']],
            ['name' => ['en' => 'Dried Herbs', 'ar' => 'أعشاب مجففة']],
            ['name' => ['en' => 'Aromatic Herbs', 'ar' => 'أعشاب عطرية']],
            ['name' => ['en' => 'Hot & Spicy', 'ar' => 'حار']],
            ['name' => ['en' => 'Mild & Sweet', 'ar' => 'هادئ وحلو']],
            ['name' => ['en' => 'Smoked Spices', 'ar' => 'توابل مدخنة']],
            ['name' => ['en' => 'Freshly Ground', 'ar' => 'مطحون طازج']],
            ['name' => ['en' => '100% Natural', 'ar' => 'طبيعي 100%']],
            ['name' => ['en' => 'Organic Spices', 'ar' => 'توابل عضوية']],
            ['name' => ['en' => 'No Preservatives', 'ar' => 'بدون مواد حافظة']],
            ['name' => ['en' => 'No Artificial Colors', 'ar' => 'بدون ألوان صناعية']],
            ['name' => ['en' => 'Gluten Free', 'ar' => 'خالٍ من الجلوتين']],
            ['name' => ['en' => 'Premium Grade', 'ar' => 'درجة أولى فاخرة']],
            ['name' => ['en' => 'Saffron & Cardamom', 'ar' => 'زعفران وهيل']],
            ['name' => ['en' => 'Ramadan Essentials', 'ar' => 'أساسيات رمضان']],
            ['name' => ['en' => 'Best Seller', 'ar' => 'الأكثر مبيعاً']],
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

