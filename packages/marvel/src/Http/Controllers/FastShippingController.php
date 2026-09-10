<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\FastShippingRepository;
use Marvel\Enums\Permission;
use Marvel\Traits\ApiResponse;


class FastShippingController extends CoreController
{
    use ApiResponse , HasCache;

    public function __construct(private readonly FastShippingRepository $repository)
    {
        $this->middleware("permission:" . Permission::VIEW_FAST_SHIPPING, ["only" => ["getSettings"]]);
        $this->middleware("permission:" . Permission::UPDATE_FAST_SHIPPING, ["only" => ["updateSettings"]]);
    }


    public function getSettings(): JsonResponse
    {
        $settings = $this->repository->getSettings();
        $settingsCache = $this->remember(FrontendResource::FAST_SHIPPING_SETTINGS->value, md5(request()->fullUrl()), $settings);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $settingsCache);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'fee' => ['sometimes', 'numeric', 'min:0'],
            'start_hour' => ['sometimes', 'string', 'date_format:H:i'],
            'end_hour' => ['sometimes', 'string', 'date_format:H:i'],
        ]);

        $this->repository->updateSettings($validated);
        $this->flushTag(FrontendResource::FAST_SHIPPING_SETTINGS->value);
        return $this->apiResponse(FAST_SHIPPING_SETTINGS_UPDATED, 200, true);
    }
}