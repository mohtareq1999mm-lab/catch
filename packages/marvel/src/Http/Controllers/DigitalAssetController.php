<?php

namespace Marvel\Http\Controllers;

use App\Models\DigitalAsset;
use App\Services\Digital\DigitalAssetService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\DigitalAssetCreateRequest;
use Marvel\Http\Requests\DigitalAssetUpdateRequest;
use Marvel\Http\Requests\StoreLicenseKeysRequest;
use Marvel\Traits\ApiResponse;

class DigitalAssetController extends CoreController
{
    use ApiResponse;

    private DigitalAssetService $digitalAssetService;

    public function __construct(DigitalAssetService $digitalAssetService)
    {
        $this->digitalAssetService = $digitalAssetService;
        $this->middleware("permission:" . Permission::VIEW_PRODUCTS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_PRODUCT, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_PRODUCT, ["only" => ["update", "destroy", "replace"]]);
        $this->middleware("permission:" . Permission::MANAGE_DIGITAL_LICENSES, ["only" => ["storeLicenseKeys"]]);
    }

    /**
     * W6 — single-asset SHOW. Same public contract as the collection
     * entries; storage internals and secrets stay hidden at model level.
     */
    public function show(string $uuid): JsonResponse
    {
        $asset = \App\Models\DigitalAsset::query()->where('uuid', $uuid)->firstOrFail();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new \Marvel\Http\Resources\DigitalAssetResource($asset));
    }

    public function index(Request $request, int $product): JsonResponse
    {
        $assets = DigitalAsset::query()
            ->where('product_id', $product)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, \Marvel\Http\Resources\DigitalAssetResource::collection($assets));
    }

    public function store(DigitalAssetCreateRequest $request, int $product): JsonResponse
    {
        try {
            $productModel = Product::findOrFail($product);

            if ($productModel->item_type !== \Marvel\Enums\ItemType::DIGITAL) {
                return $this->apiResponse(__('message.ERROR.DIGITAL_ASSET_INVALID_FILE'), 422, false);
            }

            // W5 — dispatch on the registry-validated delivery type. All
            // branches live in the app-layer service; Marvel stays CRUD.
            $validated = $request->validated();
            $type = $validated['type'] ?? \App\Enums\DigitalAssetType::FILE->value;

            $asset = match ($type) {
                \App\Enums\DigitalAssetType::URL->value => $this->digitalAssetService->createUrl($productModel, $validated),
                \App\Enums\DigitalAssetType::LICENSE->value => $this->digitalAssetService->createLicense($productModel, $validated),
                \App\Enums\DigitalAssetType::ACCESS->value => $this->digitalAssetService->createAccess($productModel, $validated),
                default => $this->digitalAssetService->store($productModel, $request->file('file'), $validated),
            };

            return $this->apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, new \Marvel\Http\Resources\DigitalAssetResource($asset));
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * W5 — bulk-provision encrypted keys into a LICENSE pool. Response
     * carries counts only; plaintext never returns.
     */
    public function storeLicenseKeys(StoreLicenseKeysRequest $request, string $uuid): JsonResponse
    {
        $asset = DigitalAsset::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $created = $this->digitalAssetService->addLicenseKeys($asset, $request->validated('keys'));

        return $this->apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, [
            'asset_uuid' => $asset->uuid,
            'created' => $created,
        ]);
    }

    public function update(DigitalAssetUpdateRequest $request, string $uuid): JsonResponse
    {
        $asset = \App\Models\DigitalAsset::where('uuid', $uuid)->firstOrFail();

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new \Marvel\Http\Resources\DigitalAssetResource(
            $this->digitalAssetService->update($asset, $request->validated())
        ));
    }

    /**
     * W6 — explicit FILE replacement. Bytes/mime/extension/size/checksum
     * are refreshed through the W4 byte-truth pipeline; uuid and metadata
     * survive. Old physical file is removed only after the new state is
     * durably committed.
     */
    public function replace(\Marvel\Http\Requests\ReplaceDigitalAssetRequest $request, string $uuid): JsonResponse
    {
        $asset = \App\Models\DigitalAsset::query()->where('uuid', $uuid)->firstOrFail();

        $replaced = $this->digitalAssetService->replace($asset, $request->file('file'), (array) $request->validated('display_name'));

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new \Marvel\Http\Resources\DigitalAssetResource($replaced));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $asset = DigitalAsset::where('uuid', $uuid)->firstOrFail();
        $this->digitalAssetService->delete($asset);

        return $this->apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true);
    }
}
