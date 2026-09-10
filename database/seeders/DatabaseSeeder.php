<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Keep permission roles and default admin user in sync across environments.
        $this->call([
            PermissionSeeder::class,
            SettingSeeder::class,
            CurrencySeeder::class,
        ]);

        $user = User::firstOrCreate([
            'email' => 'admin@demo.com',
        ], [
            'name' => 'Shop Owner',
            'password' => Hash::make('password'),
            'is_active' => true,
            'phone_number' => '34567890',
            'email_verified_at' => now(),
            'type' => 'admin',
        ]);
        $userEdit = User::firstOrCreate([
            'email' => 'editor@cms.com',
        ], [
            'name' => 'Shop Owner',
            'password' => Hash::make('password'),
            'is_active' => true,
            'phone_number' => '123456790',
            'email_verified_at' => now(),
        ]);

        $customer = User::firstOrCreate([
            'email' => 'test@g.com',
        ], [
            'name' => 'Test Customer',
            'password' => Hash::make('password'),
            'is_active' => true,
            'phone_number' => '12347890',
            'email_verified_at' => now(),
        ]);

        $user->assignRole("super_admin");
//        $userEdit->assignRole("editor");
//        $customer->assignRole("customer");

        // if ($seedDemoData) {
        //     User::factory(10000)->create();
        // }

        $this->call([
//            CategorySeeder::class,
//            AttributeSeeder::class,
            BannerSeeder::class,
            SliderSeeder::class,
//            FaqSeeder::class,
//            FlashSaleSeeder::class,
//            BrandSeeder::class,
//            ContactSeeder::class,
            TagSeeder::class,
//            ProductSeeder::class,
            SliderProductSeeder::class,
            BannerProductSeeder::class,
//            ReviewSeeder::class,
//            SiteReviewSeeder::class,
//            ProductVariantSeeder::class,
//            BrandProductSeeder::class,
            CartSeeder::class,
//            CouponSeeder::class,
//            LocationSeeder::class,
//            PromotionSeeder::class,
//            WishlistSeeder::class,
            ContentPageSeeder::class,
            StaticPageSeeder::class,
            SectionSeeder::class,
//            DashboardDataSeeder::class,
//            NotificationSeeder::class,
//            ActivityLogSeeder::class,
            PickupLocationSeeder::class,

        ]);
    }
}
