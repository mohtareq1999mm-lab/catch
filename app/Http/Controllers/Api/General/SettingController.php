<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Services\General\SettingService;
use App\Traits\HasCache;
use Marvel\Http\Resources\SettingResource;
use Marvel\Traits\ApiResponse;

class SettingController extends Controller
{
    use ApiResponse, HasCache;
    private SettingService $settingService;
    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function index()
    {
        $setting = $this->settingService->getSetting();

        // Do not cache null — it would poison HasCache and keep returning a null resource
        // which previously crashed SettingResource::toArray with getTranslation() on null.
        if (!$setting) {
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make(null));
        }

        $settingCache = $this->remember(FrontendResource::SETTINGS->value, md5(request()->fullUrl()), $setting);

        // remember() can still return null if the underlying store failed; guard again
        if (!$settingCache) {
            $settingCache = $setting;
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache));
    }
}
