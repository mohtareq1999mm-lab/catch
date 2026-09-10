<?php

declare(strict_types=1);

namespace Tests\Feature\FlashSales;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\FlashSaleType;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Events\FlashSaleProcessed;
use Marvel\Listeners\FlashSaleProductProcess;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FlashSaleProductionHardenTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $adminUser;
    private User $customerUser;
    private ProductPricingService $pricingService;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->adminUser = $this->createSuperAdmin();
        $this->customerUser = $this->createCustomer();
        $this->pricingService = app(ProductPricingService::class);
    }

    // ========== BUG-1: Listener sets has_flash_sale for vendor-approved products ==========

    /** @test */
    public function listener_sets_has_flash_sale_when_products_attached(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false]);

        $flashSale = FlashSale::create([
            'title' => 'Test Flash Sale',
            'slug' => 'test-flash-sale-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $flashSale->products()->attach($product->id);

        $optionalData = [
            'attached_product_ids' => [$product->id],
            'requested_flash_sale' => $flashSale];

        $event = new FlashSaleProcessed('append_attached_products', 'en', $optionalData);
        $listener = app(FlashSaleProductProcess::class);
        $listener->handle($event);

        $product->refresh();
        $this->assertTrue((bool) $product->has_flash_sale);
        $this->assertNotNull($product->price_after_flash_sale);
    }

    /** @test */
    public function listener_clears_has_flash_sale_when_products_detached(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => true]);

        $optionalData = [
            'detached_product_ids' => [$product->id],
            'requested_flash_sale' => null];

        $event = new FlashSaleProcessed('remove_attached_products', 'en', $optionalData);
        $listener = app(FlashSaleProductProcess::class);
        $listener->handle($event);

        $product->refresh();
        $this->assertFalse((bool) $product->has_flash_sale);
        $this->assertNull($product->price_after_flash_sale);
    }

    /** @test */
    public function listener_clears_has_flash_sale_on_vendor_request_delete(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => true]);

        $optionalData = [
            'detached_products' => [$product->id],
            'requested_flash_sale' => null];

        $event = new FlashSaleProcessed('delete_vendor_request', 'en', $optionalData);
        $listener = app(FlashSaleProductProcess::class);
        $listener->handle($event);

        $product->refresh();
        $this->assertFalse((bool) $product->has_flash_sale);
        $this->assertNull($product->price_after_flash_sale);
    }

    // ========== Admin CRUD regression ==========

    /** @test */
    public function admin_create_flash_sale_validates_image_required(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/flash-sale', [
            'title' => ['en' => 'New Flash Sale'],
            'description' => ['en' => 'Test description'],
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 15,
            'status' => 1]);

        $response->assertStatus(422);
    }

    /** @test */
    public function admin_create_flash_sale_sets_has_flash_sale_on_products(): void
    {
        Sanctum::actingAs($this->adminUser);

        $product = Product::create([
            'name' => 'Flash Product',
            'slug' => 'flash-product-' . uniqid(),
            'price' => 50.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false]);

        $data = [
            'title' => ['en' => 'New Flash Sale'],
            'description' => ['en' => 'Test description'],
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 15,
            'status' => 1,
            'products' => [$product->id]];

        $flashSale = app(\Marvel\Database\Repositories\FlashSaleRepository::class)->storeFlashSale(new Request($data));

        $product->refresh();
        $this->assertTrue((bool) $product->has_flash_sale);
        $this->assertEquals($flashSale->id, $product->flash_sales()->first()->id);
    }

    /** @test */
    public function admin_can_update_flash_sale(): void
    {
        Sanctum::actingAs($this->adminUser);

        $flashSale = FlashSale::create([
            'title' => 'Original Title',
            'slug' => 'original-title-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $response = $this->putJson(self::PREFIX . '/flash-sale/' . $flashSale->id, [
            'discount' => 25,
            'title' => ['en' => 'Updated Title']]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $flashSale->refresh();
        $this->assertEquals(25, (int) $flashSale->discount);
    }

    /** @test */
    public function admin_can_delete_flash_sale(): void
    {
        Sanctum::actingAs($this->adminUser);

        $flashSale = FlashSale::create([
            'title' => 'Deletable Sale',
            'slug' => 'deletable-sale-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $response = $this->deleteJson(self::PREFIX . '/flash-sale/' . $flashSale->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted($flashSale);
    }

    // ========== ProductPricingService: flash sale priority ==========

    /** @test */
    public function flash_sale_overrides_normal_discount_in_pricing(): void
    {
        $product = Product::create([
            'name' => 'Pricing Test',
            'slug' => 'pricing-test-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 50,
            'has_flash_sale' => true]);

        $flashSale = FlashSale::create([
            'title' => 'Active Flash',
            'slug' => 'active-flash-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20]);
        $product->flash_sales()->attach($flashSale->id);

        $pricing = $this->pricingService->calculateProductPricing($product);

        // Flash sale (20%) overrides discount (50%) → final price = 80
        $this->assertEquals(100.00, $pricing['base_price']);
        $this->assertEquals(80.00, $pricing['price_after_flash_sale']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertEquals(80.00, $pricing['final_price']);
    }

    /** @test */
    public function expired_flash_sale_is_ignored(): void
    {
        $product = Product::create([
            'name' => 'Expired Flash',
            'slug' => 'expired-flash-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'has_flash_sale' => true]);

        $flashSale = FlashSale::create([
            'title' => 'Expired Sale',
            'slug' => 'expired-sale-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::parse('-10 days')->format('Y-m-d'),
            'end_date' => Carbon::parse('-1 day')->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);
        $product->flash_sales()->attach($flashSale->id);

        $pricing = $this->pricingService->calculateProductPricing($product);

        // Flash sale is expired → discount takes over
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertEquals(80.00, $pricing['price_after_discount']);
        $this->assertEquals(80.00, $pricing['final_price']);
    }

    /** @test */
    public function inactive_flash_sale_is_ignored(): void
    {
        $product = Product::create([
            'name' => 'Inactive Flash',
            'slug' => 'inactive-flash-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'has_flash_sale' => true]);

        $flashSale = FlashSale::create([
            'title' => 'Inactive Sale',
            'slug' => 'inactive-sale-' . uniqid(),
            'status' => false,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);
        $product->flash_sales()->attach($flashSale->id);

        $pricing = $this->pricingService->calculateProductPricing($product);

        // Flash sale is inactive → discount takes over
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertEquals(80.00, $pricing['price_after_discount']);
        $this->assertEquals(80.00, $pricing['final_price']);
    }

    /** @test */
    public function calculate_flash_sale_price_with_percentage_type(): void
    {
        $flashSale = new FlashSale();
        $flashSale->type = FlashSaleType::PERCENTAGE;
        $flashSale->discount = 25;
        $flashSale->status = true;
        $flashSale->start_date = Carbon::yesterday()->format('Y-m-d');
        $flashSale->end_date = Carbon::tomorrow()->format('Y-m-d');

        $price = $this->pricingService->calculateFlashSalePrice($flashSale, 200.0);

        $this->assertEquals(150.0, $price);
    }

    /** @test */
    public function calculate_flash_sale_price_with_fixed_rate_type(): void
    {
        $flashSale = new FlashSale();
        $flashSale->type = FlashSaleType::FIXED_RATE;
        $flashSale->discount = 30;
        $flashSale->status = true;
        $flashSale->start_date = Carbon::yesterday()->format('Y-m-d');
        $flashSale->end_date = Carbon::tomorrow()->format('Y-m-d');

        $price = $this->pricingService->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertEquals(70.0, $price);
    }

    /** @test */
    public function calculate_flash_sale_price_with_final_price_type(): void
    {
        $flashSale = new FlashSale();
        $flashSale->type = FlashSaleType::FINAL_PRICE;
        $flashSale->discount = 60;
        $flashSale->status = true;
        $flashSale->start_date = Carbon::yesterday()->format('Y-m-d');
        $flashSale->end_date = Carbon::tomorrow()->format('Y-m-d');

        $price = $this->pricingService->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertEquals(60.0, $price);
    }

    /** @test */
    public function null_flash_sale_returns_null_price(): void
    {
        $price = $this->pricingService->calculateFlashSalePrice(null, 100.0);

        $this->assertNull($price);
    }

    // ========== Resource structure ==========

    /** @test */
    public function flash_sale_resource_has_description_field(): void
    {
        Sanctum::actingAs($this->adminUser);

        $flashSale = FlashSale::create([
            'title' => 'Resource Test',
            'slug' => 'resource-test-' . uniqid(),
            'description' => 'Test description content',
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $response = $this->getJson(self::PREFIX . '/flash-sale/' . $flashSale->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'title', 'slug', 'image', 'description',
                'start_date', 'end_date', 'status', 'is_valid',
                'type', 'discount', 'max_discount_amount', 'created_at']]);
    }

    // ========== Validation & Security ==========

    /** @test */
    public function create_flash_sale_validates_required_fields(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/flash-sale', []);

        $response->assertStatus(422);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_flash_sale(): void
    {
        $response = $this->postJson(self::PREFIX . '/flash-sale', [
            'title' => 'Hacked Sale']);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthorized_user_cannot_delete_flash_sale(): void
    {
        Sanctum::actingAs($this->customerUser);

        $response = $this->deleteJson(self::PREFIX . '/flash-sale/1');

        $response->assertStatus(403);
    }

    /** @test */
    public function flash_sale_show_returns_404_for_nonexistent(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/flash-sale/99999');

        $response->assertNotFound();
    }

    /** @test */
    public function flash_sale_type_must_be_valid_enum(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/flash-sale', [
            'title' => ['en' => 'Invalid Type'],
            'description' => ['en' => 'Test'],
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => 'invalid_type',
            'discount' => 10,
            'status' => 1]);

        $response->assertStatus(422);
    }

    /** @test */
    public function flash_sale_discount_must_be_numeric(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/flash-sale', [
            'title' => ['en' => 'Bad Discount'],
            'description' => ['en' => 'Test'],
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 'not-a-number',
            'status' => 1]);

        $response->assertStatus(422);
    }

    // ========== Model scope tests ==========

    /** @test */
    public function valid_scope_only_returns_active_flash_sales(): void
    {
        $activeSale = FlashSale::create([
            'title' => 'Active Sale',
            'slug' => 'active-sale-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $inactiveSale = FlashSale::create([
            'title' => 'Inactive Sale',
            'slug' => 'inactive-sale-' . uniqid(),
            'status' => false,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $expiredSale = FlashSale::create([
            'title' => 'Expired Sale',
            'slug' => 'expired-sale-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::parse('-10 days')->format('Y-m-d'),
            'end_date' => Carbon::parse('-1 day')->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $validSales = FlashSale::valid()->get();

        $this->assertTrue($validSales->contains('id', $activeSale->id));
        $this->assertFalse($validSales->contains('id', $inactiveSale->id));
        $this->assertFalse($validSales->contains('id', $expiredSale->id));
    }

    /** @test */
    public function product_get_active_flash_sale_returns_correct_sale(): void
    {
        $product = Product::create([
            'name' => 'Flash Product',
            'slug' => 'flash-product-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => true]);

        $flashSale = FlashSale::create([
            'title' => 'Active Flash',
            'slug' => 'active-flash-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);
        $product->flash_sales()->attach($flashSale->id);

        $result = $product->getActiveFlashSale();

        $this->assertNotNull($result);
        $this->assertEquals($flashSale->id, $result->id);
    }

    /** @test */
    public function product_without_has_flash_sale_returns_null_active_sale(): void
    {
        $product = Product::create([
            'name' => 'No Flash',
            'slug' => 'no-flash-' . uniqid(),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false]);

        $this->assertNull($product->getActiveFlashSale());
    }

    // ========== Soft delete / restore ==========

    /** @test */
    public function flash_sale_can_be_soft_deleted_and_restored(): void
    {
        $flashSale = FlashSale::create([
            'title' => 'Deletable',
            'slug' => 'deletable-' . uniqid(),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10]);

        $flashSale->delete();
        $this->assertSoftDeleted($flashSale);

        $flashSale->restore();
        $this->assertNotSoftDeleted($flashSale);
    }

    // ========== Route ordering ==========

    /** @test */
    public function reorder_route_does_not_conflict_with_show(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->putJson(self::PREFIX . '/flash-sale/reorder', [
            'flash_sales' => []]);

        // Should hit validation, not 404 as a show route
        $response->assertStatus(422);
    }

    private function createSuperAdmin(): User
    {
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::CREATE_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::UPDATE_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::DELETE_FlASH_SALE, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        $role->givePermissionTo([
            PermissionEnum::VIEW_FlASH_SALE,
            PermissionEnum::CREATE_FlASH_SALE,
            PermissionEnum::UPDATE_FlASH_SALE,
            PermissionEnum::DELETE_FlASH_SALE]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin.flashsale.harden@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        return $user;
    }

    private function createCustomer(): User
    {
        Permission::findOrCreate(PermissionEnum::CUSTOMER, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::CUSTOMER,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Customer'])]);

        $role->givePermissionTo([PermissionEnum::CUSTOMER]);

        $user = User::create([
            'name' => 'Customer',
            'email' => 'customer.flashsale@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        return $user;
    }
}
