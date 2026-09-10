<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Exceptions\CurrencyRateNotFoundException;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\DB;

class CurrencyConversionTest extends CurrencyTestCase
{
    private function conversion(): CurrencyConversionService
    {
        return app(CurrencyConversionService::class);
    }

    /** @test */
    public function same_currency_conversion_is_identity(): void
    {
        $this->seedCurrencyData();

        $result = $this->conversion()->convert(150.50, 'USD', 'USD');

        $this->assertSame(150.5, $result->amount);
        $this->assertSame(150.5, $result->convertedAmount);
        $this->assertSame('1', $result->rate);
        $this->assertSame('1', $result->sourceRate);
        $this->assertSame('1', $result->targetRate);
    }

    /** @test */
    public function identity_conversion_does_not_query_database(): void
    {
        $this->seedCurrencyData();

        DB::enableQueryLog();

        $result = $this->conversion()->convert(100, 'USD', 'USD');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(100.0, $result->convertedAmount);
        $this->assertSame(0, $queries);
    }

    /** @test */
    public function usd_to_kwd_uses_exchange_rate(): void
    {
        $this->seedCurrencyData();

        $result = $this->conversion()->convert(100, 'USD', 'KWD');

        $this->assertSame(22.1, $result->convertedAmount);
        $this->assertSame('0.221000', $result->rate);
        $this->assertSame('1', $result->sourceRate);
        $this->assertSame('0.221', $result->targetRate);
    }

    /** @test */
    public function kwd_to_usd_reverses_the_conversion(): void
    {
        $this->seedCurrencyData();

        $result = $this->conversion()->convert(22.1, 'KWD', 'USD');

        $this->assertSame(100.0, $result->convertedAmount);
        $this->assertSame('4.524886', $result->rate);
    }

    /** @test */
    public function cross_currency_conversion_works_sar_to_kwd(): void
    {
        $this->seedCurrencyData();

        $result = $this->conversion()->convert(100, 'SAR', 'KWD');

        $this->assertSame(5.89, $result->convertedAmount);
    }

    /** @test */
    public function historical_rate_is_used_for_a_past_date(): void
    {
        $currencies = $this->seedCurrencyData();
        $usd = $currencies['USD'];
        $kwd = $currencies['KWD'];
        $this->createRate($usd, '1.0000000000', now()->subDays(3)->toDateString());
        $this->createRate($kwd, '0.2000000000', now()->subDay()->toDateString());

        $result = $this->conversion()->convert(100, 'USD', 'KWD', now()->subDay()->toDateString());

        $this->assertSame(20.0, $result->convertedAmount);
        $this->assertSame(now()->subDay()->toDateString(), $result->effectiveDate);
    }

    /** @test */
    public function latest_rate_before_the_date_wins(): void
    {
        $currencies = $this->seedCurrencyData();
        $usd = $currencies['USD'];
        $kwd = $currencies['KWD'];
        $this->createRate($usd, '1.0000000000', now()->subDays(3)->toDateString());
        $this->createRate($kwd, '0.2000000000', now()->subDays(2)->toDateString());
        $this->createRate($kwd, '0.2100000000', now()->subDay()->toDateString());

        $result = $this->conversion()->convert(100, 'USD', 'KWD', now()->subDay()->toDateString());

        $this->assertSame(21.0, $result->convertedAmount);
    }

    /** @test */
    public function missing_target_rate_throws(): void
    {
        $this->seedCurrencyData();
        $this->createCurrency('EUR');

        $this->expectException(CurrencyRateNotFoundException::class);

        $this->conversion()->convert(100, 'USD', 'EUR');
    }

    /** @test */
    public function missing_source_rate_throws(): void
    {
        $this->seedCurrencyData();
        $this->createCurrency('EUR');

        $this->expectException(CurrencyRateNotFoundException::class);

        $this->conversion()->convert(100, 'EUR', 'USD');
    }

    /** @test */
    public function convert_price_rounds_to_two_decimals(): void
    {
        $this->seedCurrencyData();

        $price = app(CurrencyService::class)->convertPrice(99.999, 'USD', 'KWD');

        $this->assertSame(22.1, $price);
    }

    /** @test */
    public function bcmath_arithmetic_is_precise_for_large_amounts(): void
    {
        $this->seedCurrencyData();

        $result = $this->conversion()->convert('123456789.99', 'USD', 'KWD');

        $this->assertSame(27283950.59, $result->convertedAmount);
    }
}
