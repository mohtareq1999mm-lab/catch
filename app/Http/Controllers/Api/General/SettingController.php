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
        // Lazy closure: the Settings::first() query must run only on cache miss.
        // LARAVEL MECHANICS when the settings row is ABSENT: Repository::remember
        // still put()s the null result, FileStore writes it — but every read of a
        // null payload resolves to MISS, so the row is re-queried each request and
        // a cached null can never surface as data (equivalent to the historical
        // "do not cache null" guard; tiny write noise only while the row is absent).
        $settingCache = $this->remember(
            FrontendResource::SETTINGS->value,
            md5(request()->fullUrl()),
            fn () => $this->settingService->getSetting()
        );

        if (!$settingCache) {
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make(null));
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache));
    }
}
