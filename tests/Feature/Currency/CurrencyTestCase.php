<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class CurrencyTestCase extends TestCase
{
    use RefreshDatabase;

    protected const GUARD = 'api';
    protected const PREFIX = '/api/v1';
    protected const GENERAL_PREFIX = '/api/v1/general';

    protected const CURRENCY_PERMISSIONS = [
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

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        // CurrencyService is registered as a singleton and memoizes base/catalog
        // codes and rates. Forget the resolved instance and clear the array cache
        // so every test starts from a clean, isolated state.
        $this->app->forgetInstance(CurrencyService::class);
        Cache::flush();
    }

    protected function createSettings(): Settings
    {
        $settings = Settings::query()->first();

        if (!$settings) {
            $settings = Settings::create([
                'site_name' => ['en' => 'Test Site', 'ar' => 'موقع تجريبي'],
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }

        $options = $settings->options ?? [];
        $options['currency'] ??= 'USD';
        $options['base_currency_code'] ??= 'USD';
        $options['catalog_currency_code'] ??= 'USD';
        // The currency test suite exercises the enabled resolution path
        // (preference > guest cookie > catalog) unless a test explicitly
        // disables currency selection.
        $options['currency_selection_enabled'] ??= true;
        $settings->options = $options;
        $settings->save();

        return $settings;
    }

    protected function createCurrency(string $code = 'USD', array $overrides = []): Currency
    {
        return Currency::create(array_merge([
            'code' => $code,
            'name' => ['en' => $code . ' Currency', 'ar' => $code],
            'symbol' => ['en' => '$', 'ar' => '$'],
            'country_name' => ['en' => 'Country', 'ar' => 'دولة'],
            'numeric_code' => '840',
            'decimal_places' => 2,
            'icon' => strtolower($code),
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    protected function createRate(Currency $currency, string $rate = '1.0000000000', ?string $date = null): CurrencyRate
    {
        return CurrencyRate::create([
            'currency_id' => $currency->id,
            'exchange_rate' => $rate,
            'effective_date' => $date ?? now()->toDateString(),
        ]);
    }

    /**
     * Seed settings plus USD (base/catalog, rate 1.0), KWD (rate 0.221) and
     * SAR (rate 3.75). Returns the Currency models keyed by code.
     *
     * @return array{USD: Currency, KWD: Currency, SAR: Currency}
     */
    protected function seedCurrencyData(): array
    {
        $this->createSettings();

        $usd = $this->createCurrency('USD');
        $this->createRate($usd, '1.0000000000');

        $kwd = $this->createCurrency('KWD', [
            'name' => ['en' => 'Kuwaiti Dinar', 'ar' => 'دينار كويتي'],
            'symbol' => ['en' => 'د.ك', 'ar' => 'د.ك'],
            'country_name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
            'numeric_code' => '414',
            'decimal_places' => 3,
        ]);
        $this->createRate($kwd, '0.2210000000');

        $sar = $this->createCurrency('SAR', [
            'name' => ['en' => 'Saudi Riyal', 'ar' => 'ريال سعودي'],
            'numeric_code' => '682',
        ]);
        $this->createRate($sar, '3.7500000000');

        return ['USD' => $usd, 'KWD' => $kwd, 'SAR' => $sar];
    }

    protected function createCustomer(): User
    {
        $user = User::create([
            'name' => 'Test Customer',
            'email' => 'customer-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);

        return $user;
    }

    protected function createAuthenticatedCustomer(): User
    {
        $user = $this->createCustomer();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Authenticate the given user and store an effective-currency preference that
     * CurrencyService::getEffectiveCode() will resolve for the rest of the test.
     */
    protected function actAsWithCurrencyPreference(User $user, string $currencyCode): void
    {
        Sanctum::actingAs($user);
        app(UserCurrencyPreferenceService::class)->setUserPreference($user, $currencyCode);
        $this->app->forgetInstance(CurrencyService::class);
    }

    protected function createCustomerWithCurrencyPreference(string $currencyCode): User
    {
        $user = $this->createCustomer();
        $this->actAsWithCurrencyPreference($user, $currencyCode);

        return $user;
    }

    protected function createAdmin(): User
    {
        return $this->createUserWithPermissions(self::CURRENCY_PERMISSIONS, 'admin');
    }

    protected function createAuthenticatedAdmin(): User
    {
        $user = $this->createAdmin();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function createUserWithPermissions(array $permissions, string $type = 'user'): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN . '-' . uniqid(),
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Test Role']),
        ]);

        $role->givePermissionTo($permissions);

        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type,
        ]);

        $user->assignRole($role);

        return $user;
    }

    protected function currencyPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'EGP',
            'name' => ['en' => 'Egyptian Pound', 'ar' => 'جنيه مصري'],
            'symbol' => ['en' => 'E£', 'ar' => 'ج.م'],
            'country_name' => ['en' => 'Egypt', 'ar' => 'مصر'],
            'numeric_code' => '818',
            'decimal_places' => 2,
            'icon' => 'eg',
            'is_active' => true,
            'sort_order' => 7,
        ], $overrides);
    }

    protected function ratePayload(int $currencyId, array $overrides = []): array
    {
        return array_merge([
            'currency_id' => $currencyId,
            'exchange_rate' => '2.5000000000',
            'effective_date' => now()->toDateString(),
        ], $overrides);
    }
}
