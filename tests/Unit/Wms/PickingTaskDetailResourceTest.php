<?php

namespace Tests\Unit\Wms;

use App\Http\Resources\Wms\PickingTaskDetailResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Tests\TestCase;

class PickingTaskDetailResourceTest extends TestCase
{
    public function test_picking_task_detail_resource_returns_item_and_items_matching_packing_task_contract(): void
    {
        $task = new PickingTask([
            'quantity_to_pick' => 1.0,
            'quantity_picked' => 0.0,
            'status' => 'pending',
        ]);
        $task->id = 924;

        $fulfillment = new Fulfillment([
            'fulfillment_number' => 'FUL-MASS-pending-001',
            'warehouse_id' => 1,
            'status' => 'picking',
            'assigned_to' => null,
        ]);
        $fulfillment->id = 1;

        $item = new FulfillmentItem([
            'order_item_id' => 5,
            'product_id' => 10,
            'product_variant_id' => null,
            'product_location_id' => 1,
            'quantity' => 1.0,
            'quantity_picked' => 0.0,
            'status' => 'pending',
        ]);
        $item->id = 1;

        $product = new Product([
            'name' => 'FP',
            'sku' => 'SKU-FUL-1',
            'product_type' => 'simple',
        ]);
        $product->id = 10;
        $item->setRelation('product', $product);

        $location = new Location([
            'code' => 'LOC-A-01-01',
            'name' => 'Cairo Bin A-01-01',
            'barcode' => 'BAR-LOC-CAI-A-01-01',
            'type' => 'picking',
            'status' => 'active',
        ]);
        $location->id = 1;

        $prodLoc = new ProductLocation([
            'location_id' => 1,
            'warehouse_id' => 1,
        ]);
        $prodLoc->id = 1;
        $prodLoc->setRelation('location', $location);

        $item->setRelation('productLocation', $prodLoc);
        $item->setRelation('fulfillment', $fulfillment);

        $task->setRelation('fulfillmentItem', $item);
        $task->setRelation('productLocation', $prodLoc);

        $resource = new PickingTaskDetailResource($task);
        $array = $resource->toArray(Request::create('/api/v1/admin/picking-tasks/924'));

        // Assert existing keys are preserved
        $this->assertSame(924, $array['id']);
        $this->assertSame('pending', $array['status']);
        $this->assertArrayHasKey('fulfillment', $array);
        $this->assertSame('FUL-MASS-pending-001', $array['fulfillment']['fulfillment_number']);
        $this->assertArrayHasKey('location', $array);
        $this->assertSame('LOC-A-01-01', $array['location']['code']);
        $this->assertArrayHasKey('op_seq', $array);
        $this->assertArrayHasKey('scan_log', $array);

        // Assert items key exists and item key is absent
        $this->assertArrayNotHasKey('item', $array);
        $this->assertArrayHasKey('items', $array);
        $this->assertIsArray($array['items']);
        $this->assertCount(1, $array['items']);

        // Check single item structure
        $singleItem = $array['items'][0];
        $this->assertNotNull($singleItem);
        $this->assertSame(1, $singleItem['id']);
        $this->assertSame(5, $singleItem['order_item_id']);
        $this->assertSame(10, $singleItem['product_id']);
        $this->assertNull($singleItem['product_variant_id']);
        $this->assertSame(1, $singleItem['product_location_id']);
        $this->assertEquals(1.0, $singleItem['quantity']);
        $this->assertEquals(0.0, $singleItem['quantity_picked']);
        $this->assertSame('pending', $singleItem['status']);

        // Check product sub-structure
        $this->assertIsArray($singleItem['product']);
        $this->assertSame(10, $singleItem['product']['id']);
        $this->assertSame('FP', $singleItem['product']['name']);
        $this->assertSame('SKU-FUL-1', $singleItem['product']['sku']);
        $this->assertSame('simple', $singleItem['product']['product_type']);

        // Check location sub-structure inside item
        $this->assertIsArray($singleItem['location']);
        $this->assertSame(1, $singleItem['location']['id']);
        $this->assertSame('LOC-A-01-01', $singleItem['location']['code']);
        $this->assertSame('Cairo Bin A-01-01', $singleItem['location']['name']);
        $this->assertSame('BAR-LOC-CAI-A-01-01', $singleItem['location']['barcode']);
    }
}
