<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use App\Models\Currency;
use App\Models\Fulfillment\Warehouse;
use Database\Seeders\Meem\MeemSeeder;
use Database\Seeders\Meem\Support\MeemContext;
use Database\Seeders\Meem\Support\MeemManifest;
use Database\Seeders\Meem\Support\MeemSeedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Settings;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Tests\TestCase;

/**
 * Single-seeder acceptance.
 *
 * The committed manifest has every governance gate unsigned, so the default
 * run is deliberately conservative. These tests cover:
 *  - the gate matrix (skip when unsigned, write when signed);
 *  - the dry-run guarantee;
 *  - idempotency (a second run changes nothing);
 *  - the recon correction that sections are retargeted through `setting.back`
 *    (the storefront endpoint source) and not `sections.endpoint`;
 *  - the "never create a coupon code" guardrail.
 */
final class MeemSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_settings_are_skipped_while_d5_is_unsigned(): void
    {
        $context = $this->runSeeder($this->data());

        $this->assertNull(Settings::query()->first());
        $this->assertStringContainsString('[SKIP] settings', $this->lines($context));
        $this->assertStringContainsString('D5_contact_and_delivery_approved', $this->lines($context));
    }

    public function test_settings_are_written_when_d5_is_signed(): void
    {
        $data = $this->data();
        $data['meta']['governance']['D5_contact_and_delivery_approved'] = true;
        $data = $this->readySettings($data);

        $context = $this->runSeeder($data);

        $settings = Settings::query()->firstOrFail();

        $this->assertSame('Meem Market', $settings->getTranslation('site_name', 'en'));
        $this->assertSame('ميم ماركت', $settings->getTranslation('site_name', 'ar'));
        $this->assertSame('care@meem.sa', $settings->site_email);
        $this->assertSame('+966500000000', $settings->phone);
        $this->assertFalse((bool) $settings->fast_shipping_page_publish);
        $this->assertStringContainsString('[DONE] settings', $this->lines($context));
    }

    public function test_settings_are_idempotent(): void
    {
        $data = $this->data();
        $data['meta']['governance']['D5_contact_and_delivery_approved'] = true;
        $data = $this->readySettings($data);

        $this->runSeeder($data);
        $afterFirst = Settings::query()->firstOrFail()->toArray();

        $second = $this->runSeeder($data);

        $this->assertSame($afterFirst, Settings::query()->firstOrFail()->toArray());
        $this->assertStringContainsString('[OK]   settings', $this->lines($second));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $data = $this->data();
        $data['meta']['governance']['D5_contact_and_delivery_approved'] = true;
        $data = $this->readySettings($data);

        $context = $this->runSeeder($data, dryRun: true);

        $this->assertNull(Settings::query()->first());
        $this->assertStringContainsString('[PLAN] settings', $this->lines($context));
        $this->assertDatabaseCount('warehouses', 0);
    }

    // -----------------------------------------------------------------
    // Currency
    // -----------------------------------------------------------------

    public function test_currency_sets_sar_as_base_and_catalog(): void
    {
        $settings = $this->settingsWithUsdOptions();
        $this->existingSar();

        $data = $this->data();
        $data['meta']['governance']['D1_currency_sar_approved'] = true;

        $this->runSeeder($data);

        $settings->refresh();

        $this->assertSame('SAR', $settings->options['currency']);
        $this->assertSame('SAR', $settings->options['base_currency_code']);
        $this->assertSame('SAR', $settings->options['catalog_currency_code']);
        $this->assertFalse((bool) $settings->options['currency_selection_enabled']);

        $sar = Currency::query()->where('code', 'SAR')->firstOrFail();

        $this->assertTrue((bool) $sar->is_active);
        $this->assertEqualsWithDelta(1.0, (float) $sar->manual_rate, 0.0000001);
        $this->assertDatabaseHas('currency_rates', [
            'currency_id' => $sar->getKey(),
            'effective_date' => today()->toDateString(),
        ]);
    }

    public function test_currency_is_idempotent(): void
    {
        $this->settingsWithUsdOptions();
        $this->existingSar();

        $data = $this->data();
        $data['meta']['governance']['D1_currency_sar_approved'] = true;

        $this->runSeeder($data);
        $options = Settings::query()->firstOrFail()->options;

        $second = $this->runSeeder($data);

        $this->assertSame($options, Settings::query()->firstOrFail()->options);
        $this->assertStringContainsString('[OK]   currency', $this->lines($second));
    }

    // -----------------------------------------------------------------
    // Warehouse (the only dataset that is READY without a gate)
    // -----------------------------------------------------------------

    public function test_warehouse_is_created_and_never_demotes_an_existing_default(): void
    {
        Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $context = $this->runSeeder($this->data());

        $meem = Warehouse::query()->where('code', 'WH-RIYADH-01')->firstOrFail();

        $this->assertFalse((bool) $meem->is_default, 'the existing default must not be demoted');
        $this->assertTrue((bool) Warehouse::query()->where('code', 'MAIN')->firstOrFail()->is_default);
        $this->assertStringContainsString('will be created non-default', $this->lines($context));
    }

    // -----------------------------------------------------------------
    // Sections — the recon correction
    // -----------------------------------------------------------------

    public function test_sections_retarget_through_setting_back_not_the_endpoint_column(): void
    {
        $page = $this->homePage();
        $banner = $this->section($page, 'banners', 2, [
            'front' => ['columns_count' => 5],
            'back' => ['slug' => 'black-friday-deals-s2'],
        ]);

        $this->runSeeder($this->data());

        $banner->refresh();

        $this->assertSame('black-friday-deals', $banner->setting['back']['slug']);
        $this->assertSame(5, $banner->setting['front']['columns_count'], 'front settings must be merged, not dropped');
        $this->assertSame('', (string) $banner->endpoint, 'the dead endpoint column must not be written');
        $this->assertSame(2, (int) $banner->order);
    }

    public function test_the_best_seller_section_is_retargeted_without_any_gate(): void
    {
        $page = $this->homePage();
        $bestSellers = $this->section($page, 'products', 5, [
            'front' => ['columns_count' => 5],
            'back' => ['productsId' => []],
        ]);

        $this->runSeeder($this->data());

        $bestSellers->refresh();

        $this->assertSame('best_product_sales', $bestSellers->setting['back']['type']);
        $this->assertSame(10, $bestSellers->setting['back']['limit']);
    }

    public function test_the_hero_section_is_skipped_while_d2_is_unsigned(): void
    {
        $page = $this->homePage();
        $hero = $this->section($page, 'hero', 1, [
            'front' => ['eyebrow' => ['ar' => 'كاتش بيوتي', 'en' => 'Catch Beauty']],
            'back' => [],
        ]);
        $before = $hero->setting;

        $context = $this->runSeeder($this->data());

        $this->assertSame($before, $hero->refresh()->setting);
        $this->assertStringContainsString('unsigned gate [D2_hero_copy_approved]', $this->lines($context));
    }

    public function test_a_section_with_an_unresolved_endpoint_template_is_skipped(): void
    {
        $page = $this->homePage();
        $categories = $this->section($page, 'categories', 3, [
            'front' => [],
            'back' => ['limit' => 8, 'categoriesId' => [155, 120, 139]],
        ]);
        $before = $categories->setting;

        $context = $this->runSeeder($this->data());

        $this->assertSame($before, $categories->refresh()->setting);
        $this->assertStringContainsString('not resolvable yet', $this->lines($context));
    }

    // -----------------------------------------------------------------
    // Coupons
    // -----------------------------------------------------------------

    public function test_coupons_never_create_an_unknown_code(): void
    {
        $data = $this->data();
        $data['coupons']['items'] = [[
            'code' => 'HAPPY30',
            'discount_type' => 'percentage',
            'discount' => 30,
            'max_discount_amount' => 50,
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'limiter' => null,
            'status' => 1,
        ]];

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('does not exist');

        $this->runSeeder($data);
    }

    // -----------------------------------------------------------------
    // Production guard
    // -----------------------------------------------------------------

    public function test_a_production_write_is_refused_without_a_batch_id(): void
    {
        $context = new MeemContext(
            profile: 'production',
            dryRun: false,
            force: true,
            batchId: null,
        );

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('SEEDING_BATCH_ID');

        (new MeemSeeder())->usingContext($context)->run();
    }

    // -----------------------------------------------------------------
    // Products — discount block (products.rules + homepage_skus[].discount)
    // -----------------------------------------------------------------

    public function test_products_map_the_discount_block_to_product_columns(): void
    {
        $this->seedCatalogueDependencies();

        $data = $this->data();
        $data['products']['image_fixes'] = [];
        $data['products']['homepage_skus'] = [$this->productDefinition()];

        $this->runSeeder($data);

        $product = \Marvel\Database\Models\Product::query()->where('sku', 'MEEM-TEST-001')->firstOrFail();

        $this->assertTrue((bool) $product->has_discount);
        $this->assertSame('percentage', $product->discount_type);
        $this->assertSame('20.000', number_format((float) $product->discount_amount, 3, '.', ''));
        $this->assertTrue((bool) $product->discount_status);
        $this->assertSame(40, (int) $product->stock_quantity);
        $this->assertTrue((bool) $product->in_stock);

        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $product->getKey(),
            'piece_barcode' => '6280000000001',
            'carton_barcode' => '6280000000002',
            'pieces_per_carton' => 12,
        ]);

        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->getKey(),
            'category_id' => Category::query()->where('slug', 'water')->value('id'),
        ]);
    }

    public function test_a_fixed_discount_is_mapped_to_the_fixed_rate_enum(): void
    {
        $this->seedCatalogueDependencies();

        $definition = $this->productDefinition();
        $definition['discount'] = ['type' => 'fixed', 'amount' => 2, 'start_date' => null, 'end_date' => null];

        $data = $this->data();
        $data['products']['image_fixes'] = [];
        $data['products']['homepage_skus'] = [$definition];

        $this->runSeeder($data);

        $product = \Marvel\Database\Models\Product::query()->where('sku', 'MEEM-TEST-001')->firstOrFail();

        $this->assertSame('fixed_rate', $product->discount_type);
    }

    public function test_a_fixed_discount_equal_to_the_price_is_rejected(): void
    {
        $this->seedCatalogueDependencies();

        $definition = $this->productDefinition();
        $definition['discount'] = ['type' => 'fixed', 'amount' => 12, 'start_date' => null, 'end_date' => null];

        $data = $this->data();
        $data['products']['image_fixes'] = [];
        $data['products']['homepage_skus'] = [$definition];

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('lower than the price');

        $this->runSeeder($data);
    }

    // -----------------------------------------------------------------
    // Currency flags + brand guard
    // -----------------------------------------------------------------

    public function test_currency_respects_set_base_false(): void
    {
        $settings = $this->settingsWithUsdOptions();
        $this->existingSar();

        $data = $this->data();
        $data['meta']['governance']['D1_currency_sar_approved'] = true;
        $data['currency']['set_base'] = false;

        $this->runSeeder($data);

        $settings->refresh();

        $this->assertSame('USD', $settings->options['base_currency_code'], 'set_base=false must leave the base untouched');
        $this->assertSame('SAR', $settings->options['catalog_currency_code']);
    }

    public function test_brands_refuse_a_brand_that_the_rules_say_to_remove(): void
    {
        $data = $this->data();
        $data['brands']['items'] = [[
            'slug' => 'apple',
            'name' => ['ar' => 'آبل', 'en' => 'Apple'],
        ]];

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('remove_unstocked');

        $this->runSeeder($data);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function runSeeder(array $data, bool $dryRun = false): MeemContext
    {
        $context = new MeemContext(
            profile: 'local',
            dryRun: $dryRun,
            force: false,
            batchId: null,
        );

        $seeder = (new MeemSeeder())
            ->usingManifest(MeemManifest::fromArray($data, 'test-fixture'))
            ->usingContext($context);

        $seeder->run();

        return $context;
    }

    private function lines(MeemContext $context): string
    {
        return implode("\n", $context->recordedLines());
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('database/seeders/Meem/Data/homepage.seed.json')),
            true,
        );
    }

    /**
     * Resolve every settings placeholder so the dataset can become READY.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function readySettings(array $data): array
    {
        $data['settings']['site_desc'] = ['ar' => 'وصف المتجر', 'en' => 'Store description'];
        $data['settings']['meta_desc'] = ['ar' => 'وصف البحث', 'en' => 'Search description'];
        $data['settings']['site_copy_right'] = ['ar' => 'جميع الحقوق محفوظة', 'en' => 'All rights reserved'];
        $data['settings']['site_email'] = 'care@meem.sa';
        $data['settings']['email_support'] = 'support@meem.sa';
        $data['settings']['phone'] = '+966500000000';
        $data['settings']['instagram'] = 'https://www.instagram.com/meemmarket';
        $data['settings']['tiktok'] = 'https://www.tiktok.com/@meemmarket';
        $data['settings']['snapchat'] = 'https://www.snapchat.com/add/meemmarket';
        $data['settings']['youtube'] = 'https://www.youtube.com/@meemmarket';
        $data['settings']['facebook'] = 'https://www.facebook.com/meemmarket';
        $data['settings']['minimum_order_amount'] = '50.00';

        return $data;
    }

    private function settingsWithUsdOptions(): Settings
    {
        return Settings::query()->create([
            'site_name' => ['en' => 'Existing Site', 'ar' => 'موقع قائم'],
            'options' => [
                'currency' => 'USD',
                'base_currency_code' => 'USD',
                'catalog_currency_code' => 'USD',
                'currency_selection_enabled' => true,
            ],
            'minimum_order_amount' => 0,
        ]);
    }

    private function existingSar(): Currency
    {
        return Currency::query()->create([
            'code' => 'SAR',
            'name' => ['en' => 'Saudi Riyal', 'ar' => 'ريال سعودي'],
            'symbol' => ['en' => 'ر.س', 'ar' => 'ر.س'],
            'country_name' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'],
            'numeric_code' => '682',
            'decimal_places' => 2,
            'icon' => 'sa',
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productDefinition(): array
    {
        return [
            'sku' => 'MEEM-TEST-001',
            'name' => ['ar' => 'عبوة مياه', 'en' => 'Water Pack'],
            'brand_slug' => 'meem',
            'category_slugs' => ['water'],
            'price' => '12.00',
            'discount' => ['type' => 'percentage', 'amount' => 20, 'start_date' => null, 'end_date' => null],
            'pieces_per_carton' => 12,
            'piece_barcode' => '6280000000001',
            'carton_barcode' => '6280000000002',
            'stock' => ['warehouse_code' => 'WH-RIYADH-01', 'quantity' => 40],
            'images' => ['meem/water-pack.jpg'],
        ];
    }

    private function seedCatalogueDependencies(): void
    {
        Category::query()->create([
            'name' => ['ar' => 'مياه', 'en' => 'Water'],
            'slug' => 'water',
            'level' => 1,
            'status' => true,
        ]);

        Brand::query()->create([
            'name' => ['ar' => 'ميم', 'en' => 'Meem'],
            'slug' => 'meem',
            'status' => true,
        ]);
    }

    private function homePage(): ContentPage    {
        return ContentPage::query()->create([
            'title' => ['ar' => 'الرئيسية', 'en' => 'Home'],
            'slug' => 'home',
            'is_active' => true,
        ]);
    }

    /**
     * Sections are created through the model (which owns `order` via
     * SortableTrait), then the order is forced with the query builder so the
     * seeder's (type, order) contract is satisfied.
     *
     * @param array<string, mixed> $setting
     */
    private function section(ContentPage $page, string $type, int $order, array $setting): Section
    {
        $section = Section::query()->create([
            'type' => $type,
            'title' => ['ar' => 'قسم', 'en' => 'Section'],
            'order' => $order,
            'endpoint' => '',
            'content_page_id' => $page->getKey(),
            'is_active' => true,
            'title_visible' => true,
            'setting' => $setting,
        ]);

        DB::table('sections')->where('id', $section->getKey())->update([
            'order' => $order,
            'content_page_id' => $page->getKey(),
        ]);

        return $section->refresh();
    }
}
