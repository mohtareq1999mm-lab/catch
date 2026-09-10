<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Marvel\Database\Models\Settings;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $setting = Settings::first();
        if (!$setting) {
            $setting = Settings::create([
                "site_name" => [
                    'ar' => 'اسم الموقع',
                    'en' => 'Site Name',
                ],
                "site_desc" => [
                    'ar' => 'وصف الموقع',
                    'en' => 'Site Description',
                ],
                "meta_desc" => [
                    'ar' => 'وصف الموقع للبحث',
                    'en' => 'Site Description for Search',
                ],
                "site_copy_right" => [
                    'ar' => 'حقوق الموقع',
                    'en' => 'Site Copyright',
                ],
                "logo" => 'logo.png',
                "favicon" => 'favicon.png',
                "site_email" => 'info@example.com',
                "email_support" => 'support@example.com',
                "facebook" => 'https://www.facebook.com/example',
                "instagram" => 'https://www.instagram.com/example',
                "linkedin" => 'https://www.linkedin.com/company/example',
                "promotion_video_url" => 'https://www.youtube.com/watch?v=example',
                'youtube' => 'https://www.youtube.com/channel/example',
                'phone' => '+2011111111111'
            ]);
        }

        $options = $setting->options ?? [];
        $options['currency'] ??= 'USD';
        $options['base_currency_code'] ??= 'USD';
        $options['catalog_currency_code'] ??= 'USD';
        $options['currency_selection_enabled'] ??= false;
        $setting->options = $options;
        $setting->save();

        $settingImages = File::exists(public_path('images/settings'))
            ? collect(File::files(public_path('images/settings')))
            : collect(File::exists(public_path('images/shops'))
                ? collect(File::files(public_path('images/shops')))
                : collect());

        $settingImagesCount = $settingImages->count();

        if ($settingImagesCount > 0) {
            $logoImage = $settingImages[0];
            $faviconImage = $settingImages[$settingImagesCount > 1 ? 1 : 0];

            if (! $setting->hasMedia('logo')) {
                $setting
                    ->addMedia($logoImage->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $logoImage->getExtension())
                    ->toMediaCollection('logo', 'settings');
            }

            if (! $setting->hasMedia('favicon')) {
                $setting
                    ->addMedia($faviconImage->getPathname())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid() . '.' . $faviconImage->getExtension())
                    ->toMediaCollection('favicon', 'settings');
            }
        }
    }
}
