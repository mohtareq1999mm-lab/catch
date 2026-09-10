<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Illuminate\Support\Str;

class DebugPickupTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;
    public function test_debug(): void
    {
        app()->setLocale('en');
        $this->createAllTestTables();
        dump('orders has currency_code col: ' . var_export(Schema::hasColumn('orders','currency_code'), true));
        dump('payment default_currency: ' . config('payment.default_currency'));
        $admin = User::create(['name'=>'A','email'=>'a-p@test.com','password'=>bcrypt('p'),'email_verified_at'=>now()]);
        $customer = User::create(['name'=>'C','email'=>'c-p@test.com','password'=>bcrypt('p'),'email_verified_at'=>now()]);
        $country = Country::create(['name'=>'Egypt','status'=>true]);
        $gov = Governorate::create(['country_id'=>$country->id,'name'=>'Cairo','status'=>true]);
        ShippingPrice::create(['governorate_id'=>$gov->id,'price'=>30,'free_shipping_over'=>500,'status'=>true]);
        $product = Product::create(['name'=>'P','slug'=>'p-'.Str::random(6),'price'=>100,'status'=>true,'in_stock'=>true,'stock_quantity'=>50,'sold_quantity'=>0,'reserved_quantity'=>0]);
        $loc = PickupLocation::create(['store_name'=>'Store','address'=>'Addr','phone'=>'123','status'=>true]);
        Sanctum::actingAs($customer);
        $cart = Cart::create(['user_id'=>$customer->id,'status'=>'active','total_price'=>100]);
        CartItem::create(['cart_id'=>$cart->id,'product_id'=>$product->id,'quantity'=>1,'price'=>100,'total_price'=>100]);

        $response = $this->postJson('/api/v1/general/checkout', [
            'name'=>'Test','user_phone'=>'123','user_email'=>'test@test.com','address'=>['street'=>'Test'],
            'fulfillment_type'=>'pickup','payment_method'=>'online','pickup_location_id'=>$loc->id,
        ]);
        dump('status: ' . $response->status());
        dump('body: ' . json_encode($response->json()));
        $order = Order::where('user_id',$customer->id)->latest()->first();
        if ($order) {
            dump('order currency_code: ' . var_export($order->currency_code, true));
            dump('order base_currency_code: ' . var_export($order->base_currency_code, true));
        }
    }
}
