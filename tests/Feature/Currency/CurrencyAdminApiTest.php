<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Models\Currency;
use Illuminate\Support\Str;

class CurrencyAdminApiTest extends CurrencyTestCase
{
    /** @test */
    public function admin_can_create_currency(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload());

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_CREATED_SUCCESSFULLY'));
        $response->assertJsonPath('data.code', 'EGP');
        $response->assertJsonPath('data.is_base', false);

        $this->assertDatabaseHas('currencies', ['code' => 'EGP']);
    }

    /** @test */
    public function admin_can_list_all_currencies(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currencies');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(3, $response->json('data.total'));
        $codes = collect($response->json('data.data'))->pluck('code')->all();
        $this->assertContains('USD', $codes);
        $this->assertContains('KWD', $codes);
        $this->assertContains('SAR', $codes);
    }

    /** @test */
    public function admin_can_view_single_currency(): void
    {
        $this->createAuthenticatedAdmin();
        $usd = $this->seedCurrencyData()['USD'];

        $response = $this->getJson(self::PREFIX . "/currencies/{$usd->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $usd->id);
        $response->assertJsonPath('data.code', 'USD');
        $response->assertJsonPath('data.is_base', true);
        $response->assertJsonPath('data.is_catalog', true);
    }

    /** @test */
    public function admin_can_update_currency(): void
    {
        $this->createAuthenticatedAdmin();
        $usd = $this->seedCurrencyData()['USD'];

        $response = $this->putJson(self::PREFIX . "/currencies/{$usd->id}", [
            'name' => ['en' => 'United States Dollar', 'ar' => 'دولار أمريكي'],
            'icon' => 'dollar',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_UPDATED_SUCCESSFULLY'));
        $response->assertJsonPath('data.icon', 'dollar');

        $this->assertDatabaseHas('currencies', ['icon' => 'dollar']);
    }

    /** @test */
    public function admin_can_delete_currency_without_rates(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();
        $eur = $this->createCurrency('EUR');

        $response = $this->deleteJson(self::PREFIX . "/currencies/{$eur->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_DELETED_SUCCESSFULLY'));

        $this->assertSoftDeleted('currencies', ['id' => $eur->id]);
    }

    /** @test */
    public function cannot_delete_currency_that_has_exchange_rates(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->deleteJson(self::PREFIX . "/currencies/{$kwd->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('message.ERROR.CANNOT_DELETE_CURRENCY_IN_USE'));
    }

    /** @test */
    public function cannot_delete_the_base_currency(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $settings = app(\Marvel\Database\Models\Settings::class)::first();
        $options = $settings->options ?? [];
        $options['base_currency_code'] = 'EUR';
        $options['currency'] = 'EUR';
        $settings->options = $options;
        $settings->save();

        $this->app->forgetInstance(\App\Services\Currency\CurrencyService::class);

        $eur = $this->createCurrency('EUR');

        $response = $this->deleteJson(self::PREFIX . "/currencies/{$eur->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('message', __('message.ERROR.CANNOT_DELETE_BASE_CURRENCY'));
    }

    /** @test */
    public function missing_currency_returns_404(): void
    {
        $this->createAuthenticatedAdmin();

        $this->getJson(self::PREFIX . '/currencies/999999')->assertStatus(404);
        $this->putJson(self::PREFIX . '/currencies/999999', $this->currencyPayload())->assertStatus(404);
        $this->deleteJson(self::PREFIX . '/currencies/999999')->assertStatus(404);
    }

    /** @test */
    public function non_numeric_id_returns_404_not_500(): void
    {
        $this->createAuthenticatedAdmin();

        $this->getJson(self::PREFIX . '/currencies/abc')->assertStatus(404);
        $this->putJson(self::PREFIX . '/currencies/abc', $this->currencyPayload())->assertStatus(404);
        $this->deleteJson(self::PREFIX . '/currencies/abc')->assertStatus(404);
    }

    /** @test */
    public function unauthenticated_user_is_rejected(): void
    {
        $response = $this->getJson(self::PREFIX . '/currencies');

        $response->assertStatus(401);
    }

    /** @test */
    public function customer_without_permission_is_forbidden(): void
    {
        $this->createAuthenticatedCustomer();

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(403);
        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(403);
    }

    /** @test */
    public function admin_without_create_permission_is_forbidden(): void
    {
        $user = $this->createUserWithPermissions(['view-currencies'], 'admin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/currencies')->assertStatus(200);
        $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload())->assertStatus(403);
    }

    /** @test */
    public function invalid_code_is_rejected(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload(['code' => 'US']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    /** @test */
    public function code_must_be_alphabetic(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload(['code' => 'US1']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    /** @test */
    public function duplicate_code_is_rejected_even_with_different_case(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload(['code' => 'usd']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
    }

    /** @test */
    public function missing_name_is_rejected(): void
    {
        $this->createAuthenticatedAdmin();

        $payload = $this->currencyPayload();
        unset($payload['name']);

        $response = $this->postJson(self::PREFIX . '/currencies', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    /** @test */
    public function invalid_decimal_places_is_rejected(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload(['decimal_places' => 9]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('decimal_places');
    }

    /** @test */
    public function index_limits_and_caps_pagination(): void
    {
        $this->createAuthenticatedAdmin();

        foreach (['AUD', 'CAD', 'JPY', 'CHF', 'CNY'] as $code) {
            $this->createCurrency($code);
        }

        $response = $this->getJson(self::PREFIX . '/currencies?limit=3');

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.per_page'));
        $this->assertSame(5, $response->json('data.total'));
    }

    /** @test */
    public function list_can_be_filtered_by_code(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currencies?code=USD');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('USD', $response->json('data.data.0.code'));
    }

    /** @test */
    public function list_can_be_filtered_by_search_code(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currencies?search=KW');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('KWD', $response->json('data.data.0.code'));
    }

    /** @test */
    public function list_search_matches_translated_name(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currencies?search=Dinar');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('KWD', $response->json('data.data.0.code'));
    }

    /** @test */
    public function list_can_be_filtered_by_is_active(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();
        $sar = Currency::query()->where('code', 'SAR')->first();
        $sar->update(['is_active' => false]);

        $response = $this->getJson(self::PREFIX . '/currencies?is_active=1');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(2, $response->json('data.total'));

        foreach ($response->json('data.data') as $item) {
            $this->assertTrue($item['is_active']);
        }

        $inactive = $this->getJson(self::PREFIX . '/currencies?is_active=0');

        $inactive->assertStatus(200);
        $this->assertSame(1, $inactive->json('data.total'));
        $this->assertFalse($inactive->json('data.data.0.is_active'));
    }

    /** @test */
    public function list_can_be_filtered_by_sort_order(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currencies?sort_order=0');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(3, $response->json('data.total'));
    }
}
