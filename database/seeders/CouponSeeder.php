<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str as SupportStr;
use Marvel\Database\Models\Coupon;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $bannerImages = File::exists(public_path('images/coupon'))
            ? collect(File::files(public_path('images/coupon')))
            : collect();
        $bannerImagesCount = $bannerImages->count();

        $coupons = [
            ['name' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'], 'code' => 'SUMMER20', 'discount_type' => 'percentage', 'discount' => 20, 'max_discount_amount' => 100],
            ['name' => ['en' => 'First Order', 'ar' => 'أول طلب'], 'code' => 'WELCOME10', 'discount_type' => 'percentage', 'discount' => 10, 'max_discount_amount' => 50],
            ['name' => ['en' => 'Free Shipping', 'ar' => 'شحن مجاني'], 'code' => 'FREESHIP', 'discount_type' => 'free_shipping', 'discount' => 0, 'max_discount_amount' => null],
            ['name' => ['en' => 'Flash Friday', 'ar' => 'جمعة الفلاش'], 'code' => 'FLASH25', 'discount_type' => 'percentage', 'discount' => 25, 'max_discount_amount' => 150],
            ['name' => ['en' => 'Weekend Deal', 'ar' => 'عرض نهاية الأسبوع'], 'code' => 'WEEKEND15', 'discount_type' => 'percentage', 'discount' => 15, 'max_discount_amount' => 75],
            ['name' => ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً'], 'code' => 'NEW10', 'discount_type' => 'percentage', 'discount' => 10, 'max_discount_amount' => 50],
            ['name' => ['en' => 'Loyalty Discount', 'ar' => 'خصم الولاء'], 'code' => 'LOYAL5', 'discount_type' => 'percentage', 'discount' => 5, 'max_discount_amount' => 25],
            ['name' => ['en' => 'Bulk Purchase', 'ar' => 'شراء بالجملة'], 'code' => 'BULK30', 'discount_type' => 'percentage', 'discount' => 30, 'max_discount_amount' => 200],
            ['name' => ['en' => 'Refer a Friend', 'ar' => 'ادع صديقاً'], 'code' => 'REFER20', 'discount_type' => 'percentage', 'discount' => 20, 'max_discount_amount' => 100],
            ['name' => ['en' => 'Holiday Special', 'ar' => 'عرض العيد'], 'code' => 'HOLIDAY', 'discount_type' => 'fixed_rate', 'discount' => 100, 'max_discount_amount' => null],
            ['name' => ['en' => 'Clearance Sale', 'ar' => 'تخفيضات تصفية'], 'code' => 'CLEAR50', 'discount_type' => 'percentage', 'discount' => 50, 'max_discount_amount' => 250],
            ['name' => ['en' => 'Student Discount', 'ar' => 'خصم الطلاب'], 'code' => 'STUDENT15', 'discount_type' => 'percentage', 'discount' => 15, 'max_discount_amount' => 60],
            ['name' => ['en' => 'Birthday Reward', 'ar' => 'مكافأة عيد الميلاد'], 'code' => 'BDAY25', 'discount_type' => 'percentage', 'discount' => 25, 'max_discount_amount' => 120],
            ['name' => ['en' => 'App Exclusive', 'ar' => 'حصرية التطبيق'], 'code' => 'APP10', 'discount_type' => 'percentage', 'discount' => 10, 'max_discount_amount' => 40],
            ['name' => ['en' => 'Midweek Madness', 'ar' => 'عرض منتصف الأسبوع'], 'code' => 'MIDWEEK20', 'discount_type' => 'percentage', 'discount' => 20, 'max_discount_amount' => 80],
            ['name' => ['en' => 'Bundle Save', 'ar' => 'وفر مع الحزمة'], 'code' => 'BUNDLE', 'discount_type' => 'fixed_rate', 'discount' => 75, 'max_discount_amount' => null],
            ['name' => ['en' => 'Seasonal Sale', 'ar' => 'تخفيضات موسمية'], 'code' => 'SEASON', 'discount_type' => 'fixed_rate', 'discount' => 50, 'max_discount_amount' => null],
            ['name' => ['en' => 'Member Special', 'ar' => 'عرض الأعضاء'], 'code' => 'MEMBER', 'discount_type' => 'percentage', 'discount' => 15, 'max_discount_amount' => 70],
            ['name' => ['en' => 'Cashback Deal', 'ar' => 'عرض الكاش باك'], 'code' => 'CASHBACK', 'discount_type' => 'fixed_rate', 'discount' => 25, 'max_discount_amount' => null],
            ['name' => ['en' => 'Happy Hour', 'ar' => 'السعيدة'], 'code' => 'HAPPY30', 'discount_type' => 'percentage', 'discount' => 30, 'max_discount_amount' => 150],
        ];

        foreach ($coupons as $i => $couponData) {
            $coupon = Coupon::create([
                'name' => $couponData['name'],
                'slug' => Str::slug($couponData['name']['en']),
                'code' => $couponData['code'],
                'discount_type' => $couponData['discount_type'],
                'discount' => $couponData['discount'],
                'max_discount_amount' => $couponData['max_discount_amount'],
                'start_date' => Carbon::now()->subDays(rand(0, 5))->format('Y-m-d'),
                'end_date' => Carbon::now()->addDays(rand(5, 60))->format('Y-m-d'),
                'limiter' => rand(50, 500),
                'used' => rand(0, 50),
                'status' => true,
                'border_color' => sprintf('#%06x', mt_rand(0, 0xFFFFFF)),
                'borderless' => (bool) rand(0, 1),
            ]);

            if ($bannerImagesCount > 0 && $coupon) {
                $image = $bannerImages[$i % $bannerImagesCount];
                $coupon
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(SupportStr::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('coupons-desktop', 'coupons');
                $coupon
                    ->addMedia($image->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(SupportStr::uuid() . '.' . $image->getExtension())
                    ->toMediaCollection('coupons-mobile', 'coupons');
            }
        }
    }
}
