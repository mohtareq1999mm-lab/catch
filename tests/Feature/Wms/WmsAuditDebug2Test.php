<?php

namespace Tests\Feature\Wms;

use App\Console\Commands\AuditInventoryPlacements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Tests\TestCase;

class WmsAuditDebug2Test extends TestCase
{
    use RefreshDatabase;

    public function test_debug_direct_audit(): void
    {
        Product::create([
            'name' => 'Dbg '.Str::random(6), 'slug' => 'dbg-'.Str::random(8), 'price' => 50,
            'product_type' => 'simple', 'status' => 1, 'in_stock' => true,
            'quantity' => 0, 'stock_quantity' => 9, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);

        try {
            $result = (new AuditInventoryPlacements)->audit(null);
            fwrite(STDERR, "\nAUDIT-OK status={$result['status']}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nAUDIT-THREW ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
            throw $e;
        }
        $this->assertTrue(true);
    }
}
