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
        $settingCache = $this->remember(FrontendResource::SETTINGS->value, md5(request()->fullUrl()), $setting);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache));
    }
}
