<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Slider;

class SliderSeeder extends Seeder
{
    public function run(): void
    {
        $sliderImages = collect(File::files(public_path('images/sliders')));
        $sliderImagesCount = $sliderImages->count();

        $sliders = [
            [
                'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
                'order' => 1,
                'status' => true,
            ],
            [
                'title' => ['en' => 'New Collection', 'ar' => 'المجموعة الجديدة'],
                'order' => 2,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Ramadan Offers', 'ar' => 'عروض رمضان'],
                'order'=> 3,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Winter Clearance', 'ar' => 'تصفية الشتاء'],
                'order'=> 4,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Black Friday Deals', 'ar' => 'عروض الجمعة السوداء'],
                'order'=> 5,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Back to School', 'ar' => 'العودة إلى المدرسة'],
                'order'=> 6,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Flash Sale', 'ar' => 'تخفيضات سريعة'],
                'order'=> 7,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Valentine’s Day', 'ar' => 'عيد الحب'],
                'order'=> 8,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Eid Al-Fitr Sale', 'ar' => 'تخفيضات عيد الفطر'],
                'order'=> 9,
                'status' => true,
            ],
            [
                'title' => ['en' => 'Cyber Monday', 'ar' => 'سايبر مانداي'],
                'order'=> 10,
                'status' => true,
            ],
        ];

        foreach ($sliders as $index => $slider) {
            $sliderModel = Slider::create($slider);

            if ($sliderImagesCount > 0) {
                $image = $sliderImages[$index % $sliderImagesCount];
                $sliderModel
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('sliders-desktop', 'sliders');
            }
            if ($sliderImagesCount > 0) {
                $image = $sliderImages[$index % $sliderImagesCount];
                $sliderModel
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('sliders-mobile', 'sliders');
            }
        }
    }
}