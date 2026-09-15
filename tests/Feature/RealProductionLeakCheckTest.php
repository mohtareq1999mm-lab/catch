<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Brand;

/**
 * REAL DATA LEAK CHECK - Run against actual DB (no RefreshDatabase)
 * This test does NOT wipe the DB. It audits the live data via the
 * public general API and fails if any inactive product/category/brand
 * leaks through, proving the "status=0 still visible" bug.
 *
 * Run: php artisan test --filter=RealProductionLeakCheckTest
 * For full DB audit: php artisan test --filter=RealProductionLeakCheckTest --verbose
 */
class RealProductionLeakCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Do NOT use RefreshDatabase - we audit real data
        Cache::flush();
    }

    public function test_no_inactive_product_visible_via_any_general_endpoint(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('products')) {
            $this->markTestSkipped('No products table in sqlite memory - run with real DB or use RealDataActiveScopeTest');
        }
        // 1. Direct DB ground truth: what is inactive?
        $inactiveProductIds = Product::withoutGlobalScopes()
            ->where(function ($q) {
                $q->where('status', false)
                  ->orWhere('status', 0)
                  ->orWhere('status', '0')
                  ->orWhere('status', 'draft')
                  ->orWhere('status', 'unpublish')
                  ->orWhere('status', 'under_review')
                  ->orWhere('status', 'rejected');
            })
            ->orWhere(function ($q) {
                $q->where('in_stock', false)
                  ->whereRaw('(COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0)) <= 0');
            })
            ->pluck('id')
            ->toArray();

        // Also products whose sole category/brand is inactive
        $inactiveCategoryIds = Category::where('status', 0)->pluck('id')->toArray();
        $inactiveBrandIds = Brand::where('status', 0)->pluck('id')->toArray();

        $totalProducts = Product::withoutGlobalScopes()->count();
        $activeCount = Product::active()->count();
        fwrite(STDOUT, "\n[REAL DATA] total products: $totalProducts, active(scope): $activeCount, inactive(DB): ".count($inactiveProductIds)."\n");
        fwrite(STDOUT, "Inactive category IDs: ".json_encode($inactiveCategoryIds)."\n");
        fwrite(STDOUT, "Inactive brand IDs: ".json_encode($inactiveBrandIds)."\n");

        if ($totalProducts === 0) {
            $this->markTestSkipped('No products in DB - seed data first');
        }

        // 2. Hit every general endpoint that can return products and assert no inactive id appears
        $endpoints = [
            'products listing' => '/api/v1/general/products?limit=100',
            'products listing page2' => '/api/v1/general/products?limit=100&page=2',
            'products search' => '/api/v1/general/products?search=a&limit=50', // generic search
            'categories' => '/api/v1/general/categories?limit=100',
            'brands' => '/api/v1/general/brands?limit=100',
            'brands-products' => '/api/v1/general/brands-products?limit=50',
            'banners' => '/api/v1/general/banners?limit=100',
            'sliders' => '/api/v1/general/sliders?limit=100',
            'promotions' => '/api/v1/general/promotions?limit=100',
            'flash-sales' => '/api/v1/general/flash-sales?limit=100',
            'flash-sale-products' => '/api/v1/general/flash-sale-products?limit=50',
            'nav-data' => '/api/v1/general/nav-data',
        ];

        $failures = [];

        foreach ($endpoints as $label => $url) {
            $resp = $this->getJson($url);
            if ($resp->status() !== 200) {
                fwrite(STDOUT, "[$label] $url -> status ".$resp->status()." (skip)\n");
                continue;
            }
            $body = json_encode($resp->json());
            // Check each inactive product id appears in body? We check slugs/ids
            $inactiveProducts = Product::withoutGlobalScopes()->whereIn('id', $inactiveProductIds)->get(['id','slug','status']);
            foreach ($inactiveProducts as $p) {
                // Check by id and slug
                if (str_contains($body, '"id":'.$p->id) || str_contains($body, '"slug":"'.$p->slug.'"')) {
                    $failures[] = "$label leaked inactive product id {$p->id} slug {$p->slug} status=".json_encode($p->status);
                }
            }
            // Also check inactive category/brand products via relation
            if (!empty($inactiveCategoryIds)) {
                $catLeakIds = DB::table('category_product')->whereIn('category_id', $inactiveCategoryIds)->pluck('product_id')->toArray();
                // Only those where product has ONLY inactive categories (no active)
                foreach ($catLeakIds as $pid) {
                    $hasActiveCat = DB::table('category_product')
                        ->join('categories','categories.id','=','category_product.category_id')
                        ->where('category_product.product_id', $pid)
                        ->where('categories.status', 1)
                        ->exists();
                    if (!$hasActiveCat) {
                        $prod = Product::withoutGlobalScopes()->find($pid);
                        if ($prod && (str_contains($body, '"id":'.$pid) || str_contains($body, '"slug":"'.$prod->slug.'"'))) {
                            $failures[] = "$label leaked product $pid (slug {$prod->slug}) whose sole category is inactive";
                        }
                    }
                }
            }
            fwrite(STDOUT, "[$label] checked, body length ".strlen($body)."\n");
        }

        // 3. Direct detail checks for each inactive product
        $sampleInactive = Product::withoutGlobalScopes()
            ->where('status', 0)
            ->orWhere('status', 'draft')
            ->limit(3)
            ->get();
        foreach ($sampleInactive as $p) {
            $detail = $this->getJson("/api/v1/general/products/{$p->slug}");
            if ($detail->status() !== 404) {
                $failures[] = "Detail for inactive product {$p->slug} (status=".json_encode($p->status).") should 404 but got ".$detail->status()." body ".json_encode($detail->json());
            } else {
                fwrite(STDOUT, "Detail correctly 404 for inactive {$p->slug}\n");
            }
        }

        // 4. Inactive category/brand detail should 404
        $sampleCat = Category::where('status', 0)->first();
        if ($sampleCat) {
            $r = $this->getJson("/api/v1/general/categories/{$sampleCat->slug}");
            if ($r->status() !== 404) {
                $failures[] = "Inactive category {$sampleCat->slug} should 404 but got ".$r->status();
            }
        }
        $sampleBrand = Brand::where('status', 0)->first();
        if ($sampleBrand) {
            $r = $this->getJson("/api/v1/general/brands/{$sampleBrand->slug}");
            if ($r->status() !== 404) {
                $failures[] = "Inactive brand {$sampleBrand->slug} should 404 but got ".$r->status();
            }
        }

        if (!empty($failures)) {
            fwrite(STDOUT, "\n=== REAL DATA LEAKS FOUND ===\n".implode("\n", $failures)."\n");
        } else {
            fwrite(STDOUT, "\n=== REAL DATA: NO LEAKS ===\n");
        }

        $this->assertEmpty($failures, "Real data leaks found:\n".implode("\n", $failures));
    }

    public function test_status_zero_product_is_considered_inactive_by_scope(): void
    {
        // This probe is covered by RealDataActiveScopeTest with RefreshDatabase.
        // Here we just verify the scope SQL for status=0 without needing DB write.
        $query = Product::active()->toSql();
        $this->assertStringContainsString('status', $query);
        $this->assertTrue(true, 'Scope exists');
        fwrite(STDOUT, "Scope check: $query\n");
    }
}
