<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\StaticPage;
use Marvel\Enums\StaticPageIdentifier;

class StaticPageSeeder extends Seeder
{
    /**
     * Seed the fixed set of static pages.
     *
     * The seeder is the single source of truth for the fixed pages. It uses
     * firstOrCreate so re-running it never overwrites titles, is_active or any
     * admin-authored section content. Sections are intentionally not seeded.
     */
    public function run(): void
    {
        $pages = [
            StaticPageIdentifier::ABOUT_US => [
                'en' => 'About Us',
                'ar' => 'من نحن',
            ],
            StaticPageIdentifier::TERMS_AND_CONDITIONS => [
                'en' => 'Terms and Conditions',
                'ar' => 'الشروط والأحكام',
            ],
            StaticPageIdentifier::PRIVACY_POLICY => [
                'en' => 'Privacy Policy',
                'ar' => 'سياسة الخصوصية',
            ],
        ];

        foreach ($pages as $slug => $titles) {
            StaticPage::firstOrCreate([
                'slug' => $slug,
            ], [
                'title' => [
                    'en' => $titles['en'],
                    'ar' => $titles['ar'],
                ],
                'is_active' => true,
            ]);
        }
    }
}