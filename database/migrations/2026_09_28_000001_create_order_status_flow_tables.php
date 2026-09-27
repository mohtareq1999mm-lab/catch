<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order Status catalog + configurable Order Flows (linear, sort_order-driven).
 *
 * Creates:
 *  - order_statuses      global catalog (stable code, editable name, soft-disable)
 *  - order_flows         one active flow per shipping_type (local default)
 *  - order_flow_statuses ordered membership (UNIQUE flow+status, flow+sort_order)
 *
 * Seeds the 5 legacy statuses plus the logistics codes and the Local /
 * International flows. The new Order-level flow does NOT replace the
 * Fulfillment or Shipment state machines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'order_statuses_is_active_idx');
        });

        Schema::create('order_flows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            // New shipping-type dimension (local/international). Deliberately
            // NOT Marvel\Enums\ShippingType (pricing) nor ShippingMethod.
            $table->string('shipping_type', 30)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shipping_type', 'is_active'], 'order_flows_shipping_active_idx');
        });

        Schema::create('order_flow_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('order_flows')->cascadeOnDelete();
            $table->foreignId('status_id')->constrained('order_statuses')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['flow_id', 'status_id'], 'order_flow_statuses_flow_status_unique');
            $table->unique(['flow_id', 'sort_order'], 'order_flow_statuses_flow_order_unique');
            $table->index(['flow_id', 'sort_order'], 'order_flow_statuses_flow_order_idx');
        });

        $this->seedCatalog();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_flow_statuses');
        Schema::dropIfExists('order_flows');
        Schema::dropIfExists('order_statuses');
    }

    private function seedCatalog(): void
    {
        $now = now();

        foreach (\App\Services\OrderFlow\OrderFlowService::catalogSeed() as $status) {
            $exists = DB::table('order_statuses')->where('code', $status['code'])->exists();
            if ($exists) {
                DB::table('order_statuses')->where('code', $status['code'])->update([
                    'name' => $status['name'],
                    'description' => $status['description'],
                    'is_active' => $status['is_active'],
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('order_statuses')->insert([
                    'code' => $status['code'],
                    'name' => $status['name'],
                    'description' => $status['description'],
                    'is_active' => $status['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $statusIds = DB::table('order_statuses')->pluck('id', 'code');

        foreach (\App\Services\OrderFlow\OrderFlowService::flowsSeed() as $flow) {
            $flowRow = DB::table('order_flows')->where('code', $flow['code'])->first();
            if ($flowRow) {
                DB::table('order_flows')->where('id', $flowRow->id)->update([
                    'name' => $flow['name'],
                    'shipping_type' => $flow['shipping_type'],
                    'is_default' => $flow['is_default'],
                    'is_active' => $flow['is_active'],
                    'updated_at' => $now,
                ]);
                $flowId = $flowRow->id;
            } else {
                $flowId = DB::table('order_flows')->insertGetId([
                    'code' => $flow['code'],
                    'name' => $flow['name'],
                    'shipping_type' => $flow['shipping_type'],
                    'is_default' => $flow['is_default'],
                    'is_active' => $flow['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $sort = 1;
            foreach ($flow['statuses'] as $code) {
                $pivot = DB::table('order_flow_statuses')
                    ->where('flow_id', $flowId)
                    ->where('status_id', $statusIds[$code])
                    ->first();
                if ($pivot) {
                    DB::table('order_flow_statuses')->where('id', $pivot->id)->update([
                        'sort_order' => $sort,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('order_flow_statuses')->insert([
                        'flow_id' => $flowId,
                        'status_id' => $statusIds[$code],
                        'sort_order' => $sort,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                $sort++;
            }
        }
    }
};
