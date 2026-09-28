<?php

namespace Database\Seeders;

use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Database\Seeder;

/**
 * Default Flow Input definitions.
 *
 * Production-safe by design (same contract as OrderFlowSeeder):
 * - Idempotent and deterministic (stable flow code + input key).
 * - NEVER overwrites admin customizations: existing input rows keep
 *   their configuration; only missing seed inputs are appended (after
 *   the current max sort_order).
 * - NEVER touches orders data.
 *
 * Seed content:
 * - local: no required checkout inputs (legacy clients keep working).
 * - international: from_country + to_country (select→countries,
 *   required at checkout) + customs_reference (text, required at
 *   transition:customs_clearance).
 */
class FlowInputSeeder extends Seeder
{
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function seedDefinitions(): array
    {
        return [
            OrderFlowService::SHIPPING_INTERNATIONAL => [
                [
                    'key' => 'from_country',
                    'label' => ['en' => 'Country of origin', 'ar' => 'بلد المنشأ'],
                    'placeholder' => ['en' => 'Select origin country', 'ar' => 'اختر بلد المنشأ'],
                    'help_text' => ['en' => 'Where the shipment starts its journey.', 'ar' => 'المكان الذي تبدأ منه الشحنة رحلتها.'],
                    'type' => FlowInput::TYPE_SELECT,
                    'source' => FlowInput::SOURCE_COUNTRIES,
                    'required' => true,
                    'required_at' => FlowInput::REQUIRED_AT_CHECKOUT,
                ],
                [
                    'key' => 'to_country',
                    'label' => ['en' => 'Destination country', 'ar' => 'بلد الوجهة'],
                    'placeholder' => ['en' => 'Select destination country', 'ar' => 'اختر بلد الوجهة'],
                    'help_text' => ['en' => 'Where the shipment will be delivered.', 'ar' => 'المكان الذي سيتم تسليم الشحنة فيه.'],
                    'type' => FlowInput::TYPE_SELECT,
                    'source' => FlowInput::SOURCE_COUNTRIES,
                    'required' => true,
                    'required_at' => FlowInput::REQUIRED_AT_CHECKOUT,
                ],
                [
                    'key' => 'customs_reference',
                    'label' => ['en' => 'Customs reference', 'ar' => 'المرجع الجمركي'],
                    'placeholder' => ['en' => 'e.g. CUS-2026-00125', 'ar' => 'مثال: CUS-2026-00125'],
                    'help_text' => ['en' => 'Required before customs clearance starts.', 'ar' => 'مطلوب قبل بدء التخليص الجمركي.'],
                    'type' => FlowInput::TYPE_TEXT,
                    'source' => null,
                    'required' => true,
                    'required_at' => 'transition:customs_clearance',
                    'validation' => ['min' => 3, 'max' => 100],
                ],
            ],
        ];
    }

    public function run(): void
    {
        if (!OrderFlowService::tablesAvailable()) {
            $this->command?->warn('FlowInputSeeder skipped: flow tables are missing. Run migrations first.');

            return;
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('flow_inputs')) {
            $this->command?->warn('FlowInputSeeder skipped: flow_inputs table is missing. Run migrations first.');

            return;
        }

        foreach (self::seedDefinitions() as $shippingType => $inputs) {
            $flow = OrderFlow::query()->where('shipping_type', $shippingType)->first();

            if (!$flow) {
                continue;
            }

            $maxSort = (int) (FlowInput::query()->where('flow_id', $flow->id)->max('sort_order') ?? 0);

            foreach ($inputs as $definition) {
                $exists = FlowInput::query()
                    ->where('flow_id', $flow->id)
                    ->where('key', $definition['key'])
                    ->exists();

                if ($exists) {
                    // Admin-owned row: preserve configuration.
                    continue;
                }

                $maxSort++;

                FlowInput::query()->create(array_merge($definition, [
                    'flow_id' => $flow->id,
                    'sort_order' => $maxSort,
                    'is_active' => true,
                ]));
            }
        }
    }
}
