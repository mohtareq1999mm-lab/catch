<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;

class LocationSeeder extends Seeder
{
    public function run()
    {
        $country = Country::firstOrCreate(
            ['phone_code' => '20'],
            ['name' => ['en' => 'Egypt'], 'status' => true]
        );

        $governorates = [
            ['name' => ['en' => 'Cairo'], 'cities' => ['Nasr City', 'Maadi', 'Heliopolis', 'Downtown', 'Zamalek', 'New Cairo', 'Shorouk'], 'price' => 50.00, 'estimated_days' => 2, 'free_shipping_over' => 500.00, 'fast' => true],
            ['name' => ['en' => 'Giza'], 'cities' => ['Dokki', 'Mohandessin', 'Haram', '6 October', 'Sheikh Zayed'], 'price' => 60.00, 'estimated_days' => 3, 'free_shipping_over' => 600.00, 'fast' => true],
            ['name' => ['en' => 'Alexandria'], 'cities' => ['Smouha', 'Sidi Bishr', 'Stanley', 'Raml Station', 'Miami'], 'price' => 65.00, 'estimated_days' => 3, 'free_shipping_over' => 700.00, 'fast' => false],
            ['name' => ['en' => 'Sharqia'], 'cities' => ['Zagazig', 'Belbeis', 'Abu Kebir', 'Hehya'], 'price' => 70.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Dakahlia'], 'cities' => ['Mansoura', 'Mit Ghamr', 'Dikirnis', 'Talkha'], 'price' => 70.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Beheira'], 'cities' => ['Damanhur', 'Kafr El Dawwar', 'Rashid', 'Edko'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Gharbia'], 'cities' => ['Tanta', 'El Mahalla', 'Kafr El Zayat', 'Samanoud'], 'price' => 70.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Monufia'], 'cities' => ['Shibin El Kom', 'Menouf', 'Ashmoun', 'Quesna'], 'price' => 70.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Qalyubia'], 'cities' => ['Banha', 'Qalyub', 'Shubra El Kheima', 'Tukh'], 'price' => 55.00, 'estimated_days' => 3, 'free_shipping_over' => 600.00, 'fast' => true],
            ['name' => ['en' => 'Port Said'], 'cities' => ['Port Said Downtown', 'Zohour', 'Sharq'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Suez'], 'cities' => ['Suez Downtown', 'Arbaeen', 'Ganayen'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Ismailia'], 'cities' => ['Ismailia Downtown', 'Fayed', 'Tal El Kebir'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Minya'], 'cities' => ['Minya Downtown', 'Mallawi', 'Samalut', 'Maghagha'], 'price' => 80.00, 'estimated_days' => 5, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Asyut'], 'cities' => ['Asyut Downtown', 'Abnoub', 'Abu Tig', 'Dairut'], 'price' => 85.00, 'estimated_days' => 5, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Sohag'], 'cities' => ['Sohag Downtown', 'Akhmim', 'Gerga', 'Tahta'], 'price' => 85.00, 'estimated_days' => 5, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Qena'], 'cities' => ['Qena Downtown', 'Nag Hammadi', 'Deshna', 'Qus'], 'price' => 90.00, 'estimated_days' => 5, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Luxor'], 'cities' => ['Luxor Downtown', 'Karnak', 'Armant'], 'price' => 90.00, 'estimated_days' => 5, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Aswan'], 'cities' => ['Aswan Downtown', 'Kom Ombo', 'Edfu', 'Daraw'], 'price' => 95.00, 'estimated_days' => 6, 'free_shipping_over' => 1200.00, 'fast' => false],
            ['name' => ['en' => 'Red Sea'], 'cities' => ['Hurghada', 'Safaga', 'Quseir', 'Marsa Alam'], 'price' => 85.00, 'estimated_days' => 4, 'free_shipping_over' => 1000.00, 'fast' => false],
            ['name' => ['en' => 'Damietta'], 'cities' => ['Damietta Downtown', 'Ras El Bar', 'Faraskur'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Kafr El Sheikh'], 'cities' => ['Kafr El Sheikh Downtown', 'Desouk', 'Bila'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Fayoum'], 'cities' => ['Fayoum Downtown', 'Sinnuris', 'Ibsheway'], 'price' => 70.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'Beni Suef'], 'cities' => ['Beni Suef Downtown', 'El Wasta', 'Nasser'], 'price' => 75.00, 'estimated_days' => 4, 'free_shipping_over' => 800.00, 'fast' => false],
            ['name' => ['en' => 'New Valley'], 'cities' => ['Kharga', 'Dakhla', 'Farafra'], 'price' => 100.00, 'estimated_days' => 6, 'free_shipping_over' => 1500.00, 'fast' => false],
            ['name' => ['en' => 'Matrouh'], 'cities' => ['Marsa Matrouh', 'Siwa', 'El Alamein'], 'price' => 95.00, 'estimated_days' => 5, 'free_shipping_over' => 1200.00, 'fast' => false],
            ['name' => ['en' => 'North Sinai'], 'cities' => ['Arish', 'Sheikh Zuweid', 'Rafah'], 'price' => 100.00, 'estimated_days' => 5, 'free_shipping_over' => 1200.00, 'fast' => false],
            ['name' => ['en' => 'South Sinai'], 'cities' => ['Sharm El Sheikh', 'Dahab', 'Saint Catherine', 'Nuweiba'], 'price' => 95.00, 'estimated_days' => 5, 'free_shipping_over' => 1200.00, 'fast' => false],
        ];

        foreach ($governorates as $gdata) {
            $gov = Governorate::firstOrCreate(
                ['country_id' => $country->id, 'name' => $gdata['name']],
                ['status' => true, 'is_fast_shipping_enabled' => $gdata['fast']]
            );

            foreach ($gdata['cities'] as $cityName) {
                City::firstOrCreate([
                    'governorate_id' => $gov->id,
                    'name' => ['en' => $cityName, 'ar' => $cityName],
                ]);
            }

            ShippingPrice::updateOrCreate(
                ['governorate_id' => $gov->id],
                [
                    'price' => $gdata['price'],
                    'estimated_days' => $gdata['estimated_days'],
                    'free_shipping_over' => $gdata['free_shipping_over'],
                    'status' => true,
                ]
            );
        }
    }
}
