<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CurrencyPermissionTest extends CurrencyTestCase
{
    private const PERMISSION_VALUES = [
        'view-currencies',
        'create-currency',
        'update-currency',
        'delete-currency',
        'view-exchange-rates',
        'create-exchange-rate',
        'update-exchange-rate',
        'delete-exchange-rate',
        'set-base-currency',
        'set-catalog-currency',
    ];

    private const PERMISSION_LABELS = [
        'view-currencies' => ['en' => 'View currencies', 'ar' => 'عرض العملات'],
        'create-currency' => ['en' => 'Create currency', 'ar' => 'إنشاء عملة'],
        'update-currency' => ['en' => 'Update currency', 'ar' => 'تعديل عملة'],
        'delete-currency' => ['en' => 'Delete currency', 'ar' => 'حذف عملة'],
        'view-exchange-rates' => ['en' => 'View exchange rates', 'ar' => 'عرض أسعار الصرف'],
        'create-exchange-rate' => ['en' => 'Create exchange rate', 'ar' => 'إضافة سعر صرف'],
        'update-exchange-rate' => ['en' => 'Update exchange rate', 'ar' => 'تعديل سعر صرف'],
        'delete-exchange-rate' => ['en' => 'Delete exchange rate', 'ar' => 'حذف سعر صرف'],
        'set-base-currency' => ['en' => 'Set base currency', 'ar' => 'تعيين العملة الأساسية'],
        'set-catalog-currency' => ['en' => 'Set catalog currency', 'ar' => 'تعيين عملة المتجر'],
    ];

    /** @test */
    public function permission_enum_contains_all_currency_permissions(): void
    {
        $this->assertSame('view-currencies', PermissionEnum::VIEW_CURRENCIES);
        $this->assertSame('create-currency', PermissionEnum::CREATE_CURRENCY);
        $this->assertSame('update-currency', PermissionEnum::UPDATE_CURRENCY);
        $this->assertSame('delete-currency', PermissionEnum::DELETE_CURRENCY);
        $this->assertSame('view-exchange-rates', PermissionEnum::VIEW_EXCHANGE_RATES);
        $this->assertSame('create-exchange-rate', PermissionEnum::CREATE_EXCHANGE_RATE);
        $this->assertSame('update-exchange-rate', PermissionEnum::UPDATE_EXCHANGE_RATE);
        $this->assertSame('delete-exchange-rate', PermissionEnum::DELETE_EXCHANGE_RATE);
        $this->assertSame('set-base-currency', PermissionEnum::SET_BASE_CURRENCY);
        $this->assertSame('set-catalog-currency', PermissionEnum::SET_CATALOG_CURRENCY);
    }

    /** @test */
    public function permission_seeder_creates_all_currency_permissions_for_api_guard(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (self::PERMISSION_VALUES as $name) {
            $this->assertDatabaseHas('permissions', [
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);
        }
    }

    /** @test */
    public function permission_seeder_does_not_create_duplicate_records(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        foreach (self::PERMISSION_VALUES as $name) {
            $count = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', self::GUARD)
                ->count();

            $this->assertSame(1, $count, "Permission {$name} must exist exactly once after re-seeding");
        }
    }

    /** @test */
    public function super_admin_role_receives_all_currency_permissions_from_seeder(): void
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::query()->where('name', RoleEnum::SUPER_ADMIN)->where('guard_name', self::GUARD)->first();

        $this->assertNotNull($role);

        foreach (self::PERMISSION_VALUES as $name) {
            $this->assertTrue(
                $role->hasPermissionTo($name, self::GUARD),
                "super_admin must have permission {$name}"
            );
        }
    }

    /** @test */
    public function all_currency_permissions_have_english_and_arabic_translations(): void
    {
        foreach (self::PERMISSION_LABELS as $name => $labels) {
            foreach (['en', 'ar'] as $locale) {
                Lang::setLocale($locale);

                $resolved = __("permissions.{$name}");
                $this->assertNotSame(
                    "permissions.{$name}",
                    $resolved,
                    "Translation for {$name} in {$locale} must not fall back to the raw key"
                );

                $this->assertSame($labels[$locale], $resolved, "Unexpected label for {$name} in {$locale}");
            }
        }
    }

    /** @test */
    public function permission_resource_exposes_translated_labels(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (self::PERMISSION_VALUES as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', self::GUARD)->first();

            $this->assertNotNull($permission);

            $resource = (new \Marvel\Http\Resources\PermissionResource($permission))
                ->resolve(request());

            $this->assertSame(
                self::PERMISSION_LABELS[$name]['en'],
                $resource['label'],
                "Label for {$name} in default locale (en)"
            );
        }
    }

    /** @test */
    public function unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson(self::PREFIX . '/currencies')->assertStatus(401);
        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(401);
        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(401);
        $this->postJson(self::PREFIX . '/currency-rates', [])->assertStatus(401);
    }

    /** @test */
    public function customer_without_currency_permissions_is_forbidden(): void
    {
        $this->createAuthenticatedCustomer();

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(403);
        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(403);
        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(403);
        $this->postJson(self::PREFIX . '/currency-rates', [])->assertStatus(403);
    }

    /** @test */
    public function admin_with_only_view_permission_cannot_mutate_currencies(): void
    {
        $currency = $this->seedCurrencyData()['USD'];

        $user = $this->createUserWithPermissions(['view-currencies'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(200);

        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(403);
        $this->putJson(self::PREFIX . "/currencies/{$currency->id}", $this->currencyPayload())->assertStatus(403);
        $this->deleteJson(self::PREFIX . "/currencies/{$currency->id}")->assertStatus(403);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-base")->assertStatus(403);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-catalog")->assertStatus(403);
    }

    /** @test */
    public function admin_with_only_view_permission_cannot_mutate_exchange_rates(): void
    {
        $kwd = $this->seedCurrencyData()['KWD'];
        $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());
        $rate = \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->first();

        $user = $this->createUserWithPermissions(['view-exchange-rates'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(200);

        $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id))->assertStatus(403);
        $this->putJson(self::PREFIX . "/currency-rates/{$rate->id}", ['exchange_rate' => '1'])->assertStatus(403);
        $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}")->assertStatus(403);
    }

    /** @test */
    public function admin_with_update_exchange_rate_permission_cannot_delete_rates(): void
    {
        $kwd = $this->seedCurrencyData()['KWD'];
        $rate = \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->first();

        $user = $this->createUserWithPermissions(['update-exchange-rate'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->putJson(self::PREFIX . "/currency-rates/{$rate->id}", ['exchange_rate' => '1.0000000000'])->assertStatus(200);

        $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}")->assertStatus(403);
    }

    /** @test */
    public function admin_with_delete_exchange_rate_permission_can_delete_rates(): void
    {
        $kwd = $this->seedCurrencyData()['KWD'];
        $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());
        $rate = \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->first();

        $user = $this->createUserWithPermissions(['delete-exchange-rate'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('currency_rates', ['id' => $rate->id]);
    }

    /** @test */
    public function admin_with_all_currency_permissions_can_run_full_crud(): void
    {
        $currencies = $this->seedCurrencyData();
        $currency = $currencies['USD'];
        $kwd = $currencies['KWD'];
        $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());
        $rate = \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->first();

        $user = $this->createUserWithPermissions(self::PERMISSION_VALUES, 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(200);
        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(200);

        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(200);
        $this->putJson(self::PREFIX . "/currencies/{$currency->id}", $this->currencyPayload([
            'code' => 'USD',
            'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
            'symbol' => ['en' => 'US$', 'ar' => 'دولار'],
            'country_name' => ['en' => 'United States', 'ar' => 'الولايات المتحدة'],
        ]))->assertStatus(200);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-base")->assertStatus(200);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-catalog")->assertStatus(200);

        $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id))->assertStatus(200);
        $this->putJson(self::PREFIX . "/currency-rates/{$rate->id}", ['exchange_rate' => '1.0000000000'])->assertStatus(200);
        $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}")->assertStatus(200);
    }

    /** @test */
    public function super_admin_can_access_all_currency_endpoints(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = $this->createCustomer();
        $user->assignRole(RoleEnum::SUPER_ADMIN);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        \Laravel\Sanctum\Sanctum::actingAs($user);

        $currencies = $this->seedCurrencyData();
        $currency = $currencies['USD'];
        $kwd = $currencies['KWD'];
        $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());
        $rate = \App\Models\CurrencyRate::query()->where('currency_id', $kwd->id)->first();

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(200);
        $this->getJson(self::PREFIX . "/currencies/{$currency->id}")->assertStatus(200);
        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(200);
        $this->putJson(self::PREFIX . "/currencies/{$currency->id}", $this->currencyPayload([
            'code' => 'USD',
            'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
            'symbol' => ['en' => 'US$', 'ar' => 'دولار'],
            'country_name' => ['en' => 'United States', 'ar' => 'الولايات المتحدة'],
        ]))->assertStatus(200);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-base")->assertStatus(200);
        $this->postJson(self::PREFIX . "/currencies/{$currency->id}/set-catalog")->assertStatus(200);

        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(200);
        $this->getJson(self::PREFIX . "/currency-rates/{$rate->id}")->assertStatus(200);
        $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id))->assertStatus(200);
        $this->putJson(self::PREFIX . "/currency-rates/{$rate->id}", ['exchange_rate' => '1.0000000000'])->assertStatus(200);
        $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}")->assertStatus(200);
    }

    /** @test */
    public function controllers_use_permission_enum_constants_not_raw_strings(): void
    {
        $controllers = [
            \Marvel\Http\Controllers\CurrencyController::class,
            \Marvel\Http\Controllers\CurrencyRateController::class,
        ];

        foreach ($controllers as $controller) {
            $reflection = new \ReflectionClass($controller);
            $source = file_get_contents($reflection->getFileName());

            $this->assertStringContainsString('Permission::', $source);
            $this->assertDoesNotMatchRegularExpression(
                "/middleware\('permission:'\s*\.\s*'[^']+'/",
                $source,
                "{$controller} must not hardcode raw permission strings in middleware"
            );
        }
    }
}
