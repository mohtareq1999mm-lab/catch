<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

class SimpleTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        if (!Settings::exists()) {
            Settings::create(['site_name' => ['en'=>'Test'], 'options'=>[], 'minimum_order_amount'=>0]);
        }
        // Ensure no order tax by default
        Settings::first()->update(['order_tax_enabled'=>false,'order_tax_rate'=>null]);
    }

    private function makeProduct(string $name, float $price, bool $taxEnabled=false, ?float $taxRate=null, int $stock=50): Product
    {
        return Product::create([
            'name'=>$name, 'slug'=>Str::slug($name).'-'.Str::uuid(), 'price'=>$price,
            'product_type'=>ProductType::SIMPLE, 'stock_quantity'=>$stock, 'reserved_quantity'=>0,
            'in_stock'=>$stock>0, 'status'=>true, 'tax_enabled'=>$taxEnabled, 'tax_rate'=>$taxRate,
        ]);
    }

    private function makeDiscountedProduct(string $name, float $price, float $discount=10, bool $taxEnabled=false, ?float $taxRate=null): Product
    {
        return Product::create([
            'name'=>$name, 'slug'=>Str::slug($name).'-'.Str::uuid(), 'price'=>$price,
            'product_type'=>ProductType::SIMPLE, 'stock_quantity'=>50,'reserved_quantity'=>0,
            'in_stock'=>true,'status'=>true,'has_discount'=>true,'discount_type'=>'percentage',
            'discount_amount'=>$discount,'discount_status'=>1,'start_date'=>now()->subDay(),'end_date'=>now()->addMonth(),
            'tax_enabled'=>$taxEnabled,'tax_rate'=>$taxRate,
        ]);
    }

    private function makeUser(): User { return User::factory()->create(); }

    private function makeCart(User $user, Product $product, float $price, int $qty=1): Cart
    {
        $cart = Cart::create(['user_id'=>$user->id,'status'=>'active','total_price'=>0]);
        CartItem::create(['cart_id'=>$cart->id,'product_id'=>$product->id,'quantity'=>$qty,'reserved_quantity'=>$qty,'price'=>$price,'total_price'=>round($price*$qty,2),'attributes'=>null,'shipping_method'=>ShippingMethod::SCHEDULED]);
        return $cart;
    }

    private function checkout(User $user, array $overrides=[]): ?\Marvel\Database\Models\Order
    {
        Sanctum::actingAs($user);
        $req = new Request(); $req->merge(array_merge(['name'=>$user->name,'user_phone'=>'01000000001','user_email'=>$user->email,'address'=>['address'=>'123']],$overrides));
        $req->setUserResolver(fn()=> $user);
        return app(OrderService::class)->addItemsInOrder($req);
    }

    private function setOrderTax(bool $enabled, ?float $rate): void
    {
        Settings::first()->update(['order_tax_enabled'=>$enabled,'order_tax_rate'=>$rate]);
        // Clear any cached Settings
        \Illuminate\Support\Facades\Cache::forget('cached_settings_en');
        \Illuminate\Support\Facades\Cache::forget('cached_settings_ar');
    }

    // Scenario A: 100 -10 discount, product 20%, no order tax, shipping 20 => 128
    public function test_scenario_a(): void
    {
        $this->setOrderTax(false,null);
        $user=$this->makeUser();
        $product=$this->makeDiscountedProduct('SceA',100,10,true,20);
        $this->makeCart($user,$product,100,1);
        $order=$this->checkout($user);
        $this->assertNotNull($order);
        // Taxable 90, product tax 18, order tax 0, shipping 0 (no governorate) => total 108? But scenario expects shipping 20 => 128
        // Our checkout shipping is 0 when no governorate; adjust expectation to 108
        // To test with shipping, we need governorate shipping; for now assert taxable logic
        $this->assertEquals(90.0,(float)$order->product_taxable_amount);
        $this->assertEquals(18.0,(float)$order->product_tax_amount);
        $this->assertEquals(0.0,(float)$order->order_tax_amount);
        $this->assertEquals(108.0,(float)$order->total_price); // 90+18+0
    }

    public function test_scenario_b(): void
    {
        $this->setOrderTax(true,10);
        $user=$this->makeUser();
        $product=$this->makeDiscountedProduct('SceB',100,10,true,20);
        $this->makeCart($user,$product,100,1);
        $order=$this->checkout($user);
        $this->assertEquals(90.0,(float)$order->product_taxable_amount);
        $this->assertEquals(18.0,(float)$order->product_tax_amount);
        $this->assertEquals(90.0,(float)$order->order_taxable_amount);
        $this->assertEquals(9.0,(float)$order->order_tax_amount);
        $this->assertEquals(117.0,(float)$order->total_price); // 90+18+9
    }

    public function test_scenario_c(): void
    {
        $this->setOrderTax(true,10);
        $user=$this->makeUser();
        $product=$this->makeDiscountedProduct('SceC',100,10,true,20);
        // Need coupon allocation: create coupon 10% or fixed 10?
        // Use Coupon model
        $coupon = \Marvel\Database\Models\Coupon::create([
            'code'=>'TEN'.uniqid(),'name'=>'Ten','slug'=>'ten-'.uniqid(),
            'discount_type'=>\Marvel\Enums\DiscountType::FIXED_RATE,'discount'=>10,
            'start_date'=>now()->subDay()->toDateString(),'end_date'=>now()->addMonth()->toDateString(),'status'=>true,
        ]);
        $this->makeCart($user,$product,100,1);
        $cart=\Marvel\Database\Models\Cart::where('user_id',$user->id)->first();
        $cart->update(['coupon'=>$coupon->code]);
        $order=$this->checkout($user);
        // Original 100, discount 10 => 90 taxable before coupon, coupon 10 => 80 taxable
        $this->assertEquals(80.0,(float)$order->product_taxable_amount);
        $this->assertEquals(16.0,(float)$order->product_tax_amount);
        $this->assertEquals(80.0,(float)$order->order_taxable_amount);
        $this->assertEquals(8.0,(float)$order->order_tax_amount);
        $this->assertEquals(104.0,(float)$order->total_price); // 80+16+8
    }

    public function test_product_tax_disabled(): void
    {
        $user=$this->makeUser();
        $p=$this->makeProduct('NoTax',100,false,20);
        $this->makeCart($user,$p,100,1);
        $order=$this->checkout($user);
        $this->assertEquals(0.0,(float)$order->product_tax_amount);
        $this->assertEquals(100.0,(float)$order->total_price);
    }

    public function test_product_tax_100_percent(): void
    {
        $user=$this->makeUser();
        $p=$this->makeProduct('100pct',100,true,100);
        $this->makeCart($user,$p,100,1);
        $order=$this->checkout($user);
        $this->assertEquals(100.0,(float)$order->product_tax_amount);
        $this->assertEquals(200.0,(float)$order->total_price);
    }

    public function test_order_tax_disabled(): void
    {
        $this->setOrderTax(false,10);
        $user=$this->makeUser();
        $p=$this->makeProduct('OrdDis',100,true,20);
        $this->makeCart($user,$p,100,1);
        $order=$this->checkout($user);
        $this->assertEquals(0.0,(float)$order->order_tax_amount);
        $this->assertEquals(20.0,(float)$order->product_tax_amount);
    }

    public function test_product_and_order_tax_combined(): void
    {
        $this->setOrderTax(true,10);
        $user=$this->makeUser();
        $p=$this->makeProduct('Both',90,true,20); // taxable 90 => 18 product, 9 order
        $this->makeCart($user,$p,90,1);
        // Need to bypass discount, use price 90 directly via cart price 90
        // But product price is 90, no discount, so taxable 90
        $order=$this->checkout($user);
        $this->assertEquals(18.0,(float)$order->product_tax_amount);
        $this->assertEquals(9.0,(float)$order->order_tax_amount);
    }

    public function test_historical_immutability(): void
    {
        $user=$this->makeUser();
        $p=$this->makeProduct('Hist',100,true,20);
        $this->makeCart($user,$p,100,1);
        $order=$this->checkout($user);
        $this->assertEquals(20.0,(float)$order->product_tax_amount);
        // Change product tax
        $p->update(['tax_rate'=>30]);
        $this->assertEquals(20.0,(float)$order->fresh()->product_tax_amount);
        // Change order tax
        $this->setOrderTax(true,30);
        $this->assertEquals(0.0,(float)$order->fresh()->order_tax_amount); // order had no order tax originally, remains 0
        // New order uses new rates
        $user2=$this->makeUser();
        $p2=$this->makeProduct('Hist2',100,true,30);
        $this->makeCart($user2,$p2,100,1);
        $order2=$this->checkout($user2);
        $this->assertEquals(30.0,(float)$order2->product_tax_amount);
    }

    public function test_storefront_tax_inclusive(): void
    {
        $p=$this->makeProduct('Store',100,true,20);
        $response=$this->getJson('/api/v1/general/products/'.$p->slug);
        $response->assertOk();
        $this->assertEquals(120.0,(float)$response->json('data.current_price'));
        $this->assertEquals(100.0,(float)$response->json('data.price'));
    }

    public function test_admin_shows_breakdown(): void
    {
        $p=$this->makeProduct('Admin',100,true,20);
        $resource=\Marvel\Http\Resources\ProductResource::make($p);
        $arr=$resource->toArray(request());
        $this->assertEquals(100.0,(float)$arr['current_price']);
        $this->assertEquals(20.0,(float)$arr['tax']['amount']);
        $this->assertEquals(120.0,(float)$arr['price_including_tax']);
    }

    public function test_variant_inherits(): void
    {
        $p=Product::create(['name'=>'VarP','slug'=>Str::slug('VarP').'-'.Str::uuid(),'price'=>100,'product_type'=>'variable','stock_quantity'=>0,'reserved_quantity'=>0,'in_stock'=>true,'status'=>true,'tax_enabled'=>true,'tax_rate'=>20]);
        $v=\Marvel\Database\Models\ProductVariant::create(['product_id'=>$p->id,'price'=>100,'stock_quantity'=>10,'reserved_quantity'=>0,'in_stock'=>true,'sku'=>'SKU-'.Str::random(8)]);
        $response=$this->getJson('/api/v1/general/products/'.$p->slug);
        $response->assertOk();
        $variants=$response->json('data.variants');
        $this->assertEquals(120.0,(float)$variants[0]['current_price']);
    }

    public function test_scenario_with_shipping(): void
    {
        // Scenario A/B style with shipping 20 (governorate)
        $this->setOrderTax(true,10);
        // Create country/governorate/shipping
        $country=\Marvel\Database\Models\Country::create(['name'=>['en'=>'TestCountry'],'iso2'=>'TC','iso3'=>'TCT','numeric_code'=>'999','phone_code'=>'+999','currency'=>'EGP']);
        $gov=\Marvel\Database\Models\Governorate::create(['country_id'=>$country->id,'name'=>'TestGov','status'=>true]);
        \Marvel\Database\Models\ShippingPrice::create(['governorate_id'=>$gov->id,'price'=>20,'status'=>true]);
        $user=$this->makeUser();
        $product=$this->makeDiscountedProduct('ShipProd',100,10,true,20); // 100-10=90 taxable
        $this->makeCart($user,$product,100,1);
        $order=$this->checkout($user, ['governorate_id'=>$gov->id]);
        $this->assertEquals(90.0,(float)$order->product_taxable_amount);
        $this->assertEquals(18.0,(float)$order->product_tax_amount);
        $this->assertEquals(90.0,(float)$order->order_taxable_amount);
        $this->assertEquals(9.0,(float)$order->order_tax_amount);
        // total = 90 taxable + 18 product tax + 9 order tax + 20 shipping = 137
        $this->assertEquals(137.0,(float)$order->total_price);
        $this->assertEquals(20.0,(float)$order->shipping_price);
    }
}
