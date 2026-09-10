<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;

class CategorySeeder extends Seeder
{
    private int $sequenceCounter = 1;

    public function run(): void
    {
        $categoryImages = collect(File::files(public_path('images/categories')));
        $categoryImagesCount = $categoryImages->count();

        // Structured categories matching the provided image (English + Arabic)
        $structured = [
            [
                'name' => ['en' => 'Face', 'ar' => 'مكياج الوجه'],
                'children' => [
                    [
                        'name' => ['en' => 'Foundation', 'ar' => 'فاونديشن'],
                        'children' => [
                            ['name' => ['en' => 'Liquid Foundation', 'ar' => 'فاونديشن سائل']],
                            ['name' => ['en' => 'Powder Foundation', 'ar' => 'فاونديشن بودرة']],
                            ['name' => ['en' => 'Stick Foundation', 'ar' => 'فاونديشن ستيك']],
                            ['name' => ['en' => 'BB & CC Cream', 'ar' => 'بي بي وسي سي كريم']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Concealer', 'ar' => 'كونسيلر'],
                        'children' => [
                            ['name' => ['en' => 'Liquid Concealer', 'ar' => 'كونسيلر سائل']],
                            ['name' => ['en' => 'Cream Concealer', 'ar' => 'كونسيلر كريم']],
                            ['name' => ['en' => 'Color Corrector', 'ar' => 'مصححات الألوان']],
                        ],
                    ],
                    
                    [
                        'name' => ['en' => 'Powder', 'ar' => 'بودرة'],
                        'children' => [
                            ['name' => ['en' => 'Loose Powder', 'ar' => 'بودرة سائبة']],
                            ['name' => ['en' => 'Pressed Powder', 'ar' => 'بودرة مضغوطة']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Blush & Bronzer', 'ar' => 'بلاشر وبرونزر'],
                        'children' => [
                            ['name' => ['en' => 'Blush', 'ar' => 'بلاشر']],
                            ['name' => ['en' => 'Bronzer', 'ar' => 'برونزر']],
                            ['name' => ['en' => 'Contour', 'ar' => 'كونتور']],
                            ['name' => ['en' => 'Highlighter', 'ar' => 'هايلايتر']],
                        ],
                    ],
                ],
            ],

            [
                'name' => ['en' => 'Eyes', 'ar' => 'مكياج العيون'],
                'children' => [
                    [
                        'name' => ['en' => 'Eyeshadow', 'ar' => 'آيشادو'],
                        'children' => [
                            ['name' => ['en' => 'Palettes', 'ar' => 'باليت']],
                            ['name' => ['en' => 'Single Eyeshadow', 'ar' => 'آيشادو مفرد']],
                            ['name' => ['en' => 'Cream Eyeshadow', 'ar' => 'آيشادو كريم']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Mascara', 'ar' => 'ماسكارا'],
                        'children' => [
                            ['name' => ['en' => 'Volume Mascara', 'ar' => 'ماسكارا كثافة']],
                            ['name' => ['en' => 'Lengthening Mascara', 'ar' => 'ماسكارا تطويل']],
                            ['name' => ['en' => 'Waterproof Mascara', 'ar' => 'ماسكارا مقاومة للماء']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Eyeliner', 'ar' => 'آيلاينر'],
                        'children' => [
                            ['name' => ['en' => 'Liquid Eyeliner', 'ar' => 'آيلاينر سائل']],
                            ['name' => ['en' => 'Gel Eyeliner', 'ar' => 'آيلاينر جل']],
                            ['name' => ['en' => 'Pencil Eyeliner', 'ar' => 'آيلاينر قلم']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Eyebrows', 'ar' => 'الحواجب'],
                        'children' => [
                            ['name' => ['en' => 'Brow Pencil', 'ar' => 'قلم حواجب']],
                            ['name' => ['en' => 'Brow Gel', 'ar' => 'جل حواجب']],
                            ['name' => ['en' => 'Brow Powder', 'ar' => 'بودرة حواجب']],
                        ],
                    ],
                ],
            ],

            [
                'name' => ['en' => 'Lips', 'ar' => 'مكياج الشفاه'],
                'children' => [
                    [
                        'name' => ['en' => 'Lipstick', 'ar' => 'روج'],
                        'children' => [
                            ['name' => ['en' => 'Matte Lipstick', 'ar' => 'روج مطفي']],
                            ['name' => ['en' => 'Cream Lipstick', 'ar' => 'روج كريمي']],
                            ['name' => ['en' => 'Satin Lipstick', 'ar' => 'روج ساتان']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Lip Gloss', 'ar' => 'ملمع شفاه'],
                        'children' => [
                            ['name' => ['en' => 'Clear Gloss', 'ar' => 'جلوس شفاف']],
                            ['name' => ['en' => 'Tinted Gloss', 'ar' => 'جلوس ملون']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Lip Liner', 'ar' => 'محدد شفاه'],
                        'children' => [
                            ['name' => ['en' => 'Wood Pencil', 'ar' => 'قلم خشبي']],
                            ['name' => ['en' => 'Retractable Pencil', 'ar' => 'قلم أوتوماتيك']],
                        ],
                    ],
                ],
            ],

            [
                'name' => ['en' => 'Skincare', 'ar' => 'العناية بالبشرة'],
                'children' => [
                    [
                        'name' => ['en' => 'Cleanser', 'ar' => 'غسول'],
                        'children' => [
                            ['name' => ['en' => 'Foam Cleanser', 'ar' => 'غسول رغوي']],
                            ['name' => ['en' => 'Gel Cleanser', 'ar' => 'غسول جل']],
                            ['name' => ['en' => 'Oil Cleanser', 'ar' => 'غسول زيتي']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Moisturizer', 'ar' => 'مرطب'],
                        'children' => [
                            ['name' => ['en' => 'Day Cream', 'ar' => 'كريم نهاري']],
                            ['name' => ['en' => 'Night Cream', 'ar' => 'كريم ليلي']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Serums', 'ar' => 'سيروم'],
                        'children' => [
                            ['name' => ['en' => 'Vitamin C', 'ar' => 'فيتامين سي']],
                            ['name' => ['en' => 'Hyaluronic Acid', 'ar' => 'هيالورونيك أسيد']],
                            ['name' => ['en' => 'Niacinamide', 'ar' => 'نياسيناميد']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Sunscreen', 'ar' => 'واقي شمس'],
                        'children' => [
                            ['name' => ['en' => 'SPF 30', 'ar' => 'SPF 30']],
                            ['name' => ['en' => 'SPF 50+', 'ar' => 'SPF 50+']],
                        ],
                    ],
                ],
            ],

            [
                'name' => ['en' => 'Brushes & Tools', 'ar' => 'فرش وأدوات'],
                'children' => [
                    [
                        'name' => ['en' => 'Brushes', 'ar' => 'فرش'],
                        'children' => [
                            ['name' => ['en' => 'Foundation Brush', 'ar' => 'فرشاة فاونديشن']],
                            ['name' => ['en' => 'Powder Brush', 'ar' => 'فرشاة بودرة']],
                            ['name' => ['en' => 'Blush Brush', 'ar' => 'فرشاة بلاشر']],
                            ['name' => ['en' => 'Eyeshadow Brush', 'ar' => 'فرشاة آيشادو']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Beauty Tools', 'ar' => 'أدوات تجميل'],
                        'children' => [
                            ['name' => ['en' => 'Beauty Blender', 'ar' => 'بيوتي بلندر']],
                            ['name' => ['en' => 'Eyelash Curler', 'ar' => 'مكبس رموش']],
                            ['name' => ['en' => 'Tweezers', 'ar' => 'ملقاط']],
                            ['name' => ['en' => 'Makeup Bag', 'ar' => 'حقيبة مكياج']],
                        ],
                    ],
                ],
            ],

            [
                'name' => ['en' => 'Fragrance', 'ar' => 'العطور'],
                'children' => [
                    [
                        'name' => ['en' => 'Women Perfume', 'ar' => 'عطور نسائية'],
                        'children' => [
                            ['name' => ['en' => 'Eau De Parfum', 'ar' => 'أو دو بارفيوم']],
                            ['name' => ['en' => 'Eau De Toilette', 'ar' => 'أو دو تواليت']],
                        ],
                    ],
                    [
                        'name' => ['en' => 'Men Perfume', 'ar' => 'عطور رجالية'],
                        'children' => [
                            ['name' => ['en' => 'Luxury Perfumes', 'ar' => 'عطور فاخرة']],
                            ['name' => ['en' => 'Daily Perfumes', 'ar' => 'عطور يومية']],
                        ],
                    ],
                ],
            ],
        ];
        // Walk the structure and seed categories recursively
        foreach ($structured as $node) {
            $root = $this->seedCategoryWithChildren($node, null, $categoryImages, $categoryImagesCount);
        }
    }

    private function seedCategoryWithChildren(array $node, ?Category $parent, $categoryImages, int $categoryImagesCount): Category
    {
        $seq = $this->sequenceCounter++;
        $nameEn = $node['name']['en'] ?? null;
        $nameAr = $node['name']['ar'] ?? null;

        $category = $this->seedCategory($seq, $parent, $categoryImages, $categoryImagesCount, $nameEn, $nameAr);

        if (! empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $this->seedCategoryWithChildren($child, $category, $categoryImages, $categoryImagesCount);
            }
        }

        return $category;
    }

    private function seedCategory(int $sequence, ?Category $parentCategory, $categoryImages, int $categoryImagesCount, string $forceEnName = null, string $forceArName = null): Category
    {
        $nameEn = $forceEnName ?? "Category {$sequence}";
        $nameAr = $forceArName ?? "كاتوجوري {$sequence}";

        // Ensure name uniqueness to avoid DB unique constraint on `name`
        $baseEn = $nameEn;
        $baseAr = $nameAr;
        $suffix = 2;

        while (Category::where('name->en', $nameEn)->exists() || Category::where('name->ar', $nameAr)->exists()) {
            $nameEn = $baseEn . ' ' . $suffix;
            $nameAr = $baseAr . ' ' . $suffix;
            $suffix++;
        }

        // generate unique translatable slug
        $slug = Str::slug($baseEn) . '-' . $sequence;
        $slugEn = $slug;
        $i = 1;
        while (Category::where('slug', $slugEn)->exists()) {
            $i++;
            $slugEn = $slug . '-' . $i;
        }

        $level = $parentCategory ? $parentCategory->level + 1 : 1;

        $existing = Category::where('slug', $slugEn)->first();
        if (! $existing) {
            $category = Category::create([
                'slug' => $slugEn,
                'name' => [
                    'ar' => $nameAr,
                    'en' => $nameEn,
                ],
                'details' => [
                    'ar' => "تفاصيل {$nameAr}",
                    'en' => "Details of {$nameEn}",
                ],
                'parent_id' => $parentCategory?->id,
                'level' => $level,
            ]);
        } else {
            $existing->update([
                'slug' => $slugEn,
                'name' => [
                    'ar' => $nameAr,
                    'en' => $nameEn,
                ],
                'details' => [
                    'ar' => "تفاصيل {$nameAr}",
                    'en' => "Details of {$nameEn}",
                ],
                'parent_id' => $parentCategory?->id,
                'level' => $level,
            ]);
            $category = $existing;
        }
        $category->save();

        if ($categoryImagesCount > 0 && ! $category->hasMedia('categories-desktop')) {
            $image = $categoryImages[($sequence - 1) % $categoryImagesCount];

            $category
                ->addMedia($image->getPathname())
                ->preservingOriginal()
                ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                ->toMediaCollection('categories-desktop', 'categories');
        }
        if ($categoryImagesCount > 0 && ! $category->hasMedia('categories-mobile')) {
            $image = $categoryImages[($sequence - 1) % $categoryImagesCount];

            $category
                ->addMedia($image->getPathname())
                ->preservingOriginal()
                ->usingFileName(Str::uuid() . '.' . $image->getExtension())
                ->toMediaCollection('categories-mobile', 'categories');
        }

        // $category->shops()->syncWithoutDetaching([1, 2]);

        return $category;
    }
}
