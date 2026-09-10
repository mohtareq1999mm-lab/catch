<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Marvel\Database\Models\Brand;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brandImages = collect(File::files(public_path('images/brand')));
        $brandImagesCount = $brandImages->count();

        $brands = [
            ['name' => ['en' => 'Apple', 'ar' => 'أبل'], 'details' => ['en' => 'Premium electronics and smartphones', 'ar' => 'إلكترونيات وهواتف ذكية فاخرة']],
            ['name' => ['en' => 'Samsung', 'ar' => 'سامسونج'], 'details' => ['en' => 'Consumer electronics and appliances', 'ar' => 'إلكترونيات وأجهزة منزلية']],
            ['name' => ['en' => 'Sony', 'ar' => 'سوني'], 'details' => ['en' => 'Audio, visual and gaming', 'ar' => 'صوتيات ومرئيات وألعاب']],
            ['name' => ['en' => 'LG', 'ar' => 'إل جي'], 'details' => ['en' => 'Home appliances and electronics', 'ar' => 'أجهزة منزلية وإلكترونيات']],
            ['name' => ['en' => 'Nike', 'ar' => 'نايك'], 'details' => ['en' => 'Sportswear and athletic shoes', 'ar' => 'ملابس رياضية وأحذية']],
            ['name' => ['en' => 'Adidas', 'ar' => 'أديداس'], 'details' => ['en' => 'Sportswear and accessories', 'ar' => 'ملابس رياضية وإكسسوارات']],
            ['name' => ['en' => 'Puma', 'ar' => 'بوما'], 'details' => ['en' => 'Athletic footwear and apparel', 'ar' => 'أحذية وملابس رياضية']],
            ['name' => ['en' => 'Zara', 'ar' => 'زارا'], 'details' => ['en' => 'Fashion clothing and accessories', 'ar' => 'ملابس وإكسسوارات أزياء']],
            ['name' => ['en' => 'H&M', 'ar' => 'إتش أند إم'], 'details' => ['en' => 'Fast fashion clothing', 'ar' => 'ملابس أزياء سريعة']],
            ['name' => ['en' => 'IKEA', 'ar' => 'ايكيا'], 'details' => ['en' => 'Home furniture and decor', 'ar' => 'أثاث منزلي وديكور']],
            ['name' => ['en' => 'Philips', 'ar' => 'فيليبس'], 'details' => ['en' => 'Home appliances and personal care', 'ar' => 'أجهزة منزلية وعناية شخصية']],
            ['name' => ['en' => 'L\'Oréal', 'ar' => 'لوريال'], 'details' => ['en' => 'Cosmetics and beauty products', 'ar' => 'مستحضرات تجميل ومنتجات جمال']],
            ['name' => ['en' => 'Nivea', 'ar' => 'نيفيا'], 'details' => ['en' => 'Skincare and personal care', 'ar' => 'عناية بالبشرة وعناية شخصية']],
            ['name' => ['en' => 'Dove', 'ar' => 'دوف'], 'details' => ['en' => 'Personal care and beauty', 'ar' => 'عناية شخصية وجمال']],
            ['name' => ['en' => 'Pepsi', 'ar' => 'بيبسي'], 'details' => ['en' => 'Carbonated drinks and beverages', 'ar' => 'مشروبات غازية ومشروبات']],
            ['name' => ['en' => 'Coca-Cola', 'ar' => 'كوكاكولا'], 'details' => ['en' => 'Carbonated soft drinks', 'ar' => 'مشروبات غازية']],
            ['name' => ['en' => 'Nestlé', 'ar' => 'نستله'], 'details' => ['en' => 'Food and beverage products', 'ar' => 'منتجات غذائية ومشروبات']],
            ['name' => ['en' => 'Kellogg\'s', 'ar' => 'كيلوجز'], 'details' => ['en' => 'Breakfast cereals and snacks', 'ar' => 'حبوب الإفطار والوجبات الخفيفة']],
            ['name' => ['en' => 'Lay\'s', 'ar' => 'لايز'], 'details' => ['en' => 'Potato chips and snacks', 'ar' => 'رقائق البطاطس والوجبات الخفيفة']],
            ['name' => ['en' => 'Lindt', 'ar' => 'ليندت'], 'details' => ['en' => 'Premium chocolate and confectionery', 'ar' => 'شوكولاتة وحلويات فاخرة']],
            ['name' => ['en' => 'Cadbury', 'ar' => 'كادبوري'], 'details' => ['en' => 'Chocolate and confectionery', 'ar' => 'شوكولاتة وحلويات']],
            ['name' => ['en' => 'Danone', 'ar' => 'دانون'], 'details' => ['en' => 'Dairy and yogurt products', 'ar' => 'منتجات ألبان وزبادي']],
            ['name' => ['en' => 'Farm Fresh', 'ar' => 'فارم فريش'], 'details' => ['en' => 'Fresh produce and vegetables', 'ar' => 'منتجات طازجة وخضروات']],
            ['name' => ['en' => 'Barilla', 'ar' => 'باريلا'], 'details' => ['en' => 'Pasta and Italian sauces', 'ar' => 'معكرونة وصلصات إيطالية']],
            ['name' => ['en' => 'Heinz', 'ar' => 'هاينز'], 'details' => ['en' => 'Ketchup, sauces and condiments', 'ar' => 'كاتشب وصلصات وتوابل']],
            ['name' => ['en' => 'Lipton', 'ar' => 'ليبتون'], 'details' => ['en' => 'Tea and infusions', 'ar' => 'شاي ومشروبات']],
            ['name' => ['en' => 'Nescafé', 'ar' => 'نسكافيه'], 'details' => ['en' => 'Instant coffee and brewing', 'ar' => 'قهوة سريعة وتحضير']],
            ['name' => ['en' => 'Pantene', 'ar' => 'بانتين'], 'details' => ['en' => 'Hair care and shampoo', 'ar' => 'عناية بالشعر وشامبو']],
            ['name' => ['en' => 'Colgate', 'ar' => 'كولجيت'], 'details' => ['en' => 'Oral care and toothpaste', 'ar' => 'عناية بالفم ومعجون أسنان']],
            ['name' => ['en' => 'Pampers', 'ar' => 'بامبرز'], 'details' => ['en' => 'Baby diapers and wipes', 'ar' => 'حفاظات ومناديل أطفال']],
        ];

        foreach ($brands as $i => $brandData) {
            $name = $brandData['name'];
            $enName = $name['en'];

            $brand = Brand::where('name->en', $enName)->first();
            if (!$brand) {
                $brand = Brand::create([
                    'name' => $name,
                    'details' => $brandData['details'],
                    'status' => random_int(0, 1),
                ]);
            } else {
                $brand->update([
                    'name' => $name,
                    'details' => $brandData['details'],
                    'status' => random_int(0, 1),
                ]);
            }

            $brand->save();

            if ($brandImagesCount > 0 && !$brand->hasMedia('brands-desktop')) {
                $image = $brandImages[$i % $brandImagesCount];
                $brand
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('brands-desktop', 'brands');
            }
            if ($brandImagesCount > 0 && !$brand->hasMedia('brands-mobile')) {
                $image = $brandImages[$i % $brandImagesCount];
                $brand
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('brands-mobile', 'brands');
            }
        }
    }
}
