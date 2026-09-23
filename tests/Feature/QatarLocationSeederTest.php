<?php

namespace Tests\Feature;

use Database\Seeders\QatarLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;
use Tests\TestCase;

/**
 * Qatar master geography seeding: identity, idempotency, format, no shipping.
 */
class QatarLocationSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeederTwice(): void
    {
        $this->seed(QatarLocationSeeder::class);
        $this->seed(QatarLocationSeeder::class);
    }

    public function test_qatar_resolved_by_canonical_identifier(): void
    {
        $this->seed(QatarLocationSeeder::class);

        $qatar = Country::query()->where('phone_code', '974')->first();
        $this->assertNotNull($qatar);
        $this->assertSame('Qatar', $qatar->getTranslation('name', 'en'));
        $this->assertSame('قطر', $qatar->getTranslation('name', 'ar'));
        $this->assertTrue((bool) $qatar->status);
    }

    public function test_exactly_eight_active_municipalities_under_qatar(): void
    {
        $this->runSeederTwice();

        $qatar = Country::query()->where('phone_code', '974')->firstOrFail();
        $govs = Governorate::query()->where('country_id', $qatar->id)->get();

        $this->assertCount(8, $govs);
        $this->assertCount(1, Country::query()->where('phone_code', '974')->get());

        $expected = [
            ['Doha', 'الدوحة'],
            ['Al Rayyan', 'الريان'],
            ['Al Wakra', 'الوكرة'],
            ['Al Shahaniya', 'الشحانية'],
            ['Al Daayen', 'الظعاين'],
            ['Umm Salal', 'أم صلال'],
            ['Al Khor and Al Thakhira', 'الخور والذخيرة'],
            ['Al Shamal', 'الشمال'],
        ];

        foreach ($expected as [$en, $ar]) {
            $row = $govs->first(fn (Governorate $g) => $g->getTranslation('name', 'en') === $en);
            $this->assertNotNull($row, "missing municipality {$en}");
            $this->assertSame($ar, $row->getTranslation('name', 'ar'));
            $this->assertTrue((bool) $row->status);
            $this->assertFalse((bool) $row->is_fast_shipping_enabled);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(QatarLocationSeeder::class);
        $countriesOnce = Country::count();
        $govsOnce = Governorate::count();

        $this->seed(QatarLocationSeeder::class);

        $this->assertSame($countriesOnce, Country::count());
        $this->assertSame($govsOnce, Governorate::count());
    }

    public function test_no_shipping_configuration_created(): void
    {
        $this->runSeederTwice();

        $qatar = Country::query()->where('phone_code', '974')->firstOrFail();
        $ids = Governorate::query()->where('country_id', $qatar->id)->pluck('id');

        $this->assertSame(0, ShippingPrice::query()->whereIn('governorate_id', $ids)->count());
    }

    public function test_existing_data_untouched_and_api_relationship(): void
    {
        $egypt = Country::create(['name' => ['en' => 'Egypt'], 'phone_code' => '20', 'status' => true]);
        $egGov = Governorate::create(['country_id' => $egypt->id, 'name' => ['en' => 'Cairo'], 'status' => true]);

        $this->runSeederTwice();

        $this->assertTrue(Country::query()->whereKey($egypt->id)->exists());
        $this->assertSame('Cairo', Governorate::query()->whereKey($egGov->id)->first()->getTranslation('name', 'en'));

        // Existing country-filtering relationship mechanism serves Qatar rows.
        $qatar = Country::query()->where('phone_code', '974')->firstOrFail();
        $this->assertCount(8, $qatar->governorates()->where('status', true)->get());
        $this->assertCount(8, app(\Marvel\Database\Repositories\GovernorateRepository::class)->allActive($qatar->id));
    }
}
