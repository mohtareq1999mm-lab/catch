<?php

declare(strict_types=1);

namespace Database\Seeders\Meem;

use App\Enums\FrontendResource;
use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\ProductBarcode;
use Closure;
use Database\Seeders\Meem\Support\MeemContext;
use Database\Seeders\Meem\Support\MeemManifest;
use Database\Seeders\Meem\Support\MeemSeedException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Tag;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;

/**
 * The single Meem Market seeder.
 *
 * One class, one manifest, one run. Every dataset in
 * `database/seeders/Meem/Data/homepage.seed.json` is handled here in the
 * documented order; nothing is duplicated outside the manifest.
 *
 * ── Safety model ────────────────────────────────────────────────────────
 *  • Dry run by default. A write needs MEEM_SEED_APPLY=1.
 *  • Per dataset: profile allow-list → governance gate → unresolved values.
 *    A blocked dataset is skipped and reported; it is never partially written.
 *  • Every write happens inside ONE transaction per dataset.
 *  • Production writes require SEEDING_BATCH_ID **and** --force.
 *  • Sections are checked per record (each section is an independent unit),
 *    so an unresolved hero asset cannot block an unrelated banner retarget.
 *  • Nothing is ever deleted. Coupons are matched by code and updated only.
 *
 * ── Verified recon corrections baked in ─────────────────────────────────
 *  • `currencies` has no is_base/is_catalog; base+catalog live in settings.options.
 *  • Barcodes live in `product_barcodes` (one row per product), not `products`.
 *  • The storefront endpoint is derived from `type` + `setting.back`;
 *    `sections.endpoint` is never read, so we write `setting.back`.
 *  • `SortableTrait` overwrites `order` on create → order is forced via the
 *    query builder.
 *  • There are six online gateways (paypal included); `available` is never seeded.
 *  • A warehouse default is unique per non-deleted row → an existing default is
 *    never demoted implicitly.
 *
 * ── Out of scope by design ──────────────────────────────────────────────
 *  • Binary assets (logos, category/product/coupon artwork, hero video) are not
 *    uploaded here; they are listed as REQUIRED_* and reported.
 *  • Nothing is created that the manifest does not describe (no products,
 *    brands, banners, coupons or sections are invented).
 */
final class MeemSeeder extends Seeder
{
    /** Documented execution order (seeding plan §4.3). */
    private const ORDER = [
        'settings',
        'currency',
        'geography',
        'warehouse',
        'categories',
        'brands',
        'products',
        'bulk',
        'banners',
        'flash_sales',
        'coupons',
        'payment',
        'sections',
    ];

    /** The writable settings surface (settings fillable ∩ manifest). */
    private const SETTINGS_FIELDS = [
        'site_name',
        'site_desc',
        'meta_desc',
        'site_copy_right',
        'site_email',
        'email_support',
        'facebook',
        'instagram',
        'linkedin',
        'promotion_video_url',
        'youtube',
        'tiktok',
        'snapchat',
        'phone',
        'minimum_order_amount',
        'fast_shipping_page_publish',
    ];

    private const CURRENCY_OPTION_KEYS = [
        'currency',
        'base_currency_code',
        'catalog_currency_code',
    ];

    private ?MeemManifest $manifest = null;

    private ?MeemContext $context = null;

    /** Test seam: inject a manifest without going through the filesystem. */
    public function usingManifest(MeemManifest $manifest): self
    {
        $this->manifest = $manifest;

        return $this;
    }

    /** Test seam: inject a run context. */
    public function usingContext(MeemContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function run(): void
    {
        [$apply, $force] = $this->resolveFlags();

        $context = $this->context ??= MeemContext::fromEnvironment($apply, $force, $this->writer());
        $manifest = $this->manifest ??= MeemManifest::fromFile(MeemContext::envValue('MEEM_SEED_FILE'));

        $context->line('');
        $context->line('Meem seeding');
        $context->line('  profile : ' . $context->profile());
        $context->line('  mode    : ' . ($context->isDryRun()
            ? 'DRY RUN — nothing is written (set MEEM_SEED_APPLY=1 to write)'
            : 'APPLY — one transaction per dataset'));
        $context->line('  batch   : ' . ($context->batchId() ?? '(none)'));
        $context->line('  manifest: ' . $manifest->source());

        try {
            $context->assertRunnable();

            foreach (self::ORDER as $dataset) {
                $context->line('');
                $this->runDataset($dataset, $manifest, $context);
            }
        } finally {
            $context->line('');
            $context->line('Meem seeding finished.');
        }
    }

    // =================================================================
    // Dispatch
    // =================================================================

    private function runDataset(string $dataset, MeemManifest $manifest, MeemContext $context): void
    {
        // Sections are gated per record: one unresolved hero asset must not
        // block the banner or best-seller retargets.
        if ($dataset === 'sections') {
            $this->runSections($manifest, $context);

            return;
        }

        $state = $manifest->state($dataset, $context->profile());

        if ($state['state'] !== MeemManifest::READY) {
            $context->skip($dataset, $state['state'], $state['gate'], $state['missing']);

            return;
        }

        $entries = $this->plan($dataset, $manifest, $context);

        if ($entries === []) {
            $context->noop($dataset);

            return;
        }

        if ($context->isDryRun()) {
            $context->planned($dataset, $this->summarise($entries));

            return;
        }

        $context->guard($dataset, function () use ($entries, $context): void {
            $context->transaction(function () use ($entries): void {
                foreach ($entries as $entry) {
                    ($entry['write'])();
                }
            });
        });

        $this->flush($dataset);
        $context->applied($dataset, $this->summarise($entries));
    }

    /**
     * @return list<array{key: string, changes: array<string, array{from: mixed, to: mixed}>, write: Closure():void}>
     */
    private function plan(string $dataset, MeemManifest $manifest, MeemContext $context): array
    {
        return match ($dataset) {
            'settings' => $this->planSettings($manifest),
            'currency' => $this->planCurrency($manifest, $context),
            'geography' => $this->planGeography($manifest, $context),
            'warehouse' => $this->planWarehouse($manifest, $context),
            'categories' => $this->planCategories($manifest, $context),
            'brands' => $this->planBrands($manifest),
            'products' => $this->planProducts($manifest, $context),
            'bulk' => $this->planBulk($manifest, $context),
            'banners' => $this->planBanners($manifest, $context),
            'flash_sales' => $this->planFlashSales($manifest, $context),
            'coupons' => $this->planCoupons($manifest),
            'payment' => $this->planPayment($manifest, $context),
            default => throw new MeemSeedException("No planner for dataset [{$dataset}]."),
        };
    }

    /**
     * @param list<array{key: string, changes: array<string, array{from: mixed, to: mixed}>, write: Closure():void}> $entries
     * @return list<array{key: string, changes: array<string, array{from: mixed, to: mixed}>}>
     */
    private function summarise(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => ['key' => $entry['key'], 'changes' => $entry['changes']],
            $entries,
        );
    }

    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    private function entry(string $key, array $changes, Closure $write): array
    {
        return ['key' => $key, 'changes' => $changes, 'write' => $write];
    }

    // =================================================================
    // 1. Settings
    // =================================================================

    private function planSettings(MeemManifest $manifest): array
    {
        $data = $manifest->dataset('settings');
        $current = Settings::query()->first();

        $changes = [];
        $payload = [];

        foreach (self::SETTINGS_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $to = $this->castSetting($field, $data[$field]);
            $from = $current?->getAttribute($field);

            if ($current === null || MeemContext::differs($from, $to)) {
                $changes[$field] = MeemContext::change($from, $data[$field]);
                $payload[$field] = $to;
            }
        }

        if ($changes === []) {
            return [];
        }

        return [$this->entry('settings', $changes, function () use ($manifest, $payload): void {
            $manifest->assertWritable('settings', $payload);

            $settings = Settings::query()->first();

            if ($settings === null) {
                if (! isset($payload['site_name'])) {
                    throw new MeemSeedException('Cannot create the settings row without site_name.');
                }

                Settings::query()->create($payload);

                return;
            }

            $settings->fill($payload)->save();
        })];
    }

    // =================================================================
    // 2. Currency
    // =================================================================

    private function planCurrency(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('currency');

        $code = $this->currencyCode($data);
        $rate = $this->currencyRate($data);

        $settings = Settings::query()->first();

        if ($settings === null) {
            throw new MeemSeedException(
                'Currency depends on the settings row: run the settings dataset first.'
            );
        }

        $currency = Currency::withTrashed()->where('code', $code)->first();
        $options = is_array($settings->options) ? $settings->options : [];

        $changes = [];

        if ($currency === null) {
            $changes['currency'] = MeemContext::change(null, $code);
        } else {
            if ($currency->trashed()) {
                $changes['deleted_at'] = MeemContext::change('soft-deleted', 'restored');
            }

            if (! (bool) $currency->is_active) {
                $changes['is_active'] = MeemContext::change($currency->is_active, true);
            }

            $mode = $currency->rate_mode instanceof RateMode
                ? $currency->rate_mode->value
                : (string) $currency->rate_mode;

            if ($mode !== RateMode::MANUAL->value) {
                $changes['rate_mode'] = MeemContext::change($mode, RateMode::MANUAL->value);
            }

            if (MeemContext::differs($currency->manual_rate, $rate)) {
                $changes['manual_rate'] = MeemContext::change($currency->manual_rate, $rate);
            }
        }

        foreach ($this->currencyOptionsToWrite($data) as $key => $to) {
            if (MeemContext::differs($options[$key] ?? null, $to)) {
                $changes["options.{$key}"] = MeemContext::change($options[$key] ?? null, $to);
            }
        }

        $selection = (bool) ($data['currency_selection_enabled'] ?? false);

        if (! array_key_exists('currency_selection_enabled', $options)
            || (bool) $options['currency_selection_enabled'] !== $selection) {
            $changes['options.currency_selection_enabled'] = MeemContext::change(
                $options['currency_selection_enabled'] ?? null,
                $selection,
            );
        }

        if ($changes === []) {
            return [];
        }

        return [$this->entry("currency:{$code}", $changes, function () use ($manifest, $data, $code, $rate): void {
            $manifest->assertWritable('currency', [$data]);

            $currency = Currency::withTrashed()->firstOrNew(['code' => $code]);

            if ($currency->exists && $currency->trashed()) {
                $currency->restore();
            }

            if (! $currency->exists && ! isset($data['name'])) {
                throw new MeemSeedException(
                    "Currency [{$code}] does not exist and the manifest supplies no name/symbol. "
                    . 'Run the base CurrencySeeder first, or add name/symbol to the currency block.'
                );
            }

            $attributes = [
                'is_active' => true,
                'rate_mode' => RateMode::MANUAL,
                'manual_rate' => $rate,
            ];

            foreach (['name', 'symbol', 'country_name', 'numeric_code', 'decimal_places', 'icon'] as $optional) {
                if (array_key_exists($optional, $data)) {
                    $attributes[$optional] = $data[$optional];
                }
            }

            $currency->fill($attributes)->save();

            CurrencyRate::query()->updateOrCreate(
                [
                    'currency_id' => $currency->getKey(),
                    'effective_date' => today()->toDateString(),
                ],
                [
                    'exchange_rate' => (string) $rate,
                    'source' => RateSource::MANUAL,
                ],
            );

            $settings = Settings::query()->first();

            if ($settings === null) {
                throw new MeemSeedException('Settings row disappeared during the currency write.');
            }

            $options = is_array($settings->options) ? $settings->options : [];

            foreach ($this->currencyOptionsToWrite($data) as $key => $value) {
                $options[$key] = $value;
            }

            $options['currency_selection_enabled'] = (bool) ($data['currency_selection_enabled'] ?? false);

            $settings->options = $options;
            $settings->save();
        })];
    }

    // =================================================================
    // 3. Geography
    // =================================================================

    private function planGeography(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('geography');

        $countryName = $this->bilingual($data['country']['name'] ?? null, 'geography.country.name');
        $country = Country::query()->where('name->en', $countryName['en'])->first();

        $entries = [];
        $changes = [];

        if ($country === null) {
            $changes['country'] = MeemContext::change(null, $countryName['en']);
        }

        foreach (($data['governorates'] ?? []) as $definition) {
            $name = $this->bilingual($definition['name'] ?? null, 'geography.governorates[].name');
            $price = $definition['shipping_price'] ?? null;

            $governorate = $country === null
                ? null
                : Governorate::query()->where('country_id', $country->getKey())->where('name->en', $name['en'])->first();

            if ($governorate === null) {
                $changes["governorate:{$name['en']}"] = MeemContext::change(null, $name['en']);
            } elseif ($price !== null) {
                $shipping = ShippingPrice::query()->where('governorate_id', $governorate->getKey())->first();

                if ($shipping === null || MeemContext::differs($shipping->price, $price)) {
                    $changes["shipping_price:{$name['en']}"] = MeemContext::change($shipping?->price, $price);
                }
            }
        }

        if ($changes === []) {
            return [];
        }

        $entries[] = $this->entry(
            'geography:' . $countryName['en'],
            $changes,
            function () use ($manifest, $data, $countryName): void {
                $manifest->assertWritable('geography', [$data]);

                $country = Country::query()->where('name->en', $countryName['en'])->first();

                if ($country === null) {
                    $country = Country::query()->create([
                        'name' => $countryName,
                        'status' => true,
                    ]);
                }

                foreach (($data['governorates'] ?? []) as $definition) {
                    $name = $definition['name'];
                    $enabled = (bool) ($definition['is_fast_shipping_enabled'] ?? false);

                    $governorate = Governorate::query()
                        ->where('country_id', $country->getKey())
                        ->where('name->en', $name['en'])
                        ->first();

                    if ($governorate === null) {
                        $governorate = Governorate::query()->create([
                            'country_id' => $country->getKey(),
                            'name' => $name,
                            'status' => true,
                            'is_fast_shipping_enabled' => $enabled,
                        ]);
                    }

                    $price = $definition['shipping_price'] ?? null;

                    if ($price === null) {
                        continue;
                    }

                    $shipping = ShippingPrice::query()->firstOrNew(['governorate_id' => $governorate->getKey()]);
                    $shipping->fill([
                        'price' => $price,
                        'status' => true,
                    ])->save();
                }
            },
        );

        return $entries;
    }

    // =================================================================
    // 4. Warehouse + location
    // =================================================================

    private function planWarehouse(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('warehouse');

        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            throw new MeemSeedException('warehouse.code is required.');
        }

        $warehouse = Warehouse::withTrashed()->where('code', $code)->first();

        $wantsDefault = (bool) ($data['is_default'] ?? false);
        $existingDefault = Warehouse::query()
            ->where('is_default', true)
            ->when($warehouse !== null, fn ($q) => $q->whereKeyNot($warehouse->getKey()))
            ->first();

        $defaultBlocked = $wantsDefault && $existingDefault !== null;

        if ($defaultBlocked) {
            $context->line(
                "  [NOTE] warehouse: [{$existingDefault->code}] is the current default; "
                . "[{$code}] will be created non-default (the DB allows only one default)."
            );
        }

        $targetDefault = $wantsDefault && ! $defaultBlocked;

        $changes = [];

        if ($warehouse === null) {
            $changes['warehouse'] = MeemContext::change(null, $code);
        } else {
            if ($warehouse->trashed()) {
                $changes['deleted_at'] = MeemContext::change('soft-deleted', 'restored');
            }

            if (MeemContext::differs($warehouse->name, $data['name'] ?? $warehouse->name)) {
                $changes['name'] = MeemContext::change($warehouse->name, $data['name'] ?? null);
            }

            if ((bool) $warehouse->is_default !== $targetDefault) {
                $changes['is_default'] = MeemContext::change((bool) $warehouse->is_default, $targetDefault);
            }
        }

        $locationCode = $data['location']['code'] ?? null;

        if (is_string($locationCode) && $locationCode !== '') {
            $exists = $warehouse !== null && Location::query()
                ->where('warehouse_id', $warehouse->getKey())
                ->where('code', $locationCode)
                ->exists();

            if (! $exists) {
                $changes["location:{$locationCode}"] = MeemContext::change(null, $locationCode);
            }
        }

        if ($changes === []) {
            return [];
        }

        return [$this->entry("warehouse:{$code}", $changes, function () use ($manifest, $data, $code, $targetDefault): void {
            $manifest->assertWritable('warehouse', [$data]);

            $warehouse = Warehouse::withTrashed()->firstOrNew(['code' => $code]);

            if ($warehouse->exists && $warehouse->trashed()) {
                $warehouse->restore();
            }

            $warehouse->fill([
                'name' => $data['name'] ?? $warehouse->name ?? $code,
                'status' => 'active',
                'is_default' => $targetDefault,
            ])->save();

            $location = $data['location'] ?? null;

            if (! is_array($location) || empty($location['code'])) {
                return;
            }

            Location::query()->firstOrCreate(
                [
                    'warehouse_id' => $warehouse->getKey(),
                    'code' => $location['code'],
                ],
                [
                    'name' => $location['name'] ?? $location['code'],
                    'type' => $location['type'] ?? 'storage',
                    'status' => 'active',
                    'priority' => 0,
                ],
            );
        })];
    }

    // =================================================================
    // 5. Categories / macro departments
    // =================================================================

    private function planCategories(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('categories');

        $forbidden = array_map('intval', (array) ($data['rules']['forbidden_category_ids'] ?? []));
        $assignedTo = [];
        $entries = [];
        $changes = [];

        foreach (($data['roots'] ?? []) as $root) {
            $name = $this->bilingual($root['name'] ?? null, 'navigation_roots.roots[].name');
            $slug = (string) ($root['slug'] ?? '');

            if ($slug === '') {
                throw new MeemSeedException("A navigation root ({$name['en']}) has no slug.");
            }

            $rootModel = Category::query()->where('slug', $slug)->first()
                ?? Category::query()->where('name->en', $name['en'])->first();

            if ($rootModel === null) {
                $changes["root:{$slug}"] = MeemContext::change(null, $name['en']);
            } else {
                if ($this->isForbidden($rootModel->getKey(), $forbidden)) {
                    throw new MeemSeedException("Refusing to use forbidden category id [{$rootModel->getKey()}].");
                }

                if ((int) ($rootModel->parent_id ?? 0) !== 0) {
                    $changes["root:{$slug}.parent_id"] = MeemContext::change($rootModel->parent_id, null);
                }

                if ((int) $rootModel->level !== 1) {
                    $changes["root:{$slug}.level"] = MeemContext::change($rootModel->level, 1);
                }

                if (! (bool) $rootModel->active_nav) {
                    $changes["root:{$slug}.active_nav"] = MeemContext::change($rootModel->active_nav, true);
                }

                if (MeemContext::differs($rootModel->getTranslations('name'), $name)) {
                    $changes["root:{$slug}.name"] = MeemContext::change($rootModel->getTranslations('name'), $name);
                }
            }

            // Children are matched by English name — the only stable identifier
            // the manifest provides for pre-existing categories.
            foreach ((array) ($root['children_match_name_en'] ?? []) as $childName) {
                $matches = Category::query()->where('name->en', $childName)->get();

                if ($matches->isEmpty()) {
                    $context->line("  [NOTE] categories: child [{$childName}] not found — left untouched.");
                    continue;
                }

                if ($matches->count() > 1) {
                    throw new MeemSeedException(
                        "Category name [{$childName}] matches " . $matches->count()
                        . ' rows (' . $matches->pluck('id')->implode(', ') . '); refusing to guess.'
                    );
                }

                $child = $matches->first();

                if ($this->isForbidden($child->getKey(), $forbidden)) {
                    throw new MeemSeedException("Refusing to re-parent forbidden category id [{$child->getKey()}].");
                }

                if (isset($assignedTo[$child->getKey()]) && $assignedTo[$child->getKey()] !== $slug) {
                    throw new MeemSeedException(
                        "Category [{$childName}] would belong to two roots: "
                        . "[{$assignedTo[$child->getKey()]}] and [{$slug}]."
                    );
                }

                $assignedTo[$child->getKey()] = $slug;

                $needsReparent = $rootModel === null
                    || (int) ($child->parent_id ?? 0) !== (int) $rootModel->getKey()
                    || (int) $child->level !== 2;

                if ($needsReparent) {
                    $changes["child:{$childName}"] = MeemContext::change(
                        ['parent_id' => $child->parent_id, 'level' => $child->level],
                        ['parent_id' => $rootModel?->getKey() ?? 'new-root', 'level' => 2],
                    );
                }
            }
        }

        // Featured tiles (blocked while match_name_en holds placeholders).
        $featured = (bool) ($data['homepage_tile_categories']['is_featured'] ?? true);
        $minProducts = (int) ($data['homepage_tile_categories']['min_products_each'] ?? 0);

        foreach ((array) ($data['homepage_tile_categories']['match_name_en'] ?? []) as $tileName) {
            $category = Category::query()->where('name->en', $tileName)->first();

            if ($category === null) {
                $context->line("  [NOTE] categories: tile category [{$tileName}] not found.");
                continue;
            }

            // navigation_roots.rules.never_create_empty_department
            if ($minProducts > 0) {
                $count = $category->products()->count();

                if ($count < $minProducts) {
                    $context->line("  [NOTE] categories: tile [{$tileName}] has {$count} products (minimum {$minProducts}).");
                }
            }

            if ((bool) $category->is_featured !== $featured) {
                $changes["tile:{$tileName}.is_featured"] = MeemContext::change($category->is_featured, $featured);
            }
        }

        if ($changes === []) {
            return [];
        }

        $entries[] = $this->entry(
            'categories',
            $changes,
            function () use ($manifest, $data, $forbidden): void {
                $manifest->assertWritable('categories', [$data]);

                $assigned = [];

                foreach (($data['roots'] ?? []) as $root) {
                    $name = $root['name'];
                    $slug = (string) $root['slug'];

                    $rootModel = Category::query()->where('slug', $slug)->first()
                        ?? Category::query()->where('name->en', $name['en'])->first();

                    if ($rootModel === null) {
                        $rootModel = Category::query()->create([
                            'name' => $name,
                            'slug' => $slug,
                            'parent_id' => null,
                            'level' => 1,
                            'status' => true,
                            'active_nav' => (bool) ($root['active_nav'] ?? true),
                            'is_featured' => false,
                        ]);
                    } else {
                        if ($this->isForbidden($rootModel->getKey(), $forbidden)) {
                            throw new MeemSeedException("Forbidden category id [{$rootModel->getKey()}].");
                        }

                        $rootModel->fill([
                            'name' => $name,
                            'parent_id' => null,
                            'level' => 1,
                            'status' => true,
                            'active_nav' => (bool) ($root['active_nav'] ?? true),
                        ])->save();
                    }

                    foreach ((array) ($root['children_match_name_en'] ?? []) as $childName) {
                        $child = Category::query()->where('name->en', $childName)->first();

                        if ($child === null) {
                            continue;
                        }

                        if (isset($assigned[$child->getKey()]) && $assigned[$child->getKey()] !== $slug) {
                            throw new MeemSeedException(
                                "Category [{$childName}] would belong to two roots."
                            );
                        }

                        $assigned[$child->getKey()] = $slug;

                        $child->fill([
                            'parent_id' => $rootModel->getKey(),
                            'level' => 2,
                        ])->save();
                    }
                }

                foreach ((array) ($data['homepage_tile_categories']['match_name_en'] ?? []) as $tileName) {
                    Category::query()
                        ->where('name->en', $tileName)
                        ->update(['is_featured' => (bool) ($data['homepage_tile_categories']['is_featured'] ?? true)]);
                }
            },
        );

        return $entries;
    }

    // =================================================================
    // 6. Brands
    // =================================================================

    private function planBrands(MeemManifest $manifest): array
    {
        $data = $manifest->dataset('brands');
        $entries = [];

        // brands.rules.remove_unstocked — never seed a brand the manifest asks to remove.
        $removeUnstocked = array_map('mb_strtolower', (array) ($data['rules']['remove_unstocked'] ?? []));

        foreach (($data['items'] ?? []) as $index => $item) {
            $slug = (string) ($item['slug'] ?? '');
            $name = $this->bilingual($item['name'] ?? null, "brands.items[{$index}].name");

            if (in_array(mb_strtolower($name['en']), $removeUnstocked, true)) {
                throw new MeemSeedException(
                    "Brand [{$name['en']}] is listed in rules.remove_unstocked but is also seeded in items. "
                    . 'Remove it from items (this seeder never deletes a brand).'
                );
            }

            if ($slug === '') {
                continue;
            }

            $brand = Brand::query()->where('slug', $slug)->first()
                ?? Brand::query()->where('name->en', $name['en'])->first();

            $changes = [];

            if ($brand === null) {
                $changes['brand'] = MeemContext::change(null, $slug);
            } else {
                if (MeemContext::differs($brand->getTranslations('name'), $name)) {
                    $changes['name'] = MeemContext::change($brand->getTranslations('name'), $name);
                }

                if (! (bool) $brand->status) {
                    $changes['status'] = MeemContext::change($brand->status, true);
                }
            }

            if ($changes === []) {
                continue;
            }

            $entries[] = $this->entry("brand:{$slug}", $changes, function () use ($manifest, $slug, $name): void {
                $manifest->assertWritable('brands', ['slug' => $slug, 'name' => $name]);

                $brand = Brand::query()->where('slug', $slug)->first();

                if ($brand === null) {
                    Brand::query()->create([
                        'slug' => $slug,
                        'name' => $name,
                        'status' => true,
                    ]);

                    return;
                }

                $brand->fill(['name' => $name, 'status' => true])->save();
            });
        }

        return $entries;
    }

    // =================================================================
    // 7. Products
    // =================================================================

    private function planProducts(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('products');
        $entries = [];

        foreach (($data['homepage_skus'] ?? []) as $index => $definition) {
            $sku = trim((string) ($definition['sku'] ?? ''));

            if ($sku === '') {
                throw new MeemSeedException("products.homepage_skus[{$index}] has no sku.");
            }

            $name = $this->bilingual($definition['name'] ?? null, "products.homepage_skus[{$index}].name");
            $product = Product::query()->where('sku', $sku)->first();

            $changes = [];

            $attributes = [
                'name' => $name,
                'sku' => $sku,
                'slug' => Str::slug($name['en']) . '-' . strtolower($sku),
                'price' => $definition['price'] ?? null,
                'product_type' => 'simple',
                'item_type' => 'PHYSICAL',
                'status' => true,
            ];

            if (isset($definition['stock']['quantity'])) {
                $attributes['stock_quantity'] = (int) $definition['stock']['quantity'];
                $attributes['in_stock'] = (int) $definition['stock']['quantity'] > 0;
            }

            // products.rules.has_discount_iff_current_price_lt_price
            $attributes += $this->discountAttributes($definition, $attributes['price']);

            if ($product === null) {
                $changes['product'] = MeemContext::change(null, $sku);
            } else {
                foreach (['name', 'price', 'stock_quantity', 'has_discount', 'discount_type', 'discount_amount'] as $field) {
                    if (array_key_exists($field, $attributes) && MeemContext::differs($product->getAttribute($field), $attributes[$field])) {
                        $changes[$field] = MeemContext::change($product->getAttribute($field), $attributes[$field]);
                    }
                }

                if (! (bool) $product->status) {
                    $changes['status'] = MeemContext::change($product->status, true);
                }
            }

            $barcodes = array_filter([
                'piece_barcode' => $definition['piece_barcode'] ?? null,
                'carton_barcode' => $definition['carton_barcode'] ?? null,
                'pieces_per_carton' => $definition['pieces_per_carton'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            if ($barcodes !== [] && $product !== null) {
                $existing = $product->barcodes;

                foreach ($barcodes as $field => $value) {
                    if (MeemContext::differs($existing?->getAttribute($field), $value)) {
                        $changes["barcode.{$field}"] = MeemContext::change($existing?->getAttribute($field), $value);
                    }
                }
            }

            if ($changes === []) {
                continue;
            }

            $entries[] = $this->entry(
                "product:{$sku}",
                $changes,
                function () use ($manifest, $sku, $attributes, $barcodes, $definition): void {
                    $manifest->assertWritable('products', ['sku' => $sku, 'name' => $attributes['name']]);

                    $product = Product::query()->where('sku', $sku)->first()
                        ?? Product::query()->newModelInstance(['sku' => $sku]);

                    $product->fill($attributes)->save();

                    if ($barcodes !== []) {
                        ProductBarcode::query()->updateOrCreate(
                            ['product_id' => $product->getKey()],
                            $barcodes,
                        );
                    }

                    // Category + brand association (resolved by slug, never by id).
                    $categorySlugs = array_values(array_filter((array) ($definition['category_slugs'] ?? [])));
                    $categoryIds = $categorySlugs === []
                        ? []
                        : Category::query()->whereIn('slug', $categorySlugs)->pluck('id')->all();

                    if ($categoryIds !== []) {
                        $product->categories()->syncWithoutDetaching($categoryIds);
                    }

                    $brandSlug = $definition['brand_slug'] ?? null;

                    if (is_string($brandSlug) && $brandSlug !== '') {
                        $brandId = Brand::query()->where('slug', $brandSlug)->value('id');

                        if ($brandId !== null) {
                            $product->brands()->sync([$brandId]);
                        }
                    }

                    // Stock placement, if a warehouse+location and a quantity exist.
                    $warehouseCode = $definition['stock']['warehouse_code'] ?? null;
                    $quantity = $definition['stock']['quantity'] ?? null;

                    if (is_string($warehouseCode) && $quantity !== null) {
                        $warehouse = Warehouse::query()->where('code', $warehouseCode)->first();
                        $location = $warehouse === null
                            ? null
                            : Location::query()->where('warehouse_id', $warehouse->getKey())->orderBy('priority', 'desc')->first();

                        if ($warehouse !== null && $location !== null) {
                            ProductLocation::query()->updateOrCreate(
                                [
                                    'product_id' => $product->getKey(),
                                    'location_id' => $location->getKey(),
                                ],
                                [
                                    'warehouse_id' => $warehouse->getKey(),
                                    'quantity' => $quantity,
                                ],
                            );
                        }
                    }
                },
            );
        }

        if ($entries !== []) {
            $context->line('  [NOTE] products: ratings and sold_quantity are never written (no fabricated sales data).');
        }

        return $entries;
    }

    // =================================================================
    // 8. Bulk merchandising
    // =================================================================

    private function planBulk(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('bulk');

        $skus = array_values(array_filter((array) ($data['skus'] ?? [])));
        $tagDefinition = $data['tag'] ?? null;

        if ($skus === []) {
            return [];
        }

        $found = Product::query()->whereIn('sku', $skus)->pluck('sku')->all();
        $missing = array_values(array_diff($skus, $found));

        if ($missing !== []) {
            $context->line('  [NOTE] bulk: SKUs not found, skipped: ' . implode(', ', $missing));
        }

        // bulk_merchandising.target_count — reported, never padded with fake SKUs.
        $target = (array) ($data['target_count'] ?? []);
        $minTarget = (int) ($target['min'] ?? 0);
        $maxTarget = (int) ($target['max'] ?? 0);
        $count = count($found);

        if ($minTarget > 0 && $count < $minTarget) {
            $context->line("  [NOTE] bulk: {$count} SKUs resolved, target minimum {$minTarget}.");
        }

        if ($maxTarget > 0 && $count > $maxTarget) {
            $context->line("  [NOTE] bulk: {$count} SKUs resolved, target maximum {$maxTarget}.");
        }

        $products = Product::query()->whereIn('sku', $found)->get();

        $changes = [];
        $tagSlug = is_array($tagDefinition) ? (string) ($tagDefinition['slug'] ?? '') : '';

        if ($tagSlug !== '') {
            $tag = Tag::query()->where('slug', $tagSlug)->first();

            if ($tag === null) {
                $changes["tag:{$tagSlug}"] = MeemContext::change(null, $tagSlug);
            } else {
                $attached = $tag->products()->pluck('products.id')->all();

                foreach ($products as $product) {
                    if (! in_array($product->getKey(), $attached, true)) {
                        $changes["attach:{$product->sku}"] = MeemContext::change(false, true);
                    }
                }
            }
        }

        // Pack completeness is reported, never fabricated: barcode values are
        // owned by the products dataset and must come from real data.
        $requiredPack = array_values(array_filter((array) (
            $data['require_complete_pack_data'] ?? ['pieces_per_carton', 'carton_barcode']
        )));

        foreach ($products as $product) {
            $barcodes = $product->barcodes;

            foreach ($requiredPack as $field) {
                if ($barcodes === null || $barcodes->getAttribute($field) === null) {
                    $context->line("  [NOTE] bulk: {$product->sku} is missing [{$field}] (not fabricated here).");

                    break;
                }
            }
        }

        if ($changes === []) {
            return [];
        }

        return [$this->entry('bulk', $changes, function () use ($manifest, $data, $found, $tagSlug): void {
            $manifest->assertWritable('bulk', ['tag' => $data['tag'] ?? null]);

            if ($tagSlug === '') {
                return;
            }

            $tag = Tag::query()->firstOrCreate(
                ['slug' => $tagSlug],
                ['name' => $data['tag']['name'] ?? ['ar' => $tagSlug, 'en' => $tagSlug]],
            );

            $ids = Product::query()->whereIn('sku', $found)->pluck('id')->all();

            if ($ids !== []) {
                $tag->products()->syncWithoutDetaching($ids);
            }
        })];
    }

    // =================================================================
    // 9. Banners (verify only — never create, never -s2)
    // =================================================================

    private function planBanners(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('banners');
        $forbidden = array_map('strval', (array) ($data['forbidden_slugs'] ?? []));

        foreach (($data['items'] ?? []) as $item) {
            $slug = (string) ($item['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            if (in_array($slug, $forbidden, true)) {
                throw new MeemSeedException("Refusing to reference forbidden banner slug [{$slug}].");
            }

            $banner = Banner::query()->where('slug', $slug)->first();

            if ($banner === null) {
                $context->line("  [NOTE] banners: [{$slug}] does not exist — banners are never created by this seeder.");

                continue;
            }

            $expected = $item['expect_products'] ?? null;

            if (is_int($expected)) {
                $count = $banner->products()->count();

                if ($count !== $expected) {
                    $context->line("  [NOTE] banners: [{$slug}] has {$count} products, expected {$expected}.");
                }
            }
        }

        // Verification only: nothing to write.
        return [];
    }

    // =================================================================
    // 10. Flash sales (verify only — never reference forbidden ids)
    // =================================================================

    private function planFlashSales(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('flash_sales');
        $forbiddenIds = array_map('intval', (array) ($data['forbidden_sale_ids'] ?? []));

        $slug = (string) ($data['live_sale']['match'] ?? '');

        if ($slug !== '') {
            $sale = FlashSale::query()->where('slug', $slug)->first();

            if ($sale === null) {
                $context->line("  [NOTE] flash_sales: live sale [{$slug}] not found.");
            } else {
                if (in_array((int) $sale->getKey(), $forbiddenIds, true)) {
                    throw new MeemSeedException("Live sale resolves to forbidden id [{$sale->getKey()}].");
                }

                if (! (bool) $sale->status) {
                    $context->line("  [NOTE] flash_sales: [{$slug}] is not active.");
                }

                $min = (int) ($data['live_sale']['min_products'] ?? 0);
                $count = $sale->products()->count();

                if ($count < $min) {
                    $context->line("  [NOTE] flash_sales: [{$slug}] has {$count} products, minimum {$min}.");
                }
            }
        }

        $weekly = $data['weekly_offer_interim'] ?? [];

        if (($weekly['enabled'] ?? false) === true) {
            // The manifest is explicit that this is an interim. It cannot be
            // honoured mechanically: there is no weekly-offers section type on
            // this backend, so a flash sale would have to be created and a
            // section retargeted. Refuse loudly rather than half-implement it.
            throw new MeemSeedException(
                'flash_sales.weekly_offer_interim.enabled is true, but this backend has no '
                . '[weekly-offers] section type. Either set enabled=false or approve the '
                . 'flash-sale interim explicitly.'
            );
        }

        return [];
    }

    // =================================================================
    // 11. Coupons (match by code only — never create, never edit a code)
    // =================================================================

    private function planCoupons(MeemManifest $manifest): array
    {
        $data = $manifest->dataset('coupons');
        $entries = [];

        foreach (($data['items'] ?? []) as $item) {
            $code = strtoupper(trim((string) ($item['code'] ?? '')));

            if ($code === '') {
                continue;
            }

            // Canonical, case-insensitive lookup (App\Support\CouponCode).
            $coupon = Coupon::query()->byCode($code)->first();

            if ($coupon === null) {
                throw new MeemSeedException(
                    "Coupon [{$code}] does not exist. Coupon codes are server-generated; "
                    . 'this seeder never creates or edits a code.'
                );
            }

            $attributes = [];

            foreach (['discount_type', 'discount', 'max_discount_amount', 'start_date', 'end_date', 'limiter', 'status'] as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null) {
                    $attributes[$field] = $item[$field];
                }
            }

            $changes = [];

            foreach ($attributes as $field => $value) {
                if (MeemContext::differs($coupon->getAttribute($field), $value)) {
                    $changes[$field] = MeemContext::change($coupon->getAttribute($field), $value);
                }
            }

            if ($changes === []) {
                continue;
            }

            $entries[] = $this->entry("coupon:{$code}", $changes, function () use ($manifest, $code, $attributes): void {
                $manifest->assertWritable('coupons', ['code' => $code] + $attributes);

                $coupon = Coupon::query()->byCode($code)->firstOrFail();

                // `code` and `used` are system-managed and are never touched.
                $coupon->fill($attributes)->save();
            });
        }

        return $entries;
    }

    // =================================================================
    // 12. Payment gateways (enabled only — never available, never credentials)
    // =================================================================

    private function planPayment(MeemManifest $manifest, MeemContext $context): array
    {
        $data = $manifest->dataset('payment');

        $known = array_keys((array) config('payment.gateways', []));
        $requested = (array) ($data['gateways_enabled'] ?? []);

        $unknown = array_values(array_diff(array_keys($requested), $known));

        if ($unknown !== []) {
            throw new MeemSeedException(
                'Unknown payment gateway(s) in the manifest: ' . implode(', ', $unknown)
                . '. Known: ' . implode(', ', $known) . '.'
            );
        }

        $absent = array_values(array_diff($known, array_keys($requested)));

        if ($absent !== []) {
            $context->line('  [NOTE] payment: registered but not in the manifest: ' . implode(', ', $absent));
        }

        $settings = Settings::query()->first();

        if ($settings === null) {
            throw new MeemSeedException('Payment depends on the settings row: run the settings dataset first.');
        }

        $options = is_array($settings->options) ? $settings->options : [];
        $gateways = is_array($options['payment_gateways'] ?? null) ? $options['payment_gateways'] : [];

        $changes = [];
        $patch = [];

        foreach ($requested as $code => $enabled) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            $current = $gateways[$code]['enabled'] ?? null;

            if (MeemContext::differs($current, $enabled)) {
                $changes["payment_gateways.{$code}.enabled"] = MeemContext::change($current, $enabled);
                $patch[$code] = $enabled;
            }
        }

        if ($changes === []) {
            return [];
        }

        return [$this->entry('payment', $changes, function () use ($manifest, $data, $patch): void {
            $manifest->assertWritable('payment', ['gateways_enabled' => $patch]);

            $settings = Settings::query()->firstOrFail();
            $options = is_array($settings->options) ? $settings->options : [];
            $gateways = is_array($options['payment_gateways'] ?? null) ? $options['payment_gateways'] : [];

            foreach ($patch as $code => $enabled) {
                $existing = is_array($gateways[$code] ?? null) ? $gateways[$code] : [];
                // Only `enabled` is ever written; `available` is computed at runtime.
                $existing['enabled'] = $enabled;
                $gateways[$code] = $existing;
            }

            $options['payment_gateways'] = $gateways;
            $settings->options = $options;
            $settings->save();
        })];
    }

    // =================================================================
    // 13. Homepage sections (per-record gating)
    // =================================================================

    private function runSections(MeemManifest $manifest, MeemContext $context): void
    {
        $slug = (string) $manifest->get('homepage_sections.content_page_slug', 'home');
        $page = ContentPage::query()->where('slug', $slug)->first();

        if ($page === null) {
            $context->line("  [SKIP] sections — content page [{$slug}] does not exist (create it in the CMS first).");

            return;
        }

        $definitions = (array) $manifest->get('homepage_sections.sections', []);
        $entries = [];

        foreach ($definitions as $index => $definition) {
            $label = 'order ' . ($definition['order'] ?? '?') . ' (' . ($definition['type'] ?? '?') . ')';

            $gate = $definition['gate'] ?? null;

            if (! $manifest->gateSigned($gate)) {
                $context->line("  [SKIP] section {$label} — unsigned gate [{$gate}]");

                continue;
            }

            $missing = $manifest->placeholdersInPath("homepage_sections.sections.{$index}");

            if ($missing !== []) {
                $context->line("  [SKIP] section {$label} — unresolved: " . implode(', ', array_keys($missing)));

                continue;
            }

            $template = (string) ($definition['endpoint_template'] ?? '');

            if (preg_match('/<[^<>]+>/', $template) === 1) {
                $context->line("  [SKIP] section {$label} — endpoint template is not resolvable yet.");

                continue;
            }

            if (isset($definition['timer_end_at']) && preg_match('/<[^<>]+>/', (string) $definition['timer_end_at']) === 1) {
                $context->line("  [SKIP] section {$label} — timer_end_at is not resolvable yet.");

                continue;
            }

            $entry = $this->planSection($definition, $page, $context);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            $context->noop('sections');

            return;
        }

        if ($context->isDryRun()) {
            $context->planned('sections', $this->summarise($entries));

            return;
        }

        $context->guard('sections', function () use ($entries, $context): void {
            $context->transaction(function () use ($entries): void {
                foreach ($entries as $entry) {
                    ($entry['write'])();
                }
            });
        });

        $this->flush('sections');
        $context->applied('sections', $this->summarise($entries));
    }

    /**
     * Sections are matched on (content_page_id, type, order) — unique on the
     * home page. A missing section is reported, never created: `sections.title`
     * is NOT NULL and this seeder does not invent copy.
     *
     * @param array<string, mixed> $definition
     */
    private function planSection(array $definition, ContentPage $page, MeemContext $context): ?array
    {
        $type = (string) ($definition['type'] ?? '');
        $order = (int) ($definition['order'] ?? 0);

        if ($type === '' || $order < 1) {
            return null;
        }

        $section = Section::query()
            ->where('content_page_id', $page->getKey())
            ->where('type', $type)
            ->where('order', $order)
            ->first();

        if ($section === null) {
            $context->line("  [NOTE] sections: order {$order} ({$type}) not found on page [{$page->slug}] — not created.");

            return null;
        }

        $currentSetting = is_array($section->setting) ? $section->setting : [];
        $nextSetting = $currentSetting;

        if (isset($definition['setting_front']) && is_array($definition['setting_front'])) {
            $nextSetting['front'] = $this->deepMerge(
                is_array($currentSetting['front'] ?? null) ? $currentSetting['front'] : [],
                $definition['setting_front'],
            );
        }

        if (isset($definition['endpoint_template'])) {
            // The storefront endpoint is derived from `type` + `setting.back`
            // (SectionResource::buildEndpoint). The `sections.endpoint` column is
            // never read, so the template is written here, not there.
            $nextSetting['back'] = $this->backFromTemplate((string) $definition['endpoint_template']);
        }

        $changes = [];

        if (MeemContext::differs($currentSetting, $nextSetting)) {
            $changes['setting'] = MeemContext::change($currentSetting, $nextSetting);
        }

        $isActive = $definition['is_active'] ?? null;
        $nextActive = is_bool($isActive) ? $isActive : null;

        if ($nextActive !== null && (bool) $section->is_active !== $nextActive) {
            $changes['is_active'] = MeemContext::change((bool) $section->is_active, $nextActive);
        }

        if ($changes === []) {
            return null;
        }

        return $this->entry("section:{$order}:{$type}", $changes, function () use ($section, $nextSetting, $nextActive, $order, $page): void {
            $attributes = ['setting' => $nextSetting];

            if ($nextActive !== null) {
                $attributes['is_active'] = $nextActive;
            }

            $section->fill($attributes)->save();

            // SortableTrait owns `order`; force the intended value explicitly.
            DB::table('sections')
                ->where('id', $section->getKey())
                ->update(['order' => $order, 'content_page_id' => $page->getKey()]);
        });
    }

    /**
     * Convert "general/<type>?a=1&b[]=2" into the `setting.back` array the
     * storefront consumes. Numeric ids are cast so re-runs stay stable.
     *
     * @return array<string, mixed>
     */
    private function backFromTemplate(string $template): array
    {
        $position = strpos($template, '?');

        if ($position === false) {
            return [];
        }

        parse_str(substr($template, $position + 1), $params);

        $idKeys = ['categoriesId', 'brandsId', 'flashSalesId', 'couponsId', 'productsId', 'slidersId', 'bannersId', 'tagsId'];

        foreach ($idKeys as $key) {
            if (isset($params[$key]) && is_array($params[$key])) {
                $params[$key] = array_values(array_map('intval', $params[$key]));
            }
        }

        if (isset($params['limit']) && is_numeric($params['limit'])) {
            $params['limit'] = (int) $params['limit'];
        }

        return $params;
    }

    // =================================================================
    // Cache invalidation (after commit only)
    // =================================================================

    private function flush(string $dataset): void
    {
        $tags = match ($dataset) {
            'settings', 'currency', 'payment' => [FrontendResource::SETTINGS->value, FrontendResource::CURRENCIES->value],
            'categories' => [FrontendResource::CATEGORIES->value, FrontendResource::CONTENT_PAGES->value],
            'brands' => [FrontendResource::BRANDS->value],
            'products' => [FrontendResource::PRODUCTS->value],
            'bulk' => [FrontendResource::PRODUCTS->value, FrontendResource::TAGS->value],
            'banners' => [FrontendResource::BANNERS->value],
            'flash_sales' => [FrontendResource::FLASH_SALES->value],
            'coupons' => [FrontendResource::COUPONS->value],
            'sections' => [FrontendResource::CONTENT_PAGES->value],
            default => [],
        };

        if ($tags === []) {
            return;
        }

        try {
            Cache::tags($tags)->flush();
        } catch (\BadMethodCallException) {
            // Store without tag support: entries self-heal by TTL.
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function writer(): Closure
    {
        return function (string $line): void {
            if ($this->command !== null) {
                $this->command->getOutput()->writeln($line);

                return;
            }

            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    /** @return array{0: bool, 1: bool} */
    private function resolveFlags(): array
    {
        $apply = false;
        $force = false;

        if ($this->command !== null) {
            $input = $this->command->getInput();

            // Safe on any InputInterface: unknown options return the default.
            $apply = (bool) $input->getParameterOption('--apply', false);
            $force = (bool) $input->getParameterOption('--force', false);
        }

        if (! $apply) {
            $apply = filter_var(MeemContext::envValue('MEEM_SEED_APPLY') ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return [$apply, $force];
    }

    private function castSetting(string $field, mixed $value): mixed
    {
        return match ($field) {
            'fast_shipping_page_publish' => (int) (bool) $value,
            default => $value,
        };
    }

    /**
     * Translate the manifest's product discount block into product columns.
     *
     * Enforces `products.rules.has_discount_iff_current_price_lt_price`:
     * a discount is only flagged when it actually lowers the price, and an
     * impossible discount is rejected instead of being written.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function discountAttributes(array $definition, mixed $price): array
    {
        $discount = is_array($definition['discount'] ?? null) ? $definition['discount'] : [];
        $amount = $discount['amount'] ?? null;

        if ($amount === null || $amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            return [];
        }

        $type = match ((string) ($discount['type'] ?? 'percentage')) {
            'percentage' => 'percentage',
            'fixed', 'fixed_rate' => 'fixed_rate',
            default => throw new MeemSeedException(
                'Unsupported product discount type [' . (string) ($discount['type'] ?? '') . '].'
            ),
        };

        if ($type === 'percentage' && (float) $amount >= 100) {
            throw new MeemSeedException('A percentage product discount must be below 100.');
        }

        if ($type === 'fixed_rate' && is_numeric($price) && (float) $amount >= (float) $price) {
            throw new MeemSeedException(
                'A fixed product discount must be lower than the price, otherwise the discount is not real.'
            );
        }

        $attributes = [
            'has_discount' => true,
            'discount_type' => $type,
            'discount_amount' => $amount,
            'discount_status' => true,
        ];

        if (! empty($discount['start_date'])) {
            $attributes['start_date'] = $discount['start_date'];
        }

        if (! empty($discount['end_date'])) {
            $attributes['end_date'] = $discount['end_date'];
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function currencyCode(array $data): string    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($code === '' || strlen($code) > 3) {
            throw new MeemSeedException('Manifest currency.code must be a 1–3 letter ISO code.');
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function currencyRate(array $data): string
    {
        $rate = $data['rate'] ?? null;

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new MeemSeedException('Manifest currency.rate must be a positive number.');
        }

        return (string) $rate;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function currencyOption(array $data, string $key): ?string
    {
        $options = $data['options'] ?? [];

        if (! is_array($options) || ! array_key_exists($key, $options)) {
            return null;
        }

        $value = strtoupper(trim((string) $options[$key]));

        return $value === '' ? null : $value;
    }

    /**
     * Only the currency options the manifest asks for.
     *
     * `currency.set_base` / `currency.set_catalog` are honoured literally: a
     * false flag means that option is left exactly as it is.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function currencyOptionsToWrite(array $data): array
    {
        $setBase = (bool) ($data['set_base'] ?? true);
        $setCatalog = (bool) ($data['set_catalog'] ?? true);

        $skipped = [];

        if (! $setBase) {
            $skipped[] = 'base_currency_code';
        }

        if (! $setCatalog) {
            $skipped[] = 'catalog_currency_code';
        }

        $out = [];

        foreach (self::CURRENCY_OPTION_KEYS as $key) {
            if (in_array($key, $skipped, true)) {
                continue;
            }

            $value = $this->currencyOption($data, $key);

            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array{ar: string, en: string}
     */
    private function bilingual(mixed $value, string $path): array
    {
        if (! is_array($value) || ! isset($value['ar'], $value['en'])) {
            throw new MeemSeedException("{$path} must be an object with both ar and en.");
        }

        $ar = trim((string) $value['ar']);
        $en = trim((string) $value['en']);

        if ($ar === '' || $en === '') {
            throw new MeemSeedException("{$path} must be non-empty in both ar and en.");
        }

        return ['ar' => $ar, 'en' => $en];
    }

    /**
     * @param array<int> $forbidden
     */
    private function isForbidden(int|string $id, array $forbidden): bool
    {
        return in_array((int) $id, $forbidden, true);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
