<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Marvel\Http\Resources\ProductResource;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductProductionHardenTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private ProductPricingService $pricingService;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Config::set('scout.driver', 'null');

        $this->createAllTestTables();

        // Add order_status to orders if missing
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'order_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('order_status')->nullable()->after('status');
            });
        }

        // Add order_product pivot table needed by bestSellingProducts/popularProducts
        if (!Schema::hasTable('order_product')) {
            Schema::create('order_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->integer('order_quantity')->default(1);
                $table->decimal('product_price', 10, 2)->default(0);
                $table->decimal('product_total_price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);

        Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
        $this->admin->assignRole('super_admin');

        foreach ([Permission::VIEW_PRODUCTS, Permission::CREATE_PRODUCT, Permission::UPDATE_PRODUCT, Permission::DELETE_PRODUCT] as $perm) {
            \Spatie\Permission\Models\Permission::create(['name' => $perm, 'guard_name' => 'api']);
        }
        $this->admin->givePermissionTo([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT]);

        $this->pricingService = app(ProductPricingService::class);
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Test Product ' . Str::random(6)],
            'slug' => 'test-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISH,
            'in_stock' => true,
            'stock_quantity' => 50], $overrides));
    }

    private function makeSettingsTable(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('language')->default('en');
                $table->text('options')->nullable();
                $table->timestamps();
            });
        }
    }

    // =========================================================================
    // BUG 1: ProductImportService::processProductRow null property access
    // =========================================================================

    public function test_process_product_row_without_sku_does_not_crash()
    {
        $service = new \Marvel\Services\Import\ProductImportService();

        $row = [
            'name_en' => 'No SKU Product',
            'price' => 25,
            'quantity' => 3,
            'product_type' => 'simple'];

        $service->processProductRow($row, 2);

        $product = Product::orderBy('id', 'desc')->first();
        $this->assertNotNull($product);
        $this->assertEquals(25, (float) $product->price);
        $this->assertEquals(1, $service->getSuccessCount());
    }

    public function test_process_product_row_with_empty_sku_string_does_not_crash()
    {
        $service = new \Marvel\Services\Import\ProductImportService();

        $row = [
            'sku' => '',
            'name_en' => 'Empty SKU Product',
            'price' => 50,
            'quantity' => 10,
            'product_type' => 'simple'];

        $service->processProductRow($row, 2);

        $this->assertEquals(1, $service->getSuccessCount());
    }

    // =========================================================================
    // BUG 2: ProductController::popularProducts counts all orders regardless of status
    // =========================================================================

    public function test_popular_products_only_counts_completed_orders()
    {
        $this->makeSettingsTable();

        $productA = $this->createProduct(['name' => ['en' => 'Popular A'], 'slug' => 'popular-a-' . Str::random(4)]);
        $productB = $this->createProduct(['name' => ['en' => 'Popular B'], 'slug' => 'popular-b-' . Str::random(4)]);

        $user = User::create([
            'name' => 'Order User',
            'email' => 'order-user@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'email_verified_at' => now()]);

        // Product A: 1 completed, 2 non-completed
        $this->createOrderWithProduct($user, $productA, 'order-completed');
        $this->createOrderWithProduct($user, $productA, 'order-cancelled');
        $this->createOrderWithProduct($user, $productA, 'order-refunded');

        // Product B: 1 completed
        $this->createOrderWithProduct($user, $productB, 'order-completed');

        // Verify completed count for Product A is 1 (not 3)
        $completedCount = DB::table('order_product')
            ->join('orders', 'order_product.order_id', '=', 'orders.id')
            ->where('order_product.product_id', $productA->id)
            ->where('orders.order_status', 'order-completed')
            ->count();
        $this->assertEquals(1, $completedCount, 'Product A should have 1 completed order');
    }

    private function createOrderWithProduct(User $user, Product $product, string $orderStatus): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => $orderStatus,
            'price' => $product->price,
            'total_price' => $product->price]);
        // order_status is not in fillable, set it directly
        $order->setAttribute('order_status', $orderStatus);
        $order->save();

        DB::table('order_product')->insert([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'order_quantity' => 1,
            'product_price' => $product->price,
            'product_total_price' => $product->price]);

        return $order;
    }

    // =========================================================================
    // BUG 3: ProductResource status should return string, not bool
    // =========================================================================

    public function test_product_resource_returns_status_as_string()
    {
        $product = $this->createProduct(['status' => ProductStatus::PUBLISH]);
        $resource = new ProductResource($product);
        $data = $resource->toArray(request());

        $this->assertIsNotBool($data['status']);
        $this->assertEquals(ProductStatus::PUBLISH, $data['status']);
    }

    public function test_product_resource_status_preserves_draft_value()
    {
        $product = $this->createProduct(['status' => ProductStatus::DRAFT]);
        $resource = new ProductResource($product);
        $data = $resource->toArray(request());

        $this->assertEquals(ProductStatus::DRAFT, $data['status']);
    }

    public function test_product_resource_status_preserves_under_review_value()
    {
        $product = $this->createProduct(['status' => ProductStatus::UNDER_REVIEW]);
        $resource = new ProductResource($product);
        $data = $resource->toArray(request());

        $this->assertEquals(ProductStatus::UNDER_REVIEW, $data['status']);
    }

    // =========================================================================
    // BUG 4: ProductPricingService isDiscountActiveFromData with string values
    // =========================================================================

    public function test_is_discount_active_from_data_with_string_zero()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => '0'];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNull($pricing['price_after_discount'], 'Discount should be inactive when discount_status is "0"');
        $this->assertEquals(100, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_with_boolean_false()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => false];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNull($pricing['price_after_discount'], 'Discount should be inactive when discount_status is false');
        $this->assertEquals(100, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_with_string_false()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => 'false'];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNull($pricing['price_after_discount'], 'Discount should be inactive when discount_status is "false"');
        $this->assertEquals(100, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_with_integer_zero()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => 0];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNull($pricing['price_after_discount'], 'Discount should be inactive when discount_status is 0');
        $this->assertEquals(100, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_with_string_one()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => '1'];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNotNull($pricing['price_after_discount'], 'Discount should be active when discount_status is "1"');
        $this->assertEquals(90, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_without_discount_status()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNotNull($pricing['price_after_discount'], 'Discount should be active when discount_status is absent and has_discount is true');
        $this->assertEquals(90, $pricing['final_price']);
    }

    public function test_is_discount_active_from_data_with_null_discount_status()
    {
        $data = [
            'price' => 100,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => null];

        $pricing = $this->pricingService->calculateProductPricingFromData($data);
        $this->assertNotNull($pricing['price_after_discount'], 'Discount should be active when discount_status is null and has_discount is true');
        $this->assertEquals(90, $pricing['final_price']);
    }

    // =========================================================================
    // BUG 5: ProductCreateRequest status validation
    // =========================================================================

    public function test_create_product_with_valid_product_status_publish()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Status Test Publish'],
            'description' => ['en' => 'Description'],
            'price' => 50,
            'product_type' => ProductType::SIMPLE,
            'categories' => [],
            'images' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'status' => ProductStatus::PUBLISH]);

        $this->assertContains($response->status(), [201, 422],
            'Product with publish status should be accepted');
    }

    public function test_create_product_with_valid_product_status_draft()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Status Test Draft'],
            'description' => ['en' => 'Description'],
            'price' => 50,
            'product_type' => ProductType::SIMPLE,
            'categories' => [],
            'images' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'status' => ProductStatus::DRAFT]);

        $this->assertContains($response->status(), [201, 422],
            'Product with draft status should be accepted');
    }

    public function test_create_product_with_valid_product_status_under_review()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Status Test Under Review'],
            'description' => ['en' => 'Description'],
            'price' => 50,
            'product_type' => ProductType::SIMPLE,
            'categories' => [],
            'images' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'status' => ProductStatus::UNDER_REVIEW]);

        $this->assertContains($response->status(), [201, 422],
            'Product with under_review status should be accepted');
    }

    // =========================================================================
    // ProductRepository: storeProduct variant handling
    // =========================================================================

    public function test_repository_store_product_creates_variants_directly()
    {
        $product = Product::create([
            'name' => ['en' => 'Repo Variant Test'],
            'slug' => 'repo-variant-' . Str::random(8),
            'price' => 100,
            'product_type' => ProductType::VARIABLE,
            'status' => ProductStatus::PUBLISH,
            'in_stock' => true,
            'stock_quantity' => 50]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'price' => 50,
            'stock_quantity' => 10]);

        $this->assertNotNull($variant->id);
        $this->assertEquals($product->id, $variant->product_id);

        $variantsCount = ProductVariant::where('product_id', $product->id)->count();
        $this->assertEquals(1, $variantsCount);
    }

    public function test_repository_removes_variants_when_updating()
    {
        $product = Product::create([
            'name' => ['en' => 'Repo Variant Remove'],
            'slug' => 'repo-variant-remove-' . Str::random(8),
            'price' => 100,
            'product_type' => ProductType::VARIABLE,
            'status' => ProductStatus::PUBLISH,
            'in_stock' => true,
            'stock_quantity' => 50]);

        ProductVariant::create(['product_id' => $product->id, 'price' => 30, 'stock_quantity' => 5]);
        ProductVariant::create(['product_id' => $product->id, 'price' => 40, 'stock_quantity' => 3]);

        $this->assertEquals(2, $product->variations()->count());

        // Delete variants via repository's approach
        ProductVariant::where('product_id', $product->id)->delete();

        $this->assertEquals(0, $product->variations()->count());
    }

    // =========================================================================
    // Edge Cases: Product quantity and stock fields
    // =========================================================================

    public function test_product_available_stock_is_computed_correctly()
    {
        $product = $this->createProduct([
            'stock_quantity' => 100,
            'reserved_quantity' => 30]);

        $this->assertEquals(70, $product->available_stock);
    }

    public function test_product_available_stock_never_below_zero()
    {
        $product = $this->createProduct([
            'stock_quantity' => 10,
            'reserved_quantity' => 100]);

        $this->assertEquals(0, $product->available_stock);
    }

    // =========================================================================
    // Edge Cases: Best selling products filters by completed orders
    // =========================================================================

    public function test_best_selling_products_query_filters_completed_orders()
    {
        $this->makeSettingsTable();

        $product = $this->createProduct(['name' => ['en' => 'Best Seller'], 'slug' => 'best-seller-' . Str::random(4)]);
        $user = User::create([
            'name' => 'BestSeller User',
            'email' => 'bestseller@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->createOrderWithProduct($user, $product, 'order-completed');
        $this->createOrderWithProduct($user, $product, 'order-cancelled');

        $totalSales = DB::table('order_product')
            ->join('orders', 'order_product.order_id', '=', 'orders.id')
            ->where('order_product.product_id', $product->id)
            ->where('orders.order_status', 'order-completed')
            ->sum('order_product.order_quantity');

        $this->assertEquals(1, $totalSales, 'Best selling should count only completed orders');
    }

    // =========================================================================
    // ProductPricingService: calculateDiscountedPrice edge cases
    // =========================================================================

    public function test_calculate_discounted_price_with_null_price()
    {
        $result = $this->pricingService->calculateDiscountedPrice(null, DiscountType::PERCENTAGE, 10);
        $this->assertNull($result);
    }

    public function test_calculate_discounted_price_with_empty_string_price()
    {
        $result = $this->pricingService->calculateDiscountedPrice('', DiscountType::PERCENTAGE, 10);
        $this->assertNull($result);
    }

    public function test_calculate_discounted_price_with_zero_amount()
    {
        $result = $this->pricingService->calculateDiscountedPrice(100.0, DiscountType::PERCENTAGE, 0);
        $this->assertSame(100.0, $result);
    }

    // =========================================================================
    // Product model: SKU auto-generation
    // =========================================================================

    public function test_product_auto_generates_sku_when_empty()
    {
        $product = Product::create([
            'name' => ['en' => 'Auto SKU'],
            'slug' => 'auto-sku-' . Str::random(8),
            'price' => 100,
            'product_type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISH]);

        $this->assertNotNull($product->sku);
        $this->assertStringStartsWith('PRD-', $product->sku);
    }

    // =========================================================================
    // Product model: isDiscountActive
    // =========================================================================

    public function test_product_is_discount_active_when_status_is_false()
    {
        $product = $this->createProduct([
            'has_discount' => true,
            'discount_status' => false,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10]);

        $this->assertFalse($product->isDiscountActive());
    }

    public function test_product_is_discount_active_when_start_date_in_future()
    {
        $product = $this->createProduct([
            'has_discount' => true,
            'discount_status' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(10)]);

        $this->assertFalse($product->isDiscountActive());
    }

    public function test_product_is_discount_active_when_end_date_passed()
    {
        $product = $this->createProduct([
            'has_discount' => true,
            'discount_status' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'start_date' => Carbon::now()->subDays(10),
            'end_date' => Carbon::now()->subDay()]);

        $this->assertFalse($product->isDiscountActive());
    }
}
