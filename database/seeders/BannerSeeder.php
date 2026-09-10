<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Banner;

class BannerSeeder extends Seeder
{
    public function run(): void
    {
        $bannerImages = collect(File::files(public_path('images/banners')));
        $bannerImagesCount = $bannerImages->count();

        $banners = [
            [
                'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
                'description' => ['en' => 'Up to 50% off on all items', 'ar' => 'خصومات تصل إلى 50% على جميع المنتجات'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'New Collection', 'ar' => 'المجموعة الجديدة'],
                'description' => ['en' => 'Discover our latest fashion trends', 'ar' => 'اكتشف أحدث صيحات الموضة لدينا'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Ramadan Offers', 'ar' => 'عروض رمضان'],
                'description' => ['en' => 'Special discounts during Ramadan', 'ar' => 'خصومات خاصة خلال شهر رمضان'],
                'status' => false,
            ],
            [
                'title' => ['en' => 'Winter Clearance', 'ar' => 'تصفية الشتاء'],
                'description' => ['en' => 'Clearance sale on winter clothes', 'ar' => 'تخفيضات على ملابس الشتاء'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Black Friday Deals', 'ar' => 'عروض الجمعة السوداء'],
                    'description' => ['en' => 'Massive discounts on electronics', 'ar' => 'خصومات ضخمة على الإلكترونيات'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Back to School', 'ar' => 'العودة إلى المدرسة'],
                'description' => ['en' => 'Special offers on school supplies', 'ar' => 'عروض خاصة على مستلزمات المدارس'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Flash Sale', 'ar' => 'تخفيضات سريعة'],
                'description' => ['en' => 'Limited time flash sale', 'ar' => 'تخفيضات لفترة محدودة'],
                'status' => false,
            ],
            [
                'title' => ['en' => 'Valentine’s Day', 'ar' => 'عيد الحب'],
                'description' => ['en' => 'Romantic gifts and offers', 'ar' => 'هدايا وعروض رومانسية'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Eid Al-Fitr Sale', 'ar' => 'تخفيضات عيد الفطر'],
                'description' => ['en' => 'Celebrate Eid with special discounts', 'ar' => 'احتفل بالعيد مع خصومات خاصة'],
                'status' => true,
            ],
            [
                'title' => ['en' => 'Cyber Monday', 'ar' => 'سايبر مانداي'],
                'description' => ['en' => 'Exclusive online deals', 'ar' => 'عروض حصرية عبر الإنترنت'],
                'status' => true,
            ],
        ];

        foreach ($banners as $index => $banner) {
            $bannerModel = Banner::create($banner);

            if ($bannerImagesCount > 0) {
                $image = $bannerImages[$index % $bannerImagesCount];
                $bannerModel
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('banners-desktop', 'banners');
            }
            if ($bannerImagesCount > 0) {
                $image = $bannerImages[$index % $bannerImagesCount];
                $bannerModel
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('banners-mobile', 'banners');
            }
        }
    }

   
}
