<?php

namespace Database\Seeders;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderFlowStatus;
use App\Models\OrderFlow\OrderStatus;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Database\Seeder;

/**
 * Global Order Status catalog + shipping Flows seeder.
 *
 * Production-safe by design:
 * - Idempotent and deterministic (stable codes, safe to re-run via db:seed).
 * - NEVER overwrites admin customizations: existing status rows keep their
 *   name / description / is_active; existing flows keep their flags.
 * - NEVER deletes flow memberships or reorders customized flows: only
 *   missing seed members are appended (after the current max sort_order).
 * - NEVER touches orders data (no backfill here; migrations own that).
 *
 * Canonical seed content lives in OrderFlowService::catalogSeed() /
 * flowsSeed() (single source of truth shared with migrations and tests).
 */
class OrderFlowSeeder extends Seeder
{
    public function run(): void
    {
        if (!OrderFlowService::tablesAvailable()) {
            $this->command?->warn('OrderFlowSeeder skipped: flow tables are missing. Run migrations first.');

            return;
        }

        $this->seedStatuses();
        $this->seedFlows();
    }

    private function seedStatuses(): void
    {
        foreach (OrderFlowService::catalogSeed() as $seed) {
            $exists = OrderStatus::query()->where('code', $seed['code'])->exists();

            if ($exists) {
                // Admin-owned row: preserve name/description/is_active.
                continue;
            }

            OrderStatus::query()->create([
                'code' => $seed['code'],
                'name' => $seed['name'],
                'description' => $seed['description'],
                'is_active' => $seed['is_active'],
            ]);
        }
    }

    private function seedFlows(): void
    {
        $statusIds = OrderStatus::query()->pluck('id', 'code');

        foreach (OrderFlowService::flowsSeed() as $seed) {
            $flow = OrderFlow::query()->where('code', $seed['code'])->first();

            if (!$flow) {
                $flow = OrderFlow::query()->create([
                    'code' => $seed['code'],
                    'name' => $seed['name'],
                    'shipping_type' => $seed['shipping_type'],
                    'is_default' => $seed['is_default'],
                    'is_active' => $seed['is_active'],
                ]);
            }

            // Converge exactly one active default per shipping type WITHOUT
            // overriding admin choices: promote only when the type currently
            // has no active default at all.
            $hasDefault = OrderFlow::query()
                ->where('shipping_type', $flow->shipping_type)
                ->where('is_default', true)
                ->where('is_active', true)
                ->exists();
            if (!$hasDefault && $flow->is_active) {
                $flow->update(['is_default' => true]);
            }

            // Additive-only membership sync: append missing seed statuses in
            // seed order after the current maximum. Custom additions,
            // removals and reorderings made via the admin API are preserved.
            $maxSort = (int) (OrderFlowStatus::query()->where('flow_id', $flow->id)->max('sort_order') ?? 0);

            foreach ($seed['statuses'] as $code) {
                $statusId = $statusIds[$code] ?? null;
                if ($statusId === null) {
                    // Seed-definition bug: fail loudly at deploy instead of
                    // seeding a silently incomplete flow.
                    throw new \RuntimeException(
                        "OrderFlowSeeder: status code [{$code}] missing for flow [{$seed['code']}]."
                    );
                }

                $member = OrderFlowStatus::query()
                    ->where('flow_id', $flow->id)
                    ->where('status_id', $statusId)
                    ->first();

                if ($member) {
                    continue;
                }

                $maxSort++;
                OrderFlowStatus::query()->create([
                    'flow_id' => $flow->id,
                    'status_id' => $statusId,
                    'sort_order' => $maxSort,
                ]);
            }
        }
    }
}
