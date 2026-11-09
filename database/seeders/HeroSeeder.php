<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Hero;

class HeroSeeder extends Seeder
{
    public function run(): void
    {
        $sliderImages = File::isDirectory(public_path('images/sliders'))
            ? collect(File::files(public_path('images/sliders')))
            : collect();
        $sliderImagesCount = $sliderImages->count();

        $heroes = [
            [
                'id' => 1,
                'type' => Hero::TYPE_IMAGE,
                'description' => [
                    'en' => 'Discover our new seasonal collection with exclusive offers',
                    'ar' => 'اكتشف مجموعتنا الموسمية الجديدة مع عروض حصرية',
                ],
                'is_active' => true,
            ],
            [
                'id' => 2,
                'type' => Hero::TYPE_IMAGE,
                'description' => [
                    'en' => 'Shop top-rated brands with fast delivery',
                    'ar' => 'تسوق أفضل العلامات التجارية مع توصيل سريع',
                ],
                'is_active' => false,
            ],
        ];

        $activeHeroId = null;

        foreach ($heroes as $index => $data) {
            $hero = Hero::updateOrCreate(
                ['id' => $data['id']],
                [
                    'type' => $data['type'],
                    'description' => $data['description'],
                    'is_active' => $data['is_active'],
                ]
            );

            if ($data['is_active']) {
                $activeHeroId = $hero->id;
            }

            if ($sliderImagesCount > 0 && $hero->getMedia(Hero::COLLECTION_IMAGE)->isEmpty()) {
                $image = $sliderImages[$index % $sliderImagesCount];
                try {
                    $hero
                        ->addMedia($image->getPathname())
                        ->preservingOriginal()
                        ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                        ->toMediaCollection(Hero::COLLECTION_IMAGE, 'heroes');
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        if ($activeHeroId !== null) {
            Hero::where('id', '!=', $activeHeroId)->update(['is_active' => false]);
        }
    }
}

