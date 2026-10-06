<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\AddPackageItemRequest;
use App\Http\Requests\Admin\Wms\BatchCommandRequest;
use App\Http\Requests\Admin\Wms\CreatePackageRequest;
use App\Http\Requests\Admin\Wms\ListPackagesRequest;
use App\Http\Requests\Admin\Wms\SealPackageRequest;
use App\Http\Resources\Wms\PackageDetailResource;
use App\Http\Resources\Wms\PackageResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Package;
use App\Services\Fulfillment\PackingService;
use Illuminate\Http\JsonResponse;

/**
 * P9-6: package HTTP adapter. Scope resolves via package.fulfillment_id →
 * fulfillment.warehouse_id. One active (non-voided) package per fulfillment
 * (D6-1); voided rows remain as auditable history. Shipment creation from a
 * verified task is P9-8 territory and is deliberately NOT exposed here.
 */
class PackageController extends WmsAdminController
{
    public function __construct(
        private PackingService $packing,
    ) {}

    private function packageWarehouseId(Package $package): int
    {
        return (int) $package->fulfillment()->value('warehouse_id');
    }

    /**
     * GET /api/v1/admin/packages?fulfillment_id=
     */
    public function index(ListPackagesRequest $request): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey((int) $request->validated('fulfillment_id'))->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'view-fulfillment', true);

        $query = Package::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->orderByDesc('id')
            ->withCount('items');

        $status = $request->validated('status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            'Packages fetched successfully.',
            200,
            true,
            PackageResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/packages/{id}
     */
    public function show(int $id): JsonResponse
    {
        $package = Package::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->packageWarehouseId($package), 'view-fulfillment', true);

        $package->loadMissing('items');

        return $this->apiResponse(
            'Package fetched successfully.',
            200,
            true,
            PackageDetailResource::make($package)
        );
    }

    /**
     * POST /api/v1/admin/packages
     */
    public function store(CreatePackageRequest $request): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey((int) $request->validated('fulfillment_id'))->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'packing-execute');

        $taskId = $request->validated('packing_task_id');
        if ($taskId !== null) {
            // The linked task must live under the same fulfillment: the
            // service re-checks under lock, this is an early scoped read.
            $task = \App\Models\Fulfillment\PackingTask::whereKey((int) $taskId)->firstOrFail();
            $this->authorizeWarehouseScope((int) $task->fulfillment()->value('warehouse_id'), 'packing-execute', true);
        }

        try {
            $package = $this->packing->createPackage(
                $fulfillment,
                $taskId !== null ? (int) $taskId : null,
                array_filter([
                    'weight' => $request->validated('weight'),
                    'dimensions' => $request->validated('dimensions'),
                    'notes' => $request->validated('notes'),
                ], fn ($v) => $v !== null)
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already has an active package')) {
                $this->conflict($e->getMessage());
            }
            $this->unprocessable($e->getMessage());
        } catch (\Exception $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Package created successfully.',
            201,
            true,
            PackageDetailResource::make($package->fresh()->loadMissing('items'))
        );
    }

    /**
     * POST /api/v1/admin/packages/{id}/add-item
     */
    public function addItem(AddPackageItemRequest $request, int $id): JsonResponse
    {
        $package = Package::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->packageWarehouseId($package), 'packing-execute');

        try {
            $this->packing->addItemToPackage(
                $package,
                (int) $request->validated('fulfillment_item_id'),
                (float) $request->validated('quantity')
            );
        } catch (\Exception $e) {
            // Over-pack, unpicked, foreign-item, and sealed-package refusals
            // all signal with base \Exception. Narrow by design: this
            // endpoint performs no other fallible work.
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Package item added successfully.',
            200,
            true,
            PackageDetailResource::make($package->fresh()->loadMissing('items'))
        );
    }

    /**
     * POST /api/v1/admin/packages/{id}/seal
     */
    public function seal(SealPackageRequest $request, int $id): JsonResponse
    {
        $package = Package::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->packageWarehouseId($package), 'packing-execute');

        try {
            $sealed = $this->packing->sealPackage(
                $package,
                $request->validated('weight') !== null ? (float) $request->validated('weight') : null,
                $request->validated('dimensions')
            );
        } catch (\Exception $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Package sealed successfully.',
            200,
            true,
            PackageDetailResource::make($sealed->fresh()->loadMissing('items'))
        );
    }

    /**
     * POST /api/v1/admin/packages/{id}/void
     *
     * Only OPEN packages may be voided (P6-7): sealed custody is never
     * silently destroyed. The row and its items are retained for audit.
     */
    public function void(BatchCommandRequest $request, int $id): JsonResponse
    {
        $package = Package::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->packageWarehouseId($package), 'manage-fulfillment');

        try {
            $voided = $this->packing->voidPackage($package, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Package voided successfully.',
            200,
            true,
            PackageDetailResource::make($voided->fresh()->loadMissing('items'))
        );
    }
}
