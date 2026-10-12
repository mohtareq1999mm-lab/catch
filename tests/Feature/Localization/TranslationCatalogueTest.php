<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Translation catalogue integrity for the API localization audit.
 *
 * DB-free: translator only. Fails on missing/empty/copied values, key
 * drift between ar/en, duplicate-array-key regressions (PHP silently
 * overwrites), and untranslated legacy files.
 */
class TranslationCatalogueTest extends TestCase
{
    /** @return array<string, array<string>> */
    private static function flatKeys(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                $out = array_merge($out, self::flatKeys($v, $key));
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /** @dataProvider catalogueFiles */
    public function test_catalogue_file_parity(string $file): void
    {
        $arPath = base_path("resources/lang/ar/{$file}.php");
        $enPath = base_path("resources/lang/en/{$file}.php");

        $this->assertFileExists($arPath, "ar/{$file}.php must exist");
        $this->assertFileExists($enPath, "en/{$file}.php must exist");

        $ar = self::flatKeys(require $arPath);
        $en = self::flatKeys(require $enPath);

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($ar))),
            "Keys present in en/{$file}.php but missing in ar/{$file}.php"
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($ar), array_keys($en))),
            "Keys present in ar/{$file}.php but missing in en/{$file}.php"
        );

        foreach (['ar' => $ar, 'en' => $en] as $locale => $keys) {
            foreach ($keys as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $this->assertNotSame('', trim($value), "[{$locale}/{$file}] {$key} must not be empty");
                $this->assertNotSame($key, $value, "[{$locale}/{$file}] {$key} must not be a raw key copy");
            }
        }
    }

    public static function catalogueFiles(): array
    {
        return [
            'auth' => ['auth'],
            'passwords' => ['passwords'],
            'pagination' => ['pagination'],
            'common' => ['common'],
            'sms' => ['sms'],
            'checkout' => ['checkout'],
            'coupon' => ['coupon'],
            'tracking' => ['tracking'],
            'notifications' => ['notifications'],
            'permissions' => ['permissions'],
            'activity' => ['activity'],
            'validation' => ['validation'],
            'message' => ['message'],
            'wms' => ['wms'],
        ];
    }

    public function test_legacy_english_files_are_translated_to_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertNotSame('These credentials do not match our records.', __('auth.failed'));
        $this->assertStringNotContainsStringIgnoringCase('credentials', __('auth.failed'));
        $this->assertNotSame('Your password has been reset!', __('passwords.reset'));
        $this->assertNotSame('&laquo; Previous', __('pagination.previous'));
    }

    public function test_shipment_timeline_keys_survived_the_duplicate_key_merge(): void
    {
        // Regression: tracking.php defined 'shipment' twice; the first block
        // (created/failed/…) was silently overwritten → raw keys to customers.
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'tracking.shipment.created',
                'tracking.shipment.created_description',
                'tracking.shipment.failed',
                'tracking.shipment.failed_description',
                'tracking.shipment.pending',
                'tracking.shipment.label_created',
                'tracking.shipment.eta_updated',
                'tracking.shipment.eta_delayed',
            ] as $key) {
                $resolved = __($key, ['tracking_number' => 'X', 'new_eta' => 'Y', 'delay_days' => 1, 'courier_name' => 'C']);
                $this->assertNotSame($key, $resolved, "[{$locale}] {$key} must resolve");
                $this->assertStringNotContainsString(':tracking_number', $resolved, "[{$locale}] {$key} placeholders");
            }
        }
    }

    public function test_handler_generic_keys_resolve_in_both_locales(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'message.ERROR.INTERNAL',
                'message.ERROR.RESOURCE_NOT_FOUND',
                'message.ERROR.HTTP_NOT_FOUND',
                'message.ERROR.METHOD_NOT_ALLOWED',
                'message.ERROR.INVALID_SIGNATURE',
                'message.ERROR.UNAUTHENTICATED',
                'message.ERROR.UNAUTHORIZED_ACTION',
                'message.ERROR.DATABASE_ERROR',
                'message.ERROR.INVALID_DATA',
                'message.ERROR.SOMETHING_WENT_WRONG',
            ] as $key) {
                $this->assertNotSame($key, __($key), "[{$locale}] {$key} must resolve");
            }
        }
    }

    public function test_wms_catalogue_resolves_with_placeholders(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $resolved = __('wms.batch.item_open_task', ['item' => 7, 'task' => 9, 'status' => 'picking']);
            $this->assertStringContainsString('7', $resolved);
            $this->assertStringContainsString('9', $resolved);
            $this->assertStringNotContainsString(':item', $resolved);
            // Stable machine prefix must survive translation verbatim.
            $this->assertStringStartsWith(
                'PLACEMENT_MISMATCH:',
                __('wms.placement.mismatch', ['id' => 1, 'warehouse' => 2, 'location' => 3])
            );
        }
    }

    public function test_arabic_output_contains_no_english_for_core_keys(): void
    {
        app()->setLocale('ar');
        $this->assertSame('البيانات المُدخلة غير صحيحة.', __('message.ERROR.INVALID_DATA'));
        $this->assertSame('غير مصرح. يرجى تسجيل الدخول.', __('message.ERROR.UNAUTHENTICATED'));
    }

    public function test_coupon_catalogue_placeholders_match(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $resolved = __('message.MESSAGE.COUPON_CONFIG_HIGH_LIMIT', ['limiter' => 5000]);
            $this->assertStringContainsString('5000', $resolved);
            $this->assertStringNotContainsString(':limiter', $resolved);
        }
    }

    public function test_trans_choice_hours_both_locales(): void
    {
        app()->setLocale('en');
        $this->assertSame('1 hour', trans_choice('message.MESSAGE.ANALYTICS_HOURS', 1, ['count' => 1]));
        $this->assertSame('5 hours', trans_choice('message.MESSAGE.ANALYTICS_HOURS', 5, ['count' => 5]));

        app()->setLocale('ar');
        $this->assertStringNotContainsString(':count', trans_choice('message.MESSAGE.ANALYTICS_HOURS', 5, ['count' => 5]));
        $this->assertNotSame(
            'message.MESSAGE.ANALYTICS_HOURS',
            trans_choice('message.MESSAGE.ANALYTICS_HOURS', 2, ['count' => 2])
        );
    }

    public function test_lang_files_have_no_duplicate_top_level_keys(): void
    {
        // PHP keeps the LAST duplicate silently; this guards the exact
        // regression class found in tracking.php ('shipment' defined twice
        // at the same level). Same (indent,key) array-openers must be unique.
        $duplicates = [];
        foreach (['auth', 'passwords', 'pagination', 'common', 'sms', 'checkout', 'coupon', 'tracking', 'notifications', 'permissions', 'activity', 'validation', 'message', 'wms'] as $file) {
            foreach (['ar', 'en'] as $locale) {
                $content = file_get_contents(base_path("resources/lang/{$locale}/{$file}.php"));
                preg_match_all("/^(\s*)'([^']+)' => \[$/m", $content, $m, PREG_SET_ORDER);
                $seen = [];
                foreach ($m as $match) {
                    $signature = strlen($match[1]).':'.$match[2];
                    if (isset($seen[$signature])) {
                        $duplicates[] = "[{$locale}/{$file}] '{$match[2]}' at indent ".strlen($match[1]);
                    }
                    $seen[$signature] = true;
                }
            }
        }
        $this->assertSame([], $duplicates, 'Duplicate array keys: '.implode(', ', $duplicates));
    }

    public function test_translation_helper_lang_facade_agrees(): void
    {
        app()->setLocale('ar');
        $this->assertTrue(Lang::has('message.ERROR.INVALID_DATA'));
        $this->assertTrue(Lang::has('wms.batch.created'));
        $this->assertTrue(Lang::has('tracking.shipment.created'));

        app()->setLocale('en');
        $this->assertTrue(Lang::has('auth.failed'));
        $this->assertTrue(Lang::has('passwords.reset'));
        $this->assertTrue(Lang::has('pagination.next'));
    }

    public function test_every_permission_enum_value_has_a_label_in_both_locales(): void
    {
        // PermissionResource renders __('permissions.'.$name); a missing key
        // leaks raw 'permissions.view-warehouse' into roles/users responses.
        $values = [];
        foreach ((new \ReflectionClass(\Marvel\Enums\Permission::class))->getConstants() as $name => $value) {
            if (in_array($name, ['EDITOR', 'STORE_OWNER', 'STAFF', 'CUSTOMER'], true)) {
                continue;
            }
            $values[] = $value;
        }
        $values = array_values(array_unique($values));
        $this->assertNotEmpty($values);

        $missing = [];
        foreach (['ar', 'en'] as $locale) {
            $labels = require base_path("resources/lang/{$locale}/permissions.php");
            foreach ($values as $value) {
                if (!array_key_exists($value, $labels) || trim((string) $labels[$value]) === '') {
                    $missing[] = "[{$locale}] {$value}";
                }
            }
        }
        $this->assertSame([], $missing, 'Missing permission labels: '.implode(', ', $missing));
    }
}
