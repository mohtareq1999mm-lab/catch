<?php

namespace App\Services\Warehouse;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class WarehouseDashboardService
{
    /**
     * Compile comprehensive warehouse metrics and operational context.
     */
    public function getDashboard(Warehouse $warehouse, ?User $user = null): array
    {
        $warehouseId = $warehouse->id;

        // 1. Locations metrics
        $totalLocations = Location::where('warehouse_id', $warehouseId)->count();
        $locationsByStatus = Location::where('warehouse_id', $warehouseId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $locationsByType = Location::where('warehouse_id', $warehouseId)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->all();

        $occupiedLocationsCount = ProductLocation::where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->distinct('location_id')
            ->count('location_id');

        $availableLocationsCount = max(0, $totalLocations - $occupiedLocationsCount);
        $occupancyRate = $totalLocations > 0 ? round(($occupiedLocationsCount / $totalLocations) * 100, 1) : 0.0;

        // 2. Fulfillments metrics
        $fulfillmentsByStatus = Fulfillment::where('warehouse_id', $warehouseId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $totalFulfillments = array_sum($fulfillmentsByStatus);

        // 3. Batches & Picking metrics
        $batchesByStatus = FulfillmentBatch::where('warehouse_id', $warehouseId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $batchSubquery = FulfillmentBatch::where('warehouse_id', $warehouseId)->select('id');

        $pickingTasksByStatus = PickingTask::whereIn('batch_id', $batchSubquery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $totalUnitsToPick = (float) PickingTask::whereIn('batch_id', $batchSubquery)->sum('quantity_to_pick');
        $totalUnitsPicked = (float) PickingTask::whereIn('batch_id', $batchSubquery)->sum('quantity_picked');

        // 4. Packing & Packages metrics
        $fulfillmentSubquery = Fulfillment::where('warehouse_id', $warehouseId)->select('id');

        $packingStationsByStatus = PackingStation::where('warehouse_id', $warehouseId)
            ->selectRaw("(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as is_active, count(*) as count")
            ->groupBy(\Illuminate\Support\Facades\DB::raw("CASE WHEN status = 'active' THEN 1 ELSE 0 END"))
            ->pluck('count', 'is_active')
            ->all();

        $packingTasksByStatus = PackingTask::whereIn('fulfillment_id', $fulfillmentSubquery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $packagesByStatus = Package::whereIn('fulfillment_id', $fulfillmentSubquery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // 5. Shipments metrics
        $shipmentsByStatus = Shipment::whereIn('fulfillment_id', $fulfillmentSubquery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // 6. Inventory placed summary
        $placementsWithStock = ProductLocation::where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->count();

        $uniqueProductsPlaced = ProductLocation::where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->distinct('product_id')
            ->count('product_id');

        $totalPhysicalUnits = (float) ProductLocation::where('warehouse_id', $warehouseId)->sum('quantity');

        // 7. Worker Terminal Context
        $workerContext = $this->buildWorkerContext($warehouse, $user);

        return [
            'warehouse' => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
                'is_default' => (bool) $warehouse->is_default,
                'address' => $warehouse->address,
                'city' => $warehouse->city,
                'country' => $warehouse->country,
                'metadata' => $warehouse->metadata,
                'created_at' => $warehouse->created_at?->toIso8601String(),
            ],
            'locations' => [
                'total' => $totalLocations,
                'occupied' => $occupiedLocationsCount,
                'available' => $availableLocationsCount,
                'occupancy_rate_percent' => $occupancyRate,
                'by_status' => $locationsByStatus,
                'by_type' => $locationsByType,
            ],
            'fulfillments' => [
                'total' => $totalFulfillments,
                'pending' => $fulfillmentsByStatus['pending'] ?? 0,
                'picking' => $fulfillmentsByStatus['picking'] ?? 0,
                'packing' => $fulfillmentsByStatus['packing'] ?? 0,
                'ready_to_ship' => $fulfillmentsByStatus['ready_to_ship'] ?? 0,
                'shipped' => $fulfillmentsByStatus['shipped'] ?? 0,
                'cancelled' => $fulfillmentsByStatus['cancelled'] ?? 0,
                'by_status' => $fulfillmentsByStatus,
            ],
            'picking_and_batches' => [
                'total_batches' => array_sum($batchesByStatus),
                'batches_by_status' => $batchesByStatus,
                'total_tasks' => array_sum($pickingTasksByStatus),
                'tasks_by_status' => $pickingTasksByStatus,
                'total_units_to_pick' => $totalUnitsToPick,
                'total_units_picked' => $totalUnitsPicked,
                'picking_progress_percent' => $totalUnitsToPick > 0
                    ? round(($totalUnitsPicked / $totalUnitsToPick) * 100, 1)
                    : 100.0,
            ],
            'packing_and_packages' => [
                'stations_total' => array_sum($packingStationsByStatus),
                'stations_active' => $packingStationsByStatus[1] ?? $packingStationsByStatus['true'] ?? 0,
                'total_tasks' => array_sum($packingTasksByStatus),
                'tasks_by_status' => $packingTasksByStatus,
                'total_packages' => array_sum($packagesByStatus),
                'packages_by_status' => $packagesByStatus,
            ],
            'shipments' => [
                'total' => array_sum($shipmentsByStatus),
                'by_status' => $shipmentsByStatus,
            ],
            'inventory' => [
                'placements_with_stock' => $placementsWithStock,
                'unique_products_count' => $uniqueProductsPlaced,
                'total_physical_units' => $totalPhysicalUnits,
            ],
            'worker_context' => $workerContext,
            'quick_actions' => [
                'barcode_scan' => '/api/v1/admin/barcode/scan?warehouse_id=' . $warehouseId,
                'batches_next_task' => '/api/v1/admin/batches/next-task?warehouse_id=' . $warehouseId,
                'pending_fulfillments' => '/api/v1/admin/batches/pending-fulfillments?warehouse_id=' . $warehouseId,
                'inventory_overview' => '/api/v1/admin/inventory/overview?warehouse_id=' . $warehouseId,
                'stock_receipt' => '/api/v1/admin/locations/{location}/stock-receipts',
                'stock_transfer' => '/api/v1/admin/stock-transfers',
            ],
        ];
    }

    /**
     * Build worker / terminal operational context for the authenticated user.
     */
    private function buildWorkerContext(Warehouse $warehouse, ?User $user): array
    {
        if (!$user) {
            return [
                'is_assigned' => false,
                'roles' => [],
                'active_picking_task' => null,
                'active_packing_task' => null,
            ];
        }

        $warehouseId = $warehouse->id;
        $isAssigned = ($user->warehouse_id === $warehouseId)
            || $warehouse->users()->where('users.id', $user->id)->exists();

        $activePickingTask = PickingTask::where('claimed_by', $user->id)
            ->whereIn('status', ['claimed', 'picking'])
            ->whereHas('batch', fn($q) => $q->where('warehouse_id', $warehouseId))
            ->first(['id', 'batch_id', 'status', 'quantity_to_pick', 'quantity_picked', 'product_location_id']);

        $activePackingTask = PackingTask::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->whereHas('fulfillment', fn($q) => $q->where('warehouse_id', $warehouseId))
            ->first(['id', 'fulfillment_id', 'packing_station_id', 'status']);

        $roles = $user->roles
            ? $user->roles->pluck('name')->intersect([
                'picker', 'packer', 'warehouse-supervisor', 'warehouse-manager', 'super_admin'
            ])->values()->all()
            : [];

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'is_assigned' => $isAssigned,
            'roles' => $roles,
            'active_picking_task' => $activePickingTask ? [
                'id' => $activePickingTask->id,
                'batch_id' => $activePickingTask->batch_id,
                'status' => $activePickingTask->status,
                'quantity_to_pick' => (float) $activePickingTask->quantity_to_pick,
                'quantity_picked' => (float) $activePickingTask->quantity_picked,
                'product_location_id' => $activePickingTask->product_location_id,
            ] : null,
            'active_packing_task' => $activePackingTask ? [
                'id' => $activePackingTask->id,
                'fulfillment_id' => $activePackingTask->fulfillment_id,
                'packing_station_id' => $activePackingTask->packing_station_id,
                'status' => $activePackingTask->status,
            ] : null,
        ];
    }

    /**
     * Get all WMS / Warehouse permissions categorized and labeled.
     */
    public function getPermissions(?User $user = null): array
    {
        $categories = [
            'warehouses' => [
                'key' => 'warehouses',
                'name' => 'إدارة المستودعات (Warehouse Management)',
                'permissions' => [
                    Permission::WAREHOUSES_INDEX,
                    Permission::WAREHOUSES_SHOW,
                    Permission::WAREHOUSES_CREATE,
                    Permission::WAREHOUSES_UPDATE,
                    Permission::WAREHOUSES_SET_DEFAULT,
                    Permission::WAREHOUSES_ACTIVATE,
                    Permission::WAREHOUSES_DEACTIVATE,
                    Permission::WAREHOUSES_DELETE,
                    Permission::WAREHOUSES_USERS_VIEW,
                    Permission::WAREHOUSES_USERS_ASSIGN,
                    Permission::WAREHOUSES_USERS_REMOVE_BULK,
                    Permission::WAREHOUSES_USERS_REMOVE,
                    'view-warehouse',
                    'manage-warehouse',
                ],
            ],
            'locations' => [
                'key' => 'locations',
                'name' => 'المواقع والأرفف (Locations & Bins)',
                'permissions' => [
                    Permission::LOCATIONS_INDEX,
                    Permission::LOCATIONS_SHOW,
                    Permission::LOCATIONS_CHECK_OCCUPANCY,
                    Permission::LOCATIONS_CREATE,
                    Permission::LOCATIONS_UPDATE,
                    Permission::LOCATIONS_ACTIVATE,
                    Permission::LOCATIONS_DEACTIVATE,
                    Permission::LOCATIONS_DELETE,
                    Permission::LOCATIONS_EMPTY_PRODUCTS,
                    'view-location',
                    'manage-location',
                ],
            ],
            'fulfillments' => [
                'key' => 'fulfillments',
                'name' => 'أوامر التنفيذ (Fulfillment Orders)',
                'permissions' => [
                    Permission::FULFILLMENTS_INDEX,
                    Permission::FULFILLMENTS_SHOW,
                    Permission::FULFILLMENTS_ASSIGN,
                    Permission::FULFILLMENTS_CREATE_TASKS,
                    Permission::FULFILLMENTS_COMPLETE_PICKING,
                    Permission::FULFILLMENT_ITEMS_ASSIGN_PLACEMENT,
                    Permission::FULFILLMENTS_CREATE_PACKING_TASKS,
                    Permission::FULFILLMENTS_CREATE_PACKING_TASK,
                    Permission::FULFILLMENT_CREATE,
                    Permission::FULFILLMENT_CANCEL,
                    'view-fulfillment',
                    'manage-fulfillment',
                    'fulfillment-override',
                ],
            ],
            'picking_and_batches' => [
                'key' => 'picking_and_batches',
                'name' => 'الالتقاط والباتشات (Picking & Batches)',
                'permissions' => [
                    Permission::BATCH_INDEX,
                    Permission::BATCH_SHOW,
                    Permission::BATCH_PENDING_FULFILLMENTS,
                    Permission::BATCH_NEXT_TASK,
                    Permission::BATCH_CREATE,
                    Permission::BATCH_ASSIGN,
                    Permission::BATCH_START,
                    Permission::BATCH_REFRESH_PROGRESS,
                    Permission::BATCH_COMPLETE,
                    Permission::BATCH_CONFIRM_LOCATION,
                    Permission::BATCH_CANCEL,
                    Permission::BATCH_RETRY,
                    Permission::PICKING_TASKS_INDEX,
                    Permission::PICKING_TASKS_SHOW,
                    Permission::PICKING_TASKS_CLAIM,
                    Permission::PICKING_TASKS_RELEASE,
                    Permission::PICKING_TASKS_CONFIRM,
                    Permission::PICKING_TASKS_RECORD_PICK,
                    Permission::PICKING_TASKS_SKIP,
                    Permission::PICKING_TASKS_REALLOCATE,
                    'picking-execute',
                    'picking.claim',
                    'picking.complete',
                    'batch.manage',
                    'batch.operate',
                ],
            ],
            'packing_and_packages' => [
                'key' => 'packing_and_packages',
                'name' => 'التغليف والطرود (Packing & Packages)',
                'permissions' => [
                    Permission::PACKING_TASKS_INDEX,
                    Permission::PACKING_TASKS_SHOW,
                    Permission::PACKING_TASKS_ASSIGN,
                    Permission::PACKING_TASKS_START,
                    Permission::PACKING_TASKS_PACK,
                    Permission::PACKING_TASKS_VERIFY,
                    Permission::PACKING_TASKS_CANCEL,
                    Permission::PACKING_TASKS_STATIONS,
                    Permission::PACKING_STATIONS_INDEX,
                    Permission::PACKING_STATIONS_SHOW,
                    Permission::PACKING_STATIONS_CREATE,
                    Permission::PACKING_STATIONS_UPDATE,
                    Permission::PACKING_STATIONS_DELETE,
                    Permission::PACKAGES_INDEX,
                    Permission::PACKAGES_SHOW,
                    Permission::PACKAGES_CREATE,
                    Permission::PACKAGES_ADD_ITEM,
                    Permission::PACKAGES_SEAL,
                    Permission::PACKAGES_VOID,
                    'packing-execute',
                    'packing.complete',
                ],
            ],
            'shipments' => [
                'key' => 'shipments',
                'name' => 'شحنات المستودع (WMS Shipments)',
                'permissions' => [
                    Permission::WMS_SHIPMENTS_INDEX,
                    Permission::WMS_SHIPMENTS_SHOW,
                    Permission::WMS_SHIPMENTS_CREATE,
                    Permission::WMS_SHIPMENTS_BULK_CREATE,
                    Permission::WMS_SHIPMENTS_BULK_DISPATCH,
                    Permission::WMS_SHIPMENTS_BULK_DELIVER,
                    Permission::WMS_SHIPMENTS_DISPATCH,
                    Permission::WMS_SHIPMENTS_DELIVER,
                    Permission::WMS_SHIPMENTS_CANCEL,
                ],
            ],
            'inventory' => [
                'key' => 'inventory',
                'name' => 'المخزون والتحويلات (Inventory Operations)',
                'permissions' => [
                    Permission::INVENTORY_RECEIVE,
                    Permission::INVENTORY_TRANSFER,
                    Permission::INVENTORY_PRODUCT_PLACEMENTS,
                    Permission::INVENTORY_OVERVIEW,
                    Permission::INVENTORY_OPERATIONS_INDEX,
                    Permission::INVENTORY_OPERATIONS_SHOW,
                    'inventory-adjust',
                ],
            ],
            'workflow' => [
                'key' => 'workflow',
                'name' => 'سير عمل المستودع (Warehouse Workflow)',
                'permissions' => [
                    Permission::WAREHOUSE_WORKFLOW_SHOW,
                    Permission::WAREHOUSE_WORKFLOW_HISTORY,
                    Permission::WAREHOUSE_WORKFLOW_PUBLISH,
                    Permission::MANAGE_WAREHOUSE_WORKFLOW,
                ],
            ],
            'barcode' => [
                'key' => 'barcode',
                'name' => 'مسح الباركود (Barcode Scanning)',
                'permissions' => [
                    Permission::BARCODE_SCAN,
                ],
            ],
        ];

        $userPerms = $user ? $user->getAllPermissions()->pluck('name')->all() : [];
        $isSuperAdmin = $user && method_exists($user, 'hasRole') && $user->hasRole('super_admin');

        $resultModules = [];
        $allWmsKeys = [];

        foreach ($categories as $catKey => $category) {
            $formattedPerms = [];
            foreach ($category['permissions'] as $permKey) {
                $allWmsKeys[] = $permKey;
                $has = $isSuperAdmin || in_array($permKey, $userPerms, true);

                $label = null;
                if (function_exists('app') && app()->bound('translator')) {
                    $translated = __('permissions.' . $permKey);
                    if ($translated !== 'permissions.' . $permKey) {
                        $label = $translated;
                    }
                }
                if (!$label) {
                    $label = ucwords(str_replace(['.', '-', '_'], ' ', $permKey));
                }

                $formattedPerms[] = [
                    'key' => $permKey,
                    'label' => $label,
                    'granted' => $has,
                ];
            }

            $resultModules[] = [
                'module' => $catKey,
                'name' => $category['name'],
                'permissions' => $formattedPerms,
            ];
        }

        $allWmsKeys = array_unique($allWmsKeys);

        $myGrantedWmsPermissions = $isSuperAdmin
            ? $allWmsKeys
            : array_values(array_intersect($allWmsKeys, $userPerms));

        $standardRoles = [
            'picker' => [
                'role' => 'picker',
                'name' => 'عامل التقاط (Picker)',
                'permissions' => [
                    'view-warehouse', 'view-location', 'view-fulfillment',
                    'picking-execute', 'picking.claim', 'barcode.scan'
                ],
            ],
            'packer' => [
                'role' => 'packer',
                'name' => 'عامل تغليف (Packer)',
                'permissions' => [
                    'view-warehouse', 'view-location', 'view-fulfillment',
                    'packing-execute', 'packing.complete', 'barcode.scan'
                ],
            ],
            'warehouse-supervisor' => [
                'role' => 'warehouse-supervisor',
                'name' => 'مشرف مستودع (Supervisor)',
                'permissions' => [
                    'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
                    'picking-execute', 'packing-execute', 'fulfillment-override',
                    'fulfillment.create', 'fulfillment.cancel',
                    'picking.claim', 'picking.complete',
                    'packing.complete',
                    'batch.manage', 'batch.operate',
                    'order.cancel-during-fulfillment',
                    'barcode.scan',
                ],
            ],
            'warehouse-manager' => [
                'role' => 'warehouse-manager',
                'name' => 'مدير مستودع (Manager)',
                'permissions' => [
                    'view-warehouse', 'manage-warehouse',
                    'view-location', 'manage-location',
                    'view-fulfillment', 'manage-fulfillment',
                    'picking-execute', 'packing-execute',
                    'fulfillment-override', 'inventory-adjust',
                    'manage-warehouse-workflow',
                    'fulfillment.create', 'fulfillment.cancel',
                    'picking.claim', 'picking.complete',
                    'packing.complete',
                    'batch.manage', 'batch.operate',
                    'order.cancel-during-fulfillment',
                    'barcode.scan',
                ],
            ],
        ];

        return [
            'modules' => $resultModules,
            'roles' => $standardRoles,
            'my_wms_permissions' => $myGrantedWmsPermissions,
            'total_wms_permissions' => count($allWmsKeys),
        ];
    }
}
