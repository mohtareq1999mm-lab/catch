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
        // Infrastructure baseline — production-safe and idempotent.
        // Always runs, in every environment.
        $this->call([
            PermissionSeeder::class,
            SettingSeeder::class,
            CurrencySeeder::class,
        ]);

        // Demo storefront accounts with public known passwords. Runs in every
        // environment when `php artisan db:seed` is invoked (operator
        // explicitly requested full seeding including production).
        {
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
        }

        // Infrastructure content catalog — idempotent (firstOrCreate /
        // updateOrCreate, additive-only). Safe to re-run anywhere, so it
        // always runs, including production (shipping prices, order flows,
        // CMS baseline, pickup locations).
        $this->call([
//            CategorySeeder::class,
//            AttributeSeeder::class,
            TagSeeder::class,
            LocationSeeder::class,
            ContentPageSeeder::class,
            StaticPageSeeder::class,
            SectionSeeder::class,
            PickupLocationSeeder::class,
            OrderFlowSeeder::class,
            FlowInputSeeder::class,
            OrderStatusPermissionSeeder::class,
        ]);

        // Demo / test-scenario dataset — deterministic fixtures with known
        // passwords, bearer tokens and ORD-TEST-* / ORD-WMS-* orders plus
        // non-idempotent ::create demo content. Runs in every environment
        // when `php artisan db:seed` is invoked (operator explicitly
        // requested full seeding including production).
        $this->call([
            BannerSeeder::class,
            SliderSeeder::class,
            FaqSeeder::class,
            FlashSaleSeeder::class,
            BrandSeeder::class,
            ContactSeeder::class,
            ProductSeeder::class,
            SliderProductSeeder::class,
            BannerProductSeeder::class,
            ReviewSeeder::class,
            SiteReviewSeeder::class,
            ProductVariantSeeder::class,
            BrandProductSeeder::class,
            CartSeeder::class,
            CouponSeeder::class,
            QatarLocationSeeder::class,
            PromotionSeeder::class,
            WishlistSeeder::class,
            NotificationSeeder::class,
            ActivityLogSeeder::class,
            DashboardDataSeeder::class,
            WarehouseSeeder::class,
            AdminOrderOperationsSeeder::class,
            WmsOperationsSeeder::class,
        ]);
    }
}
