<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Models\Currency;
use App\Models\CurrencyRate;
use Illuminate\Database\QueryException;

class CurrencyRateTest extends CurrencyTestCase
{
    /** @test */
    public function admin_can_create_exchange_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => '0.2500000000',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_RATE_CREATED_SUCCESSFULLY'));
        $response->assertJsonPath('data.currency.code', 'KWD');
        $this->assertEquals(0.25, (float) $response->json('data.exchange_rate'));

        $this->assertDatabaseCount('currency_rates', 3);
    }

    /** @test */
    public function posting_a_rate_for_the_same_day_upserts_instead_of_duplicating(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => '0.2500000000',
        ]))->assertStatus(200);

        $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => '0.3000000000',
        ]))->assertStatus(200);

        $this->assertDatabaseCount('currency_rates', 3);

        $rate = CurrencyRate::query()
            ->where('currency_id', $kwd->id)
            ->whereDate('effective_date', now()->toDateString())
            ->first();

        $this->assertNotNull($rate);
        $this->assertEquals(0.3, (float) $rate->exchange_rate);
    }

    /** @test */
    public function admin_can_update_exchange_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $rate = $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());

        $response = $this->putJson(self::PREFIX . "/currency-rates/{$rate->id}", [
            'exchange_rate' => '0.4000000000',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_RATE_UPDATED_SUCCESSFULLY'));
        $this->assertEquals(0.4, (float) $response->json('data.exchange_rate'));

        $fresh = $rate->fresh();
        $this->assertEquals(0.4, (float) $fresh->exchange_rate);
    }

    /** @test */
    public function admin_can_delete_exchange_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $rate = $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());

        $response = $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.CURRENCY_RATE_DELETED_SUCCESSFULLY'));

        $this->assertDatabaseMissing('currency_rates', ['id' => $rate->id]);
    }

    /** @test */
    public function admin_cannot_delete_the_current_effective_rate(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];
        $rate = CurrencyRate::query()->where('currency_id', $kwd->id)->firstOrFail();

        $response = $this->deleteJson(self::PREFIX . "/currency-rates/{$rate->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('currency_rates', ['id' => $rate->id]);
    }

    /** @test */
    public function list_can_be_filtered_by_currency(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();
        $sar = $currencies['SAR'];

        $this->createRate($sar, '3.7000000000', now()->subDay()->toDateString());

        $response = $this->getJson(self::PREFIX . "/currency-rates?currency_id={$sar->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(2, $response->json('data.total'));

        foreach ($response->json('data.data') as $item) {
            $this->assertSame($sar->id, $item['currency']['id']);
        }
    }

    /** @test */
    public function list_can_be_filtered_by_effective_date(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();
        $sar = $currencies['SAR'];
        $this->createRate($sar, '3.7000000000', '2026-01-01');

        $response = $this->getJson(self::PREFIX . '/currency-rates?effective_date=2026-01-01');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('2026-01-01', $response->json('data.data.0.effective_date'));
    }

/** @test */
    public function list_can_be_filtered_by_code(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();
        $sar = Currency::query()->where('code', 'SAR')->first();
        $this->createRate($sar, '3.7000000000', now()->subDay()->toDateString());

        $response = $this->getJson(self::PREFIX . '/currency-rates?code=SAR');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(2, $response->json('data.total'));

        foreach ($response->json('data.data') as $item) {
            $this->assertSame('SAR', $item['currency']['code']);
        }
    }

    /** @test */
    public function list_can_be_filtered_by_date_range(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();
        $sar = Currency::query()->where('code', 'SAR')->first();
        $this->createRate($sar, '3.1000000000', '2026-01-01');
        $this->createRate($sar, '3.2000000000', '2026-02-01');

        $response = $this->getJson(self::PREFIX . '/currency-rates?date_from=2026-01-15&date_to=2026-02-15');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('2026-02-01', $response->json('data.data.0.effective_date'));
    }

    /** @test */
    public function list_code_filter_returns_empty_for_unknown_code(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->getJson(self::PREFIX . '/currency-rates?code=XXX');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total'));
    }

    /** @test */
    public function exchange_rate_must_be_positive(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => '0',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('exchange_rate');
    }

    /** @test */
    public function exchange_rate_must_be_numeric(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => 'abc',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('exchange_rate');
    }

    /** @test */
    public function currency_id_must_exist(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $response = $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload(999999));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('currency_id');
    }

    /** @test */
    public function effective_date_is_required(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $payload = $this->ratePayload($kwd->id);
        unset($payload['effective_date']);

        $response = $this->postJson(self::PREFIX . '/currency-rates', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('effective_date');
    }

    /** @test */
    public function missing_rate_returns_404(): void
    {
        $this->createAuthenticatedAdmin();
        $this->seedCurrencyData();

        $this->getJson(self::PREFIX . '/currency-rates/999999')->assertStatus(404);
        $this->putJson(self::PREFIX . '/currency-rates/999999', ['exchange_rate' => '1'])->assertStatus(404);
        $this->deleteJson(self::PREFIX . '/currency-rates/999999')->assertStatus(404);
    }

    /** @test */
    public function unauthenticated_user_is_rejected(): void
    {
        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(401);
    }

    /** @test */
    public function customer_without_permission_is_forbidden(): void
    {
        $this->createAuthenticatedCustomer();

        $this->getJson(self::PREFIX . '/currency-rates')->assertStatus(403);
    }

    /** @test */
    public function duplicate_rate_for_same_day_violates_unique_constraint(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $this->expectException(QueryException::class);

        CurrencyRate::create([
            'currency_id' => $kwd->id,
            'exchange_rate' => '0.5000000000',
            'effective_date' => now()->toDateString(),
        ]);
    }

    /** @test */
    public function exchange_rate_precision_is_preserved(): void
    {
        $this->createAuthenticatedAdmin();
        $kwd = $this->seedCurrencyData()['KWD'];

        $response = $this->postJson(self::PREFIX . '/currency-rates', $this->ratePayload($kwd->id, [
            'exchange_rate' => '0.1234567890',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(0.123456789, (float) $response->json('data.exchange_rate'));
    }
}
