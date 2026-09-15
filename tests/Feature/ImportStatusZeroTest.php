<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Product;
use Marvel\Services\Import\ProductImportService;
use Marvel\Enums\ProductStatus;

class ImportStatusZeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_import_with_status_zero_is_hidden_from_client(): void
    {
        $service = new ProductImportService();

        // Simulate import row with status = 0 (inactive) - like Excel "0" or "draft"
        $row = [
            'name_en' => 'Imported Inactive',
            'name_ar' => 'مستورد غير نشط',
            'sku' => 'IMPORT-STATUS-0-'.uniqid(),
            'price' => 100,
            'quantity' => 10,
            'status' => '0', // user sends 0
            'in_stock' => '1',
        ];
        $service->processProductRow($row, 2);

        $this->assertCount(0, $service->getFailedRows(), 'Import should succeed');
        $product = Product::withoutGlobalScopes()->where('sku', $row['sku'])->first();
        $this->assertNotNull($product, 'Product should be created');
        $this->assertEquals(0, (int)$product->getRawOriginal('status'), 'DB status should be 0');

        // Scope must hide it
        $isActive = Product::active()->where('id', $product->id)->exists();
        $this->assertFalse($isActive, 'Product with status=0 must NOT be active via scopeActive');

        // Client must not see it
        $resp = $this->getJson('/api/v1/general/products?limit=100');
        $resp->assertOk();
        $this->assertStringNotContainsString($product->slug, json_encode($resp->json()), 'status=0 product leaked into listing');
        $this->getJson("/api/v1/general/products/{$product->slug}")->assertStatus(404);

        // Also test status='draft' string
        $row2 = [
            'name_en' => 'Imported Draft',
            'sku' => 'IMPORT-DRAFT-'.uniqid(),
            'price' => 100,
            'quantity' => 10,
            'status' => 0,
        ];
        $service->processProductRow($row2, 3);
        $p2 = Product::withoutGlobalScopes()->where('sku', $row2['sku'])->first();
        $rawStatus = $p2->getRawOriginal('status');
        $this->assertTrue($rawStatus == 0 || $rawStatus === false || $rawStatus === 'draft', 'draft should be stored as inactive, got '.json_encode($rawStatus));
        $this->assertFalse(Product::active()->where('id', $p2->id)->exists(), 'draft must be inactive');
        $this->assertStringNotContainsString($p2->slug, json_encode($this->getJson('/api/v1/general/products?limit=100')->json()));
    }

    public function test_import_active_product_is_visible(): void
    {
        $service = new ProductImportService();
        $row = [
            'name_en' => 'Imported Active',
            'sku' => 'IMPORT-ACTIVE-'.uniqid(),
            'price' => 50,
            'quantity' => 5,
            'status' => '1',
            'in_stock' => '1',
        ];
        $service->processProductRow($row, 2);
        $product = Product::withoutGlobalScopes()->where('sku', $row['sku'])->first();
        $this->assertTrue(Product::active()->where('id', $product->id)->exists(), 'Active product must be active');
        $this->getJson('/api/v1/general/products?limit=100')->assertJsonFragment(['slug' => $product->slug]);
        $this->getJson("/api/v1/general/products/{$product->slug}")->assertOk();
    }
}
